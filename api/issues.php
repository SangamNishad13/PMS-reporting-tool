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

// Handle session refresh requests to prevent timeout during active use
if (isset($_SERVER['HTTP_X_SESSION_REFRESH']) && $_SERVER['HTTP_X_SESSION_REFRESH'] === '1') {
    // Update last activity time to prevent session timeout
    $_SESSION['last_activity'] = time();
    
    // Update session record in database
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ? AND user_id = ?");
        $stmt->execute([session_id(), $_SESSION['user_id']]);
    } catch (Exception $e) {
        // Non-fatal, continue processing
        error_log("Session refresh failed: " . $e->getMessage());
    }
}

// Dedicated error log for issues API.
$issuesApiLogDir = __DIR__ . '/../tmp/logs';
$issuesApiLogFile = $issuesApiLogDir . '/issues_api.log';
$issuesApiLogConfigured = false;
if (is_dir($issuesApiLogDir) || @mkdir($issuesApiLogDir, 0775, true)) {
    $issuesApiLogConfigured = @ini_set('log_errors', '1') !== false;
    $issuesApiLogConfigured = (@ini_set('error_log', $issuesApiLogFile) !== false) || $issuesApiLogConfigured;
}
if (!$issuesApiLogConfigured) {
    // Fallback when host blocks ini_set or tmp/logs is not writable.
    $issuesApiLogFile = __DIR__ . '/issues_api.log';
    @ini_set('log_errors', '1');
    @ini_set('error_log', $issuesApiLogFile);
}
register_shutdown_function(function () {
    $fatal = error_get_last();
    if (!$fatal) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (in_array((int)$fatal['type'], $fatalTypes, true)) {
        error_log('issues api fatal: ' . ($fatal['message'] ?? '') . ' in ' . ($fatal['file'] ?? '') . ':' . ($fatal['line'] ?? '0'));
    }
});

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
    static $cache = [];
    $map = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed'
    ];
    $target = $map[strtolower($name)] ?? $name;
    if (isset($cache[$target])) return $cache[$target];
    $stmt = $db->prepare("SELECT id FROM issue_statuses WHERE name = ? LIMIT 1");
    $stmt->execute([$target]);
    $id = $stmt->fetchColumn();
    $cache[$target] = $id ?: null;
    return $cache[$target];
}

function getPriorityId($db, $name) {
    if (!$name) return null;
    static $cache = [];
    $map = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
        'critical' => 'Critical'
    ];
    $target = $map[strtolower($name)] ?? $name;
    if (isset($cache[$target])) return $cache[$target];
    $stmt = $db->prepare("SELECT id FROM issue_priorities WHERE name = ? LIMIT 1");
    $stmt->execute([$target]);
    $id = $stmt->fetchColumn();
    $cache[$target] = $id ?: null;
    return $cache[$target];
}

function replaceMeta($db, $issueId, $key, $values) {
    $db->prepare("DELETE FROM issue_metadata WHERE issue_id = ? AND meta_key = ?")->execute([$issueId, $key]);
    if (empty($values)) return;
    // Batch insert all values in a single query instead of N individual inserts
    $rows = [];
    $params = [];
    foreach ($values as $v) {
        $val = is_scalar($v) ? (string)$v : json_encode($v);
        if ($val === '') continue;
        $rows[] = '(?, ?, ?)';
        $params[] = $issueId;
        $params[] = $key;
        $params[] = $val;
    }
    if (empty($rows)) return;
    $db->prepare("INSERT INTO issue_metadata (issue_id, meta_key, meta_value) VALUES " . implode(',', $rows))->execute($params);
}

/**
 * Flush all pending meta replacements in a single DELETE + batch INSERT per key.
 * Call flushMetaBatch() after all replaceMeta calls inside a transaction.
 */
$_metaBatch = [];
function queueMeta($issueId, $key, $values) {
    global $_metaBatch;
    $_metaBatch[] = ['issue_id' => $issueId, 'key' => $key, 'values' => $values];
}
function flushMetaBatch($db) {
    global $_metaBatch;
    if (empty($_metaBatch)) return;
    // Group by issue_id+key, collect all rows
    $deleteMap = [];
    $insertRows = [];
    $insertParams = [];
    foreach ($_metaBatch as $item) {
        $deleteMap[$item['issue_id']][] = $item['key'];
        foreach ((array)$item['values'] as $v) {
            $val = is_scalar($v) ? (string)$v : json_encode($v);
            if ($val === '') continue;
            $insertRows[] = '(?, ?, ?)';
            $insertParams[] = $item['issue_id'];
            $insertParams[] = $item['key'];
            $insertParams[] = $val;
        }
    }
    // Batch deletes grouped by issue_id
    foreach ($deleteMap as $issueId => $keys) {
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $db->prepare("DELETE FROM issue_metadata WHERE issue_id = ? AND meta_key IN ($ph)")
           ->execute(array_merge([$issueId], $keys));
    }
    if (!empty($insertRows)) {
        $db->prepare("INSERT INTO issue_metadata (issue_id, meta_key, meta_value) VALUES " . implode(',', $insertRows))
           ->execute($insertParams);
    }
    $_metaBatch = [];
}

function ensureIssueReporterQaStatusTable($db) {
    static $isReady = null;
    if ($isReady !== null) return $isReady;
    try {
        // Check if table exists first to avoid implicit commit from CREATE TABLE
        $exists = $db->query("SHOW TABLES LIKE 'issue_reporter_qa_status'")->fetchColumn();
        if ($exists) {
            $isReady = true;
            return true;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS issue_reporter_qa_status (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                issue_id INT NOT NULL,
                reporter_user_id INT NOT NULL,
                qa_status_key VARCHAR(100) NOT NULL,
                set_by_user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_issue_reporter (issue_id, reporter_user_id),
                KEY idx_issue_id (issue_id),
                KEY idx_reporter_user_id (reporter_user_id),
                KEY idx_qa_status_key (qa_status_key),
                CONSTRAINT fk_irqs_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
                CONSTRAINT fk_irqs_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_irqs_set_by FOREIGN KEY (set_by_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $isReady = true;
    } catch (Exception $e) {
        error_log('ensureIssueReporterQaStatusTable error: ' . $e->getMessage());
        $isReady = false;
    }
    return $isReady;
}

function parseReporterQaStatusMapInput($value) {
    $map = [];
    if ($value === null) return $map;
    if (is_string($value)) {
        $raw = trim($value);
        if ($raw === '') return $map;
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $value = $decoded;
        } else {
            return $map;
        }
    }
    if (!is_array($value)) return $map;
    foreach ($value as $reporterId => $statusValue) {
        $rid = (int)$reporterId;
        if ($rid <= 0) continue;
        $statusKeys = [];
        if (is_array($statusValue)) {
            $statusKeys = $statusValue;
        } elseif (is_string($statusValue)) {
            $raw = trim($statusValue);
            if ($raw !== '' && $raw[0] === '[') {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $statusKeys = $decoded;
                } else {
                    $statusKeys = [$statusValue];
                }
            } elseif (strpos($raw, ',') !== false) {
                $statusKeys = array_map('trim', explode(',', $raw));
            } else {
                $statusKeys = [$statusValue];
            }
        } elseif ($statusValue !== null) {
            $statusKeys = [(string)$statusValue];
        }
        $statusKeys = array_values(array_unique(array_filter(array_map(static function($v){
            return strtolower(trim((string)$v));
        }, $statusKeys), static function($v){
            return $v !== '';
        })));
        if (empty($statusKeys)) continue;
        $map[$rid] = $statusKeys;
    }
    return $map;
}

function parseReporterQaStatusMapFromMetaValues($metaValues) {
    if (!is_array($metaValues) || empty($metaValues)) return [];
    foreach ($metaValues as $value) {
        $map = parseReporterQaStatusMapInput($value);
        if (!empty($map)) return $map;
    }
    return [];
}

function normalizeReporterQaStatusMap($map, $reporterIds, $validStatusKeys = []) {
    $allowedReporterIds = [];
    foreach ($reporterIds as $rid) {
        $rid = (int)$rid;
        if ($rid > 0) $allowedReporterIds[$rid] = true;
    }
    $validStatusLookup = [];
    foreach ($validStatusKeys as $key) {
        $k = strtolower(trim((string)$key));
        if ($k !== '') $validStatusLookup[$k] = true;
    }

    $normalized = [];
    foreach ($map as $rid => $statusValues) {
        $rid = (int)$rid;
        if ($rid <= 0) continue;
        if (!isset($allowedReporterIds[$rid])) continue;
        $keys = is_array($statusValues) ? $statusValues : [$statusValues];
        $keys = array_values(array_unique(array_filter(array_map(static function($v){
            return strtolower(trim((string)$v));
        }, $keys), static function($v){
            return $v !== '';
        })));
        if (!empty($validStatusLookup)) {
            $keys = array_values(array_filter($keys, static function($key) use ($validStatusLookup){
                return isset($validStatusLookup[$key]);
            }));
        }
        if (empty($keys)) continue;
        $normalized[$rid] = $keys;
    }
    return $normalized;
}

function loadReporterQaStatusMapByIssueIds($db, $issueIds) {
    $result = [];
    if (empty($issueIds)) return $result;
    if (!ensureIssueReporterQaStatusTable($db)) return $result;

    $issueIds = array_values(array_filter(array_map('intval', $issueIds), function($v){ return $v > 0; }));
    if (empty($issueIds)) return $result;
    $ph = implode(',', array_fill(0, count($issueIds), '?'));
    $stmt = $db->prepare("SELECT issue_id, reporter_user_id, qa_status_key FROM issue_reporter_qa_status WHERE issue_id IN ($ph)");
    $stmt->execute($issueIds);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $iid = (int)$row['issue_id'];
        $rid = (int)$row['reporter_user_id'];
        $raw = trim((string)$row['qa_status_key']);
        if ($iid <= 0 || $rid <= 0 || $raw === '') continue;
        $statusKeys = [];
        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $statusKeys = $decoded;
            }
        }
        if (empty($statusKeys)) {
            $statusKeys = strpos($raw, ',') !== false ? explode(',', $raw) : [$raw];
        }
        $statusKeys = array_values(array_unique(array_filter(array_map(static function($v){
            return strtolower(trim((string)$v));
        }, $statusKeys), static function($v){
            return $v !== '';
        })));
        if (empty($statusKeys)) continue;
        if (!isset($result[$iid])) $result[$iid] = [];
        $result[$iid][$rid] = $statusKeys;
    }
    return $result;
}

function persistIssueReporterQaStatuses($db, $issueId, $map, $actorUserId) {
    if (!ensureIssueReporterQaStatusTable($db)) return false;
    $issueId = (int)$issueId;
    $actorUserId = (int)$actorUserId;
    if ($issueId <= 0) return false;

    $db->prepare("DELETE FROM issue_reporter_qa_status WHERE issue_id = ?")->execute([$issueId]);
    if (empty($map)) return true;

    $ins = $db->prepare("
        INSERT INTO issue_reporter_qa_status (issue_id, reporter_user_id, qa_status_key, set_by_user_id)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($map as $rid => $statusValues) {
        $rid = (int)$rid;
        if ($rid <= 0) continue;
        $statusKeys = is_array($statusValues) ? $statusValues : [$statusValues];
        $statusKeys = array_values(array_unique(array_filter(array_map(static function($v){
            return strtolower(trim((string)$v));
        }, $statusKeys), static function($v){
            return $v !== '';
        })));
        if (empty($statusKeys)) continue;
        $statusKeyCsv = implode(',', $statusKeys);
        $ins->execute([$issueId, $rid, $statusKeyCsv, $actorUserId > 0 ? $actorUserId : $rid]);
    }
    return true;
}

function getDefaultTypeId($db, $projectId) {
    $stmt = $db->prepare("SELECT MIN(type_id) FROM issues WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $db->query("SELECT MIN(type_id) FROM issues");
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $db->query("SELECT MIN(id) FROM issue_types");
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : 0;
}

function getIssueKey($db, $projectId) {
    $proj = $db->prepare("SELECT project_code, po_number FROM projects WHERE id = ? LIMIT 1");
    $proj->execute([$projectId]);
    $row = $proj->fetch(PDO::FETCH_ASSOC);
    $prefix = $row['project_code'] ?: ($row['po_number'] ?: 'PRJ');
    // Use MAX(id) filtered by prefix — faster than ORDER BY id DESC LIMIT 1 on large tables
    $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(issue_key, '-', -1) AS UNSIGNED)) FROM issues WHERE issue_key LIKE ?");
    $stmt->execute([$prefix . '-%']);
    $maxNum = (int)$stmt->fetchColumn();
    return $prefix . '-' . ($maxNum + 1);
}

function getAnyStatusId($db) {
    $stmt = $db->query("SELECT id FROM issue_statuses ORDER BY id ASC LIMIT 1");
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function columnExists($db, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($column));
        $cache[$key] = $stmt && $stmt->rowCount() > 0;
    }
    return $cache[$key];
}


function ensureIssuePresenceTable($db) {
    static $isReady = null;
    if ($isReady !== null) return $isReady;
    try {
        // Check if table exists first to avoid implicit commit from CREATE TABLE
        $exists = $db->query("SHOW TABLES LIKE 'issue_active_editors'")->fetchColumn();
        if ($exists) {
            $isReady = true;
        } else {
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
            $isReady = (bool)$db->query("SHOW TABLES LIKE 'issue_active_editors'")->fetchColumn();
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

function getTesterBlockedIssueIdsForDelete($db, $projectId, $issueIds) {
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

function collectIssueDeleteHtmlBlocks($db, $projectId, $issueIds) {
    $issueIds = array_values(array_unique(array_map('intval', $issueIds)));
    $issueIds = array_values(array_filter($issueIds, function ($id) { return $id > 0; }));
    if (empty($issueIds)) return [];

    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $params = array_merge($issueIds, [$projectId]);
    $blocks = [];

    $issueStmt = $db->prepare("SELECT description FROM issues WHERE id IN ($placeholders) AND project_id = ?");
    $issueStmt->execute($params);
    while ($row = $issueStmt->fetch(PDO::FETCH_ASSOC)) {
        $html = (string)($row['description'] ?? '');
        if (trim($html) !== '') $blocks[] = $html;
    }

    $commentStmt = $db->prepare("
        SELECT ic.comment_html
        FROM issue_comments ic
        INNER JOIN issues i ON i.id = ic.issue_id
        WHERE ic.issue_id IN ($placeholders) AND i.project_id = ?
    ");
    $commentStmt->execute($params);
    while ($row = $commentStmt->fetch(PDO::FETCH_ASSOC)) {
        $html = (string)($row['comment_html'] ?? '');
        if (trim($html) !== '') $blocks[] = $html;
    }

    return $blocks;
}

function cleanupIssueUploadsFromHtmlBlocks($htmlBlocks) {
    if (!function_exists('delete_local_upload_files_from_html')) return;
    foreach ($htmlBlocks as $html) {
        delete_local_upload_files_from_html((string)$html, ['uploads/issues/', 'uploads/chat/']);
    }
}

function ensureIssuePresenceSessionsTable($db) {
    static $isReady = null;
    if ($isReady !== null) return $isReady;
    try {
        // Check if table exists first to avoid implicit commit from CREATE TABLE
        $exists = $db->query("SHOW TABLES LIKE 'issue_presence_sessions'")->fetchColumn();
        if ($exists) {
            $isReady = true;
        } else {
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
            $isReady = (bool)$db->query("SHOW TABLES LIKE 'issue_presence_sessions'")->fetchColumn();
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
    if (function_exists('random_bytes')) {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $e) {
            // Fallback below.
        }
    }
    return md5(uniqid('iss_', true) . mt_rand());
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

// Add comprehensive error handling wrapper
set_error_handler(function($severity, $message, $file, $line) {
    // Don't handle fatal errors here
    if (!(error_reporting() & $severity)) {
        return false;
    }
    
    // Log the error
    error_log("Issues API Error: $message in $file:$line");
    
    // For database-related errors, provide more helpful messages
    if (strpos($message, 'Unknown column') !== false) {
        error_log("Database schema mismatch detected. This may indicate a missing database migration.");
    }
    
    return false; // Let PHP handle the error normally
});

// Add database connection error handling
try {
    $db = Database::getInstance();
    
    $db->setAttribute(PDO::ATTR_TIMEOUT, 30);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (Exception $e) {
    error_log("Issues API: Database connection failed: " . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Database connection failed', 'message' => 'Please try again later'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);

// Handle health check requests
if ($action === 'health_check' && isset($_SERVER['HTTP_X_HEALTH_CHECK'])) {
    http_response_code(200);
    echo json_encode(['status' => 'healthy', 'timestamp' => time()], JSON_UNESCAPED_UNICODE);
    exit;
}

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

// Handle image deletion
if ($method === 'POST' && $action === 'delete_image') {
    $imagePath = $_POST['image_path'] ?? '';
    if (!$imagePath) {
        jsonError('image_path is required', 400);
    }
    
    // Security: Only allow deletion of files in assets/uploads/
    if (strpos($imagePath, '/assets/uploads/') === false) {
        jsonError('Invalid image path', 400);
    }
    
    // Convert to absolute path
    $basePath = dirname(__DIR__);
    $fullPath = $basePath . $imagePath;
    
    // Check if file exists and delete it
    if (file_exists($fullPath)) {
        if (@unlink($fullPath)) {
            jsonResponse(['success' => true, 'message' => 'Image deleted']);
        } else {
            jsonError('Failed to delete image file', 500);
        }
    } else {
        // File doesn't exist, consider it already deleted
        jsonResponse(['success' => true, 'message' => 'Image already deleted']);
    }
}

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
        
        // Filter for client role - only show client_ready issues
        if ($userRole === 'client') {
            $sql .= " AND i.client_ready = 1";
        }
        
        $orderByClause = columnExists($db, 'issues', 'issue_key') ? "ORDER BY i.issue_key ASC" : "ORDER BY i.id ASC";
        $sql .= " $orderByClause";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $issueIds = array_map(function($r){ return (int)$r['id']; }, $issues);
        $metaMap = [];
        $commentCountMap = [];
        $reporterQaStatusByIssue = [];
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
            $reporterQaStatusByIssue = loadReporterQaStatusMapByIssueIds($db, $issueIds);
        }

        $out = [];
        foreach ($issues as $i) {
            $iid = (int)$i['id'];
            $meta = $metaMap[$iid] ?? [];
            $pages = $meta['page_ids'] ?? [];
            if (empty($pages) && !empty($i['page_id'])) $pages = [(string)$i['page_id']];
            $statusValue = ($meta['issue_status'][0] ?? strtolower(str_replace(' ', '_', $i['status_name'] ?? '')));
            $qaStatusValues = ($meta['qa_status'] ?? []);
            $reporterQaStatusMap = $reporterQaStatusByIssue[$iid] ?? [];
            if (empty($reporterQaStatusMap) && isset($meta['reporter_qa_status_map'])) {
                $reporterQaStatusMap = parseReporterQaStatusMapFromMetaValues($meta['reporter_qa_status_map']);
            }
            $hasComments = (($commentCountMap[$iid] ?? 0) > 0);
            $isOpen = isIssueOpenStatusValue($statusValue);
            $hasQaStatus = !empty($reporterQaStatusMap) || isQaStatusMetaFilled($qaStatusValues);
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
            
            $rowOut = [
                'id' => $iid,
                'issue_key' => $i['issue_key'] ?? 'ISS-' . $iid, // Fallback if column doesn't exist
                'project_id' => (int)$i['project_id'],
                'page_id' => $i['page_id'],
                'title' => $i['title'],
                'description' => $i['description'],
                'status' => $statusValue,
                'status_id' => (int)$i['status_id'],
                'qa_status' => $qaStatusValues, // Return as array for multi-select
                'reporter_qa_status_map' => $reporterQaStatusMap,
                'has_comments' => $hasComments,
                'can_tester_delete' => $canTesterDelete,
                'severity' => $severity,
                'priority' => $priority,
                'pages' => $pages,
                'grouped_urls' => ($meta['grouped_urls'] ?? []),
                'reporters' => ($meta['reporter_ids'] ?? []),
                'reporter_name' => $i['reporter_name'] ?? null,
                'assignee_id' => (int)($i['assignee_id'] ?? 0) ?: null,
                'assignee_ids' => isset($meta['assignee_ids']) ? array_values(array_filter(array_map('intval', $meta['assignee_ids']), function($v){ return $v > 0; })) : ((int)($i['assignee_id'] ?? 0) ? [(int)$i['assignee_id']] : []),
                'qa_name' => $i['qa_name'] ?? null,
                'client_ready' => (int)($i['client_ready'] ?? 0),
                'created_at' => $i['created_at'],
                'updated_at' => $i['updated_at'],
                'latest_history_id' => (int)($i['latest_history_id'] ?? 0)
            ];
            // Add all metadata fields dynamically
            foreach ($meta as $metaKey => $metaVals) {
                if (!array_key_exists($metaKey, $rowOut)) {
                    // Handle common_title as string, others as arrays
                    if ($metaKey === 'common_title') {
                        $rowOut[$metaKey] = is_array($metaVals) ? ($metaVals[0] ?? '') : $metaVals;
                    } else {
                        $rowOut[$metaKey] = $metaVals;
                    }
                }
            }
            $out[] = $rowOut;
        }

        jsonResponse(['success' => true, 'issues' => $out]);
    }
    
    if ($method === 'GET' && $action === 'get_all') {
        // Cache key based on project + role (client sees filtered set)
        $cacheKey = "issues_all_{$projectId}_" . ($userRole === 'client' ? 'client' : 'staff');
        $cacheTtl = 120; // 2 minutes

        // Try APCu first (in-process, zero network overhead)
        $cached = false;
        if (function_exists('apcu_fetch')) {
            $data = apcu_fetch($cacheKey, $cached);
            if ($cached) {
                jsonResponse(['success' => true, 'issues' => $data, 'cached' => true]);
            }
        }

        // Fetch all issues for the project with complete information
        $sql = "SELECT DISTINCT i.*, 
                       s.name as status_name,
                       s.color as status_color,
                       reporter.full_name as reporter_name,
                       (SELECT COALESCE(MAX(ih.id), 0) FROM issue_history ih WHERE ih.issue_id = i.id) AS latest_history_id
                FROM issues i
                LEFT JOIN issue_statuses s ON i.status_id = s.id
                LEFT JOIN users reporter ON i.reporter_id = reporter.id
                WHERE i.project_id = ?";
        
        // Filter for client role - only show client_ready issues
        if ($userRole === 'client') {
            $sql .= " AND i.client_ready = 1";
        }
        
        $sql .= " ORDER BY i.issue_key ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$projectId]);
        $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all metadata
        $issueIds = array_map(function($r){ return (int)$r['id']; }, $issues);
        $metaMap = [];
        $pageMap = [];
        $qaStatusMap = [];
        $reporterQaStatusByIssue = [];
        
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
            $reporterQaStatusByIssue = loadReporterQaStatusMapByIssueIds($db, $issueIds);
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
            $reporterQaStatusMap = $reporterQaStatusByIssue[$iid] ?? [];
            if (empty($reporterQaStatusMap) && isset($meta['reporter_qa_status_map'])) {
                $reporterQaStatusMap = parseReporterQaStatusMapFromMetaValues($meta['reporter_qa_status_map']);
            }
            
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
            if (!empty($reporterQaStatusMap)) {
                foreach ($reporterQaStatusMap as $statusValues) {
                    $vals = is_array($statusValues) ? $statusValues : [$statusValues];
                    foreach ($vals as $statusKey) {
                        $key = strtolower(trim((string)$statusKey));
                        if ($key !== '') $qaStatusKeys[] = $key;
                    }
                }
                $qaStatusKeys = array_values(array_unique($qaStatusKeys));
            }
            if (empty($qaStatusKeys) && isset($meta['qa_status'])) {
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
                'issue_key' => $i['issue_key'] ?? 'ISS-' . $iid, // Fallback if column doesn't exist
                'title' => $i['title'],
                'description' => $i['description'],
                'common_title' => isset($meta['common_title']) && is_array($meta['common_title']) ? $meta['common_title'][0] : ($meta['common_title'] ?? ''),
                'status_id' => (int)$i['status_id'],
                'status_name' => $i['status_name'] ?? '',
                'status_color' => $i['status_color'] ?? '#6c757d',
                'pages' => implode(', ', $pageNames),
                'page_ids' => $pageIds,
                'qa_statuses' => $qaStatuses,
                'qa_status_keys' => $qaStatusKeys,
                'reporter_qa_status_map' => $reporterQaStatusMap,
                'reporters' => implode(', ', $reporters),
                'reporter_ids' => $reporterIds,
                'assignee_id' => (int)($i['assignee_id'] ?? 0) ?: null,
                'assignee_ids' => isset($meta['assignee_ids']) ? array_values(array_filter(array_map('intval', $meta['assignee_ids']), function($v){ return $v > 0; })) : ((int)($i['assignee_id'] ?? 0) ? [(int)$i['assignee_id']] : []),
                'severity' => isset($meta['severity']) ? (is_array($meta['severity']) ? $meta['severity'][0] : $meta['severity']) : 'medium',
                'priority' => isset($meta['priority']) ? (is_array($meta['priority']) ? $meta['priority'][0] : $meta['priority']) : 'medium',
                'grouped_urls' => isset($meta['grouped_urls']) && is_array($meta['grouped_urls']) ? $meta['grouped_urls'] : [],
                'client_ready' => (int)($i['client_ready'] ?? 0),
                'metadata' => $meta, // Include all metadata for custom fields
                'created_at' => $i['created_at'],
                'updated_at' => $i['updated_at'],
                'latest_history_id' => (int)($i['latest_history_id'] ?? 0)
            ];
        }

        // Store in APCu cache for next requests
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $out, $cacheTtl);
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

        // debug: error_log('issues api: title=' . $title . ', pageId=' . $pageId);

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
        if (!$statusId) $statusId = getAnyStatusId($db);
        if (!$statusId) jsonError('Issue statuses are not configured.', 500);
        $reporters = parseArrayInput($_POST['reporters'] ?? []);
        $reporters = array_values(array_filter(array_map('intval', $reporters), function($v){ return $v > 0; }));
        $reporterId = !empty($reporters) ? (int)$reporters[0] : (int)$userId;
        $qaStatusMasterRows = [];
        try {
            $qaStatusMasterRows = $db->query("SELECT status_key FROM qa_status_master WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $qaStatusMasterRows = [];
        }
        $validQaStatusKeys = array_values(array_filter(array_map(static function($v) {
            return strtolower(trim((string)$v));
        }, (array)$qaStatusMasterRows)));
        $priorityId = getPriorityId($db, $_POST['priority'] ?? 'medium');
        if (!$priorityId) $priorityId = getPriorityId($db, 'Medium');
        if (!$priorityId) $priorityId = getAnyPriorityId($db);
        if (!$priorityId) jsonError('Issue priorities are not configured.', 500);
        $typeId = getDefaultTypeId($db, $projectId);
        if (!$typeId) jsonError('Issue types are not configured.', 500);
        $issueKey = '';
        $commonTitle = trim($_POST['common_title'] ?? '');
        $clientReady = (int)($_POST['client_ready'] ?? 0);
        $severity = trim($_POST['severity'] ?? 'medium');
        // assignee_ids: multi-select QA names — stored in metadata; first ID also goes to assignee_id column
        $assigneeIdsRaw = parseArrayInput($_POST['assignee_ids'] ?? ($_POST['assignee_id'] ?? []));
        $assigneeIds = array_values(array_filter(array_map('intval', $assigneeIdsRaw), function($v){ return $v > 0; }));
        $assigneeId = !empty($assigneeIds) ? $assigneeIds[0] : null;

        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
            }

            if ($action === 'create') {
                $stmt = $db->prepare("INSERT INTO issues (project_id, issue_key, title, description, type_id, priority_id, status_id, reporter_id, assignee_id, page_id, severity, is_final, common_issue_title, client_ready) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
                $created = false;
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $issueKey = getIssueKey($db, $projectId);
                    try {
                        $stmt->execute([$projectId, $issueKey, $title, $description, $typeId, $priorityId, $statusId, $reporterId, $assigneeId, $pageId ?: null, $severity, $commonTitle ?: null, $clientReady]);
                        $id = (int)$db->lastInsertId();
                        $created = true;
                        break;
                    } catch (PDOException $pe) {
                        // Retry only on unique issue_key race/collision.
                        $isDuplicate = ((int)($pe->errorInfo[1] ?? 0) === 1062);
                        $isIssueKeyDup = stripos((string)$pe->getMessage(), 'issue_key') !== false;
                        if (!($isDuplicate && $isIssueKeyDup)) {
                            throw $pe;
                        }
                    }
                }
                if (!$created) {
                    throw new RuntimeException('Unable to generate a unique issue key. Please retry.');
                }
            } else {
                if (!$id) {
                    if ($db->inTransaction()) $db->rollBack();
                    jsonError('id is required', 400);
                }
                
                // --- HISTORY LOGGING ---
                // Fetch current state
                $oldStmt = $db->prepare("SELECT * FROM issues WHERE id = ? FOR UPDATE");
                $oldStmt->execute([$id]);
                $oldIssue = $oldStmt->fetch(PDO::FETCH_ASSOC);
                if (!$oldIssue) {
                    if ($db->inTransaction()) $db->rollBack();
                    jsonError('Issue not found', 404);
                }

                if ($expectedUpdatedAt !== '' && !empty($oldIssue['updated_at']) && $expectedUpdatedAt !== (string)$oldIssue['updated_at']) {
                    if ($db->inTransaction()) $db->rollBack();
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
                        if ($db->inTransaction()) $db->rollBack();
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

                // DETECT CHANGES
                $hasChanged = false;
                if ($oldIssue['title'] !== $title) $hasChanged = true;
                if ($oldIssue['description'] !== $description) $hasChanged = true;
                if ((int)$oldIssue['priority_id'] !== (int)$priorityId) $hasChanged = true;
                if ((int)$oldIssue['status_id'] !== (int)$statusId) $hasChanged = true;
                if ((int)$oldIssue['reporter_id'] !== (int)$reporterId) $hasChanged = true;
                if ($oldIssue['page_id'] != ($pageId ?: null)) $hasChanged = true;
                if ($oldIssue['severity'] !== $severity) $hasChanged = true;
                if ($oldIssue['common_issue_title'] !== ($commonTitle ?: null)) $hasChanged = true;
                if ((int)($oldIssue['client_ready'] ?? 0) !== $clientReady) $hasChanged = true;
                if ((int)($oldIssue['assignee_id'] ?? 0) !== (int)($assigneeId ?? 0)) $hasChanged = true;

                function logHistory($db, $issueId, $userId, $field, $oldVal, $newVal) {
                    if ($oldVal === $newVal) return;
                    $stmt = $db->prepare("INSERT INTO issue_history (issue_id, user_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$issueId, $userId, $field, $oldVal, $newVal]);
                }

                logHistory($db, $id, $userId, 'title', $oldIssue['title'], $title);
                logHistory($db, $id, $userId, 'description', $oldIssue['description'], $description);
                logHistory($db, $id, $userId, 'severity', $oldIssue['severity'], $severity);
                logHistory($db, $id, $userId, 'common_issue_title', $oldIssue['common_issue_title'], $commonTitle ?: null);
                logHistory($db, $id, $userId, 'client_ready', $oldIssue['client_ready'] ?? 0, $clientReady);
                logHistory($db, $id, $userId, 'assignee_id', $oldIssue['assignee_id'] ?? null, $assigneeId);

                if ($hasChanged) {
                    $stmt = $db->prepare("UPDATE issues SET title = ?, description = ?, priority_id = ?, status_id = ?, reporter_id = ?, assignee_id = ?, page_id = ?, severity = ?, common_issue_title = ?, client_ready = ?, updated_at = NOW() WHERE id = ? AND project_id = ?");
                } else {
                    $stmt = $db->prepare("UPDATE issues SET title = ?, description = ?, priority_id = ?, status_id = ?, reporter_id = ?, assignee_id = ?, page_id = ?, severity = ?, common_issue_title = ?, client_ready = ? WHERE id = ? AND project_id = ?");
                }
                $stmt->execute([$title, $description, $priorityId, $statusId, $reporterId, $assigneeId, $pageId ?: null, $severity, $commonTitle ?: null, $clientReady, $id, $projectId]);
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
            $multiKeys = ['qa_status', 'page_ids', 'reporter_ids', 'grouped_urls', 'reporter_qa_status_map'];
            $allowCsv = in_array($key, $multiKeys, true);
            $oldVals = normalizeHistoryMetaValues($oldMeta[$key] ?? [], $allowCsv);
            $newVals = normalizeHistoryMetaValues($newValues, $allowCsv);

            if ($oldVals === $newVals) return;

            $oldVal = implode(', ', $oldVals);
            $newVal = implode(', ', $newVals);
            $stmt = $db->prepare("INSERT INTO issue_history (issue_id, user_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$issueId, $userId, "meta:$key", $oldVal, $newVal]);
        }

        // For update operations, check if QA status is actually being changed
        // NOTE: Must initialize before the history logging block below uses it.
        $isActuallyUpdatingQaStatus = false;

        if ($action === 'update') {
            handleMetaHistory($db, $id, $userId, 'issue_status', $_POST['issue_status'] ?? '', $oldMeta);
            // Only track QA status history if user has permission and is actually updating QA status
            if ($canUpdateQaStatus && $isActuallyUpdatingQaStatus) {
                handleMetaHistory($db, $id, $userId, 'qa_status', $_POST['qa_status'] ?? '', $oldMeta);
                handleMetaHistory($db, $id, $userId, 'reporter_qa_status_map', $_POST['reporter_qa_status_map'] ?? '', $oldMeta);
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
        $qaStatusInput = array_values(array_filter(array_map(static function($v) {
            return strtolower(trim((string)$v));
        }, $qaStatusInput), static function($v) {
            return $v !== '';
        }));

        $reporterQaStatusMapInput = parseReporterQaStatusMapInput($_POST['reporter_qa_status_map'] ?? null);
        $reporterQaStatusMap = normalizeReporterQaStatusMap($reporterQaStatusMapInput, $reporters, $validQaStatusKeys);

        // For update operations, check if QA status is actually being changed
        $isActuallyUpdatingQaStatus = false;
        
        // If user doesn't have QA permission, don't check for QA status changes at all
        if (!$canUpdateQaStatus) {
            $isActuallyUpdatingQaStatus = false;
        } else if ($action === 'update' && $id > 0) {
            // Get existing QA status values for comparison
            $existingQaStatusStmt = $db->prepare("SELECT meta_value FROM issue_metadata WHERE issue_id = ? AND meta_key = 'qa_status'");
            $existingQaStatusStmt->execute([$id]);
            $existingQaStatusValues = $existingQaStatusStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $existingQaStatusNormalized = array_values(array_filter(array_map(static function($v) {
                return strtolower(trim((string)$v));
            }, $existingQaStatusValues), static function($v) {
                return $v !== '';
            }));
            
            // Get existing reporter QA status map
            $existingReporterQaMapStmt = $db->prepare("SELECT meta_value FROM issue_metadata WHERE issue_id = ? AND meta_key = 'reporter_qa_status_map'");
            $existingReporterQaMapStmt->execute([$id]);
            $existingReporterQaMapValues = $existingReporterQaMapStmt->fetchAll(PDO::FETCH_COLUMN);
            $existingReporterQaMap = [];
            if (!empty($existingReporterQaMapValues)) {
                $existingReporterQaMap = parseReporterQaStatusMapFromMetaValues($existingReporterQaMapValues);
            }
            
            // Compare current values with new values to see if they're actually changing
            sort($qaStatusInput);
            sort($existingQaStatusNormalized);
            $qaStatusChanged = (json_encode($qaStatusInput) !== json_encode($existingQaStatusNormalized));
            $reporterQaMapChanged = (json_encode($reporterQaStatusMap) !== json_encode($existingReporterQaMap));
            
            $isActuallyUpdatingQaStatus = $qaStatusChanged || $reporterQaMapChanged;
        } else {
            // For create operations, check if QA status data is being provided
            $isActuallyUpdatingQaStatus = !empty($qaStatusInput) || !empty($reporterQaStatusMap);
        }

        // Only check QA permissions if user is actually trying to change QA status
        if (!$canUpdateQaStatus && $isActuallyUpdatingQaStatus) {
            jsonError('You do not have permission to update QA status for this project.', 403);
        }
        // Only process QA status updates if user has permission and is actually updating QA status
        if ($canUpdateQaStatus && $isActuallyUpdatingQaStatus) {
            if (empty($reporterQaStatusMap) && !empty($qaStatusInput) && !empty($reporters)) {
                // Backward-compatible behavior: if only global QA status is sent, apply selected statuses to all selected reporters.
                $defaultStatuses = array_values(array_unique(array_filter(array_map(static function($v){
                    return strtolower(trim((string)$v));
                }, $qaStatusInput), static function($v){
                    return $v !== '';
                })));
                foreach ($reporters as $rid) {
                    $reporterQaStatusMap[(int)$rid] = $defaultStatuses;
                }
            }
            if (!empty($reporterQaStatusMap)) {
                $flatQaStatuses = [];
                foreach ($reporterQaStatusMap as $statusValues) {
                    $vals = is_array($statusValues) ? $statusValues : [$statusValues];
                    foreach ($vals as $sv) {
                        $key = strtolower(trim((string)$sv));
                        if ($key !== '') $flatQaStatuses[] = $key;
                    }
                }
                $qaStatusInput = array_values(array_unique($flatQaStatuses));
            }
            replaceMeta($db, $id, 'qa_status', $qaStatusInput);
            replaceMeta($db, $id, 'reporter_qa_status_map', [json_encode($reporterQaStatusMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            persistIssueReporterQaStatuses($db, $id, $reporterQaStatusMap, $userId);
        }
        
        replaceMeta($db, $id, 'page_ids', $pageIds);
        replaceMeta($db, $id, 'grouped_urls', parseArrayInput($_POST['grouped_urls'] ?? []));
        replaceMeta($db, $id, 'reporter_ids', $reporters);
        replaceMeta($db, $id, 'assignee_ids', $assigneeIds);
        replaceMeta($db, $id, 'common_title', [trim($_POST['common_title'] ?? '')]);

        // Handle dynamic metadata (admin-created custom fields) — all go through same replaceMeta batch
        if (isset($_POST['metadata'])) {
            $metadata = json_decode($_POST['metadata'], true);
            if (is_array($metadata)) {
                foreach ($metadata as $key => $value) {
                    if ($action === 'update') {
                        handleMetaHistory($db, $id, $userId, $key, $value, $oldMeta);
                    }
                    $valueArray = is_array($value) ? $value : [$value];
                    replaceMeta($db, $id, $key, $valueArray);
                }
            }
        }

        // Batch insert issue_pages (single query instead of N individual inserts)
        $db->prepare("DELETE FROM issue_pages WHERE issue_id = ?")->execute([$id]);
        if (!empty($pageIds)) {
            $pageRows = implode(',', array_fill(0, count($pageIds), '(?, ?)'));
            $pageParams = [];
            foreach ($pageIds as $pid) { $pageParams[] = $id; $pageParams[] = (int)$pid; }
            $db->prepare("INSERT INTO issue_pages (issue_id, page_id) VALUES $pageRows")->execute($pageParams);
        }

        if ($commonTitle && count($pageIds) > 1) {
            $stmt = $db->prepare("SELECT id FROM common_issues WHERE issue_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $cid = $stmt->fetchColumn();
            if ($cid) {
                $up = $db->prepare("UPDATE common_issues SET title = ?, updated_at = NOW() WHERE id = ?");
                $up->execute([$commonTitle, $cid]);
            } else {
                if (columnExists($db, 'common_issues', 'created_by')) {
                    $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title, created_by) VALUES (?, ?, ?, ?)");
                    $ins->execute([$projectId, $id, $commonTitle, $userId]);
                } else {
                    $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title) VALUES (?, ?, ?)");
                    $ins->execute([$projectId, $id, $commonTitle]);
                }
            }
        } else {
            $db->prepare("DELETE FROM common_issues WHERE issue_id = ?")->execute([$id]);
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 500);
    }

        // Fetch the updated issue data to return to client
        $sql = "SELECT DISTINCT i.*, 
                       s.name as status_name,
                       s.color as status_color,
                       reporter.full_name as reporter_name
                FROM issues i
                LEFT JOIN issue_statuses s ON i.status_id = s.id
                LEFT JOIN users reporter ON i.reporter_id = reporter.id
                WHERE i.id = ? AND i.project_id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$id, $projectId]);
        $issueData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$issueData) {
            jsonResponse(['success' => true, 'id' => $id, 'issue_key' => $issueKey]);
        }
        
        // Fetch metadata for this issue
        $metaStmt = $db->prepare("SELECT meta_key, meta_value FROM issue_metadata WHERE issue_id = ?");
        $metaStmt->execute([$id]);
        $meta = [];
        while ($m = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($meta[$m['meta_key']])) {
                $meta[$m['meta_key']] = [];
            }
            $meta[$m['meta_key']][] = $m['meta_value'];
        }
        
        // Fetch page info
        $pageStmt = $db->prepare("
            SELECT pp.id, pp.page_name, pp.page_number
            FROM issue_pages ip
            INNER JOIN project_pages pp ON ip.page_id = pp.id
            WHERE ip.issue_id = ?
        ");
        $pageStmt->execute([$id]);
        $pages = [];
        $pageIds = [];
        while ($p = $pageStmt->fetch(PDO::FETCH_ASSOC)) {
            $pages[] = [
                'id' => (int)$p['id'],
                'name' => $p['page_name'],
                'number' => $p['page_number']
            ];
            $pageIds[] = (int)$p['id'];
        }
        
        // If no pages from issue_pages, try metadata
        if (empty($pages) && isset($meta['page_ids'])) {
            $metaPageIds = $meta['page_ids'];
            if (is_array($metaPageIds)) {
                $pageIds = array_values(array_filter(array_map('intval', $metaPageIds)));
            } else {
                $decoded = json_decode($metaPageIds, true);
                if (is_array($decoded)) {
                    $pageIds = array_values(array_filter(array_map('intval', $decoded)));
                } else {
                    $pageIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $metaPageIds)))));
                }
            }
        }
        
        // Get reporter QA status map
        $reporterQaStatusByIssue = loadReporterQaStatusMapByIssueIds($db, [$id]);
        $reporterQaStatusMap = $reporterQaStatusByIssue[$id] ?? [];
        if (empty($reporterQaStatusMap) && isset($meta['reporter_qa_status_map'])) {
            $reporterQaStatusMap = parseReporterQaStatusMapFromMetaValues($meta['reporter_qa_status_map']);
        }
        
        // Get all reporters
        $reporterIds = [];
        if (!empty($issueData['reporter_id'])) {
            $reporterIds[] = (int)$issueData['reporter_id'];
        }
        
        if (isset($meta['reporter_ids'])) {
            $reporterIdsData = $meta['reporter_ids'];
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
            $reporterIds = array_unique(array_merge($reporterIds, $additionalReporterIds));
        }
        
        // Get QA status keys
        $qaStatusKeys = [];
        if (!empty($reporterQaStatusMap)) {
            foreach ($reporterQaStatusMap as $statusValues) {
                $vals = is_array($statusValues) ? $statusValues : [$statusValues];
                foreach ($vals as $statusKey) {
                    $key = strtolower(trim((string)$statusKey));
                    if ($key !== '') $qaStatusKeys[] = $key;
                }
            }
            $qaStatusKeys = array_values(array_unique($qaStatusKeys));
        }
        if (empty($qaStatusKeys) && isset($meta['qa_status'])) {
            $qaStatusData = $meta['qa_status'];
            if (is_array($qaStatusData)) {
                if (count($qaStatusData) === 1 && is_string($qaStatusData[0]) && $qaStatusData[0][0] === '[') {
                    $decoded = json_decode($qaStatusData[0], true);
                    $qaStatusKeys = is_array($decoded) ? $decoded : $qaStatusData;
                } else {
                    $qaStatusKeys = $qaStatusData;
                }
            } else {
                $decoded = json_decode($qaStatusData, true);
                if (is_array($decoded)) {
                    $qaStatusKeys = $decoded;
                } else {
                    $qaStatusKeys = array_filter(array_map('trim', explode(',', $qaStatusData)));
                }
            }
        }
        
        // Check if issue has comments
        $commentStmt = $db->prepare("SELECT COUNT(*) FROM issue_comments WHERE issue_id = ?");
        $commentStmt->execute([$id]);
        $hasComments = $commentStmt->fetchColumn() > 0;
        
        // Check if tester can delete (for UI purposes)
        $canTesterDelete = true;
        if ($isTesterRole) {
            $blockedIds = getTesterBlockedIssueIdsForDelete($db, $projectId, [$id]);
            $canTesterDelete = empty($blockedIds);
        }
        
        // Get latest history ID for conflict detection
        $historyStmt = $db->prepare("SELECT MAX(id) FROM issue_history WHERE issue_id = ?");
        $historyStmt->execute([$id]);
        $latestHistoryId = (int)$historyStmt->fetchColumn();
        
        $updatedIssue = [
            'id' => (int)$issueData['id'],
            'issue_key' => $issueData['issue_key'] ?? 'ISS-' . $issueData['id'], // Fallback if column doesn't exist
            'title' => $issueData['title'],
            'description' => $issueData['description'],
            'common_title' => isset($meta['common_title']) && is_array($meta['common_title']) ? $meta['common_title'][0] : '',
            'status' => $issueData['status_name'] ?? 'open',
            'status_id' => (int)$issueData['status_id'],
            'qa_status' => $qaStatusKeys,
            'severity' => isset($meta['severity']) ? (is_array($meta['severity']) ? $meta['severity'][0] : $meta['severity']) : 'medium',
            'priority' => isset($meta['priority']) ? (is_array($meta['priority']) ? $meta['priority'][0] : $meta['priority']) : 'medium',
            'pages' => $pageIds,
            'grouped_urls' => isset($meta['grouped_urls']) && is_array($meta['grouped_urls']) ? $meta['grouped_urls'] : [],
            'reporter_name' => $issueData['reporter_name'],
            'qa_name' => $issueData['qa_name'] ?? null,
            'assignee_id' => (int)($issueData['assignee_id'] ?? 0) ?: null,
            'assignee_ids' => isset($meta['assignee_ids']) ? array_values(array_filter(array_map('intval', $meta['assignee_ids']), function($v){ return $v > 0; })) : ((int)($issueData['assignee_id'] ?? 0) ? [(int)$issueData['assignee_id']] : []),
            'page_id' => !empty($pageIds) ? $pageIds[0] : null,
            'client_ready' => (int)($issueData['client_ready'] ?? 0),
            'environments' => isset($meta['environments']) && is_array($meta['environments']) ? $meta['environments'] : [],
            'usersaffected' => isset($meta['usersaffected']) && is_array($meta['usersaffected']) ? $meta['usersaffected'] : [],
            'wcagsuccesscriteria' => isset($meta['wcagsuccesscriteria']) && is_array($meta['wcagsuccesscriteria']) ? $meta['wcagsuccesscriteria'] : [],
            'wcagsuccesscriterianame' => isset($meta['wcagsuccesscriterianame']) && is_array($meta['wcagsuccesscriterianame']) ? $meta['wcagsuccesscriterianame'] : [],
            'wcagsuccesscriterialevel' => isset($meta['wcagsuccesscriterialevel']) && is_array($meta['wcagsuccesscriterialevel']) ? $meta['wcagsuccesscriterialevel'] : [],
            'gigw30' => isset($meta['gigw30']) && is_array($meta['gigw30']) ? $meta['gigw30'] : [],
            'is17802' => isset($meta['is17802']) && is_array($meta['is17802']) ? $meta['is17802'] : [],
            'reporters' => $reporterIds,
            'reporter_qa_status_map' => $reporterQaStatusMap,
            'has_comments' => $hasComments,
            'can_tester_delete' => $canTesterDelete,
            'created_at' => $issueData['created_at'],
            'updated_at' => $issueData['updated_at'],
            'latest_history_id' => $latestHistoryId
        ];
        
        // Add custom metadata fields
        if (isset($meta)) {
            foreach ($meta as $key => $values) {
                if (!isset($updatedIssue[$key])) {
                    $updatedIssue[$key] = is_array($values) && count($values) === 1 ? $values[0] : $values;
                }
            }
        }

        // Invalidate get_all cache for this project
        if (function_exists('apcu_delete')) {
            apcu_delete("issues_all_{$projectId}_staff");
            apcu_delete("issues_all_{$projectId}_client");
        }

        jsonResponse(['success' => true, 'id' => $id, 'issue_key' => $issueKey, 'issue' => $updatedIssue]);
    }

    if ($method === 'POST' && $action === 'bulk_client_ready') {
        $issueIds = $_POST['issue_ids'] ?? '';
        $clientReady = (int)($_POST['client_ready'] ?? 0);
        
        $ids = is_array($issueIds) ? $issueIds : array_filter(array_map('intval', explode(',', $issueIds)));
        if (empty($ids)) jsonError('issue_ids required', 400);
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$clientReady], $ids, [$projectId]);
        
        try {
            $stmt = $db->prepare("UPDATE issues SET client_ready = ?, updated_at = NOW() WHERE id IN ($placeholders) AND project_id = ?");
            $stmt->execute($params);
            
            jsonResponse(['success' => true, 'updated' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            error_log("Bulk client ready update error: " . $e->getMessage());
            jsonError('Failed to update issues', 500);
        }
    }

    if ($method === 'POST' && $action === 'delete') {
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : array_filter(array_map('intval', explode(',', $idsRaw)));
        if (empty($ids)) jsonError('ids required', 400);

        // Permission check BEFORE fetching HTML blocks (avoid wasted work on 403)
        if ($isTesterRole) {
            $blockedIds = getTesterBlockedIssueIdsForDelete($db, $projectId, $ids);
            if (!empty($blockedIds)) {
                jsonResponse([
                    'error' => 'Testers can delete only when QA status is empty and no comments exist on the issue.',
                    'blocked_issue_ids' => $blockedIds
                ], 403);
            }
        }

        $htmlBlocksForCleanup = collectIssueDeleteHtmlBlocks($db, $projectId, $ids);

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
            cleanupIssueUploadsFromHtmlBlocks($htmlBlocksForCleanup);
            // Invalidate get_all cache
            if (function_exists('apcu_delete')) {
                apcu_delete("issues_all_{$projectId}_staff");
                apcu_delete("issues_all_{$projectId}_client");
            }
            jsonResponse(['success' => true, 'deleted' => $stmt->rowCount()]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    if ($method === 'GET' && $action === 'common_list') {
        $orderByClause = columnExists($db, 'issues', 'issue_key') ? "ORDER BY i.issue_key ASC" : "ORDER BY i.id ASC";
        
        $stmt = $db->prepare("
            SELECT ci.id as common_id, ci.title as common_title, i.*, s.name AS status_name
            FROM common_issues ci
            JOIN issues i ON ci.issue_id = i.id
            LEFT JOIN issue_statuses s ON s.id = i.status_id
            WHERE ci.project_id = ?
            $orderByClause
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
            
            if (columnExists($db, 'common_issues', 'created_by')) {
                $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title, created_by) VALUES (?, ?, ?, ?)");
                $ins->execute([$projectId, $issueId, $title, $userId]);
            } else {
                $ins = $db->prepare("INSERT INTO common_issues (project_id, issue_id, title) VALUES (?, ?, ?)");
                $ins->execute([$projectId, $issueId, $title]);
            }
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
        $htmlBlocksForCleanup = !empty($issueIds) ? collectIssueDeleteHtmlBlocks($db, $projectId, array_map('intval', $issueIds)) : [];

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
        cleanupIssueUploadsFromHtmlBlocks($htmlBlocksForCleanup);
        jsonResponse(['success' => true]);
    }

    jsonError('Invalid action', 400);
} catch (Exception $e) {
    error_log('issues api error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonError('Server error', 500);
}
