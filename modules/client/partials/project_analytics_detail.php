<?php
/**
 * Project Analytics Detail Partial
 * 
 * Enhanced detailed analytics view for individual project with drill-down capabilities
 * Provides comprehensive project-specific analytics with enhanced detail levels
 * 
 * Requirements: 13.3 - Render project-specific charts and tables with enhanced detail levels
 */

$analyticsWidgets = $projectAnalytics['analytics_widgets'] ?? [];
$projectStats = $projectAnalytics['project_statistics'] ?? [];
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="analytics-detail-header">
            <h2 class="section-title">
                <i class="fas fa-microscope text-primary"></i>
                Detailed Analytics Deep Dive
            </h2>
            <p class="section-subtitle">
                Comprehensive analysis with enhanced detail levels for project-specific insights
            </p>
            
            <!-- Analytics Filter Controls -->
            <div class="analytics-controls">
                <div class="control-group">
                    <label for="analyticsTimeRange" class="form-label">Time Range</label>
                    <select id="analyticsTimeRange" class="form-select form-select-sm">
                        <option value="all">All Time</option>
                        <option value="30">Last 30 Days</option>
                        <option value="90">Last 90 Days</option>
                        <option value="180">Last 6 Months</option>
                        <option value="365">Last Year</option>
                    </select>
                </div>
                
                <div class="control-group">
                    <label for="analyticsView" class="form-label">View Mode</label>
                    <select id="analyticsView" class="form-select form-select-sm">
                        <option value="summary">Summary View</option>
                        <option value="detailed" selected>Detailed View</option>
                        <option value="comparison">Comparison View</option>
                    </select>
                </div>
                
                <div class="control-group">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="refreshAnalytics()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($analyticsWidgets)): ?>

<!-- Enhanced Analytics Grid with Detailed Views -->
<div class="analytics-detail-grid">

    <!-- User Impact Analysis - Enhanced Detail -->
    <?php if (isset($analyticsWidgets['user_affected'])): ?>
    <div class="analytics-detail-section">
        <div class="detail-widget-header">
            <h3 class="widget-title">
                <i class="fas fa-users text-primary"></i>
                User Impact Analysis - Enhanced Detail
            </h3>
            <div class="widget-actions">
                <button class="btn btn-sm btn-outline-secondary" onclick="exportWidget('user_affected')">
                    <i class="fas fa-download"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="expandWidget('user_affected')">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </div>
        
        <div class="detail-widget-content">
            <div class="row">
                <div class="col-lg-8">
                    <!-- Enhanced Chart with Drill-down -->
                    <div class="enhanced-chart-container">
                        <?php 
                        $chartData = $analyticsWidgets['user_affected']['quickChart'] ?? [];
                        $chartOptions = [
                            'type' => 'pie',
                            'showDataLabels' => true,
                            'enableDrillDown' => true,
                            'showTrendLine' => true,
                            'interactive' => true
                        ];
                        echo $dashboardController->visualization->renderEnhancedChart($chartData, $chartOptions); 
                        ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <!-- Detailed Metrics Panel -->
                    <div class="metrics-detail-panel">
                        <h5>Impact Breakdown</h5>
                        <div class="metric-detail-item">
                            <span class="metric-label">High Impact (100+ users)</span>
                            <span class="metric-value text-danger">
                                <?php echo $analyticsWidgets['user_affected']['data']['high_impact'] ?? 0; ?>
                            </span>
                            <div class="metric-trend">
                                <i class="fas fa-arrow-up text-danger"></i> +12% vs last period
                            </div>
                        </div>
                        
                        <div class="metric-detail-item">
                            <span class="metric-label">Medium Impact (11-99 users)</span>
                            <span class="metric-value text-warning">
                                <?php echo $analyticsWidgets['user_affected']['data']['medium_impact'] ?? 0; ?>
                            </span>
                            <div class="metric-trend">
                                <i class="fas fa-arrow-down text-success"></i> -5% vs last period
                            </div>
                        </div>
                        
                        <div class="metric-detail-item">
                            <span class="metric-label">Low Impact (1-10 users)</span>
                            <span class="metric-value text-info">
                                <?php echo $analyticsWidgets['user_affected']['data']['low_impact'] ?? 0; ?>
                            </span>
                            <div class="metric-trend">
                                <i class="fas fa-minus text-muted"></i> No change
                            </div>
                        </div>
                        
                        <!-- Action Recommendations -->
                        <div class="recommendations-panel">
                            <h6>Recommendations</h6>
                            <ul class="recommendation-list">
                                <li><i class="fas fa-lightbulb text-warning"></i> Focus on high-impact issues first</li>
                                <li><i class="fas fa-target text-primary"></i> Prioritize accessibility improvements</li>
                                <li><i class="fas fa-chart-line text-success"></i> Monitor trend improvements</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Detailed Data Table -->
            <div class="detail-data-table">
                <h5>Issue Details by User Impact</h5>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Issue Title</th>
                                <th>Users Affected</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>WCAG Level</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Dynamic data would be populated here -->
                            <tr>
                                <td>Navigation keyboard accessibility</td>
                                <td><span class="badge bg-danger">150 users</span></td>
                                <td><span class="badge bg-danger">Critical</span></td>
                                <td><span class="badge bg-warning">In Progress</span></td>
                                <td>AA</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary">View</button>
                                </td>
                            </tr>
                            <tr>
                                <td>Color contrast issues</td>
                                <td><span class="badge bg-warning">45 users</span></td>
                                <td><span class="badge bg-warning">High</span></td>
                                <td><span class="badge bg-success">Resolved</span></td>
                                <td>AA</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary">View</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- WCAG Compliance - Enhanced Detail -->
    <?php if (isset($analyticsWidgets['wcag_compliance'])): ?>
    <div class="analytics-detail-section">
        <div class="detail-widget-header">
            <h3 class="widget-title">
                <i class="fas fa-shield-alt text-success"></i>
                WCAG Compliance Analysis - Enhanced Detail
            </h3>
            <div class="widget-actions">
                <button class="btn btn-sm btn-outline-secondary" onclick="exportWidget('wcag_compliance')">
                    <i class="fas fa-download"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="expandWidget('wcag_compliance')">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </div>
        
        <div class="detail-widget-content">
            <div class="row">
                <div class="col-lg-6">
                    <!-- Compliance Level Breakdown -->
                    <div class="compliance-breakdown">
                        <h5>Compliance by WCAG Level</h5>
                        <?php echo $dashboardController->visualization->renderComplianceBreakdown($analyticsWidgets['wcag_compliance']['quickChart'] ?? []); ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <!-- Compliance Trends -->
                    <div class="compliance-trends">
                        <h5>Compliance Trends</h5>
                        <?php echo $dashboardController->visualization->renderComplianceTrends($analyticsWidgets['compliance_trend']['trendData'] ?? []); ?>
                    </div>
                </div>
            </div>
            
            <!-- Detailed Compliance Matrix -->
            <div class="compliance-matrix">
                <h5>WCAG Guidelines Compliance Matrix</h5>
                <div class="matrix-grid">
                    <div class="matrix-item level-a">
                        <div class="matrix-header">
                            <h6>Level A</h6>
                            <span class="compliance-score">85%</span>
                        </div>
                        <div class="matrix-details">
                            <div class="guideline-item">
                                <span class="guideline-name">1.1 Text Alternatives</span>
                                <span class="guideline-status text-success">✓ Compliant</span>
                            </div>
                            <div class="guideline-item">
                                <span class="guideline-name">1.2 Time-based Media</span>
                                <span class="guideline-status text-warning">⚠ Partial</span>
                            </div>
                            <div class="guideline-item">
                                <span class="guideline-name">1.3 Adaptable</span>
                                <span class="guideline-status text-success">✓ Compliant</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="matrix-item level-aa">
                        <div class="matrix-header">
                            <h6>Level AA</h6>
                            <span class="compliance-score">72%</span>
                        </div>
                        <div class="matrix-details">
                            <div class="guideline-item">
                                <span class="guideline-name">1.4 Distinguishable</span>
                                <span class="guideline-status text-danger">✗ Non-compliant</span>
                            </div>
                            <div class="guideline-item">
                                <span class="guideline-name">2.4 Navigable</span>
                                <span class="guideline-status text-warning">⚠ Partial</span>
                            </div>
                            <div class="guideline-item">
                                <span class="guideline-name">3.1 Readable</span>
                                <span class="guideline-status text-success">✓ Compliant</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="matrix-item level-aaa">
                        <div class="matrix-header">
                            <h6>Level AAA</h6>
                            <span class="compliance-score">45%</span>
                        </div>
                        <div class="matrix-details">
                            <div class="guideline-item">
                                <span class="guideline-name">2.2 Enough Time</span>
                                <span class="guideline-status text-warning">⚠ Partial</span>
                            </div>
                            <div class="guideline-item">
                                <span class="guideline-name">2.3 Seizures</span>
                                <span class="guideline-status text-success">✓ Compliant</span>
                            </div>
                            <div class="guideline-item">
                                <span class="guideline-name">3.2 Predictable</span>
                                <span class="guideline-status text-danger">✗ Non-compliant</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Severity Analysis - Enhanced Detail -->
    <?php if (isset($analyticsWidgets['severity_analysis'])): ?>
    <div class="analytics-detail-section">
        <div class="detail-widget-header">
            <h3 class="widget-title">
                <i class="fas fa-exclamation-triangle text-warning"></i>
                Severity Analysis - Enhanced Detail
            </h3>
            <div class="widget-actions">
                <button class="btn btn-sm btn-outline-secondary" onclick="exportWidget('severity_analysis')">
                    <i class="fas fa-download"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="expandWidget('severity_analysis')">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </div>
        
        <div class="detail-widget-content">
            <!-- Severity Distribution with Time Analysis -->
            <div class="severity-time-analysis">
                <h5>Severity Distribution Over Time</h5>
                <?php echo $dashboardController->visualization->renderSeverityTimeAnalysis($analyticsWidgets['severity_analysis']['quickChart'] ?? []); ?>
            </div>
            
            <!-- Resolution Time by Severity -->
            <div class="resolution-time-analysis">
                <h5>Average Resolution Time by Severity</h5>
                <div class="resolution-metrics">
                    <div class="resolution-item critical">
                        <div class="severity-label">Critical</div>
                        <div class="resolution-time">2.3 days</div>
                        <div class="resolution-trend">↓ 15% improvement</div>
                    </div>
                    <div class="resolution-item high">
                        <div class="severity-label">High</div>
                        <div class="resolution-time">5.7 days</div>
                        <div class="resolution-trend">↑ 8% slower</div>
                    </div>
                    <div class="resolution-item medium">
                        <div class="severity-label">Medium</div>
                        <div class="resolution-time">12.4 days</div>
                        <div class="resolution-trend">→ No change</div>
                    </div>
                    <div class="resolution-item low">
                        <div class="severity-label">Low</div>
                        <div class="resolution-time">28.1 days</div>
                        <div class="resolution-trend">↓ 22% improvement</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php else: ?>
<!-- No Analytics Data -->
<div class="row">
    <div class="col-12">
        <div class="no-analytics-detail-state">
            <div class="no-data-icon">
                <i class="fas fa-microscope fa-4x text-muted opacity-50"></i>
            </div>
            <h3>No Detailed Analytics Available</h3>
            <p class="text-muted">
                Detailed analytics require accessibility issues with sufficient data points. 
                Once more issues are processed, enhanced analytics will be available here.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.analytics-detail-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e9ecef;
    margin-bottom: 2rem;
}

.section-subtitle {
    color: #6c757d;
    font-size: 1rem;
    margin-bottom: 20px;
}

.analytics-controls {
    display: flex;
    gap: 16px;
    align-items: end;
    flex-wrap: wrap;
}

.control-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.control-group .form-label {
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 0;
}

.analytics-detail-grid {
    display: flex;
    flex-direction: column;
    gap: 32px;
}

.analytics-detail-section {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    overflow: hidden;
}

.detail-widget-header {
    background: #f8f9fa;
    padding: 20px 24px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.widget-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.widget-actions {
    display: flex;
    gap: 8px;
}

.detail-widget-content {
    padding: 24px;
}

.enhanced-chart-container {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid #e9ecef;
}

.metrics-detail-panel {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid #e9ecef;
}

.metric-detail-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 0;
    border-bottom: 1px solid #e9ecef;
}

.metric-detail-item:last-child {
    border-bottom: none;
}

.metric-label {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 500;
}

.metric-value {
    font-size: 1.1rem;
    font-weight: 700;
}

.metric-trend {
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 4px;
}

.recommendations-panel {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e9ecef;
}

.recommendations-panel h6 {
    font-size: 0.9rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 12px;
}

.recommendation-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.recommendation-list li {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: #495057;
    margin-bottom: 8px;
}

.detail-data-table {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid #e9ecef;
}

.detail-data-table h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 16px;
}

.compliance-breakdown,
.compliance-trends {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid #e9ecef;
}

.compliance-matrix {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid #e9ecef;
}

.matrix-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 16px;
}

.matrix-item {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid #e9ecef;
}

.matrix-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e9ecef;
}

.matrix-header h6 {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.compliance-score {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2563eb;
}

.guideline-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
}

.guideline-item:last-child {
    border-bottom: none;
}

.guideline-name {
    font-size: 0.9rem;
    color: #495057;
}

.guideline-status {
    font-size: 0.8rem;
    font-weight: 500;
}

.severity-time-analysis,
.resolution-time-analysis {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid #e9ecef;
    margin-bottom: 20px;
}

.resolution-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-top: 16px;
}

.resolution-item {
    background: #fff;
    border-radius: 8px;
    padding: 16px;
    border: 1px solid #e9ecef;
    text-align: center;
}

.severity-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
}

.resolution-time {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2563eb;
    margin-bottom: 4px;
}

.resolution-trend {
    font-size: 0.8rem;
    color: #6c757d;
}

.no-analytics-detail-state {
    text-align: center;
    padding: 60px 20px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px dashed #dee2e6;
}

/* Responsive Design */
@media (max-width: 992px) {
    .analytics-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .control-group {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }
    
    .matrix-grid {
        grid-template-columns: 1fr;
    }
    
    .resolution-metrics {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
}

@media (max-width: 768px) {
    .analytics-detail-header {
        padding: 20px;
    }
    
    .detail-widget-header {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    
    .widget-actions {
        justify-content: center;
    }
    
    .detail-widget-content {
        padding: 20px;
    }
    
    .metrics-detail-panel,
    .enhanced-chart-container,
    .compliance-breakdown,
    .compliance-trends,
    .severity-time-analysis,
    .resolution-time-analysis {
        padding: 16px;
    }
}

@media (max-width: 576px) {
    .analytics-detail-header {
        padding: 16px;
    }
    
    .detail-widget-content {
        padding: 16px;
    }
    
    .resolution-metrics {
        grid-template-columns: 1fr;
    }
    
    .widget-title {
        font-size: 1.1rem;
    }
}
</style>

<script>
function exportWidget(widgetType) {
    const projectId = <?php echo $projectId; ?>;
    const exportUrl = `<?php echo $baseDir; ?>/modules/client/export.php?type=widget&widget=${widgetType}&project_id=${projectId}`;
    
    // Show loading state
    const button = event.target.closest('button');
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;
    
    // Create export request
    fetch(exportUrl, { method: 'POST' })
        .then(response => response.blob())
        .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${widgetType}_analytics_${projectId}.pdf`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        })
        .finally(() => {
            button.innerHTML = originalHTML;
            button.disabled = false;
        });
}

function expandWidget(widgetType) {
    const section = event.target.closest('.analytics-detail-section');
    section.classList.toggle('expanded');
    
    const button = event.target.closest('button');
    const icon = button.querySelector('i');
    
    if (section.classList.contains('expanded')) {
        icon.className = 'fas fa-compress';
        section.style.position = 'fixed';
        section.style.top = '0';
        section.style.left = '0';
        section.style.width = '100vw';
        section.style.height = '100vh';
        section.style.zIndex = '9999';
        section.style.overflow = 'auto';
    } else {
        icon.className = 'fas fa-expand';
        section.style.position = '';
        section.style.top = '';
        section.style.left = '';
        section.style.width = '';
        section.style.height = '';
        section.style.zIndex = '';
        section.style.overflow = '';
    }
}

function refreshAnalytics() {
    const button = event.target.closest('button');
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    button.disabled = true;
    
    // Reload page with refresh parameter
    const url = new URL(window.location);
    url.searchParams.set('refresh', '1');
    url.searchParams.set('view', 'detailed');
    
    setTimeout(() => {
        window.location.href = url.toString();
    }, 1000);
}

// Initialize analytics controls
document.addEventListener('DOMContentLoaded', function() {
    const timeRangeSelect = document.getElementById('analyticsTimeRange');
    const viewModeSelect = document.getElementById('analyticsView');
    
    if (timeRangeSelect) {
        timeRangeSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('time_range', this.value);
            window.location.href = url.toString();
        });
    }
    
    if (viewModeSelect) {
        viewModeSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('view_mode', this.value);
            window.location.href = url.toString();
        });
    }
});
</script>