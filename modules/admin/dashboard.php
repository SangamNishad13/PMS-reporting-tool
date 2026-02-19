<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();
$projectManager = new ProjectManager();

// Get project statuses from status master (same source used in project create/edit flows)
$projectStatusOptions = getStatusOptions('project');
if (empty($projectStatusOptions)) {
    $projectStatusOptions = [
        ['status_key' => 'planning', 'status_label' => 'Planning'],
        ['status_key' => 'in_progress', 'status_label' => 'In Progress'],
        ['status_key' => 'on_hold', 'status_label' => 'On Hold'],
        ['status_key' => 'completed', 'status_label' => 'Completed'],
        ['status_key' => 'cancelled', 'status_label' => 'Cancelled'],
    ];
}

// Count projects by status
$statusRows = $db->query("
    SELECT COALESCE(NULLIF(TRIM(status), ''), 'not_started') AS status_key, COUNT(*) AS total
    FROM projects
    GROUP BY COALESCE(NULLIF(TRIM(status), ''), 'not_started')
")->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = [];
foreach ($statusRows as $row) {
    $statusCounts[(string)$row['status_key']] = (int)$row['total'];
}

// Keep stats shape for existing references
$stats = [
    'total_projects' => (int)$db->query("SELECT COUNT(*) FROM projects")->fetchColumn()
];

// Get recent projects (include current phase if available)
$recentProjects = $db->query(
    "SELECT p.*, c.name as client_name, u.full_name as lead_name, p.project_lead_id,
        (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) as current_phase
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN users u ON p.project_lead_id = u.id
    ORDER BY p.created_at DESC
    LIMIT 5"
)->fetchAll();

// Handle filters for resource workload
$roleFilter = $_GET['role_filter'] ?? '';
$workloadFilter = $_GET['workload_filter'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'name';

// Build WHERE clause for filters
$whereConditions = ["u.is_active = 1"];
$params = [];

if ($roleFilter && $roleFilter !== 'all') {
    $whereConditions[] = "u.role = ?";
    $params[] = $roleFilter;
} else {
    $whereConditions[] = "u.role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
}

$whereClause = implode(' AND ', $whereConditions);

// Build ORDER BY clause
$orderBy = match($sortBy) {
    'role' => 'u.role, u.full_name',
    'projects' => 'active_projects DESC, u.full_name',
    'hours' => 'total_hours DESC, u.full_name',
    'pages' => 'assigned_pages DESC, u.full_name',
    default => 'u.full_name'
};

// Get resource workload with enhanced data
$sql = "
    SELECT
        u.id,
        u.full_name,
        u.role,
        u.email,
        (SELECT COUNT(DISTINCT p2.id) 
         FROM project_pages pp2 
         JOIN projects p2 ON pp2.project_id = p2.id 
         WHERE (pp2.at_tester_id = u.id OR pp2.ft_tester_id = u.id OR pp2.qa_id = u.id
                OR (pp2.at_tester_ids IS NOT NULL AND JSON_CONTAINS(pp2.at_tester_ids, JSON_ARRAY(u.id)))
                OR (pp2.ft_tester_ids IS NOT NULL AND JSON_CONTAINS(pp2.ft_tester_ids, JSON_ARRAY(u.id))))
         AND p2.status NOT IN ('completed', 'cancelled')) as active_projects,
        (SELECT COUNT(DISTINCT pp3.id) 
         FROM project_pages pp3 
         WHERE (pp3.at_tester_id = u.id OR pp3.ft_tester_id = u.id OR pp3.qa_id = u.id
                OR (pp3.at_tester_ids IS NOT NULL AND JSON_CONTAINS(pp3.at_tester_ids, JSON_ARRAY(u.id)))
                OR (pp3.ft_tester_ids IS NOT NULL AND JSON_CONTAINS(pp3.ft_tester_ids, JSON_ARRAY(u.id))))) as assigned_pages,
        (SELECT COUNT(DISTINCT p3.id) 
         FROM project_pages pp4 
         JOIN projects p3 ON pp4.project_id = p3.id 
         WHERE (pp4.at_tester_id = u.id OR pp4.ft_tester_id = u.id OR pp4.qa_id = u.id
                OR (pp4.at_tester_ids IS NOT NULL AND JSON_CONTAINS(pp4.at_tester_ids, JSON_ARRAY(u.id)))
                OR (pp4.ft_tester_ids IS NOT NULL AND JSON_CONTAINS(pp4.ft_tester_ids, JSON_ARRAY(u.id))))
         AND p3.status = 'in_progress') as in_progress_projects,
        (SELECT COUNT(DISTINCT p4.id) 
         FROM project_pages pp5 
         JOIN projects p4 ON pp5.project_id = p4.id 
         WHERE (pp5.at_tester_id = u.id OR pp5.ft_tester_id = u.id OR pp5.qa_id = u.id
                OR (pp5.at_tester_ids IS NOT NULL AND JSON_CONTAINS(pp5.at_tester_ids, JSON_ARRAY(u.id)))
                OR (pp5.ft_tester_ids IS NOT NULL AND JSON_CONTAINS(pp5.ft_tester_ids, JSON_ARRAY(u.id))))
         AND p4.priority = 'critical') as critical_projects,
        (SELECT COALESCE(SUM(hours_spent), 0) FROM testing_results tr WHERE tr.tester_id = u.id AND DATE(tr.tested_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as hours_last_30_days,
        (SELECT COALESCE(SUM(hours_spent), 0) FROM testing_results tr WHERE tr.tester_id = u.id) as total_hours,
        (SELECT COUNT(*) FROM project_time_logs ptl WHERE ptl.user_id = u.id AND DATE(ptl.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as recent_activity
    FROM users u
    WHERE $whereClause
    ORDER BY $orderBy
";

if (!empty($params)) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $workload = $stmt->fetchAll();
} else {
    $workload = $db->query($sql)->fetchAll();
}

// Apply workload filter after query
if ($workloadFilter && $workloadFilter !== 'all') {
    $workload = array_filter($workload, function($resource) use ($workloadFilter) {
        return match($workloadFilter) {
            'overloaded' => $resource['active_projects'] > 5,
            'busy' => $resource['active_projects'] >= 3 && $resource['active_projects'] <= 5,
            'available' => $resource['active_projects'] < 3,
            'inactive' => $resource['recent_activity'] == 0,
            default => true
        };
    });
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-2">
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="<?php echo $baseDir; ?>/modules/admin/bulk_hours_management.php" class="btn btn-outline-primary btn-sm">Bulk Hours Management</a>
        <a href="<?php echo $baseDir; ?>/modules/admin/resource_workload.php" class="btn btn-outline-secondary btn-sm">Resource Workload</a>
        <a href="<?php echo $baseDir; ?>/modules/admin/calendar.php" class="btn btn-outline-secondary btn-sm">Users Calendar</a>
    </div>
    
    <!-- Statistics Cards -->
    <!-- Statistics Cards -->
    <div class="row mb-4 g-3">
        <div class="col-md-6 col-xl-3">
            <a href="<?php echo $baseDir; ?>/modules/reports/dashboard.php" class="text-decoration-none">
                <div class="widget widget-primary clickable-widget h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['total_projects']; ?></h3>
                            <p>Total Projects</p>
                        </div>
                        <span class="widget-pill">Overview</span>
                    </div>
                </div>
            </a>
        </div>
        <?php foreach ($projectStatusOptions as $opt): ?>
            <?php
                $statusKey = (string)($opt['status_key'] ?? '');
                if ($statusKey === '') continue;
                $count = (int)($statusCounts[$statusKey] ?? 0);
                $badgeClass = projectStatusBadgeClass($statusKey);
                $widgetClass = $badgeClass === 'warning' ? 'widget-warning'
                    : ($badgeClass === 'success' ? 'widget-success'
                    : ($badgeClass === 'info' ? 'widget-info'
                    : ($badgeClass === 'danger' ? 'widget-danger' : 'widget-secondary')));
                $label = (string)($opt['status_label'] ?? formatProjectStatusLabel($statusKey));
            ?>
            <div class="col-md-6 col-xl-3">
                <a href="<?php echo $baseDir; ?>/modules/reports/dashboard.php?status=<?php echo urlencode($statusKey); ?>" class="text-decoration-none">
                    <div class="widget <?php echo $widgetClass; ?> clickable-widget h-100">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h3><?php echo $count; ?></h3>
                                <p><?php echo htmlspecialchars($label); ?></p>
                            </div>
                            <span class="widget-pill">Status</span>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>



    <!-- Admin dashboard content starts here (no navigation links) -->
    
    <style>
        .clickable-widget {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .clickable-widget:hover, .hover-shadow:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
        }

        .widget-pill {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.5);
            background: rgba(255,255,255,0.15);
            color: #fff;
            white-space: nowrap;
        }
        
        .badge-sm {
            font-size: 0.7em;
        }
        
        .resource-workload .table th {
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .resource-workload .table td {
            font-size: 0.85em;
            vertical-align: middle;
        }
        
        .progress {
            border-radius: 10px;
        }
        
        .progress-bar {
            font-size: 0.75em;
            font-weight: bold;
        }
        
        .table-sticky-header {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .workload-summary {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 8px;
            padding: 10px;
        }
    </style>

    
    <div class="row">
        <!-- Recent Projects -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>Recent Projects</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Project Code</th>
                                    <th>Title</th>
                                    <th>Client</th>
                                    <th>Lead</th>
                                    <th>Status</th>
                                    <th>Phase</th>
                                    <th>Priority</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentProjects as $project): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars(!empty($project['project_code']) ? $project['project_code'] : ($project['po_number'] ?? '')); ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>">
                                            <?php echo htmlspecialchars($project['title']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($project['client_name'] ?? '—'); ?></td>
                                    <td>
                                        <?php if (!empty($project['project_lead_id'])): ?>
                                            <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $project['project_lead_id']; ?>">
                                                <?php echo htmlspecialchars($project['lead_name'] ?? 'Not assigned'); ?>
                                            </a>
                                        <?php else: ?>
                                            Not assigned
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $pClass = projectStatusBadgeClass($project['status']); $pLabel = formatProjectStatusLabel($project['status']); ?>
                                        <span class="badge bg-<?php echo $pClass; ?>"><?php echo $pLabel; ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($project['current_phase'])): ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($project['current_phase']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $project['priority'] === 'critical' ? 'danger' : 
                                                 ($project['priority'] === 'high' ? 'warning' : 'secondary');
                                        ?>">
                                            <?php echo ucfirst($project['priority']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Enhanced Resource Workload -->
        <div class="col-md-4">
            <div class="card resource-workload">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>Resource Workload</h5>
                    <div>
                        <a href="<?php echo $baseDir; ?>/modules/admin/resource_workload.php?v=<?php echo time(); ?>" class="btn btn-sm btn-outline-info me-2" title="Detailed View">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#workloadFilters">
                            <i class="fas fa-filter"></i> Filters
                        </button>
                    </div>
                </div>
                
                <!-- Filters Section -->
                <div class="collapse" id="workloadFilters">
                    <div class="card-body border-bottom">
                        <form method="GET" class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Role</label>
                                <select name="role_filter" class="form-select form-select-sm">
                                    <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                                    <option value="project_lead" <?php echo $roleFilter === 'project_lead' ? 'selected' : ''; ?>>Project Lead</option>
                                    <option value="qa" <?php echo $roleFilter === 'qa' ? 'selected' : ''; ?>>QA</option>
                                    <option value="at_tester" <?php echo $roleFilter === 'at_tester' ? 'selected' : ''; ?>>AT Tester</option>
                                    <option value="ft_tester" <?php echo $roleFilter === 'ft_tester' ? 'selected' : ''; ?>>FT Tester</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Workload</label>
                                <select name="workload_filter" class="form-select form-select-sm">
                                    <option value="all" <?php echo $workloadFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="overloaded" <?php echo $workloadFilter === 'overloaded' ? 'selected' : ''; ?>>Overloaded (5+ projects)</option>
                                    <option value="busy" <?php echo $workloadFilter === 'busy' ? 'selected' : ''; ?>>Busy (3-5 projects)</option>
                                    <option value="available" <?php echo $workloadFilter === 'available' ? 'selected' : ''; ?>>Available (&lt;3 projects)</option>
                                    <option value="inactive" <?php echo $workloadFilter === 'inactive' ? 'selected' : ''; ?>>Inactive (No recent activity)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Sort By</label>
                                <select name="sort_by" class="form-select form-select-sm">
                                    <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name</option>
                                    <option value="role" <?php echo $sortBy === 'role' ? 'selected' : ''; ?>>Role</option>
                                    <option value="projects" <?php echo $sortBy === 'projects' ? 'selected' : ''; ?>>Active Projects</option>
                                    <option value="hours" <?php echo $sortBy === 'hours' ? 'selected' : ''; ?>>Total Hours</option>
                                    <option value="pages" <?php echo $sortBy === 'pages' ? 'selected' : ''; ?>>Assigned Pages</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary btn-sm w-100">Apply Filters</button>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary btn-sm w-100 mt-1">Clear Filters</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Summary Stats -->
                    <div class="row mb-3">
                        <div class="col-6">
                            <div class="text-center">
                                <h6 class="text-muted mb-1">Total Resources</h6>
                                <h4 class="mb-0"><?php echo count($workload); ?></h4>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <h6 class="text-muted mb-1">Avg Projects</h6>
                                <h4 class="mb-0">
                                    <?php 
                                    $avgProjects = count($workload) > 0 ? round(array_sum(array_column($workload, 'active_projects')) / count($workload), 1) : 0;
                                    echo $avgProjects;
                                    ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover">
                            <thead class="table-sticky-header bg-light">
                                <tr>
                                    <th>Resource</th>
                                    <th>Role</th>
                                    <th>Load</th>
                                    <th>Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workload as $resource): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $resource['id']; ?>" class="text-decoration-none">
                                                <strong><?php echo htmlspecialchars($resource['full_name']); ?></strong>
                                            </a>
                                            <?php if ($resource['critical_projects'] > 0): ?>
                                                <i class="fas fa-exclamation-triangle text-danger ms-1" title="Has critical projects"></i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($resource['role']) {
                                                'project_lead' => 'primary',
                                                'qa' => 'success',
                                                'at_tester' => 'info',
                                                'ft_tester' => 'warning',
                                                default => 'secondary'
                                            };
                                        ?> badge-sm">
                                            <?php echo ucfirst(str_replace('_', ' ', $resource['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <span class="badge bg-<?php 
                                                echo $resource['active_projects'] > 5 ? 'danger' : 
                                                     ($resource['active_projects'] >= 3 ? 'warning' : 'success'); 
                                            ?> badge-sm mb-1">
                                                <?php echo $resource['active_projects']; ?> projects
                                            </span>
                                            <small class="text-muted"><?php echo $resource['assigned_pages']; ?> pages</small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <small class="text-primary">
                                                <?php 
                                                // Get allocated hours for dashboard
                                                $allocQuery = $db->prepare("SELECT COALESCE(SUM(hours_allocated), 0) as allocated FROM user_assignments ua JOIN projects p ON ua.project_id = p.id WHERE ua.user_id = ? AND p.status NOT IN ('completed', 'cancelled')");
                                                $allocQuery->execute([$resource['id']]);
                                                $allocated = $allocQuery->fetch()['allocated'];
                                                echo number_format($allocated, 1);
                                                ?>h allocated
                                            </small>
                                            <small class="text-muted">
                                                <?php echo $resource['hours_last_30_days']; ?>h (30d)
                                            </small>
                                            <small class="text-muted">
                                                <?php echo $resource['total_hours']; ?>h total
                                            </small>
                                            <?php if ($resource['recent_activity'] == 0): ?>
                                                <small class="text-warning">
                                                    <i class="fas fa-clock"></i> Inactive
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($workload)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">
                                        No resources found matching the selected filters.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Workload Distribution Chart -->
                    <div class="mt-3">
                        <h6 class="text-muted mb-2">Workload Distribution</h6>
                        <?php
                        $overloaded = count(array_filter($workload, fn($r) => $r['active_projects'] > 5));
                        $busy = count(array_filter($workload, fn($r) => $r['active_projects'] >= 3 && $r['active_projects'] <= 5));
                        $available = count(array_filter($workload, fn($r) => $r['active_projects'] < 3));
                        $total = count($workload);
                        ?>
                        <div class="progress mb-2" style="height: 20px;">
                            <?php if ($total > 0): ?>
                                <div class="progress-bar bg-danger" style="width: <?php echo ($overloaded/$total)*100; ?>%">
                                    <?php echo $overloaded; ?>
                                </div>
                                <div class="progress-bar bg-warning" style="width: <?php echo ($busy/$total)*100; ?>%">
                                    <?php echo $busy; ?>
                                </div>
                                <div class="progress-bar bg-success" style="width: <?php echo ($available/$total)*100; ?>%">
                                    <?php echo $available; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-between">
                            <small class="text-danger">Overloaded: <?php echo $overloaded; ?></small>
                            <small class="text-warning">Busy: <?php echo $busy; ?></small>
                            <small class="text-success">Available: <?php echo $available; ?></small>
                        </div>
                    </div>
                </div>
            </div>
            

        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
