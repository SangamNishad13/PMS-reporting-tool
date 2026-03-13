<?php
/**
 * Individual Project Dashboard Template
 * 
 * Detailed analytics view for a specific project with enhanced detail levels
 * Implements navigation between projects and unified dashboard
 * 
 * Requirements: 13.1, 13.2, 13.4, 13.5
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/controllers/UnifiedDashboardController.php';
require_once __DIR__ . '/../../includes/models/ClientAccessControlManager.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Get project ID from URL
$projectId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$projectId) {
    $_SESSION['error'] = 'Project ID is required.';
    redirect('/modules/client/dashboard.php');
}

// Initialize controllers
$dashboardController = new UnifiedDashboardController();
$accessControl = new ClientAccessControlManager();

// Get client user ID
$clientUserId = $userId;
if (in_array($userRole, ['admin', 'super_admin']) && isset($_GET['client_id'])) {
    $clientUserId = intval($_GET['client_id']);
}

// Verify project access
if (!$accessControl->hasProjectAccess($clientUserId, $projectId)) {
    $_SESSION['error'] = 'You do not have access to this project.';
    redirect('/modules/client/dashboard.php');
}

// Get project information
$stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    redirect('/modules/client/dashboard.php');
}

// Get project analytics data
$projectAnalytics = $dashboardController->generateProjectAnalytics($projectId, $clientUserId);

// Get assigned projects for navigation
$assignedProjects = $accessControl->getAssignedProjects($clientUserId);

// Set page title
$pageTitle = htmlspecialchars($project['title']) . ' - Project Analytics';

// Prepare additional CSS
$additionalCSS = '
<link href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.css" rel="stylesheet">
' . $dashboardController->visualization->getVisualizationCSS();

// Start output buffering for page content
ob_start();
?>

<div class="container-fluid">
    
    <!-- Project Navigation -->
    <?php include __DIR__ . '/partials/project_navigation.php'; ?>
    
    <!-- Project Header -->
    <?php include __DIR__ . '/partials/project_header.php'; ?>
    
    <!-- Project Summary -->
    <?php include __DIR__ . '/partials/project_summary.php'; ?>
    
    <!-- Project Analytics Widgets -->
    <?php include __DIR__ . '/partials/project_analytics.php'; ?>
    
    <!-- Project Actions -->
    <?php include __DIR__ . '/partials/project_actions.php'; ?>
    
    <!-- Enhanced View Option -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="enhanced-view-promotion">
                <div class="promotion-content">
                    <div class="promotion-icon">
                        <i class="fas fa-rocket text-primary"></i>
                    </div>
                    <div class="promotion-text">
                        <h4>Try Enhanced Analytics</h4>
                        <p>Get deeper insights with our enhanced project analytics featuring detailed drill-downs, comparison views, and AI-powered recommendations.</p>
                    </div>
                    <div class="promotion-action">
                        <a href="<?php echo $baseDir; ?>/modules/client/project_dashboard_enhanced.php?id=<?php echo $projectId; ?>" 
                           class="btn btn-primary btn-lg">
                            <i class="fas fa-microscope"></i> Enhanced View
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Chart.js and Dashboard Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php echo $dashboardController->visualization->getVisualizationJS(); ?>

<script>
// Project-specific JavaScript functionality
document.addEventListener('DOMContentLoaded', function() {
    // Initialize project analytics
    initializeProjectAnalytics();
    
    // Setup navigation handlers
    setupProjectNavigation();
});

function initializeProjectAnalytics() {
    // Load project-specific analytics data
    const projectId = <?php echo $projectId; ?>;
    const clientUserId = <?php echo $clientUserId; ?>;
    
    // Any project-specific initialization can go here
}

function setupProjectNavigation() {
    // Handle project switching
    const projectSelect = document.getElementById('projectNavSelect');
    if (projectSelect) {
        projectSelect.addEventListener('change', function() {
            if (this.value) {
                window.location.href = `<?php echo $baseDir; ?>/modules/client/project_dashboard.php?id=${this.value}`;
            }
        });
    }
}
</script>

<style>
.enhanced-view-promotion {
    background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
    border: 1px solid #2196f3;
    border-radius: 12px;
    padding: 0;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(33,150,243,0.15);
}

.promotion-content {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 24px;
}

.promotion-icon {
    font-size: 3rem;
    color: #2196f3;
    flex-shrink: 0;
}

.promotion-text {
    flex: 1;
}

.promotion-text h4 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
}

.promotion-text p {
    font-size: 1rem;
    color: #6c757d;
    margin: 0;
    line-height: 1.5;
}

.promotion-action {
    flex-shrink: 0;
}

.promotion-action .btn {
    font-weight: 600;
    border-radius: 8px;
    padding: 12px 24px;
    box-shadow: 0 2px 8px rgba(33,150,243,0.3);
    transition: all 0.3s ease;
}

.promotion-action .btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(33,150,243,0.4);
}

@media (max-width: 768px) {
    .promotion-content {
        flex-direction: column;
        text-align: center;
        gap: 16px;
        padding: 20px;
    }
    
    .promotion-icon {
        font-size: 2.5rem;
    }
    
    .promotion-text h4 {
        font-size: 1.1rem;
    }
    
    .promotion-text p {
        font-size: 0.9rem;
    }
    
    .promotion-action .btn {
        padding: 10px 20px;
    }
}
</style>

<?php
// Capture the page content
$pageContent = ob_get_clean();

// Include the complete page template
include __DIR__ . '/../../includes/client_page_template.php';
?>