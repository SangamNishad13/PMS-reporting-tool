<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

// Get project details
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$pageTitle = 'Accessibility Report - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>">
                        <?php echo htmlspecialchars($project['title']); ?>
                    </a></li>
                    <li class="breadcrumb-item active">Accessibility Report</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1">
                        <i class="fas fa-universal-access text-primary me-2"></i>
                        Accessibility Report
                    </h2>
                    <p class="text-muted mb-0">
                        Project: <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                        <?php if ($project['client_name']): ?>
                            | Client: <strong><?php echo htmlspecialchars($project['client_name']); ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Project
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Pages Card -->
        <div class="col-md-6">
            <div class="card h-100 border-primary">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-file-alt fa-4x text-primary"></i>
                    </div>
                    <h4 class="card-title">Pages</h4>
                    <p class="card-text text-muted">
                        View page-wise final accessibility issues and reports
                    </p>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_pages.php?project_id=<?php echo $projectId; ?>" 
                       class="btn btn-primary btn-lg">
                        <i class="fas fa-arrow-right me-1"></i> View Pages
                    </a>
                </div>
            </div>
        </div>

        <!-- Common Issues Card -->
        <div class="col-md-6">
            <div class="card h-100 border-info">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-layer-group fa-4x text-info"></i>
                    </div>
                    <h4 class="card-title">Common Issues</h4>
                    <p class="card-text text-muted">
                        Manage issues that apply across multiple pages in the project
                    </p>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_common.php?project_id=<?php echo $projectId; ?>" 
                       class="btn btn-info btn-lg">
                        <i class="fas fa-arrow-right me-1"></i> View Common Issues
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Export Issues Card -->
        <div class="col-md-6">
            <div class="card h-100 border-success">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-file-export fa-4x text-success"></i>
                    </div>
                    <h4 class="card-title">Export Issues</h4>
                    <p class="card-text text-muted">
                        Export issues to Excel or PDF with customizable columns and filters
                    </p>
                    <a href="<?php echo $baseDir; ?>/modules/projects/export_issues.php?project_id=<?php echo $projectId; ?>" 
                       class="btn btn-success btn-lg">
                        <i class="fas fa-download me-1"></i> Export Issues
                    </a>
                </div>
            </div>
        </div>
        
        <!-- All Issues Card -->
        <div class="col-md-6">
            <div class="card h-100 border-warning">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-list fa-4x text-warning"></i>
                    </div>
                    <h4 class="card-title">All Issues</h4>
                    <p class="card-text text-muted">
                        View, edit, and manage all issues in one comprehensive list
                    </p>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_all.php?project_id=<?php echo $projectId; ?>" 
                       class="btn btn-warning btn-lg">
                        <i class="fas fa-list me-1"></i> View All Issues
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
