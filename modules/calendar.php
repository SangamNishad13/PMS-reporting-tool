<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$baseDir = getBaseDir();

$userId = $_SESSION['user_id'];
$pageTitle = 'My Availability Calendar';
$availabilityStatuses = getAvailabilityStatusOptions(false);
$availabilityStatusMap = [];
foreach ($availabilityStatuses as $statusRow) {
    $statusKey = strtolower(trim((string)($statusRow['status_key'] ?? '')));
    if ($statusKey === '') continue;
    $availabilityStatusMap[$statusKey] = [
        'status_label' => (string)($statusRow['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))),
        'badge_color' => (string)($statusRow['badge_color'] ?? 'secondary')
    ];
}
if (!isset($availabilityStatusMap['not_updated'])) {
    $availabilityStatusMap['not_updated'] = ['status_label' => 'Not Updated', 'badge_color' => 'secondary'];
}
$badgeToHex = [
    'primary' => '#0d6efd',
    'secondary' => '#6c757d',
    'success' => '#198754',
    'danger' => '#dc3545',
    'warning' => '#ffc107',
    'info' => '#0dcaf0',
    'light' => '#f8f9fa',
    'dark' => '#212529'
];

// Get assigned projects for the current user (for quick production hours logging)
$projectsStmt = $db->prepare("
    SELECT p.id, p.title, p.po_number
    FROM projects p
    LEFT JOIN user_assignments ua ON p.id = ua.project_id AND ua.user_id = ?
    WHERE p.status NOT IN ('cancelled', 'archived') AND (ua.id IS NOT NULL OR p.project_lead_id = ? OR p.po_number = 'OFF-PROD-001')
    ORDER BY p.po_number = 'OFF-PROD-001', p.title
");
$projectsStmt->execute([$userId, $userId]);
$assignedProjects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

// Ensure personal notes table exists (safe to run if migration not applied)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_calendar_notes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        note_date DATE NOT NULL,
        content TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_date (user_id, note_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    // ignore - will surface when attempting to use notes if necessary
}

// Handle AJAX request for events
if (isset($_GET['action']) && $_GET['action'] === 'get_events') {
    $start = $_GET['start'] ?? date('Y-m-d');
    $end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));

    $filterUserId = isset($_GET['user_id']) ? $_GET['user_id'] : null;
    $editRequestFilter = isset($_GET['edit_request_filter']) ? $_GET['edit_request_filter'] : null;
    $statusFilters = isset($_GET['status_filter']) ? explode(',', (string)$_GET['status_filter']) : ['all'];
    $statusFilters = array_values(array_filter(array_map(static function ($v) {
        return strtolower(trim((string)$v));
    }, $statusFilters)));
    if (in_array('all', $statusFilters, true) || empty($statusFilters)) {
        $statusFilters = ['all'];
    }
    $statusFilterAllows = static function ($statusKey) use ($statusFilters) {
        $statusKey = strtolower(trim((string)$statusKey));
        if (in_array('all', $statusFilters, true)) return true;
        if (in_array($statusKey, $statusFilters, true)) return true;
        if (($statusKey === 'on_leave' || $statusKey === 'sick_leave') && in_array('leave', $statusFilters, true)) return true;
        return false;
    };
    $isAdminUser = hasAdminPrivileges();

    $events = [];

    // Fetch explicit statuses depending on filter/admin
    if ($isAdminUser && $filterUserId === 'all') {
        $stmt = $db->prepare(
            "SELECT uds.*, u.full_name, u.role, uds.user_id
             FROM user_daily_status uds
             JOIN users u ON uds.user_id = u.id
             WHERE uds.status_date BETWEEN ? AND ?"
        );
        $stmt->execute([$start, $end]);

        $userStmt = $db->prepare("SELECT id, full_name, role FROM users WHERE is_active = 1");
        $userStmt->execute();
        $usersList = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        $usersById = [];
        foreach ($usersList as $u) $usersById[$u['id']] = $u;

    } elseif ($isAdminUser && $filterUserId) {
        $stmt = $db->prepare(
            "SELECT uds.*, u.full_name, u.role, uds.user_id
             FROM user_daily_status uds
             JOIN users u ON uds.user_id = u.id
             WHERE uds.user_id = ?
             AND uds.status_date BETWEEN ? AND ?"
        );
        $stmt->execute([$filterUserId, $start, $end]);

        $userStmt = $db->prepare("SELECT id, full_name, role FROM users WHERE id = ?");
        $userStmt->execute([$filterUserId]);
        $usersList = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        $usersById = [];
        foreach ($usersList as $u) $usersById[$u['id']] = $u;

    } else {
        $stmt = $db->prepare(
            "SELECT uds.*, u.full_name, u.role, uds.user_id
             FROM user_daily_status uds
             JOIN users u ON uds.user_id = u.id
             WHERE uds.user_id = ?
             AND uds.status_date BETWEEN ? AND ?"
        );
        $stmt->execute([$userId, $start, $end]);

        $userStmt = $db->prepare("SELECT id, full_name, role FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $usersList = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        $usersById = [];
        foreach ($usersList as $u) $usersById[$u['id']] = $u;
    }

    // Fetch edit requests for current user in date range
    $editRequests = [];
    if (!$isAdminUser || !$filterUserId) {
        $editStmt = $db->prepare("SELECT req_date, status, reason, created_at, updated_at FROM user_edit_requests WHERE user_id = ? AND req_date BETWEEN ? AND ?");
        $editStmt->execute([$userId, $start, $end]);
        while ($editRow = $editStmt->fetch(PDO::FETCH_ASSOC)) {
            $editRequests[$editRow['req_date']] = $editRow;
        }
    }

    $status_map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $uid = $row['user_id'] ?? null;
        $d = $row['status_date'];
        if ($uid === null) continue;
        if (!isset($status_map[$uid])) $status_map[$uid] = [];
        $status_map[$uid][$d] = $row;

        // Skip if edit request filter is active and doesn't match
        if ($editRequestFilter && !$isAdminUser && $uid == $userId) {
            $hasEditRequest = isset($editRequests[$d]);
            $editStatus = $hasEditRequest ? $editRequests[$d]['status'] : null;
            
            if ($editRequestFilter !== $editStatus) {
                continue;
            }
        }
        $statusKey = strtolower(trim((string)($row['status'] ?? 'not_updated')));
        if (!$statusFilterAllows($statusKey)) {
            continue;
        }

        $badgeColor = strtolower((string)($availabilityStatusMap[$statusKey]['badge_color'] ?? 'secondary'));
        $color = $badgeToHex[$badgeColor] ?? '#6c757d';
        $title = (string)($availabilityStatusMap[$statusKey]['status_label'] ?? ucfirst($row['status']));

        // Add edit request indicator to title if exists
        $titleSuffix = '';
        $colorOverride = null;
        
        if (isset($editRequests[$d])) {
            $editStatus = $editRequests[$d]['status'];
            switch ($editStatus) {
                case 'pending':
                    $titleSuffix .= ' • Edit Pending';
                    $colorOverride = '#fd7e14'; // Orange
                    break;
                case 'approved':
                    $titleSuffix .= ' • Edit Approved';
                    $colorOverride = '#20c997'; // Teal
                    break;
                case 'rejected':
                    $titleSuffix .= ' • Edit Rejected';
                    break;
                case 'used':
                    $titleSuffix .= ' • Edit Used';
                    $colorOverride = '#6f42c1'; // Purple
                    break;
            }
        }

        if ($colorOverride) {
            $color = $colorOverride;
        }

        $displayName = $row['full_name'] ?? ($usersById[$uid]['full_name'] ?? ('User ' . intval($uid)));

        $events[] = [
            'title' => $displayName . ' — ' . $title . $titleSuffix,
            'start' => $d,
            'color' => $color,
            'description' => $row['notes'] ?? '',
            'extendedProps' => [
                'notes' => $row['notes'] ?? '',
                'status' => $row['status'],
                'user_full_name' => $displayName,
                'user_role' => $row['role'] ?? ($usersById[$uid]['role'] ?? null),
                'user_id' => $uid,
                'edit_request' => isset($editRequests[$d]) ? $editRequests[$d] : null
            ]
        ];
    }

    // Build date range and add 'Not updated' for missing dates per-user
    $period = new DatePeriod(
        new DateTime($start),
        new DateInterval('P1D'),
        (new DateTime($end))->modify('+1 day')
    );

    foreach ($period as $dt) {
        $d = $dt->format('Y-m-d');

        if ($isAdminUser && $filterUserId === 'all') {
            foreach ($usersList as $u) {
                $uid = $u['id'];
                if (empty($status_map[$uid][$d])) {
                    if (!$statusFilterAllows('not_updated')) {
                        continue;
                    }
                    $events[] = [
                        'title' => $u['full_name'] . ' (Not updated)',
                        'start' => $d,
                        'color' => '#6c757d',
                        'description' => '',
                        'extendedProps' => [
                            'status' => 'not_updated',
                            'user_id' => $uid,
                            'user_full_name' => $u['full_name'],
                            'user_role' => $u['role'] ?? null
                        ]
                    ];
                }
            }
            continue;
        }

        if (!empty($usersList)) {
            foreach ($usersList as $u) {
                $uid = $u['id'];
                if (empty($status_map[$uid][$d])) {
                    if (!$statusFilterAllows('not_updated')) {
                        continue;
                    }
                    if ($editRequestFilter && !$isAdminUser && $uid == $userId) {
                        $hasEditRequest = isset($editRequests[$d]);
                        $editStatus = $hasEditRequest ? $editRequests[$d]['status'] : null;
                        
                        if ($editRequestFilter !== $editStatus) {
                            continue;
                        }
                    }

                    $title = $u['full_name'] . ' (Not updated)';
                    $color = '#6c757d';
                    
                    if (isset($editRequests[$d])) {
                        $editStatus = $editRequests[$d]['status'];
                        switch ($editStatus) {
                            case 'pending':
                                $title = $u['full_name'] . ' (Edit Pending)';
                                $color = '#fd7e14';
                                break;
                            case 'approved':
                                $title = $u['full_name'] . ' (Edit Approved)';
                                $color = '#20c997';
                                break;
                            case 'rejected':
                                $title = $u['full_name'] . ' (Edit Rejected)';
                                $color = '#e83e8c';
                                break;
                            case 'used':
                                $title = $u['full_name'] . ' (Edit Used)';
                                $color = '#6f42c1';
                                break;
                        }
                    }

                    $events[] = [
                        'title' => $title,
                        'start' => $d,
                        'color' => $color,
                        'description' => '',
                        'extendedProps' => [
                            'status' => 'not_updated',
                            'user_id' => $uid,
                            'user_full_name' => $u['full_name'],
                            'user_role' => $u['role'] ?? null,
                            'edit_request' => isset($editRequests[$d]) ? $editRequests[$d] : null
                        ]
                    ];
                }
            }
        }
    }

    // Add personal notes for this user (only if content is not empty)
    $noteStmt = $db->prepare("SELECT note_date, content FROM user_calendar_notes WHERE user_id = ? AND note_date BETWEEN ? AND ? AND content IS NOT NULL AND TRIM(content) != ''");
    $noteStmt->execute([$userId, $start, $end]);
    while ($n = $noteStmt->fetch(PDO::FETCH_ASSOC)) {
        $events[] = [
            'title' => 'Personal Note',
            'start' => $n['note_date'],
            'color' => '#6610f2',
            'description' => $n['content'] ?? '',
            'extendedProps' => [ 'personal_note' => $n['content'] ?? '' ]
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($events);
    exit;
}

include __DIR__ . '/../includes/header.php';

// If admin, prepare users for admin selector
$usersForSelect = [];
if (hasAdminPrivileges()) {
    try {
        $uStmt = $db->prepare("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name ASC");
        $uStmt->execute();
        $usersForSelect = $uStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $usersForSelect = [];
    }
}

$canEditFuture = true;
?>

<!-- FullCalendar CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>My Availability Calendar</h2>
        <div class="d-flex align-items-center">
            <?php if (!empty($usersForSelect)): ?>
                <div class="me-2">
                    <select id="admin_user_select" class="form-select">
                        <option value="">-- View user production hours --</option>
                        <option value="all">All users</option>
                        <?php foreach ($usersForSelect as $u): ?>
                            <option value="<?php echo intval($u['id']); ?>"><?php echo htmlspecialchars($u['full_name'] . ' (@' . $u['username'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="me-2">
                <select id="edit_request_filter" class="form-select">
                    <option value="">All Dates</option>
                    <option value="pending">Pending Requests</option>
                    <option value="approved">Approved Requests</option>
                    <option value="rejected">Rejected Requests</option>
                    <option value="used">Used Approvals</option>
                </select>
            </div>
            <div class="me-2">
                <div class="btn-group" role="group" aria-label="Filter Status">
                    <?php foreach ($availabilityStatusMap as $statusKey => $meta): ?>
                        <?php
                        $filterId = 'cal_filter_' . preg_replace('/[^a-z0-9_]+/i', '_', $statusKey);
                        $badgeColor = strtolower((string)($meta['badge_color'] ?? 'secondary'));
                        $outlineClass = in_array($badgeColor, ['primary','secondary','success','danger','warning','info','dark'], true)
                            ? $badgeColor
                            : 'secondary';
                        ?>
                        <input type="checkbox" class="btn-check status-filter-check" id="<?php echo htmlspecialchars($filterId, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8'); ?>" checked autocomplete="off">
                        <label class="btn btn-outline-<?php echo htmlspecialchars($outlineClass, ENT_QUOTES, 'UTF-8'); ?>" for="<?php echo htmlspecialchars($filterId, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars((string)($meta['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))), ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php" class="btn btn-primary">Go to Daily Status</a>
            </div>
        </div>
    </div>

    <!-- Legends -->
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-info-circle"></i> Availability Status Legend</h6>
                </div>
                <div class="card-body py-2">
                    <div class="row">
                        <?php $legendIdx = 0; foreach ($availabilityStatusMap as $statusKey => $meta): ?>
                            <?php
                            $colClass = ($legendIdx % 2 === 0) ? 'col-sm-6' : 'col-sm-6';
                            $badgeColor = strtolower((string)($meta['badge_color'] ?? 'secondary'));
                            $hex = $badgeToHex[$badgeColor] ?? '#6c757d';
                            ?>
                            <div class="<?php echo $colClass; ?>">
                                <small class="d-flex align-items-center mb-1">
                                    <span class="badge me-2" style="background-color: <?php echo htmlspecialchars($hex, ENT_QUOTES, 'UTF-8'); ?>;">&nbsp;&nbsp;&nbsp;</span>
                                    <?php echo htmlspecialchars((string)($meta['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))), ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                            </div>
                        <?php $legendIdx++; endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-edit"></i> Edit Request Status Legend</h6>
                </div>
                <div class="card-body py-2">
                    <div class="row">
                        <div class="col-sm-6">
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #fd7e14;">&nbsp;&nbsp;&nbsp;</span>
                                Pending Request
                            </small>
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #20c997;">&nbsp;&nbsp;&nbsp;</span>
                                Approved Request
                            </small>
                        </div>
                        <div class="col-sm-6">
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #e83e8c;">&nbsp;&nbsp;&nbsp;</span>
                                Rejected Request
                            </small>
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #6f42c1;">&nbsp;&nbsp;&nbsp;</span>
                                Used Approval
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div id="calendar"></div>
        </div>
    </div>
</div>

<!-- Calendar Edit Modal -->
<div class="modal fade" id="calendarEditModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update My Availability</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="calendarEditForm">
                <div class="modal-body">
                    <input type="hidden" id="calDate" name="date">
                    
                    <!-- Edit Request Status -->
                    <div id="editRequestStatus" class="alert alert-info" style="display: none;">
                        <h6><i class="fas fa-info-circle"></i> Edit Request Status: <span id="editRequestStatusBadge" class="badge"></span></h6>
                        <div id="editRequestReasonRow" style="display: none;">
                            <strong>Reason:</strong> <span id="editRequestReason"></span>
                        </div>
                        <div id="editRequestDatesRow" style="display: none;">
                            <strong>Requested:</strong> <span id="editRequestDate"></span>
                        </div>
                        <div id="editRequestUpdatedRow" style="display: none;">
                            <strong>Updated:</strong> <span id="editRequestUpdated"></span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="calStatus" class="form-label">Availability Status</label>
                                <select id="calStatus" name="status" class="form-select">
                                    <?php foreach ($availabilityStatuses as $st): ?>
                                        <?php $stKey = (string)($st['status_key'] ?? ''); ?>
                                        <?php if ($stKey === '') continue; ?>
                                        <option value="<?php echo htmlspecialchars($stKey); ?>">
                                            <?php echo htmlspecialchars((string)($st['status_label'] ?? ucfirst(str_replace('_', ' ', $stKey)))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="calNotes" class="form-label">Work Notes</label>
                                <textarea id="calNotes" name="notes" class="form-control" rows="4" placeholder="What did you work on today?"></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="calPersonalNote" class="form-label">Personal Notes (Private)</label>
                                <textarea id="calPersonalNote" name="personal_note" class="form-control" rows="3" placeholder="Personal reminders, thoughts, etc."></textarea>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0"><i class="fas fa-clock"></i> Production Hours <span id="hoursDate"></span></h6>
                                    <button type="button"
                                            id="openLogHoursModalBtn"
                                            class="btn btn-outline-primary btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#calendarLogHoursModal">
                                        Log Hours
                                    </button>
                                </div>
                                <div class="card-body py-2">
                                    <div class="text-center mb-2">
                                        <h5 id="totalHours" class="mb-2">0.00 hrs</h5>
                                        <div class="progress mb-1">
                                            <div id="utilizedProgress" class="progress-bar bg-success" role="progressbar" style="width: 0%">
                                                Utilized
                                            </div>
                                            <div id="benchProgress" class="progress-bar bg-secondary" role="progressbar" style="width: 100%">
                                                Bench
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            Utilized: <span id="utilizedHours">0.00</span>h | 
                                            Bench: <span id="benchHours">0.00</span>h
                                        </small>
                                    </div>
                                    
                                    <div id="hoursEntries" style="max-height: 140px; overflow-y: auto;">
                                        <p class="text-muted text-center">Loading...</p>
                                    </div>
                                    
                                    <!-- Production hours quick-form is rendered in separate modal -->
                                    <div id="calendarModalLogFormContainer" class="d-none">
                                        <form id="logProductionHoursForm" class="row g-2" novalidate>
                                            <div class="col-md-6">
                                                <label class="form-label">Project</label>
                                                <select name="project_id" id="productionProjectSelect" class="form-select" required>
                                                    <option value="">Select Project</option>
                                                    <?php foreach ($assignedProjects as $p): ?>
                                                        <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Task Type</label>
                                                <select name="task_type" id="taskTypeSelect" class="form-select">
                                                    <option value="">Select Task Type</option>
                                                    <option value="page_testing">Page Testing</option>
                                                    <option value="page_qa">Page QA</option>
                                                    <option value="regression_testing">Regression Testing</option>
                                                    <option value="project_phase">Project Phase</option>
                                                    <option value="generic_task">Generic Task</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6" id="pageTestingContainer" style="display:none;">
                                                <label class="form-label">Page / Screen</label>
                                                <select id="productionPageSelect" class="form-select" multiple size="4">
                                                    <option value="">Select project first</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6" id="productionEnvCol" style="display:none;">
                                                <label class="form-label">Environment</label>
                                                <select id="productionEnvSelect" class="form-select">
                                                    <option value="">Select page first</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Hours</label>
                                                <input type="number" id="logHoursInput" step="0.25" min="0.25" class="form-control" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Description</label>
                                                <input type="text" id="logDescriptionInput" class="form-control">
                                            </div>
                                            <div class="col-12 d-flex justify-content-end">
                                                <button type="button" id="logTimeBtn" class="btn btn-primary" onclick="if(window.submitCalendarLogHours){return window.submitCalendarLogHours(event);} return false;">Log Hours</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" id="requestEditFooterBtn" class="btn btn-warning" style="display:none;">Request Edit</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <!-- Dynamic buttons will be added here -->
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Log Hours Modal -->
<div class="modal fade" id="calendarLogHoursModal" tabindex="-1" aria-labelledby="calendarLogHoursModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="calendarLogHoursModalLabel">Log Production Hours</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="calendarLogHoursModalBody">
                <div id="calendarLogStatus" class="alert d-none py-2 px-3 small mb-3" role="alert"></div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Request Modal -->
<div class="modal fade" id="editRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Request Edit Permission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editRequestForm">
                <div class="modal-body">
                    <input type="hidden" id="requestDate" name="date">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        You are requesting permission to edit a past date. Please provide a reason for this request.
                    </div>
                    
                    <div class="mb-3">
                        <label for="editReason" class="form-label">Reason for Edit Request <span class="text-danger">*</span></label>
                        <textarea id="editReason" name="reason" class="form-control" rows="3" placeholder="Please explain why you need to edit this past date..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" id="editRequestSendBtn">Send Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<style>
#calendarEditModal .modal-dialog {
    max-width: min(1140px, 96vw);
    margin: 0.75rem auto;
    height: calc(100vh - 1.5rem);
}
#calendarEditModal .modal-content {
    height: 100%;
    max-height: none;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
#calendarEditModal #calendarEditForm {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
}
#calendarEditModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 1rem;
}
#calendarEditModal .modal-footer {
    position: sticky;
    bottom: 0;
    min-height: 60px;
    background: #fff;
    z-index: 2;
    border-top: 1px solid #dee2e6;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var canEditFuture = <?php echo $canEditFuture ? 'true' : 'false'; ?>;
    var assignedProjects = <?php echo json_encode($assignedProjects, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?> || [];
    var calendarEl = document.getElementById('calendar');
    var lastClickedDate = null;
    var lastClickedEditRequest = null;
    var isAdmin = <?php echo hasAdminPrivileges() ? 'true' : 'false'; ?>;

    // Helper function to check if date is editable by the user directly.
    // Allow only: today, and the previous business day (yesterday or last Friday if today is Monday).
    // Future dates are NOT editable here (production-hours form will be hidden for future dates).
    function isEditableDate(dateStr) {
        try {
            var date = new Date(dateStr + 'T00:00:00');
            var today = new Date();
            today.setHours(0, 0, 0, 0);

            // Allow today
            if (date.getTime() === today.getTime()) return true;

            // Allow previous business day: usually yesterday, except when today is Monday -> last Friday
            var prev = new Date(today);
            prev.setDate(prev.getDate() - 1);
            if (today.getDay() === 1) { // Monday
                var lastFriday = new Date(today);
                lastFriday.setDate(lastFriday.getDate() - 3);
                return date.getTime() === lastFriday.getTime();
            }

            return date.getTime() === prev.getTime();
        } catch (e) {
            return false;
        }
    }

    function resetModal() {
        var modalTitle = document.querySelector('#calendarEditModal .modal-title');
        if (modalTitle) {
            modalTitle.textContent = 'Update My Availability';
            modalTitle.className = 'modal-title';
        }
        
        document.getElementById('calDate').value = '';
        try { document.getElementById('calendarEditModal').dataset.activeDate = ''; } catch (e) {}
        document.getElementById('calStatus').value = 'not_updated';
        document.getElementById('calNotes').value = '';
        document.getElementById('calPersonalNote').value = '';
        
        document.getElementById('editRequestStatus').style.display = 'none';
        lastClickedEditRequest = null;
        var reqFooterBtn = document.getElementById('requestEditFooterBtn');
        if (reqFooterBtn) {
            reqFooterBtn.style.display = 'none';
            reqFooterBtn.onclick = null;
        }
        var modalFooter = document.querySelector('#calendarEditModal .modal-footer');
        if (modalFooter) {
            var dynamicButtons = modalFooter.querySelectorAll('.dynamic-btn');
            dynamicButtons.forEach(btn => btn.remove());
        }
        
        document.getElementById('totalHours').textContent = '0.00 hrs';
        document.getElementById('utilizedHours').textContent = '0.00';
        document.getElementById('benchHours').textContent = '0.00';
        document.getElementById('utilizedProgress').style.width = '0%';
        document.getElementById('benchProgress').style.width = '100%';
        document.getElementById('hoursEntries').innerHTML = '<p class="text-muted text-center">Loading...</p>';
    }

    function enableEditing() {
        try {
            var el = document.getElementById('calStatus'); if (el) el.disabled = false;
            var n = document.getElementById('calNotes'); if (n) n.readOnly = false;
            var p = document.getElementById('calPersonalNote'); if (p) p.readOnly = false;

            // Production form fields
            var selectors = [
                '#logProductionHoursForm select[name="project_id"]', '#productionProjectSelect',
                '#productionPageSelect', '#productionEnvSelect', '#taskTypeSelect', '#testingTypeSelect', '#productionIssueSelect',
                '#logHoursInput', '#logDescriptionInput', '#logTimeBtn'
            ];
            selectors.forEach(function(s){
                var el = document.querySelector(s);
                if (!el) return;
                try { el.disabled = false; } catch(e) {}
                try { el.readOnly = false; } catch(e) {}
            });
        } catch (e) {}
    }

    function disableEditing() {
        try {
            var el = document.getElementById('calStatus'); if (el) el.disabled = true;
            var n = document.getElementById('calNotes'); if (n) n.readOnly = true;
            var p = document.getElementById('calPersonalNote'); if (p) p.readOnly = true;

            // Production form fields
            var selectors = [
                '#logProductionHoursForm select[name="project_id"]', '#productionProjectSelect',
                '#productionPageSelect', '#productionEnvSelect', '#taskTypeSelect', '#testingTypeSelect', '#productionIssueSelect',
                '#logHoursInput', '#logDescriptionInput', '#logTimeBtn'
            ];
            selectors.forEach(function(s){
                var el = document.querySelector(s);
                if (!el) return;
                try { el.disabled = true; } catch(e) {}
                try { el.readOnly = true; } catch(e) {}
            });
        } catch (e) {}
    }

    function loadProductionHours(date) {
        document.getElementById('hoursDate').textContent = '(' + date + ')';
        
        var url = '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/user_hours.php?user_id=<?php echo $userId; ?>&date=' + encodeURIComponent(date);
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                
                if (data.success) {
                    var totalHours = parseFloat(data.total_hours || 0);
                    var utilizedHours = 0;
                    var benchHours = 0;
                    
                    document.getElementById('totalHours').textContent = totalHours.toFixed(2) + ' hrs';
                    
                    if (data.entries && data.entries.length > 0) {
                        var productionEntries = data.entries.filter(function(entry) {
                            return entry.po_number !== 'OFF-PROD-001';
                        });
                        var benchEntries = data.entries.filter(function(entry) {
                            return entry.po_number === 'OFF-PROD-001';
                        });
                        
                        var html = '<div class="list-group list-group-flush">';
                        
                        if (productionEntries.length > 0) {
                            html += '<div class="list-group-item bg-light"><strong class="text-success">Production Hours</strong></div>';
                            productionEntries.forEach(function(entry) {
                                var hours = parseFloat(entry.hours_spent || 0);
                                utilizedHours += hours;
                                
                                html += '<div class="list-group-item py-2">';
                                html += '<div class="d-flex justify-content-between align-items-start">';
                                html += '<div class="flex-grow-1">';
                                html += '<h6 class="mb-1">' + escapeHtml(entry.project_title || 'Unknown Project') + '</h6>';
                                
                                // Show task type and details
                                if (entry.task_type === 'page_testing' && entry.page_name) {
                                    html += '<p class="mb-1 text-muted small"><i class="fas fa-desktop"></i> Page: ' + escapeHtml(entry.page_name) + '</p>';
                                    if (entry.environment_name) {
                                        html += '<p class="mb-1 text-muted small"><i class="fas fa-cog"></i> Environment: ' + escapeHtml(entry.environment_name) + '</p>';
                                    }
                                    if (entry.testing_type) {
                                        html += '<p class="mb-1 text-muted small"><i class="fas fa-tasks"></i> Type: ' + escapeHtml(entry.testing_type.replace('_', ' ')) + '</p>';
                                    }
                                } else if (entry.task_type === 'project_phase' && entry.phase_name) {
                                    html += '<p class="mb-1 text-muted small"><i class="fas fa-project-diagram"></i> Phase: ' + escapeHtml(entry.phase_name) + '</p>';
                                } else if (entry.task_type === 'generic_task' && entry.generic_category_name) {
                                    html += '<p class="mb-1 text-muted small"><i class="fas fa-tag"></i> Category: ' + escapeHtml(entry.generic_category_name) + '</p>';
                                } else if (entry.page_name) {
                                    // Fallback for older entries without task_type
                                    html += '<p class="mb-1 text-muted small"><i class="fas fa-desktop"></i> Page: ' + escapeHtml(entry.page_name) + '</p>';
                                    if (entry.environment_name) {
                                        html += '<p class="mb-1 text-muted small"><i class="fas fa-cog"></i> Environment: ' + escapeHtml(entry.environment_name) + '</p>';
                                    }
                                }
                                
                                if (entry.comments) {
                                    html += '<p class="mb-0 small">' + escapeHtml(entry.comments) + '</p>';
                                }
                                html += '</div>';
                                html += '<div class="text-end">';
                                html += '<span class="badge bg-success">' + hours.toFixed(2) + 'h</span>';
                                html += '</div>';
                                html += '</div>';
                                html += '</div>';
                            });
                        }
                        
                        if (benchEntries.length > 0) {
                            html += '<div class="list-group-item bg-light"><strong class="text-secondary">Off-Production/Bench Hours</strong></div>';
                            benchEntries.forEach(function(entry) {
                                var hours = parseFloat(entry.hours_spent || 0);
                                benchHours += hours;
                                
                                html += '<div class="list-group-item py-2">';
                                html += '<div class="d-flex justify-content-between align-items-start">';
                                html += '<div class="flex-grow-1">';
                                html += '<h6 class="mb-1 text-secondary">Off-Production Activity</h6>';
                                if (entry.comments) {
                                    html += '<p class="mb-0 small">' + escapeHtml(entry.comments) + '</p>';
                                }
                                html += '</div>';
                                html += '<div class="text-end">';
                                html += '<span class="badge bg-secondary">' + hours.toFixed(2) + 'h</span>';
                                html += '</div>';
                                html += '</div>';
                                html += '</div>';
                            });
                        }
                        
                        html += '</div>';
                        document.getElementById('hoursEntries').innerHTML = html;
                    } else {
                        document.getElementById('hoursEntries').innerHTML = '<p class="text-muted text-center">No time logged for this date</p>';
                    }
                    
                    document.getElementById('utilizedHours').textContent = utilizedHours.toFixed(2);
                    document.getElementById('benchHours').textContent = benchHours.toFixed(2);
                    
                    if (totalHours > 0) {
                        var utilizedPercent = (utilizedHours / totalHours) * 100;
                        var benchPercent = (benchHours / totalHours) * 100;
                        document.getElementById('utilizedProgress').style.width = utilizedPercent + '%';
                        document.getElementById('benchProgress').style.width = benchPercent + '%';
                    }
                } else {
                    document.getElementById('hoursEntries').innerHTML = '<p class="text-danger text-center">Failed to load production hours: ' + (data.error || 'Unknown error') + '</p>';
                }
            })
            .catch(error => {
                document.getElementById('hoursEntries').innerHTML = '<p class="text-danger text-center">Error loading production hours: ' + error.message + '</p>';
            });
    }

    function addModalButtons(date) {
        var normalizedDate = String(date || '').slice(0, 10);
        var modalFooter = document.querySelector('#calendarEditModal .modal-footer');
        if (!modalFooter) return;
        var oldDynamicButtons = modalFooter.querySelectorAll('.dynamic-btn');
        oldDynamicButtons.forEach(function(btn){ btn.remove(); });
        
        var cancelBtn = modalFooter.querySelector('button[data-bs-dismiss="modal"]');
        if (!cancelBtn) return;
        
        var today = new Date();
        var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        var isFutureDate = normalizedDate > todayStr;
        var isPastDate = normalizedDate < todayStr;
        var reqFooterBtn = document.getElementById('requestEditFooterBtn');
        function showRequestFooter(show) {
            if (!reqFooterBtn) return;
            reqFooterBtn.style.display = show ? 'inline-block' : 'none';
            reqFooterBtn.onclick = show ? function() { openEditRequestModal(normalizedDate); } : null;
        }
        // Default hidden; each branch decides explicitly.
        showRequestFooter(false);

        if (isPastDate) {
            // Request Edit is handled by fixed footer button.
        }

        if (isEditableDate(normalizedDate) || isFutureDate) {
            // Today / previous business day OR future dates -> allow saving availability changes
            enableEditing();
            var saveBtn = document.createElement('button');
            saveBtn.type = 'submit';
            saveBtn.className = 'btn btn-success dynamic-btn';
            saveBtn.textContent = 'Save Changes';
            modalFooter.insertBefore(saveBtn, cancelBtn);
            // Never show Request Edit for future dates
            if (isFutureDate) return;

            checkEditRequestStatus(normalizedDate, function(pending, approved, status, pendingLocked) {
                if (pending) {
                    // Pending exists: if submitted, keep read-only. Otherwise allow pending edit.
                    showRequestFooter(false);
                    if (pendingLocked) {
                        disableEditing();
                    } else {
                        var pendingBtnEditable = document.createElement('button');
                        pendingBtnEditable.type = 'button';
                        pendingBtnEditable.className = 'btn btn-warning dynamic-btn';
                        pendingBtnEditable.textContent = 'Edit Pending Changes';
                        pendingBtnEditable.onclick = function() {
                            enableEditingForPendingRequest(normalizedDate);
                        };
                        modalFooter.insertBefore(pendingBtnEditable, cancelBtn);
                    }
                } else {
                    showRequestFooter(true);
                }
            });

        } else {
            // Past dates - check approval status
            checkEditRequestStatus(normalizedDate, function(pending, approved, status, pendingLocked) {
                if (approved) {
                    // Approved requests are already applied by admin flow.
                    // User should raise a fresh request for any further edits.
                    showRequestFooter(true);
                } else if (pending) {
                    // Pending exists: if submitted, keep read-only. Otherwise allow pending edit.
                    showRequestFooter(false);
                    if (pendingLocked) {
                        disableEditing();
                    } else {
                        var editBtn = document.createElement('button');
                        editBtn.type = 'button';
                        editBtn.className = 'btn btn-warning dynamic-btn';
                        editBtn.textContent = 'Edit Pending Changes';
                        editBtn.onclick = function() {
                            enableEditingForPendingRequest(normalizedDate);
                        };
                        modalFooter.insertBefore(editBtn, cancelBtn);
                    }
                } else {
                    // No pending/approved request: show only Request Edit.
                    showRequestFooter(true);
                }
            });
        }
    }

    function checkEditRequestStatus(date, callback) {
        fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php?action=check_edit_request&date=' + encodeURIComponent(date))
            .then(response => response.json())
            .then(data => {
                callback(data.pending || false, data.approved || false, data.status || null, data.pending_locked || false);
            })
            .catch(() => {
                callback(false, false, null, false);
            });
    }

    function loadDateData(date) {
        fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php?action=get_personal_note&date=' + encodeURIComponent(date))
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    document.getElementById('calStatus').value = data.status || 'not_updated';
                    document.getElementById('calNotes').value = data.notes || '';
                    document.getElementById('calPersonalNote').value = data.personal_note || '';

                    var modalTitle = document.querySelector('#calendarEditModal .modal-title');
                    if (modalTitle) {
                        modalTitle.textContent = 'Update My Availability';
                        modalTitle.className = 'modal-title';
                    }
                }
            })
            .catch(error => {
                // suppressed date data fetch error
                document.getElementById('calStatus').value = 'not_updated';
                document.getElementById('calNotes').value = '';
                document.getElementById('calPersonalNote').value = '';
            });
    }

    function displayEditRequestInfo(editRequest) {
        var editRequestStatus = document.getElementById('editRequestStatus');
        var statusBadge = document.getElementById('editRequestStatusBadge');
        var reasonRow = document.getElementById('editRequestReasonRow');
        var reason = document.getElementById('editRequestReason');
        var datesRow = document.getElementById('editRequestDatesRow');
        var requestDate = document.getElementById('editRequestDate');
        var updatedRow = document.getElementById('editRequestUpdatedRow');
        var updated = document.getElementById('editRequestUpdated');
        
        if (!editRequest) {
            editRequestStatus.style.display = 'none';
            return;
        }
        
        editRequestStatus.style.display = 'block';
        
        var status = editRequest.status;
        statusBadge.className = 'badge ';
        statusBadge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        
        switch (status) {
            case 'pending':
                statusBadge.className += 'bg-warning text-dark';
                break;
            case 'approved':
                statusBadge.className += 'bg-success';
                break;
            case 'rejected':
                statusBadge.className += 'bg-danger';
                break;
            case 'used':
                statusBadge.className += 'bg-secondary';
                break;
        }
        
        if (editRequest.reason) {
            reasonRow.style.display = 'block';
            reason.textContent = editRequest.reason;
        } else {
            reasonRow.style.display = 'none';
        }
        
        if (editRequest.created_at) {
            datesRow.style.display = 'block';
            requestDate.textContent = new Date(editRequest.created_at).toLocaleString();
        } else {
            datesRow.style.display = 'none';
        }
        
        if (editRequest.updated_at && (status === 'approved' || status === 'rejected' || status === 'used')) {
            updatedRow.style.display = 'block';
            updated.textContent = new Date(editRequest.updated_at).toLocaleString();
        } else {
            updatedRow.style.display = 'none';
        }
    }

    function openModalForDate(date, eventInfo) {
        resetModal();
        document.getElementById('calDate').value = date;
        try { document.getElementById('calendarEditModal').dataset.activeDate = date; } catch (e) {}
        lastClickedDate = date;
        lastClickedEditRequest = (eventInfo && eventInfo.extendedProps && eventInfo.extendedProps.edit_request)
            ? eventInfo.extendedProps.edit_request
            : null;
        
        if (eventInfo && eventInfo.extendedProps && eventInfo.extendedProps.edit_request) {
            displayEditRequestInfo(eventInfo.extendedProps.edit_request);
        }
        
        loadDateData(date);
        loadProductionHours(date);
        
        var modal = new bootstrap.Modal(document.getElementById('calendarEditModal'));
        modal.show();
        // production form visibility will be handled below based on date rules

        // Populate project select to ensure options show in the modal
        (function populateProjectSelect(){
            try {
                var projSel = document.querySelector('#logProductionHoursForm select[name="project_id"]');
                if (!projSel) return;
                projSel.innerHTML = '';
                var blank = document.createElement('option'); blank.value = ''; blank.textContent = 'Select Project';
                projSel.appendChild(blank);
                assignedProjects.forEach(function(p){
                    var opt = document.createElement('option');
                    opt.value = p.id || p.ID || '';
                    opt.textContent = (p.po_number ? ('['+p.po_number+'] ') : '') + (p.title || p.title_name || p.name || 'Project');
                    projSel.appendChild(opt);
                });
            } catch (e) {}
        })();

        // Adjust modal: future dates hide production form; only today/previous business day allow direct edits;
        // older past dates require an edit request (show Request Edit button and hide production form).
        (function adjustModalForDate(){
            try {
                var dt = new Date(date + 'T00:00:00');
                var today = new Date(); today.setHours(0,0,0,0);
                var formContainer = document.getElementById('calendarModalLogFormContainer');
                var openLogBtn = document.getElementById('openLogHoursModalBtn');
                var modalFooter = document.querySelector('#calendarEditModal .modal-footer');

                // Clean up any previous dynamic buttons
                if (modalFooter) {
                    var oldBtns = modalFooter.querySelectorAll('.dynamic-btn, .request-edit-btn');
                    oldBtns.forEach(function(b){ b.remove(); });
                }

                // Future dates: hide production form (only availability should be editable here)
                if (dt.getTime() > today.getTime()) {
                    if (formContainer) formContainer.style.display = 'none';
                    if (openLogBtn) openLogBtn.style.display = 'none';
                    // availability fields editable
                    try { document.getElementById('calStatus').disabled = false; document.getElementById('calNotes').readOnly = false; document.getElementById('calPersonalNote').readOnly = false; } catch(e) {}
                    return;
                }

                // Today or previous business day: allow direct edits and show form
                if (isEditableDate(date)) {
                    if (formContainer) formContainer.style.display = '';
                    if (openLogBtn) openLogBtn.style.display = '';
                    enableEditing();
                    return;
                }

                // Older past dates: show production form but keep it read-only; Request Edit button provided by addModalButtons
                if (formContainer) formContainer.style.display = '';
                if (openLogBtn) openLogBtn.style.display = '';
                disableEditing();
            } catch (e) {}
        })();
    }

    function openEditRequestModal(date) {
        document.getElementById('requestDate').value = date;
        document.getElementById('editReason').value = '';
        var sendBtn = document.getElementById('editRequestSendBtn');
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send Request';
        }
        var modal = new bootstrap.Modal(document.getElementById('editRequestModal'));
        modal.show();
    }

    function sendEditRequestWithReason(date, reason) {
        var sendBtn = document.getElementById('editRequestSendBtn');
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
        }
        var modalEl = document.getElementById('editRequestModal');
        try {
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        } catch (e) {}

        // Optimistic UI: allow editing immediately after click.
        enableEditingForPendingRequest(date);

        var formData = new FormData();
        formData.append('action', 'request_edit');
        formData.append('date', date);
        formData.append('reason', reason);
        
        fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Edit request sent successfully! You can now make changes that will be saved as pending.', 'success');
            } else {
                // Rollback optimistic state on server failure.
                disableEditing();
                addModalButtons(date);
                showToast('Failed to send edit request: ' + (data.error || 'Unknown error'), 'danger');
                try {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                } catch (e) {}
            }
        })
        .catch(error => {
            // Rollback optimistic state on request failure.
            disableEditing();
            addModalButtons(date);
            showToast('Failed to send edit request. Please try again.', 'danger');
            try {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } catch (e) {}
        })
        .finally(() => {
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send Request';
            }
        });
    }

    function enableEditingForPendingRequest(date) {
        var modalFooter = document.querySelector('#calendarEditModal .modal-footer');
        if (modalFooter) {
            var dynamicButtons = modalFooter.querySelectorAll('.dynamic-btn');
            dynamicButtons.forEach(btn => btn.remove());
            
            var cancelBtn = modalFooter.querySelector('button[data-bs-dismiss="modal"]');
            
            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'btn btn-warning dynamic-btn';
            saveBtn.textContent = 'Save Pending Changes';
            saveBtn.onclick = function() {
                savePendingChanges(date, false);
            };
            modalFooter.insertBefore(saveBtn, cancelBtn);

            var submitBtn = document.createElement('button');
            submitBtn.type = 'button';
            submitBtn.className = 'btn btn-primary dynamic-btn';
            submitBtn.textContent = 'Submit Pending';
            submitBtn.onclick = function() {
                confirmModal('Submit pending changes now? After submit, you will not be able to change them until admin reviews.', function() {
                    submitPendingChanges(date);
                }, {
                    title: 'Submit Pending Changes',
                    confirmText: 'Submit',
                    confirmClass: 'btn-primary'
                });
            };
            modalFooter.insertBefore(submitBtn, cancelBtn);
        }

        var reqFooterBtn = document.getElementById('requestEditFooterBtn');
        if (reqFooterBtn) {
            reqFooterBtn.style.display = 'none';
            reqFooterBtn.onclick = null;
        }
        
        enableEditing();
    }

    function savePendingChanges(date, closeOnSuccess) {
        var shouldClose = (closeOnSuccess === true);
        var formData = new FormData();
        formData.append('action', 'save_pending');
        formData.append('date', date);
        formData.append('status', document.getElementById('calStatus').value);
        formData.append('notes', document.getElementById('calNotes').value);
        formData.append('personal_note', document.getElementById('calPersonalNote').value);

        fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (window._myCalendar) {
                    window._myCalendar.refetchEvents();
                }
                if (shouldClose) {
                    var modal = bootstrap.Modal.getInstance(document.getElementById('calendarEditModal'));
                    if (modal) modal.hide();
                }
                showToast('Pending changes saved.', 'success');
            } else {
                showToast('Failed to save pending changes: ' + (data.error || 'Unknown error'), 'danger');
            }
        })
            .catch(error => {
            showToast('Request failed. Please try again.', 'danger');
        });
    }

    function submitPendingChanges(date) {
        var formData = new FormData();
        formData.append('action', 'save_pending');
        formData.append('date', date);
        formData.append('status', document.getElementById('calStatus').value);
        formData.append('notes', document.getElementById('calNotes').value);
        formData.append('personal_note', document.getElementById('calPersonalNote').value);

        fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(function(saveResp) {
            if (!saveResp || !saveResp.success) {
                throw new Error((saveResp && saveResp.error) ? saveResp.error : 'Failed to save pending changes.');
            }
            var submitFd = new FormData();
            submitFd.append('action', 'submit_pending');
            submitFd.append('date', date);
            return fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php', {
                method: 'POST',
                body: submitFd
            });
        })
        .then(response => response.json())
        .then(function(submitResp) {
            if (!submitResp || !submitResp.success) {
                throw new Error((submitResp && submitResp.error) ? submitResp.error : 'Failed to submit pending changes.');
            }
            disableEditing();
            addModalButtons(date);
            if (window._myCalendar) {
                window._myCalendar.refetchEvents();
            }
            showToast('Pending changes submitted. You can no longer edit until admin reviews.', 'success');
        })
        .catch(function(err) {
            showToast(err.message || 'Failed to submit pending changes.', 'danger');
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[s];
        });
    }

    function getSelectedStatusFilters() {
        var checks = document.querySelectorAll('.status-filter-check:checked');
        var values = Array.prototype.map.call(checks, function (el) { return String(el.value || '').trim(); })
            .filter(function (v) { return v.length > 0; });
        return values.length ? values.join(',') : 'all';
    }

    function getEventsUrl() {
        var sel = document.getElementById('admin_user_select');
        var user = sel ? sel.value : '';
        var editFilter = document.getElementById('edit_request_filter');
        var editRequestFilter = editFilter ? editFilter.value : '';
        var statusFilter = getSelectedStatusFilters();
        
        var url = '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/calendar.php?action=get_events';
        if (user) url += '&user_id=' + encodeURIComponent(user);
        if (editRequestFilter) url += '&edit_request_filter=' + encodeURIComponent(editRequestFilter);
        if (statusFilter) url += '&status_filter=' + encodeURIComponent(statusFilter);
        return url;
    }

    // Initialize FullCalendar
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,listWeek'
        },
        events: getEventsUrl(),
        dateClick: function(info) {
            if (!isAdmin) {
                openModalForDate(info.dateStr, null);
            }
        },
        eventClick: function(info) {
            if (!isAdmin) {
                openModalForDate(info.event.startStr, info.event);
            }
        },
        eventDidMount: function(info) {
            // Add tooltip with event details
            if (info.event.extendedProps.notes) {
                info.el.setAttribute('title', info.event.extendedProps.notes);
            }
        }
    });

    window._myCalendar = calendar;
    calendar.render();

    function setCalendarLogStatus(message, type) {
        var statusEl = document.getElementById('calendarLogStatus');
        if (!statusEl) return;
        statusEl.className = 'alert py-2 px-3 small mb-3';
        if (type === 'success') statusEl.classList.add('alert-success');
        else if (type === 'warning') statusEl.classList.add('alert-warning');
        else statusEl.classList.add('alert-danger');
        statusEl.textContent = message;
        statusEl.classList.remove('d-none');
    }

    function notifyCalendar(message, type) {
        if (typeof showToast === 'function') {
            try { showToast(message, type || 'info'); } catch (e) {}
        }
        setCalendarLogStatus(message, type || 'danger');
    }

    function submitCalendarLogHours(e) {
        if (e) e.preventDefault();
        try {
            var dateEl = document.getElementById('calDate');
            var date = dateEl ? String(dateEl.value || '').slice(0, 10) : '';
            if (!date) {
                try {
                    var modalDate = document.getElementById('calendarEditModal').dataset.activeDate || '';
                    date = String(modalDate).slice(0, 10);
                } catch (err) {}
            }
            if (!date && lastClickedDate) {
                date = String(lastClickedDate).slice(0, 10);
            }
            if (dateEl && date) {
                dateEl.value = date;
            }
            var projectEl = document.getElementById('productionProjectSelect') || document.querySelector('#logProductionHoursForm select[name="project_id"]');
            var hoursEl = document.getElementById('logHoursInput');
            var submitBtn = document.getElementById('logTimeBtn');
            var taskTypeEl = document.getElementById('taskTypeSelect');
            var pageEl = document.getElementById('productionPageSelect');
            var envEl = document.getElementById('productionEnvSelect');
            var testingTypeEl = document.getElementById('testingTypeSelect');
            var descEl = document.getElementById('logDescriptionInput');
            var pageColEl = document.getElementById('pageTestingContainer');
            var envColEl = document.getElementById('productionEnvCol');
            var calFormEl = document.getElementById('logProductionHoursForm');
            if (submitBtn && submitBtn.dataset.logging === '1') {
                return false;
            }

            if (!date) {
                notifyCalendar('Date is missing. Reopen the modal and try again.', 'warning');
                return false;
            }
            var projectValue = '';
            if (projectEl) {
                projectValue = String(projectEl.value || '').trim();
                if (!projectValue && typeof projectEl.selectedIndex === 'number' && projectEl.selectedIndex >= 0 && projectEl.options && projectEl.options[projectEl.selectedIndex]) {
                    projectValue = String(projectEl.options[projectEl.selectedIndex].value || '').trim();
                }
            }
            if (!projectValue && calFormEl) {
                try {
                    var fdProbe = new FormData(calFormEl);
                    projectValue = String(fdProbe.get('project_id') || '').trim();
                } catch (err) {}
            }
            if (!projectValue) {
                notifyCalendar('Please select a project.', 'warning');
                return false;
            }
            if (!hoursEl || !hoursEl.value || parseFloat(hoursEl.value) <= 0) {
                notifyCalendar('Please enter valid hours.', 'warning');
                return false;
            }

            var oldLabel = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.logging = '1';
                submitBtn.textContent = 'Logging...';
            }

            var fd = new FormData();
            fd.append('action', 'log');
            fd.append('user_id', '<?php echo $userId; ?>');
            fd.append('project_id', projectValue);
            fd.append('task_type', taskTypeEl ? taskTypeEl.value : '');
            var pages = pageEl ? Array.from(pageEl.selectedOptions || []).map(function(o){ return o.value; }).filter(Boolean) : [];
            if (pages.length) fd.append('page_id', pages[0]);
            var envs = envEl ? Array.from(envEl.selectedOptions || []).map(function(o){ return o.value; }).filter(Boolean) : [];
            if (envs.length) fd.append('environment_id', envs[0]);
            fd.append('testing_type', testingTypeEl ? testingTypeEl.value : '');
            fd.append('log_date', date);
            fd.append('hours', hoursEl.value);
            fd.append('description', descEl ? descEl.value : '');
            fd.append('is_utilized', 1);

            // For older past dates, hours should be saved into pending changes (not directly logged).
            var t = new Date();
            var todayStr = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
            var isOlderPastDate = date < todayStr && !isEditableDate(date);
            if (!isAdmin && isOlderPastDate) {
                checkEditRequestStatus(date, function(pending, approved, status, pendingLocked) {
                    if (!pending) {
                        notifyCalendar('Please send an Edit Request first for this date.', 'warning');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = oldLabel || 'Log Hours';
                        }
                        return;
                    }
                    if (pendingLocked) {
                        notifyCalendar('Pending changes are already submitted and locked. You cannot edit until admin reviews.', 'warning');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = oldLabel || 'Log Hours';
                        }
                        return;
                    }

                    var pendingEntry = {
                        project_id: projectValue,
                        task_type: taskTypeEl ? taskTypeEl.value : '',
                        page_ids: pageEl ? Array.from(pageEl.selectedOptions || []).map(function(o){ return o.value; }).filter(Boolean) : [],
                        environment_ids: envEl ? Array.from(envEl.selectedOptions || []).map(function(o){ return o.value; }).filter(Boolean) : [],
                        testing_type: testingTypeEl ? testingTypeEl.value : '',
                        issue_id: '',
                        hours: hoursEl.value,
                        description: descEl ? descEl.value : '',
                        is_utilized: 1
                    };

                    var pendingFd = new FormData();
                    pendingFd.append('action', 'save_pending');
                    pendingFd.append('date', date);
                    pendingFd.append('status', document.getElementById('calStatus') ? document.getElementById('calStatus').value : 'not_updated');
                    pendingFd.append('notes', document.getElementById('calNotes') ? document.getElementById('calNotes').value : '');
                    pendingFd.append('personal_note', document.getElementById('calPersonalNote') ? document.getElementById('calPersonalNote').value : '');
                    pendingFd.append('pending_time_logs', JSON.stringify([pendingEntry]));
                    pendingFd.append('pending_time_logs_append', '1');

                    fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php', {
                        method: 'POST',
                        body: pendingFd,
                        credentials: 'same-origin'
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            loadProductionHours(date);
                            if (window._myCalendar && typeof window._myCalendar.refetchEvents === 'function') window._myCalendar.refetchEvents();
                            addModalButtons(date);
                            try {
                                var logModalInst = bootstrap.Modal.getOrCreateInstance(document.getElementById('calendarLogHoursModal'));
                                logModalInst.hide();
                            } catch (err) {}
                            if (calFormEl) calFormEl.reset();
                            if (pageColEl) pageColEl.style.display = 'none';
                            if (envColEl) envColEl.style.display = 'none';
                            notifyCalendar('Hours saved to pending changes.', 'success');
                        } else {
                            notifyCalendar('Failed to save pending hours: ' + ((resp && (resp.error || resp.message)) || 'Unknown error'), 'danger');
                        }
                    })
                    .catch(function(err){
                        notifyCalendar('Error saving pending hours: ' + err.message, 'danger');
                    })
                    .finally(function(){
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            delete submitBtn.dataset.logging;
                            submitBtn.textContent = oldLabel || 'Log Hours';
                        }
                    });
                });
                return false;
            }

            fetch('<?php echo $baseDir; ?>/api/project_hours.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){
                    return r.text().then(function(text){
                        var parsed = null;
                        try { parsed = JSON.parse(text); } catch (e) {}
                        return { ok: r.ok, status: r.status, body: parsed, raw: text };
                    });
                })
                .then(function(resp){
                    if (resp && resp.body && resp.body.success) {
                        loadProductionHours(date);
                        if (window._myCalendar && typeof window._myCalendar.refetchEvents === 'function') window._myCalendar.refetchEvents();
                        addModalButtons(date);
                        try {
                            var logModalInst = bootstrap.Modal.getOrCreateInstance(document.getElementById('calendarLogHoursModal'));
                            logModalInst.hide();
                        } catch (err) {}
                        if (calFormEl) calFormEl.reset();
                        if (pageColEl) pageColEl.style.display = 'none';
                        if (envColEl) envColEl.style.display = 'none';
                        notifyCalendar('Hours logged successfully.', 'success');
                    } else {
                        var msg = 'Failed to log hours.';
                        if (resp && resp.body && (resp.body.error || resp.body.message)) {
                            msg += ' ' + (resp.body.error || resp.body.message);
                        } else if (resp && resp.raw) {
                            msg += ' Response: ' + resp.raw.substring(0, 200);
                        }
                        notifyCalendar(msg, 'danger');
                    }
                })
                .catch(function(err){
                    notifyCalendar('Error logging hours: ' + err.message, 'danger');
                })
                .finally(function(){
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        delete submitBtn.dataset.logging;
                        submitBtn.textContent = oldLabel || 'Log Hours';
                    }
                });
        } catch (err) {
            var submitBtnOnError = document.getElementById('logTimeBtn');
            if (submitBtnOnError) {
                submitBtnOnError.disabled = false;
                delete submitBtnOnError.dataset.logging;
                submitBtnOnError.textContent = 'Log Hours';
            }
            notifyCalendar('Log form error: ' + err.message, 'danger');
        }
        return false;
    }

    window.submitCalendarLogHours = submitCalendarLogHours;
    var globalCalForm = document.getElementById('logProductionHoursForm');
    if (globalCalForm && !globalCalForm.dataset.boundSubmit) {
        globalCalForm.addEventListener('submit', submitCalendarLogHours);
        globalCalForm.dataset.boundSubmit = '1';
    }
    var openLogBtn = document.getElementById('openLogHoursModalBtn');
    if (openLogBtn && !openLogBtn.dataset.boundClick) {
        openLogBtn.addEventListener('click', function() {
            try {
                var d = '';
                var dEl = document.getElementById('calDate');
                if (dEl && dEl.value) d = String(dEl.value).slice(0, 10);
                if (!d) {
                    d = String((document.getElementById('calendarEditModal').dataset.activeDate || lastClickedDate || '')).slice(0, 10);
                }
                if (dEl && d) dEl.value = d;
            } catch (err) {}
        });
        openLogBtn.dataset.boundClick = '1';
    }

    // Handle admin user selection change
    var adminUserSelect = document.getElementById('admin_user_select');
    if (adminUserSelect) {
        adminUserSelect.addEventListener('change', function() {
            calendar.refetchEvents();
        });
    }

    // Handle edit request filter change
    var editRequestFilter = document.getElementById('edit_request_filter');
    if (editRequestFilter) {
        editRequestFilter.addEventListener('change', function() {
            calendar.refetchEvents();
        });
    }

    document.querySelectorAll('.status-filter-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            calendar.refetchEvents();
        });
    });

    // Handle modal form submission
    document.getElementById('calendarEditForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        formData.append('update_status', '1');
        formData.set('notes', document.getElementById('calNotes').value);
        formData.set('personal_note', document.getElementById('calPersonalNote').value);

        fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                calendar.refetchEvents();
                var modal = bootstrap.Modal.getInstance(document.getElementById('calendarEditModal'));
                if (modal) modal.hide();
                showToast('Status updated successfully!', 'success');
            } else {
                showToast('Failed to update status: ' + (data.error || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            showToast('Request failed. Please try again.', 'danger');
        });
    });

    // Handle edit request form submission
    document.getElementById('editRequestForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        var date = document.getElementById('requestDate').value;
        var reason = document.getElementById('editReason').value;
        
        if (!reason.trim()) {
            showToast('Please provide a reason for the edit request.', 'warning');
            return;
        }
        
        sendEditRequestWithReason(date, reason);
    });

    // Initialize modal event handlers
    document.getElementById('calendarEditModal').addEventListener('shown.bs.modal', function() {
        var dateFromField = '';
        try { dateFromField = document.getElementById('calDate').value || ''; } catch (e) {}
        var dateFromDataset = '';
        try { dateFromDataset = document.getElementById('calendarEditModal').dataset.activeDate || ''; } catch (e) {}
        var activeDate = dateFromField || dateFromDataset || lastClickedDate || '';
        if (!activeDate) {
            var t = new Date();
            activeDate = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
            try { document.getElementById('calDate').value = activeDate; } catch (e) {}
        }
        if (activeDate) {
            addModalButtons(activeDate);
        }

        // Initialize calendar modal production-hours quick form bindings
        (function(){
            var projSel = document.querySelector('#logProductionHoursForm select[name="project_id"]') || document.getElementById('productionProjectSelect');
            var pageSel = document.getElementById('productionPageSelect');
            var envSel = document.getElementById('productionEnvSelect');
            var taskSel = document.getElementById('taskTypeSelect');
            var pageCol = document.getElementById('pageTestingContainer');
            var envCol = document.getElementById('productionEnvCol');
            if (!projSel) return;
            if (!projSel.dataset.boundChange) {
                projSel.addEventListener('change', function(){
                    var pid = projSel.value;
                    if (!pageSel) return;
                    pageSel.innerHTML = '<option>Loading pages...</option>';
                    fetch('<?php echo $baseDir; ?>/api/tasks.php?project_id=' + encodeURIComponent(pid), {credentials:'same-origin'})
                        .then(r => r.json()).then(function(pages){
                            pageSel.innerHTML = '';
                            pageSel.appendChild(new Option('(none)',''));
                            if (Array.isArray(pages)) pages.forEach(function(pg){ pageSel.appendChild(new Option(pg.page_name||pg.title||('Page '+pg.id), pg.id)); });
                        }).catch(function(){ pageSel.innerHTML = '<option value="">Error loading pages</option>'; });
                });
                projSel.dataset.boundChange = '1';
            }

            if (pageSel && !pageSel.dataset.boundChange) {
                pageSel.addEventListener('change', function(){
                    if (!envSel) return;
                    var val = pageSel.value;
                    var pid = Array.isArray(val) ? (val[0] || '') : (val || '');
                    envSel.innerHTML = '<option>Loading envs...</option>';
                    if (!pid) { envSel.innerHTML = '<option value="">Select page first</option>'; return; }
                    fetch('<?php echo $baseDir; ?>/api/tasks.php?page_id=' + encodeURIComponent(pid), {credentials:'same-origin'})
                        .then(r => r.json()).then(function(page){
                            envSel.innerHTML = '';
                            if (page && page.environments && page.environments.length) {
                                page.environments.forEach(function(env){ envSel.appendChild(new Option(env.name||env.environment_name||('Env '+(env.id||env.environment_id)), env.id||env.environment_id)); });
                            } else envSel.appendChild(new Option('No environments',''));
                        }).catch(function(){ envSel.innerHTML = '<option value="">Error loading environments</option>'; });
                });
                pageSel.dataset.boundChange = '1';
            }

            if (taskSel && !taskSel.dataset.boundChange) {
                taskSel.addEventListener('change', function(){
                    var t = taskSel.value;
                    if (t === 'page_testing' || t === 'page_qa') { pageCol.style.display='block'; envCol.style.display='block'; }
                    else { pageCol.style.display='none'; envCol.style.display='none'; }
                });
                taskSel.dataset.boundChange = '1';
            }

            var calForm = document.getElementById('logProductionHoursForm');
            if (calForm && !calForm.dataset.boundSubmit) {
                calForm.addEventListener('submit', submitCalendarLogHours);
                calForm.dataset.boundSubmit = '1';
            }
        })();
    });

    document.getElementById('calendarEditModal').addEventListener('hidden.bs.modal', function() {
        try {
            var logModalEl = document.getElementById('calendarLogHoursModal');
            var logModalInst = bootstrap.Modal.getInstance(logModalEl);
            if (logModalInst) logModalInst.hide();
        } catch (e) {}
        resetModal();
    });

    // Move the log form into dedicated log-hours modal body
    (function moveLogFormToDedicatedModal() {
        try {
            var formContainer = document.getElementById('calendarModalLogFormContainer');
            var targetBody = document.getElementById('calendarLogHoursModalBody');
            if (formContainer && targetBody && formContainer.parentElement !== targetBody) {
                formContainer.classList.remove('d-none');
                targetBody.appendChild(formContainer);
            }
        } catch (e) {}
    })();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
