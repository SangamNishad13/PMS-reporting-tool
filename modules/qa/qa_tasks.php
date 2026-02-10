<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['qa', 'admin', 'super_admin']);

$userId = $_SESSION['user_id'];
$db = Database::getInstance();

// Handle QA updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_qa'])) {
    $pageId = $_POST['page_id'];
    $qaStatus = $_POST['qa_status'] ?? 'na';
    $environmentId = $_POST['environment_id'] ?? null;
    $pageStatus = $_POST['page_status'] ?? null; // optional page-level status
    $issues = $_POST['issues_found'] ?? 0;
    $comments = sanitizeInput($_POST['comments'] ?? '');
    $hours = isset($_POST['hours_spent']) && $_POST['hours_spent'] !== '' ? floatval($_POST['hours_spent']) : 0;

    // Normalize qa_results.status to allowed enum (pass/fail/na)
    $qaStatus = in_array($qaStatus, ['pass','fail','na']) ? $qaStatus : 'na';

    // Insert QA result
    $stmt = $db->prepare("INSERT INTO qa_results (page_id, qa_id, status, issues_found, comments, hours_spent) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$pageId, $userId, $qaStatus, $issues, $comments, $hours]);

    // Update environment-specific status if provided
    if ($environmentId) {
        $db->prepare("UPDATE page_environments SET status = ?, last_updated_by = ? WHERE page_id = ? AND environment_id = ?")
           ->execute([$qaStatus, $userId, $pageId, $environmentId]);
    }

    // Determine page status to set on project_pages
    if (empty($pageStatus)) {
        if ($qaStatus === 'pass') {
            // Only set to completed if ALL environments are passed (if any exist)
            $allEnvs = $db->prepare("SELECT status FROM page_environments WHERE page_id = ?");
            $allEnvs->execute([$pageId]);
            $statuses = $allEnvs->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($statuses) || (count(array_unique($statuses)) === 1 && $statuses[0] === 'pass')) {
                $pageStatus = 'completed';
            } else {
                $pageStatus = 'qa_in_progress';
            }
        } elseif ($qaStatus === 'fail') {
            $pageStatus = 'in_fixing';
        } else {
            $pageStatus = 'needs_review';
        }
    }

    if ($pageStatus) {
        $db->prepare("UPDATE project_pages SET status = ? WHERE id = ?")->execute([$pageStatus, $pageId]);
    }

    // Log Activity
    logActivity($db, $userId, 'qa_review', 'page', $pageId, [
        'qa_status' => $qaStatus,
        'page_status' => $pageStatus,
        'environment_id' => $environmentId
    ]);

    $_SESSION['success'] = "QA review submitted successfully!";
    
    // Redirect back to referring page or dashboard
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
    header("Location: " . $redirect);
    exit;
}

// Fetch pages for display if not redirecting
$pages = $db->prepare("
    SELECT pp.*, p.title as project_title, p.priority,
           at_user.full_name as at_tester_name,
           ft_user.full_name as ft_tester_name
    FROM project_pages pp
    JOIN projects p ON pp.project_id = p.id
    LEFT JOIN users at_user ON pp.at_tester_id = at_user.id
    LEFT JOIN users ft_user ON pp.ft_tester_id = ft_user.id
    WHERE pp.qa_id = ? 
    AND p.status NOT IN ('completed', 'cancelled')
    ORDER BY pp.status, p.priority, pp.created_at
");
$pages->execute([$userId]);

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid">
    <h2>QA Tasks Management</h2>
    
    <?php $activeTab = $_GET['tab'] ?? 'pending'; ?>
    <ul class="nav nav-tabs mb-3" id="qaTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeTab === 'pending' ? 'active' : ''; ?>" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button">
                Pending Review <span class="badge bg-warning"><?php 
                    $stmt = $db->prepare("
                        SELECT COUNT(*) FROM project_pages pp
                        JOIN projects p ON pp.project_id = p.id
                        WHERE pp.qa_id = ? AND pp.status IN ('qa_in_progress', 'needs_review')
                        AND p.status NOT IN ('completed', 'cancelled')
                    ");
                    $stmt->execute([$userId]);
                    $pending = $stmt->fetchColumn();
                    echo $pending;
                ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeTab === 'completed' ? 'active' : ''; ?>" id="completed-tab" data-bs-toggle="tab" data-bs-target="#completed" type="button">
            
                Completed <span class="badge bg-success"><?php 
                    $stmt = $db->prepare("
                        SELECT COUNT(*) FROM project_pages pp
                        JOIN projects p ON pp.project_id = p.id
                        WHERE pp.qa_id = ? AND pp.status = 'completed'
                        AND p.status NOT IN ('completed', 'cancelled')
                    ");
                    $stmt->execute([$userId]);
                    $completed = $stmt->fetchColumn();
                    echo $completed;
                ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeTab === 'fixing' ? 'active' : ''; ?>" id="fixing-tab" data-bs-toggle="tab" data-bs-target="#fixing" type="button">
                In Fixing <span class="badge bg-danger"><?php 
                    $fixing = $db->prepare("
                        SELECT COUNT(*) FROM project_pages pp
                        JOIN projects p ON pp.project_id = p.id
                        WHERE pp.qa_id = ? AND pp.status = 'in_fixing'
                        AND p.status NOT IN ('completed', 'cancelled')
                    ");
                    $fixing->execute([$userId]);
                    $fixing = $fixing->fetchColumn();
                    echo $fixing;
                ?></span>
            </button>
        </li>
    </ul>
    
    <!-- Tab Content -->
    <div class="tab-content" id="qaTabsContent">
        <!-- Pending Review Tab -->
        <div class="tab-pane fade <?php echo $activeTab === 'pending' ? 'show active' : ''; ?>" id="pending" role="tabpanel">
            <?php
            $pendingPages = $db->prepare("
                SELECT pp.*, p.title as project_title, p.priority,
                       at_user.full_name as at_tester_name,
                       ft_user.full_name as ft_tester_name
                FROM project_pages pp
                JOIN projects p ON pp.project_id = p.id
                LEFT JOIN users at_user ON pp.at_tester_id = at_user.id
                LEFT JOIN users ft_user ON pp.ft_tester_id = ft_user.id
                WHERE pp.qa_id = ? 
                AND pp.status IN ('qa_in_progress', 'needs_review')
                AND p.status NOT IN ('completed', 'cancelled')
                ORDER BY p.priority, pp.created_at
            ");
            $pendingPages->execute([$userId]);
            ?>
            
            <div class="row">
                <?php while ($page = $pendingPages->fetch()): ?>
                <div class="col-md-6 mb-3">
                    <div class="card h-100 border-<?php 
                        echo $page['priority'] === 'critical' ? 'danger' : 
                             ($page['priority'] === 'high' ? 'warning' : 'primary');
                    ?>">
                        <div class="card-header bg-<?php 
                            echo $page['priority'] === 'critical' ? 'danger' : 
                                 ($page['priority'] === 'high' ? 'warning' : 'primary');
                        ?> text-white">
                            <h5 class="mb-0"><?php echo $page['page_name']; ?></h5>
                            <small>Project: <?php echo $page['project_title']; ?></small>
                        </div>
                        <div class="card-body">
                            <p><strong>URL/Screen:</strong> <?php echo $page['url'] ?: $page['screen_name']; ?></p>
                            <p><strong>Testers:</strong>
                                <?php
                                    $atHtml = $page['at_tester_name'] ?: getAssignedNamesHtml($db, $page, 'at_tester');
                                    $ftHtml = $page['ft_tester_name'] ?: getAssignedNamesHtml($db, $page, 'ft_tester');
                                ?>
                                <?php if ($atHtml): ?>
                                    <br>AT: <?php echo $atHtml; ?>
                                <?php endif; ?>
                                <?php if ($ftHtml): ?>
                                    <br>FT: <?php echo $ftHtml; ?>
                                <?php endif; ?>
                            </p>
                            <p><strong>Status:</strong>
                                <?php $computed = computePageStatus($db, $page); ?>
                                <?php
                                    if ($computed === 'completed') { $cclass = 'success'; }
                                    elseif ($computed === 'in_testing') { $cclass = 'primary'; }
                                    elseif ($computed === 'testing_failed') { $cclass = 'danger'; }
                                    elseif ($computed === 'tested') { $cclass = 'info'; }
                                    elseif ($computed === 'qa_review') { $cclass = 'warning'; }
                                    elseif ($computed === 'qa_failed') { $cclass = 'danger'; }
                                    elseif ($computed === 'on_hold') { $cclass = 'warning'; }
                                    else { $cclass = 'secondary'; }
                                ?>
                                <span class="badge bg-<?php echo $cclass; ?>"><?php echo ucfirst(str_replace('_', ' ', $computed)); ?></span>
                            </p>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#qaReviewModal<?php echo $page['id']; ?>">
                                    <i class="fas fa-check-circle"></i> Perform QA Review
                                </button>
                                <a href="<?php echo $baseDir; ?>/modules/chat/project_chat.php?page_id=<?php echo $page['id']; ?>" 
                                   class="btn btn-success">
                                    <i class="fas fa-comments"></i> Discuss with Team
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                    <!-- QA Review Modal -->
                    <div class="modal fade" id="qaReviewModal<?php echo $page['id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                    <input type="hidden" name="update_qa" value="1">
                                    <div class="modal-header">
                                        <h5 class="modal-title">QA Review: <?php echo htmlspecialchars($page['page_name']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label>Page Status</label>
                                            <select name="page_status" class="form-select" required>
                                                <option value="qa_in_progress">QA In Progress</option>
                                                <option value="completed">Completed</option>
                                                <option value="in_fixing">In Fixing</option>
                                                <option value="needs_review">Needs Review</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="on_hold">On Hold</option>
                                                <option value="not_started">Not Started</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label>Issues Found</label>
                                            <input type="number" name="issues_found" class="form-control" value="0" min="0">
                                        </div>
                                        <!-- Hours entry removed: use My Daily Status to log hours -->
                                        <div class="mb-3">
                                            <label>Comments</label>
                                            <textarea name="comments" class="form-control" rows="3" placeholder="Enter QA comments..."></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary">Submit QA Review</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
        
        <!-- Completed Tab -->
        <div class="tab-pane fade <?php echo $activeTab === 'completed' ? 'show active' : ''; ?>" id="completed" role="tabpanel">
            <?php
            $completedPages = $db->prepare("
                SELECT pp.*, p.title as project_title, p.priority,
                       qr.status as qa_status, qr.comments as qa_comments,
                       qr.qa_date, qr.issues_found
                FROM project_pages pp
                JOIN projects p ON pp.project_id = p.id
                LEFT JOIN qa_results qr ON pp.id = qr.page_id
                WHERE pp.qa_id = ? 
                AND pp.status = 'completed'
                AND p.status NOT IN ('completed', 'cancelled')
                ORDER BY qr.qa_date DESC
            ");
            $completedPages->execute([$userId]);
            ?>
            
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Page/Screen</th>
                            <th>Project</th>
                            <th>QA Status</th>
                            <th>Issues Found</th>
                            <th>QA Date</th>
                            <th>Comments</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($page = $completedPages->fetch()): ?>
                        <tr>
                            <td>
                                <strong><?php echo $page['page_name']; ?></strong><br>
                                <small><?php echo $page['url'] ?: $page['screen_name']; ?></small>
                            </td>
                            <td><?php echo $page['project_title']; ?></td>
                            <td>
                                <?php $qaLabel = formatQAStatusLabel($page['qa_status'] ?? null);
                                      $qs = strtolower($page['qa_status'] ?? '');
                                      if ($qs === 'pass') { $qcls = 'success'; }
                                      elseif (in_array($qs, ['in_progress','inprogress','ongoing'])) { $qcls = 'primary'; }
                                      elseif (in_array($qs, ['on_hold','hold'])) { $qcls = 'warning'; }
                                      elseif ($qs === 'pending') { $qcls = 'secondary'; }
                                      elseif (in_array($qs, ['fail','failed'])) { $qcls = 'danger'; }
                                      else { $qcls = 'secondary'; }
                                ?>
                                <span class="badge bg-<?php echo $qcls; ?>"><?php echo $qaLabel; ?></span>
                            </td>
                            <td>
                                <?php if ($page['issues_found'] > 0): ?>
                                <span class="badge bg-warning"><?php echo $page['issues_found']; ?></span>
                                <?php else: ?>
                                <span class="badge bg-success">0</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M d, H:i', strtotime($page['qa_date'])); ?></td>
                            <td>
                                <?php if ($page['qa_comments']): ?>
                                <small title="<?php echo $page['qa_comments']; ?>">
                                    <?php echo substr($page['qa_comments'], 0, 50); ?>...
                                </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#viewQAModal<?php echo $page['id']; ?>">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- In Fixing Tab -->
        <div class="tab-pane fade <?php echo $activeTab === 'fixing' ? 'show active' : ''; ?>" id="fixing" role="tabpanel">
            <?php
            $fixingPages = $db->prepare("
                SELECT pp.*, p.title as project_title, p.priority,
                       qr.comments as qa_comments, qr.issues_found,
                       qr.qa_date
                FROM project_pages pp
                JOIN projects p ON pp.project_id = p.id
                LEFT JOIN qa_results qr ON pp.id = qr.page_id
                WHERE pp.qa_id = ? 
                AND pp.status = 'in_fixing'
                AND p.status NOT IN ('completed', 'cancelled')
                ORDER BY p.priority, qr.qa_date
            ");
            $fixingPages->execute([$userId]);
            ?>
            
            <div class="row">
                <?php while ($page = $fixingPages->fetch()): ?>
                <div class="col-md-6 mb-3">
                    <div class="card h-100 border-danger">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><?php echo $page['page_name']; ?></h5>
                            <small>Project: <?php echo $page['project_title']; ?></small>
                        </div>
                        <div class="card-body">
                            <p><strong>URL/Screen:</strong> <?php echo $page['url'] ?: $page['screen_name']; ?></p>
                            <p><strong>QA Date:</strong> <?php echo date('M d, H:i', strtotime($page['qa_date'])); ?></p>
                            <p><strong>Issues Found:</strong> <span class="badge bg-warning"><?php echo $page['issues_found']; ?></span></p>
                            <?php if ($page['qa_comments']): ?>
                            <div class="alert alert-warning">
                                <strong>QA Comments:</strong><br>
                                <?php echo substr($page['qa_comments'], 0, 150); ?>...
                            </div>
                            <?php endif; ?>
                            <div class="d-grid gap-2">
                                <a href="<?php echo $baseDir; ?>/modules/chat/project_chat.php?page_id=<?php echo $page['id']; ?>" 
                                   class="btn btn-warning">
                                    <i class="fas fa-comments"></i> Discuss Fixes
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize tabs
    $('#qaTabs button').on('click', function (e) {
        e.preventDefault();
        $(this).tab('show');
    });
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>