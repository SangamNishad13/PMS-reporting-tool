<?php
/**
 * Dashboard Summary Cards Partial
 * 
 * Overview statistics cards showing key metrics
 */

$projectStats = $dashboardData['project_statistics'] ?? [];
$totalProjects = $projectStats['total_projects'] ?? 0;
$clientReadyIssues = $projectStats['client_ready_issues'] ?? 0;
// For client view, client-ready issues ARE the total issues
$totalIssues = $clientReadyIssues; // Hide internal total count from client
$compliancePercentage = $dashboardData['compliance_percentage'] ?? 0;
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="section-title">
            <i class="fas fa-chart-bar text-primary"></i>
            Overview Statistics
        </h2>
    </div>
</div>

<div class="row mb-4">
    <!-- Total Projects Card -->
    <div class="col-lg-4 col-md-6 mb-3">
        <div class="summary-card card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="summary-icon mb-3">
                    <i class="fas fa-folder-open fa-2x text-primary"></i>
                </div>
                <h3 class="summary-value text-primary"><?php echo number_format($totalProjects); ?></h3>
                <p class="summary-label mb-2">Assigned Projects</p>
                <small class="text-muted">Projects you have access to</small>
            </div>
        </div>
    </div>

    <!-- Total Issues Card -->
    <div class="col-lg-4 col-md-6 mb-3">
        <div class="summary-card card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="summary-icon mb-3">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                </div>
                <h3 class="summary-value text-warning"><?php echo number_format($totalIssues); ?></h3>
                <p class="summary-label mb-2">Total Issues</p>
                <small class="text-muted">Accessibility issues in your projects</small>
            </div>
        </div>
    </div>

    <!-- Processing Status Card -->
    <div class="col-lg-4 col-md-6 mb-3">
        <div class="summary-card card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="summary-icon mb-3">
                    <i class="fas fa-percentage fa-2x text-info"></i>
                </div>
                <h3 class="summary-value text-info"><?php echo $compliancePercentage; ?>%</h3>
                <p class="summary-label mb-2">Compliance Percentage</p>
                <small class="text-muted">Total issues resolved vs open</small>
                
                <!-- Progress Bar -->
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-info" 
                         style="width: <?php echo $compliancePercentage; ?>%"
                         role="progressbar" 
                         aria-valuenow="<?php echo $compliancePercentage; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.section-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9ecef;
}

.summary-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border-radius: 12px;
    overflow: hidden;
}

.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
}

.summary-card .card-body {
    padding: 1.5rem;
}

.summary-icon {
    opacity: 0.8;
}

.summary-value {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    line-height: 1;
}

.summary-label {
    font-size: 1rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.25rem;
}

.summary-card small {
    font-size: 0.875rem;
    line-height: 1.3;
}

@media (max-width: 768px) {
    .summary-value {
        font-size: 2rem;
    }
    
    .summary-card .card-body {
        padding: 1.25rem;
    }
    
    .summary-icon i {
        font-size: 1.5rem !important;
    }
}

@media (max-width: 576px) {
    .summary-value {
        font-size: 1.75rem;
    }
    
    .section-title {
        font-size: 1.25rem;
    }
}
</style>