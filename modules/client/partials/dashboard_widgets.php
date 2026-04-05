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

<div class="section-heading">
    <div>
        <span class="section-kicker">Report Workspace</span>
        <h2 class="section-title mb-2">Analytics reports</h2>
        <p class="section-description mb-0">Each tile summarizes a different slice of accessibility performance. Use the shortcuts below to jump directly to the report you need.</p>
    </div>
</div>

<?php if (!empty($analyticsWidgets)): ?>
<div class="analytics-shortcuts" aria-label="Analytics report shortcuts">
    <?php if (isset($analyticsWidgets['user_affected'])): ?><a class="analytics-shortcut-pill" href="#analytics-report-user_affected">User Impact</a><?php endif; ?>
    <?php if (isset($analyticsWidgets['wcag_compliance'])): ?><a class="analytics-shortcut-pill" href="#analytics-report-wcag_compliance">WCAG</a><?php endif; ?>
    <?php if (isset($analyticsWidgets['severity_analysis'])): ?><a class="analytics-shortcut-pill" href="#analytics-report-severity_analysis">Severity</a><?php endif; ?>
    <?php if (isset($analyticsWidgets['common_issues'])): ?><a class="analytics-shortcut-pill" href="#analytics-report-common_issues">Common Issues</a><?php endif; ?>
    <?php if (isset($analyticsWidgets['blocker_issues'])): ?><a class="analytics-shortcut-pill" href="#analytics-report-blocker_issues">Blockers</a><?php endif; ?>
    <?php if (isset($analyticsWidgets['page_issues'])): ?><a class="analytics-shortcut-pill" href="#analytics-report-page_issues">Pages</a><?php endif; ?>
    <?php if (isset($analyticsWidgets['commented_issues'])): ?><a class="analytics-shortcut-pill" href="#analytics-report-commented_issues">Discussion</a><?php endif; ?>
    <?php if (isset($analyticsWidgets['compliance_trend'])): ?><a class="analytics-shortcut-pill" href="#analytics-report-compliance_trend">Trend</a><?php endif; ?>
</div>
<?php endif; ?>

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

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/client-dashboard-widgets.js"></script>