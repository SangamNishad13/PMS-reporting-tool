<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['at_tester', 'admin', 'super_admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Get AT Tester's assigned projects and pages (ONLY ACTIVE/IN-PROGRESS)
$assignedProjectsQuery = "
    SELECT DISTINCT p.id, p.title, p.po_number, p.status, p.project_type,
           COUNT(DISTINCT pp.id) as total_pages,
           COUNT(DISTINCT CASE WHEN pe.at_tester_id = ? THEN pp.id END) as assigned_pages,
           COUNT(DISTINCT CASE WHEN pe.status = 'tested' AND pe.at_tester_id = ? THEN pp.id END) as completed_pages
    FROM projects p
    LEFT JOIN project_pages pp ON p.id = pp.project_id
    LEFT JOIN page_environments pe ON pp.id = pe.page_id
    WHERE (pe.at_tester_id = ? OR pp.at_tester_id = ?)
    AND p.status IN ('in_progress', 'planning')
    GROUP BY p.id, p.title, p.po_number, p.status, p.project_type
    ORDER BY p.created_at DESC
    LIMIT 5
";

$assignedProjects = $db->prepare($assignedProjectsQuery);
$assignedProjects->execute([$userId, $userId, $userId, $userId]);
$projects = $assignedProjects->fetchAll();

// Get recent testing activities
$recentActivitiesQuery = "
    SELECT tr.*, pp.page_name, p.title as project_title, te.name as environment_name
    FROM testing_results tr
    JOIN project_pages pp ON tr.page_id = pp.id
    JOIN projects p ON pp.project_id = p.id
    JOIN testing_environments te ON tr.environment_id = te.id
    WHERE tr.tester_id = ? AND tr.tester_role = 'at_tester'
    ORDER BY tr.tested_at DESC
    LIMIT 10
";

$recentActivities = $db->prepare($recentActivitiesQuery);
$recentActivities->execute([$userId]);
$activities = $recentActivities->fetchAll();

// Get pending tasks: all assigned tasks except on-hold/completed
$pendingTasksQuery = "
    SELECT pp.id, pp.page_name, p.title as project_title, pe.status, te.name as environment_name,
           p.id as project_id
    FROM project_pages pp
    JOIN projects p ON pp.project_id = p.id
    JOIN page_environments pe ON pp.id = pe.page_id
    JOIN testing_environments te ON pe.environment_id = te.id
    WHERE pe.at_tester_id = ? 
    AND (pe.status IS NULL OR LOWER(pe.status) NOT IN ('on_hold', 'hold', 'completed', 'tested', 'pass'))
    AND p.status NOT IN ('completed', 'cancelled')
    ORDER BY 
        CASE pe.status 
            WHEN 'fail' THEN 1
            WHEN 'testing_failed' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'in_testing' THEN 2
            WHEN 'not_started' THEN 3
            WHEN 'not_tested' THEN 3
            WHEN '' THEN 4
        END,
        pp.created_at ASC
    LIMIT 20
";

$pendingTasks = $db->prepare($pendingTasksQuery);
$pendingTasks->execute([$userId]);
$tasks = $pendingTasks->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-desktop text-primary"></i> AT Tester Dashboard</h2>
                <div>
                    <span class="badge bg-info">Accessibility Testing</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?php echo count($projects); ?></h4>
                            <p class="mb-0">Assigned Projects</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-project-diagram fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?php echo count($tasks); ?></h4>
                            <p class="mb-0">Pending Tasks</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-clock fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <?php 
                            $totalCompleted = 0;
                            foreach($projects as $project) {
                                $totalCompleted += $project['completed_pages'];
                            }
                            ?>
                            <h4><?php echo $totalCompleted; ?></h4>
                            <p class="mb-0">Completed Tests</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?php echo count($activities); ?></h4>
                            <p class="mb-0">Recent Activities</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-history fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- All Assigned Projects Table with Filters -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-project-diagram"></i> Active Projects</h5>
                    <a href="<?php echo $baseDir; ?>/modules/at_tester/my_projects.php" class="btn btn-sm btn-primary">
                        <i class="fas fa-list"></i> View All Projects
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($projects)): ?>
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
                                    <?php foreach ($projects as $project): ?>
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
                                            <a href="<?php echo $baseDir; ?>/modules/at_tester/project_tasks.php?project_id=<?php echo $project['id']; ?>" 
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
        </div>
    </div>

    <div class="row">
        <!-- Pending Tasks -->
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-tasks"></i> Pending Tasks</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($tasks)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-check-circle fa-3x mb-3"></i>
                            <p>No pending tasks</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($tasks as $task): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div class="flex-grow-1">
                                    <strong><?php echo htmlspecialchars($task['page_name']); ?></strong><br>
                                    <small class="text-muted">
                                        <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $task['project_id']; ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($task['project_title']); ?>
                                        </a> - 
                                        <?php echo htmlspecialchars($task['environment_name']); ?>
                                    </small>
                                </div>
                                <div>
                                    <span class="badge bg-<?php 
                                        echo $task['status'] === 'fail' ? 'danger' : 
                                            ($task['status'] === 'in_progress' ? 'info' : 'warning'); 
                                    ?>">
                                        <?php echo formatProjectStatusLabel($task['status']); ?>
                                    </span>
                                    <a href="<?php echo $baseDir; ?>/modules/at_tester/project_tasks.php?project_id=<?php echo $task['project_id']; ?>" 
                                       class="btn btn-sm btn-primary ms-2">
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activities -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-history"></i> Recent Testing Activities</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($activities)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-history fa-3x mb-3"></i>
                            <p>No recent activities</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Project</th>
                                        <th>Page</th>
                                        <th>Environment</th>
                                        <th>Status</th>
                                        <th>Issues</th>
                                        <th>Hours</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activities as $activity): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($activity['tested_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($activity['project_title']); ?></td>
                                        <td><?php echo htmlspecialchars($activity['page_name']); ?></td>
                                        <td><?php echo htmlspecialchars($activity['environment_name']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $activity['status'] === 'pass' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($activity['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $activity['issues_found']; ?></td>
                                        <td><?php echo $activity['hours_spent']; ?>h</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
