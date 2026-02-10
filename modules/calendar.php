<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$baseDir = getBaseDir();

$userId = $_SESSION['user_id'];
$pageTitle = 'My Availability Calendar';

// Get assigned projects for the current user (for quick production hours logging)
$projectsStmt = $db->prepare("
    SELECT p.id, p.title, p.po_number
    FROM projects p
    LEFT JOIN user_assignments ua ON p.id = ua.project_id AND ua.user_id = ?
    WHERE p.status = 'in_progress' AND (ua.id IS NOT NULL OR p.project_lead_id = ? OR p.po_number = 'OFF-PROD-001')
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

        $color = '#28a745'; // Available
        $title = ucfirst($row['status']);
        switch ($row['status']) {
            case 'working':
                $color = '#17a2b8'; // Info blue
                break;
            case 'on_leave':
            case 'sick_leave':
                $color = '#dc3545';
                break;
            case 'busy':
                $color = '#ffc107';
                break;
        }

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
                        <div class="col-sm-6">
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #28a745;">&nbsp;&nbsp;&nbsp;</span>
                                Available
                            </small>
                            <small class="d-flex align-items-center mb-1">
                        <!-- Removed inline calendar quick-form; logging moved into calendar edit modal -->
                                <span class="badge me-2" style="background-color: #17a2b8;">&nbsp;&nbsp;&nbsp;</span>
                                Working
                            </small>
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #ffc107;">&nbsp;&nbsp;&nbsp;</span>
                                Busy / In Meeting
                            </small>
                        </div>
                        <div class="col-sm-6">
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #dc3545;">&nbsp;&nbsp;&nbsp;</span>
                                On Leave / Sick
                            </small>
                            <small class="d-flex align-items-center mb-1">
                                <span class="badge me-2" style="background-color: #6c757d;">&nbsp;&nbsp;&nbsp;</span>
                                Not Updated
                            </small>
                        </div>
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
    <div class="modal-dialog modal-lg">
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
                                    <option value="not_updated">Not Updated</option>
                                    <option value="available">Available</option>
                                    <option value="working">Working</option>
                                    <option value="busy">Busy / In Meeting</option>
                                    <option value="on_leave">On Leave</option>
                                    <option value="sick_leave">Sick Leave</option>
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
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-clock"></i> Production Hours <span id="hoursDate"></span></h6>
                                </div>
                                <div class="card-body">
                                    <div class="text-center mb-3">
                                        <h4 id="totalHours">0.00 hrs</h4>
                                        <div class="progress mb-2">
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
                                    
                                    <div id="hoursEntries" style="max-height: 200px; overflow-y: auto;">
                                        <p class="text-muted text-center">Loading...</p>
                                    </div>
                                    
                                    <!-- Production hours quick-form (appears inside modal when logging for a date) -->
                                    <div id="calendarModalLogFormContainer" class="mt-3">
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
                                                <button type="submit" id="logTimeBtn" class="btn btn-primary">Log Hours</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <!-- Dynamic buttons will be added here -->
                </div>
            </form>
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
                    <button type="submit" class="btn btn-warning">Send Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var canEditFuture = <?php echo $canEditFuture ? 'true' : 'false'; ?>;
    var assignedProjects = <?php echo json_encode($assignedProjects, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?> || [];
    var calendarEl = document.getElementById('calendar');
    var lastClickedDate = null;
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
        document.getElementById('calStatus').value = 'not_updated';
        document.getElementById('calNotes').value = '';
        document.getElementById('calPersonalNote').value = '';
        
        document.getElementById('editRequestStatus').style.display = 'none';
        
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
        var modalFooter = document.querySelector('#calendarEditModal .modal-footer');
        if (!modalFooter) return;
        
        var cancelBtn = modalFooter.querySelector('button[data-bs-dismiss="modal"]');
        if (!cancelBtn) return;
        
        var dt = new Date(date + 'T00:00:00');
        var today = new Date(); today.setHours(0,0,0,0);

        if (isEditableDate(date) || dt.getTime() > today.getTime()) {
            // Today / previous business day OR future dates -> allow saving availability changes
            enableEditing();
            var saveBtn = document.createElement('button');
            saveBtn.type = 'submit';
            saveBtn.className = 'btn btn-success dynamic-btn';
            saveBtn.textContent = 'Save Changes';
            modalFooter.insertBefore(saveBtn, cancelBtn);

        } else {
            // Past dates - check approval status
            checkEditRequestStatus(date, function(pending, approved) {
                if (approved) {
                    // Approved - can edit
                    var editBtn = document.createElement('button');
                    editBtn.type = 'button';
                    editBtn.className = 'btn btn-success dynamic-btn';
                    editBtn.textContent = 'Edit (Approved)';
                    editBtn.onclick = function() {
                        enableEditing();
                        editBtn.style.display = 'none';
                        var saveBtn = document.createElement('button');
                        saveBtn.type = 'submit';
                        saveBtn.className = 'btn btn-success dynamic-btn';
                        saveBtn.textContent = 'Save Changes';
                        modalFooter.insertBefore(saveBtn, cancelBtn);
                    };
                    modalFooter.insertBefore(editBtn, cancelBtn);
                    
                } else if (pending) {
                    // Pending - can edit pending changes
                    var editBtn = document.createElement('button');
                    editBtn.type = 'button';
                    editBtn.className = 'btn btn-warning dynamic-btn';
                    editBtn.textContent = 'Edit Pending Changes';
                    editBtn.onclick = function() {
                        enableEditingForPendingRequest(date);
                    };
                    modalFooter.insertBefore(editBtn, cancelBtn);
                    
                } else {
                    // No request - show request button
                    var requestBtn = document.createElement('button');
                    requestBtn.type = 'button';
                    requestBtn.className = 'btn btn-warning dynamic-btn';
                    requestBtn.textContent = 'Request Edit';
                    requestBtn.onclick = function() {
                        openEditRequestModal(date);
                    };
                    modalFooter.insertBefore(requestBtn, cancelBtn);
                }
            });
        }
    }

    function checkEditRequestStatus(date, callback) {
        fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php?action=check_edit_request&date=' + encodeURIComponent(date))
            .then(response => response.json())
            .then(data => {
                callback(data.pending || false, data.approved || false);
            })
            .catch(() => {
                callback(false, false);
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
                    if (data.is_pending) {
                        modalTitle.textContent = 'Update My Availability (Pending Changes)';
                        modalTitle.className = 'modal-title text-warning';
                    } else {
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
        lastClickedDate = date;
        
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
                var modalFooter = document.querySelector('#calendarEditModal .modal-footer');

                // Clean up any previous dynamic buttons
                if (modalFooter) {
                    var oldBtns = modalFooter.querySelectorAll('.dynamic-btn, .request-edit-btn');
                    oldBtns.forEach(function(b){ b.remove(); });
                }

                // Future dates: hide production form (only availability should be editable here)
                if (dt.getTime() > today.getTime()) {
                    if (formContainer) formContainer.style.display = 'none';
                    // availability fields editable
                    try { document.getElementById('calStatus').disabled = false; document.getElementById('calNotes').readOnly = false; document.getElementById('calPersonalNote').readOnly = false; } catch(e) {}
                    return;
                }

                // Today or previous business day: allow direct edits and show form
                if (isEditableDate(date)) {
                    if (formContainer) formContainer.style.display = '';
                    enableEditing();
                    return;
                }

                // Older past dates: show production form but keep it read-only; Request Edit button provided by addModalButtons
                if (formContainer) formContainer.style.display = '';
                disableEditing();
            } catch (e) {}
        })();
    }

    function openEditRequestModal(date) {
        document.getElementById('requestDate').value = date;
        document.getElementById('editReason').value = '';
        var modal = new bootstrap.Modal(document.getElementById('editRequestModal'));
        modal.show();
    }

    function sendEditRequestWithReason(date, reason) {
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
                var reasonModal = bootstrap.Modal.getInstance(document.getElementById('editRequestModal'));
                if (reasonModal) reasonModal.hide();
                
                enableEditingForPendingRequest(date);
                showToast('Edit request sent successfully! You can now make changes that will be saved as pending.', 'success');
            } else {
                showToast('Failed to send edit request: ' + (data.error || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            showToast('Failed to send edit request. Please try again.', 'danger');
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
            saveBtn.textContent = 'Save as Pending';
            saveBtn.onclick = function() {
                savePendingChanges(date);
            };
            modalFooter.insertBefore(saveBtn, cancelBtn);
        }
        
        enableEditing();
        
        var modalTitle = document.querySelector('#calendarEditModal .modal-title');
        if (modalTitle) {
            modalTitle.textContent = 'Update My Availability (Pending Approval)';
            modalTitle.className = 'modal-title text-warning';
        }
    }

    function savePendingChanges(date) {
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
                var modal = bootstrap.Modal.getInstance(document.getElementById('calendarEditModal'));
                if (modal) modal.hide();
                showToast('Changes saved as pending! They will be applied once admin approves.', 'success');
            } else {
                showToast('Failed to save pending changes: ' + (data.error || 'Unknown error'), 'danger');
            }
        })
            .catch(error => {
            showToast('Request failed. Please try again.', 'danger');
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[s];
        });
    }

    function getEventsUrl() {
        var sel = document.getElementById('admin_user_select');
        var user = sel ? sel.value : '';
        var editFilter = document.getElementById('edit_request_filter');
        var editRequestFilter = editFilter ? editFilter.value : '';
        
        var url = '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/calendar.php?action=get_events';
        if (user) url += '&user_id=' + encodeURIComponent(user);
        if (editRequestFilter) url += '&edit_request_filter=' + encodeURIComponent(editRequestFilter);
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
        if (lastClickedDate) {
            addModalButtons(lastClickedDate);
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
            projSel.addEventListener('change', function(){
                var pid = projSel.value;
                pageSel.innerHTML = '<option>Loading pages...</option>';
                fetch('<?php echo $baseDir; ?>/api/tasks.php?project_id=' + encodeURIComponent(pid), {credentials:'same-origin'})
                    .then(r => r.json()).then(function(pages){
                        pageSel.innerHTML = '';
                        pageSel.appendChild(new Option('(none)',''));
                        if (Array.isArray(pages)) pages.forEach(function(pg){ pageSel.appendChild(new Option(pg.page_name||pg.title||('Page '+pg.id), pg.id)); });
                    }).catch(function(){ pageSel.innerHTML = '<option value="">Error loading pages</option>'; });
            });

            if (pageSel) pageSel.addEventListener('change', function(){
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

            if (taskSel) taskSel.addEventListener('change', function(){
                var t = taskSel.value;
                if (t === 'page_testing' || t === 'page_qa') { pageCol.style.display='block'; envCol.style.display='block'; }
                else { pageCol.style.display='none'; envCol.style.display='none'; }
            });

            var calForm = document.getElementById('logProductionHoursForm');
            if (calForm) {
                calForm.addEventListener('submit', function(e){
                    e.preventDefault();
                    var date = document.getElementById('calDate').value;
                        // No client-side block: allow logging for future dates here; server will validate if needed.
                    var fd = new FormData();
                    fd.append('action','log');
                    fd.append('user_id','<?php echo $userId; ?>');
                    fd.append('project_id', document.querySelector('#logProductionHoursForm select[name="project_id"]').value);
                    fd.append('task_type', document.getElementById('taskTypeSelect').value || '');
                    // append first selected page if any
                    var pages = Array.from(document.getElementById('productionPageSelect').selectedOptions).map(o=>o.value).filter(Boolean);
                    if (pages.length) fd.append('page_id', pages[0]);
                    var envs = Array.from(document.getElementById('productionEnvSelect').selectedOptions).map(o=>o.value).filter(Boolean);
                    if (envs.length) fd.append('environment_id', envs[0]);
                    fd.append('testing_type', document.getElementById('testingTypeSelect') ? document.getElementById('testingTypeSelect').value : '');
                    fd.append('log_date', date);
                    fd.append('hours', document.getElementById('logHoursInput').value);
                    fd.append('description', document.getElementById('logDescriptionInput').value || '');
                    fd.append('is_utilized', 1);
                    fetch('<?php echo $baseDir; ?>/api/project_hours.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(r => r.json()).then(function(resp){
                            if (resp && resp.success) {
                                loadProductionHours(date);
                                if (window._myCalendar && typeof window._myCalendar.refetchEvents === 'function') window._myCalendar.refetchEvents();
                            } else {
                                showToast('Failed to log hours: ' + (resp && resp.message ? resp.message : JSON.stringify(resp)), 'danger');
                            }
                        }).catch(function(err){ showToast('Error logging hours: ' + err.message, 'danger'); });
                });
            }
        })();
    });

    document.getElementById('calendarEditModal').addEventListener('hidden.bs.modal', function() {
        resetModal();
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>