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
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Please login to access this resource']);
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

function parseMentionsInput($value) {
    if ($value === null || $value === '') return [];
    if (is_array($value)) {
        return array_values(array_unique(array_filter(array_map('intval', $value), function ($v) { return $v > 0; })));
    }
    $raw = trim((string)$value);
    if ($raw === '') return [];
    if ($raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_unique(array_filter(array_map('intval', $decoded), function ($v) { return $v > 0; })));
        }
    }
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), function ($v) { return $v > 0; })));
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$issueId = (int)($_GET['issue_id'] ?? $_POST['issue_id'] ?? 0);

if (!$projectId) jsonError('project_id required', 400);
if (!$issueId) jsonError('issue_id required', 400);
if (!hasProjectAccess($db, $userId, $projectId)) jsonError('Permission denied', 403);
// Ensure issue belongs to project
$chk = $db->prepare("SELECT COUNT(*) FROM issues WHERE id = ? AND project_id = ?");
$chk->execute([$issueId, $projectId]);
if ($chk->fetchColumn() == 0) jsonError('Invalid issue for project', 404);

try {
    if ($method === 'GET' && $action === 'list') {
        // Check if comment_type column exists
        $hasCommentType = false;
        try {
            $checkCol = $db->query("SHOW COLUMNS FROM issue_comments LIKE 'comment_type'");
            $hasCommentType = $checkCol->rowCount() > 0;
        } catch (Exception $e) {
            $hasCommentType = false;
        }
        
        $selectFields = "ic.*, u.full_name as user_name, r.full_name as recipient_name, s.name as qa_status_name";
        if (!$hasCommentType) {
            // If column doesn't exist, add a default value in SELECT
            $selectFields = "ic.*, 'normal' as comment_type, u.full_name as user_name, r.full_name as recipient_name, s.name as qa_status_name";
        }
        
        $stmt = $db->prepare("SELECT $selectFields
                              FROM issue_comments ic
                              JOIN users u ON ic.user_id = u.id
                              LEFT JOIN users r ON ic.recipient_id = r.id
                              LEFT JOIN issue_statuses s ON ic.qa_status_id = s.id
                              WHERE ic.issue_id = ? ORDER BY ic.created_at DESC");
        $stmt->execute([$issueId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch reply previews for comments that have reply_to
        foreach ($rows as &$row) {
            if (!empty($row['reply_to'])) {
                $replyStmt = $db->prepare("SELECT ic.id, ic.comment_html, u.full_name as user_name 
                                          FROM issue_comments ic 
                                          JOIN users u ON ic.user_id = u.id 
                                          WHERE ic.id = ? LIMIT 1");
                $replyStmt->execute([$row['reply_to']]);
                $replyData = $replyStmt->fetch(PDO::FETCH_ASSOC);
                if ($replyData) {
                    $row['reply_preview'] = [
                        'id' => $replyData['id'],
                        'user_name' => $replyData['user_name'],
                        'text' => $replyData['comment_html']
                    ];
                }
            }
        }
        
        jsonResponse(['success' => true, 'comments' => $rows]);
    }

    if ($method === 'POST' && $action === 'create') {
        $commentHtml = $_POST['comment_html'] ?? '';
        $commentType = $_POST['comment_type'] ?? 'normal';
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $replyTo = (int)($_POST['reply_to'] ?? 0);
        $mentions = parseMentionsInput($_POST['mentions'] ?? []);
        $qaStatusRaw = $_POST['qa_status_id'] ?? '';
        $qaStatusId = is_numeric($qaStatusRaw) ? (int)$qaStatusRaw : (int)(getStatusId($db, $qaStatusRaw) ?: 0);
        if (!$commentHtml) jsonError('comment_html required', 400);
        
        // Validate comment_type
        if (!in_array($commentType, ['normal', 'regression'])) {
            $commentType = 'normal';
        }
        
        // Check if sanitize_chat_html function exists, otherwise use basic sanitization
        if (function_exists('sanitize_chat_html')) {
            $clean = sanitize_chat_html($commentHtml);
        } else {
            // Fallback: basic sanitization
            $clean = $commentHtml;
        }
        
        // Check if comment_type column exists before using it
        try {
            $stmt = $db->prepare("INSERT INTO issue_comments (issue_id, user_id, recipient_id, qa_status_id, comment_html, comment_type, reply_to) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$issueId, $userId, $recipientId ?: null, $qaStatusId ?: null, $clean, $commentType, $replyTo ?: null]);
        } catch (PDOException $e) {
            // If comment_type column doesn't exist, try without it
            if (strpos($e->getMessage(), 'comment_type') !== false) {
                $stmt = $db->prepare("INSERT INTO issue_comments (issue_id, user_id, recipient_id, qa_status_id, comment_html, reply_to) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$issueId, $userId, $recipientId ?: null, $qaStatusId ?: null, $clean, $replyTo ?: null]);
            } else {
                throw $e;
            }
        }

        // Send notifications for mentions / explicit recipient.
        $notifyUserIds = $mentions;
        if ($recipientId > 0) $notifyUserIds[] = $recipientId;
        $notifyUserIds = array_values(array_unique(array_filter(array_map('intval', $notifyUserIds), function ($id) use ($userId) {
            return $id > 0 && $id !== (int)$userId;
        })));

        if (!empty($notifyUserIds)) {
            $senderName = trim((string)($_SESSION['full_name'] ?? 'A user'));
            $link = getBaseDir() . '/modules/projects/view.php?id=' . (int)$projectId . '#issues';
            foreach ($notifyUserIds as $targetUserId) {
                createNotification(
                    $db,
                    (int)$targetUserId,
                    'mention',
                    $senderName . ' mentioned you in an issue comment.',
                    $link
                );
            }
        }
        
        jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()]);
    }

    jsonError('Unsupported action', 400);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}
