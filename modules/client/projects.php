<?php
/**
 * Client Projects Listing Page
 * 
 * Lists all assigned projects with navigation to individual project dashboards
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/ClientAccessControlManager.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Initialize access control
$accessControl = new ClientAccessControlManager();

// Get client user ID
$clientUserId = $userId;
if (in_array($userRole, ['admin', 'super_admin']) && isset($_GET['client_id'])) {
    $clientUserId = intval($_GET['client_id']);
}

// Get assigned projects with statistics
$assignedProjects = $accessControl->getAssignedProjects($clientUserId);

// Set page title
$pageTitle = 'My Projects - Client Portal';

// Ensure baseDir is set
if (!isset($baseDir)) {
    require_once __DIR__ . '/../../includes/helpers.php';
    $baseDir = getBaseDir();
}

// Handle flash messages
// picked up by header.php if needed
?>
<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="container-fluid" id="main-content" tabindex="-1">

    
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-header">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="<?php echo $baseDir; ?>/modules/client/dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">
                            <i class="fas fa-folder-open"></i> Projects
                        </li>
                    </ol>
                </nav>
                
                <div class="header-content">
                    <h1 class="page-title">
                        <i class="fas fa-folder-open text-primary"></i>
                        My Projects
                    </h1>
                    <p class="page-subtitle">
                        Browse and analyze your assigned projects
                    </p>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($assignedProjects)): ?>
    
    <!-- Projects Grid -->
    <div class="row">
        <?php foreach ($assignedProjects as $project): 
            // Get project statistics
            $projectStats = $accessControl->getProjectStatistics($clientUserId, $project['id']);
        ?>
        <div class="col-lg-6 col-xl-4 mb-4">
            <div class="project-card">
                <div class="project-header">
                    <h3 class="project-title">
                        <?php echo htmlspecialchars($project['title']); ?>
                    </h3>
                    <span class="project-status badge bg-<?php 
                        echo $project['status'] === 'completed' ? 'success' : 
                             ($project['status'] === 'in_progress' ? 'primary' : 'secondary');
                    ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                    </span>
                </div>
                
                <div class="project-stats">
                    <div class="stat-row">
                        <div class="stat-item">
                            <span class="stat-label">Total Issues</span>
                            <span class="stat-value"><?php echo $projectStats['client_ready_issues'] ?? 0; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Open Issues</span>
                            <span class="stat-value text-warning"><?php echo $projectStats['open_issues'] ?? 0; ?></span>
                        </div>
                    </div>
                    <div class="stat-row">
                        <div class="stat-item">
                            <span class="stat-label">Resolved</span>
                            <span class="stat-value text-success"><?php echo $projectStats['resolved_issues'] ?? 0; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Compliance</span>
                            <span class="stat-value text-info"><?php echo round($projectStats['compliance_score'] ?? 0, 1); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="project-actions">
                    <a href="<?php echo $baseDir; ?>/modules/client/project_dashboard.php?id=<?php echo $project['id']; ?>" 
                       class="btn btn-primary btn-sm">
                        <i class="fas fa-chart-line"></i> View Analytics
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                       class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eye"></i> Project Details
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php else: ?>
    
    <!-- No Projects State -->
    <div class="row">
        <div class="col-12">
            <div class="no-projects-state">
                <div class="no-projects-icon">
                    <i class="fas fa-folder-open fa-4x text-muted"></i>
                </div>
                <h3>No Projects Assigned</h3>
                <p class="text-muted">
                    You don't have any projects assigned to your account yet. 
                    Please contact your administrator to get started.
                </p>
                <a href="<?php echo $baseDir; ?>/modules/client/dashboard.php" class="btn btn-primary">
                    <i class="fas fa-tachometer-alt"></i> Return to Dashboard
                </a>
            </div>
        </div>
    </div>
    
    <?php endif; ?>

</div>

<style>
.page-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e9ecef;
    margin-bottom: 2rem;
}

.breadcrumb {
    background: none;
    padding: 0;
    margin-bottom: 16px;
}

.breadcrumb-item a {
    color: #2563eb;
    text-decoration: none;
}

.breadcrumb-item a:hover {
    text-decoration: underline;
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 8px;
}

.page-subtitle {
    color: #6c757d;
    font-size: 1.1rem;
    margin: 0;
}

.project-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 24px;
    height: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.project-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    border-color: #2563eb;
}

.project-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    gap: 12px;
}

.project-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
    line-height: 1.3;
    flex: 1;
}

.project-status {
    font-size: 0.8rem;
    padding: 4px 8px;
    flex-shrink: 0;
}

.project-stats {
    margin-bottom: 20px;
}

.stat-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
}

.stat-row:last-child {
    margin-bottom: 0;
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    flex: 1;
}

.stat-label {
    font-size: 0.8rem;
    color: #6c757d;
    margin-bottom: 4px;
    font-weight: 500;
}

.stat-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2c3e50;
}

.project-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.project-actions .btn {
    flex: 1;
    font-size: 0.875rem;
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 500;
}

.no-projects-state {
    text-align: center;
    padding: 60px 20px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px dashed #dee2e6;
}

.no-projects-icon {
    margin-bottom: 24px;
    opacity: 0.6;
}

.no-projects-state h3 {
    color: #2c3e50;
    margin-bottom: 16px;
}

.no-projects-state p {
    font-size: 1.1rem;
    margin-bottom: 24px;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}

/* Responsive Design */
@media (max-width: 768px) {
    .page-header {
        padding: 20px;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .project-card {
        padding: 20px;
    }
    
    .project-header {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    
    .project-status {
        align-self: flex-start;
    }
    
    .stat-row {
        margin-bottom: 16px;
    }
    
    .project-actions {
        flex-direction: column;
    }
    
    .project-actions .btn {
        flex: none;
    }
}

@media (max-width: 576px) {
    .page-title {
        font-size: 1.25rem;
    }
    
    .project-card {
        padding: 16px;
    }
    
    .project-title {
        font-size: 1.1rem;
    }
    
    .no-projects-state {
        padding: 40px 16px;
    }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>