<?php
// Global Session Check - MUST be before any HTML output
if (isset($_SESSION['user_id']) && ($_SESSION['force_reset'] ?? false)) {
    $currentPage = $_SERVER['PHP_SELF'];
    if (strpos($currentPage, 'modules/auth/force_reset.php') === false && 
        strpos($currentPage, 'modules/auth/logout.php') === false) {
        require_once __DIR__ . '/helpers.php';
        redirect("/modules/auth/force_reset.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'PMS'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
    <?php
    if (!isset($baseDir)) {
        require_once __DIR__ . '/helpers.php';
        $baseDir = getBaseDir();
    }
    ?>
    <?php $assetVersion = '20260202v3'; ?>
    <link href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/css/style.css?v=<?php echo $assetVersion; ?>" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Global suppression: disable browser alert/confirm/prompt, Notification prompts,
    // prevent Bootstrap modals from appearing, and hide success alerts/toasts.
    (function(){
        try {
            // Disable native dialogs
            window._origAlert = window.alert; window.alert = function(){};
            window._origConfirm = window.confirm; window.confirm = function(){ return true; };
            window._origPrompt = window.prompt; window.prompt = function(){ return null; };

            // Prevent Notification permission prompts and construction
            if (window.Notification) {
                try {
                    Notification.requestPermission = function(){ return Promise.resolve('denied'); };
                } catch (e) {}
                try {
                    // Replace constructor with noop to avoid showing system notifications
                    window.Notification = function(){};
                } catch (e) {}
            }

            // Allow Bootstrap modals to function (previously blocked; re-enabled to fix calendar dialogs)

            // Hide success alerts and toasts immediately and when added
            function hideSuccessElements(node){
                if (!node) return;
                try {
                    if (node.nodeType === 1) {
                        if (node.matches('.alert-success, .toast, .alert-success *')) {
                            node.style.display = 'none';
                        }
                        var els = node.querySelectorAll && node.querySelectorAll('.alert-success, .toast');
                        if (els && els.length) {
                            els.forEach(function(el){ el.style.display = 'none'; });
                        }
                    }
                } catch (e) {}
            }

            // Initial pass
            hideSuccessElements(document.documentElement);

            // Observe DOM for new success elements
            var observer = new MutationObserver(function(mutations){
                mutations.forEach(function(m){
                    m.addedNodes.forEach(hideSuccessElements);
                });
            });
            try { observer.observe(document.documentElement || document.body, { childList: true, subtree: true }); } catch(e){}

        } catch (e) {}
    })();
    
    // Global toast helper (available early for all pages)
    function escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return String(unsafe)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast(message, variant = 'info', ttl = 4000) {
        try {
            const containerId = 'pmsGlobalToastContainer';
            let container = document.getElementById(containerId);
            if (!container) {
                container = document.createElement('div');
                container.id = containerId;
                container.className = 'position-fixed bottom-0 end-0 p-3';
                container.style.zIndex = 10800;
                document.body.appendChild(container);
            }
            const toastId = 'toast_' + Date.now() + '_' + Math.floor(Math.random()*1000);
            const bg = variant === 'success' ? 'bg-success text-white' : (variant === 'danger' ? 'bg-danger text-white' : (variant === 'warning' ? 'bg-warning text-dark' : 'bg-secondary text-white'));
            const toastEl = document.createElement('div');
            toastEl.id = toastId;
            toastEl.className = 'toast align-items-center ' + bg + ' border-0 show';
            toastEl.role = 'alert';
            toastEl.ariaLive = 'assertive';
            toastEl.ariaAtomic = 'true';
            toastEl.style.minWidth = '220px';
            toastEl.innerHTML = `\n                <div class="d-flex">\n                    <div class="toast-body">${escapeHtml(message)}</div>\n                    <button type="button" class="btn-close btn-close-white me-2 m-auto" aria-label="Close"></button>\n                </div>`;
            container.appendChild(toastEl);
            const closeBtn = toastEl.querySelector('.btn-close');
            closeBtn.addEventListener('click', () => { toastEl.remove(); });
            setTimeout(() => { toastEl.remove(); }, ttl);
        } catch (e) {
            try { window._origAlert ? window._origAlert(message) : null; } catch (_) {}
        }
    }
    </script>
</head>
<body class="app-shell">
    <!-- Compact Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top" style="background-color: #0f6cbf !important; background-color: var(--primary) !important;">
        <div class="container-fluid">
            <!-- Brand -->
            <a class="navbar-brand d-flex align-items-center gap-2 me-4" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/" aria-label="PMS Home">
                <div class="d-flex align-items-center justify-content-center bg-primary rounded fs-6" style="width: 28px; height: 28px;">
                    <i class="fas fa-layer-group text-white"></i>
                </div>
                <span class="tracking-tight">PMS</span>
            </a>

            <!-- Mobile Toggle -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navbar Content -->
            <div class="collapse navbar-collapse" id="navbarContent">
                <?php
                // Track visited sections/pages in session for admin audit (keep recent 20)
                if (isset($_SESSION['user_id'])) {
                    $path = $_SERVER['PHP_SELF'] ?? '';
                    $qs = $_SERVER['QUERY_STRING'] ?? '';
                    $section = $path . ($qs ? ('?' . $qs) : '');
                    if (!isset($_SESSION['user_sections']) || !is_array($_SESSION['user_sections'])) $_SESSION['user_sections'] = [];
                    // avoid duplicates in immediate succession
                    if (empty($_SESSION['user_sections']) || ($_SESSION['user_sections'][0] ?? '') !== $section) {
                        array_unshift($_SESSION['user_sections'], $section);
                        $_SESSION['user_sections'] = array_slice($_SESSION['user_sections'], 0, 20);
                    }
                }
                ?>

                <?php
                // Update user_sessions last_activity for current session (best-effort)
                if (isset($_SESSION['user_id'])) {
                    try {
                        require_once __DIR__ . '/../config/database.php';
                        $db = Database::getInstance();
                        $sid = session_id();
                        $upd = $db->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ? AND user_id = ?");
                        $upd->execute([$sid, $_SESSION['user_id']]);
                    } catch (Exception $_) {
                        // non-fatal
                    }
                }
                ?>

                <ul class="navbar-nav me-auto gap-1">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php
                        if (!isset($baseDir)) {
                            require_once __DIR__ . '/helpers.php';
                            $baseDir = getBaseDir();
                        }
                        $role = $_SESSION['role'] ?? 'auth';
                        $moduleDir = getModuleDirectory($role);
                        ?>

                        <!-- Dashboard -->
                        <li class="nav-item">
                            <a class="nav-link text-white" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/<?php echo htmlspecialchars($moduleDir, ENT_QUOTES, 'UTF-8'); ?>/dashboard.php">
                                <i class="fas fa-home me-1 opacity-50"></i> Dashboard
                            </a>
                        </li>

                        <!-- Common Workspace Menus (All Users) -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle text-white" href="#" id="workspaceDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-briefcase me-1 opacity-50"></i> Workspace
                            </a>
                            <ul class="dropdown-menu shadow-sm" aria-labelledby="workspaceDropdown">
                                <li>
                                    <a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php">
                                        <i class="fas fa-clock me-2 text-primary opacity-75"></i> Daily Log
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/calendar.php">
                                            <i class="fas fa-calendar-alt me-2 text-success opacity-75"></i> My Calendar
                                        </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/feedback.php">
                                        <i class="fas fa-comment-dots me-2 text-info opacity-75"></i> Feedback
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/chat/project_chat.php">
                                        <i class="fas fa-comments me-2 text-warning opacity-75"></i> Chat
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/devices.php">
                                        <i class="fas fa-laptop me-2 text-secondary opacity-75"></i> Devices
                                    </a>
                                </li>
                            </ul>
                        </li>

                        <!-- Admin Menus -->
                        <?php if ($_SESSION['role'] === 'super_admin' || $_SESSION['role'] === 'admin'): ?>
                        
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle text-white" href="#" id="projectsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    Projects
                                </a>
                                <ul class="dropdown-menu shadow-sm animate slideIn" aria-labelledby="projectsDropdown">
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/projects.php">Manage Projects</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/bulk_hours_management.php">Bulk Hours</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/manage_statuses.php">Statuses</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/clients.php">Clients</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/environments.php">Environments</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/issue_config.php">Issue Configuration</a></li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <?php if ($_SESSION['role'] === 'super_admin' || $_SESSION['role'] === 'admin' || !empty($_SESSION['can_manage_issue_config'])): ?>
                            
                            <?php if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'admin' && !empty($_SESSION['can_manage_issue_config'])): ?>
                                 <li class="nav-item">
                                    <a class="nav-link text-white" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/issue_config.php">
                                        <i class="fas fa-tools me-1 opacity-50"></i> Issue Config
                                    </a>
                                </li>
                            <?php endif; ?>

                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle text-white" href="#" id="loginActivityDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    Login Activity
                                </a>
                                <ul class="dropdown-menu shadow-sm" aria-labelledby="loginActivityDropdown">
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/login_activity.php">Login Activity</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/active_sessions.php">Active Sessions</a></li>
                                </ul>
                            </li>

                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle text-white" href="#" id="peopleDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    People
                                </a>
                                <ul class="dropdown-menu shadow-sm" aria-labelledby="peopleDropdown">
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/users.php">Users Directory</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/resource_workload.php">Resource Workload</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/calendar.php">Users Calendar</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item small text-muted text-uppercase fw-bold px-3 py-1">Permissions</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/resource_workload_permissions.php">Workload Access</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/project_specific_permissions.php">Project Access</a></li>
                                </ul>
                            </li>

                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle text-white" href="#" id="configDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    System
                                </a>
                                <ul class="dropdown-menu shadow-sm" aria-labelledby="configDropdown">
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/vault.php">Admin Vault</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/devices.php">Device Management</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/hours_compliance.php">Hours Compliance</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/env_status_master.php">Manage Env Status</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/qa_status_master.php">Manage QA Status</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/issue_statuses.php">Manage Issue Status</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/phase_master.php">Manage Phase Names</a></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/performance.php">Resource Performance</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/edit_requests.php">Edit Requests</a></li>
                                </ul>
                            </li>
                            
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/reports/dashboard.php">
                                    Reports
                                </a>
                            </li>

                        <?php endif; ?>

                        <!-- Project Lead Menus -->
                        <?php if ($_SESSION['role'] === 'project_lead'): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/project_lead/team_assignment.php">
                                    <i class="fas fa-users-cog me-1 opacity-50"></i> Team Assignment
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- QA Menus -->
                        <?php if ($_SESSION['role'] === 'qa'): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/qa/qa_tasks.php">
                                    <i class="fas fa-tasks me-1 opacity-50"></i> QA Tasks
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- AT Tester Menus -->
                        <?php if ($_SESSION['role'] === 'at_tester'): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/at_tester/test_history.php">
                                    <i class="fas fa-history me-1 opacity-50"></i> Test History
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- FT Tester Menus -->
                        <?php if ($_SESSION['role'] === 'ft_tester'): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/ft_tester/test_history.php">
                                    <i class="fas fa-history me-1 opacity-50"></i> Test History
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- Legacy Tester Menus (for backward compatibility) -->
                        <?php if (in_array($_SESSION['role'], ['tester'])): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/tester/testing_tasks.php">
                                    <i class="fas fa-vial me-1 opacity-50"></i> My Testing Tasks
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>

                <!-- Right Side Items -->
                <ul class="navbar-nav ms-auto align-items-center gap-2">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        
                        <!-- Notifications -->
                        <li class="nav-item dropdown me-1">
                            <a class="nav-link position-relative text-white p-2" href="#" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="far fa-bell fa-lg"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" style="font-size: 0.6rem; padding: 0.25em 0.4em;" id="notificationCount">0</span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="notificationDropdown" style="width: 320px; max-height: 480px; overflow-y: auto;">
                                <li class="dropdown-header py-2 d-flex justify-content-between align-items-center bg-light">
                                    <span class="fw-bold text-dark">Notifications</span>
                                    <a href="#" id="markAllRead" class="text-decoration-none small">Mark all read</a>
                                </li>
                                <div id="notificationsContent">
                                    <li><span class="dropdown-item text-muted small py-3 text-center">No new notifications</span></li>
                                </div>
                                <li><hr class="dropdown-divider m-0"></li>
                                <li><a class="dropdown-item text-center small text-primary py-2" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/notifications.php">View History</a></li>
                            </ul>
                        </li>

                        <!-- User Profile -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 text-white" href="#" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white small fw-bold" style="width: 32px; height: 32px; border: 2px solid rgba(255,255,255,0.2);">
                                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                                </div>
                                <span class="d-none d-lg-block small fw-semibold text-truncate" style="max-width: 150px;">
                                    <?php echo isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8') : 'User'; ?>
                                </span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="userDropdown">
                                <li>
                                    <div class="px-3 py-2 border-bottom mb-2">
                                        <div class="fw-bold text-dark small"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                                        <div class="text-muted small" style="font-size: 0.75rem;"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></div>
                                    </div>
                                </li>
                                <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/profile.php"><i class="fas fa-user-circle me-2 text-muted"></i> Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </li>

                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link btn btn-sm btn-outline-light px-3 text-white" href="<?php echo htmlspecialchars($baseDir ?? '', ENT_QUOTES, 'UTF-8'); ?>/modules/auth/login.php">Login</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    
    <?php if (isset($_SESSION['user_id'])): ?>
    <!-- Notification Loading System -->
    <script>
    (function() {
        function loadNotifications() {
            $.get('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/status.php?action=get_notifications', function(response) {
                if (response.success) {
                    const notifications = response.notifications || [];
                    const unreadCount = notifications.filter(n => !n.is_read).length;
                    
                    // Update badge
                    const badge = $('#notificationCount');
                    if (unreadCount > 0) {
                        badge.text(unreadCount).removeClass('d-none');
                    } else {
                        badge.addClass('d-none');
                    }
                    
                    // Update dropdown content
                    const content = $('#notificationsContent');
                    if (notifications.length === 0) {
                        content.html('<li><span class="dropdown-item text-muted small py-3 text-center">No new notifications</span></li>');
                    } else {
                        let html = '';
                        notifications.slice(0, 10).forEach(function(notif) {
                            const readClass = notif.is_read ? 'bg-white' : 'bg-light';
                            const icon = notif.type === 'mention' ? 'fa-at' : (notif.type === 'assignment' ? 'fa-tasks' : 'fa-bell');
                            const link = notif.link || '#';
                            html += `
                                <li>
                                    <a class="dropdown-item ${readClass} py-2 notification-item" href="${escapeHtml(link)}" data-id="${notif.id}">
                                        <div class="d-flex align-items-start">
                                            <i class="fas ${icon} text-primary me-2 mt-1"></i>
                                            <div class="flex-grow-1">
                                                <div class="small">${escapeHtml(notif.message)}</div>
                                                <div class="text-muted" style="font-size: 0.7rem;">${notif.time_ago}</div>
                                            </div>
                                        </div>
                                    </a>
                                </li>
                            `;
                        });
                        content.html(html);
                    }
                }
            }).fail(function() {
                // Silently fail
            });
        }
        
        // Mark notification as read when clicked
        $(document).on('click', '.notification-item', function() {
            const notifId = $(this).data('id');
            if (notifId) {
                $.post('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/status.php', {
                    action: 'mark_notification_read',
                    notification_id: notifId
                });
            }
        });
        
        // Mark all as read
        $('#markAllRead').on('click', function(e) {
            e.preventDefault();
            $.post('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/status.php', {
                action: 'mark_all_notifications_read'
            }, function() {
                loadNotifications();
            });
        });
        
        // Load notifications on page load
        $(document).ready(function() {
            loadNotifications();
        });
        
        // Refresh notifications every 30 seconds
        setInterval(loadNotifications, 30000);
    })();
    </script>
    
    <!-- Hours Reminder System -->
    <script>
    (function() {
        let reminderShown = false;
        
        function checkHoursReminder() {
            if (reminderShown) return;
            
            $.get('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/hours_reminder.php?action=check_reminder_time', function(response) {
                if (response.success && response.show_reminder) {
                    reminderShown = true;
                    showHoursReminderModal(response);
                }
            }).fail(function() {
                // Silently fail
            });
        }
        
        function showHoursReminderModal(data) {
            const modalHtml = `
                <div class="modal fade" id="hoursReminderModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-warning" style="border-width: 3px;">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title">
                                    <i class="fas fa-clock"></i> Daily Hours Reminder
                                </h5>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-warning mb-3">
                                    <i class="fas fa-exclamation-triangle"></i> <strong>Action Required</strong>
                                </div>
                                <p class="mb-3">${escapeHtml(data.message)}</p>
                                <div class="row text-center mb-3">
                                    <div class="col-6">
                                        <div class="card bg-light">
                                            <div class="card-body py-2">
                                                <small class="text-muted">Current Hours</small>
                                                <h3 class="mb-0 text-warning">${data.current_hours}</h3>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="card bg-light">
                                            <div class="card-body py-2">
                                                <small class="text-muted">Required Hours</small>
                                                <h3 class="mb-0 text-success">${data.minimum_hours}</h3>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-muted small mb-0">
                                    <i class="fas fa-info-circle"></i> Please update your production hours before end of day.
                                </p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="dismissHoursReminder()">
                                    Remind Me Later
                                </button>
                                <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/my_daily_status.php" class="btn btn-primary">
                                    <i class="fas fa-edit"></i> Update Hours Now
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHtml);
            const modal = new bootstrap.Modal(document.getElementById('hoursReminderModal'));
            modal.show();
        }
        
        window.dismissHoursReminder = function() {
            $.post('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/hours_reminder.php', {
                action: 'dismiss_reminder'
            });
            $('#hoursReminderModal').modal('hide');
            setTimeout(() => {
                $('#hoursReminderModal').remove();
            }, 500);
        };
        
        // Check immediately on page load
        $(document).ready(function() {
            setTimeout(checkHoursReminder, 2000);
        });
        
        // Check every 2 minutes
        setInterval(checkHoursReminder, 120000);
    })();
    </script>
    <?php endif; ?>
    
    <div class="container-fluid">
