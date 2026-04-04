<?php
/**
 * Dashboard Header Partial
 * 
 * Header section with title, project selector, and export buttons
 */

$assignedProjects = $dashboardData['assigned_projects'] ?? [];
$projectStats = $dashboardData['project_statistics'] ?? [];
$selectedProjectId = $_GET['project_id'] ?? null;
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="dashboard-header-section">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <!-- Title and Description -->
                <div class="dashboard-title-area">
                    <h1 class="dashboard-title" style="color: #2c3e50 !important;">
                        <i class="fas fa-tachometer-alt text-primary"></i>
                        Analytics Dashboard
                    </h1>
                    <p class="dashboard-subtitle mb-0" style="color: #495057 !important;">
                        Comprehensive accessibility analytics across 
                        <strong><?php echo count($assignedProjects); ?></strong> assigned digital assets
                    </p>
                    <div class="dashboard-meta mt-2">
                        <small style="color: #6c757d !important;">
                            <i class="fas fa-clock"></i>
                            Last updated: <?php echo date('M j, Y \a\t g:i A'); ?>
                        </small>
                    </div>
                </div>

                <!-- Controls -->
                <div class="dashboard-controls d-flex flex-wrap gap-2">
                    <!-- Project Filter -->
                    <div class="control-group">
                        <label for="projectFilter" class="form-label small mb-1" style="color: #495057 !important;">Filter by Digital Asset</label>
                        <select id="projectFilter" class="form-select form-select-sm" style="min-width: 250px;">
                            <option value="">All Digital Assets</option>
                            <?php foreach ($assignedProjects as $project): ?>
                                <option value="<?php echo $project['id']; ?>" 
                                        <?php echo ($selectedProjectId == $project['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Export Buttons -->
                    <div class="control-group">
                        <label class="form-label small mb-1" style="color: #495057 !important;">Export Reports</label>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-success btn-sm" data-dashboard-export="pdf">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" data-dashboard-export="excel">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                        </div>
                    </div>

                    <!-- Refresh Button -->
                    <div class="control-group">
                        <label class="form-label small mb-1" style="color: #495057 !important;">&nbsp;</label>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-dashboard-refresh="1">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-header-section {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #dee2e6;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

/* Force dark text colors for header section */
.dashboard-header-section,
.dashboard-header-section * {
    color: #2c3e50 !important;
}

.dashboard-title {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50 !important;
    margin-bottom: 8px;
}

.dashboard-subtitle {
    font-size: 1.1rem;
    line-height: 1.4;
    color: #495057 !important;
}

.dashboard-meta {
    font-size: 0.875rem;
}

.dashboard-meta .text-muted,
.dashboard-meta small {
    color: #6c757d !important;
}

.control-group {
    display: flex;
    flex-direction: column;
}

.control-group .form-label {
    font-weight: 600;
    margin-bottom: 4px;
    color: #495057 !important;
}

.control-group .text-muted {
    color: #6c757d !important;
}

/* Ensure icons maintain proper colors */
.dashboard-header-section .text-primary {
    color: #0755C6 !important;
}

.dashboard-header-section .fas {
    color: inherit;
}

@media (max-width: 768px) {
    .dashboard-header-section {
        padding: 16px;
    }
    
    .dashboard-title {
        font-size: 1.5rem;
    }
    
    .dashboard-controls {
        width: 100%;
        justify-content: stretch;
    }
    
    .control-group {
        flex: 1;
        min-width: 0;
    }
    
    .control-group select,
    .control-group .btn-group {
        width: 100%;
    }
}
</style>