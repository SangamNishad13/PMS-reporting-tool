<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Please login to access this resource'], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError($message, $statusCode = 400) {
    jsonResponse(['error' => $message], $statusCode);
}

function parseArrayInput($value) {
    if ($value === null) return [];
    if (is_array($value)) return array_values(array_filter($value, function($v){ return $v !== '' && $v !== null; }));
    $value = trim((string)$value);
    if ($value === '') return [];
    if ($value[0] === '[') {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter($decoded, function($v){ return $v !== '' && $v !== null; }));
        }
    }
    return array_values(array_filter(array_map('trim', explode(',', $value)), function($v){ return $v !== ''; }));
}

function getStatusId($db, $name) {
    if (!$name) return null;
    $map = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed'
    ];
    $target = $map[strtolower($name)] ?? $name;
    $stmt = $db->prepare("SELECT id FROM issue_statuses WHERE name = ? LIMIT 1");
    $stmt->execute([$target]);
    $id = $stmt->fetchColumn();
    return $id ?: null;
}

function getPriorityId($db, $name) {
    if (!$name) return null;
    $map = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
        'critical' => 'Critical'
    ];
    $target = $map[strtolower($name)] ?? $name;
    $stmt = $db->prepare("SELECT id FROM issue_priorities WHERE name = ? LIMIT 1");
    $stmt->execute([$target]);
    $id = $stmt->fetchColumn();
    return $id ?: null;
}

function replaceMeta($db, $issueId, $key, $values) {
    $db->prepare("DELETE FROM issue_metadata WHERE issue_id = ? AND meta_key = ?")->execute([$issueId, $key]);
    if (empty($values)) return;
    $ins = $db->prepare("INSERT INTO issue_metadata (issue_id, meta_key, meta_value) VALUES (?, ?, ?)");
    foreach ($values as $v) {
        $val = is_scalar($v) ? (string)$v : json_encode($v);
        if ($val === '') continue;
        $ins->execute([$issueId, $key, $val]);
    }
}

function getDefaultTypeId($db, $projectId) {
    $stmt = $db->prepare("SELECT MIN(type_id) FROM issues WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $db->query("SELECT MIN(type_id) FROM issues");
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : 1;
}

function getIssueKey($db, $projectId) {
    $proj = $db->prepare("SELECT project_code, po_number FROM projects WHERE id = ? LIMIT 1");
    $proj->execute([$projectId]);
    $row = $proj->fetch(PDO::FETCH_ASSOC);
    $prefix = $row['project_code'] ?: ($row['po_number'] ?: 'PRJ');
    $stmt = $db->prepare("SELECT issue_key FROM issues WHERE project_id = ? AND issue_key LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$projectId, $prefix . '-%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && strpos($last, '-') !== false) {
        $parts = explode('-', $last);
        $num = (int)end($parts);
        if ($num > 0) $next = $num + 1;
    }
    return $prefix . '-' . (string)$next;
}

function ensureIssuePresenceTable($db) {
    static $isReady = null;
    if ($isReady !== null) return $isReady;
    try {
        $isReady = (bool)$db->query("SHOW TABLES LIKE " . $db->quote('issue_active_editors'))->fetchColumn();
        if (!$isReady) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS issue_active_editors (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    project_id INT NOT NULL,
                    issue_id INT NOT NULL,
                    user_id INT NOT NULL,
                    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY ux_issue_active_editor (project_id, issue_id, user_id),
                    KEY idx_issue_active_last_seen (last_seen),
                    KEY idx_issue_active_issue (project_id, issue_id, last_seen)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $isReady = (bool)$db->query("SHOW TABLES LIKE " . $db->quote('issue_active_editors'))->fetchColumn();
        }
        if ($isReady) {
            $cols = [];
            $colStmt = $db->query("SHOW COLUMNS FROM issue_active_editors");
            while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[strtolower((string)$c['Field'])] = true;
            }
            if (!isset($cols['project_id'])) {
                $db->exec("ALTER TABLE issue_active_editors ADD COLUMN project_id INT NOT NULL DEFAULT 0");
            }
            if (!isset($cols['issue_id'])) {
                $db->exec("ALTER TABLE issue_active_editors ADD COLUMN issue_id INT NOT NULL DEFAULT 0");
            }
            if (!isset($cols['user_id'])) {
                $db->exec("ALTER TABLE issue_active_editors ADD COLUMN user_id INT NOT NULL DEFAULT 0");
            }
            if (!isset($cols['last_seen'])) {
                $db->exec("ALTER TABLE issue_active_editors ADD COLUMN last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }
            try {
                $db->exec("ALTER TABLE issue_active_editors ADD UNIQUE KEY ux_issue_active_editor (project_id, issue_id, user_id)");
            } catch (Exception $e) { }
        }
    } catch (Exception $e) {
        error_log('ensureIssuePresenceTable error: ' . $e->getMessage());
        try {
            $isReady = (bool)$db->query("SHOW TABLES LIKE " . $db->quote('issue_active_editors'))->fetchColumn();
        } catch (Exception $e2) {
            $isReady = false;
        }
    }
    return $isReady;
}

function normalizeIssueStatusValue($value) {
    $raw = trim((string)$value);
    if ($raw !== '' && $raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $raw = trim((string)($decoded[0] ?? ''));
        }
    }
    $v = strtolower($raw);
    if ($v === '') return '';
    $v = str_replace('-', '_', $v);
    $v = preg_replace('/\s+/', '_', $v);
    return $v;
}

function isIssueOpenStatusValue($value) {
    return normalizeIssueStatusValue($value) === 'open';
}

function isQaStatusMetaFilled($qaValues) {
    if ($qaValues === null) return false;
    $values = is_array($qaValues) ? $qaValues : [$qaValues];
    foreach ($values as $v) {
        $s = trim((string)$v);
        if ($s === '' || $s === '[]') continue;
        if ($s[0] === '[') {
            $decoded = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (trim((string)$item) !== '') return true;
                }
                continue;
            }
        }
        return true;
    }
    return false;
}

function getTesterBlockedIssueIdsForDelete(PDO $db, int $projectId, array $issueIds): array {
    if (empty($issueIds)) return [];
    $issueIds = array_values(array_unique(array_map('intval', $issueIds)));
    $issueIds = array_values(array_filter($issueIds, function($id){ return $id > 0; }));
    if (empty($issueIds)) return [];

    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $sql = "
        SELECT
            i.id,
            COALESCE(
                (
                    SELECT im_status.meta_value
                    FROM issue_metadata im_status
                    WHERE im_status.issue_id = i.id AND im_status.meta_key = 'issue_status'
                    ORDER BY im_status.id DESC
                    LIMIT 1
                ),
                s.name,
                ''
            ) AS issue_status_value,
            EXISTS (
                SELECT 1
                FROM issue_metadata im_qa
                WHERE im_qa.issue_id = i.id
                  AND im_qa.meta_key = 'qa_status'
                  AND TRIM(COALESCE(im_qa.meta_value, '')) <> ''
                  AND TRIM(COALESCE(im_qa.meta_value, '')) <> '[]'
            ) AS has_qa_status,
            EXISTS (
                SELECT 1
                FROM issue_comments ic
                WHERE ic.issue_id = i.id
            ) AS has_comments
        FROM issues i
        LEFT JOIN issue_statuses s ON s.id = i.status_id
        WHERE i.project_id = ? AND i.id IN ($placeholders)
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$projectId], $issueIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $blocked = [];
    foreach ($rows as $row) {
        $issueId = (int)$row['id'];
        $status = normalizeIssueStatusValue($row['issue_status_value'] ?? '');
        $isOpen = ($status === 'open');
        $hasQaStatus = !empty($row['has_qa_status']);
        $hasComments = !empty($row['has_comments']);

        if ($hasComments || ($isOpen && $hasQaStatus)) {
            $blocked[] = $issueId;
        }
    }
    return $blocked;
}

function ensureIssuePresenceSessionsTable($db) {
    static $isReady = null;
    if ($isReady !== null) return $isReady;
    try {
        $isReady = (bool)$db->query("SHOW TABLES LIKE " . $db->quote('issue_presence_sessions'))->fetchColumn();
        if (!$isReady) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS issue_presence_sessions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    project_id INT NOT NULL,
                    issue_id INT NOT NULL,
                    user_id INT NOT NULL,
                    session_token VARCHAR(64) NOT NULL,
                    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    closed_at DATETIME NULL,
                    duration_seconds INT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY ux_issue_presence_session_token (session_token),
                    KEY idx_issue_presence_issue (project_id, issue_id, opened_at),
                    KEY idx_issue_presence_user (user_id, opened_at),
                    KEY idx_issue_presence_open (closed_at, last_seen)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $isReady = (bool)$db->query("SHOW TABLES LIKE " . $db->quote('issue_presence_sessions'))->fetchColumn();
        }
        if ($isReady) {
            $cols = [];
            $colStmt = $db->query("SHOW COLUMNS FROM issue_presence_sessions");
            while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[strtolower((string)$c['Field'])] = true;
            }
            if (!isset($cols['session_token'])) {
                try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN session_token VARCHAR(64) NOT NULL DEFAULT ''"); } catch (Exception $e) { }
            }
            if (!isset($cols['opened_at'])) {
                try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Exception $e) { }
            }
            if (!isset($cols['last_seen'])) {
                try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Exception $e) { }
            }
            if (!isset($cols['closed_at'])) {
                try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN closed_at DATETIME NULL"); } catch (Exception $e) { }
            }
            if (!isset($cols['duration_seconds'])) {
                try { $db->exec("ALTER TABLE issue_presence_sessions ADD COLUMN duration_seconds INT NULL"); } catch (Exception $e) { }
            }
            try { $db->exec("ALTER TABLE issue_presence_sessions ADD INDEX idx_issue_presence_issue (project_id, issue_id, opened_at)"); } catch (Exception $e) { }
            try { $db->exec("ALTER TABLE issue_presence_sessions ADD INDEX idx_issue_presence_user (user_id, opened_at)"); } catch (Exception $e) { }
            try { $db->exec("ALTER TABLE issue_presence_sessions ADD INDEX idx_issue_presence_open (closed_at, last_seen)"); } catch (Exception $e) { }
            // Optional uniqueness. Ignore failures on legacy rows.
            try { $db->exec("ALTER TABLE issue_presence_sessions ADD UNIQUE KEY ux_issue_presence_session_token (session_token)"); } catch (Exception $e) { }
        }
    } catch (Exception $e) {
        error_log('ensureIssuePresenceSessionsTable error: ' . $e->getMessage());
        // Keep presence features available even if migration/index tuning fails.
        try {
            $isReady = (bool)$db->query("SHOW TABLES LIKE " . $db->quote('issue_presence_sessions'))->fetchColumn();
        } catch (Exception $e2) {
            $isReady = false;
        }
    }
    return $isReady;
}

function generatePresenceSessionToken() {
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return uniqid('iss_', true);
    }
}

function issueBelongsToProject($db, $issueId, $projectId) {
    $stmt = $db->prepare("SELECT id FROM issues WHERE id = ? AND project_id = ? LIMIT 1");
    $stmt->execute([(int)$issueId, (int)$projectId]);
    return (bool)$stmt->fetchColumn();
}

function cleanupIssuePresence($db) {
    if (!ensureIssuePresenceTable($db)) return;
    $db->exec("DELETE FROM issue_active_editors WHERE last_seen < (NOW() - INTERVAL 6 SECOND)");
}

function cleanupIssuePresenceSessions($db) {
    if (!ensureIssuePresenceSessionsTable($db)) return;
    // Auto-close stale open sessions (e.g., browser/tab closed unexpectedly).
    $db->exec("
        UPDATE issue_presence_sessions
        SET closed_at = IFNULL(closed_at, NOW()),
            duration_seconds = TIMESTAMPDIFF(SECOND, opened_at, IFNULL(closed_at, NOW()))
        WHERE closed_at IS NULL
          AND last_seen < (NOW() - INTERVAL 2 MINUTE)
    ");
}

function getIssuePresenceUsers($db, $projectId, $issueId, $excludeUserId = 0) {
    if (!ensureIssuePresenceTable($db)) return [];
    $sql = "
        SELECT p.user_id, u.full_name, p.last_seen
        FROM issue_active_editors p
        JOIN users u ON u.id = p.user_id
        WHERE p.project_id = ? AND p.issue_id = ? AND p.last_seen >= (NOW() - INTERVAL 6 SECOND)
    ";
    $params = [(int)$projectId, (int)$issueId];
    if ((int)$excludeUserId > 0) {
        $sql .= " AND p.user_id <> ? ";
        $params[] = (int)$excludeUserId;
    }
    $sql .= " ORDER BY p.last_seen DESC, u.full_name ASC ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getIssuePresenceSessions($db, $projectId, $issueId) {
    if (!ensureIssuePresenceSessionsTable($db)) return [];
    $stmt = $db->prepare("
        SELECT s.user_id, u.full_name, s.opened_at, s.closed_at, s.duration_seconds
        FROM issue_presence_sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.project_id = ? AND s.issue_id = ?
        ORDER BY s.opened_at DESC
        LIMIT 200
    ");
    $stmt->execute([(int)$projectId, (int)$issueId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);

if (!$projectId) {
    jsonError('project_id is required', 400);
}

$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? '';
$isTesterRole = in_array($userRole, ['at_tester', 'ft_tester'], true);
if (!hasProjectAccess($db, $userId, $projectId)) {
    jsonError('Permission denied', 403);
}
$canUpdateQaStatus = hasIssueQaStatusUpdateAccess($db, $userId, $projectId);

try {
    if ($method === 'GET' && $action === 'list') {
        $pageId = (int)($_GET['page_id'] ?? 0);
        $params = [$projectId];
        $sql = "SELECT DISTINCT i.*, 
                       s.name as status_name, 
                       p.name as priority_name,
                       reporter.full_name as reporter_name,
                       assignee.full_name as qa_name,
                       (SELECT COALESCE(MAX(ih.id), 0) FROM issue_history ih WHERE ih.issue_id = i.id) AS latest_history_id
                FROM issues i
                LEFT JOIN issue_statuses s ON i.status_id = s.id
                LEFT JOIN issue_priorities p ON i.priority_id = p.id
                LEFT JOIN users reporter ON i.reporter_id = reporter.id
                LEFT JOIN users assignee ON i.assignee_id = assignee.id";
        if ($pageId) {
            $sql .= " LEFT JOIN issue_metadata im ON im.issue_id = i.id AND im.meta_key = 'page_ids'";
            $sql .= " WHERE i.project_id = ? AND (i.page_id = ? OR im.meta_value = ?)";
            $params[] = $pageId;
            $params[] = (string)$pageId;
        } else {
            $sql .= " WHERE i.project_id = ?";
        }
        $sql .= " ORDER BY i.updated_at DESC, i.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $issueIds = array_map(function($r){ return (int)$r['id']; }, $issues);
        $metaMap = [];
        $commentCountMap = [];
        if (!empty($issueIds)) {
            $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
            $metaStmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders)");
            $metaStmt->execute($issueIds);
            while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
                $iid = (int)$m['issue_id'];
                if (!isset($metaMap[$iid])) $metaMap[$iid] = [];
                if (!isset($metaMap[$iid][$m['meta_key']])) $metaMap[$iid][$m['meta_key']] = [];
                $metaMap[$iid][$m['meta_key']][] = $m['meta_value'];
            }

            $commentStmt = $db->prepare("SELECT issue_id, COUNT(*) AS c FROM issue_comments WHERE issue_id IN ($placeholders) GROUP BY issue_id");
            $commentStmt->execute($issueIds);
            while ($c = $commentStmt->fetch(PDO::FETCH_ASSOC)) {
                $commentCountMap[(int)$c['issue_id']] = (int)$c['c'];
            }
        }

        $out = [];
        foreach ($issues as $i) {
            $iid = (int)$i['id'];
            $meta = $metaMap[$iid] ?? [];
            $pages = $meta['page_ids'] ?? [];
            if (empty($pages) && !empty($i['page_id'])) $pages = [(string)$i['page_id']];
            $statusValue = ($meta['issue_status'][0] ?? strtolower(str_replace(' ', '_', $i['status_name'] ?? '')));
            $qaStatusValues = ($meta['qa_status'] ?? []);
            $hasComments = (($commentCountMap[$iid] ?? 0) > 0);
            $isOpen = isIssueOpenStatusValue($statusValue);
            $hasQaStatus = isQaStatusMetaFilled($qaStatusValues);
            $canTesterDelete = (!$hasComments && !($isOpen && $hasQaStatus));
            
            // Extract severity and priority as simple strings (not arrays or JSON)
            $severity = 'medium';
            if (isset($meta['severity'])) {
                if (is_array($meta['severity'])) {
                    // Get first value from array
                    $severity = $meta['severity'][0] ?? 'medium';
                    // If it's still JSON encoded, decode it
                    if (is_string($severity) && $severity[0] === '[') {
                        $decoded = json_decode($severity, true);
                        if (is_array($decoded)) {
                            $severity = $decoded[0] ?? 'medium';
                        }
                    }
                } else {
                    $severity = $meta['severity'];
                }
            } elseif (!empty($i['severity'])) {
                $severity = $i['severity'];
            }
            // Ensure it's a clean string
            $severity = is_string($severity) ? trim($severity) : 'medium';
            
            $priority = 'medium';
            if (isset($meta['priority'])) {
                if (is_array($meta['priority'])) {
                    // Get first value from array
                    $priority = $meta['priority'][0] ?? 'medium';
                    // If it's still JSON encoded, decode it
                    if (is_string($priority) && $priority[0] === '[') {
                        $decoded = json_decode($priority, true);
                        if (is_array($decoded)) {
                            $priority = $decoded[0] ?? 'medium';
                        }
                    }
                } else {
                    $priority = $meta['priority'];
                }
            } elseif (!empty($i['priority_name'])) {
                $priority = strtolower(str_replace(' ', '_', $i['priority_name']));
            }
            // Ensure it's a clean string
            $priority = is_string($priority) ? trim($priority) : 'medium';
            
            $out[] = [
                'id' => $iid,
                'issue_key' => $i['issue_key'] ?? '',
                'project_id' => (int)$i['project_id'],
                'page_id' => $i['page_id'],
                'title' => $i['title'],
                'description' => $i['description'],
                'status' => $statusValue,
                'status_id' => (int)$i['status_id'],
                'qa_status' => $qaStatusValues, // Return as array for multi-select
                'has_comments' => $hasComments,
                'can_tester_delete' => $canTesterDelete,
                'severity' => $severity,
                'priority' => $priority,
                'pages' => $pages,
                'grouped_urls' => ($meta['grouped_urls'] ?? []),
                'reporters' => ($meta['reporter_ids'] ?? []),
                'reporter_name' => $i['reporter_name'] ?? null,
                'qa_name' => $i['qa_name'] ?? null,
                // Return all metadata fields with their actual keys from issue_metadata table
                'usersaffected' => ($meta['usersaffected'] ?? []),
                'wcagsuccesscriteria' => ($meta['wcagsuccesscriteria'] ?? []),
                'wcagsuccesscriterianame' => ($meta['wcagsuccesscriterianame'] ?? []),
                'wcagsuccesscriterialevel' => ($meta['wcagsuccesscriterialevel'] ?? []),
                'gigw30' => ($meta['gigw30'] ?? []),
                'is17802' => ($meta['is17802'] ?? []),
                'common_title' => ($meta['common_title'][0] ?? ''),
                'created_at' => $i['created_at'],
                'updated_at' => $i['updated_at'],
                'latest_history_id' => (int)($i['latest_history_id'] ?? 0)
            ];
        }

        jsonResponse(['success' => true, 'issues' => $out]);
    }
    
    if ($method === 'GET' && $action === 'get_all') {
        // Fetch all issues for the project with complete information
        $sql = "SELECT DISTINCT i.*, 
                       s.name as status_name,
                       s.color as status_color,
                       reporter.full_name as reporter_name
                FROM issues i
                LEFT JOIN issue_statuses s ON i.status_id = s.id
                LEFT JOIN users reporter ON i.reporter_id = reporter.id
                WHERE i.project_id = ?
                ORDER BY i.updated_at DESC, i.id DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$projectId]);
        $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all metadata
        $issueIds = array_map(function($r){ return (int)$r['id']; }, $issues);
        $metaMap = [];
        $pageMap = [];
        $qaStatusMap = [];
        
        if (!empty($issueIds)) {
            // Fetch metadata
            $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
            $metaStmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders)");
            $metaStmt->execute($issueIds);
            while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
                $iid = (int)$m['issue_id'];
                if (!isset($metaMap[$iid])) $metaMap[$iid] = [];
                // Store as array to handle multiple values per key
                if (!isset($metaMap[$iid][$m['meta_key']])) {
                    $metaMap[$iid][$m['meta_key']] = [];
                }
                $metaMap[$iid][$m['meta_key']][] = $m['meta_value'];
            }
            
            // Fetch page names
            $pageStmt = $db->prepare("
                SELECT ip.issue_id, pp.id, pp.page_name, pp.page_number
                FROM issue_pages ip
                INNER JOIN project_pages pp ON ip.page_id = pp.id
                WHERE ip.issue_id IN ($placeholders)
            ");
            $pageStmt->execute($issueIds);
            while ($p = $pageStmt->fetch(PDO::FETCH_ASSOC)) {
                $iid = (int)$p['issue_id'];
                if (!isset($pageMap[$iid])) $pageMap[$iid] = [];
                $pageMap[$iid][] = [
                    'id' => (int)$p['id'],
                    'name' => $p['page_name'],
                    'number' => $p['page_number']
                ];
            }
        }
        
        // Fetch QA status master for labels
        $qaStatusStmt = $db->query("SELECT status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1");
        $qaStatusMaster = [];
        while ($qs = $qaStatusStmt->fetch(PDO::FETCH_ASSOC)) {
            $qaStatusMaster[$qs['status_key']] = [
                'label' => $qs['status_label'],
                'color' => $qs['badge_color']
            ];
        }

        $out = [];
        foreach ($issues as $i) {
            $iid = (int)$i['id'];
            $meta = $metaMap[$iid] ?? [];
            
            // Get page info
            $pages = $pageMap[$iid] ?? [];
            $pageNames = array_map(function($p) { return $p['number'] . ' - ' . $p['name']; }, $pages);
            $pageIds = array_map(function($p) { return $p['id']; }, $pages);
            
            // If no pages from issue_pages, try metadata
            if (empty($pages) && isset($meta['page_ids'])) {
                $metaPageIds = $meta['page_ids'];
                // Handle both array and string formats
                if (is_array($metaPageIds)) {
                    // Already an array, just filter
                    $pageIds = array_values(array_filter(array_map('intval', $metaPageIds)));
                } else {
                    // String format, try JSON decode first
                    $decoded = json_decode($metaPageIds, true);
                    if (is_array($decoded)) {
                        $pageIds = array_values(array_filter(array_map('intval', $decoded)));
                    } else {
                        $pageIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $metaPageIds)))));
                    }
                }
                
                if (!empty($pageIds)) {
                    $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
                    // Only fetch pages that actually exist
                    $pageStmt = $db->prepare("SELECT id, page_name, page_number FROM project_pages WHERE id IN ($placeholders)");
                    $pageStmt->execute($pageIds);
                    $pageData = $pageStmt->fetchAll(PDO::FETCH_ASSOC);
                    $pageNames = array_map(function($p) { return $p['page_number'] . ' - ' . $p['page_name']; }, $pageData);
                    // Update pageIds to only include existing pages
                    $pageIds = array_map(function($p) { return (int)$p['id']; }, $pageData);
                }
            }
            
            // Get QA statuses with labels
            $qaStatuses = [];
            $qaStatusKeys = [];
            if (isset($meta['qa_status'])) {
                $qaStatusData = $meta['qa_status'];
                // Handle both array and string formats
                if (is_array($qaStatusData)) {
                    // If it's an array with one JSON string, decode it
                    if (count($qaStatusData) === 1 && is_string($qaStatusData[0]) && $qaStatusData[0][0] === '[') {
                        $decoded = json_decode($qaStatusData[0], true);
                        $qaStatusKeys = is_array($decoded) ? $decoded : $qaStatusData;
                    } else {
                        $qaStatusKeys = $qaStatusData;
                    }
                } else {
                    // String format
                    $decoded = json_decode($qaStatusData, true);
                    if (is_array($decoded)) {
                        $qaStatusKeys = $decoded;
                    } else {
                        $qaStatusKeys = array_filter(array_map('trim', explode(',', $qaStatusData)));
                    }
                }
                
                foreach ($qaStatusKeys as $key) {
                    if (isset($qaStatusMaster[$key])) {
                        $qaStatuses[] = [
                            'key' => $key,
                            'label' => $qaStatusMaster[$key]['label'],
                            'color' => $qaStatusMaster[$key]['color']
                        ];
                    }
                }
            }
            
            // Get all reporters
            $reporters = [];
            $reporterIds = [];
            if (!empty($i['reporter_name'])) {
                $reporters[] = $i['reporter_name'];
                $reporterIds[] = (int)$i['reporter_id'];
            }
            
            if (isset($meta['reporter_ids'])) {
                $reporterIdsData = $meta['reporter_ids'];
                // Handle both array and string formats
                if (is_array($reporterIdsData)) {
                    $additionalReporterIds = array_values(array_filter(array_map('intval', $reporterIdsData)));
                } else {
                    $decoded = json_decode($reporterIdsData, true);
                    if (is_array($decoded)) {
                        $additionalReporterIds = array_values(array_filter(array_map('intval', $decoded)));
                    } else {
                        $additionalReporterIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $reporterIdsData)))));
                    }
                }
                
                if (!empty($additionalReporterIds)) {
                    $placeholders = implode(',', array_fill(0, count($additionalReporterIds), '?'));
                    $reporterStmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders)");
                    $reporterStmt->execute($additionalReporterIds);
                    while ($r = $reporterStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!in_array($r['full_name'], $reporters)) {
                            $reporters[] = $r['full_name'];
                            $reporterIds[] = (int)$r['id'];
                        }
                    }
                }
            }
            
            $out[] = [
                'id' => $iid,
                'issue_key' => $i['issue_key'] ?? '',
                'title' => $i['title'],
                'description' => $i['description'],
                'common_title' => $meta['common_title'] ?? '',
                'status_id' => (int)$i['status_id'],
                'status_name' => $i['status_name'] ?? '',
                'status_color' => $i['status_color'] ?? '#6c757d',
                'pages' => implode(', ', $pageNames),
                'page_ids' => $pageIds,
                'qa_statuses' => $qaStatuses,
                'qa_status_keys' => $qaStatusKeys,
                'reporters' => implode(', ', $reporters),
                'reporter_ids' => $reporterIds,
                'severity' => isset($meta['severity']) ? (is_array($meta['severity']) ? $meta['severity'][0] : $meta['severity']) : 'medium',
                'priority' => isset($meta['priority']) ? (is_array($meta['priority']) ? $meta['priority'][0] : $meta['priority']) : 'medium',
                'grouped_urls' => isset($meta['grouped_urls']) && is_array($meta['grouped_urls']) ? $meta['grouped_urls'] : [],
                'metadata' => $meta, // Include all metadata for custom fields
                'created_at' => $i['created_at'],
                'updated_at' => $i['updated_at']
            ];
        }

        jsonResponse(['success' => true, 'issues' => $out]);
    }

    if ($method === 'GET' && $action === 'presence_list') {
        $issueId = (int)($_GET['issue_id'] ?? 0);
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (!ensureIssuePresenceTable($db)) {
            jsonResponse(['success' => true, 'users' => []]);
        }
        cleanupIssuePresence($db);
        $users = getIssuePresenceUsers($db, $projectId, $issueId, $userId);
        jsonResponse(['success' => true, 'users' => $users]);
    }

    if ($method === 'GET' && $action === 'presence_session_list') {
        $issueId = (int)($_GET['issue_id'] ?? 0);
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (!ensureIssuePresenceSessionsTable($db)) {
            jsonResponse(['success' => true, 'sessions' => []]);
        }
        cleanupIssuePresenceSessions($db);
        $sessions = getIssuePresenceSessions($db, $projectId, $issueId);
        jsonResponse(['success' => true, 'sessions' => $sessions]);
    }

    if ($method === 'POST' && $action === 'presence_open_session') {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (!ensureIssuePresenceSessionsTable($db)) {
            jsonResponse(['success' => true, 'session_token' => '']);
        }
        cleanupIssuePresenceSessions($db);

        $sessionToken = generatePresenceSessionToken();
        $ins = $db->prepare("
            INSERT INTO issue_presence_sessions
                (project_id, issue_id, user_id, session_token, opened_at, last_seen)
            VALUES
                (?, ?, ?, ?, NOW(), NOW())
        ");
        $ins->execute([(int)$projectId, (int)$issueId, (int)$userId, $sessionToken]);

        jsonResponse(['success' => true, 'session_token' => $sessionToken]);
    }

    if ($method === 'POST' && $action === 'presence_ping') {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        $sessionToken = trim((string)($_POST['session_token'] ?? ''));
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (!ensureIssuePresenceTable($db)) {
            jsonResponse(['success' => true, 'users' => []]);
        }
        cleanupIssuePresence($db);
        $db->prepare("DELETE FROM issue_active_editors WHERE project_id = ? AND issue_id = ? AND last_seen < (NOW() - INTERVAL 6 SECOND)")
            ->execute([(int)$projectId, (int)$issueId]);

        $upsert = $db->prepare("
            INSERT INTO issue_active_editors (project_id, issue_id, user_id, last_seen)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE project_id = VALUES(project_id), last_seen = NOW()
        ");
        $upsert->execute([(int)$projectId, (int)$issueId, (int)$userId]);

        if ($sessionToken !== '') {
            if (ensureIssuePresenceSessionsTable($db)) {
                $touch = $db->prepare("
                    UPDATE issue_presence_sessions
                    SET last_seen = NOW()
                    WHERE session_token = ? AND project_id = ? AND issue_id = ? AND user_id = ? AND closed_at IS NULL
                ");
                $touch->execute([$sessionToken, (int)$projectId, (int)$issueId, (int)$userId]);
            }
        }

        $users = getIssuePresenceUsers($db, $projectId, $issueId, $userId);
        jsonResponse(['success' => true, 'users' => $users]);
    }

    if ($method === 'POST' && $action === 'presence_leave') {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        $sessionToken = trim((string)($_POST['session_token'] ?? ''));
        if (!$issueId) jsonError('issue_id is required', 400);
        if (!issueBelongsToProject($db, $issueId, $projectId)) jsonError('Issue not found', 404);

        if (ensureIssuePresenceTable($db)) {
            $del = $db->prepare("DELETE FROM issue_active_editors WHERE project_id = ? AND issue_id = ? AND user_id = ?");
            $del->execute([(int)$projectId, (int)$issueId, (int)$userId]);
        }

        if (ensureIssuePresenceSessionsTable($db)) {
            if ($sessionToken !== '') {
                $close = $db->prepare("
                    UPDATE issue_presence_sessions
                    SET closed_at = NOW(),
                        duration_seconds = TIMESTAMPDIFF(SECOND, opened_at, NOW()),
                        last_seen = NOW()
                    WHERE session_token = ? AND project_id = ? AND issue_id = ? AND user_id = ? AND closed_at IS NULL
                ");
                $close->execute([$sessionToken, (int)$projectId, (int)$issueId, (int)$userId]);
            } else {
                $closeAny = $db->prepare("
                    UPDATE issue_presence_sessions
                    SET closed_at = NOW(),
                        duration_seconds = TIMESTAMPDIFF(SECOND, opened_at, NOW()),
                        last_seen = NOW()
                    WHERE project_id = ? AND issue_id = ? AND user_id = ? AND closed_at IS NULL
                ");
                $closeAny->execute([(int)$projectId, (int)$issueId, (int)$userId]);
            }
        }
        jsonResponse(['success' => true]);
    }

    if ($method === 'POST' && ($action === 'create' || $action === 'update')) {
        $id = (int)($_POST['id'] ?? 0);
        $expectedUpdatedAt = trim((string)($_POST['expected_updated_at'] ?? ''));
        $expectedHistoryId = isset($_POST['expected_history_id']) ? (int)$_POST['expected_history_id'] : null;
        $title = trim($_POST['title'] ?? '');
        if (!$title) jsonError('title is required', 400);
        $description = $_POST['description'] ?? '';
        $pageIds = parseArrayInput($_POST['pages'] ?? []);
        $pageId = (int)($_POST['page_id'] ?? 0);
        if (!$pageId && !empty($pageIds)) $pageId = (int)$pageIds[0];

        error_log('issues api: title=' . $title . ', pageId=' . $pageId);

        $statusId = null;
        $statusInput = $_POST['issue_status'] ?? '';
        if (is_numeric($statusInput)) {
            // Direct ID provided
            $statusId = (int)$statusInput;
        } else {
            // Name provided, convert to ID
            $statusId = getStatusId($db, $statusInput);
        }
        $statusId = null;
        $statusInput = $_POST['issue_status'] ?? '';
        if (is_numeric($statusInput)) {
            // Direct ID provided
            $statusId = (int)$statusInput;
        } else {
            // Name provided, convert to ID
            $statusId = getStatusId($db, $statusInput);
        }
        if (!$statusId) $statusId = getStatusId($db, 'Open');
        $reporters = parseArrayInput($_POST['reporters'] ?? []);
        $reporterId = !empty($reporters) ? (int)$reporters[0] : (int)$userId;
        $priorityId = getPriorityId($db, $_POST['priority'] ?? 'medium');
        if (!$priorityId) $priorityId = getPriorityId($db, 'Medium');
        $typeId = getDefaultTypeId($db, $projectId);
        $issueKey = getIssueKey($db, $projectId);
        $severity = $_POST['severity'] ?? 'major';
        $commonTitle = trim($_POST['common_title'] ?? '');

        if ($action === 'create') {
            $stmt = $db->prepare("INSERT INTO issues (project_id, issue_key, title, description, type_id, priority_id, status_id, reporter_id, page_id, severity, is_final, common_issue_title) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
            $stmt->execute([$projectId, $issueKey, $title, $description, $typeId, $priorityId, $statusId, $reporterId, $pageId ?: null, $severity, $commonTitle ?: null]);
            $id = (int)$db->lastInsertId();
        } else {
            if (!$id) jsonError('id is required', 400);
            
            // --- HISTORY LOGGING ---
            // Fetch current state
            $oldStmt = $db->prepare("SELECT * FROM issues WHERE id = ?");
            $oldStmt->execute([$id]);
            $oldIssue = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldIssue) jsonError('Issue not found', 404);

            if ($expectedUpdatedAt !== '' && !empty($oldIssue['updated_at']) && $expectedUpdatedAt !== (string)$oldIssue['updated_at']) {
                jsonResponse([
                    'error' => 'This issue was modified by another user. Please reload latest data and try again.',
                    'conflict' => true,
                    'current_updated_at' => (string)$oldIssue['updated_at']
                ], 409);
            }

            if ($expectedHistoryId !== null) {
                $histStmt = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM issue_history WHERE issue_id = ?");
                $histStmt->execute([$id]);
                $currentHistoryId = (int)$histStmt->fetchColumn();
                if ($currentHistoryId !== $expectedHistoryId) {
                    jsonResponse([
                        'error' => 'This issue was modified by another user. Please reload latest data and try again.',
                        'conflict' => true,
                        'current_history_id' => $currentHistoryId
                    ], 409);
                }
            }

            $oldMetaStmt = $db->prepare("SELECT meta_key, meta_value FROM issue_metadata WHERE issue_id = ? ORDER BY id ASC");
            $oldMetaStmt->execute([$id]);
            $oldMeta = [];
            while ($m = $oldMetaStmt->fetch(PDO::FETCH_ASSOC)) {
                $k = (string)$m['meta_key'];
                if (!isset($oldMeta[$k])) $oldMeta[$k] = [];
                $oldMeta[$k][] = (string)$m['meta_value'];
            }

            function logHistory($db, $issueId, $userId, $field, $oldVal, $newVal) {
                if ($oldVal === $newVal) return;
                $stmt = $db->prepare("INSERT INTO issue_history (issue_id, user_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$issueId, $userId, $field, $oldVal, $newVal]);
            }

            logHistory($db, $id, $userId, 'title', $oldIssue['title'], $title);
            logHistory($db, $id, $userId, 'description', $oldIssue['description'], $description);
            logHistory($db, $id, $userId, 'severity', $oldIssue['severity'], $severity);
            logHistory($db, $id, $userId, 'common_issue_title', $oldIssue['common_issue_title'], $commonTitle ?: null);
            // More fields could be logged if needed (status, priority etc)

            $stmt = $db->prepare("UPDATE issues SET title = ?, description = ?, priority_id = ?, status_id = ?, reporter_id = ?, page_id = ?, severity = ?, common_issue_title = ?, updated_at = NOW() WHERE id = ? AND project_id = ?");
            $stmt->execute([$title, $description, $priorityId, $statusId, $reporterId, $pageId ?: null, $severity, $commonTitle ?: null, $id, $projectId]);
        }

        function normalizeHistoryMetaValues($values, $allowCsv = false) {
            $out = [];
            $push = function($v) use (&$out) {
                if ($v === null) return;
                $s = trim((string)$v);
                if ($s === '') return;
                $out[] = $s;
            };

            $walk = function($input) use (&$walk, $push, $allowCsv) {
                if ($input === null) return;
                if (is_array($input)) {
                    foreach ($input as $v) $walk($v);
                    return;
                }
                $raw = trim((string)$input);
                if ($raw === '') return;

                if ($raw[0] === '[') {
                    $decoded = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        foreach ($decoded as $v) $walk($v);
                        return;
                    }
                }

                if ($allowCsv && strpos($raw, ',') !== false) {
                    foreach (explode(',', $raw) as $part) $push($part);
                    return;
                }

                $push($raw);
            };

            $walk($values);
            $out = array_values(array_unique(array_filter($out, function($v){ return $v !== ''; })));
            sort($out, SORT_NATURAL | SORT_FLAG_CASE);
            return $out;
        }

        function handleMetaHistory($db, $issueId, $userId, $key, $newValues, $oldMeta) {
            $multiKeys = ['qa_status', 'page_ids', 'reporter_ids', 'grouped_urls'];
            $allowCsv = in_array($key, $multiKeys, true);
            $oldVals = normalizeHistoryMetaValues($oldMeta[$key] ?? [], $allowCsv);
            $newVals = normalizeHistoryMetaValues($newValues, $allowCsv);

            if ($oldVals === $newVals) return;

            $oldVal = implode(', ', $oldVals);
            $newVal = implode(', ', $newVals);
            $stmt = $db->prepare("INSERT INTO issue_history (issue_id, user_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$issueId, $userId, "meta:$key", $oldVal, $newVal]);
        }

        if ($action === 'update') {
            handleMetaHistory($db, $id, $userId, 'issue_status', $_POST['issue_status'] ?? '', $oldMeta);
            if ($canUpdateQaStatus) {
                handleMetaHistory($db, $id, $userId, 'qa_status', $_POST['qa_status'] ?? '', $oldMeta);
            }
            handleMetaHistory($db, $id, $userId, 'page_ids', $pageIds, $oldMeta);
            handleMetaHistory($db, $id, $userId, 'reporter_ids', $reporters, $oldMeta);
            // Add other meta fields as needed
        }

        replaceMeta($db, $id, 'issue_status', [$_POST['issue_status'] ?? '']);
        
        // Handle QA status as array (multi-select)
        $qaStatusInput = $_POST['qa_status'] ?? [];
        if (is_string($qaStatusInput) && !empty($qaStatusInput)) {
            // If it's a JSON string, decode it
            if ($qaStatusInput[0] === '[') {
                $parsed = json_decode($qaStatusInput, true);
                if (is_array($parsed)) {
                    $qaStatusInput = $parsed;
                } else {
                    $qaStatusInput = [$qaStatusInput];
                }
            } else {
                $qaStatusInput = [$qaStatusInput];
            }
        } elseif (!is_array($qaStatusInput)) {
            $qaStatusInput = [];
        }
        if (!$canUpdateQaStatus && !empty($qaStatusInput)) {
            jsonError('You do not have permission to update QA status for this project.', 403);
        }
        if ($canUpdateQaStatus) {
            replaceMeta($db, $id, 'qa_status', $qaStatusInput);
        }
        
        replaceMeta($db, $id, 'page_ids', $pageIds);
        replaceMeta($db, $id, 'grouped_urls', parseArrayInput($_POST['grouped_urls'] ?? []));
        replaceMeta($db, $id, 'reporter_ids', $reporters);
        replaceMeta($db, $id, 'common_title', [trim($_POST['common_title'] ?? '')]);
        
        // Update issue_pages junction table
        if (!empty($pageIds)) {
            // Delete existing entries
            $db->prepare("DELETE FROM issue_pages WHERE issue_id = ?")->execute([$id]);
            
            // Insert new entries
            $insertPageStmt = $db->prepare("INSERT INTO issue_pages (issue_id, page_id) VALUES (?, ?)");
            foreach ($pageIds as $pid) {
                $insertPageStmt->execute([$id, (int)$pid]);
            }
        }

        // Handle dynamic metadata from POST for both create and update
        if (isset($_POST['metadata'])) {
            $metadata = json_decode($_POST['metadata'], true);
            error_log('Received metadata JSON: ' . $_POST['metadata']);
            if (is_array($metadata)) {
                error_log('Metadata is array with ' . count($metadata) . ' keys: ' . implode(', ', array_keys($metadata)));
                foreach ($metadata as $key => $value) {
                    if ($action === 'update') {
                        handleMetaHistory($db, $id, $userId, $key, $value, $oldMeta);
                    }
                    $valueArray = is_array($value) ? $value : [$value];
                    error_log("Saving metadata: key=$key, values=" . implode(',', $valueArray));
                    replaceMeta($db, $id, $key, $valueArray);
                }
            } else {
                error_log('Metadata is not an array after json_decode');
            }
        } else {
            error_log('No metadata in POST');
        }

        if ($commonTitle && count($pageIds) > 1) {
            $stmt = $db->prepare("SELECT id FROM common_issues WHERE issue_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $cid = $stmt->fetchColumn();
            if ($cid) {
                $up = $db->prepare("UPDATE common_issues SET title = ?, updated_at = NOW() WHERE id = ?");
                $up->execute([$commonTitle, $cid]);
            } else {
                $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title, created_by) VALUES (?, ?, ?, ?)");
                $ins->execute([$projectId, $id, $commonTitle, $userId]);
            }
        } else {
            $db->prepare("DELETE FROM common_issues WHERE issue_id = ?")->execute([$id]);
        }

        jsonResponse(['success' => true, 'id' => $id, 'issue_key' => $issueKey]);
    }

    if ($method === 'POST' && $action === 'delete') {
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : array_filter(array_map('intval', explode(',', $idsRaw)));
        if (empty($ids)) jsonError('ids required', 400);

        if ($isTesterRole) {
            $blockedIds = getTesterBlockedIssueIdsForDelete($db, $projectId, $ids);
            if (!empty($blockedIds)) {
                jsonResponse([
                    'error' => 'Testers can delete only when QA status is empty and no comments exist on the issue.',
                    'blocked_issue_ids' => $blockedIds
                ], 403);
            }
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$projectId]);

        $db->beginTransaction();
        try {
            // Remove dependent rows first to avoid FK failures.
            $delMeta = $db->prepare("DELETE FROM issue_metadata WHERE issue_id IN ($placeholders)");
            $delMeta->execute($ids);

            $delCommon = $db->prepare("DELETE FROM common_issues WHERE issue_id IN ($placeholders) AND project_id = ?");
            $delCommon->execute($params);

            $stmt = $db->prepare("DELETE FROM issues WHERE id IN ($placeholders) AND project_id = ?");
            $stmt->execute($params);

            $db->commit();
            jsonResponse(['success' => true, 'deleted' => $stmt->rowCount()]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    if ($method === 'GET' && $action === 'common_list') {
        $stmt = $db->prepare("
            SELECT ci.id as common_id, ci.title as common_title, i.*, s.name AS status_name
            FROM common_issues ci
            JOIN issues i ON ci.issue_id = i.id
            LEFT JOIN issue_statuses s ON s.id = i.status_id
            WHERE ci.project_id = ?
            ORDER BY ci.updated_at DESC, ci.id DESC
        ");
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $issueIds = array_map(function($r){ return (int)$r['id']; }, $rows);
        $metaMap = [];
        $commentCountMap = [];
        if (!empty($issueIds)) {
            $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
            $metaStmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders)");
            $metaStmt->execute($issueIds);
            while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
                $iid = (int)$m['issue_id'];
                if (!isset($metaMap[$iid])) $metaMap[$iid] = [];
                if (!isset($metaMap[$iid][$m['meta_key']])) $metaMap[$iid][$m['meta_key']] = [];
                $metaMap[$iid][$m['meta_key']][] = $m['meta_value'];
            }
            $commentStmt = $db->prepare("SELECT issue_id, COUNT(*) AS c FROM issue_comments WHERE issue_id IN ($placeholders) GROUP BY issue_id");
            $commentStmt->execute($issueIds);
            while ($c = $commentStmt->fetch(PDO::FETCH_ASSOC)) {
                $commentCountMap[(int)$c['issue_id']] = (int)$c['c'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $iid = (int)$r['id'];
            $meta = $metaMap[$iid] ?? [];
            $pages = $meta['page_ids'] ?? [];
            $statusValue = ($meta['issue_status'][0] ?? strtolower(str_replace(' ', '_', $r['status_name'] ?? '')));
            $qaStatusValues = ($meta['qa_status'] ?? []);
            $hasComments = (($commentCountMap[$iid] ?? 0) > 0);
            $isOpen = isIssueOpenStatusValue($statusValue);
            $hasQaStatus = isQaStatusMetaFilled($qaStatusValues);
            $canTesterDelete = (!$hasComments && !($isOpen && $hasQaStatus));
            $out[] = [
                'id' => (int)$r['common_id'],
                'issue_id' => $iid,
                'title' => $r['common_title'] ?: $r['title'],
                'description' => $r['description'],
                'pages' => $pages,
                'status' => $statusValue,
                'qa_status' => $qaStatusValues,
                'has_comments' => $hasComments,
                'can_tester_delete' => $canTesterDelete
            ];
        }
        jsonResponse(['success' => true, 'common' => $out]);
    }

    if ($method === 'POST' && ($action === 'common_create' || $action === 'common_update')) {
        $commonId = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if (!$title) jsonError('title is required', 400);
        $description = $_POST['description'] ?? '';
        $pageIds = parseArrayInput($_POST['pages'] ?? []);
        $statusId = getStatusId($db, 'Open');
        $priorityId = getPriorityId($db, 'Medium');
        $typeId = getDefaultTypeId($db, $projectId);
        $issueKey = getIssueKey($db, $projectId);
        $severity = 'major';

        if ($action === 'common_create') {
            $stmt = $db->prepare("INSERT INTO issues (project_id, issue_key, title, description, type_id, priority_id, status_id, reporter_id, severity, is_final, common_issue_title) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
            $stmt->execute([$projectId, $issueKey, $title, $description, $typeId, $priorityId, $statusId, $userId, $severity, $title]);
            $issueId = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title, created_by) VALUES (?, ?, ?, ?)");
            $ins->execute([$projectId, $issueId, $title, $userId]);
            $commonId = (int)$db->lastInsertId();
        } else {
            if (!$commonId) jsonError('id is required', 400);
            $stmt = $db->prepare("SELECT issue_id FROM common_issues WHERE id = ? AND project_id = ?");
            $stmt->execute([$commonId, $projectId]);
            $issueId = (int)$stmt->fetchColumn();
            if (!$issueId) jsonError('Common issue not found', 404);
            $upIssue = $db->prepare("UPDATE issues SET title = ?, description = ?, common_issue_title = ?, updated_at = NOW() WHERE id = ? AND project_id = ?");
            $upIssue->execute([$title, $description, $title, $issueId, $projectId]);
            $up = $db->prepare("UPDATE common_issues SET title = ?, updated_at = NOW() WHERE id = ?");
            $up->execute([$title, $commonId]);
        }

        replaceMeta($db, $issueId, 'page_ids', $pageIds);
        replaceMeta($db, $issueId, 'common_title', [$title]);

        jsonResponse(['success' => true, 'id' => $commonId, 'issue_id' => $issueId]);
    }

    if ($method === 'POST' && $action === 'common_delete') {
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : array_filter(array_map('intval', explode(',', $idsRaw)));
        if (empty($ids)) jsonError('ids required', 400);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT issue_id FROM common_issues WHERE id IN ($placeholders) AND project_id = ?");
        $stmt->execute(array_merge($ids, [$projectId]));
        $issueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($isTesterRole && !empty($issueIds)) {
            $issueIds = array_map('intval', $issueIds);
            $blockedIds = getTesterBlockedIssueIdsForDelete($db, $projectId, $issueIds);
            if (!empty($blockedIds)) {
                jsonResponse([
                    'error' => 'Testers can delete only when QA status is empty and no comments exist on the issue.',
                    'blocked_issue_ids' => $blockedIds
                ], 403);
            }
        }

        $del = $db->prepare("DELETE FROM common_issues WHERE id IN ($placeholders) AND project_id = ?");
        $del->execute(array_merge($ids, [$projectId]));
        if (!empty($issueIds)) {
            $issueIds = array_map('intval', $issueIds);
            $ph = implode(',', array_fill(0, count($issueIds), '?'));
            $db->prepare("DELETE FROM issues WHERE id IN ($ph) AND project_id = ?")->execute(array_merge($issueIds, [$projectId]));
        }
        jsonResponse(['success' => true]);
    }

    jsonError('Invalid action', 400);
} catch (Exception $e) {
    jsonError('Server error', 500);
}
