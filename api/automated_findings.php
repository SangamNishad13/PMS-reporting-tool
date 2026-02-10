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
            $title = trim($r['issue_description'] ?? '');
            if ($title === '') $title = 'Automated Issue';
            if (strlen($title) > 80) $title = substr($title, 0, 77) . '...';
            $out[] = [
                'id' => (int)$r['id'],
                'page_id' => (int)$r['page_id'],
                'page_name' => $r['page_name'],
                'instance_name' => $r['instance_name'],
                'wcag_failure' => $r['wcag_failure'],
                'title' => $title,
                'summary' => '',
                'snippet' => '',
                'details' => $r['issue_description'],
                'detected_at' => $r['detected_at']
            ];
        }
        jsonResponse(['success' => true, 'findings' => $out]);
    }

    if ($method === 'POST' && $action === 'create') {
        $pageId = (int)($_POST['page_id'] ?? 0);
        $details = trim($_POST['details'] ?? '');
        if (!$details) jsonError('details are required', 400);

        $stmt = $db->prepare("
            INSERT INTO automated_findings
                (page_id, environment_id, instance_name, issue_description, wcag_failure, detected_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $pageId ?: null,
            (int)($_POST['environment_id'] ?? 0) ?: null,
            trim($_POST['instance_name'] ?? ''),
            $details,
            trim($_POST['wcag_failure'] ?? '')
        ]);
        $id = (int)$db->lastInsertId();
        jsonResponse(['success' => true, 'id' => $id]);
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

        foreach ($findings as $f) {
            $desc = $f['issue_description'] ?: '';
            $insertIssue->execute([
                $projectId,
                getIssueKey($db, $projectId),
                $f['issue_description'] ? substr($f['issue_description'], 0, 120) : 'Automated Issue',
                $desc,
                $typeId,
                $priorityId,
                $statusId,
                $userId,
                $f['page_id'],
                'major'
            ]);
            $issueId = (int)$db->lastInsertId();
            $deleteFinding->execute([$f['id']]);
            $created[] = ['finding_id' => (int)$f['id'], 'issue_id' => $issueId, 'title' => ($f['issue_description'] ?: 'Automated Issue')];
        }

        $db->commit();
        jsonResponse(['success' => true, 'created' => $created]);
    }

    jsonError('Invalid action', 400);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('automated_findings error: ' . $e->getMessage());
    jsonError('Server error', 500);
}
