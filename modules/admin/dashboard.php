<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$projectManager = new ProjectManager();
$baseDir = getBaseDir();
$devicesApiUrl = $baseDir . '/api/devices.php';

// Consolidated pending requests for admin dashboard
$pendingBuckets = [];
$pendingFeed = [];
$pendingTotalCount = 0;

try {
    $devicePendingCount = (int)$db->query("SELECT COUNT(*) FROM device_switch_requests WHERE status = 'Pending'")->fetchColumn();
    $devicePendingRows = $db->query("
        SELECT dsr.id, dsr.requested_at, d.device_name, d.device_type, u.full_name AS requester_name
        FROM device_switch_requests dsr
        JOIN devices d ON d.id = dsr.device_id
        JOIN users u ON u.id = dsr.requested_by
        WHERE dsr.status = 'Pending'
        ORDER BY dsr.requested_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $pendingBuckets[] = [
        'key' => 'device',
        'label' => 'Device Requests',
        'count' => $devicePendingCount,
        'link' => $baseDir . '/modules/admin/devices.php',
        'items' => $devicePendingRows
    ];
    foreach ($devicePendingRows as $row) {
        $pendingFeed[] = [
            'type' => 'Device',
            'title' => trim((string)$row['device_name']) . ' (' . trim((string)$row['device_type']) . ')',
            'user' => (string)($row['requester_name'] ?? 'Unknown'),
            'requested_at' => (string)($row['requested_at'] ?? ''),
            'link' => $baseDir . '/modules/admin/devices.php',
            'action_kind' => 'device',
            'request_id' => (int)($row['id'] ?? 0)
        ];
    }
    $pendingTotalCount += $devicePendingCount;
} catch (Exception $e) {
    error_log('dashboard pending device requests load failed: ' . $e->getMessage());
}

try {
    $hoursPendingCount = (int)$db->query("SELECT COUNT(*) FROM user_edit_requests WHERE status = 'pending'")->fetchColumn();
    $hoursPendingRows = $db->query("
        SELECT uer.id, uer.user_id, uer.req_date, uer.request_type, uer.created_at, u.full_name AS requester_name
        FROM user_edit_requests uer
        JOIN users u ON u.id = uer.user_id
        WHERE uer.status = 'pending'
        ORDER BY uer.created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $pendingBuckets[] = [
        'key' => 'hours',
        'label' => 'Hours Log Requests',
        'count' => $hoursPendingCount,
        'link' => $baseDir . '/modules/admin/edit_requests.php',
        'items' => $hoursPendingRows
    ];
    foreach ($hoursPendingRows as $row) {
        $requestType = strtolower(trim((string)($row['request_type'] ?? 'edit'))) === 'delete' ? 'Delete' : 'Edit';
        $pendingFeed[] = [
            'type' => 'Hours',
            'title' => $requestType . ' request for ' . (string)($row['req_date'] ?? '-'),
            'user' => (string)($row['requester_name'] ?? 'Unknown'),
            'requested_at' => (string)($row['created_at'] ?? ''),
            'link' => $baseDir . '/modules/admin/edit_requests.php',
            'action_kind' => 'hours',
            'request_id' => (int)($row['id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'req_date' => (string)($row['req_date'] ?? '')
        ];
    }
    $pendingTotalCount += $hoursPendingCount;
} catch (Exception $e) {
    error_log('dashboard pending hours requests load failed: ' . $e->getMessage());
}

try {
    $pendingEditsCount = (int)$db->query("SELECT COUNT(*) FROM user_pending_log_edits WHERE status = 'pending'")->fetchColumn();
    $pendingBuckets[] = [
        'key' => 'log_edits',
        'label' => 'Pending Log Edit Items',
        'count' => $pendingEditsCount,
        'link' => $baseDir . '/modules/admin/edit_requests.php',
        'items' => []
    ];
    $pendingTotalCount += $pendingEditsCount;
} catch (Exception $e) {
    error_log('dashboard pending log edits load failed: ' . $e->getMessage());
}

try {
    $pendingDeletesCount = (int)$db->query("SELECT COUNT(*) FROM user_pending_log_deletions WHERE status = 'pending'")->fetchColumn();
    $pendingBuckets[] = [
        'key' => 'log_deletes',
        'label' => 'Pending Log Delete Items',
        'count' => $pendingDeletesCount,
        'link' => $baseDir . '/modules/admin/edit_requests.php',
        'items' => []
    ];
    $pendingTotalCount += $pendingDeletesCount;
} catch (Exception $e) {
    error_log('dashboard pending log deletions load failed: ' . $e->getMessage());
}

usort($pendingFeed, static function (array $a, array $b): int {
    return strtotime((string)($b['requested_at'] ?? '')) <=> strtotime((string)($a['requested_at'] ?? ''));
});
$pendingFeed = array_slice($pendingFeed, 0, 8);
$myDevicesStmt = $db->prepare("
    SELECT d.device_name, d.device_type, d.model, d.version, da.assigned_at
    FROM device_assignments da
    JOIN devices d ON d.id = da.device_id
    WHERE da.user_id = ? AND da.status = 'Active'
    ORDER BY da.assigned_at DESC
");
$myDevicesStmt->execute([$userId]);
$myDevices = $myDevicesStmt->fetchAll(PDO::FETCH_ASSOC);

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

$workload = array_values($workload);
$workloadUserIds = array_values(array_filter(array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $workload)));
$allocatedHoursByUser = [];
if (!empty($workloadUserIds)) {
    $ph = implode(',', array_fill(0, count($workloadUserIds), '?'));
    $allocStmt = $db->prepare("
        SELECT ua.user_id, COALESCE(SUM(ua.hours_allocated), 0) AS allocated
        FROM user_assignments ua
        JOIN projects p ON ua.project_id = p.id
        WHERE ua.user_id IN ($ph)
          AND p.status NOT IN ('completed', 'cancelled')
        GROUP BY ua.user_id
    ");
    $allocStmt->execute($workloadUserIds);
    while ($allocRow = $allocStmt->fetch(PDO::FETCH_ASSOC)) {
        $allocatedHoursByUser[(int)$allocRow['user_id']] = (float)$allocRow['allocated'];
    }
}

foreach ($workload as &$resourceRow) {
    $rid = (int)($resourceRow['id'] ?? 0);
    $resourceRow['allocated_hours'] = (float)($allocatedHoursByUser[$rid] ?? 0);
}
unset($resourceRow);

$workloadCount = count($workload);
$avgProjects = $workloadCount > 0 ? round(array_sum(array_column($workload, 'active_projects')) / $workloadCount, 1) : 0;
$overloaded = count(array_filter($workload, fn($r) => (int)$r['active_projects'] > 5));
$busy = count(array_filter($workload, fn($r) => (int)$r['active_projects'] >= 3 && (int)$r['active_projects'] <= 5));
$available = count(array_filter($workload, fn($r) => (int)$r['active_projects'] < 3));
$inactive = count(array_filter($workload, fn($r) => (int)$r['recent_activity'] === 0));
$total = $workloadCount;

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

    <div class="card mb-3 border-warning">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-inbox"></i> Pending Requests (All Modules)</h6>
            <span class="badge bg-warning text-dark"><?php echo (int)$pendingTotalCount; ?> pending</span>
        </div>
        <div class="card-body">
            <?php if ((int)$pendingTotalCount === 0): ?>
                <span class="text-muted">No pending requests right now.</span>
            <?php else: ?>
                <div class="row g-2 mb-3">
                    <?php foreach ($pendingBuckets as $bucket): ?>
                        <?php if ((int)($bucket['count'] ?? 0) <= 0) continue; ?>
                        <div class="col-sm-6 col-xl-3">
                            <a href="<?php echo htmlspecialchars((string)$bucket['link']); ?>" class="text-decoration-none">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted"><?php echo htmlspecialchars((string)$bucket['label']); ?></div>
                                    <div class="h5 mb-0 text-dark"><?php echo (int)$bucket['count']; ?></div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($pendingFeed)): ?>
                    <h6 class="mb-2">Latest Pending Requests</h6>
                    <div class="list-group">
                        <?php foreach ($pendingFeed as $feed): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="badge bg-secondary me-2"><?php echo htmlspecialchars((string)$feed['type']); ?></span>
                                        <strong><?php echo htmlspecialchars((string)$feed['title']); ?></strong>
                                        <div class="small text-muted">Requested by <?php echo htmlspecialchars((string)$feed['user']); ?></div>
                                    </div>
                                    <small class="text-muted"><?php echo !empty($feed['requested_at']) ? date('M d, H:i', strtotime((string)$feed['requested_at'])) : '-'; ?></small>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php if (($feed['action_kind'] ?? '') === 'device' && (int)($feed['request_id'] ?? 0) > 0): ?>
                                        <button type="button" class="btn btn-sm btn-success" onclick="respondDeviceRequestFromDashboard(<?php echo (int)$feed['request_id']; ?>, 'approve')">Accept</button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="respondDeviceRequestFromDashboard(<?php echo (int)$feed['request_id']; ?>, 'reject')">Reject</button>
                                        <a href="<?php echo htmlspecialchars((string)$feed['link']); ?>" class="btn btn-sm btn-outline-secondary">Open</a>
                                    <?php elseif (($feed['action_kind'] ?? '') === 'hours' && (int)($feed['request_id'] ?? 0) > 0): ?>
                                        <form method="POST" action="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/edit_requests.php" class="d-inline">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$feed['request_id']; ?>">
                                            <input type="hidden" name="action" value="approved">
                                            <input type="hidden" name="user_id" value="<?php echo (int)($feed['user_id'] ?? 0); ?>">
                                            <input type="hidden" name="date" value="<?php echo htmlspecialchars((string)($feed['req_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($baseDir . '/modules/admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="btn btn-sm btn-success">Accept</button>
                                        </form>
                                        <form method="POST" action="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/edit_requests.php" class="d-inline">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$feed['request_id']; ?>">
                                            <input type="hidden" name="action" value="rejected">
                                            <input type="hidden" name="user_id" value="<?php echo (int)($feed['user_id'] ?? 0); ?>">
                                            <input type="hidden" name="date" value="<?php echo htmlspecialchars((string)($feed['req_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($baseDir . '/modules/admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                                        </form>
                                        <a href="<?php echo htmlspecialchars((string)$feed['link']); ?>" class="btn btn-sm btn-outline-secondary">Open</a>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars((string)$feed['link']); ?>" class="btn btn-sm btn-outline-secondary">Open</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-laptop"></i> My Assigned Devices</h6>
            <a href="<?php echo $baseDir; ?>/modules/devices.php" class="btn btn-sm btn-outline-primary">View Devices</a>
        </div>
        <div class="card-body py-2">
            <?php if (empty($myDevices)): ?>
                <span class="text-muted">No office device assigned.</span>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($myDevices as $dev): ?>
                        <span class="badge bg-light text-dark border">
                            <?php echo htmlspecialchars((string)$dev['device_name']); ?>
                            (<?php echo htmlspecialchars((string)$dev['device_type']); ?>)
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
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
        
        .resource-workload {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .resource-workload .card-header {
            flex: 0 0 auto;
        }

        .resource-workload .resource-workload-body,
        .resource-workload > .card-body {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        .resource-workload .resource-workload-scroll {
            flex: 1 1 auto;
            min-height: 220px;
            max-height: 46vh;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid #f1f3f5;
            border-radius: 8px;
        }

        .resource-workload .resource-workload-footer {
            flex: 0 0 auto;
            margin-top: 0.75rem;
            padding-top: 0.5rem;
            border-top: 1px solid #f1f3f5;
        }

        @media (min-width: 992px) {
            .resource-workload {
                height: min(70vh, 740px);
            }
            .resource-workload .resource-workload-scroll {
                max-height: none;
            }
        }
        
        .progress {
            border-radius: 10px;
        }

        .resource-workload-stats .stat-card {
            border: 1px solid #eef1f4;
            border-radius: 10px;
            background: #fafbfc;
            padding: 10px;
        }

        .resource-workload-stats .stat-value {
            font-size: 1.15rem;
            font-weight: 700;
            line-height: 1;
        }

        .resource-list {
            display: grid;
            gap: 10px;
            padding: 10px;
        }

        .resource-item {
            border: 1px solid #eceff3;
            border-radius: 10px;
            padding: 10px;
            background: #fff;
        }

        .resource-item .resource-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .resource-item .resource-name {
            font-size: 0.92rem;
            font-weight: 600;
            line-height: 1.2;
        }

        .resource-item .resource-meta {
            margin-top: 8px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
            font-size: 0.75rem;
            color: #6c757d;
        }

        .resource-item .resource-load {
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .resource-item .mini-progress {
            height: 8px;
            background: #f1f3f5;
            border-radius: 999px;
            overflow: hidden;
            flex: 1 1 auto;
        }

        .resource-item .mini-progress-bar {
            height: 100%;
            border-radius: 999px;
        }

        .resource-item .resource-flag {
            font-size: 0.72rem;
            font-weight: 600;
        }

        @media (max-width: 575px) {
            .resource-item .resource-meta {
                grid-template-columns: 1fr;
            }
        }
    </style>

    
    <div class="row">
        <!-- Recent Projects -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>Recent Projects</h5>
                </div>
                <div class="card-body resource-workload-body">
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
                
                <div class="card-body resource-workload-body">
                    <div class="resource-workload-stats row g-2 mb-3">
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <div class="text-muted small">Total</div>
                                <div class="stat-value"><?php echo $workloadCount; ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <div class="text-muted small">Avg Projects</div>
                                <div class="stat-value"><?php echo $avgProjects; ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <div class="text-muted small">Overloaded</div>
                                <div class="stat-value text-danger"><?php echo $overloaded; ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <div class="text-muted small">Inactive</div>
                                <div class="stat-value text-warning"><?php echo $inactive; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="resource-workload-scroll">
                        <div class="resource-list">
                            <?php foreach ($workload as $resource): ?>
                                <?php
                                $activeProjects = (int)$resource['active_projects'];
                                $assignedPages = (int)$resource['assigned_pages'];
                                $loadClass = $activeProjects > 5 ? 'danger' : ($activeProjects >= 3 ? 'warning' : 'success');
                                $roleClass = match($resource['role']) {
                                    'project_lead' => 'primary',
                                    'qa' => 'success',
                                    'at_tester' => 'info',
                                    'ft_tester' => 'warning',
                                    default => 'secondary'
                                };
                                $loadPercent = min(100, ($activeProjects / 6) * 100);
                                ?>
                                <div class="resource-item">
                                    <div class="resource-top">
                                        <div>
                                            <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo (int)$resource['id']; ?>" class="resource-name text-decoration-none">
                                                <?php echo htmlspecialchars($resource['full_name']); ?>
                                            </a>
                                            <?php if ((int)$resource['critical_projects'] > 0): ?>
                                                <i class="fas fa-exclamation-triangle text-danger ms-1" title="Has critical projects"></i>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge bg-<?php echo $roleClass; ?> badge-sm">
                                            <?php echo ucfirst(str_replace('_', ' ', (string)$resource['role'])); ?>
                                        </span>
                                    </div>
                                    <div class="resource-load">
                                        <span class="badge bg-<?php echo $loadClass; ?> badge-sm"><?php echo $activeProjects; ?> projects</span>
                                        <div class="mini-progress">
                                            <div class="mini-progress-bar bg-<?php echo $loadClass; ?>" style="width: <?php echo $loadPercent; ?>%;"></div>
                                        </div>
                                        <span class="resource-flag text-muted"><?php echo $assignedPages; ?> pages</span>
                                    </div>
                                    <div class="resource-meta">
                                        <span><strong><?php echo number_format((float)$resource['allocated_hours'], 1); ?>h</strong> allocated</span>
                                        <span><strong><?php echo number_format((float)$resource['hours_last_30_days'], 1); ?>h</strong> in 30d</span>
                                        <span><strong><?php echo number_format((float)$resource['total_hours'], 1); ?>h</strong> total</span>
                                        <span>
                                            <?php if ((int)$resource['recent_activity'] === 0): ?>
                                                <span class="text-warning"><i class="fas fa-clock"></i> Inactive</span>
                                            <?php else: ?>
                                                <span class="text-success"><i class="fas fa-check-circle"></i> Active</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (empty($workload)): ?>
                                <div class="text-center text-muted py-3">
                                    No resources found matching the selected filters.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Workload Distribution Chart -->
                    <div class="resource-workload-footer">
                        <h6 class="text-muted mb-2">Workload Distribution</h6>
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
<script>
function respondDeviceRequestFromDashboard(requestId, action) {
    const actionLabel = action === 'approve' ? 'accept' : 'reject';
    confirmModal(`Are you sure you want to ${actionLabel} this device request?`, function() {
        $.post('<?php echo htmlspecialchars($devicesApiUrl, ENT_QUOTES, 'UTF-8'); ?>', {
            action: 'respond_to_request',
            request_id: requestId,
            response_action: action,
            response_notes: 'Processed from admin dashboard'
        }, function(response) {
            if (response && response.success) {
                location.reload();
            } else {
                showToast((response && response.message) ? response.message : 'Failed to process request', 'danger');
            }
        }).fail(function(xhr) {
            let msg = 'Failed to process request';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
            showToast(msg, 'danger');
        });
    }, {
        title: action === 'approve' ? 'Confirm Accept' : 'Confirm Reject',
        confirmText: action === 'approve' ? 'Accept' : 'Reject',
        confirmClass: action === 'approve' ? 'btn-success' : 'btn-danger'
    });
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
