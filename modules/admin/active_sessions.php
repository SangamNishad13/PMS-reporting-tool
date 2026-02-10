<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin','super_admin']);

$db = Database::getInstance();

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

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container mt-4">
    <h3>Active Sessions</h3>
    <p class="text-muted">Shows recent sessions (active & inactive). Use Force Logout to terminate a session.</p>

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

    <div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead>
            <tr>
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
    </script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
