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
        $idsRaw = $_POST['log_ids'] ?? [];
        $ids = [];
        if (is_array($idsRaw)) {
            foreach ($idsRaw as $id) {
                $i = (int)$id;
                if ($i > 0) {
                    $ids[] = $i;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            $_SESSION['error'] = 'No activity rows selected.';
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "DELETE FROM activity_log WHERE id IN ($placeholders) AND action IN ('login','logout')";
            $stmt = $db->prepare($sql);
            $stmt->execute($ids);
            $deleted = (int)$stmt->rowCount();
            $_SESSION['success'] = $deleted . ' login activity record(s) deleted.';
            try {
                logActivity($db, (int)$_SESSION['user_id'], 'admin_cleanup_login_activity', 'activity_log', 0, [
                    'deleted_ids_count' => count($ids),
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
        $type = trim((string)($_POST['action_type'] ?? 'all'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDate)) {
            $_SESSION['error'] = 'Please select a valid date.';
            header('Location: ' . $redirectTo);
            exit;
        }

        $params = [$beforeDate . ' 00:00:00'];
        $sql = "DELETE FROM activity_log WHERE created_at < ? AND action IN ('login','logout')";
        if ($type === 'login' || $type === 'logout') {
            $sql .= " AND action = ?";
            $params[] = $type;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $deleted = (int)$stmt->rowCount();
        $_SESSION['success'] = $deleted . ' old login activity record(s) deleted.';
        try {
            logActivity($db, (int)$_SESSION['user_id'], 'admin_purge_login_activity', 'activity_log', 0, [
                'before_date' => $beforeDate,
                'action_type' => $type,
                'deleted_rows' => $deleted
            ]);
        } catch (Throwable $_) {
            // non-fatal
        }

        header('Location: ' . $redirectTo);
        exit;
    }
}

// Filters & pagination
$params = [];
$where = [];

$q = trim($_GET['q'] ?? '');
$actionFilter = $_GET['action'] ?? 'all';
$since = trim($_GET['since'] ?? '');
$perPage = intval($_GET['per_page'] ?? 25);
if ($perPage <= 0 || $perPage > 500) $perPage = 25;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

if ($q !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR al.details LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($actionFilter === 'login' || $actionFilter === 'logout') {
    $where[] = "al.action = ?";
    $params[] = $actionFilter;
}
if ($since !== '') {
    $where[] = "al.created_at >= ?";
    $params[] = $since . ' 00:00:00';
}

$whereSql = '';
if (!empty($where)) $whereSql = 'WHERE ' . implode(' AND ', $where);

// total
$countSql = "SELECT COUNT(*) FROM activity_log al LEFT JOIN users u ON u.id = al.user_id " . $whereSql;
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT al.*, u.id AS user_id, u.full_name, u.email FROM activity_log al LEFT JOIN users u ON u.id = al.user_id " . $whereSql . " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
$paramsWithLimit = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare($sql);
$stmt->execute($paramsWithLimit);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($total / $perPage));

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container mt-4">
    <h3>Login / Logout Activity</h3>
    <p class="text-muted">Showing <?php echo $total; ?> events.</p>

    <div class="card mb-3 border-warning">
        <div class="card-body py-3">
            <div class="fw-semibold mb-2">Storage Cleanup</div>
            <form method="post" class="row g-2 align-items-end" data-confirm="Delete old login/logout records?">
                <input type="hidden" name="cleanup_action" value="purge_old">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Delete records older than</label>
                    <input type="date" name="before_date" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Action type</label>
                    <select name="action_type" class="form-select form-select-sm">
                        <option value="all">Login + Logout</option>
                        <option value="login">Only Login</option>
                        <option value="logout">Only Logout</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-danger">Purge Old Records</button>
                </div>
            </form>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-auto">
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control form-control-sm" placeholder="Search user, email, details">
        </div>
        <div class="col-auto">
            <select name="action" class="form-select form-select-sm">
                <option value="all"<?php if ($actionFilter==='all') echo ' selected'; ?>>All</option>
                <option value="login"<?php if ($actionFilter==='login') echo ' selected'; ?>>Login</option>
                <option value="logout"<?php if ($actionFilter==='logout') echo ' selected'; ?>>Logout</option>
            </select>
        </div>
        <div class="col-auto">
            <input type="date" name="since" value="<?php echo htmlspecialchars($since); ?>" class="form-control form-control-sm" title="From date">
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

    <p class="text-muted">Showing <?php echo count($rows); ?> of <?php echo $total; ?> events (Page <?php echo $page; ?> of <?php echo $totalPages; ?>).</p>

    <form method="post" data-confirm="Delete selected login activity records?">
    <input type="hidden" name="cleanup_action" value="delete_selected">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="small text-muted">Select rows and delete selected records if needed.</div>
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete Selected</button>
    </div>
    <div class="table-responsive">
    <table class="table table-striped table-sm">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllLoginLogs"></th>
                <th>When</th>
                <th>User</th>
                <th>Action</th>
                <th>IP</th>
                <th>Device / Browser</th>
                <th>Location</th>
                <th>Session ID</th>
                <th>Recent Sections</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $details = json_decode($r['details'], true) ?: [];
            $ua = $details['user_agent'] ?? '';
            $ua_parsed = $details['ua_parsed'] ?? null;
            $sessionId = $details['session_id'] ?? '';
            $ip = $r['ip_address'] ?? ($details['device_ip'] ?? '');
            $sections = $details['user_sections'] ?? [];
        ?>
            <tr>
                <td><input type="checkbox" name="log_ids[]" value="<?php echo (int)$r['id']; ?>"></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td>
                    <?php if (!empty($r['user_id'])): ?>
                        <a href="<?php echo htmlspecialchars(getBaseDir()); ?>/modules/profile.php?id=<?php echo intval($r['user_id']); ?>"><?php echo htmlspecialchars($r['full_name'] ?: 'User'); ?></a>
                    <?php else: ?>
                        <?php echo htmlspecialchars($r['full_name'] ?: 'System'); ?>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars(ucfirst($r['action'])); ?></td>
                <td><?php echo htmlspecialchars($ip); ?></td>
                <td>
                    <?php
                        $ua_full = $ua ?? '';
                        $ua_short = mb_substr($ua_full, 0, 100);
                        $ua_too_long = mb_strlen($ua_full) > 100;
                    ?>
                    <?php if ($ua_parsed): ?>
                        <?php echo htmlspecialchars($ua_parsed['platform'] . ' / ' . $ua_parsed['browser'] . ' ' . ($ua_parsed['browser_version'] ?? '')); ?>
                        <div class="small text-muted ua-snippet" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:480px;" title="<?php echo htmlspecialchars($ua_full); ?>"><?php echo htmlspecialchars($ua_short); ?><?php if ($ua_too_long) echo '...'; ?></div>
                        <?php if ($ua_too_long): ?>
                            <div class="small text-muted ua-full d-none" style="white-space:normal; word-break:break-word; margin-top:4px; max-width:480px;"> <?php echo htmlspecialchars($ua_full); ?></div>
                            <div class="mt-1">
                                <button type="button" class="btn btn-link btn-sm ua-toggle">Read more</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm ua-copy ms-2" title="Copy user-agent">Copy</button>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="ua-snippet" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:480px;" title="<?php echo htmlspecialchars($ua_full); ?>"><?php echo htmlspecialchars($ua_short); ?><?php if ($ua_too_long) echo '...'; ?></div>
                        <?php if ($ua_too_long): ?>
                            <div class="ua-full d-none" style="white-space:normal; word-break:break-word; margin-top:4px; max-width:480px;"><?php echo htmlspecialchars($ua_full); ?></div>
                            <div class="mt-1">
                                <button type="button" class="btn btn-link btn-sm ua-toggle">Read more</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm ua-copy ms-2" title="Copy user-agent">Copy</button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                        $geo = $details['geo'] ?? [];
                        // Don't call get_geo_info during page load - it's too slow
                        if (!empty($geo)) {
                            $addr = trim(($geo['city'] ?? '') . ' ' . ($geo['postal'] ?? '') . ', ' . ($geo['region'] ?? '') . ', ' . ($geo['country'] ?? ''));
                            if (!empty($geo['latitude']) && !empty($geo['longitude'])) {
                                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($geo['latitude'] . ',' . $geo['longitude']);
                            } else {
                                $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr);
                            }
                            echo htmlspecialchars($addr) . ' <a href="' . htmlspecialchars($mapUrl) . '" target="_blank" rel="noopener" class="ms-1"><i class="fas fa-map-marker-alt text-primary"></i></a>';
                        } else {
                            echo '<span class="text-muted">-</span>';
                        }
                    ?>
                </td>
                <td><code><?php echo htmlspecialchars($sessionId); ?></code></td>
                <td><?php echo htmlspecialchars(implode(' › ', array_slice($sections,0,10))); ?></td>
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
                $link = $baseUrl . '?' . ($baseQs ? ($baseQs . '&') : '') . 'page=' . $p;
                echo '<li class="page-item' . $activeClass . '"><a class="page-link" href="' . htmlspecialchars($link) . '">' . $p . '</a></li>';
            }
            ?>
        </ul>
    </nav>

</div>
<script>
// Read more toggle for long user-agent strings in login activity
function isOverflowing(el) { return el && el.scrollWidth > el.clientWidth; }

function ensureUaButtonsLogin() {
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
            var fullText = snippet.getAttribute('title') || snippet.textContent;
            if (btn.dataset.expanded === '1') {
                snippet.textContent = fullText.substring(0, 100) + (fullText.length>100? '...':'');
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
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); btn.innerHTML = 'Copied'; setTimeout(function(){ btn.innerHTML = 'Copy'; },1500);} catch(e){ showToast('Copy failed', 'danger'); }
            document.body.removeChild(ta);
        }
    }
});
window.addEventListener('load', ensureUaButtonsLogin);
window.addEventListener('resize', function(){ setTimeout(ensureUaButtonsLogin, 150); });

var selectAllLoginLogs = document.getElementById('selectAllLoginLogs');
if (selectAllLoginLogs) {
    selectAllLoginLogs.addEventListener('change', function() {
        var checked = !!this.checked;
        document.querySelectorAll('input[name="log_ids[]"]').forEach(function(cb) {
            cb.checked = checked;
        });
    });
}

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
<?php require_once __DIR__ . '/../../includes/footer.php';

?>
