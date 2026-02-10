<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['at_tester', 'admin', 'super_admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$projectId = (int)($_GET['project_id'] ?? 0);

if (!$projectId) {
    header('Location: dashboard.php');
    exit;
}

// Get project details
$projectQuery = "SELECT * FROM projects WHERE id = ?";
$projectStmt = $db->prepare($projectQuery);
$projectStmt->execute([$projectId]);
$project = $projectStmt->fetch();

if (!$project) {
    $_SESSION['error'] = "Project not found.";
    header('Location: dashboard.php');
    exit;
}

// Handle test result submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
    $pageId = (int)$_POST['page_id'];
    $environmentId = (int)$_POST['environment_id'];
    $status = $_POST['status'];
    $issuesFound = (int)$_POST['issues_found'];
    $comments = trim($_POST['comments']);
    $hoursSpent = (float)$_POST['hours_spent'];
    
    try {
        $db->beginTransaction();
        
        // Insert test result
        $insertResult = $db->prepare("
            INSERT INTO testing_results (page_id, environment_id, tester_id, tester_role, status, issues_found, comments, hours_spent, tested_at)
            VALUES (?, ?, ?, 'at_tester', ?, ?, ?, ?, NOW())
        ");
        $insertResult->execute([$pageId, $environmentId, $userId, $status, $issuesFound, $comments, $hoursSpent]);
        
        // Update page environment status
        $updateStatus = $db->prepare("UPDATE page_environments SET status = ? WHERE page_id = ? AND environment_id = ?");
        $updateStatus->execute([$status === 'pass' ? 'tested' : 'testing_failed', $pageId, $environmentId]);
        
        // Log time
        $logTime = $db->prepare("
            INSERT INTO project_time_logs (project_id, user_id, page_id, environment_id, hours_spent, task_type, description, logged_at, is_utilized)
            VALUES (?, ?, ?, ?, ?, 'at_testing', ?, NOW(), 1)
        ");
        $logTime->execute([$projectId, $userId, $pageId, $environmentId, $hoursSpent, $comments]);
        
        $db->commit();
        $_SESSION['success'] = "Test result submitted successfully!";
        
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Error submitting test result: " . $e->getMessage();
    }
    
    header("Location: project_tasks.php?project_id=$projectId");
    exit;
}

// Get assigned pages for this project
$pagesQuery = "
    SELECT pp.id, pp.page_name, pp.url, pe.page_id, pe.environment_id, pe.status,
           te.name as environment_name, te.browser, te.assistive_tech,
           tr.status as last_test_status, tr.tested_at as last_tested
    FROM project_pages pp
    JOIN page_environments pe ON pp.id = pe.page_id
    JOIN testing_environments te ON pe.environment_id = te.id
    LEFT JOIN testing_results tr ON pp.id = tr.page_id AND pe.environment_id = tr.environment_id 
                                  AND tr.tester_id = ? AND tr.tester_role = 'at_tester'
                                  AND tr.id = (SELECT MAX(id) FROM testing_results tr2 
                                             WHERE tr2.page_id = pp.id AND tr2.environment_id = pe.environment_id 
                                             AND tr2.tester_id = ? AND tr2.tester_role = 'at_tester')
    WHERE pp.project_id = ? AND pe.at_tester_id = ?
    ORDER BY pp.page_name, te.name
";

$pagesStmt = $db->prepare($pagesQuery);
$pagesStmt->execute([$userId, $userId, $projectId, $userId]);
$pages = $pagesStmt->fetchAll();

// Get testing environments
$environmentsQuery = "SELECT * FROM testing_environments WHERE type = ? ORDER BY name";
$environmentsStmt = $db->prepare($environmentsQuery);
$environmentsStmt->execute([$project['project_type']]);
$environments = $environmentsStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="fas fa-tasks text-primary"></i> AT Testing Tasks</h2>
                    <p class="text-muted mb-0">
                        Project: <strong><?php echo htmlspecialchars($project['title']); ?></strong> 
                        (<?php echo $project['po_number']; ?>)
                    </p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> Assigned Pages</h5>
        </div>
        <div class="card-body">
            <?php if (empty($pages)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No pages assigned for AT testing in this project</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Page Name</th>
                                <th>Environment</th>
                                <th>Status</th>
                                <th>Last Test</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pages as $page): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($page['page_name']); ?></strong>
                                    <?php if ($page['url']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($page['url']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($page['environment_name']); ?></strong>
                                    <?php if ($page['browser']): ?>
                                        <br><small class="text-muted">Browser: <?php echo htmlspecialchars($page['browser']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($page['assistive_tech']): ?>
                                        <br><small class="text-muted">AT: <?php echo htmlspecialchars($page['assistive_tech']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = 'secondary';
                                    $statusText = 'Not Tested';
                                    
                                    if ($page['status'] === 'tested') {
                                        $statusClass = 'success';
                                        $statusText = 'Tested';
                                    } elseif ($page['status'] === 'testing_failed') {
                                        $statusClass = 'danger';
                                        $statusText = 'Failed';
                                    } elseif ($page['status'] === 'in_testing') {
                                        $statusClass = 'warning';
                                        $statusText = 'In Testing';
                                    }
                                    ?>
                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                    
                                    <?php if ($page['last_test_status']): ?>
                                        <br><small class="text-muted">
                                            Last: <?php echo ucfirst($page['last_test_status']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($page['last_tested']): ?>
                                        <?php echo date('M j, Y g:i A', strtotime($page['last_tested'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#testModal"
                                            onclick="openTestModal(<?php echo $page['id']; ?>, <?php echo $page['environment_id']; ?>, '<?php echo htmlspecialchars($page['page_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($page['environment_name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-vial"></i> Test
                                    </button>
                                    
                                    <?php if ($page['url']): ?>
                                        <a href="<?php echo htmlspecialchars($page['url']); ?>" 
                                           target="_blank" 
                                           class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-external-link-alt"></i> Open
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Test Result Modal -->
<div class="modal fade" id="testModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Submit AT Test Result</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="page_id" id="modal_page_id">
                    <input type="hidden" name="environment_id" id="modal_environment_id">
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>Page:</strong></label>
                        <p id="modal_page_name" class="form-control-plaintext"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>Environment:</strong></label>
                        <p id="modal_environment_name" class="form-control-plaintext"></p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Test Result <span class="text-danger">*</span></label>
                                <select name="status" class="form-select" required>
                                    <option value="">Select Result</option>
                                    <option value="pass">Pass</option>
                                    <option value="fail">Fail</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Issues Found</label>
                                <input type="number" name="issues_found" class="form-control" min="0" value="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Hours Spent <span class="text-danger">*</span></label>
                        <input type="number" name="hours_spent" class="form-control" step="0.25" min="0.25" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Comments/Notes</label>
                        <textarea name="comments" class="form-control" rows="4" placeholder="Describe any issues found, testing approach, or other relevant notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_test" class="btn btn-primary">Submit Test Result</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openTestModal(pageId, environmentId, pageName, environmentName) {
    document.getElementById('modal_page_id').value = pageId;
    document.getElementById('modal_environment_id').value = environmentId;
    document.getElementById('modal_page_name').textContent = pageName;
    document.getElementById('modal_environment_name').textContent = environmentName;
    
    // Reset form
    document.querySelector('#testModal form').reset();
    document.getElementById('modal_page_id').value = pageId;
    document.getElementById('modal_environment_id').value = environmentId;
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>