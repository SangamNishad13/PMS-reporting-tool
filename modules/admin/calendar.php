<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');
$db = Database::getInstance();
$baseDir = getBaseDir();
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
    $availabilityStatusMap['not_updated'] = [
        'status_label' => 'Not Updated',
        'badge_color' => 'secondary'
    ];
}
$availabilityFilterOptions = $availabilityStatusMap;
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

$pageTitle = 'Team Availability';
// Selected user filter for admin view (optional)
$selectedUser = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? intval($_GET['user_id']) : null;

// Handle AJAX request for events
if (isset($_GET['action']) && $_GET['action'] === 'get_events') {
    $start = $_GET['start'] ?? date('Y-m-d');
    $end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));
    $selectedUser = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? $_GET['user_id'] : null;
    
    // Get status filters as array (from checkboxes)
    $statusFilters = isset($_GET['status_filter']) ? explode(',', $_GET['status_filter']) : ['all'];
    $statusFilters = array_values(array_filter(array_map(static function ($v) {
        return strtolower(trim((string)$v));
    }, $statusFilters)));
    if (in_array('all', $statusFilters) || empty($statusFilters)) {
        $statusFilters = ['all'];
    }
    $statusFilterAllows = static function ($statusKey) use ($statusFilters) {
        $statusKey = strtolower(trim((string)$statusKey));
        if (in_array('all', $statusFilters, true)) return true;
        if (in_array($statusKey, $statusFilters, true)) return true;
        if (($statusKey === 'on_leave' || $statusKey === 'sick_leave') && in_array('leave', $statusFilters, true)) return true;
        return false;
    };
    $statusColor = static function ($statusKey) use ($availabilityStatusMap, $badgeToHex) {
        $statusKey = strtolower(trim((string)$statusKey));
        $badge = strtolower((string)($availabilityStatusMap[$statusKey]['badge_color'] ?? 'secondary'));
        return $badgeToHex[$badge] ?? '#6c757d';
    };
    $statusLabelFn = static function ($statusKey) use ($availabilityStatusMap) {
        $statusKey = strtolower(trim((string)$statusKey));
        return (string)($availabilityStatusMap[$statusKey]['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey)));
    };

    $events = [];

    // Fetch explicit statuses in range and index them by user+date (exclude admin and super_admin)
    $sql = "SELECT uds.*, u.full_name, u.role
         FROM user_daily_status uds
         JOIN users u ON uds.user_id = u.id
         WHERE uds.status_date BETWEEN ? AND ?
         AND u.role NOT IN ('admin', 'super_admin')";
    $params = [$start, $end];
    if ($selectedUser && $selectedUser !== 'all') {
        $sql .= " AND u.id = ?";
        $params[] = $selectedUser;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $status_map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status_map[$row['user_id']][$row['status_date']] = $row;
    }

    // Fetch summed hours per user per date in the range
    $hours_map = [];
    $hoursSql = "SELECT ptl.user_id, ptl.log_date, SUM(ptl.hours_spent) as total_hours
                 FROM project_time_logs ptl
                 JOIN users u ON ptl.user_id = u.id
                 WHERE ptl.log_date BETWEEN ? AND ?
                 AND u.role NOT IN ('admin','super_admin')";
    $hparams = [$start, $end];
    if ($selectedUser && $selectedUser !== 'all') {
        $hoursSql .= " AND ptl.user_id = ?";
        $hparams[] = $selectedUser;
    }
    $hoursSql .= " GROUP BY ptl.user_id, ptl.log_date";
    $hstmt = $db->prepare($hoursSql);
    $hstmt->execute($hparams);
    while ($hr = $hstmt->fetch(PDO::FETCH_ASSOC)) {
        $hours_map[$hr['user_id']][$hr['log_date']] = floatval($hr['total_hours']);
    }

    // Fetch users to show "Not updated" where no status exists (exclude admin and super_admin)
    if ($selectedUser && $selectedUser !== 'all') {
        $users = $db->prepare("SELECT id, full_name, role FROM users WHERE is_active = 1 AND id = ? AND role NOT IN ('admin', 'super_admin')");
        $users->execute([$selectedUser]);
        $users = $users->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $users = $db->query("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role NOT IN ('admin', 'super_admin') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Build date range
    $period = new DatePeriod(
        new DateTime($start), 
        new DateInterval('P1D'), 
        (new DateTime($end))->modify('+1 day')
    );

    // If "All Users" is selected, create consolidated events
    if ($selectedUser === 'all') {
        // Group users by date and status for consolidated view
        $consolidated_events = [];
        
        foreach ($period as $dt) {
            $d = $dt->format('Y-m-d');
            $date_users = [];
            
            // Collect all users for this date
            foreach ($users as $u) {
                $userHours = $hours_map[$u['id']][$d] ?? 0;
                $userStatus = $status_map[$u['id']][$d] ?? null;
                
                if ($userStatus) {
                    $statusType = strtolower(trim((string)($userStatus['status'] ?? 'not_updated')));
                    
                    if ($statusFilterAllows($statusType)) {
                        $date_users[$statusType][] = [
                            'name' => $u['full_name'],
                            'id' => $u['id'],
                            'hours' => $userHours,
                            'status' => $userStatus['status'],
                            'notes' => $userStatus['notes'] ?? ''
                        ];
                    }
                } else {
                    // Not updated
                    if ($statusFilterAllows('not_updated')) {
                        $date_users['not_updated'][] = [
                            'name' => $u['full_name'],
                            'id' => $u['id'],
                            'hours' => $userHours,
                            'status' => 'not_updated',
                            'notes' => ''
                        ];
                    }
                }
            }
            
            // Create consolidated events for each status type
            foreach ($date_users as $statusType => $userList) {
                if (empty($userList)) continue;
                
                $count = count($userList);
                $totalHours = array_sum(array_column($userList, 'hours'));
                
                $color = $statusColor($statusType);
                $statusLabel = $statusLabelFn($statusType);
                
                // Highlight if total hours < expected (assuming 8h per person per day)
                if ($totalHours > 0 && $totalHours < ($count * 8)) {
                    $color = '#ff4d4f';
                }
                
                $title = $statusLabel . ' (' . $count . ')';
                if ($totalHours > 0) {
                    $title .= ' — ' . $totalHours . 'h';
                }
                
                $events[] = [
                    'title' => $title,
                    'start' => $d,
                    'color' => $color,
                    'description' => '',
                    'extendedProps' => [
                        'statusType' => $statusType,
                        'userCount' => $count,
                        'totalHours' => $totalHours,
                        'userList' => $userList,
                        'consolidated' => true
                    ]
                ];
            }
        }
    } else {
        // Individual user view (existing logic)
        foreach ($users as $u) {
            foreach ($period as $dt) {
                $d = $dt->format('Y-m-d');
                
                $userHours = $hours_map[$u['id']][$d] ?? 0;
                if (empty($status_map[$u['id']][$d])) {
                    if ($statusFilterAllows('not_updated')) {
                        $title = $u['full_name'] . ' (Not updated)';
                        if ($userHours > 0) $title .= ' — ' . $userHours . 'h';
                        $color = $userHours > 0 && $userHours < 8 ? '#ff4d4f' : '#6c757d';
                        $events[] = [
                            'title' => $title,
                            'start' => $d,
                            'color' => $color,
                            'description' => '',
                            'extendedProps' => [
                                'role' => $u['role'],
                                'notes' => '',
                                'statusType' => 'not_updated',
                                'total_hours' => $userHours,
                                'user_id' => $u['id'],
                                'user_full_name' => $u['full_name']
                            ]
                        ];
                    }
                } else {
                    $st = $status_map[$u['id']][$d];
                    $stType = strtolower(trim((string)($st['status'] ?? 'not_updated')));
                    if (!$statusFilterAllows($stType)) {
                        continue;
                    }
                    $title = $st['full_name'] . ' (' . $statusLabelFn($stType) . ')';
                    $userHours = $hours_map[$u['id']][$d] ?? 0;
                    if ($userHours > 0) $title .= ' — ' . $userHours . 'h';
                    $color = $statusColor($stType);
                    if ($userHours > 0 && $userHours < 8) $color = '#ff4d4f';
                    $events[] = [
                        'title' => $title,
                        'start' => $d,
                        'color' => $color,
                        'description' => $st['notes'] ?? '',
                        'extendedProps' => [
                            'role' => $st['role'],
                            'notes' => $st['notes'] ?? '',
                            'statusType' => $stType,
                            'total_hours' => $userHours,
                            'user_id' => $st['user_id'] ?? $u['id'],
                            'user_full_name' => $st['full_name'] ?? $u['full_name']
                        ]
                    ];
                }
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode($events);
    exit;
}

// Handle AJAX request for edit-request events (same page endpoint for reliability)
if (isset($_GET['action']) && $_GET['action'] === 'get_edit_requests') {
    $filterUserId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
    $sql = "
        SELECT uer.id, uer.user_id, uer.req_date, uer.reason, uer.status, uer.request_type, u.full_name AS user_name
        FROM user_edit_requests uer
        JOIN users u ON uer.user_id = u.id
        WHERE 1=1
    ";
    $params = [];
    if ($filterUserId) {
        $sql .= " AND uer.user_id = ?";
        $params[] = $filterUserId;
    }
    $sql .= " ORDER BY uer.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'requests' => $requests]);
    exit;
}

include __DIR__ . '/../../includes/header.php';

// Fetch users for dropdown (exclude admin/super_admin)
$allUsers = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 AND role NOT IN ('admin', 'super_admin') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>

<style>
    /* Compact Calendar Tweaks */
    .fc-event {
        cursor: pointer;
        border: none !important;
        border-radius: 4px;
        font-size: 0.8em;
        padding: 2px 4px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .fc-daygrid-event-dot {
        border-width: 5px;
    }
    .fc-toolbar-title {
        font-size: 1.25rem !important;
        font-weight: 600;
    }
    .fc-button-primary {
        background-color: var(--primary) !important;
        border-color: var(--primary) !important;
    }
    .fc-button-primary:hover {
        background-color: var(--primary-dark) !important;
        border-color: var(--primary-dark) !important;
    }
    .fc-today-button {
        opacity: 0.8;
    }
    /* Day Detail Card inside calendar */
    .fc-day-detail {
        background: var(--bg-surface);
        border: 1px solid var(--border-subtle);
        border-radius: 6px;
        padding: 8px;
        margin-bottom: 4px;
        box-shadow: var(--shadow-sm);
    }
    .fc-day-detail-title {
        font-weight: 600;
        font-size: 0.85rem;
        margin-bottom: 4px;
        color: var(--text-main);
    }
    .badge-status {
        font-size: 0.7rem;
        padding: 2px 6px;
    }
    .btn-check + .btn::after {
        content: "";
        display: none;
        margin-left: 6px;
        font-weight: 700;
        line-height: 1;
    }
    .btn-check:checked + .btn::after {
        content: "\2713";
        display: inline-block;
    }
    .calendar-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 10px 16px;
    }
    .legend-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.85rem;
        color: #495057;
    }
    .legend-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 1px solid rgba(0, 0, 0, 0.15);
        display: inline-block;
        flex: 0 0 12px;
    }
    /* Keep Summernote fully contained inside admin edit modal columns */
    #adminEditModal .modal-body {
        overflow-x: hidden;
    }
    #adminEditModal .note-editor.note-frame {
        width: 100%;
        max-width: 100%;
    }
    #adminEditModal .note-editor .note-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
    }
    #adminEditModal .note-editor .note-toolbar > .note-btn-group {
        float: none;
        margin-right: 0;
    }
    #adminEditModal .note-editor .note-editing-area,
    #adminEditModal .note-editor .note-statusbar {
        overflow: hidden;
    }
    #adminEditModal .note-editor .note-editable {
        word-break: break-word;
    }
    /* In readonly mode hide controls to avoid toolbar overflow and keep clean view */
    #adminEditModal.admin-editor-readonly .note-toolbar,
    #adminEditModal.admin-editor-readonly .note-statusbar {
        display: none;
    }
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2>Team Availability</h2>
            <p class="text-muted mb-0">Overview of resource production hours and status.</p>
        </div>
        <div>
            <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/production_logs.php" class="btn btn-outline-primary">
                <i class="fas fa-list me-1"></i> Production Logs
            </a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="row g-3 align-items-center">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">View Scope</label>
                    <select id="userSelect" class="form-select form-select-sm">
                        <option value="">Individual Users</option>
                        <option value="all">All Users (Consolidated)</option>
                        <?php foreach ($allUsers as $au): ?>
                            <option value="<?php echo $au['id']; ?>" <?php echo ($selectedUser && $selectedUser == $au['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($au['full_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-9">
                    <label class="form-label small text-muted mb-1">Filter Status</label>
                    <div class="d-flex flex-wrap gap-2">
                        <div class="btn-group" role="group">
                            <?php foreach ($availabilityFilterOptions as $statusKey => $meta): ?>
                                <?php
                                $inputId = 'filter_' . preg_replace('/[^a-z0-9_]+/i', '_', $statusKey);
                                $badgeColor = strtolower((string)($meta['badge_color'] ?? 'secondary'));
                                $outlineClass = in_array($badgeColor, ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'], true)
                                    ? $badgeColor
                                    : 'secondary';
                                ?>
                                <input type="checkbox" class="btn-check status-filter-check" id="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8'); ?>" checked autocomplete="off">
                                <label class="btn btn-outline-<?php echo htmlspecialchars($outlineClass, ENT_QUOTES, 'UTF-8'); ?> btn-sm" for="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars((string)($meta['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))), ENT_QUOTES, 'UTF-8'); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="vr mx-1"></div>
                        
                        <div class="btn-group" role="group">
                            <input type="checkbox" class="btn-check" id="filterEditRequests" checked autocomplete="off" onchange="if(window.__adminCalendarToggleEditRequests){window.__adminCalendarToggleEditRequests();}">
                            <label class="btn btn-outline-info btn-sm" for="filterEditRequests">
                                <i class="fas fa-bell me-1"></i> Edit Requests
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="small fw-semibold text-muted mb-2">Calendar Legend</div>
            <div class="calendar-legend">
                <?php foreach ($availabilityFilterOptions as $statusKey => $meta): ?>
                    <?php
                    $badgeColor = strtolower((string)($meta['badge_color'] ?? 'secondary'));
                    $legendColor = $badgeToHex[$badgeColor] ?? '#6c757d';
                    ?>
                    <span class="legend-item"><span class="legend-dot" style="background:<?php echo htmlspecialchars($legendColor, ENT_QUOTES, 'UTF-8'); ?>"></span><?php echo htmlspecialchars((string)($meta['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endforeach; ?>
                <span class="legend-item"><span class="legend-dot" style="background:#ff4d4f"></span>Under 8h Logged</span>
                <span class="legend-item"><span class="legend-dot" style="background:#17a2b8"></span>Edit/Delete Request: Pending</span>
                <span class="legend-item"><span class="legend-dot" style="background:#28a745"></span>Edit/Delete Request: Approved</span>
                <span class="legend-item"><span class="legend-dot" style="background:#dc3545"></span>Edit/Delete Request: Rejected</span>
                <span class="legend-item"><span class="legend-dot" style="background:#343a40"></span>Edit/Delete Request: Used</span>
            </div>
        </div>
    </div>

    <!-- Calendar Container -->
    <div class="card">
        <div class="card-body p-0">
            <div id="calendar" class="p-3"></div>
        </div>
    </div>
</div>

<!-- Removed duplicate FullCalendar JS (already included at top) -->

<script>
window.availabilityStatusMeta = <?php echo json_encode($availabilityFilterOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

function availabilityStatusLabel(statusKey) {
    var key = String(statusKey || '').toLowerCase();
    var meta = (window.availabilityStatusMeta && window.availabilityStatusMeta[key]) ? window.availabilityStatusMeta[key] : null;
    return (meta && meta.status_label) ? String(meta.status_label) : key.replace(/_/g, ' ');
}

function availabilityStatusBadgeClass(statusKey, withBgPrefix) {
    var key = String(statusKey || '').toLowerCase();
    var meta = (window.availabilityStatusMeta && window.availabilityStatusMeta[key]) ? window.availabilityStatusMeta[key] : null;
    var color = (meta && meta.badge_color) ? String(meta.badge_color).toLowerCase() : 'secondary';
    var allowed = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark', 'light'];
    if (allowed.indexOf(color) === -1) color = 'secondary';
    return withBgPrefix ? ('bg-' + color) : color;
}

document.addEventListener('DOMContentLoaded', function() {
    var FC = window.FullCalendar || (typeof FullCalendar !== 'undefined' ? FullCalendar : null);
    if (!FC) {
        console.error('FullCalendar failed to load');
        return;
    }
    var statusFilterStorageKey = 'admin_calendar_status_filters_v1';

    var calendarEl = document.getElementById('calendar');
    
    // Define event sources
    var mainEventsSource = {
        id: 'mainEvents',
        url: '', // will be set dynamically
        extraParams: {}
    };
    
    // Function to get current filters for main events
    function getSelectedFilters() {
        var checkboxes = document.querySelectorAll('.status-filter-check:checked');
        var filters = Array.from(checkboxes).map(cb => cb.value);
        return filters.length > 0 ? filters.join(',') : 'none';
    }

    function getSelectedFilterSet() {
        return new Set(
            Array.from(document.querySelectorAll('.status-filter-check:checked')).map(function(cb) {
                return String(cb.value || '').toLowerCase();
            })
        );
    }

    function isStatusVisibleByFilter(statusType, selectedSet) {
        var key = String(statusType || '').toLowerCase();
        if (!selectedSet || selectedSet.size === 0) return false;
        if (selectedSet.has(key)) return true;
        if ((key === 'on_leave' || key === 'sick_leave') && selectedSet.has('leave')) return true;
        return false;
    }

    function applyStatusFilterToRenderedEvents() {
        var selectedSet = getSelectedFilterSet();
        var eventEls = calendarEl.querySelectorAll('.fc-event[data-status-type], .fc-daygrid-event[data-status-type]');
        eventEls.forEach(function(el) {
            var statusType = String(el.getAttribute('data-status-type') || '').toLowerCase();
            el.style.display = isStatusVisibleByFilter(statusType, selectedSet) ? '' : 'none';
        });
    }

    function applySavedStatusFilters() {
        try {
            var raw = localStorage.getItem(statusFilterStorageKey);
            if (!raw) return;
            var saved = JSON.parse(raw);
            if (!Array.isArray(saved)) return;
            var boxes = document.querySelectorAll('.status-filter-check');
            boxes.forEach(function(cb) {
                cb.checked = saved.indexOf(cb.value) !== -1;
            });
        } catch (e) {}
    }

    function saveStatusFilters() {
        try {
            var selected = Array.from(document.querySelectorAll('.status-filter-check:checked')).map(function(cb) {
                return cb.value;
            });
            localStorage.setItem(statusFilterStorageKey, JSON.stringify(selected));
        } catch (e) {}
    }
    
    // Helper to read current user selection
    function getSelectedUserId() {
        var select = document.getElementById('userSelect');
        return select ? select.value : '';
    }

    // Function to construct URL for main events
    function getEventsUrl() {
        var userId = getSelectedUserId();
        return '<?php echo $_SERVER["PHP_SELF"]; ?>?action=get_events' + (userId ? '&user_id=' + encodeURIComponent(userId) : '');
    }

    // Refresh only the main events source
    function refreshMainEvents() {
        var source = window.calendar.getEventSourceById('mainEvents');
        if (source) {
            source.remove();
        }
        window.calendar.addEventSource({
            id: 'mainEvents',
            url: getEventsUrl()
        });
    }

    // Edit requests source definition
    function fetchEditRequests(fetchInfo, successCallback, failureCallback) {
        var selectedUserId = getSelectedUserId();
        var editUrl = '<?php echo $_SERVER["PHP_SELF"]; ?>?action=get_edit_requests';
        if (selectedUserId && selectedUserId !== 'all') {
            editUrl += '&user_id=' + encodeURIComponent(selectedUserId);
        }

        fetch(editUrl)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.requests) {
                    var targetUserId = selectedUserId && selectedUserId !== 'all' ? parseInt(selectedUserId, 10) : null;
                    var events = data.requests
                        .filter(function(request) {
                            return !targetUserId || Number(request.user_id) === targetUserId;
                        })
                        .map(function(request) {
                            var reqStatus = (request.status || '').toLowerCase();
                            var reqType = (request.request_type || 'edit').toLowerCase();
                            var color = '#17a2b8';
                            var textColor = '#ffffff';
                            if (reqStatus === 'approved') color = '#28a745';
                            else if (reqStatus === 'rejected') color = '#dc3545';
                            else if (reqStatus === 'used') color = '#343a40';
                            else if (reqStatus === 'pending' || reqStatus === '') color = '#17a2b8';

                            var statusLabel = reqStatus ? reqStatus.charAt(0).toUpperCase() + reqStatus.slice(1) : 'Unknown';
                            var typeLabel = reqType === 'delete' ? 'Delete Request' : 'Edit Request';
                            return {
                                title: typeLabel + ' [' + statusLabel + '] - ' + request.user_name,
                                start: request.req_date,
                                color: color,
                                textColor: textColor,
                                extendedProps: {
                                    isEditRequest: true,
                                    requestId: request.id,
                                    userId: request.user_id,
                                    userName: request.user_name,
                                    reason: request.reason,
                                    requestStatus: reqStatus,
                                    requestType: reqType
                                }
                            };
                        });
                    successCallback(events);
                } else {
                    successCallback([]);
                }
            })
            .catch(error => {
                // console.error('Failed to load edit requests:', error);
                failureCallback(error);
            });
    }

    // Toggle edit requests overlay
    function isEditRequestEvent(ev) {
        if (!ev) return false;
        var props = ev.extendedProps || {};
        if (props.isEditRequest) return true;
        try {
            var src = typeof ev.getSource === 'function' ? ev.getSource() : null;
            if (src && src.id === 'editRequests') return true;
        } catch (e) {}
        var title = (typeof ev.title === 'string') ? ev.title : '';
        return title.indexOf('Edit Request') === 0 || title.indexOf('Delete Request') === 0;
    }

    function toggleEditRequestsOverlay() {
        var toggleEl = document.getElementById('filterEditRequests');
        var showEditRequests = toggleEl ? !!toggleEl.checked : false;
        var source = window.calendar.getEventSourceById('editRequests');
        var renderedEditEls = calendarEl.querySelectorAll('.fc-edit-request-event');

        renderedEditEls.forEach(function(el) {
            el.style.display = showEditRequests ? '' : 'none';
        });

        if (!showEditRequests) {
            if (source) {
                source.remove();
            }
            window.calendar.getEvents().forEach(function(ev){
                if (isEditRequestEvent(ev)) {
                    ev.remove();
                }
            });
            if (typeof window.calendar.updateSize === 'function') {
                window.calendar.updateSize();
            }
            return;
        }

        if (!source) {
            source = window.calendar.addEventSource({
                id: 'editRequests',
                events: fetchEditRequests
            });
        }
        if (source && typeof source.refetch === 'function') {
            source.refetch();
        } else if (typeof window.calendar.refetchEvents === 'function') {
            window.calendar.refetchEvents();
        }
        if (typeof window.calendar.updateSize === 'function') {
            window.calendar.updateSize();
        }
    }
    window.__adminCalendarToggleEditRequests = toggleEditRequestsOverlay;
    
    var defaultView = window.innerWidth < 768 ? 'dayGridDay' : 'dayGridMonth';

    // Use plugins exposed on the FullCalendar namespace
    var plugins = [];
    if (FC.dayGridPlugin) plugins.push(FC.dayGridPlugin);
    // list view removed; no list plugin
    if (FC.interactionPlugin) plugins.push(FC.interactionPlugin);

    window.calendar = new FC.Calendar(calendarEl, {
        plugins: plugins,
        initialView: defaultView,
        dayMaxEventRows: false,
        views: {
            dayGridMonth: { dayMaxEventRows: 2 },
            dayGridDay: { dayMaxEventRows: false, dayHeaderFormat: { weekday: 'short', day: 'numeric', month: 'short' } }
        },
        moreLinkClick: 'popover',
        moreLinkText: 'more',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,dayGridDay'
        },
        eventDisplay: 'block',
        eventContent: function(arg) {
            // Show richer cards in day view where there is ample space
            if (!arg || !arg.view || arg.view.type !== 'dayGridDay') return;

            var props = arg.event.extendedProps || {};
            var card = document.createElement('div');
            card.className = 'fc-day-detail';

            var head = document.createElement('div');
            head.className = 'd-flex justify-content-between align-items-start mb-1';

            var title = document.createElement('div');
            title.className = 'fc-day-detail-title me-2';
            title.textContent = props.user_full_name || arg.event.title;
            head.appendChild(title);

            var badges = document.createElement('div');
            badges.className = 'd-flex gap-1 flex-wrap justify-content-end';

            var statusRaw = props.statusType || props.status || '';
            var statusLabel = statusRaw ? availabilityStatusLabel(statusRaw) : 'Status';
            
            if (statusRaw) {
                var badgeClass = availabilityStatusBadgeClass(statusRaw, true);

                var statusBadge = document.createElement('span');
                statusBadge.className = 'badge ' + badgeClass + ' badge-status';
                statusBadge.textContent = statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1);
                badges.appendChild(statusBadge);
            }

            if (props.total_hours !== undefined && props.total_hours !== null) {
                var hoursBadge = document.createElement('span');
                hoursBadge.className = 'badge bg-light text-dark border badge-status';
                hoursBadge.textContent = parseFloat(props.total_hours).toFixed(2) + ' h';
                badges.appendChild(hoursBadge);
            }

            head.appendChild(badges);
            card.appendChild(head);

            if (props.notes) {
                var notes = document.createElement('p');
                notes.className = 'small text-muted mb-0';
                notes.textContent = props.notes.replace(/<[^>]*>?/gm, ''); // strip any HTML
                card.appendChild(notes);
            }

            return { domNodes: [card] };
        },
        // Remove initial events: getEventsUrl(), we will add it manually
        eventDidMount: function(info) {
            try {
                var el = info.el || (info.elms && info.elms[0]);
                if (el && info.event && info.event.extendedProps) {
                    var props = info.event.extendedProps || {};
                    if (isEditRequestEvent(info.event)) {
                        el.classList.add('fc-edit-request-event');
                        var toggleEl = document.getElementById('filterEditRequests');
                        if (toggleEl && !toggleEl.checked) {
                            el.style.display = 'none';
                        }
                    }
                    if (info.event.extendedProps.user_id) el.setAttribute('data-user-id', info.event.extendedProps.user_id);
                    if (info.event.extendedProps.user_full_name) el.setAttribute('data-user-fullname', info.event.extendedProps.user_full_name);
                    if (info.event.extendedProps.role) el.setAttribute('data-user-role', info.event.extendedProps.role);
                    if (info.event.extendedProps.statusType) el.setAttribute('data-status-type', info.event.extendedProps.statusType);
                    if (typeof info.event.startStr !== 'undefined') el.setAttribute('data-date', info.event.startStr);
                    if (props.statusType) {
                        var selectedSet = getSelectedFilterSet();
                        if (!isStatusVisibleByFilter(props.statusType, selectedSet)) {
                            el.style.display = 'none';
                        }
                    }
                }
            } catch (e) { /* ignore */ }
        },
        eventsSet: function() {
            applyStatusFilterToRenderedEvents();
        },
        eventClick: function(info) {
            // Handle Edit Request Click
            if (info.event.extendedProps.isEditRequest) {
                showEditRequestModal({
                    userName: info.event.extendedProps.userName,
                    reason: info.event.extendedProps.reason,
                    date: info.event.startStr
                });
                return false;
            }

            var uid = info.event.extendedProps.user_id || info.event.extendedProps.userId;
            var date = info.event.startStr;
            var role = info.event.extendedProps.role || '';
            
            // Handle consolidated view
            if (info.event.extendedProps.consolidated) {
                showConsolidatedModal(info.event.extendedProps.userList, date, info.event.extendedProps.statusType);
                return false;
            }
            
            if (uid) {
                try { info.jsEvent && info.jsEvent.preventDefault(); info.jsEvent && info.jsEvent.stopPropagation(); } catch(e){}
                fetchUserHoursAndShow(uid, date, role);
                return false;
            }
            
            // fallback: show simple toast
            var notes = info.event.extendedProps.notes;
            var msg = 'User: ' + info.event.title + '\nRole: ' + info.event.extendedProps.role;
            if (notes) msg += '\nNotes: ' + notes;
            showToast(msg, 'info');
        },
        height: 'auto',
        contentHeight: 600,
        handleWindowResize: true,
        windowResizeDelay: 100
    });
    
    // Wait for fonts to load before rendering to ensure correct sizing
    document.fonts.ready.then(function() {
        // Small delay to ensure container is fully painted
        setTimeout(function() {
            window.calendar.render();
            // Add initial event sources
            refreshMainEvents();
            toggleEditRequestsOverlay();
            
            // Force one more update after a short delay to catch any layout shifts
            setTimeout(function() {
                window.calendar.updateSize();
            }, 300);
        }, 100);
    });
    
    // Handle checkbox filter changes
    document.querySelectorAll('.status-filter-check').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            saveStatusFilters();
            applyStatusFilterToRenderedEvents();
        });
    });
    
    // Handle edit requests filter (explicit controlled toggle)
    var editRequestsToggle = document.getElementById('filterEditRequests');
    if (editRequestsToggle) {
        editRequestsToggle.addEventListener('change', toggleEditRequestsOverlay);
        editRequestsToggle.addEventListener('click', function() {
            setTimeout(toggleEditRequestsOverlay, 0);
        });
    }
    
    // Handle user selection change
    var userSelect = document.getElementById('userSelect');
    if (userSelect) {
        userSelect.addEventListener('change', function() {
            refreshMainEvents();

            var editSource = window.calendar.getEventSourceById('editRequests');
            var showEditRequests = document.getElementById('filterEditRequests').checked;

            if (showEditRequests && editSource && typeof editSource.refetch === 'function') {
                editSource.refetch();
            } else if (showEditRequests) {
                toggleEditRequestsOverlay();
            } else if (editSource) {
                editSource.remove();
            }
        });
    }

    // Restore persisted filters after handlers are attached.
    applySavedStatusFilters();
    applyStatusFilterToRenderedEvents();
});
</script>

<!-- Summernote (AdminLTE editor) -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>

<!-- Admin Edit Modal (Same structure as user calendar) -->
<div class="modal fade" id="adminEditModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form id="adminCalendarEditForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Left Column - Status Form -->
                        <div class="col-md-6">
                            <input type="hidden" id="a_user_id" name="user_id" value="">
                            <input type="hidden" id="a_date" name="date" value="">
                            <div class="mb-3">
                                <label for="a_status" class="form-label">Availability Status</label>
                                <select id="a_status" name="status" class="form-select">
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
                                <label for="a_notes" class="form-label">Notes (Visible to team)</label>
                                <textarea id="a_notes" name="notes" class="form-control" rows="4" placeholder="Add notes for this date..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="a_personal_note" class="form-label">Personal Note <small class="text-muted">(Visible only to user)</small></label>
                                <textarea id="a_personal_note" name="personal_note" class="form-control" rows="4" placeholder="Personal note or todo for this date..."></textarea>
                            </div>
                        </div>
                        
                        <!-- Right Column - Production Hours -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="fas fa-clock"></i> Production Hours
                                        <span id="adminHoursDate" class="text-muted ms-2"></span>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div id="adminHoursHeader" class="mb-3 text-center">
                                        <h4 id="adminTotalHours" class="text-primary">0.00 hrs</h4>
                                        <div class="progress mb-2">
                                            <div id="adminUtilizedProgress" class="progress-bar bg-success" role="progressbar" style="width: 0%">
                                                Utilized
                                            </div>
                                            <div id="adminBenchProgress" class="progress-bar bg-secondary" role="progressbar" style="width: 100%">
                                                Bench/Off
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            Utilized: <span id="adminUtilizedHours">0.00</span>h | 
                                            Off-Prod: <span id="adminBenchHours">0.00</span>h
                                        </small>
                                    </div>
                                    <div id="adminHoursEntries">
                                        <p class="text-muted text-center">Loading...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="adminEditBtn" class="btn btn-primary">Edit</button>
                    <!-- Dynamic save button will be added here -->
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function enableAdminToolbarKeyboardA11y($el) {
    if (!window.jQuery || !$el || !$el.length) return;
    var $toolbar = $el.next('.note-editor').find('.note-toolbar').first();
    if (!$toolbar.length || $toolbar.data('kbdA11yBound')) return;

    function getItems() {
        return $toolbar.find('.note-btn-group button').filter(function() {
            var $b = jQuery(this);
            if ($b.is(':hidden')) return false;
            if ($b.prop('disabled')) return false;
            if ($b.closest('.dropdown-menu').length) return false;
            if ($b.attr('aria-hidden') === 'true') return false;
            return true;
        });
    }

    function setActiveIndex(idx) {
        var $items = getItems();
        if (!$items.length) return;
        var next = Math.max(0, Math.min(idx, $items.length - 1));
        $items.attr('tabindex', '-1');
        $items.eq(next).attr('tabindex', '0');
        $toolbar.data('kbdIndex', next);
    }

    function ensureOneTabStop() {
        var $items = getItems();
        if (!$items.length) return;
        if (!$items.filter('[tabindex="0"]').length) {
            $items.attr('tabindex', '-1');
            $items.eq(0).attr('tabindex', '0');
        }
    }

    function handleToolbarArrowNav(e) {
        var key = e.key || (e.originalEvent && e.originalEvent.key);
        if (key !== 'ArrowRight' && key !== 'ArrowLeft' && key !== 'Home' && key !== 'End') return;

        var $items = getItems();
        if (!$items.length) return;
        var activeEl = document.activeElement;
        var idx = $items.index(activeEl);
        if (idx < 0 && activeEl && activeEl.closest) {
            var parentBtn = activeEl.closest('button');
            if (parentBtn) idx = $items.index(parentBtn);
        }
        if (idx < 0) {
            var savedIdx = parseInt($toolbar.data('kbdIndex'), 10);
            if (!isNaN(savedIdx) && savedIdx >= 0 && savedIdx < $items.length) idx = savedIdx;
        }
        if (idx < 0) idx = $items.index($items.filter('[tabindex="0"]').first());
        if (idx < 0) idx = 0;

        e.preventDefault();
        if (e.stopPropagation) e.stopPropagation();
        if (key === 'Home') idx = 0;
        else if (key === 'End') idx = $items.length - 1;
        else if (key === 'ArrowRight') idx = (idx + 1) % $items.length;
        else if (key === 'ArrowLeft') idx = (idx - 1 + $items.length) % $items.length;

        setActiveIndex(idx);
        var $target = $items.eq(idx);
        $target.focus();
        if (document.activeElement !== $target.get(0)) {
            setTimeout(function() { $target.focus(); }, 0);
        }
    }

    $toolbar.attr('role', 'toolbar');
    if (!$toolbar.attr('aria-label')) {
        $toolbar.attr('aria-label', 'Editor toolbar');
    }

    setActiveIndex(0);
    $toolbar.on('focusin', 'button', function() {
        var $items = getItems();
        var idx = $items.index(this);
        if (idx >= 0) setActiveIndex(idx);
    });
    $toolbar.on('click', 'button', function() {
        var $items = getItems();
        var idx = $items.index(this);
        if (idx >= 0) setActiveIndex(idx);
    });
    $toolbar.on('keydown', handleToolbarArrowNav);
    if (!$toolbar.data('kbdA11yNativeKeyBound')) {
        $toolbar.get(0).addEventListener('keydown', handleToolbarArrowNav, true);
        $toolbar.data('kbdA11yNativeKeyBound', true);
    }

    var observer = new MutationObserver(function() { ensureOneTabStop(); });
    observer.observe($toolbar[0], { subtree: true, attributes: true, attributeFilter: ['tabindex', 'class', 'disabled'] });
    $toolbar.data('kbdA11yObserver', observer);
    var fixTimer = setInterval(ensureOneTabStop, 1000);
    $toolbar.data('kbdA11yTimer', fixTimer);
    ensureOneTabStop();
    $toolbar.data('kbdA11yBound', true);
}

function focusAdminEditorToolbar($el) {
    if (!window.jQuery || !$el || !$el.length) return;
    var $toolbar = $el.next('.note-editor').find('.note-toolbar').first();
    if (!$toolbar.length) return;
    var $items = $toolbar.find('.note-btn-group button').filter(function() {
        var $b = jQuery(this);
        if ($b.is(':hidden')) return false;
        if ($b.prop('disabled')) return false;
        if ($b.closest('.dropdown-menu').length) return false;
        if ($b.attr('aria-hidden') === 'true') return false;
        return true;
    });
    if (!$items.length) return;
    $items.attr('tabindex', '-1');
    $items.eq(0).attr('tabindex', '0').focus();
}

// initialize summernote on admin modal
$(document).ready(function(){
    try {
        if ($.fn.summernote) {
            // Initialize Summernote when modal is shown
            $('#adminEditModal').on('shown.bs.modal', function() {
                $('#a_personal_note').summernote({
                    height: 120,
                    toolbar: [
                        ['style', ['bold', 'italic', 'underline', 'clear']],
                        ['font', ['strikethrough']],
                        ['para', ['ul', 'ol', 'paragraph']],
                        ['insert', ['link']],
                        ['view', ['codeview']]
                    ],
                    callbacks: {
                        onInit: function() {
                            var $editor = $('#a_personal_note');
                            setTimeout(function() { enableAdminToolbarKeyboardA11y($editor); }, 0);
                            setTimeout(function() { enableAdminToolbarKeyboardA11y($editor); }, 200);
                        },
                        onKeydown: function(e) {
                            if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) {
                                e.preventDefault();
                                focusAdminEditorToolbar($('#a_personal_note'));
                            }
                        }
                    }
                });
                $('#a_notes').summernote({
                    height: 120,
                    toolbar: [
                        ['style', ['bold', 'italic', 'underline', 'clear']],
                        ['font', ['strikethrough']],
                        ['para', ['ul', 'ol', 'paragraph']],
                        ['insert', ['link']],
                        ['view', ['codeview']]
                    ],
                    callbacks: {
                        onInit: function() {
                            var $editor = $('#a_notes');
                            setTimeout(function() { enableAdminToolbarKeyboardA11y($editor); }, 0);
                            setTimeout(function() { enableAdminToolbarKeyboardA11y($editor); }, 200);
                        },
                        onKeydown: function(e) {
                            if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) {
                                e.preventDefault();
                                focusAdminEditorToolbar($('#a_notes'));
                            }
                        }
                    }
                });
                
                // Initially disable editing
                disableAdminEditing();
            });
            
            // Destroy Summernote when modal is hidden
            $('#adminEditModal').on('hide.bs.modal', function() {
                try {
                    $('#a_personal_note').summernote('destroy');
                    $('#a_notes').summernote('destroy');
                } catch(e) {
                    // ignore errors
                }
            });
        }
    } catch(e) {
        // Summernote initialization failed, will use plain textarea
    }
});

// Enable editing mode for admin
function enableAdminEditing() {
    document.getElementById('a_status').disabled = false;
    var modal = document.getElementById('adminEditModal');
    if (modal) {
        modal.classList.remove('admin-editor-readonly');
    }
    
    // Wait a bit for Summernote to be initialized
    setTimeout(function() {
        if ($.fn.summernote && $('#a_notes').summernote('code') !== undefined) {
            $('#a_notes').summernote('enable');
            $('#a_personal_note').summernote('enable');
            enableAdminToolbarKeyboardA11y($('#a_notes'));
            enableAdminToolbarKeyboardA11y($('#a_personal_note'));
        } else {
            document.getElementById('a_notes').readOnly = false;
            document.getElementById('a_personal_note').readOnly = false;
        }
    }, 200);
}

// Disable editing mode for admin
function disableAdminEditing() {
    document.getElementById('a_status').disabled = true;
    var modal = document.getElementById('adminEditModal');
    if (modal) {
        modal.classList.add('admin-editor-readonly');
    }
    
    // Wait a bit for Summernote to be initialized
    setTimeout(function() {
        if ($.fn.summernote && $('#a_notes').summernote('code') !== undefined) {
            $('#a_notes').summernote('disable');
            $('#a_personal_note').summernote('disable');
        } else {
            document.getElementById('a_notes').readOnly = true;
            document.getElementById('a_personal_note').readOnly = true;
        }
    }, 200);
}

// Load production hours for admin modal
function loadAdminProductionHours(userId, date) {
    document.getElementById('adminHoursDate').textContent = '(' + date + ')';
    
    var url = '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/user_hours.php?user_id=' + encodeURIComponent(userId) + '&date=' + encodeURIComponent(date);
    
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                var totalHours = parseFloat(data.total_hours || 0);
                var utilizedHours = 0;
                var benchHours = 0;
                
                document.getElementById('adminTotalHours').textContent = totalHours.toFixed(2) + ' hrs';
                
                if (data.entries && data.entries.length > 0) {
                    var html = '<div class="list-group list-group-flush">';
                    data.entries.forEach(function(entry) {
                        var hours = parseFloat(entry.hours_spent || 0);
                        var isUtilized = entry.is_utilized == 1 || entry.po_number !== 'OFF-PROD-001';
                        
                        if (isUtilized) {
                            utilizedHours += hours;
                        } else {
                            benchHours += hours;
                        }
                        
                        html += '<div class="list-group-item py-2">';
                        html += '<div class="d-flex justify-content-between align-items-start">';
                        html += '<div class="flex-grow-1">';
                        html += '<h6 class="mb-1">' + escapeHtml(entry.project_title || 'Unknown Project') + '</h6>';
                        if (entry.page_name) {
                            html += '<p class="mb-1 text-muted small">Page: ' + escapeHtml(entry.page_name) + '</p>';
                        }
                        if (entry.comments) {
                            html += '<p class="mb-0 small">' + escapeHtml(entry.comments) + '</p>';
                        }
                        html += '</div>';
                        html += '<div class="text-end">';
                        html += '<span class="badge ' + (isUtilized ? 'bg-success' : 'bg-secondary') + '">' + hours.toFixed(2) + 'h</span>';
                        html += '</div>';
                        html += '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                    document.getElementById('adminHoursEntries').innerHTML = html;
                } else {
                    document.getElementById('adminHoursEntries').innerHTML = '<p class="text-muted text-center">No time logged for this date</p>';
                }
                
                // Update progress bars
                document.getElementById('adminUtilizedHours').textContent = utilizedHours.toFixed(2);
                document.getElementById('adminBenchHours').textContent = benchHours.toFixed(2);
                
                if (totalHours > 0) {
                    var utilizedPercent = (utilizedHours / totalHours) * 100;
                    var benchPercent = (benchHours / totalHours) * 100;
                    document.getElementById('adminUtilizedProgress').style.width = utilizedPercent + '%';
                    document.getElementById('adminBenchProgress').style.width = benchPercent + '%';
                }
            } else {
                console.error('Admin API returned error:', data.error);
                document.getElementById('adminHoursEntries').innerHTML = '<p class="text-danger text-center">Failed to load production hours: ' + (data.error || 'Unknown error') + '</p>';
            }
        })
        .catch(error => {
            console.error('Admin fetch error:', error);
            document.getElementById('adminHoursEntries').innerHTML = '<p class="text-danger text-center">Error loading production hours: ' + error.message + '</p>';
        });
}

function openAdminEditModal(userId, date, role, status, notes, personal_note) {
    // Reset modal state
    document.getElementById('a_user_id').value = userId;
    document.getElementById('a_date').value = date;
    document.getElementById('a_status').value = status || 'available';
    
    // Clear dynamic buttons
    var modalFooter = document.querySelector('#adminEditModal .modal-footer');
    var dynamicButtons = modalFooter.querySelectorAll('.dynamic-save-btn');
    dynamicButtons.forEach(btn => btn.remove());
    
    // Show edit button and ensure it's enabled
    var editBtn = document.getElementById('adminEditBtn');
    editBtn.style.display = 'inline-block';
    editBtn.disabled = false;
    
    // Load production hours
    loadAdminProductionHours(userId, date);
    
    var modalEl = document.getElementById('adminEditModal');
    if (!modalEl) {
        showToast('Modal not found', 'warning');
        return;
    }
    
    var m = new bootstrap.Modal(modalEl);
    m.show();
    
    // Load user data after modal is shown and initially disable editing
    setTimeout(function() {
        loadAdminUserData(userId, date);
        // Start in readonly mode as requested
        disableAdminEditing();
    }, 300);
}

// Load user data for admin modal
function loadAdminUserData(userId, date) {
    var url = '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php?action=get_personal_note&date=' + encodeURIComponent(date) + '&user_id=' + encodeURIComponent(userId);
    
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
            
            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    throw new Error('Server returned HTML instead of JSON. Response: ' + text.substring(0, 200));
                });
            }
            
            return response.json();
        })
        .then(data => {
            if (data.success) {
                document.getElementById('a_status').value = data.status || 'not_updated';
                
                if ($.fn.summernote) {
                    try {
                        $('#a_notes').summernote('code', data.notes || '');
                        $('#a_personal_note').summernote('code', data.personal_note || '');
                    } catch(e) {
                        document.getElementById('a_notes').value = data.notes || '';
                        document.getElementById('a_personal_note').value = data.personal_note || '';
                    }
                } else {
                    document.getElementById('a_notes').value = data.notes || '';
                    document.getElementById('a_personal_note').value = data.personal_note || '';
                }
            } else {
                showToast('Failed to load user data: ' + (data.error || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            showToast('Failed to load user data: ' + error.message, 'danger');
        });
}

// Handle admin edit button click
document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'adminEditBtn') {
        enableAdminEditing();
        
        // Hide edit button and show save button
        e.target.style.display = 'none';
        
        var modalFooter = document.querySelector('#adminEditModal .modal-footer');
        var cancelBtn = modalFooter.querySelector('.btn-secondary');
        
        var saveBtn = document.createElement('button');
        saveBtn.type = 'button'; // Changed from submit to button
        saveBtn.className = 'btn btn-success dynamic-save-btn';
        saveBtn.textContent = 'Save Changes';
        saveBtn.onclick = function() {
            // Manually trigger form submission
            $('#adminCalendarEditForm').submit();
        };
        modalFooter.insertBefore(saveBtn, cancelBtn);
    }
});

// Open admin edit from userHoursModal
document.addEventListener('click', function(e){
    var t = e.target;
    if (t && t.matches && t.matches('.open-admin-edit')) {
        var uid = t.getAttribute('data-user-id');
        var date = t.getAttribute('data-date');
        if (!uid || !date) {
            return;
        }
        openAdminEditModal(uid, date, '', '', '', '');
    }
});

// Submit admin edit form via AJAX
$(document).on('submit', '#adminCalendarEditForm', function(e){
    e.preventDefault();
    
    var userId = document.getElementById('a_user_id').value;
    var date = document.getElementById('a_date').value;
    var status = document.getElementById('a_status').value;
    
    var notes = '';
    var personal = '';
    
    if ($.fn.summernote) {
        try {
            notes = $('#a_notes').summernote('code');
            personal = $('#a_personal_note').summernote('code');
        } catch(e) {
            notes = document.getElementById('a_notes').value;
            personal = document.getElementById('a_personal_note').value;
        }
    } else {
        notes = document.getElementById('a_notes').value;
        personal = document.getElementById('a_personal_note').value;
    }

    var data = {
        update_status: 1,
        user_id: userId,
        status: status,
        notes: notes,
        personal_note: personal
    };

    var url = '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php?date=' + encodeURIComponent(date);

    $.ajax({
        url: url,
        method: 'POST',
        data: data,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        success: function(resp){
            try { 
                var j = typeof resp === 'object' ? resp : JSON.parse(resp); 
            } catch(e){ 
                showToast('Unexpected response from server', 'danger'); 
                return; 
            }
            if (j.success) {
                if (window.calendar && typeof window.calendar.refetchEvents === 'function') {
                    window.calendar.refetchEvents();
                }
                var m = bootstrap.Modal.getInstance(document.getElementById('adminEditModal'));
                if (m) m.hide();
                showToast('Status updated successfully', 'success');
            } else {
                showToast('Failed to update: ' + (j.error || 'Unknown error'), 'danger');
            }
        },
        error: function(xhr, status, error){ 
            showToast('Request failed: ' + error + ' (Status: ' + xhr.status + ')', 'danger'); 
        }
    });
});
</script>

<!-- Consolidated Users Modal -->
<div class="modal fade" id="consolidatedModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="consolidatedModalTitle">Users Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="consolidatedContent">
                    <p class="text-muted">Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Request Modal -->
<div class="modal fade" id="editRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1"><strong>User:</strong> <span id="ermUser"></span></p>
                <p class="mb-2"><strong>Date:</strong> <span id="ermDate"></span></p>
                <div class="border rounded p-2 bg-light">
                    <p class="mb-0" id="ermReason"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function showConsolidatedModal(userList, date, statusType) {
    var statusLabel = availabilityStatusLabel(statusType);
    var modalTitle = statusLabel + ' Users - ' + date + ' (' + userList.length + ' users)';
    
    document.getElementById('consolidatedModalTitle').textContent = modalTitle;
    
    var html = '<div class="row">';
    userList.forEach(function(user, index) {
        var badgeClass = availabilityStatusBadgeClass(statusType, false);
        if (user.hours > 0 && user.hours < 8) badgeClass = 'warning';
        
        html += '<div class="col-md-6 mb-3">';
        html += '<div class="card h-100">';
        html += '<div class="card-body p-3">';
        html += '<div class="d-flex justify-content-between align-items-start mb-2">';
        html += '<h6 class="card-title mb-0">' + escapeHtml(user.name) + '</h6>';
        html += '<span class="badge bg-' + badgeClass + '">' + user.hours.toFixed(1) + 'h</span>';
        html += '</div>';
        html += '<p class="card-text small text-muted mb-2">Status: ' + ucfirst(user.status.replace('_', ' ')) + '</p>';
        if (user.notes) {
            html += '<p class="card-text small">' + escapeHtml(user.notes) + '</p>';
        }
        html += '<button class="btn btn-sm btn-primary" onclick="openAdminEditModal(' + user.id + ', \'' + date + '\', \'\', \'' + user.status + '\', \'' + escapeHtml(user.notes) + '\', \'\')">';
        html += '<i class="fas fa-edit"></i> Edit';
        html += '</button>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
    });
    html += '</div>';
    
    document.getElementById('consolidatedContent').innerHTML = html;
    
    var modal = new bootstrap.Modal(document.getElementById('consolidatedModal'));
    modal.show();
}

// Show custom modal for edit requests
function showEditRequestModal(data) {
    try {
        document.getElementById('ermUser').textContent = data.userName || '';
        document.getElementById('ermDate').textContent = data.date || '';
        document.getElementById('ermReason').textContent = data.reason || 'No reason provided';

        var modalEl = document.getElementById('editRequestModal');
        if (!modalEl) return;
        var m = new bootstrap.Modal(modalEl);
        m.show();
    } catch (e) {
        console.error('Failed to open edit request modal', e);
        showToast('Edit Request from: ' + (data.userName || '') + '\nReason: ' + (data.reason || ''), 'info');
    }
}

function showConsolidatedModal(userList, date, statusType) {
    var statusLabel = availabilityStatusLabel(statusType);
    var modalTitle = statusLabel + ' Users - ' + date + ' (' + userList.length + ' users)';
    
    document.getElementById('consolidatedModalTitle').textContent = modalTitle;
    
    var html = '<div class="row g-3">';
    userList.forEach(function(user, index) {
        var badgeClass = availabilityStatusBadgeClass(statusType, true);
        if (user.hours > 0 && user.hours < 8) badgeClass = 'bg-warning text-dark';
        
        html += '<div class="col-md-6">';
        html += '<div class="card h-100 shadow-sm border">';
        html += '<div class="card-body p-3">';
        html += '<div class="d-flex justify-content-between align-items-start mb-2">';
        html += '<h6 class="card-title mb-0 fw-bold text-truncate" style="max-width: 70%;" title="' + escapeHtml(user.name) + '">' + escapeHtml(user.name) + '</h6>';
        html += '<span class="badge ' + badgeClass + '">' + user.hours.toFixed(1) + 'h</span>';
        html += '</div>';
        
        var statusText = ucfirst(user.status.replace('_', ' '));
        html += '<div class="mb-2">';
        html += '<span class="badge bg-light text-dark border me-1">' + statusText + '</span>';
        html += '</div>';
        
        if (user.notes) {
            html += '<div class="p-2 bg-light rounded small text-muted mb-3 text-truncate">' + escapeHtml(user.notes) + '</div>';
        } else {
            html += '<div class="mb-3"></div>';
        }
        
        html += '<button class="btn btn-sm btn-outline-primary w-100" onclick="openAdminEditModal(' + user.id + ', \'' + date + '\', \'\', \'' + user.status + '\', \'' + escapeHtml(user.notes) + '\', \'\')">';
        html += '<i class="fas fa-edit me-1"></i> Edit Status';
        html += '</button>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
    });
    html += '</div>';
    
    document.getElementById('consolidatedContent').innerHTML = html;
    
    var modal = new bootstrap.Modal(document.getElementById('consolidatedModal'));
    modal.show();
}

function ucfirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&"'<>]/g, function (s) {
        return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[s];
    });
}

function fetchUserHoursAndShow(userId, date, role) {
    // For admin calendar, open the admin edit modal with same structure as user modal
    openAdminEditModal(userId, date, role || '', '', '', '');
}
</script>
