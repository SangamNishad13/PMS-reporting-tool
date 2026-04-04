<?php
/**
 * Dashboard Analytics Widgets Partial
 * 
 * Grid layout of analytics widgets with drill-down capabilities
 */

$analyticsWidgets = $dashboardData['analytics_widgets'] ?? [];
$assignedProjects = $dashboardData['assigned_projects'] ?? [];
$projectIdsList = implode(',', array_column($assignedProjects, 'id'));
$activeReport = (string) ($_GET['report'] ?? '');
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="section-title">
            <i class="fas fa-chart-line text-primary"></i>
            Analytics Reports
        </h2>
    </div>
</div>

<!-- Analytics Widgets Grid -->
<div class="analytics-widgets-grid">
    <?php if (!empty($analyticsWidgets)): ?>
        
        <!-- User Impact Analysis Widget -->
        <?php if (isset($analyticsWidgets['user_affected'])): ?>
        <div class="widget-container<?php echo $activeReport === 'user_affected' ? ' is-active' : ''; ?>" id="analytics-report-user_affected">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['user_affected']); ?>
        </div>
        <?php endif; ?>

        <!-- WCAG Compliance Widget -->
        <?php if (isset($analyticsWidgets['wcag_compliance'])): ?>
        <div class="widget-container<?php echo $activeReport === 'wcag_compliance' ? ' is-active' : ''; ?>" id="analytics-report-wcag_compliance">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['wcag_compliance']); ?>
        </div>
        <?php endif; ?>

        <!-- Severity Analysis Widget -->
        <?php if (isset($analyticsWidgets['severity_analysis'])): ?>
        <div class="widget-container<?php echo $activeReport === 'severity_analysis' ? ' is-active' : ''; ?>" id="analytics-report-severity_analysis">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['severity_analysis']); ?>
        </div>
        <?php endif; ?>

        <!-- Common Issues Widget -->
        <?php if (isset($analyticsWidgets['common_issues'])): ?>
        <div class="widget-container<?php echo $activeReport === 'common_issues' ? ' is-active' : ''; ?>" id="analytics-report-common_issues">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['common_issues']); ?>
        </div>
        <?php endif; ?>

        <!-- Blocker Issues Widget -->
        <?php if (isset($analyticsWidgets['blocker_issues'])): ?>
        <div class="widget-container<?php echo $activeReport === 'blocker_issues' ? ' is-active' : ''; ?>" id="analytics-report-blocker_issues">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['blocker_issues']); ?>
        </div>
        <?php endif; ?>

        <!-- Page Analysis Widget -->
        <?php if (isset($analyticsWidgets['page_issues'])): ?>
        <div class="widget-container<?php echo $activeReport === 'page_issues' ? ' is-active' : ''; ?>" id="analytics-report-page_issues">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['page_issues']); ?>
        </div>
        <?php endif; ?>

        <!-- Discussion Activity Widget -->
        <?php if (isset($analyticsWidgets['commented_issues'])): ?>
        <div class="widget-container<?php echo $activeReport === 'commented_issues' ? ' is-active' : ''; ?>" id="analytics-report-commented_issues">
            <?php echo $dashboardController->visualization->renderDashboardWidget('analytics', $analyticsWidgets['commented_issues']); ?>
        </div>
        <?php endif; ?>

        <!-- Compliance Trends Widget (Full Width) -->
        <?php if (isset($analyticsWidgets['compliance_trend'])): ?>
        <div class="widget-container widget-full-width<?php echo $activeReport === 'compliance_trend' ? ' is-active' : ''; ?>" id="analytics-report-compliance_trend">
            <?php echo $dashboardController->visualization->renderDashboardWidget('trend', $analyticsWidgets['compliance_trend']); ?>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- No Data State -->
        <div class="col-12">
            <div class="no-data-state text-center py-5">
                <div class="no-data-icon mb-4">
                    <i class="fas fa-chart-bar fa-4x text-muted opacity-50"></i>
                </div>
                <h3 class="text-muted">No Analytics Data Available</h3>
                <p class="text-muted mb-4">
                    Analytics widgets will appear here once you have accessibility issues in your assigned digital assets.
                </p>
                <a href="<?php echo $baseDir; ?>/modules/client/projects.php" class="btn btn-primary">
                    <i class="fas fa-folder-open"></i> View Digital Assets
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.analytics-widgets-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 24px;
    margin-bottom: 2rem;
}

.widget-container {
    position: relative;
}

.widget-container.widget-full-width {
    grid-column: 1 / -1;
}

.widget-container .dashboard-widget {
    height: 100%;
    min-height: 300px;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    overflow: hidden;
}

.widget-container .dashboard-widget:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    border-color: #2563eb;
}

.widget-container.is-active .dashboard-widget,
.widget-container .dashboard-widget.is-active {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15), 0 12px 30px rgba(37, 99, 235, 0.12);
}

.widget-container .widget-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 1px solid #dee2e6;
    padding: 16px 20px;
}

.widget-container .widget-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.widget-container .widget-title i {
    margin-right: 8px;
    opacity: 0.8;
}

.widget-container .widget-action {
    color: #6c757d;
    font-size: 0.9rem;
    transition: color 0.2s ease;
}

.widget-container .widget-action:hover {
    color: #2563eb;
}

.widget-container .widget-content {
    padding: 20px;
}

.analytics-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.summary-metric {
    text-align: center;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.summary-metric .metric-label {
    font-size: 0.8rem;
    color: #6c757d;
    margin-bottom: 4px;
    font-weight: 500;
}

.summary-metric .metric-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2c3e50;
    line-height: 1;
}

.widget-actions {
    margin-top: 16px;
    text-align: center;
}

.widget-actions .btn {
    font-size: 0.875rem;
    padding: 6px 16px;
    border-radius: 6px;
    font-weight: 500;
}

.no-data-state {
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px dashed #dee2e6;
    margin: 2rem 0;
}

.no-data-icon {
    opacity: 0.6;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .analytics-widgets-grid {
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }
}

@media (max-width: 768px) {
    .analytics-widgets-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .widget-container .dashboard-widget {
        min-height: 250px;
    }
    
    .widget-container .widget-content {
        padding: 16px;
    }
    
    .analytics-summary {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
    
    .summary-metric {
        padding: 8px;
    }
    
    .summary-metric .metric-value {
        font-size: 1.1rem;
    }
}

@media (max-width: 576px) {
    .analytics-widgets-grid {
        gap: 12px;
    }
    
    .widget-container .widget-header {
        padding: 12px 16px;
    }
    
    .widget-container .widget-title {
        font-size: 1rem;
    }
    
    .analytics-summary {
        grid-template-columns: 1fr;
    }
}
</style>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/client-dashboard-widgets.js"></script>