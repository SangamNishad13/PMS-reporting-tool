<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/client_permissions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$baseDir = getBaseDir();

// Check if user has any project permissions
if (!hasAnyProjectPermissions($db, $userId)) {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

// Get projects user has permissions for
$projectsWithCreate = getProjectsWithPermission($db, $userId, 'create_project');
$projectsWithEdit = getProjectsWithPermission($db, $userId, 'edit_project');
$projectsWithView = getProjectsWithPermission($db, $userId, 'view_project');

// Merge all project IDs
$allProjectIds = array_unique(array_merge($projectsWithCreate, $projectsWithEdit, $projectsWithView));

// Get projects details
$projects = [];
if (!empty($allProjectIds)) {
    $placeholders = str_repeat('?,', count($allProjectIds) - 1) . '?';
    $stmt = $db->prepare("
        SELECT p.*, c.name as client_name, u.full_name as project_lead_name,
               COUNT(DISTINCT pp.id) as total_pages,
               COUNT(DISTINCT CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN pp.id END) as completed_pages
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        LEFT JOIN users u ON p.project_lead_id = u.id
        LEFT JOIN project_pages pp ON p.id = pp.project_id
        WHERE p.id IN ($placeholders)
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute($allProjectIds);
    $projects = $stmt->fetchAll();
}

// Get clients that have accessible projects
$clientIds = getClientsWithAccessibleProjects($db, $userId);

// Get project permissions details
$permissionsStmt = $db->prepare("
    SELECT cp.*, p.title as project_title, p.po_number as project_code, c.name as client_name, cpt.description
    FROM client_permissions cp
    JOIN projects p ON cp.project_id = p.id
    JOIN clients c ON p.client_id = c.id
    LEFT JOIN client_permissions_types cpt ON cp.permission_type = cpt.permission_type
    WHERE cp.user_id = ? AND cp.is_active = 1 AND cp.project_id IS NOT NULL
    AND (cp.expires_at IS NULL OR cp.expires_at > NOW())
    ORDER BY c.name, p.title, cp.permission_type
");
$permissionsStmt->execute([$userId]);
$userPermissions = $permissionsStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h2><i class="fas fa-briefcase"></i> My Projects</h2>
            <p class="text-muted">Projects you can manage based on your permissions</p>

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

            <!-- Your Permissions Card -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-key"></i> Your Project Permissions</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($userPermissions)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Project</th>
                                        <th>Permission</th>
                                        <th>Description</th>
                                        <th>Expires</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userPermissions as $perm): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($perm['client_name']); ?></strong></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($perm['project_code']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($perm['project_title']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary">
                                                <?php echo ucfirst(str_replace('_', ' ', $perm['permission_type'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($perm['description'] ?? ''); ?></td>
                                        <td>
                                            <?php if ($perm['expires_at']): ?>
                                                <span class="badge bg-warning">
                                                    <?php echo date('M d, Y', strtotime($perm['expires_at'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Never</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No active permissions found.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <?php if (!empty($projectsWithCreate)): ?>
            <div class="card mb-4 border-success">
                <div class="card-body">
                    <h5><i class="fas fa-plus-circle text-success"></i> Projects You Can Create</h5>
                    <p class="text-muted">You have create permissions for <?php echo count($projectsWithCreate); ?> project(s).</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Projects List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Projects (<?php echo count($projects); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($projects)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Project Code</th>
                                        <th>Title</th>
                                        <th>Client</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                        <th>Your Access</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projects as $project): 
                                        $canCreate = in_array($project['id'], $projectsWithCreate);
                                        $canEdit = in_array($project['id'], $projectsWithEdit);
                                        $canView = in_array($project['id'], $projectsWithView);
                                        
                                        $progress = 0;
                                        if ($project['total_pages'] > 0) {
                                            $progress = round(($project['completed_pages'] / $project['total_pages']) * 100);
                                        }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($project['po_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($project['title']); ?></td>
                                        <td><?php echo htmlspecialchars($project['client_name']); ?></td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?php echo formatProjectStatusLabel($project['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px; min-width: 100px;">
                                                <div class="progress-bar" style="width: <?php echo $progress; ?>%">
                                                    <?php echo $progress; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($canEdit): ?>
                                                <span class="badge bg-primary" title="Can edit this project">Edit</span>
                                            <?php endif; ?>
                                            <?php if ($canView): ?>
                                                <span class="badge bg-info" title="Can view this project">View</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <?php if ($canEdit): ?>
                                                <a href="<?php echo $baseDir; ?>/modules/projects/edit.php?id=<?php echo $project['id']; ?>" 
                                                   class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No projects found with your current permissions.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 