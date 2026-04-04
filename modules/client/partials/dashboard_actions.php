<?php
/**
 * Dashboard Quick Actions Partial
 * 
 * Quick action buttons and navigation links
 */

$assignedProjects = $dashboardData['assigned_projects'] ?? [];
$projectIdsList = implode(',', array_column($assignedProjects, 'id'));
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="section-title">
            <i class="fas fa-bolt text-primary"></i>
            Quick Actions
        </h2>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="quick-actions-grid">

            <!-- Export PDF Report -->
            <div class="action-card">
                <div class="action-icon text-success">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <div class="action-content">
                    <h4 class="action-title">Export PDF Report</h4>
                    <p class="action-description">Download comprehensive analytics as a PDF document</p>
                    <button type="button" data-dashboard-export="pdf" class="btn btn-success action-button">
                        <i class="fas fa-download"></i> Download PDF
                    </button>
                </div>
            </div>

            <!-- Export Excel Data -->
            <div class="action-card">
                <div class="action-icon text-info">
                    <i class="fas fa-file-excel"></i>
                </div>
                <div class="action-content">
                    <h4 class="action-title">Export Excel Data</h4>
                    <p class="action-description">Download raw analytics data in Excel format for analysis</p>
                    <button type="button" data-dashboard-export="excel" class="btn btn-info action-button">
                        <i class="fas fa-download"></i> Download Excel
                    </button>
                </div>
            </div>

            <!-- View Digital Assets -->
            <div class="action-card">
                <div class="action-icon text-secondary">
                    <i class="fas fa-folder-open"></i>
                </div>
                <div class="action-content">
                    <h4 class="action-title">View Digital Assets</h4>
                    <p class="action-description">Browse all assigned digital assets from one place</p>
                    <a href="<?php echo $baseDir; ?>/modules/client/projects.php" 
                       class="btn btn-secondary action-button">
                        <i class="fas fa-arrow-right"></i> Browse Digital Assets
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    margin-bottom: 2rem;
}

.action-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.action-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    border-color: #2563eb;
}

.action-icon {
    font-size: 3rem;
    color: #2563eb;
    margin-bottom: 16px;
    opacity: 0.8;
}

.action-content {
    text-align: center;
}

.action-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
}

.action-description {
    color: #6c757d;
    font-size: 0.9rem;
    line-height: 1.4;
    margin-bottom: 16px;
}

.action-button {
    font-weight: 500;
    padding: 8px 20px;
    border-radius: 8px;
    transition: all 0.2s ease;
    border: none;
    font-size: 0.9rem;
}

.action-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}


/* Responsive Design */
@media (max-width: 768px) {
    .quick-actions-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .action-card {
        padding: 20px;
    }
    
    .action-icon {
        font-size: 2.5rem;
        margin-bottom: 12px;
    }
    
    .action-title {
        font-size: 1.1rem;
    }
    
}

@media (max-width: 576px) {
    .action-card {
        padding: 16px;
    }
    
    .action-icon {
        font-size: 2rem;
    }
    
    .action-title {
        font-size: 1rem;
    }
    
    .action-description {
        font-size: 0.85rem;
    }
}
</style>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/client-dashboard-actions.js"></script>