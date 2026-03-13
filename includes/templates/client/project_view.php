<?php
/**
 * Client Project View Template
 * Displays detailed analytics for a single project
 */

// Ensure we have the required data
$projectAnalytics = $projectAnalytics ?? [];
$projectId = $projectId ?? 0;
$clientUser = $clientUser ?? [];
$csrfToken = $csrfToken ?? '';
$baseDir = $baseDir ?? '';

$projectName = $projectAnalytics['project_name'] ?? 'Project';
$projectDescription = $projectAnalytics['project_description'] ?? '';

$pageTitle = $projectName;
require_once __DIR__ . '/../../header.php';
?>

<div class="container py-4">
    <div class="project-analytics-view">
        <!-- Project Header -->
        <div class="row mb-4 align-items-center">
            <div class="col-md-8">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-2">
                        <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/client/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Project Details</li>
                    </ol>
                </nav>
                <h1 class="h3 mb-2"><?php echo htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8'); ?></h1>
                <?php if ($projectDescription): ?>
                <p class="text-muted mb-0">
                    <?php echo htmlspecialchars($projectDescription, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-md-end">
                <div class="btn-group" role="group" aria-label="Project actions">
                    <button type="button" class="btn btn-outline-primary" data-action="refresh" title="Refresh Data">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" data-action="export" data-format="pdf" data-project="<?php echo $projectId; ?>">
                                <i class="fas fa-file-pdf text-danger me-2"></i>Export as PDF
                            </a></li>
                            <li><a class="dropdown-item" href="#" data-action="export" data-format="xlsx" data-project="<?php echo $projectId; ?>">
                                <i class="fas fa-file-excel text-success me-2"></i>Export as Excel
                            </a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Project Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <div class="h2 mb-1 text-primary">
                            <?php echo $projectAnalytics['client_ready_issues'] ?? ($projectAnalytics['total_issues'] ?? 0); ?>
                        </div>
                        <div class="text-muted small">Total Issues</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <div class="h2 mb-1 text-success">
                            <?php echo $projectAnalytics['resolved_issues'] ?? 0; ?>
                        </div>
                        <div class="text-muted small">Resolved</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <div class="h2 mb-1 text-warning">
                            <?php echo $projectAnalytics['pending_issues'] ?? 0; ?>
                        </div>
                        <div class="text-muted small">Pending</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <div class="h2 mb-1 text-info">
                            <?php echo number_format($projectAnalytics['compliance_percentage'] ?? 0, 1); ?>%
                        </div>
                        <div class="text-muted small">Compliance</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Analytics Grid -->
        <div class="row g-4">
            <!-- User Affected Analytics -->
            <div class="col-lg-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-users text-primary me-2"></i>
                            Users Affected Analysis
                        </h5>
                    </div>
                    <div class="card-body" id="widget-user_affected_project">
                        <div class="text-center mb-3">
                            <canvas id="projectUserAffectedChart" width="400" height="300"></canvas>
                        </div>
                        <div class="widget-summary text-center text-muted small"></div>
                    </div>
                </div>
            </div>

            <!-- WCAG Compliance Breakdown -->
            <div class="col-lg-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            WCAG Compliance Breakdown
                        </h5>
                    </div>
                    <div class="card-body" id="widget-wcag_compliance_project">
                        <div class="text-center mb-3">
                            <canvas id="projectWcagChart" width="400" height="300"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Severity Distribution -->
            <div class="col-lg-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                            Issue Severity Distribution
                        </h5>
                    </div>
                    <div class="card-body" id="widget-severity_project">
                        <div class="text-center mb-3">
                            <canvas id="projectSeverityChart" width="400" height="300"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Page Issues Detailed -->
            <div class="col-lg-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-file-alt text-secondary me-2"></i>
                            Page Issues Analysis
                        </h5>
                    </div>
                    <div class="card-body" id="widget-page_issues_project">
                        <div class="widget-table">
                            <!-- Table will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Common Issues for Project -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list text-info me-2"></i>
                            Most Common Issues
                        </h5>
                    </div>
                    <div class="card-body" id="widget-common_issues_project">
                        <div class="widget-table">
                            <!-- Table will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compliance Trend for Project -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-line text-success me-2"></i>
                            Compliance Trend (Last 30 Days)
                        </h5>
                    </div>
                    <div class="card-body" id="widget-compliance_trend_project">
                        <div class="text-center mb-3">
                            <canvas id="projectComplianceTrendChart" width="800" height="300"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
require_once __DIR__ . '/../../footer.php';
?>