<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['qa', 'admin', 'super_admin']);

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$db = Database::getInstance();

// Get QA's assigned pages
if (hasAdminPrivileges()) {
    // Admin can see all pages that need QA
    $pages = $db->prepare("
        SELECT pp.*, p.title as project_title, p.priority,
               at_user.full_name as at_tester_name,
               ft_user.full_name as ft_tester_name,
               tr.status as test_status, tr.comments as test_comments
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        LEFT JOIN users at_user ON pp.at_tester_id = at_user.id
        LEFT JOIN users ft_user ON pp.ft_tester_id = ft_user.id
        LEFT JOIN testing_results tr ON pp.id = tr.page_id AND tr.tester_role IN ('at_tester', 'ft_tester')
        WHERE p.status NOT IN ('completed', 'cancelled')
        AND (pp.status IS NULL OR LOWER(pp.status) NOT IN ('on_hold', 'hold', 'completed'))
        ORDER BY 
            CASE p.priority 
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            pp.created_at
    ");
    $pages->execute();
    $pagesList = $pages->fetchAll();
} else {
    $pages = $db->prepare("
        SELECT pp.*, p.title as project_title, p.priority,
               at_user.full_name as at_tester_name,
               ft_user.full_name as ft_tester_name,
               tr.status as test_status, tr.comments as test_comments
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        LEFT JOIN users at_user ON pp.at_tester_id = at_user.id
        LEFT JOIN users ft_user ON pp.ft_tester_id = ft_user.id
        LEFT JOIN testing_results tr ON pp.id = tr.page_id AND tr.tester_role IN ('at_tester', 'ft_tester')
        WHERE pp.qa_id = ? 
        AND p.status NOT IN ('completed', 'cancelled')
        AND (pp.status IS NULL OR LOWER(pp.status) NOT IN ('on_hold', 'hold', 'completed'))
        ORDER BY 
            CASE p.priority 
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            pp.created_at
    ");
    $pages->execute([$userId]);
    $pagesList = $pages->fetchAll();
}

// Get QA stats
if (hasAdminPrivileges()) {
    // Admin sees stats for all QA work
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN pp.status IS NULL OR LOWER(pp.status) NOT IN ('on_hold', 'hold', 'completed') THEN pp.id END) as pending_pages,
            COUNT(DISTINCT CASE WHEN pp.status = 'completed' THEN pp.id END) as completed_pages,
            COUNT(DISTINCT CASE WHEN pp.status = 'in_fixing' THEN pp.id END) as fixing_pages,
            COUNT(DISTINCT pp.project_id) as total_assigned_projects
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE p.status NOT IN ('cancelled')
    ");
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get Active Projects Count for admin (all active projects)
    $projStats = $db->prepare("
        SELECT COUNT(*) 
        FROM projects p 
        WHERE p.status NOT IN ('completed', 'cancelled')
    ");
    $projStats->execute();
    $stats['active_projects'] = $projStats->fetchColumn();
} else {
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN pp.status IS NULL OR LOWER(pp.status) NOT IN ('on_hold', 'hold', 'completed') THEN pp.id END) as pending_pages,
            COUNT(DISTINCT CASE WHEN pp.status = 'completed' THEN pp.id END) as completed_pages,
            COUNT(DISTINCT CASE WHEN pp.status = 'in_fixing' THEN pp.id END) as fixing_pages,
            COUNT(DISTINCT pp.project_id) as total_assigned_projects
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        WHERE pp.qa_id = ? AND p.status NOT IN ('cancelled')
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get Active Projects Count (from user_assignments) - refined to not include completed/cancelled
    $projStats = $db->prepare("
        SELECT COUNT(*) 
        FROM user_assignments ua 
        JOIN projects p ON ua.project_id = p.id 
        WHERE ua.user_id = ? AND ua.role = 'qa' 
        AND p.status NOT IN ('completed', 'cancelled')
    ");
    $projStats->execute([$userId]);
    $stats['active_projects'] = $projStats->fetchColumn();
}

// Get Completed Projects Count
$compProjStats = $db->prepare("
    SELECT COUNT(*) 
    FROM user_assignments ua 
    JOIN projects p ON ua.project_id = p.id 
    WHERE ua.user_id = ? AND ua.role = 'qa' 
    AND p.status = 'completed'
");
$compProjStats->execute([$userId]);
$stats['completed_projects'] = $compProjStats->fetchColumn();


// Get Active Projects List (ONLY active/in-progress, limit 5)
$assignedProjectsQuery = "
    SELECT DISTINCT p.id, p.title, p.po_number, p.status, p.project_type,
           COUNT(DISTINCT pp.id) as total_pages,
           COUNT(DISTINCT CASE WHEN pp.qa_id = ? THEN pp.id END) as assigned_pages,
           COUNT(DISTINCT CASE WHEN pp.status = 'completed' AND pp.qa_id = ? THEN pp.id END) as completed_pages
    FROM projects p
    JOIN user_assignments ua ON p.id = ua.project_id
    LEFT JOIN project_pages pp ON p.id = pp.project_id
    WHERE ua.user_id = ? AND ua.role = 'qa'
    AND p.status IN ('in_progress', 'planning')
    GROUP BY p.id, p.title, p.po_number, p.status, p.project_type
    ORDER BY p.created_at DESC
    LIMIT 5
";

$assignedProjects = $db->prepare($assignedProjectsQuery);
$assignedProjects->execute([$userId, $userId, $userId]);
$activeProjects = $assignedProjects->fetchAll();

?>

<style>
    .clickable-widget {
        transition: transform 0.2s, box-shadow 0.2s;
        cursor: pointer;
    }
    .clickable-widget:hover {
        transform: translateY(-5px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    }
</style>

<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>QA Dashboard</h2>
        <a href="page_assignment.php" class="btn btn-primary">
            <i class="fas fa-users-cog"></i> Manage Page Assignments
        </a>
    </div>
    
    <!-- Welcome Card -->
    <div class="card mb-3 bg-light">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <h4>Welcome, <?php echo $_SESSION['full_name']; ?>!</h4>
                    <p class="mb-0">You have <?php echo (int)$stats['pending_pages']; ?> pages pending review.</p>
                </div>
                <div class="col-md-8">
                    <div class="row g-2">
                        <div class="col-md-6 col-lg-3">
                            <a href="qa_tasks.php?tab=pending" class="text-decoration-none">
                                <div class="widget widget-primary clickable-widget h-100 p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="fs-4 mb-0"><?php echo (int)$stats['pending_pages']; ?></h3>
                                            <p class="small text-muted mb-0">Pending</p>
                                        </div>
                                        <i class="fas fa-clock fs-3 text-primary opacity-25 position-static"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <a href="qa_tasks.php?tab=fixing" class="text-decoration-none">
                                <div class="widget widget-danger clickable-widget h-100 p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="fs-4 mb-0"><?php echo (int)$stats['fixing_pages']; ?></h3>
                                            <p class="small text-muted mb-0">In Fixing</p>
                                        </div>
                                        <i class="fas fa-tools fs-3 text-danger opacity-25 position-static"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <a href="my_projects.php" class="text-decoration-none">
                                <div class="widget widget-success clickable-widget h-100 p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="fs-4 mb-0"><?php echo (int)$stats['completed_projects']; ?></h3>
                                            <p class="small text-muted mb-0">Completed</p>
                                        </div>
                                        <i class="fas fa-check-circle fs-3 text-success opacity-25 position-static"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <a href="my_projects.php" class="text-decoration-none">
                                <div class="widget widget-warning clickable-widget h-100 p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="fs-4 mb-0"><?php echo (int)$stats['active_projects']; ?></h3>
                                            <p class="small text-muted mb-0">Active</p>
                                        </div>
                                        <i class="fas fa-project-diagram fs-3 text-warning opacity-25 position-static"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
    
    <!-- Active Projects Table -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-project-diagram"></i> Active Projects</h5>
            <a href="<?php echo $baseDir; ?>/modules/qa/my_projects.php" class="btn btn-sm btn-primary">
                <i class="fas fa-list"></i> View All Projects
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($activeProjects)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No active projects assigned</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Project Title</th>
                                <th>Project Code</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Assigned Pages</th>
                                <th>Completed</th>
                                <th>Progress</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeProjects as $project): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($project['po_number']); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo ucfirst(str_replace('_', ' ', $project['project_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'planning' => 'secondary',
                                        'in_progress' => 'primary',
                                        'on_hold' => 'warning',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                    $statusColor = $statusColors[$project['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $statusColor; ?>">
                                        <?php echo formatProjectStatusLabel($project['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $project['assigned_pages']; ?></td>
                                <td><?php echo $project['completed_pages']; ?></td>
                                <td>
                                    <?php 
                                    $progress = $project['assigned_pages'] > 0 ? 
                                        round(($project['completed_pages'] / $project['assigned_pages']) * 100) : 0;
                                    ?>
                                    <div class="progress" style="height: 20px; min-width: 100px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%">
                                            <?php echo $progress; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                                       class="btn btn-sm btn-info me-1">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?php echo $baseDir; ?>/modules/qa/qa_tasks.php?project_id=<?php echo $project['id']; ?>" 
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-tasks"></i> Tasks
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    
    <!-- My Regression Tasks -->
    <?php
        // Get regression tasks assigned to this QA user (direct tasks + assignments)
        $regStmt = $db->prepare(
                "SELECT rt.id, rt.project_id, rt.page_id, rt.environment_id,
                    CAST(rt.title AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as title,
                    CAST(rt.description AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as description,
                    rt.assigned_user_id,
                    CAST(rt.assigned_role AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as assigned_role,
                    CAST(rt.phase AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as phase,
                    CAST(rt.status AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as status,
                    rt.created_at,
                    CAST(p.title AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as project_title,
                    CAST(pp.page_name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as page_name,
                    CAST(e.name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as env_name
            FROM regression_tasks rt
            LEFT JOIN projects p ON rt.project_id = p.id
            LEFT JOIN project_pages pp ON rt.page_id = pp.id
            LEFT JOIN testing_environments e ON rt.environment_id = e.id
            WHERE (rt.assigned_user_id = ? OR rt.assigned_role = ?
               OR EXISTS (SELECT 1 FROM assignments a WHERE a.task_type = 'regression' AND a.assigned_user_id = ? AND (
                   (a.page_id IS NOT NULL AND a.page_id = rt.page_id) OR
                   (a.environment_id IS NOT NULL AND a.environment_id = rt.environment_id) OR
                   (a.project_id IS NOT NULL AND a.project_id = rt.project_id)
               )))

            UNION ALL

                 SELECT CAST(CONCAT('assign-', a.id) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as id, a.project_id, a.page_id, a.environment_id,
                     CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.meta, '$.title')), 'Regression Assignment') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as title,
                       CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as description,
                       a.assigned_user_id,
                       CAST(a.assigned_role AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as assigned_role,
                       CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as phase,
                       CAST('assigned' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as status,
                       a.created_at,
                     CAST(p.title AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as project_title, CAST(pp.page_name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as page_name, CAST(e.name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as env_name
            FROM assignments a
            LEFT JOIN projects p ON a.project_id = p.id
            LEFT JOIN project_pages pp ON a.page_id = pp.id
            LEFT JOIN testing_environments e ON a.environment_id = e.id
            WHERE a.task_type = 'regression' AND a.assigned_user_id = ?

            ORDER BY created_at DESC"
        );
        $regStmt->execute([$userId, $userRole, $userId, $userId]);
        $regTasks = $regStmt->fetchAll();
    ?>
    <div class="card mb-3">
        <div class="card-header">
            <h5>My Regression Tasks</h5>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($regTasks)): ?>
            <table class="table table-sm mb-0">
                <thead><tr><th>Title</th><th>Project</th><th>Page / Env</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                <?php foreach ($regTasks as $rt): ?>
                <tr>
                    <?php
                        $projId = $rt['project_id'] ?? ''; 
                        $projLink = $baseDir . '/modules/projects/view.php?id=' . urlencode($projId) . '#regression';
                        $rawId = (string)($rt['id'] ?? '');
                        if (strpos($rawId, 'assign-') === 0) {
                            $assignId = intval(substr($rawId, 7));
                            $taskLink = $baseDir . '/modules/projects/view.php?id=' . urlencode($projId) . '&open_reg_assignment=' . $assignId . '#regression';
                        } else {
                            $taskLink = $baseDir . '/modules/projects/view.php?id=' . urlencode($projId) . '&open_reg_task=' . urlencode($rawId) . '#regression';
                        }
                    ?>
                    <td><a href="<?php echo htmlspecialchars($taskLink); ?>"><?php echo htmlspecialchars($rt['title']); ?></a></td>
                    <td><a href="<?php echo htmlspecialchars($projLink); ?>"><?php echo htmlspecialchars($rt['project_title'] ?? '—'); ?></a></td>
                    <td><?php echo htmlspecialchars($rt['page_name'] ?? $rt['env_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($rt['status']); ?></td>
                    <td><?php echo date('M d, H:i', strtotime($rt['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="p-2 text-muted">No regression tasks assigned.</div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Pages for QA Review -->
    <div class="card">
        <div class="card-header">
            <h5>Pages Pending QA Review</h5>
        </div>
        <div class="card-body">
            <?php if ($pages->rowCount() > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Page/Screen</th>
                            <th>Project</th>
                            <th>Testers</th>
                            <th>Test Status</th>
                            <th>Current Status</th>
                            <th>Priority</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagesList as $page): ?>
                        <tr>
                            <td>
                                <strong><?php echo $page['page_name']; ?></strong><br>
                                <small class="text-muted"><?php echo $page['url'] ?: $page['screen_name']; ?></small>
                            </td>
                            <td><?php echo $page['project_title']; ?></td>
                            <td>
                                <?php
                                    $atHtml = $page['at_tester_name'] ?: getAssignedNamesHtml($db, $page, 'at_tester');
                                    $ftHtml = $page['ft_tester_name'] ?: getAssignedNamesHtml($db, $page, 'ft_tester');
                                ?>
                                <?php if ($atHtml): ?>
                                    <small>AT: <?php echo $atHtml; ?></small><br>
                                <?php endif; ?>
                                <?php if ($ftHtml): ?>
                                    <small>FT: <?php echo $ftHtml; ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $envs = $db->prepare("SELECT e.id, e.name, pe.status FROM page_environments pe JOIN testing_environments e ON pe.environment_id = e.id WHERE pe.page_id = ?");
                                $envs->execute([$page['id']]);
                                $pageEnvs = $envs->fetchAll();
                                if (empty($pageEnvs)):
                                    echo '<span class="text-muted">No environments</span>';
                                else:
                                    foreach ($pageEnvs as $pe):
                                ?>
                                    <div class="mb-1 d-flex align-items-center justify-content-between">
                                        <small class="text-nowrap me-2">
                                            <strong><?php echo htmlspecialchars($pe['name']); ?>:</strong>
                                        </small>
                                        <?php echo renderEnvStatusDropdown($page['id'], $pe['id'], $pe['status']); ?>
                                    </div>
                                <?php endforeach; endif; ?>
                            </td>
                            <td>
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
                                <?php echo renderPageStatusDropdown($page['id'], $computed); ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $page['priority'] === 'critical' ? 'danger' : 
                                         ($page['priority'] === 'high' ? 'warning' : 'secondary');
                                ?>">
                                    <?php echo ucfirst($page['priority']); ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#qaReviewModal<?php echo $page['id']; ?>">
                                    <i class="fas fa-check-circle"></i> Review
                                </button>
                                <a href="<?php echo $baseDir; ?>/modules/chat/project_chat.php?page_id=<?php echo $page['id']; ?>" 
                                   class="btn btn-sm btn-success" title="Discuss">
                                    <i class="fas fa-comments"></i>
                                </a>
                            </td>
                        </tr>
                        
                        <!-- QA Review Modal placeholder (moved below table to avoid invalid nesting) -->
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i> Great! You don't have any pages pending QA review.
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php // Render QA Review Modals after the table to avoid invalid DOM nesting ?>
    <?php if (!empty($pagesList)): ?>
        <?php foreach ($pagesList as $page): ?>
            <div class="modal fade" id="qaReviewModal<?php echo $page['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="<?php echo $baseDir; ?>/modules/qa/qa_tasks.php">
                            <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">QA Review: <?php echo htmlspecialchars($page['page_name']); ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label>Environment (Optional - to update specific env status)</label>
                                    <select name="environment_id" class="form-select">
                                        <option value="">-- All Environments / General --</option>
                                        <?php
                                        $envs = $db->prepare("SELECT e.id, e.name, pe.status FROM page_environments pe JOIN testing_environments e ON pe.environment_id = e.id WHERE pe.page_id = ?");
                                        $envs->execute([$page['id']]);
                                        while ($e = $envs->fetch()):
                                        ?>
                                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['name']); ?> (<?php echo ucfirst($e['status']); ?>)</option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>QA Status *</label>
                                    <select name="qa_status" class="form-select" required>
                                        <option value="pass">Pass</option>
                                        <option value="fail">Fail - Send for Fixing</option>
                                        <option value="na">Not Applicable / On Hold</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Issues Found</label>
                                    <input type="number" name="issues_found" class="form-control" value="0" min="0">
                                </div>
                                <div class="mb-3">
                                    <label>Comments</label>
                                    <textarea name="comments" class="form-control" rows="3" placeholder="Enter QA comments..."></textarea>
                                </div>
                                <?php if (!empty($page['test_comments'])): ?>
                                    <div class="alert alert-info">
                                        <strong>Tester Comments:</strong><br>
                                        <?php echo $page['test_comments']; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="submit" name="update_qa" class="btn btn-primary">Submit QA Review</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Recent QA Activity -->
    <div class="card mt-3">
        <div class="card-header">
            <h5>My Recent QA Activity</h5>
        </div>
        <div class="card-body">
            <?php
            $qaActivity = $db->prepare("
                SELECT qr.*, pp.page_name, p.title as project_title
                FROM qa_results qr
                JOIN project_pages pp ON qr.page_id = pp.id
                JOIN projects p ON pp.project_id = p.id
                WHERE qr.qa_id = ?
                ORDER BY qr.qa_date DESC
                LIMIT 10
            ");
            $qaActivity->execute([$userId]);
            
            if ($qaActivity->rowCount() > 0):
            ?>
            <div class="list-group">
                <?php while ($activity = $qaActivity->fetch()): ?>
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">
                            <i class="fas fa-check text-<?php echo $activity['status'] === 'pass' ? 'success' : 'danger'; ?>"></i>
                            <?php echo $activity['page_name']; ?> - <?php echo $activity['project_title']; ?>
                        </h6>
                        <small class="text-muted"><?php echo date('M d, H:i', strtotime($activity['qa_date'])); ?></small>
                    </div>
                    <p class="mb-1">Status: 
                        <span class="badge bg-<?php echo $activity['status'] === 'pass' ? 'success' : 'danger'; ?>">
                            <?php echo strtoupper($activity['status']); ?>
                        </span>
                        <?php if ($activity['issues_found'] > 0): ?>
                        | Issues: <span class="badge bg-warning"><?php echo $activity['issues_found']; ?></span>
                        <?php endif; ?>
                        <?php if ($activity['hours_spent'] > 0): ?>
                        | Hours: <span class="badge bg-info"><?php echo $activity['hours_spent']; ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if ($activity['comments']): ?>
                    <small class="text-muted"><?php echo substr($activity['comments'], 0, 100); ?>...</small>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="alert-info">
                <i class="fas fa-info-circle"></i> No QA activity recorded yet.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>



<?php include __DIR__ . '/../../includes/footer.php'; ?>
