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
            <!-- Dashboard Header -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <h1 class="h3 mb-2">Welcome, <?php echo htmlspecialchars($clientUser['full_name'] ?? ($clientUser['username'] ?? 'Client'), ENT_QUOTES, 'UTF-8'); ?>!</h1>
                    <p class="text-muted mb-0">
                        Here's your accessibility analytics overview.
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="btn-group" role="group" aria-label="Dashboard actions">
                        <button type="button" class="btn btn-outline-primary" data-action="refresh" title="Refresh Data">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" data-action="export" data-format="pdf">
                                    <i class="fas fa-file-pdf text-danger me-2"></i>Export as PDF
                                </a></li>
                                <li><a class="dropdown-item" href="#" data-action="export" data-format="xlsx">
                                    <i class="fas fa-file-excel text-success me-2"></i>Export as Excel
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Project Summary -->
            <?php if (!empty($assignedProjects)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title mb-3">
                                <i class="fas fa-project-diagram text-primary me-2"></i>
                                Assigned Projects (<?php echo count($assignedProjects); ?>)
                            </h5>
                            <div class="row g-3">
                                <?php foreach ($assignedProjects as $project): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card h-100 border">
                                        <div class="card-body">
                                            <h6 class="card-title text-truncate" title="<?php echo htmlspecialchars($project['title'] ?? 'Untitled Project', ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($project['title'] ?? 'Untitled Project', ENT_QUOTES, 'UTF-8'); ?>
                                            </h6>
                                            <p class="card-text small text-muted mb-2">
                                                <?php echo htmlspecialchars($project['description'] ?? 'No description', ENT_QUOTES, 'UTF-8'); ?>
                                            </p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <?php echo $project['client_ready_count'] ?? 0; ?> accessibility issues
                                                </small>
                                                <a href="<?php echo $baseDir; ?>/client/project/<?php echo $project['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    View Details
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-project-diagram fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Projects Assigned</h5>
                            <p class="text-muted">You don't have any projects assigned yet. Please contact your administrator.</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Analytics Summary Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-users fa-2x text-primary mb-2"></i>
                            <h5 class="card-title">Users Affected</h5>
                            <h3 class="text-primary" id="total-users-affected">-</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                            <h5 class="card-title">Total Issues</h5>
                            <h3 class="text-warning" id="total-issues">-</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-ban fa-2x text-danger mb-2"></i>
                            <h5 class="card-title">Blocker Issues</h5>
                            <h3 class="text-danger" id="blocker-issues">-</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                            <h5 class="card-title">Compliance</h5>
                            <h3 class="text-success" id="compliance-percentage">-</h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Coming Soon Message -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-chart-line fa-3x text-info mb-3"></i>
                            <h4 class="text-info">Advanced Analytics Coming Soon</h4>
                            <p class="text-muted">
                                We're working on bringing you detailed analytics charts and reports. 
                                In the meantime, you can view your assigned projects above.
                            </p>
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