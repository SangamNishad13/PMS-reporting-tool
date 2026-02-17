<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin','super_admin']);

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string)($_POST['cleanup_action'] ?? ''));
    $redirectTo = strtok($_SERVER['REQUEST_URI'], '?');
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    if ($queryString !== '') {
        $redirectTo .= '?' . $queryString;
    }

    if ($postAction === 'delete_selected') {
        $sessionIdsRaw = $_POST['session_ids'] ?? [];
        $sessionIds = [];
        if (is_array($sessionIdsRaw)) {
            foreach ($sessionIdsRaw as $sid) {
                $sid = trim((string)$sid);
                if ($sid !== '') {
                    $sessionIds[] = $sid;
                }
            }
        }
        $sessionIds = array_values(array_unique($sessionIds));
        if (empty($sessionIds)) {
            $_SESSION['error'] = 'No session rows selected.';
        } else {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $sql = "DELETE FROM user_sessions WHERE session_id IN ($placeholders) AND session_id <> ?";
            $params = array_merge($sessionIds, [session_id()]);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $deleted = (int)$stmt->rowCount();
            $_SESSION['success'] = $deleted . ' session record(s) deleted.';
            try {
                logActivity($db, (int)$_SESSION['user_id'], 'admin_cleanup_sessions', 'user_sessions', 0, [
                    'selected_count' => count($sessionIds),
                    'deleted_rows' => $deleted
                ]);
            } catch (Throwable $_) {
                // non-fatal
            }
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($postAction === 'purge_old') {
        $beforeDate = trim((string)($_POST['before_date'] ?? ''));
        $mode = trim((string)($_POST['purge_mode'] ?? 'inactive_only'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDate)) {
            $_SESSION['error'] = 'Please select a valid date.';
            header('Location: ' . $redirectTo);
            exit;
        }
        $sql = "DELETE FROM user_sessions WHERE last_activity < ? AND session_id <> ?";
        $params = [$beforeDate . ' 00:00:00', session_id()];
        if ($mode === 'inactive_only') {
            $sql .= " AND active = 0";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $deleted = (int)$stmt->rowCount();
        $_SESSION['success'] = $deleted . ' old session record(s) deleted.';
        try {
            logActivity($db, (int)$_SESSION['user_id'], 'admin_purge_sessions', 'user_sessions', 0, [
                'before_date' => $beforeDate,
                'purge_mode' => $mode,
                'deleted_rows' => $deleted
            ]);
        } catch (Throwable $_) {
            // non-fatal
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($postAction === 'delete_by_scope') {
        $scopeType = trim((string)($_POST['scope_type'] ?? ''));
        $targetId = (int)($_POST['target_id'] ?? 0);
        $mode = trim((string)($_POST['scope_mode'] ?? 'all'));

        if ($targetId <= 0) {
            $_SESSION['error'] = 'Please select a valid scope target.';
            header('Location: ' . $redirectTo);
            exit;
        }

        $userIds = [];
        if ($scopeType === 'user') {
            $userIds[] = $targetId;
        } elseif ($scopeType === 'project') {
            $uStmt = $db->prepare("
                SELECT DISTINCT ua.user_id
                FROM user_assignments ua
                WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
            ");
            $uStmt->execute([$targetId]);
            $userIds = array_map('intval', $uStmt->fetchAll(PDO::FETCH_COLUMN));

            $leadStmt = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ? LIMIT 1");
            $leadStmt->execute([$targetId]);
            $leadId = (int)$leadStmt->fetchColumn();
            if ($leadId > 0) {
                $userIds[] = $leadId;
            }
            $userIds = array_values(array_unique(array_filter($userIds)));
        } else {
            $_SESSION['error'] = 'Invalid scope type.';
            header('Location: ' . $redirectTo);
            exit;
        }

        if (empty($userIds)) {
            $_SESSION['error'] = 'No users found for selected scope.';
            header('Location: ' . $redirectTo);
            exit;
        }

        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "DELETE FROM user_sessions WHERE user_id IN ($ph) AND session_id <> ?";
        $params = array_merge($userIds, [session_id()]);
        if ($mode === 'inactive_only') {
            $sql .= " AND active = 0";
        } elseif ($mode === 'active_only') {
            $sql .= " AND active = 1";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $deleted = (int)$stmt->rowCount();
        $_SESSION['success'] = $deleted . ' session record(s) deleted for selected scope.';
        try {
            logActivity($db, (int)$_SESSION['user_id'], 'admin_cleanup_sessions_by_scope', 'user_sessions', 0, [
                'scope_type' => $scopeType,
                'target_id' => $targetId,
                'scope_mode' => $mode,
                'user_count' => count($userIds),
                'deleted_rows' => $deleted
            ]);
        } catch (Throwable $_) {
            // non-fatal
        }
        header('Location: ' . $redirectTo);
        exit;
    }
}

// Build filters and pagination
$params = [];
$where = [];

$search = trim($_GET['q'] ?? '');
$filterActive = $_GET['active'] ?? 'all';
$filterIp = trim($_GET['ip'] ?? '');
$since = trim($_GET['since'] ?? '');
$filterOnline = $_GET['online'] ?? 'all'; // New filter for truly online users

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR us.session_id LIKE ? )";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterActive === 'yes') {
    $where[] = "us.active = 1";
} elseif ($filterActive === 'no') {
    $where[] = "us.active = 0";
}
if ($filterOnline === 'yes') {
    // Consider users online if active AND last activity within last 10 minutes
    // Use TIMESTAMPDIFF to handle timezone differences properly
    $where[] = "us.active = 1 AND TIMESTAMPDIFF(MINUTE, us.last_activity, NOW()) <= 10";
}
if ($filterIp !== '') {
    $where[] = "us.ip_address LIKE ?";
    $params[] = '%' . $filterIp . '%';
}
if ($since !== '') {
    // Expect YYYY-MM-DD
    $where[] = "us.last_activity >= ?";
    $params[] = $since . ' 00:00:00';
}

$whereSql = '';
if (!empty($where)) $whereSql = 'WHERE ' . implode(' AND ', $where);

$perPage = intval($_GET['per_page'] ?? 25);
if ($perPage <= 0 || $perPage > 200) $perPage = 25;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// total count
$countSql = "SELECT COUNT(*) FROM user_sessions us LEFT JOIN users u ON u.id = us.user_id " . $whereSql;
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT us.*, u.full_name, u.email FROM user_sessions us LEFT JOIN users u ON u.id = us.user_id " . $whereSql . " ORDER BY us.last_activity DESC LIMIT ? OFFSET ?";
$paramsWithLimit = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare($sql);
$stmt->execute($paramsWithLimit);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($total / $perPage));
$allUsers = $db->query("SELECT id, full_name, email FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allProjects = $db->query("SELECT id, title FROM projects ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container mt-4">
    <h3>Active Sessions</h3>
    <p class="text-muted">Shows recent sessions (active & inactive). Use Force Logout to terminate a session.</p>

    <div class="card mb-3 border-warning">
        <div class="card-body py-3">
            <div class="fw-semibold mb-2">Session Storage Cleanup</div>
            <form method="post" class="row g-2 align-items-end" data-confirm="Delete old session records?">
                <input type="hidden" name="cleanup_action" value="purge_old">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Delete sessions older than</label>
                    <input type="date" name="before_date" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Delete mode</label>
                    <select name="purge_mode" class="form-select form-select-sm">
                        <option value="inactive_only">Only logged-out sessions</option>
                        <option value="all">All sessions (except current)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-danger">Purge Session Records</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card mb-3 border-warning">
        <div class="card-body py-3">
            <div class="fw-semibold mb-2">User/Project Wise Session Cleanup</div>
            <form method="post" class="row g-2 align-items-end" data-confirm="Delete sessions for the selected scope?">
                <input type="hidden" name="cleanup_action" value="delete_by_scope">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Scope</label>
                    <select class="form-select form-select-sm" name="scope_type" id="sessionScopeType">
                        <option value="user">User Wise</option>
                        <option value="project">Project Team Wise</option>
                    </select>
                </div>
                <div class="col-md-4" id="sessionUserTargetWrap">
                    <label class="form-label form-label-sm">User</label>
                    <select class="form-select form-select-sm" name="target_id" id="sessionUserTarget">
                        <option value="">Select user</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)$u['full_name'] . ' (' . (string)$u['email'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-none" id="sessionProjectTargetWrap">
                    <label class="form-label form-label-sm">Project</label>
                    <select class="form-select form-select-sm" id="sessionProjectTarget">
                        <option value="">Select project</option>
                        <?php foreach ($allProjects as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Mode</label>
                    <select class="form-select form-select-sm" name="scope_mode">
                        <option value="all">All sessions</option>
                        <option value="inactive_only">Only logged-out</option>
                        <option value="active_only">Only active</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-danger">Delete By Scope</button>
                </div>
            </form>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-auto">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control form-control-sm" placeholder="Search user, email, session id">
        </div>
        <div class="col-auto">
            <input type="text" name="ip" value="<?php echo htmlspecialchars($filterIp); ?>" class="form-control form-control-sm" placeholder="IP contains">
        </div>
        <div class="col-auto">
            <input type="date" name="since" value="<?php echo htmlspecialchars($since); ?>" class="form-control form-control-sm" title="Last activity since">
        </div>
        <div class="col-auto">
            <select name="active" class="form-select form-select-sm">
                <option value="all"<?php if ($filterActive==='all') echo ' selected'; ?>>All Sessions</option>
                <option value="yes"<?php if ($filterActive==='yes') echo ' selected'; ?>>Active Sessions</option>
                <option value="no"<?php if ($filterActive==='no') echo ' selected'; ?>>Logged Out</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="online" class="form-select form-select-sm">
                <option value="all"<?php if ($filterOnline==='all') echo ' selected'; ?>>All</option>
                <option value="yes"<?php if ($filterOnline==='yes') echo ' selected'; ?>>Online Now (10min)</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="per_page" class="form-select form-select-sm">
                <?php foreach ([10,25,50,100] as $pp): ?>
                    <option value="<?php echo $pp; ?>"<?php if ($perPage==$pp) echo ' selected'; ?>><?php echo $pp; ?> per page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-primary">Filter</button>
        </div>
    </form>

    <form method="post" data-confirm="Delete selected sessions?">
    <input type="hidden" name="cleanup_action" value="delete_selected">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="small text-muted">Select session rows to delete DB records directly.</div>
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete Selected</button>
    </div>
    <div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllSessions"></th>
                <th>User</th>
                <th>Email</th>
                <th>Session ID</th>
                <th>IP</th>
                <th>User Agent</th>
                <th>Location</th>
                <th>Created</th>
                <th>Last Activity</th>
                <th>Logout At</th>
                <th>Logout Type</th>
                <th>Active</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr id="sess-<?php echo htmlspecialchars($r['session_id']); ?>">
                <td>
                    <?php if ((string)$r['session_id'] === (string)session_id()): ?>
                        <span class="text-muted small">Current</span>
                    <?php else: ?>
                        <input type="checkbox" name="session_ids[]" value="<?php echo htmlspecialchars($r['session_id']); ?>">
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($r['user_id'])): ?>
                        <a href="<?php echo htmlspecialchars(getBaseDir()); ?>/modules/profile.php?id=<?php echo intval($r['user_id']); ?>"><?php echo htmlspecialchars($r['full_name'] ?? 'User'); ?></a>
                    <?php else: ?>
                        <?php echo htmlspecialchars($r['full_name'] ?? 'User'); ?>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($r['email'] ?? ''); ?></td>
                <td><code><?php echo htmlspecialchars($r['session_id']); ?></code></td>
                <td><?php echo htmlspecialchars($r['ip_address'] ?? ''); ?></td>
                <td>
                    <?php
                        $ua_full = $r['user_agent'] ?? '';
                        $ua_short = mb_substr($ua_full, 0, 120);
                        $ua_too_long = mb_strlen($ua_full) > 120;
                    ?>
                    <div class="ua-snippet" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:480px;" title="<?php echo htmlspecialchars($ua_full); ?>"><?php echo htmlspecialchars($ua_short); ?><?php if ($ua_too_long) echo '...'; ?></div>
                    <?php if ($ua_too_long): ?>
                        <div class="ua-full d-none" style="white-space:normal; word-break:break-word; max-width:480px; margin-top:4px;"><?php echo htmlspecialchars($ua_full); ?></div>
                        <div class="mt-1">
                            <button type="button" class="btn btn-link btn-sm ua-toggle">Read more</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm ua-copy ms-2" title="Copy user-agent">Copy</button>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                        $loc = [];
                        if (!empty($r['ip_location'])) {
                            $loc = json_decode($r['ip_location'], true) ?: [];
                        }
                        // Don't call get_geo_info during page load - it's too slow
                        if (!empty($loc)) {
                            $addr = trim(($loc['city'] ?? '') . ' ' . ($loc['postal'] ?? '') . ', ' . ($loc['region'] ?? '') . ', ' . ($loc['country'] ?? ''));
                            if (!empty($loc['latitude']) && !empty($loc['longitude'])) {
                                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($loc['latitude'] . ',' . $loc['longitude']);
                            } else {
                                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr);
                            }
                            echo htmlspecialchars($addr) . ' <a href="' . htmlspecialchars($mapUrl) . '" target="_blank" rel="noopener" class="ms-1"><i class="fas fa-map-marker-alt text-primary"></i></a>';
                        } else {
                            echo '<span class="text-muted">-</span>';
                        }
                    ?>
                </td>
                <td><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['last_activity'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['logout_at'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['logout_type'] ?? ''); ?></td>
                <td>
                    <?php 
                    $isActive = (bool)$r['active'];
                    // Use database to calculate time difference to avoid timezone issues
                    $stmt = $db->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) as minutes_ago");
                    $stmt->execute([$r['last_activity']]);
                    $minutesAgo = abs((int)$stmt->fetchColumn());
                    $isOnline = $isActive && $minutesAgo <= 10;
                    
                    if ($isOnline) {
                        echo '<span class="badge bg-success">Online</span>';
                    } elseif ($isActive) {
                        echo '<span class="badge bg-warning text-dark">Idle (' . $minutesAgo . 'm ago)</span>';
                    } else {
                        echo '<span class="badge bg-secondary">Logged Out</span>';
                    }
                    ?>
                </td>
                <td>
                    <?php if ($r['active']): ?>
                        <button class="btn btn-sm btn-danger force-logout" data-session="<?php echo htmlspecialchars($r['session_id']); ?>">Force Logout</button>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </form>

    <nav aria-label="Page navigation">
        <ul class="pagination pagination-sm">
            <?php
            // build base query string for pagination links
            $qs = $_GET; unset($qs['page']);
            $baseQs = http_build_query($qs);
            $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
            for ($p=1;$p<=$totalPages;$p++) {
                $activeClass = ($p==$page) ? ' active' : '';
                $link = $baseUrl . '?' . ($baseQs ? ($baseQs . '&') : '') . 'page=' . $p . '&per_page=' . $perPage;
                echo '<li class="page-item' . $activeClass . '"><a class="page-link" href="' . htmlspecialchars($link) . '">' . $p . '</a></li>';
            }
            ?>
        </ul>
    </nav>

    <script>
    const baseDir = '<?php echo getBaseDir(); ?>';
    
    document.querySelectorAll('.force-logout').forEach(function(btn){
        btn.addEventListener('click', function(){
            var self = this;
            var sid = self.dataset.session;
            confirmModal('Force logout session '+sid+' ?', function() {
                fetch(baseDir + '/api/force_logout_session.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({session_id: sid})
                }).then(r=>r.json()).then(function(j){
                    if (j && j.success) {
                        var row = document.getElementById('sess-'+sid);
                        if (row) {
                            // Update Active column (11th column) to show "Logged Out"
                            var activeCell = row.querySelector('td:nth-child(11)');
                            if (activeCell) {
                                activeCell.innerHTML = '<span class="badge bg-secondary">Logged Out</span>';
                            }
                            // Update Action column (12th column) to remove button
                            var actionCell = row.querySelector('td:nth-child(12)');
                            if (actionCell) {
                                actionCell.innerHTML = '<span class="text-muted">-</span>';
                            }
                        }
                        showToast('Session terminated successfully.', 'success');
                    } else {
                        showToast('Failed: '+(j && j.error ? j.error : 'unknown'), 'danger');
                    }
                }).catch(function(e){ 
                    console.error('Force logout error:', e);
                    showToast('Request failed', 'danger'); 
                });
            });
        });
    });
        // Read more toggle for long user-agent strings
        function isOverflowing(el) {
            return el && el.scrollWidth > el.clientWidth;
        }

        function ensureUaButtons() {
            document.querySelectorAll('.ua-snippet').forEach(function(snippet){
                var cell = snippet.closest('td');
                if (!cell) return;
                var btnToggle = cell.querySelector('.ua-toggle');
                var btnCopy = cell.querySelector('.ua-copy');
                var overflowing = isOverflowing(snippet);
                if (overflowing) {
                    if (!btnToggle) {
                        btnToggle = document.createElement('button');
                        btnToggle.type = 'button';
                        btnToggle.className = 'btn btn-link btn-sm ua-toggle';
                        btnToggle.textContent = 'Read more';
                    }
                    if (!btnCopy) {
                        btnCopy = document.createElement('button');
                        btnCopy.type = 'button';
                        btnCopy.className = 'btn btn-outline-secondary btn-sm ua-copy ms-2';
                        btnCopy.title = 'Copy user-agent';
                        btnCopy.textContent = 'Copy';
                    }
                    var container = cell.querySelector('.ua-actions');
                    if (!container) {
                        container = document.createElement('div');
                        container.className = 'mt-1 ua-actions';
                        snippet.after(container);
                    }
                    if (!container.contains(btnToggle)) container.appendChild(btnToggle);
                    if (!container.contains(btnCopy)) container.appendChild(btnCopy);
                    // ensure visible
                    btnToggle.classList.remove('d-none');
                    btnCopy.classList.remove('d-none');
                } else {
                    if (btnToggle) btnToggle.classList.add('d-none');
                    if (btnCopy) btnCopy.classList.add('d-none');
                }
            });
        }

        document.addEventListener('click', function(e){
            if (e.target && e.target.classList && e.target.classList.contains('ua-toggle')) {
                var btn = e.target;
                var cell = btn.closest('td');
                if (!cell) return;
                var full = cell.querySelector('.ua-full');
                var snippet = cell.querySelector('.ua-snippet');
                if (full) {
                    if (full.classList.contains('d-none')) {
                        full.classList.remove('d-none');
                        if (snippet) snippet.classList.add('d-none');
                        btn.textContent = 'Read less';
                    } else {
                        full.classList.add('d-none');
                        if (snippet) snippet.classList.remove('d-none');
                        btn.textContent = 'Read more';
                    }
                } else if (snippet) {
                    // fallback: toggle between truncated and full title
                    var fullText = snippet.getAttribute('title') || snippet.textContent;
                    if (btn.dataset.expanded === '1') {
                        // collapse
                        snippet.textContent = fullText.substring(0, 120) + (fullText.length>120? '...':'');
                        btn.textContent = 'Read more';
                        btn.dataset.expanded = '0';
                    } else {
                        snippet.textContent = fullText;
                        btn.textContent = 'Read less';
                        btn.dataset.expanded = '1';
                    }
                }
            }
        });
        // Copy UA to clipboard
        document.addEventListener('click', function(e){
            if (e.target && e.target.classList && e.target.classList.contains('ua-copy')) {
                var btn = e.target;
                var cell = btn.closest('td');
                if (!cell) return;
                var full = cell.querySelector('.ua-full');
                var text = full ? full.textContent.trim() : (cell.querySelector('.ua-snippet') ? (cell.querySelector('.ua-snippet').getAttribute('title') || cell.querySelector('.ua-snippet').textContent) : '');
                if (!text) return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function(){
                        var old = btn.innerHTML;
                        btn.innerHTML = 'Copied';
                        setTimeout(function(){ btn.innerHTML = old; }, 1500);
                    }).catch(function(){ showToast('Copy failed', 'danger'); });
                } else {
                    // fallback
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); btn.innerHTML = 'Copied'; setTimeout(function(){ btn.innerHTML = 'Copy'; },1500);} catch(e){ showToast('Copy failed', 'danger'); }
                    document.body.removeChild(ta);
                }
            }
        });

        // run on load and on window resize to ensure buttons show when snippet is visually truncated
        window.addEventListener('load', ensureUaButtons);
        window.addEventListener('resize', function(){ setTimeout(ensureUaButtons, 150); });

        var selectAllSessions = document.getElementById('selectAllSessions');
        if (selectAllSessions) {
            selectAllSessions.addEventListener('change', function() {
                var checked = !!this.checked;
                document.querySelectorAll('input[name="session_ids[]"]').forEach(function(cb) {
                    cb.checked = checked;
                });
            });
        }

        (function() {
            var scopeType = document.getElementById('sessionScopeType');
            var userWrap = document.getElementById('sessionUserTargetWrap');
            var projectWrap = document.getElementById('sessionProjectTargetWrap');
            var userSelect = document.getElementById('sessionUserTarget');
            var projectSelect = document.getElementById('sessionProjectTarget');
            if (!scopeType || !userWrap || !projectWrap || !userSelect || !projectSelect) return;

            function syncScope() {
                if (scopeType.value === 'project') {
                    userWrap.classList.add('d-none');
                    projectWrap.classList.remove('d-none');
                    userSelect.name = '';
                    projectSelect.name = 'target_id';
                } else {
                    projectWrap.classList.add('d-none');
                    userWrap.classList.remove('d-none');
                    projectSelect.name = '';
                    userSelect.name = 'target_id';
                }
            }
            scopeType.addEventListener('change', syncScope);
            syncScope();
        })();

        document.querySelectorAll('form[data-confirm]').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                var msg = form.getAttribute('data-confirm') || 'Are you sure?';
                e.preventDefault();
                if (typeof window.confirmModal === 'function') {
                    window.confirmModal(msg, function() {
                        form.submit();
                    });
                    return;
                }
                var confirmFn = (typeof window._origConfirm === 'function') ? window._origConfirm : window.confirm;
                if (confirmFn(msg)) {
                    form.submit();
                }
            });
        });
    </script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
