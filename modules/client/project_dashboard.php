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
if (in_array($userRole, ['admin']) && isset($_GET['client_id'])) {
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

if (!isset($cspNonce) && function_exists('generateCspNonce')) {
    $cspNonce = generateCspNonce();
}

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
    

</div>

<!-- Chart.js and Dashboard Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php echo $dashboardController->visualization->getVisualizationJS(); ?>

<script nonce="<?php echo htmlspecialchars($cspNonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">window._clientProjectConfig = { projectId: <?php echo (int)$projectId; ?>, clientUserId: <?php echo (int)$clientUserId; ?>, baseDir: '<?php echo $baseDir; ?>' };</script>
<script src="<?php echo $baseDir; ?>/assets/js/client-project-dashboard.js?v=<?php echo time(); ?>"></script>



<?php
// Capture the page content
$pageContent = ob_get_clean();

// Include the complete page template
include __DIR__ . '/../../includes/client_page_template.php';
