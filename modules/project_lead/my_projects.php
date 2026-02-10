<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['project_lead', 'admin', 'super_admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Get ALL projects for this project lead (including completed)
$assignedProjectsQuery = "
    SELECT DISTINCT p.id, p.title, p.po_number, p.project_code, p.status, p.project_type, p.priority,
           c.name as client_name,
           (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) as current_phase,
           COUNT(DISTINCT pp.id) as total_pages,
           SUM(CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN 1 ELSE 0 END) as completed_pages
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN project_pages pp ON p.id = pp.project_id
    WHERE p.project_lead_id = ? OR p.created_by = ?
    GROUP BY p.id, p.title, p.po_number, p.project_code, p.status, p.project_type, p.priority, c.name
    ORDER BY p.created_at DESC
";

$assignedProjects = $db->prepare($assignedProjectsQuery);
$assignedProjects->execute([$userId, $userId]);
$projects = $assignedProjects->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-project-diagram text-primary"></i> My Projects</h2>
                <a href="<?php echo $baseDir; ?>/modules/project_lead/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- All Projects Table with Filters -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list"></i> All My Projects</h5>
            <div class="d-flex gap-2">
                <select id="statusFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All Status</option>
                    <option value="planning">Planning</option>
                    <option value="in_progress">In Progress</option>
                    <option value="on_hold">On Hold</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select id="typeFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All Types</option>
                    <option value="website">Website</option>
                    <option value="mobile_app">Mobile App</option>
                    <option value="web_app">Web App</option>
                    <option value="other">Other</option>
                </select>
                <select id="priorityFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All Priorities</option>
                    <option value="critical">Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
                <input type="text" id="searchProject" class="form-control form-control-sm" placeholder="Search projects..." style="width: 200px;">
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($projects)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No projects assigned yet</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="projectsTable">
                        <thead>
                            <tr>
                                <th>Project Title</th>
                                <th>Project Code</th>
                                <th>Client</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Phase</th>
                                <th>Progress</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                            <tr data-status="<?php echo htmlspecialchars($project['status']); ?>" 
                                data-type="<?php echo htmlspecialchars($project['project_type']); ?>"
                                data-priority="<?php echo htmlspecialchars($project['priority']); ?>"
                                data-title="<?php echo htmlspecialchars(strtolower($project['title'])); ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($project['project_code'] ?: $project['po_number']); ?></td>
                                <td><?php echo htmlspecialchars($project['client_name'] ?? '—'); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo ucfirst(str_replace('_', ' ', $project['project_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'planning' => 'secondary',
                                        'in_progress' => 'primary',
                                        'on_hold' => 'warning',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                    $statusColor = $statusColors[$project['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $statusColor; ?>">
                                        <?php echo formatProjectStatusLabel($project['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $priorityColors = [
                                        'critical' => 'danger',
                                        'high' => 'warning',
                                        'medium' => 'info',
                                        'low' => 'secondary'
                                    ];
                                    $priorityColor = $priorityColors[$project['priority']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $priorityColor; ?>">
                                        <?php echo ucfirst($project['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($project['current_phase'])): ?>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($project['current_phase']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $progress = $project['total_pages'] > 0 ? 
                                        round(($project['completed_pages'] / $project['total_pages']) * 100) : 0;
                                    ?>
                                    <div class="progress" style="height: 20px; min-width: 100px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%">
                                            <?php echo $progress; ?>%
                                        </div>
                                    </div>
                                    <small class="text-muted"><?php echo $project['completed_pages']; ?>/<?php echo $project['total_pages']; ?></small>
                                </td>
                                <td>
                                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                                       class="btn btn-sm btn-info me-1">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?php echo $baseDir; ?>/modules/projects/manage_assignments.php?project_id=<?php echo $project['id']; ?>" 
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-tasks"></i> Assign
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Project table filtering
$(document).ready(function() {
    function filterProjects() {
        const statusFilter = $('#statusFilter').val().toLowerCase();
        const typeFilter = $('#typeFilter').val().toLowerCase();
        const priorityFilter = $('#priorityFilter').val().toLowerCase();
        const searchText = $('#searchProject').val().toLowerCase();
        
        $('#projectsTable tbody tr').each(function() {
            const row = $(this);
            const status = row.data('status');
            const type = row.data('type');
            const priority = row.data('priority');
            const title = row.data('title');
            
            let showRow = true;
            
            if (statusFilter && status !== statusFilter) showRow = false;
            if (typeFilter && type !== typeFilter) showRow = false;
            if (priorityFilter && priority !== priorityFilter) showRow = false;
            if (searchText && title.indexOf(searchText) === -1) showRow = false;
            
            row.toggle(showRow);
        });
    }
    
    $('#statusFilter, #typeFilter, #priorityFilter').on('change', filterProjects);
    $('#searchProject').on('keyup', filterProjects);
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
