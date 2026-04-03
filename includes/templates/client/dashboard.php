<?php
/**
 * Client Dashboard Template
 * Displays unified analytics dashboard with interactive charts
 */

// Ensure we have the required data
$assignedProjects = $assignedProjects ?? [];
$dashboardData = $dashboardData ?? [];
$csrfToken = $csrfToken ?? '';
$clientUser = $clientUser ?? [];
$pageTitle = $pageTitle ?? 'Analytics Dashboard';

// Extract project IDs for JavaScript
$projectIds = array_column($assignedProjects, 'id');

require_once __DIR__ . '/../../header.php';
?>

<div class="container py-4">
    <div class="client-dashboard">
            <?php 
            // Map local variables to what partials expect
            // $dashboardController is passed from the controller
            
            try {
                include __DIR__ . '/../../../modules/client/partials/dashboard_header.php'; 
            } catch (Exception $e) {
                echo '<div class="alert alert-warning">Dashboard header could not be loaded.</div>';
            }
            
            try {
                include __DIR__ . '/../../../modules/client/partials/dashboard_summary.php'; 
            } catch (Exception $e) {
                echo '<div class="alert alert-warning">Dashboard summary could not be loaded.</div>';
            }
            
            try {
                include __DIR__ . '/../../../modules/client/partials/dashboard_widgets.php'; 
            } catch (Exception $e) {
                echo '<div class="alert alert-warning">Dashboard widgets could not be loaded.</div>';
            }
            
            try {
                include __DIR__ . '/../../../modules/client/partials/dashboard_actions.php'; 
            } catch (Exception $e) {
                echo '<div class="alert alert-warning">Dashboard actions could not be loaded.</div>';
            }
            ?>
        </div>
    </div>
<?php 
// Chart.js and Dashboard Scripts 
?>

<?php 
try {
    if (isset($dashboardController) && isset($dashboardController->visualization) && method_exists($dashboardController->visualization, 'getVisualizationJS')) {
        echo $dashboardController->visualization->getVisualizationJS(); 
    }
} catch (Exception $e) {
    // Ignore JS error
}
?>

<?php 
require_once __DIR__ . '/../../footer.php';
?>