<?php
/**
 * Unified Dashboard View Template
 * 
 * Responsive dashboard layout with widget grid for client analytics
 * Implements drill-down navigation to detailed reports
 * 
 * Requirements: 12.3, 12.4, 13.5
 */

// Analytics dashboard for client users

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/controllers/UnifiedDashboardController.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Initialize dashboard controller
$dashboardController = new UnifiedDashboardController();

// Get client user ID (either current user or admin viewing specific client)
$clientUserId = $userId;
if (in_array($userRole, ['admin', 'super_admin']) && isset($_GET['client_id'])) {
    $clientUserId = intval($_GET['client_id']);
}

// Generate dashboard data
try {
    $dashboardData = $dashboardController->generateUnifiedDashboard($clientUserId);
} catch (Exception $e) {
    error_log("Dashboard generation error: " . $e->getMessage());
    $dashboardData = [
        'onboarding' => false,
        'project_statistics' => [
            'total_projects' => 0,
            'client_ready_issues' => 0,
            'total_issues' => 0
        ],
        'assigned_projects' => []
    ];
}

// Determine actual client_id for export
$actualClientId = 0;
if (in_array($userRole, ['admin', 'super_admin']) && isset($_GET['client_id'])) {
    $actualClientId = intval($_GET['client_id']);
} elseif (!empty($dashboardData['assigned_projects'])) {
    // For client users, take the client_id from their first assigned project
    $actualClientId = $dashboardData['assigned_projects'][0]['client_id'];
}

// Set page title for header.php
$pageTitle = 'Analytics Dashboard - Client Reporting';

// Ensure baseDir is set correctly
if (!isset($baseDir)) {
    require_once __DIR__ . '/../../includes/helpers.php';
    $baseDir = getBaseDir();
}

// Handle flash messages
// These will be picked up by header.php if included after, 
// but dashboard_unified.php has its own toast logic below.
?>
<?php include __DIR__ . '/../../includes/header.php'; ?>

<!-- Dashboard Styles -->
<?php 
try {
    if (isset($dashboardController->visualization) && method_exists($dashboardController->visualization, 'getVisualizationCSS')) {
        echo $dashboardController->visualization->getVisualizationCSS(); 
    }
} catch (Exception $e) {
    error_log("Visualization CSS error: " . $e->getMessage());
}
?>

<div class="container-fluid" id="main-content" tabindex="-1">
    <?php if (($dashboardData['onboarding'] ?? false) || empty($dashboardData['assigned_projects'])): ?>
        <!-- Onboarding Dashboard -->
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info">
                    <h4>Welcome to Client Analytics Dashboard</h4>
                    <p>No projects are currently assigned to you. Please contact your administrator to get access to projects.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Analytics Dashboard -->
        <?php 
        try {
            include __DIR__ . '/partials/dashboard_header.php'; 
        } catch (Exception $e) {
            echo '<div class="alert alert-warning">Dashboard header could not be loaded.</div>';
        }
        ?>
        
        <?php 
        try {
            include __DIR__ . '/partials/dashboard_summary.php'; 
        } catch (Exception $e) {
            echo '<div class="alert alert-warning">Dashboard summary could not be loaded.</div>';
        }
        ?>
        
        <?php 
        try {
            include __DIR__ . '/partials/dashboard_widgets.php'; 
        } catch (Exception $e) {
            echo '<div class="alert alert-warning">Dashboard widgets could not be loaded.</div>';
        }
        ?>
        
        <?php 
        try {
            include __DIR__ . '/partials/dashboard_actions.php'; 
        } catch (Exception $e) {
            echo '<div class="alert alert-warning">Dashboard actions could not be loaded.</div>';
        }
        ?>
    <?php endif; ?>
</div>

<!-- Chart.js and Dashboard Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php 
try {
    if (isset($dashboardController->visualization) && method_exists($dashboardController->visualization, 'getVisualizationJS')) {
        echo $dashboardController->visualization->getVisualizationJS(); 
    }
} catch (Exception $e) {
    error_log("Visualization JS error: " . $e->getMessage());
}
?>

<script>
// Initialize dashboard
initializeDashboard();

function initializeDashboard() {
    
    // Add any missing functionality here
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(button => {
        if (!button.onclick && button.getAttribute('onclick')) {
            // Ensure onclick handlers work
            const onclickAttr = button.getAttribute('onclick');
            button.addEventListener('click', function() {
                try {
                    eval(onclickAttr);
                } catch (e) {
                    console.error('Button click error:', e);
                }
            });
        }
    });
}

// Export functions
function exportDashboard(format) {
    const clientId = '<?php echo $actualClientId; ?>';
    const projectId = '<?php echo $selectedProjectId ?? ""; ?>';
    const baseUrl = '<?php echo $baseDir; ?>/api/client_export.php';
    
    if (!clientId || clientId === '0') {
        alert('Cannot export: Client information not found.');
        return;
    }
    
    // Provide visual feedback
    const btn = event ? event.target.closest('.btn') : null;
    let originalHtml = '';
    if (btn) {
        originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        btn.disabled = true;
    }
    
    // Construct final URL
    let exportUrl = `${baseUrl}?client_id=${clientId}&format=${format}`;
    if (projectId) {
        exportUrl += `&project_id=${projectId}`;
    }
    
    if (format === 'pdf') {
        window.open(exportUrl, '_blank');
        // Reset button state since we didn't redirect away
        setTimeout(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }, 2000);
    } else {
        window.location.href = exportUrl;
        // Reset button state after a delay in case the browser stays on the same page
        setTimeout(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }, 2000);
    }
}

function refreshDashboard() {
    window.location.reload();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
