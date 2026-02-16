<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'super_admin', 'project_lead']);

$db = Database::getInstance();
$baseDir = getBaseDir();

// Add cache-busting headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// --- 1. Handle Filters ---
$roleFilter = $_GET['role_filter'] ?? 'all';
$userFilter = $_GET['user_filter'] ?? 'all';
$projectFilter = $_GET['project_filter'] ?? 'all';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// --- 2. Fetch Helper Data (Users, Projects) ---

// Fetch Users for Dropdown
$usersQuery = "SELECT id, full_name, role FROM users WHERE is_active = 1";
$paramsUsers = [];
if ($roleFilter !== 'all') {
    $usersQuery .= " AND role = ?";
    $paramsUsers[] = $roleFilter;
} else {
    // Show relevants roles
     $usersQuery .= " AND role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
}
$usersQuery .= " ORDER BY full_name";
$stmtUsers = $db->prepare($usersQuery);
$stmtUsers->execute($paramsUsers);
$usersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Fetch Projects
$projectsList = $db->query("SELECT id, title, po_number FROM projects WHERE status NOT IN ('completed', 'cancelled') ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);


// --- 3. Build Main Query for Logs ---
// We need to fetch from project_time_logs and join with projects and users
$sql = "
    SELECT 
        ptl.*, 
        u.full_name as user_name, 
        u.role as user_role,
        p.title as project_title,
        p.po_number,
        p.status as project_status
    FROM project_time_logs ptl
    JOIN users u ON ptl.user_id = u.id
    LEFT JOIN projects p ON ptl.project_id = p.id
    WHERE ptl.log_date BETWEEN ? AND ?
";

$params = [$startDate, $endDate];

// Apply User Filter
if ($userFilter !== 'all') {
    $sql .= " AND ptl.user_id = ?";
    $params[] = $userFilter;
} else if ($roleFilter !== 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $roleFilter;
} else {
    // If no specific user/role selected, limit to relevant roles to avoid clutter (e.g. dont show admin logs unless requested)
    $sql .= " AND u.role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
}

// Apply Project Filter
if ($projectFilter !== 'all') {
    $sql .= " AND ptl.project_id = ?";
    $params[] = $projectFilter;
}

$sql .= " ORDER BY ptl.log_date DESC, u.full_name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3b. Time Log History (Admin only) ---
$isAdminViewer = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true);
$historyByUser = [];
if ($isAdminViewer) {
    try {
        $historySql = "
            SELECT
                h.*,
                u.full_name AS target_user_name,
                cb.full_name AS changed_by_name,
                p.title AS project_title
            FROM project_time_log_history h
            LEFT JOIN users u ON h.user_id = u.id
            LEFT JOIN users cb ON h.changed_by = cb.id
            LEFT JOIN projects p ON h.project_id = p.id
            WHERE DATE(COALESCE(h.new_log_date, h.old_log_date, h.changed_at)) BETWEEN ? AND ?
        ";
        $historyParams = [$startDate, $endDate];

        if ($userFilter !== 'all') {
            $historySql .= " AND h.user_id = ?";
            $historyParams[] = $userFilter;
        } else if ($roleFilter !== 'all') {
            $historySql .= " AND u.role = ?";
            $historyParams[] = $roleFilter;
        } else {
            $historySql .= " AND u.role IN ('project_lead', 'qa', 'at_tester', 'ft_tester')";
        }

        if ($projectFilter !== 'all') {
            $historySql .= " AND h.project_id = ?";
            $historyParams[] = $projectFilter;
        }

        $historySql .= " ORDER BY u.full_name ASC, h.changed_at DESC";

        $historyStmt = $db->prepare($historySql);
        $historyStmt->execute($historyParams);
        $historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($historyRows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if (!isset($historyByUser[$uid])) {
                $historyByUser[$uid] = [
                    'user_name' => $row['target_user_name'] ?: ('User #' . $uid),
                    'rows' => []
                ];
            }
            $historyByUser[$uid]['rows'][] = $row;
        }
    } catch (Exception $e) {
        $historyByUser = [];
    }
}

// --- 4. Calculate Summary Metrics ---
$totalHours = 0;
$utilizedHours = 0;
$benchHours = 0;

foreach ($logs as $log) {
    $hours = floatval($log['hours_spent']);
    $totalHours += $hours;
    
    // Determine utilization
    // Logic matches other reports: is_utilized flag OR not OFF-PROD-001
    $isUtilized = $log['is_utilized'] == 1 || ($log['po_number'] !== 'OFF-PROD-001' && $log['project_id'] !== null);
    
    if ($isUtilized) {
        $utilizedHours += $hours;
    } else {
        $benchHours += $hours;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Production Logs View</h2>
        <div>
            <a href="<?php echo $baseDir; ?>/modules/admin/calendar.php" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-calendar-alt"></i> Back to Calendar
            </a>
            <a href="<?php echo $baseDir; ?>/modules/admin/resource_workload.php" class="btn btn-outline-info ms-2">
                <i class="fas fa-users"></i> Resource Workload
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Role</label>
                    <select name="role_filter" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <option value="project_lead" <?php echo $roleFilter === 'project_lead' ? 'selected' : ''; ?>>Project Lead</option>
                        <option value="qa" <?php echo $roleFilter === 'qa' ? 'selected' : ''; ?>>QA</option>
                        <option value="at_tester" <?php echo $roleFilter === 'at_tester' ? 'selected' : ''; ?>>AT Tester</option>
                        <option value="ft_tester" <?php echo $roleFilter === 'ft_tester' ? 'selected' : ''; ?>>FT Tester</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">User</label>
                    <select name="user_filter" class="form-select">
                        <option value="all">All Users</option>
                        <?php foreach ($usersList as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $userFilter == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Project</label>
                    <select name="project_filter" class="form-select">
                        <option value="all">All Projects</option>
                        <?php foreach ($projectsList as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo $projectFilter == $p['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="d-flex gap-2 w-100">
                        <button type="submit" class="btn btn-primary w-100">Apply</button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-primary"><?php echo number_format($totalHours, 2); ?>h</h3>
                    <p class="mb-0 text-muted">Total Hours Logged</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-success"><?php echo number_format($utilizedHours, 2); ?>h</h3>
                    <p class="mb-0 text-muted">Utilized Hours (Billable/Project)</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h3 class="text-secondary"><?php echo number_format($benchHours, 2); ?>h</h3>
                    <p class="mb-0 text-muted">Bench/Off-Prod Hours</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Production Logs (<?php echo count($logs); ?> entries)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Resource</th>
                            <th>Project / Task Info</th>
                            <th>Details</th>
                            <th class="text-end">Hours</th>
                            <th class="text-center">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No logs found for the selected criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <?php 
                                    $isUtilized = $log['is_utilized'] == 1 || ($log['po_number'] !== 'OFF-PROD-001' && $log['project_id'] !== null);
                                ?>
                                <tr>
                                    <td class="text-nowrap"><?php echo date('M d, Y', strtotime($log['log_date'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($log['user_name']); ?></strong><br>
                                        <span class="badge bg-secondary badge-sm" style="font-size: 0.7em;">
                                            <?php echo ucfirst(str_replace('_', ' ', $log['user_role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['project_title']): ?>
                                            <strong><?php echo htmlspecialchars($log['project_title']); ?></strong>
                                            <?php if ($log['po_number']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($log['po_number']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No Project</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            // Construct details based on what's available
                                            $details = [];
                                            if (!empty($log['page_name'])) $details[] = '<i class="fas fa-file-alt text-muted small me-1"></i> ' . htmlspecialchars($log['page_name']);
                                            if (!empty($log['environment_name'])) $details[] = '<i class="fas fa-server text-muted small me-1"></i> ' . htmlspecialchars($log['environment_name']);
                                            if (!empty($log['comments'])) $details[] = '<span class="text-muted">'.htmlspecialchars($log['comments']).'</span>';
                                            
                                            if (!empty($details)) {
                                                echo implode('<br>', $details);
                                            } else {
                                                echo '<span class="text-muted text-italic">No details provided</span>';
                                            }
                                        ?>
                                    </td>
                                    <td class="text-end font-weight-bold">
                                        <?php echo number_format($log['hours_spent'], 2); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($isUtilized): ?>
                                            <span class="badge bg-success">Utilized</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Bench</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($isAdminViewer): ?>
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Daily Hours Change History (User-wise)</h5>
            <span class="badge bg-info"><?php echo array_sum(array_map(function($g){ return count($g['rows']); }, $historyByUser)); ?> events</span>
        </div>
        <div class="card-body">
            <?php if (empty($historyByUser)): ?>
                <div class="text-muted text-center py-3">No history records found for selected filters.</div>
            <?php else: ?>
                <div class="accordion" id="hoursHistoryAccordion">
                    <?php $hIdx = 0; foreach ($historyByUser as $uid => $group): ?>
                        <?php $collapseId = 'historyUser' . (int)$uid; ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading-<?php echo $collapseId; ?>">
                                <button class="accordion-button <?php echo $hIdx > 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="<?php echo $hIdx === 0 ? 'true' : 'false'; ?>" aria-controls="<?php echo $collapseId; ?>">
                                    <strong><?php echo htmlspecialchars($group['user_name']); ?></strong>
                                    <span class="badge bg-secondary ms-2"><?php echo count($group['rows']); ?> events</span>
                                </button>
                            </h2>
                            <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse <?php echo $hIdx === 0 ? 'show' : ''; ?>" aria-labelledby="heading-<?php echo $collapseId; ?>" data-bs-parent="#hoursHistoryAccordion">
                                <div class="accordion-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Changed At</th>
                                                    <th>Project</th>
                                                    <th>Action</th>
                                                    <th>Date (Old -> New)</th>
                                                    <th>Hours (Old -> New)</th>
                                                    <th>Changed By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($group['rows'] as $row): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($row['changed_at']))); ?></td>
                                                        <td><?php echo htmlspecialchars($row['project_title'] ?: '-'); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $row['action_type'] === 'deleted' ? 'danger' : ($row['action_type'] === 'created' ? 'success' : 'warning'); ?>">
                                                                <?php echo htmlspecialchars(ucfirst($row['action_type'])); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($row['old_log_date'] ?: '-'); ?>
                                                            <i class="fas fa-arrow-right text-muted mx-1"></i>
                                                            <?php echo htmlspecialchars($row['new_log_date'] ?: '-'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($row['old_hours'] !== null ? number_format((float)$row['old_hours'], 2) : '-'); ?>
                                                            <i class="fas fa-arrow-right text-muted mx-1"></i>
                                                            <?php echo htmlspecialchars($row['new_hours'] !== null ? number_format((float)$row['new_hours'], 2) : '-'); ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($row['changed_by_name'] ?: 'Unknown'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php $hIdx++; endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
