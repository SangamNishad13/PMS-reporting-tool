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

function sanitizeRecommendationText($text) {
    $t = trim((string)$text);
    if ($t === '') return '';
    // Remove external links (e.g. deque rule URLs) from recommendation text.
    $t = preg_replace('/https?:\/\/\S+/i', '', $t);
    $t = preg_replace('/\s{2,}/', ' ', $t);
    $t = trim($t, " \t\n\r\0\x0B-");
    return $t;
}

function htmlToPlainText($html) {
    $src = (string)$html;
    if ($src === '') return '';
    $src = preg_replace('/<br\s*\/?>/i', "\n", $src);
    $src = preg_replace('/<\/(p|div|li|h[1-6]|pre|code|ul|ol)>/i', "\n", $src);
    $text = trim(html_entity_decode(strip_tags($src), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim((string)$text);
}

function extractNodeLabelText($html) {
    $raw = trim((string)$html);
    if ($raw === '') return '';
    $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', trim((string)$text));
    if ($text === '') return '';
    if (mb_strlen($text) > 80) {
        $text = mb_substr($text, 0, 77) . '...';
    }
    return $text;
}

function extractAutomatedFields($details) {
    $out = [
        'issue_title' => '',
        'rule_id' => '',
        'impact' => '',
        'source_url' => '',
        'description_text' => '',
        'failure_summary' => '',
        'incorrect_code' => '',
        'screenshots' => '',
        'recommendation' => ''
    ];
    $text = trim((string)$details);
    if ($text === '') return $out;

    if (preg_match('/<!--\s*ISSUE_TITLE:(.*?)-->/is', $text, $mTitle)) {
        $out['issue_title'] = trim((string)$mTitle[1]);
    }
    if (preg_match('/<!--\s*RULE_ID:(.*?)-->/is', $text, $mRule)) {
        $out['rule_id'] = trim((string)$mRule[1]);
    }
    if (preg_match('/<!--\s*IMPACT:(.*?)-->/is', $text, $mImpact)) {
        $out['impact'] = trim((string)$mImpact[1]);
    }
    if (preg_match('/<!--\s*SOURCE_URL:(.*?)-->/is', $text, $mSource)) {
        $out['source_url'] = trim((string)$mSource[1]);
    }

    $extract = function($label) use ($text) {
        // Works for both multiline and single-line "Label: value Label2: value2" formats.
        $labels = 'Issue|Rule ID|Impact|Source URL|Description|Failure|Incorrect Code|Screenshots|Recommendation';
        $pattern = '/\b' . preg_quote($label, '/') . ':\s*(.*?)(?=\s+(?:' . $labels . '):|\z)/is';
        if (preg_match($pattern, $text, $m)) {
            return trim((string)$m[1]);
        }
        return '';
    };

    $out['issue_title'] = $extract('Issue');
    if ($out['rule_id'] === '') $out['rule_id'] = $extract('Rule ID');
    if ($out['impact'] === '') $out['impact'] = $extract('Impact');
    if ($out['source_url'] === '') $out['source_url'] = $extract('Source URL');
    $out['description_text'] = $extract('Description');
    $out['failure_summary'] = $extract('Failure');
    $out['incorrect_code'] = $extract('Incorrect Code');
    $out['screenshots'] = $extract('Screenshots');
    $out['recommendation'] = sanitizeRecommendationText($extract('Recommendation'));

    // Support modal-edited section format:
    // [Actual Results], [Incorrect Code], [Recommendation], [Correct Code], URL N:
    if ($out['source_url'] === '' && preg_match('/URL\s+\d+\s*:\s*(https?:\/\/\S+)/i', $text, $mUrl)) {
        $out['source_url'] = trim((string)$mUrl[1]);
    }
    if ($out['description_text'] === '' && preg_match('/\[Actual Results\]\s*(.*?)(?=\[Incorrect Code\]|\z)/is', $text, $mDesc)) {
        $out['description_text'] = trim((string)$mDesc[1]);
    }
    if ($out['incorrect_code'] === '' && preg_match('/\[Incorrect Code\]\s*(.*?)(?=\[Recommendation\]|\z)/is', $text, $mBad)) {
        $out['incorrect_code'] = trim((string)$mBad[1]);
    }

    if ($out['recommendation'] === '' && preg_match('/\[Recommendation\]\s*(.*?)(?=\[Correct Code\]|\z)/is', $text, $mRec)) {
        $out['recommendation'] = sanitizeRecommendationText((string)$mRec[1]);
    }

    if ($out['description_text'] !== '') {
        $out['description_text'] = htmlToPlainText($out['description_text']);
    }
    if ($out['failure_summary'] !== '') {
        $out['failure_summary'] = htmlToPlainText($out['failure_summary']);
    }
    if ($out['recommendation'] !== '') {
        $out['recommendation'] = htmlToPlainText($out['recommendation']);
    }

    if ($out['description_text'] === '' && $out['issue_title'] === '' && $text !== '') {
        $out['description_text'] = htmlToPlainText($text);
    }
    return $out;
}

function stripReviewMetaComments($html) {
    $t = (string)$html;
    if ($t === '') return '';
    $t = preg_replace('/<!--\s*ISSUE_TITLE:.*?-->\s*/is', '', $t);
    $t = preg_replace('/<!--\s*RULE_ID:.*?-->\s*/is', '', $t);
    $t = preg_replace('/<!--\s*IMPACT:.*?-->\s*/is', '', $t);
    $t = preg_replace('/<!--\s*SOURCE_URL:.*?-->\s*/is', '', $t);
    return trim((string)$t);
}

function replaceIssueMetaValues($db, $issueId, $key, $values) {
    $vals = is_array($values) ? $values : [$values];
    $clean = [];
    foreach ($vals as $v) {
        $s = trim((string)$v);
        if ($s === '') continue;
        if (!in_array($s, $clean, true)) $clean[] = $s;
    }
    $db->prepare("DELETE FROM issue_metadata WHERE issue_id = ? AND meta_key = ?")->execute([$issueId, $key]);
    if (empty($clean)) return;
    $ins = $db->prepare("INSERT INTO issue_metadata (issue_id, meta_key, meta_value) VALUES (?, ?, ?)");
    foreach ($clean as $v) {
        $ins->execute([$issueId, $key, $v]);
    }
}

function resolveNodeBinary() {
    $candidates = [];
    $envNode = trim((string)getenv('PMS_NODE_BIN'));
    if ($envNode !== '') $candidates[] = $envNode;
    $candidates[] = 'node';
    $candidates[] = 'node.exe';
    $candidates[] = 'C:\\Program Files\\nodejs\\node.exe';
    $candidates[] = 'C:\\Program Files (x86)\\nodejs\\node.exe';

    foreach ($candidates as $candidate) {
        if (preg_match('/[\\\\\\/]/', $candidate)) {
            if (is_file($candidate)) return $candidate;
            continue;
        }

        if (function_exists('shell_exec')) {
            $checkCmd = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                ? 'where ' . escapeshellarg($candidate)
                : 'command -v ' . escapeshellarg($candidate);
            $output = @shell_exec($checkCmd . ' 2>NUL');
            if (is_string($output) && trim($output) !== '') {
                $line = trim((string)preg_split('/\R/', trim($output))[0]);
                if ($line !== '') return $line;
            }
        }
    }

    throw new Exception('Node.js binary not found. Set PMS_NODE_BIN or install Node.js for Apache user.');
}

function runScanCommand($url, $cookieHeader, $timeoutMs = 45000) {
    if (!function_exists('proc_open')) {
        throw new Exception('proc_open is disabled in PHP configuration.');
    }

    $nodeBin = resolveNodeBinary();
    $script = realpath(__DIR__ . '/../tools/accessibility/run_axe_scan.js');
    if (!$script || !file_exists($script)) {
        throw new Exception('Scanner script not found.');
    }

    $cmd = escapeshellarg($nodeBin) . ' ' . escapeshellarg($script)
        . ' --url ' . escapeshellarg($url)
        . ' --timeout ' . (int)$timeoutMs;
    if ($cookieHeader !== '') {
        $cmd .= ' --cookie ' . escapeshellarg($cookieHeader);
    }

    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptorspec, $pipes, dirname($script));
    if (!is_resource($process)) {
        throw new Exception('Unable to start scanner process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new Exception('Scanner failed: ' . trim($stderr ?: $stdout));
    }

    $json = json_decode($stdout, true);
    if (!is_array($json)) {
        throw new Exception('Invalid scanner output.');
    }
    return $json;
}

function getAppBasePath() {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $apiDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $base = preg_replace('#/api$#i', '', $apiDir);
    return $base ?: '';
}

function toAppPublicUrl($path) {
    $p = trim((string)$path);
    if ($p === '') return '';
    if (preg_match('/^https?:\/\//i', $p)) return $p;
    if ($p[0] !== '/') $p = '/' . $p;
    return rtrim(getAppBasePath(), '/') . $p;
}

function getStatusId($db, $name) {
    $stmt = $db->prepare("SELECT id FROM issue_statuses WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    return $stmt->fetchColumn() ?: null;
}

function getPriorityId($db, $name) {
    $stmt = $db->prepare("SELECT id FROM issue_priorities WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    return $stmt->fetchColumn() ?: null;
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
    return $prefix . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);

if (!$projectId) {
    jsonError('project_id is required', 400);
}

$userId = $_SESSION['user_id'] ?? 0;
if (!hasProjectAccess($db, $userId, $projectId)) {
    jsonError('Permission denied', 403);
}

try {
    if ($method === 'GET' && $action === 'list') {
        $pageId = (int)($_GET['page_id'] ?? 0);
        $sql = "SELECT af.*, pp.page_name, pp.project_id
                FROM automated_findings af
                JOIN project_pages pp ON af.page_id = pp.id
                WHERE pp.project_id = ?";
        $params = [$projectId];
        if ($pageId) {
            $sql .= " AND af.page_id = ?";
            $params[] = $pageId;
        }
        $sql .= " ORDER BY af.detected_at DESC, af.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $parsed = extractAutomatedFields($r['issue_description'] ?? '');
            $title = trim((string)$parsed['issue_title']);
            if ($title === '') {
                $descFirst = trim((string)preg_split('/\R+/', (string)($parsed['description_text'] ?? ''))[0] ?? '');
                $title = $descFirst !== '' ? $descFirst : 'Automated Issue';
            }
            if (strlen($title) > 80) $title = substr($title, 0, 77) . '...';
            $out[] = [
                'id' => (int)$r['id'],
                'page_id' => (int)$r['page_id'],
                'page_name' => $r['page_name'],
                'instance_name' => $r['instance_name'],
                'wcag_failure' => $r['wcag_failure'],
                'rule_id' => $parsed['rule_id'],
                'impact' => $parsed['impact'],
                'source_url' => $parsed['source_url'],
                'description_text' => $parsed['description_text'],
                'failure_summary' => $parsed['failure_summary'],
                'incorrect_code' => $parsed['incorrect_code'],
                'recommendation' => $parsed['recommendation'],
                'title' => $title,
                'summary' => '',
                'snippet' => '',
                'details' => $r['issue_description'],
                'detected_at' => $r['detected_at']
            ];
        }
        jsonResponse(['success' => true, 'findings' => $out]);
    }

    if ($method === 'POST' && $action === 'delete') {
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : array_filter(array_map('intval', explode(',', $idsRaw)));
        if (empty($ids)) jsonError('ids required', 400);

        // Fetch findings to remove related assets/files
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$projectId]);
        $findingsStmt = $db->prepare(
            "SELECT af.* FROM automated_findings af
             JOIN project_pages pp ON af.page_id = pp.id
             WHERE af.id IN ($placeholders) AND pp.project_id = ?"
        );
        $findingsStmt->execute($params);
        $findings = $findingsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Start transaction to ensure DB consistency
        $db->beginTransaction();
        try {
            // For each finding, try to remove referenced screenshot files and matching project_assets
            $root = realpath(__DIR__ . '/..'); // project root (PMS)
            $deletedFiles = [];
            $deletedAssetsCount = 0;
            foreach ($findings as $f) {
                $desc = (string)($f['issue_description'] ?? '');
                // Find any /assets/uploads/automated_findings/... references (also allow possible absolute app base)
                if (preg_match_all('#(?:/assets/uploads/automated_findings/|assets/uploads/automated_findings/)[\w\-_.]+#i', $desc, $m)) {
                    foreach (array_unique($m[0]) as $urlPath) {
                        // Normalize leading slash
                        if ($urlPath[0] !== '/') $urlPath = '/' . $urlPath;
                        // File system path
                        $filePath = $root . $urlPath;
                        if (file_exists($filePath) && is_file($filePath)) {
                            if (@unlink($filePath)) {
                                $deletedFiles[] = $urlPath;
                            }
                        }
                        // Remove any project_assets rows that reference this file (by file_path or main_url/basename)
                        try {
                            $base = basename($filePath);
                            $delAsset = $db->prepare("DELETE FROM project_assets WHERE file_path LIKE ? OR main_url LIKE ? OR asset_name = ?");
                            $delAsset->execute(["%automated_findings/%$base%", "%automated_findings/%$base%", $base]);
                            $deletedAssetsCount += $delAsset->rowCount();
                        } catch (Exception $_) {
                            // non-fatal
                        }
                    }
                }
            }

            // Delete the findings rows
            $delStmt = $db->prepare(
                "DELETE af FROM automated_findings af
                 JOIN project_pages pp ON af.page_id = pp.id
                 WHERE af.id IN ($placeholders) AND pp.project_id = ?"
            );
            $delStmt->execute($params);
            $deleted = $delStmt->rowCount();

            $db->commit();

            // Log deletion activity (best-effort)
            try {
                $logDetails = json_encode([
                    'deleted_finding_ids' => array_values($ids),
                    'deleted_files' => $deletedFiles,
                    'deleted_project_assets' => $deletedAssetsCount
                ], JSON_UNESCAPED_UNICODE);
                $logStmt = $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, 'delete_automated_findings', 'automated_findings', NULL, ?, ?)");
                $logStmt->execute([$_SESSION['user_id'] ?? null, $logDetails, $_SERVER['REMOTE_ADDR'] ?? null]);
            } catch (Exception $_) {
                // ignore logging failures
            }

            jsonResponse(['success' => true, 'deleted' => $deleted, 'deleted_files' => $deletedFiles, 'deleted_project_assets' => $deletedAssetsCount]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    if ($method === 'POST' && $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonError('id is required', 400);

        $stmt = $db->prepare("
            UPDATE automated_findings af
            JOIN project_pages pp ON af.page_id = pp.id
            SET af.instance_name = ?, af.wcag_failure = ?, af.issue_description = ?
            WHERE af.id = ? AND pp.project_id = ?
        ");
        $stmt->execute([
            trim($_POST['instance_name'] ?? ''),
            trim($_POST['wcag_failure'] ?? ''),
            trim($_POST['details'] ?? ''),
            $id,
            $projectId
        ]);
        jsonResponse(['success' => true]);
    }

    if ($method === 'POST' && $action === 'delete') {
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : array_filter(array_map('intval', explode(',', $idsRaw)));
        if (empty($ids)) jsonError('ids required', 400);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$projectId]);
        $stmt = $db->prepare("
            DELETE af FROM automated_findings af
            JOIN project_pages pp ON af.page_id = pp.id
            WHERE af.id IN ($placeholders) AND pp.project_id = ?
        ");
        $stmt->execute($params);
        jsonResponse(['success' => true, 'deleted' => $stmt->rowCount()]);
    }

    if ($method === 'POST' && $action === 'move_to_issue') {
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : array_filter(array_map('intval', explode(',', $idsRaw)));
        if (empty($ids)) jsonError('ids required', 400);

        $db->beginTransaction();

        $statusId = getStatusId($db, 'Open');
        $priorityId = getPriorityId($db, 'Medium');
        $typeId = getDefaultTypeId($db, $projectId);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$projectId]);
        $findingsStmt = $db->prepare("
            SELECT af.*, pp.project_id 
            FROM automated_findings af
            JOIN project_pages pp ON af.page_id = pp.id
            WHERE af.id IN ($placeholders) AND pp.project_id = ?
        ");
        $findingsStmt->execute($params);
        $findings = $findingsStmt->fetchAll(PDO::FETCH_ASSOC);

        $created = [];
        $insertIssue = $db->prepare("
            INSERT INTO issues (project_id, issue_key, title, description, type_id, priority_id, status_id, reporter_id, page_id, severity, is_final)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $deleteFinding = $db->prepare("DELETE FROM automated_findings WHERE id = ?");

        $mergedTitle = trim((string)($_POST['merged_title'] ?? ''));
        $mergedDetails = trim((string)($_POST['merged_details'] ?? ''));
        $mergedSeverity = strtolower(trim((string)($_POST['merged_severity'] ?? '')));
        $mergedSourceUrlsRaw = $_POST['merged_source_urls'] ?? '[]';
        $mergedSourceUrls = json_decode((string)$mergedSourceUrlsRaw, true);
        if (!is_array($mergedSourceUrls)) $mergedSourceUrls = [];
        $mergedSourceUrls = array_values(array_unique(array_filter(array_map(function($u){
            $s = trim((string)$u);
            return preg_match('/^https?:\/\//i', $s) ? $s : '';
        }, $mergedSourceUrls))));

        // Modal move sends merged payload. In that case create ONE final issue from all finding IDs.
        $isMergedMove = ($mergedTitle !== '' || $mergedDetails !== '');
        if ($isMergedMove && !empty($findings)) {
            $firstFinding = $findings[0];
            $title = $mergedTitle !== '' ? $mergedTitle : 'Automated Issue';
            if (mb_strlen($title) > 120) $title = mb_substr($title, 0, 117) . '...';
            $desc = stripReviewMetaComments($mergedDetails !== '' ? $mergedDetails : (string)($firstFinding['issue_description'] ?? ''));

            $sev = 'major';
            if (in_array($mergedSeverity, ['critical', 'major', 'medium', 'minor'], true)) {
                $sev = $mergedSeverity;
            }

            $insertIssue->execute([
                $projectId,
                getIssueKey($db, $projectId),
                $title,
                $desc,
                $typeId,
                $priorityId,
                $statusId,
                $userId,
                $firstFinding['page_id'],
                $sev
            ]);
            $issueId = (int)$db->lastInsertId();

            $urls = $mergedSourceUrls;
            if (empty($urls)) {
                foreach ($findings as $f) {
                    $p = extractAutomatedFields((string)($f['issue_description'] ?? ''));
                    $u = trim((string)($p['source_url'] ?? ''));
                    if ($u !== '' && !in_array($u, $urls, true)) $urls[] = $u;
                }
            }
            if (!empty($urls)) {
                replaceIssueMetaValues($db, $issueId, 'grouped_urls', $urls);
            }

            foreach ($findings as $f) {
                $deleteFinding->execute([$f['id']]);
            }

            $db->commit();
            jsonResponse([
                'success' => true,
                'created' => [[
                    'issue_id' => $issueId,
                    'title' => $title,
                    'moved_findings' => count($findings)
                ]]
            ]);
        }

        foreach ($findings as $f) {
            $rawDesc = (string)($f['issue_description'] ?? '');
            $parsed = extractAutomatedFields($rawDesc);
            $desc = stripReviewMetaComments($rawDesc);

            $title = trim((string)($parsed['issue_title'] ?? ''));
            if ($title === '') {
                $title = trim((string)($parsed['description_text'] ?? ''));
            }
            if ($title === '') {
                $title = htmlToPlainText($desc);
            }
            if ($title === '') {
                $title = 'Automated Issue';
            }
            if (mb_strlen($title) > 120) {
                $title = mb_substr($title, 0, 117) . '...';
            }

            $impact = strtolower(trim((string)($parsed['impact'] ?? '')));
            $severity = 'major';
            if ($impact === 'critical') $severity = 'critical';
            elseif ($impact === 'serious') $severity = 'major';
            elseif ($impact === 'moderate') $severity = 'medium';
            elseif ($impact === 'minor') $severity = 'minor';

            $insertIssue->execute([
                $projectId,
                getIssueKey($db, $projectId),
                $title,
                $desc,
                $typeId,
                $priorityId,
                $statusId,
                $userId,
                $f['page_id'],
                $severity
            ]);
            $issueId = (int)$db->lastInsertId();

            // Persist source URL from automated finding as grouped_urls metadata in final issue.
            $sourceUrl = trim((string)($parsed['source_url'] ?? ''));
            if ($sourceUrl !== '') {
                replaceIssueMetaValues($db, $issueId, 'grouped_urls', [$sourceUrl]);
            }

            $deleteFinding->execute([$f['id']]);
            $created[] = ['finding_id' => (int)$f['id'], 'issue_id' => $issueId, 'title' => $title];
        }

        $db->commit();
        jsonResponse(['success' => true, 'created' => $created]);
    }

    if ($method === 'POST' && $action === 'store_scan_results') {
        $pageId = (int)($_POST['page_id'] ?? 0);
        if (!$pageId) jsonError('page_id is required', 400);

        $pageStmt = $db->prepare("SELECT id, page_name, url FROM project_pages WHERE id = ? AND project_id = ? LIMIT 1");
        $pageStmt->execute([$pageId, $projectId]);
        $page = $pageStmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) jsonError('Page not found', 404);

        $scanUrl = trim((string)($_POST['scan_url'] ?? ''));
        if ($scanUrl === '') {
            $scanUrl = trim((string)($page['url'] ?? ''));
        }
        if ($scanUrl === '') jsonError('Page URL missing for scan', 400);
        if (!preg_match('/^https?:\/\//i', $scanUrl)) {
            $scanUrl = 'https://' . ltrim($scanUrl, '/');
        }

        $violationsRaw = $_POST['violations_json'] ?? '';
        $violations = json_decode((string)$violationsRaw, true);
        if (!is_array($violations)) {
            jsonError('Invalid violations_json payload', 400);
        }

        $ins = $db->prepare("
            INSERT INTO automated_findings
                (page_id, environment_id, instance_name, issue_description, wcag_failure, detected_at)
            VALUES (?, NULL, ?, ?, ?, NOW())
        ");

        $created = 0;
        foreach ($violations as $v) {
            if (!is_array($v)) continue;
            $title = trim((string)($v['help'] ?? 'Automated Issue'));
            $desc = trim((string)($v['description'] ?? ''));
            $impact = trim((string)($v['impact'] ?? ''));
            $ruleId = trim((string)($v['id'] ?? ''));
            $actualResultText = $title !== '' ? $title : $desc;
            $recommendationText = sanitizeRecommendationText($desc !== '' ? $desc : $title);
            $nodes = is_array($v['nodes'] ?? null) ? $v['nodes'] : [];
            $tags = is_array($v['tags'] ?? null) ? $v['tags'] : [];
            $wcagTags = array_values(array_filter($tags, function($t){ return stripos((string)$t, 'wcag') === 0; }));
            $wcag = implode(', ', $wcagTags);

            if (empty($nodes)) {
                $instance = trim($title);
                $detailsLines = [
                    'Issue: ' . $title,
                    'Rule ID: ' . $ruleId,
                    'Impact: ' . $impact,
                    'Source URL: ' . $scanUrl,
                    'Description: ' . $actualResultText,
                    'Recommendation: ' . $recommendationText
                ];
                $details = trim(implode("\n", array_filter($detailsLines, function($line){ return trim((string)$line) !== ''; })));
                $ins->execute([$pageId, $instance, $details, $wcag]);
                $created++;
                continue;
            }

            foreach ($nodes as $n) {
                if (!is_array($n)) continue;
                $target = '';
                if (!empty($n['target']) && is_array($n['target'])) {
                    $target = trim((string)implode(' | ', $n['target']));
                }
                $nodeLabel = '';
                if (isset($n['html'])) {
                    $nodeLabel = extractNodeLabelText((string)$n['html']);
                }
                $instance = ($nodeLabel !== '' && $target !== '') ? ($nodeLabel . ' | ' . $target) : ($target !== '' ? $target : trim($title));
                $failureSummary = trim((string)($n['failureSummary'] ?? ''));
                $incorrectCode = trim((string)($n['html'] ?? ''));
                if (strlen($incorrectCode) > 700) {
                    $incorrectCode = substr($incorrectCode, 0, 700) . '...';
                }

                $detailsLines = [
                    'Issue: ' . $title,
                    'Rule ID: ' . $ruleId,
                    'Impact: ' . $impact,
                    'Source URL: ' . $scanUrl,
                    'Description: ' . $actualResultText,
                    'Failure: ' . $failureSummary,
                    'Incorrect Code: ' . $incorrectCode,
                    'Recommendation: ' . $recommendationText
                ];
                $details = trim(implode("\n", array_filter($detailsLines, function($line){ return trim((string)$line) !== ''; })));
                $ins->execute([$pageId, $instance, $details, $wcag]);
                $created++;
            }
        }

        jsonResponse([
            'success' => true,
            'scan_url' => $scanUrl,
            'violations' => count($violations),
            'created' => $created,
            'mode' => 'browser'
        ]);
    }

    if ($method === 'POST' && $action === 'run_scan') {
        $pageId = (int)($_POST['page_id'] ?? 0);
        if (!$pageId) jsonError('page_id is required', 400);

        $pageStmt = $db->prepare("SELECT id, page_name, url FROM project_pages WHERE id = ? AND project_id = ? LIMIT 1");
        $pageStmt->execute([$pageId, $projectId]);
        $page = $pageStmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) jsonError('Page not found', 404);

        $scanUrl = trim((string)($_POST['scan_url'] ?? ''));
        if ($scanUrl === '') {
            $scanUrl = trim((string)($page['url'] ?? ''));
        }
        if ($scanUrl === '') jsonError('Page URL missing for scan', 400);
        if (!preg_match('/^https?:\/\//i', $scanUrl)) {
            $scanUrl = 'https://' . ltrim($scanUrl, '/');
        }

        $cookieHeader = '';
        if (!empty($_COOKIE)) {
            $cookiePairs = [];
            foreach ($_COOKIE as $k => $v) {
                $k = trim((string)$k);
                if ($k === '') continue;
                $cookiePairs[] = $k . '=' . rawurlencode((string)$v);
            }
            $cookieHeader = implode('; ', $cookiePairs);
        }

        $scanResult = runScanCommand($scanUrl, $cookieHeader, 45000);
        $violations = is_array($scanResult['violations'] ?? null) ? $scanResult['violations'] : [];

        $ins = $db->prepare("
            INSERT INTO automated_findings
                (page_id, environment_id, instance_name, issue_description, wcag_failure, detected_at)
            VALUES (?, NULL, ?, ?, ?, NOW())
        ");

        $created = 0;
        foreach ($violations as $v) {
            if (!is_array($v)) continue;
            $title = trim((string)($v['help'] ?? 'Automated Issue'));
            $desc = trim((string)($v['description'] ?? ''));
            $impact = trim((string)($v['impact'] ?? ''));
            $ruleId = trim((string)($v['id'] ?? ''));
            // Use axe "help" text as actual result summary and "description" as recommendation guidance.
            $actualResultText = $title !== '' ? $title : $desc;
            $recommendationText = sanitizeRecommendationText($desc !== '' ? $desc : $title);
            $nodes = is_array($v['nodes'] ?? null) ? $v['nodes'] : [];
            $tags = is_array($v['tags'] ?? null) ? $v['tags'] : [];
            $wcagTags = array_values(array_filter($tags, function($t){ return stripos((string)$t, 'wcag') === 0; }));
            $wcag = implode(', ', $wcagTags);

            if (empty($nodes)) {
                $instance = trim($title);
                $detailsLines = [
                    'Issue: ' . $title,
                    'Rule ID: ' . $ruleId,
                    'Impact: ' . $impact,
                    'Source URL: ' . $scanUrl,
                    'Description: ' . $actualResultText,
                    'Recommendation: ' . $recommendationText
                ];
                $details = trim(implode("\n", array_filter($detailsLines, function($line){ return trim((string)$line) !== ''; })));
                $ins->execute([$pageId, $instance, $details, $wcag]);
                $created++;
                continue;
            }

            foreach ($nodes as $n) {
                $target = '';
                if (is_array($n) && !empty($n['target']) && is_array($n['target'])) {
                    $target = trim((string)implode(' | ', $n['target']));
                }
                $nodeLabel = '';
                if (is_array($n) && isset($n['html'])) {
                    $nodeLabel = extractNodeLabelText((string)$n['html']);
                }
                if ($nodeLabel !== '' && $target !== '') {
                    $instance = $nodeLabel . ' | ' . $target;
                } else {
                    $instance = $target !== '' ? $target : trim($title);
                }
                $failureSummary = '';
                if (is_array($n) && !empty($n['failureSummary'])) {
                    $failureSummary = trim((string)$n['failureSummary']);
                }
                $incorrectCode = '';
                if (is_array($n) && isset($n['html'])) {
                    $incorrectCode = trim((string)$n['html']);
                    if (strlen($incorrectCode) > 700) {
                        $incorrectCode = substr($incorrectCode, 0, 700) . '...';
                    }
                }
                $screenshotUrl = '';
                if (is_array($n) && !empty($n['screenshotPath'])) {
                    $screenshotUrl = toAppPublicUrl((string)$n['screenshotPath']);
                }
                $detailsLines = [
                    'Issue: ' . $title,
                    'Rule ID: ' . $ruleId,
                    'Impact: ' . $impact,
                    'Source URL: ' . $scanUrl,
                    'Description: ' . $actualResultText,
                    'Failure: ' . $failureSummary,
                    'Incorrect Code: ' . $incorrectCode,
                    'Screenshots: ' . $screenshotUrl,
                    'Recommendation: ' . $recommendationText
                ];
                $details = trim(implode("\n", array_filter($detailsLines, function($line){ return trim((string)$line) !== ''; })));
                $ins->execute([$pageId, $instance, $details, $wcag]);
                $created++;
            }
        }

        jsonResponse([
            'success' => true,
            'scan_url' => $scanUrl,
            'violations' => count($violations),
            'created' => $created
        ]);
    }

    jsonError('Invalid action', 400);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('automated_findings error: ' . $e->getMessage());
    jsonError($e->getMessage(), 500);
}
