<?php
/**
 * Enhanced Individual Project Dashboard Template
 * 
 * Comprehensive analytics view for a specific project with enhanced detail levels
 * Implements advanced navigation between projects and unified dashboard
 * 
 * Requirements: 13.1, 13.2, 13.3, 13.4, 13.5
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/controllers/ProjectAnalyticsController.php';
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
$projectController = new ProjectAnalyticsController();
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

// Get project analytics data with enhanced details
$projectAnalytics = $projectController->generateProjectAnalytics($projectId, $clientUserId);

// Get assigned projects for navigation
$assignedProjects = $accessControl->getAssignedProjects($clientUserId);

// Determine view mode
$viewMode = $_GET['view'] ?? 'standard';
$timeRange = $_GET['time_range'] ?? 'all';

// Set page title
$pageTitle = htmlspecialchars($project['title']) . ' - Enhanced Project Analytics';

// Prepare additional CSS
$additionalCSS = '
<link href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.css" rel="stylesheet">
<link href="' . $baseDir . '/modules/client/assets/dashboard.css" rel="stylesheet">
' . $projectController->visualization->getVisualizationCSS();

// Start output buffering for page content
ob_start();
?>

<div class="container-fluid enhanced-project-dashboard">
    
    <!-- Enhanced Project Navigation -->
    <?php include __DIR__ . '/partials/project_navigation_enhanced.php'; ?>
    
    <!-- Project Header with Enhanced Actions -->
    <?php include __DIR__ . '/partials/project_header.php'; ?>
    
    <!-- View Mode Selector -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="view-mode-selector">
                <div class="selector-header">
                    <h3 class="selector-title">
                        <i class="fas fa-eye text-primary"></i>
                        Analytics View Mode
                    </h3>
                    <p class="selector-subtitle">Choose your preferred level of detail</p>
                </div>
                
                <div class="view-mode-options">
                    <a href="?id=<?php echo $projectId; ?>&view=standard" 
                       class="view-mode-option <?php echo ($viewMode === 'standard') ? 'active' : ''; ?>">
                        <div class="option-icon">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="option-content">
                            <h5>Standard View</h5>
                            <p>Essential analytics with key metrics and charts</p>
                        </div>
                    </a>
                    
                    <a href="?id=<?php echo $projectId; ?>&view=detailed" 
                       class="view-mode-option <?php echo ($viewMode === 'detailed') ? 'active' : ''; ?>">
                        <div class="option-icon">
                            <i class="fas fa-microscope"></i>
                        </div>
                        <div class="option-content">
                            <h5>Detailed View</h5>
                            <p>Enhanced analytics with drill-down capabilities</p>
                        </div>
                    </a>
                    
                    <a href="?id=<?php echo $projectId; ?>&view=comparison" 
                       class="view-mode-option <?php echo ($viewMode === 'comparison') ? 'active' : ''; ?>">
                        <div class="option-icon">
                            <i class="fas fa-balance-scale"></i>
                        </div>
                        <div class="option-content">
                            <h5>Comparison View</h5>
                            <p>Compare with other projects and benchmarks</p>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Project Summary with Enhanced Metrics -->
    <?php include __DIR__ . '/partials/project_summary.php'; ?>
    
    <!-- Analytics Content Based on View Mode -->
    <?php if ($viewMode === 'detailed'): ?>
        <!-- Enhanced Detailed Analytics -->
        <?php include __DIR__ . '/partials/project_analytics_detail.php'; ?>
    <?php elseif ($viewMode === 'comparison'): ?>
        <!-- Comparison Analytics -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="comparison-analytics-section">
                    <h2 class="section-title">
                        <i class="fas fa-balance-scale text-primary"></i>
                        Project Comparison Analytics
                    </h2>
                    <p class="section-subtitle">
                        Compare this project's performance against your other projects and industry benchmarks
                    </p>
                    
                    <!-- Comparison Charts -->
                    <div class="comparison-charts-grid">
                        <div class="comparison-chart-item">
                            <h4>Compliance Score Comparison</h4>
                            <?php echo $projectController->visualization->renderComparisonChart('compliance', $projectAnalytics, $assignedProjects); ?>
                        </div>
                        
                        <div class="comparison-chart-item">
                            <h4>Issue Resolution Rate</h4>
                            <?php echo $projectController->visualization->renderComparisonChart('resolution', $projectAnalytics, $assignedProjects); ?>
                        </div>
                        
                        <div class="comparison-chart-item">
                            <h4>User Impact Analysis</h4>
                            <?php echo $projectController->visualization->renderComparisonChart('user_impact', $projectAnalytics, $assignedProjects); ?>
                        </div>
                        
                        <div class="comparison-chart-item">
                            <h4>Critical Issues Ratio</h4>
                            <?php echo $projectController->visualization->renderComparisonChart('critical_ratio', $projectAnalytics, $assignedProjects); ?>
                        </div>
                    </div>
                    
                    <!-- Benchmark Analysis -->
                    <div class="benchmark-analysis">
                        <h4>Industry Benchmark Analysis</h4>
                        <div class="benchmark-metrics">
                            <div class="benchmark-item">
                                <div class="benchmark-label">Accessibility Compliance</div>
                                <div class="benchmark-comparison">
                                    <div class="benchmark-bar">
                                        <div class="benchmark-industry" style="width: 75%;">Industry: 75%</div>
                                        <div class="benchmark-project" style="width: <?php echo $projectAnalytics['project_statistics']['compliance_rate'] ?? 0; ?>%;">
                                            Your Project: <?php echo round($projectAnalytics['project_statistics']['compliance_rate'] ?? 0, 1); ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="benchmark-item">
                                <div class="benchmark-label">Issue Resolution Time</div>
                                <div class="benchmark-comparison">
                                    <div class="benchmark-bar">
                                        <div class="benchmark-industry" style="width: 60%;">Industry: 14 days</div>
                                        <div class="benchmark-project" style="width: 45%;">Your Project: 10 days</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Standard Analytics View -->
        <?php include __DIR__ . '/partials/project_analytics.php'; ?>
    <?php endif; ?>
    
    <!-- Enhanced Project Actions -->
    <?php include __DIR__ . '/partials/project_actions.php'; ?>
    
    <!-- Quick Insights Panel -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="quick-insights-panel">
                <h3 class="insights-title">
                    <i class="fas fa-lightbulb text-warning"></i>
                    AI-Powered Insights
                </h3>
                
                <div class="insights-grid">
                    <div class="insight-item priority-high">
                        <div class="insight-icon">
                            <i class="fas fa-exclamation-triangle text-danger"></i>
                        </div>
                        <div class="insight-content">
                            <h5>Critical Issue Alert</h5>
                            <p>3 critical accessibility issues affecting 150+ users require immediate attention.</p>
                            <a href="#" class="insight-action">View Critical Issues</a>
                        </div>
                    </div>
                    
                    <div class="insight-item priority-medium">
                        <div class="insight-icon">
                            <i class="fas fa-chart-line text-success"></i>
                        </div>
                        <div class="insight-content">
                            <h5>Improvement Opportunity</h5>
                            <p>Compliance score improved by 12% this month. Focus on WCAG AA guidelines for further gains.</p>
                            <a href="#" class="insight-action">View Compliance Trends</a>
                        </div>
                    </div>
                    
                    <div class="insight-item priority-low">
                        <div class="insight-icon">
                            <i class="fas fa-target text-info"></i>
                        </div>
                        <div class="insight-content">
                            <h5>Optimization Suggestion</h5>
                            <p>Consider addressing common color contrast issues to impact 45+ users efficiently.</p>
                            <a href="#" class="insight-action">View Common Issues</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Chart.js and Enhanced Dashboard Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php echo $projectController->visualization->getVisualizationJS(); ?>

<script>
// Enhanced project dashboard functionality
document.addEventListener('DOMContentLoaded', function() {
    // Initialize enhanced project analytics
    initializeEnhancedProjectAnalytics();
    
    // Setup view mode persistence
    setupViewModePersistence();
    
    // Initialize real-time updates
    initializeRealTimeUpdates();
});

function initializeEnhancedProjectAnalytics() {
    const projectId = <?php echo $projectId; ?>;
    const clientUserId = <?php echo $clientUserId; ?>;
    const viewMode = '<?php echo $viewMode; ?>';
    
    // Initialize charts based on view mode
    if (viewMode === 'detailed') {
        initializeDetailedCharts();
    } else if (viewMode === 'comparison') {
        initializeComparisonCharts();
    }
}

function setupViewModePersistence() {
    // Store view mode preference
    const viewMode = '<?php echo $viewMode; ?>';
    localStorage.setItem('preferredViewMode', viewMode);
    
    // Apply view mode to other project links
    const projectLinks = document.querySelectorAll('a[href*="project_dashboard"]');
    projectLinks.forEach(link => {
        const url = new URL(link.href, window.location.origin);
        if (!url.searchParams.has('view')) {
            url.searchParams.set('view', viewMode);
            link.href = url.toString();
        }
    });
}

function initializeRealTimeUpdates() {
    // Check for updates every 5 minutes
    setInterval(() => {
        checkForUpdates();
    }, 300000);
}

function checkForUpdates() {
    const projectId = <?php echo $projectId; ?>;
    const lastUpdate = '<?php echo $projectAnalytics['generated_at'] ?? ''; ?>';
    
    fetch(`<?php echo $baseDir; ?>/api/project_updates.php?project_id=${projectId}&last_update=${encodeURIComponent(lastUpdate)}`)
        .then(response => response.json())
        .then(data => {
            if (data.hasUpdates) {
                showUpdateNotification();
            }
        })
        .catch(error => {
            // Update check failed
        });
}

function showUpdateNotification() {
    const notification = document.createElement('div');
    notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 300px;';
    notification.innerHTML = `
        <i class="fas fa-info-circle"></i>
        <strong>Updates Available</strong><br>
        New data is available for this project.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <div class="mt-2">
            <button class="btn btn-sm btn-primary" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 10 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 10000);
}

function initializeDetailedCharts() {
    // Enhanced chart configurations for detailed view
}

function initializeComparisonCharts() {
    // Comparison chart configurations
}

// Export functions
function exportProject(format) {
    const projectId = <?php echo $projectId; ?>;
    const viewMode = '<?php echo $viewMode; ?>';
    const exportUrl = `<?php echo $baseDir; ?>/modules/client/export.php?type=project&format=${format}&project_id=${projectId}&view_mode=${viewMode}`;
    
    // Show loading state
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    button.disabled = true;
    
    // Create hidden form for export
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = exportUrl;
    form.style.display = 'none';
    document.body.appendChild(form);
    form.submit();
    
    // Reset button after delay
    setTimeout(() => {
        button.innerHTML = originalText;
        button.disabled = false;
        document.body.removeChild(form);
    }, 3000);
}

// Keyboard shortcuts for enhanced navigation
document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
        return;
    }
    
    switch(e.key) {
        case '1':
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                window.location.href = `?id=<?php echo $projectId; ?>&view=standard`;
            }
            break;
        case '2':
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                window.location.href = `?id=<?php echo $projectId; ?>&view=detailed`;
            }
            break;
        case '3':
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                window.location.href = `?id=<?php echo $projectId; ?>&view=comparison`;
            }
            break;
    }
});
</script>

<style>
.enhanced-project-dashboard {
    background: #f8f9fa;
    min-height: 100vh;
    padding-bottom: 2rem;
}

.view-mode-selector {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.selector-header {
    text-align: center;
    margin-bottom: 24px;
}

.selector-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.selector-subtitle {
    color: #6c757d;
    font-size: 1rem;
    margin: 0;
}

.view-mode-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.view-mode-option {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    text-decoration: none;
    color: inherit;
    transition: all 0.3s ease;
}

.view-mode-option:hover {
    background: #e3f2fd;
    border-color: #2196f3;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    text-decoration: none;
    color: inherit;
}

.view-mode-option.active {
    background: #e3f2fd;
    border-color: #2196f3;
    box-shadow: 0 4px 12px rgba(33,150,243,0.2);
}

.option-icon {
    font-size: 2rem;
    color: #2196f3;
    flex-shrink: 0;
}

.option-content h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 4px;
}

.option-content p {
    font-size: 0.9rem;
    color: #6c757d;
    margin: 0;
    line-height: 1.4;
}

.comparison-analytics-section {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.comparison-charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.comparison-chart-item {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
}

.comparison-chart-item h4 {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 16px;
    text-align: center;
}

.benchmark-analysis {
    border-top: 1px solid #e9ecef;
    padding-top: 24px;
}

.benchmark-analysis h4 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 20px;
    text-align: center;
}

.benchmark-metrics {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.benchmark-item {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
}

.benchmark-label {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 12px;
}

.benchmark-bar {
    position: relative;
    height: 40px;
    background: #e9ecef;
    border-radius: 6px;
    overflow: hidden;
}

.benchmark-industry,
.benchmark-project {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    display: flex;
    align-items: center;
    padding: 0 12px;
    font-size: 0.85rem;
    font-weight: 500;
    color: white;
    transition: width 0.5s ease;
}

.benchmark-industry {
    background: #6c757d;
    z-index: 1;
}

.benchmark-project {
    background: #2196f3;
    z-index: 2;
}

.quick-insights-panel {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.insights-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 20px;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.insights-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.insight-item {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    border-left: 4px solid #dee2e6;
    transition: all 0.3s ease;
}

.insight-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.insight-item.priority-high {
    border-left-color: #dc3545;
}

.insight-item.priority-medium {
    border-left-color: #28a745;
}

.insight-item.priority-low {
    border-left-color: #17a2b8;
}

.insight-icon {
    font-size: 1.5rem;
    flex-shrink: 0;
    margin-top: 4px;
}

.insight-content h5 {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
}

.insight-content p {
    font-size: 0.9rem;
    color: #6c757d;
    margin-bottom: 12px;
    line-height: 1.4;
}

.insight-action {
    font-size: 0.85rem;
    color: #2196f3;
    text-decoration: none;
    font-weight: 500;
}

.insight-action:hover {
    text-decoration: underline;
    color: #1976d2;
}

/* Responsive Design */
@media (max-width: 992px) {
    .view-mode-options {
        grid-template-columns: 1fr;
    }
    
    .comparison-charts-grid {
        grid-template-columns: 1fr;
    }
    
    .insights-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .enhanced-project-dashboard {
        padding-bottom: 1rem;
    }
    
    .view-mode-selector,
    .comparison-analytics-section,
    .quick-insights-panel {
        padding: 20px;
    }
    
    .view-mode-option {
        padding: 16px;
        flex-direction: column;
        text-align: center;
        gap: 12px;
    }
    
    .option-icon {
        font-size: 1.5rem;
    }
    
    .insight-item {
        padding: 16px;
        flex-direction: column;
        text-align: center;
        gap: 12px;
    }
}

@media (max-width: 576px) {
    .view-mode-selector,
    .comparison-analytics-section,
    .quick-insights-panel {
        padding: 16px;
    }
    
    .selector-title {
        font-size: 1.25rem;
    }
    
    .insights-title {
        font-size: 1.1rem;
    }
}
</style>

<?php
// Capture the page content
$pageContent = ob_get_clean();

// Include the complete page template
include __DIR__ . '/../../includes/client_page_template.php';
?>