<?php
// modules/chat/project_chat.php

// Include configuration
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$baseDir = getBaseDir();
$viewerRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$viewerRole = preg_replace('/[^a-z0-9]+/', '_', $viewerRole);
$viewerRole = trim($viewerRole, '_');
$isAdminChatViewer = in_array($viewerRole, ['admin', 'super_admin'], true);

$embed = isset($_GET['embed']) && $_GET['embed'] === '1';

// Get project and page IDs
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$pageId = isset($_GET['page_id']) ? intval($_GET['page_id']) : 0;

// Connect to database
$db = Database::getInstance();

function chatColExistsLocal($db, $name) {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM chat_messages LIKE ?");
        $stmt->execute([$name]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function ensureReplyColumnLocal($db) {
    try {
        if (!chatColExistsLocal($db, 'reply_to')) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN reply_to INT NULL");
        }
    } catch (Exception $e) {
    }
}

function fetchChatMessageRowLocal($db, $messageId) {
    $mStmt = $db->prepare("
        SELECT cm.*, u.username, u.full_name, u.role
        FROM chat_messages cm
        JOIN users u ON cm.user_id = u.id
        WHERE cm.id = ?
        LIMIT 1
    ");
    $mStmt->execute([(int)$messageId]);
    return $mStmt->fetch(PDO::FETCH_ASSOC);
}

function isChatMessageDeletedLocal($row) {
    $deletedAt = trim((string)($row['deleted_at'] ?? ''));
    if ($deletedAt !== '') return true;
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string)($row['message'] ?? ''))));
    return strcasecmp($plain, 'Message deleted') === 0;
}

// Send message (non-AJAX fallback; AJAX handled via api/chat_actions, but keep safe here)
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_message'])) {
    $messageId = (int)($_POST['message_id'] ?? 0);
    $newMessage = trim((string)($_POST['message'] ?? ''));
    if ($messageId <= 0 || $newMessage === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $row = fetchChatMessageRowLocal($db, $messageId);
    if (!$row) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Message not found']);
        exit;
    }
    $isOwn = ((int)$row['user_id'] === $userId);
    if (!$isAdminChatViewer && !$isOwn) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    if (isChatMessageDeletedLocal($row)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Deleted message cannot be edited']);
        exit;
    }

    $updated = false;
    try {
        if (chatColExistsLocal($db, 'edited_at')) {
            $u = $db->prepare("UPDATE chat_messages SET message = ?, edited_at = NOW() WHERE id = ?");
            $updated = $u->execute([$newMessage, $messageId]);
        } else {
            $u = $db->prepare("UPDATE chat_messages SET message = ? WHERE id = ?");
            $updated = $u->execute([$newMessage, $messageId]);
        }
    } catch (Exception $e) {
        $updated = false;
    }
    if (!$updated) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to edit message']);
        exit;
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_message_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                action_type ENUM('edit','delete') NOT NULL,
                old_message MEDIUMTEXT NULL,
                new_message MEDIUMTEXT NULL,
                acted_by INT NOT NULL,
                acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $h = $db->prepare("INSERT INTO chat_message_history (message_id, action_type, old_message, new_message, acted_by) VALUES (?, 'edit', ?, ?, ?)");
        $h->execute([$messageId, $row['message'] ?? '', $newMessage, $userId]);
    } catch (Exception $e) {
    }

    $msgRow = fetchChatMessageRowLocal($db, $messageId);
    if ($msgRow) {
        $msgRow['message'] = sanitize_chat_html($msgRow['message'] ?? '');
        if (function_exists('rewrite_upload_urls_to_secure')) {
            $msgRow['message'] = rewrite_upload_urls_to_secure($msgRow['message']);
        }
        $isOwnRow = ((int)($msgRow['user_id'] ?? 0) === $userId);
        $isDeletedRow = isChatMessageDeletedLocal($msgRow);
        $msgRow['can_edit'] = (!$isDeletedRow && ($isOwnRow || $isAdminChatViewer));
        $msgRow['can_delete'] = (!$isDeletedRow && ($isOwnRow || $isAdminChatViewer));
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $msgRow]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
    $messageId = (int)($_POST['message_id'] ?? 0);
    if ($messageId <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $row = fetchChatMessageRowLocal($db, $messageId);
    if (!$row) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Message not found']);
        exit;
    }
    $isOwn = ((int)$row['user_id'] === $userId);
    if (!$isAdminChatViewer && !$isOwn) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    if (isChatMessageDeletedLocal($row)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    $oldMessageHtml = (string)($row['message'] ?? '');
    $deletedMsg = '<p><em>Message deleted</em></p>';
    $updated = false;
    try {
        if (chatColExistsLocal($db, 'deleted_at') && chatColExistsLocal($db, 'deleted_by')) {
            $u = $db->prepare("UPDATE chat_messages SET message = ?, deleted_at = NOW(), deleted_by = ? WHERE id = ?");
            $updated = $u->execute([$deletedMsg, $userId, $messageId]);
        } else {
            $u = $db->prepare("UPDATE chat_messages SET message = ? WHERE id = ?");
            $updated = $u->execute([$deletedMsg, $messageId]);
        }
    } catch (Exception $e) {
        $updated = false;
    }
    if (!$updated) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to delete message']);
        exit;
    }
    if (function_exists('delete_local_upload_files_from_html') && trim($oldMessageHtml) !== '') {
        delete_local_upload_files_from_html($oldMessageHtml, ['uploads/chat/', 'uploads/issues/']);
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_message_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                action_type ENUM('edit','delete') NOT NULL,
                old_message MEDIUMTEXT NULL,
                new_message MEDIUMTEXT NULL,
                acted_by INT NOT NULL,
                acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $h = $db->prepare("INSERT INTO chat_message_history (message_id, action_type, old_message, new_message, acted_by) VALUES (?, 'delete', ?, ?, ?)");
        $h->execute([$messageId, $row['message'] ?? '', $deletedMsg, $userId]);
    } catch (Exception $e) {
    }

    $msgRow = fetchChatMessageRowLocal($db, $messageId);
    if ($msgRow) {
        $msgRow['message'] = sanitize_chat_html($msgRow['message'] ?? '');
        if (function_exists('rewrite_upload_urls_to_secure')) {
            $msgRow['message'] = rewrite_upload_urls_to_secure($msgRow['message']);
        }
        $msgRow['can_edit'] = false;
        $msgRow['can_delete'] = false;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $msgRow]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_message_history')) {
    header('Content-Type: application/json');
    if (!$isAdminChatViewer) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $messageId = (int)($_GET['message_id'] ?? 0);
    if ($messageId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid message id']);
        exit;
    }
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_message_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                action_type ENUM('edit','delete') NOT NULL,
                old_message MEDIUMTEXT NULL,
                new_message MEDIUMTEXT NULL,
                acted_by INT NOT NULL,
                acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $stmt = $db->prepare("
            SELECT h.*, u.full_name AS acted_by_name
            FROM chat_message_history h
            LEFT JOIN users u ON u.id = h.acted_by
            WHERE h.message_id = ?
            ORDER BY h.acted_at DESC, h.id DESC
        ");
        $stmt->execute([$messageId]);
        echo json_encode(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'history' => []]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    ensureReplyColumnLocal($db);
    $replyTo = isset($_POST['reply_to']) && is_numeric($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
    if ($replyTo === null) {
        $replyToken = trim((string)($_POST['reply_token'] ?? ''));
        if (preg_match('/^r:(\d+)$/', $replyToken, $m)) {
            $replyTo = (int)$m[1];
        }
    }
    
    if (!empty($message)) {
        $userId = $_SESSION['user_id'];
        $mentions = [];
        
        // Parse mentions
        preg_match_all('/@(\w+)/', $message, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $username) {
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($user = $stmt->fetch()) {
                    $mentions[] = $user['id'];
                }
            }
        }
        
        try {
            try {
                $stmt = $db->prepare("
                    INSERT INTO chat_messages (project_id, page_id, user_id, message, mentions, reply_to)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $projectId ?: null,
                    $pageId ?: null,
                    $userId,
                    $message,
                    json_encode($mentions),
                    $replyTo ?: null
                ]);
            } catch (Exception $innerInsert) {
                $stmt = $db->prepare("
                    INSERT INTO chat_messages (project_id, page_id, user_id, message, mentions)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $projectId ?: null,
                    $pageId ?: null,
                    $userId,
                    $message,
                    json_encode($mentions)
                ]);
            }
            
            // If embed or ajax, return JSON to stay within widget
            if ($embed || $isAjax) {
                $lastId = (int)$db->lastInsertId();
                $msgRow = null;
                if ($lastId > 0) {
                    try {
                        $mStmt = $db->prepare("
                            SELECT cm.*, u.username, u.full_name, u.role
                            FROM chat_messages cm
                            JOIN users u ON cm.user_id = u.id
                            WHERE cm.id = ?
                            LIMIT 1
                        ");
                        $mStmt->execute([$lastId]);
                        $msgRow = $mStmt->fetch(PDO::FETCH_ASSOC);
                        if ($msgRow) {
                            $msgRow['message'] = sanitize_chat_html($msgRow['message'] ?? '');
                            if (function_exists('rewrite_upload_urls_to_secure')) {
                                $msgRow['message'] = rewrite_upload_urls_to_secure($msgRow['message']);
                            }
                            if (!empty($msgRow['reply_to'])) {
                                try {
                                    $pStmt = $db->prepare("
                                        SELECT cm.id, cm.user_id, cm.message, cm.created_at, u.username, u.full_name
                                        FROM chat_messages cm
                                        JOIN users u ON cm.user_id = u.id
                                        WHERE cm.id = ?
                                        LIMIT 1
                                    ");
                                    $pStmt->execute([(int)$msgRow['reply_to']]);
                                    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($pRow) {
                                        $pRow['message'] = sanitize_chat_html($pRow['message'] ?? '');
                                        if (function_exists('rewrite_upload_urls_to_secure')) {
                                            $pRow['message'] = rewrite_upload_urls_to_secure($pRow['message']);
                                        }
                                        $msgRow['reply_preview'] = [
                                            'id' => $pRow['id'],
                                            'user_id' => $pRow['user_id'],
                                            'username' => $pRow['username'],
                                            'full_name' => $pRow['full_name'],
                                            'message' => $pRow['message'],
                                            'created_at' => $pRow['created_at'] ?? null
                                        ];
                                    }
                                } catch (Exception $inner2) {
                                }
                            }
                            $isOwnRow = ((int)($msgRow['user_id'] ?? 0) === (int)$userId);
                            $isDeletedRow = isChatMessageDeletedLocal($msgRow);
                            $msgRow['can_edit'] = (!$isDeletedRow && ($isOwnRow || $isAdminChatViewer));
                            $msgRow['can_delete'] = (!$isDeletedRow && ($isOwnRow || $isAdminChatViewer));
                        }
                    } catch (Exception $inner) {
                        $msgRow = null;
                    }
                }
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'id' => $lastId, 'message' => $msgRow]);
                exit;
            }
            // Redirect to avoid resubmission (full page only)
            $redirect_url = $baseDir . "/modules/chat/project_chat.php";
            if ($projectId) {
                $redirect_url .= "?project_id=" . $projectId;
            }
            if ($pageId) {
                $redirect_url .= ($projectId ? "&" : "?") . "page_id=" . $pageId;
            }
            header("Location: " . $redirect_url);
            exit;
            
        } catch (Exception $e) {
            if ($embed || $isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error = "Failed to send message: " . $e->getMessage();
        }
    }
}

// Get chat messages
try {
    if ($pageId > 0) {
        // Page-level chat
        $stmt = $db->prepare("
            SELECT cm.*, u.username, u.full_name, u.role
            FROM chat_messages cm
            JOIN users u ON cm.user_id = u.id
            WHERE cm.page_id = ?
            ORDER BY cm.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$pageId]);
    } elseif ($projectId > 0) {
        // Project-level chat
        $stmt = $db->prepare("
            SELECT cm.*, u.username, u.full_name, u.role
            FROM chat_messages cm
            JOIN users u ON cm.user_id = u.id
            WHERE cm.project_id = ? AND cm.page_id IS NULL
            ORDER BY cm.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$projectId]);
    } else {
        // General chat
        $stmt = $db->prepare("
            SELECT cm.*, u.username, u.full_name, u.role
            FROM chat_messages cm
            JOIN users u ON cm.user_id = u.id
            WHERE cm.project_id IS NULL AND cm.page_id IS NULL
            ORDER BY cm.created_at DESC
            LIMIT 50
        ");
        $stmt->execute();
    }
    
    $messages = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "Failed to load messages: " . $e->getMessage();
    $messages = [];
}

// Get project info if project ID is provided
$project = null;
if ($projectId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, title, po_number FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
    } catch (Exception $e) {
        // Silently fail, project will be null
    }
}

// Get page info if page ID is provided
$page = null;
if ($pageId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, page_name, project_id FROM project_pages WHERE id = ?");
        $stmt->execute([$pageId]);
        $page = $stmt->fetch();
        
        // If we have page but not project, get project from page
        if ($page && !$project && $page['project_id']) {
            $stmt = $db->prepare("SELECT id, title, po_number FROM projects WHERE id = ?");
            $stmt->execute([$page['project_id']]);
            $project = $stmt->fetch();
        }
    } catch (Exception $e) {
        // Silently fail, page will be null
    }
}

// Get online users (users active in last 5 minutes)
$onlineUsers = [];
try {
    $stmt = $db->prepare("
        SELECT DISTINCT u.id, u.username, u.full_name, u.role
        FROM users u
        JOIN activity_log al ON u.id = al.user_id
        WHERE al.created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        AND u.is_active = 1
        ORDER BY u.role, u.full_name
    ");
    $stmt->execute();
    $onlineUsers = $stmt->fetchAll();
} catch (Exception $e) {
    // Silently fail, onlineUsers will be empty
}

// Build mention list (project members + project lead) or all active users as fallback
$mentionUsers = [];
try {
    if ($projectId > 0) {
        $mentionStmt = $db->prepare("
            SELECT DISTINCT u.id, u.username, u.full_name
            FROM user_assignments ua
            JOIN users u ON ua.user_id = u.id
            WHERE ua.project_id = ? AND u.is_active = 1
            UNION
            SELECT u.id, u.username, u.full_name
            FROM projects p
            JOIN users u ON p.project_lead_id = u.id
            WHERE p.id = ? AND p.project_lead_id IS NOT NULL AND u.is_active = 1
            UNION
            SELECT u.id, u.username, u.full_name
            FROM users u
            WHERE u.is_active = 1 AND u.role IN ('admin', 'super_admin')
        ");
        $mentionStmt->execute([$projectId, $projectId]);
    } elseif ($page && !empty($page['project_id'])) {
        $mentionStmt = $db->prepare("
            SELECT DISTINCT u.id, u.username, u.full_name
            FROM user_assignments ua
            JOIN users u ON ua.user_id = u.id
            WHERE ua.project_id = ? AND u.is_active = 1
            UNION
            SELECT u.id, u.username, u.full_name
            FROM users u
            WHERE u.is_active = 1 AND u.role IN ('admin', 'super_admin')
        ");
        $mentionStmt->execute([$page['project_id']]);
    } else {
        $mentionStmt = $db->prepare("SELECT id, username, full_name FROM users WHERE is_active = 1 LIMIT 50");
        $mentionStmt->execute();
    }
    $mentionUsers = $mentionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    usort($mentionUsers, function($a, $b) {
        $aU = strtolower((string)($a['username'] ?? ''));
        $bU = strtolower((string)($b['username'] ?? ''));
        $aIsAdmin = in_array($aU, ['admin', 'super_admin', 'superadmin'], true);
        $bIsAdmin = in_array($bU, ['admin', 'super_admin', 'superadmin'], true);
        if ($aIsAdmin !== $bIsAdmin) {
            return $aIsAdmin ? -1 : 1;
        }
        return strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
    });
} catch (Exception $e) {
    $mentionUsers = [];
}

// Ensure baseDir available
if (!isset($baseDir)) {
    require_once __DIR__ . '/../../includes/helpers.php';
    $baseDir = getBaseDir();
}

// Set page title and output head
$pageTitle = 'Chat - Project Management System';

if (!$embed) {
    include __DIR__ . '/../../includes/header.php';
    ?>
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <?php
} else {
    ?><!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Project Chat</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
        <script src="https://code.jquery.com/jquery-3.6.0.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js" crossorigin="anonymous"></script>
        <script>
            // Basic CDN fallback for jQuery/Summernote in embed mode
            (function() {
                if (!window.jQuery) {
                    var jq = document.createElement('script');
                    jq.src = 'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js';
                    document.head.appendChild(jq);
                }
            })();
        </script>
        <style>html,body{height:100%;margin:0;} body{background:#f8f9fa;overflow:hidden;} .container-embed{padding:8px;height:100%;}</style>
    </head>
    <body class="chat-embed">
    <div class="container-fluid container-embed chat-shell">
    <?php
}

?>

<style>
    .chat-container {
        height: 500px;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        padding: 15px;
        background-color: #f8f9fa;
    }
    .message {
        margin-bottom: 15px;
        padding: 10px;
        border-radius: 12px;
        background-color: white;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        max-width: 90%;
    }
    .message-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 8px;
        margin-bottom: 5px;
    }
    .message-header-right { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; flex-shrink: 0; }
    .message-actions { display: inline-flex; align-items: center; gap: 4px; }
    .chat-action-btn {
        border: 0;
        background: transparent;
        color: #0d6efd;
        width: 24px;
        height: 24px;
        padding: 0;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
    }
    .chat-action-btn:hover, .chat-action-btn:focus { background: rgba(13, 110, 253, 0.12); color: #0a58ca; }
    .chat-action-btn.chat-delete { color: #dc3545; }
    .chat-action-btn.chat-delete:hover, .chat-action-btn.chat-delete:focus { background: rgba(220, 53, 69, 0.12); color: #b02a37; }
    .message-sender { font-weight: bold; color: #333; }
    .message-time { font-size: 0.85em; color: #6c757d; }
    .message-content { word-wrap: break-word; }
    .reply-preview { border-left: 3px solid #e9ecef; background: #f8f9fa; padding:6px; margin-bottom:8px; }
    .mention { background-color: #fff3cd; padding: 2px 4px; border-radius: 3px; font-weight: bold; }
    .user-badge { font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
    .message-meta { font-size: 0.8em; color: #6c757d; margin-top: 4px; text-align: right; }
    .message:focus,
    .message:focus-visible {
        outline: 3px solid #0d6efd;
        outline-offset: 2px;
    }

    /* Embed chat layout */
    .chat-embed { height: 100%; overflow: hidden; }
    .chat-embed body { background: #ece5dd; margin: 0; overflow: hidden; }
    .chat-embed .chat-shell { background: #ece5dd; border-radius: 16px; padding: 8px; height: 100%; overflow: hidden; }
    .chat-embed .chat-embed-wrapper { display: flex; flex-direction: column; height: 100%; min-height: 0; background: #ece5dd; position: relative; overflow: hidden; }
    .chat-embed .chat-container { background: transparent; border: 0; box-shadow: none; padding: 6px; flex: 1; overflow-y: auto; }
    .chat-embed .message { box-shadow: none; border: 0; padding: 8px 12px; position: relative; }
    .chat-embed .message.other-message { background: #fff; margin-right: auto; }
    .chat-embed .message.own-message { background: #dcf8c6; margin-left: auto; }
    .chat-embed .message .message-content { font-size: 0.95rem; }
    .chat-embed .message .message-meta { font-size: 0.78rem; color: #6c757d; text-align: right; margin-top: 4px; }
    .chat-embed .message-header .user-badge { display: none; }
    .chat-embed .chat-embed-form { background: #f0f2f5; border-radius: 14px 14px 0 0; padding: 8px; box-shadow: 0 -4px 14px rgba(0,0,0,0.10); position: sticky; bottom: 0; z-index: 20; }
    .chat-embed .chat-embed-form.collapsed { padding: 6px 8px; }
    .chat-embed #chatForm { position: relative; }
    #chatForm { position: relative; }
    .chat-embed .note-editor.note-frame { background: transparent; }
    .chat-embed .note-statusbar { display: none; }
    .chat-embed .note-toolbar { border: 0; background: transparent; padding: 4px 0 0 0; display: flex; flex-wrap: nowrap; overflow-x: auto; gap: 4px; }
    .chat-embed .note-toolbar .note-btn-group { float: none; display: inline-flex; flex-wrap: nowrap; }
    .chat-embed .note-editor { border: 0; box-shadow: none; resize: vertical; overflow: auto; }
    .chat-embed .note-editable { min-height: 40px; background: #fff; border-radius: 10px; }
    .chat-embed .btn { border-radius: 999px; }
    .chat-embed .chat-compose-toggle {
        width: 100%;
        border-radius: 12px;
        border: 1px solid #ced4da;
        background: #ffffff;
        color: #0d6efd;
        font-weight: 600;
        margin: 0;
        padding: 8px 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        visibility: visible;
        opacity: 1;
    }
    .chat-embed .chat-compose-toggle:focus,
    .chat-embed .chat-compose-toggle:focus-visible {
        outline: 3px solid #0d6efd !important;
        outline-offset: 2px;
        box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.25);
    }
    .chat-embed .chat-compose-toggle.expanded { margin-bottom: 8px; }
    .chat-embed .chat-container { padding-bottom: 10px; }
    .chat-compose-body { display: none; }
    .chat-compose-body.open { display: block; padding-bottom: 24px; }
    .chat-compose-toggle { width: 100%; }
    .chat-message img, .message-content img { max-width: 100%; height: auto; }
    .chat-image-wrap { position: relative; display: inline-block; }
    .chat-image-thumb { max-width: 100%; max-height: 180px; object-fit: contain; display: block; }
    .chat-image-full-btn { position: absolute; top: 6px; right: 6px; padding: 6px; width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.92); box-shadow: 0 1px 4px rgba(0,0,0,0.2); border: 1px solid rgba(0,0,0,0.05); display: inline-flex; align-items: center; justify-content: center; }
    .chat-image-full-btn i { font-size: 0.85rem; }
    #chatModalImg { width: 100%; height: auto; }
</style>
<?php $lastMessageId = 0; ?>

<?php if ($embed): ?>
    <div class="chat-embed-wrapper">
        <div class="chat-container mb-2" id="chatMessages">
            <?php if (empty($messages)): ?>
            <div class="text-center text-muted py-4 no-messages">
                <i class="fas fa-comment-slash fa-2x mb-2"></i>
                <div>No messages yet. Start chatting!</div>
            </div>
            <?php else: ?>
                <?php foreach (array_reverse($messages) as $msg):
                    if ($msg['id'] > $lastMessageId) { $lastMessageId = $msg['id']; }
                    $isMentioned = false;
                    if ($msg['mentions']) {
                        $mentionIds = json_decode($msg['mentions'], true);
                        if (is_array($mentionIds) && in_array($_SESSION['user_id'], $mentionIds)) {
                            $isMentioned = true;
                        }
                    }
                    $isOwn = ((int)$msg['user_id'] === (int)$_SESSION['user_id']);
                ?>
                <div class="message <?php echo $isOwn ? 'own-message' : 'other-message'; ?> <?php echo $isMentioned ? 'border-start border-warning border-4 bg-light' : ''; ?>" data-id="<?php echo $msg['id']; ?>">
                    <div class="message-header">
                        <div>
                            <span class="message-sender text-muted small"><?php echo htmlspecialchars($msg['full_name']); ?></span>
                            <small class="text-muted">@<?php echo htmlspecialchars($msg['username']); ?></small>
                        </div>
                        <div class="message-header-right">
                            <div class="message-time"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                            <?php
                                $isOwn = ((int)$msg['user_id'] === (int)$_SESSION['user_id']);
                                $isDeleted = isChatMessageDeletedLocal($msg);
                                $canManage = (!$isDeleted && ($isOwn || $isAdminChatViewer));
                            ?>
                            <div class="message-actions">
                                <button type="button" class="chat-action-btn chat-reply" title="Reply" aria-label="Reply to message" data-mid="<?php echo (int)$msg['id']; ?>" data-username="<?php echo htmlspecialchars($msg['username']); ?>" data-message="<?php echo htmlspecialchars($msg['message']); ?>"><i class="fas fa-reply"></i></button>
                                <?php if ($canManage): ?>
                                    <button type="button" class="chat-action-btn chat-edit" title="Edit" aria-label="Edit message" data-mid="<?php echo (int)$msg['id']; ?>" data-message="<?php echo htmlspecialchars($msg['message']); ?>"><i class="fas fa-pen"></i></button>
                                    <button type="button" class="chat-action-btn chat-delete" title="Delete" aria-label="Delete message" data-mid="<?php echo (int)$msg['id']; ?>"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                                <?php if ($isAdminChatViewer): ?>
                                    <button type="button" class="chat-action-btn chat-history" title="History" aria-label="View message history" data-mid="<?php echo (int)$msg['id']; ?>"><i class="fas fa-history"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="message-content">
                        <?php
                        $messageHtml = sanitize_chat_html($msg['message']);
                        $messageHtml = rewrite_upload_urls_to_secure($messageHtml);
                        $messageHtml = preg_replace('/@(\w+)/', '<span class="mention">@$1</span>', $messageHtml);
                        $messageHtml = preg_replace_callback('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', function($m) {
                            $src = $m[1];
                            $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                            $imgTag = $m[0];
                            if (stripos($imgTag, 'class=') === false) {
                                $imgTag = preg_replace('/<img/i', '<img class="chat-image-thumb"', $imgTag, 1);
                            } else {
                                $imgTag = preg_replace('/class=["\']([^"\']*)["\']/', 'class="$1 chat-image-thumb"', $imgTag, 1);
                            }
                            return '<span class="chat-image-wrap">' . $imgTag . '<button type="button" class="btn btn-light btn-sm chat-image-full-btn" data-src="' . $safeSrc . '" aria-label="View full image"><i class="fas fa-up-right-from-square"></i></button></span>';
                        }, $messageHtml);
                        if (!empty($msg['reply_to'])) {
                            $pr = null;
                            try {
                                $pstmt = $db->prepare("SELECT cm.id, cm.user_id, cm.message, cm.created_at, u.username, u.full_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                                $pstmt->execute([$msg['reply_to']]);
                                $pr = $pstmt->fetch();
                            } catch (Exception $e) { $pr = null; }
                            if ($pr) {
                                $pmsg = sanitize_chat_html($pr['message']);
                                $pmsg = rewrite_upload_urls_to_secure($pmsg);
                                $ptime = !empty($pr['created_at']) ? date('M d, H:i', strtotime($pr['created_at'])) : '';
                                echo '<div class="reply-preview"><strong>' . htmlspecialchars($pr['full_name']) . '</strong>' . ($ptime ? ' <small class="text-muted ms-2">' . htmlspecialchars($ptime) . '</small>' : '') . ': ' . $pmsg . '</div>';
                            }
                        }
                        echo $messageHtml;
                        ?>
                        <div class="message-meta small text-muted"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form id="chatForm" class="chat-embed-form" method="POST">
            <input type="hidden" name="send_message" value="1">
            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
            <input type="hidden" name="page_id" value="<?php echo $pageId; ?>">
            <button type="button" class="btn btn-sm chat-compose-toggle" id="composeToggle">
                <i class="fas fa-comment-dots"></i> Compose
            </button>
            <div class="chat-compose-body" id="composeBody">
                <div class="mb-2">
                    <textarea 
                        class="form-control" 
                        id="message" 
                        name="message"
                        placeholder="Type a message"
                    ></textarea>
                </div>
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <button type="submit" class="btn btn-success btn-sm" id="sendBtn">
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                    <span class="text-muted small" id="charCount">0/1000</span>
                </div>
            </div>
            <div id="mentionDropdown" class="dropdown-menu" style="display:none; position:absolute; z-index: 1050; max-height:180px; overflow-y:auto;"></div>
        </form>
    </div>
<?php else: ?>
    <div class="container-fluid">
        <div class="row">
            <!-- Main Chat Area -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-comments"></i>
                            <?php
                            if ($page) {
                                echo "Chat: " . htmlspecialchars($page['page_name']);
                            } elseif ($project) {
                                echo "Project Chat: " . htmlspecialchars($project['title']);
                            } else {
                                echo "General Chat";
                            }
                            ?>
                        </h5>
                        <?php if ($project): ?>
                        <small class="text-light">
                            Project: <?php echo htmlspecialchars($project['title']); ?>
                            (<?php echo htmlspecialchars($project['po_number']); ?>)
                        </small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body">
                        <!-- Error Message -->
                        <?php if (isset($error)): ?>
                        <div class="alert alert-danger" id="chatError" style="display:none;">
                            <i class="fas fa-exclamation-triangle"></i> <span class="error-text"></span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Chat Messages -->
                        <div class="chat-container mb-3" id="chatMessages">
                            <?php if (empty($messages)): ?>
                            <div class="text-center text-muted py-5 no-messages">
                                <i class="fas fa-comment-slash fa-3x mb-3"></i>
                                <p>No messages yet. Start the conversation!</p>
                            </div>
                            <?php else: ?>
                                <?php 
                                foreach (array_reverse($messages) as $msg): 
                                    if ($msg['id'] > $lastMessageId) { $lastMessageId = $msg['id']; }
                                    $isMentioned = false;
                                    if ($msg['mentions']) {
                                        $mentionIds = json_decode($msg['mentions'], true);
                                        if (is_array($mentionIds) && in_array($_SESSION['user_id'], $mentionIds)) {
                                            $isMentioned = true;
                                        }
                                    }
                                    $isOwn = ((int)$msg['user_id'] === (int)$_SESSION['user_id']);
                                ?>
                                <div class="message <?php echo $isOwn ? 'own-message' : 'other-message'; ?> <?php echo $isMentioned ? 'border-start border-warning border-4 bg-light' : ''; ?>" data-id="<?php echo $msg['id']; ?>">
                                    <div class="message-header">
                                        <div>
                                            <span class="message-sender">
                                                <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $msg['user_id']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($msg['full_name']); ?>
                                                </a>
                                            </span>
                                            <span class="badge user-badge bg-<?php
                                                echo $msg['role'] == 'admin' ? 'danger' :
                                                     ($msg['role'] == 'project_lead' ? 'warning' : 'info');
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $msg['role'])); ?>
                                            </span>
                                            <small class="text-muted">@<?php echo htmlspecialchars($msg['username']); ?></small>
                                        </div>
                                        <div class="message-header-right">
                                            <div class="message-time"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                                            <?php
                                                $isOwn = ((int)$msg['user_id'] === (int)$_SESSION['user_id']);
                                                $isDeleted = isChatMessageDeletedLocal($msg);
                                                $canManage = (!$isDeleted && ($isOwn || $isAdminChatViewer));
                                            ?>
                                            <div class="message-actions">
                                                <button type="button" class="chat-action-btn chat-reply" title="Reply" aria-label="Reply to message" data-mid="<?php echo (int)$msg['id']; ?>" data-username="<?php echo htmlspecialchars($msg['username']); ?>" data-message="<?php echo htmlspecialchars($msg['message']); ?>"><i class="fas fa-reply"></i></button>
                                                <?php if ($canManage): ?>
                                                    <button type="button" class="chat-action-btn chat-edit" title="Edit" aria-label="Edit message" data-mid="<?php echo (int)$msg['id']; ?>" data-message="<?php echo htmlspecialchars($msg['message']); ?>"><i class="fas fa-pen"></i></button>
                                                    <button type="button" class="chat-action-btn chat-delete" title="Delete" aria-label="Delete message" data-mid="<?php echo (int)$msg['id']; ?>"><i class="fas fa-trash"></i></button>
                                                <?php endif; ?>
                                                <?php if ($isAdminChatViewer): ?>
                                                    <button type="button" class="chat-action-btn chat-history" title="History" aria-label="View message history" data-mid="<?php echo (int)$msg['id']; ?>"><i class="fas fa-history"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="message-content">
                                        <?php
                                        $messageHtml = sanitize_chat_html($msg['message']);
                                        $messageHtml = rewrite_upload_urls_to_secure($messageHtml);
                                        // Highlight mentions server-side
                                        $messageHtml = preg_replace('/@(\w+)/', '<span class="mention">@$1</span>', $messageHtml);
                                        $messageHtml = preg_replace_callback('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', function($m) {
                                            $src = $m[1];
                                            $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                                            $imgTag = $m[0];
                                            if (stripos($imgTag, 'class=') === false) {
                                                $imgTag = preg_replace('/<img/i', '<img class="chat-image-thumb"', $imgTag, 1);
                                            } else {
                                                $imgTag = preg_replace('/class=["\']([^"\']*)["\']/', 'class="$1 chat-image-thumb"', $imgTag, 1);
                                            }
                                            return '<span class="chat-image-wrap">' . $imgTag . '<button type="button" class="btn btn-light btn-sm chat-image-full-btn" data-src="' . $safeSrc . '" aria-label="View full image"><i class="fas fa-up-right-from-square"></i></button></span>';
                                        }, $messageHtml);
                                        // If this message is a reply, include preview
                                        if (!empty($msg['reply_to'])) {
                                            // fetch preview if available
                                            $pr = null;
                                            try {
                                                $pstmt = $db->prepare("SELECT cm.id, cm.user_id, cm.message, cm.created_at, u.username, u.full_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                                                $pstmt->execute([$msg['reply_to']]);
                                                $pr = $pstmt->fetch();
                                            } catch (Exception $e) { $pr = null; }
                                            if ($pr) {
                                                $pmsg = sanitize_chat_html($pr['message']);
                                                $pmsg = rewrite_upload_urls_to_secure($pmsg);
                                                $ptime = !empty($pr['created_at']) ? date('M d, H:i', strtotime($pr['created_at'])) : '';
                                                echo '<div class="reply-preview"><strong>' . htmlspecialchars($pr['full_name']) . '</strong>' . ($ptime ? ' <small class="text-muted ms-2">' . htmlspecialchars($ptime) . '</small>' : '') . ': ' . $pmsg . '</div>';
                                            }
                                        }
                                        echo $messageHtml;
                                        ?>
                                        <div class="message-meta small text-muted"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Message Form -->
                        <form id="chatForm" class="mt-3" method="POST">
                            <input type="hidden" name="send_message" value="1">
                            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                            <input type="hidden" name="page_id" value="<?php echo $pageId; ?>">
                            <div class="mb-3">
                                    <label for="message" class="form-label">Your Message</label>
                                    <textarea 
                                        class="form-control" 
                                        id="message" 
                                        name="message"
                                        placeholder="Type your message here... Use @username to mention someone."
                                    ></textarea>
                                    <small class="text-muted">Mention users with @username. You can paste images.</small>
                                </div>
                            <div class="d-flex justify-content-between">
                                <div>
                                    <button type="submit" class="btn btn-primary" id="sendBtn">
                                        <i class="fas fa-paper-plane"></i> Send Message
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="clearMessage">
                                        <i class="fas fa-times"></i> Clear
                                    </button>
                                </div>
                                <div>
                                    <span class="text-muted" id="charCount">0/1000</span>
                                </div>
                            </div>
                            <div id="mentionDropdown" class="dropdown-menu" style="display:none; position:absolute; z-index: 1050; max-height:180px; overflow-y:auto;"></div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="col-md-4">
                <!-- Online Users -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-users"></i> Online Users
                            <span class="badge bg-success" id="onlineCount"><?php echo count($onlineUsers); ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div id="onlineUsersList">
                        <?php if (empty($onlineUsers)): ?>
                        <p class="text-muted">No users online</p>
                        <?php else: ?>
                            <?php foreach ($onlineUsers as $user): ?>
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-success me-2">●</span>
                                <div>
                                    <strong>
                                        <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $user['id']; ?>" class="text-decoration-none text-dark">
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </a>
                                    </strong>
                                    <small class="text-muted d-block">
                                        @<?php echo htmlspecialchars($user['username']); ?> • 
                                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                    </small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary ms-auto mention-user" 
                                        data-username="@<?php echo htmlspecialchars($user['username']); ?>">
                                    @
                                </button>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Chat Info -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Chat Information</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($project): ?>
                        <p><strong>Project:</strong> <?php echo htmlspecialchars($project['title']); ?></p>
                        <p><strong>Project Code:</strong> <?php echo htmlspecialchars($project['po_number']); ?></p>
                        <?php endif; ?>
                        
                        <?php if ($page): ?>
                        <p><strong>Page:</strong> <?php echo htmlspecialchars($page['page_name']); ?></p>
                        <?php endif; ?>
                        
                        <p><strong>Your Role:</strong> 
                            <span class="badge bg-<?php
                                echo $_SESSION['role'] == 'admin' ? 'danger' :
                                     ($_SESSION['role'] == 'project_lead' ? 'warning' : 'info');
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>
                            </span>
                        </p>
                        
                        <div class="mt-3">
                            <h6>Quick Actions:</h6>
                            <div class="d-grid gap-2">
                                <?php if ($project): ?>
                                <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to Project
                                </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-info" id="refreshChat">
                                    <i class="fas fa-sync-alt"></i> Force Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($embed): ?>
    </div> <!-- /.container-embed -->
<?php endif; ?>

<!-- Image modal for full view -->
<div class="modal fade" id="chatImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img id="chatModalImg" src="" alt="Full image" />
            </div>
        </div>
    </div>
</div>

<?php if ($isAdminChatViewer): ?>
<div class="modal fade" id="chatHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Message History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="chatHistoryBody">
                <p class="text-muted mb-0">Loading...</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="chatEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="chatEditMessageId" value="">
                <label for="chatEditMessageInput" class="form-label">Message</label>
                <textarea id="chatEditMessageInput" class="form-control" rows="4"></textarea>
                <div class="small text-muted mt-1" id="chatEditCharCount">0/1000</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="chatEditSaveBtn">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="chatDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="chatDeleteMessageId" value="">
                <p class="mb-0">Are you sure you want to delete this message?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="chatDeleteConfirmBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="chatActionStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="chatActionStatusTitle">Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="chatActionStatusText">Unable to complete this action.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

    <script>
    (function(initFn){
        if (window.jQuery) {
            jQuery(initFn);
        } else {
            document.addEventListener('DOMContentLoaded', initFn);
        }
    })(function(){
        const hasJQ = !!window.jQuery;
        const $ = window.jQuery;
        const chatMessages = hasJQ ? $('#chatMessages') : document.querySelector('#chatMessages');
        const currentUserId = Number(<?php echo $_SESSION['user_id']; ?>);
        const currentUserRole = <?php echo json_encode($_SESSION['role'] ?? ''); ?>;
        const canViewHistoryAdmin = <?php echo $isAdminChatViewer ? 'true' : 'false'; ?>;
        const projectId = <?php echo $projectId ?: 'null'; ?>;
        const pageId = <?php echo $pageId ?: 'null'; ?>;
        let lastMessageId = <?php echo $lastMessageId ?? 0; ?>;
        const mentionUsers = <?php echo json_encode($mentionUsers); ?>;
        const mdRawList = Array.from(document.querySelectorAll('#mentionDropdown'));
        const mentionDropdownEl = mdRawList.length ? mdRawList[mdRawList.length - 1] : null;
        const mentionDropdown = hasJQ ? (mentionDropdownEl ? $(mentionDropdownEl) : $('#mentionDropdown')) : mentionDropdownEl;
        let mentionIndex = -1;
        let lastMentionAnchor = null;
        let lastMentionRange = null;
        let mentionSearchDisabled = false;
        let activeReplyToId = null;

        function isMentionVisible() {
            if (hasJQ) return mentionDropdown && mentionDropdown.length && mentionDropdown.is(':visible');
            return mentionDropdown && mentionDropdown.style.display !== 'none';
        }

        function scrollToBottom() {
            try {
                if (hasJQ) {
                    chatMessages.scrollTop(chatMessages[0].scrollHeight);
                } else if (chatMessages) {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
            } catch (e) {}
        }
        scrollToBottom();

        function enhanceImages(container) {
            if (!container) return;
            const imgs = hasJQ ? $(container).find('.message-content img').toArray() : Array.from(container.querySelectorAll('.message-content img'));
            imgs.forEach(processImg);
        }

        function processImg(img) {
            if (!img) return;
            const src = img.getAttribute('src') || '';
            let wrap = (img.closest && img.closest('.chat-image-wrap')) || null;
            if (!wrap) {
                wrap = document.createElement('span');
                wrap.className = 'chat-image-wrap';
                if (img.parentNode) {
                    img.parentNode.insertBefore(wrap, img);
                    wrap.appendChild(img);
                }
            }
            if (!wrap) return;
            img.classList.add('chat-image-thumb');
            let btn = wrap.querySelector('.chat-image-full-btn');
            if (!btn && src) {
                btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-light btn-sm chat-image-full-btn';
                btn.dataset.src = src;
                btn.setAttribute('aria-label', 'View full image');
                btn.innerHTML = '<i class="fas fa-up-right-from-square"></i>';
                wrap.appendChild(btn);
            } else if (btn && src) {
                btn.dataset.src = src;
            }
        }

        function showImageModal(src) {
            if (!src) return;
            const modalEl = document.getElementById('chatImageModal');
            const modalImg = document.getElementById('chatModalImg');
            if (modalImg) modalImg.src = src;
            if (window.bootstrap && modalEl) {
                const m = bootstrap.Modal.getOrCreateInstance(modalEl);
                m.show();
            } else {
                window.open(src, '_blank');
            }
        }

        enhanceImages(hasJQ ? chatMessages[0] : chatMessages);

        // Watch for future images (including immediately after paste/send fetch)
        if (chatMessages && typeof MutationObserver !== 'undefined') {
            const obs = new MutationObserver(() => {
                enhanceImages(hasJQ ? chatMessages[0] : chatMessages);
            });
            obs.observe(hasJQ ? chatMessages[0] : chatMessages, { childList: true, subtree: true });
        }

        // Periodic fallback enhancer to catch any missed images
        let enhanceTicks = 0;
        const enhanceInterval = setInterval(() => {
            enhanceImages(hasJQ ? chatMessages[0] : chatMessages);
            enhanceTicks++;
            if (enhanceTicks > 5) clearInterval(enhanceInterval);
        }, 800);

        // Upload image and insert URL into target summernote editor
        function uploadAndInsertImage(file, $targetEditor) {
            if (!file) return;
            if (file.type && !file.type.startsWith('image/')) {
                showToast('Only image files are allowed', 'warning');
                return;
            }
            const formData = new FormData();
            formData.append('image', file);
            return fetch('<?php echo $baseDir; ?>/api/chat_upload_image.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }).then(res => {
                return res.text().then(txt => {
                    try {
                        return JSON.parse(txt);
                    } catch (e) {
                        return { error: 'Upload failed (invalid server response)' };
                    }
                });
            }).then(res => {
                if (res && res.success && res.url) {
                    const $target = ($targetEditor && $targetEditor.length) ? $targetEditor : $msg;
                    if ($target && $target.length && $target.data('summernote')) {
                        $target.summernote('pasteHTML', '<p><img src="' + res.url + '" alt="image" /></p>');
                    }
                } else if (res && res.error) {
                    showToast(res.error, 'danger');
                }
            }).catch(err => {
                console.error('Image upload failed', err);
                showToast('Image upload failed', 'danger');
            });
        }

        // Initialize Summernote editor (embed/full)
        let summernoteReady = false;
        const $msg = hasJQ ? $('#message') : null;
        const isEmbed = document.body.classList.contains('chat-embed');
        const composeBody = document.getElementById('composeBody');
        let composeToggle = document.getElementById('composeToggle');
        const composeForm = document.getElementById('chatForm');
        if (!composeToggle && composeForm) {
            composeToggle = document.createElement('button');
            composeToggle.type = 'button';
            composeToggle.id = 'composeToggle';
            composeToggle.className = 'btn btn-sm chat-compose-toggle';
            composeToggle.innerHTML = '<i class="fas fa-comment-dots"></i> Compose';
            if (composeBody && composeBody.parentNode === composeForm) {
                composeForm.insertBefore(composeToggle, composeBody);
            } else {
                composeForm.appendChild(composeToggle);
            }
        }
        let composeCollapsed = isEmbed ? true : false;
        let messageKeyboardBound = false;
        let initialRecentMessageFocused = false;
        function focusFirstComposeControl() {
            if (composeCollapsed) return;
            let target = null;
            if (summernoteReady && $msg && $msg.length) {
                const $toolbarItems = $msg.next('.note-editor').find('.note-toolbar .note-btn-group button').filter(function() {
                    const $b = $(this);
                    return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length;
                });
                if ($toolbarItems.length) target = $toolbarItems.get(0);
            }
            if (!target) {
                target = document.querySelector('#composeBody #message, #composeBody .note-editable, #composeBody #sendBtn');
            }
            if (!target && summernoteReady && $msg && $msg.length) {
                const editable = $msg.next('.note-editor').find('.note-editable[contenteditable="true"]').get(0);
                if (editable) target = editable;
            }
            if (target && typeof target.focus === 'function') {
                try { target.focus(); } catch (e) {}
            }
        }

        function focusComposeEditable() {
            let target = null;
            if (summernoteReady && $msg && $msg.length) {
                target = $msg.next('.note-editor').find('.note-editable[contenteditable="true"]').get(0);
            }
            if (!target) {
                target = document.querySelector('#composeBody #message, #composeBody .note-editable');
            }
            if (target && typeof target.focus === 'function') {
                try { target.focus(); } catch (e) {}
            }
        }
        function applyEmbedTabOrder() {
            if (!isEmbed) return;
            if (composeToggle && composeToggle.hasAttribute('tabindex')) {
                composeToggle.removeAttribute('tabindex');
            }
            const composeInteractive = document.querySelectorAll(
                '#composeBody button, #composeBody input, #composeBody textarea, #composeBody select, #composeBody [contenteditable="true"]'
            );
            composeInteractive.forEach(function (el) {
                if (!el) return;
                if (el.id === 'composeToggle') return;
                if (el.classList && el.classList.contains('note-btn')) return;
                if (el.hasAttribute('tabindex')) el.removeAttribute('tabindex');
            });
            const rows = document.querySelectorAll('#chatMessages .message');
            rows.forEach(function (row) {
                if (!row.hasAttribute('tabindex')) row.setAttribute('tabindex', '-1');
            });
        }

        function getMessageRows() {
            return Array.from(document.querySelectorAll('#chatMessages .message'));
        }

        function getMessageActionButtons(row) {
            if (!row || !row.querySelectorAll) return [];
            return Array.from(row.querySelectorAll('.message-actions button:not([disabled])'));
        }

        function setRowActionTabStops(activeRow) {
            const rows = getMessageRows();
            rows.forEach(function (row) {
                const actions = getMessageActionButtons(row);
                actions.forEach(function (btn) {
                    btn.setAttribute('tabindex', row === activeRow ? '0' : '-1');
                });
            });
        }

        function setActiveMessageRow(row, shouldFocus) {
            const rows = getMessageRows();
            rows.forEach(function (r) { r.setAttribute('tabindex', '-1'); });
            if (row) row.setAttribute('tabindex', '0');
            setRowActionTabStops(row || null);
            if (row && shouldFocus) {
                try { row.focus(); } catch (e) {}
            }
        }

        function ensureRecentMessageAnchor(shouldFocus) {
            const rows = getMessageRows();
            if (!rows.length) return;
            const recent = rows[rows.length - 1];
            setActiveMessageRow(recent, !!shouldFocus);
        }

        function bindMessageKeyboardNavigation() {
            const host = hasJQ ? (chatMessages && chatMessages[0]) : chatMessages;
            if (!host || messageKeyboardBound) return;
            messageKeyboardBound = true;

            host.addEventListener('focusin', function (e) {
                const row = e.target && e.target.closest ? e.target.closest('.message') : null;
                if (row) setActiveMessageRow(row, false);
            });

            host.addEventListener('keydown', function (e) {
                const target = e.target;
                const row = target && target.closest ? target.closest('.message') : null;
                if (!row) return;
                const rows = getMessageRows();
                const idx = rows.indexOf(row);
                if (idx < 0) return;

                if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    const nextIdx = e.key === 'ArrowUp' ? Math.max(0, idx - 1) : Math.min(rows.length - 1, idx + 1);
                    setActiveMessageRow(rows[nextIdx], true);
                    return;
                }
                if (e.key === 'Home') {
                    e.preventDefault();
                    setActiveMessageRow(rows[0], true);
                    return;
                }
                if (e.key === 'End') {
                    e.preventDefault();
                    setActiveMessageRow(rows[rows.length - 1], true);
                    return;
                }
                if ((e.key === 'Enter' || e.key === ' ') && target.classList && target.classList.contains('message')) {
                    const firstAction = getMessageActionButtons(row)[0];
                    if (firstAction) {
                        e.preventDefault();
                        firstAction.focus();
                    }
                    return;
                }
                if (e.key === 'Tab' && !e.shiftKey && target.classList && target.classList.contains('message')) {
                    const firstAction = getMessageActionButtons(row)[0];
                    if (firstAction) {
                        e.preventDefault();
                        firstAction.focus();
                    }
                    return;
                }
                if (e.key === 'Tab' && e.shiftKey && target.closest && target.closest('.message-actions')) {
                    const actions = getMessageActionButtons(row);
                    if (actions.length && target === actions[0]) {
                        e.preventDefault();
                        setActiveMessageRow(row, true);
                    }
                }
            });
        }
        function updateComposeCollapse() {
            if (!composeBody) return;
            if (composeCollapsed) {
                composeBody.classList.remove('open');
                hideMentionDropdown();
            } else {
                composeBody.classList.add('open');
            }
            if (composeForm) {
                if (composeCollapsed) composeForm.classList.add('collapsed');
                else composeForm.classList.remove('collapsed');
            }
            if (composeToggle) {
                composeToggle.classList.toggle('expanded', !composeCollapsed);
                composeToggle.innerHTML = composeCollapsed ? '<i class="fas fa-comment-dots"></i> Compose' : '<i class="fas fa-chevron-down"></i> Hide Compose';
            }
            applyEmbedTabOrder();
        }
        function toggleCompose(nextState) {
            if (typeof nextState === 'boolean') {
                // nextState=true means expanded/open, false means collapsed/closed
                composeCollapsed = !nextState;
            } else {
                composeCollapsed = !composeCollapsed;
            }
            updateComposeCollapse();
        }
        updateComposeCollapse();
        if (composeToggle) {
            composeToggle.type = 'button';
            composeToggle.addEventListener('click', function(){
                toggleCompose();
                setTimeout(function () { try { composeToggle.focus(); } catch (e) {} }, 0);
            });
            composeToggle.addEventListener('keydown', function(e) {
                if (!e) return;
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleCompose();
                    return;
                }
                if (composeCollapsed) return;
                if (e.key === 'Tab' && !e.shiftKey) {
                    e.preventDefault();
                    focusFirstComposeControl();
                }
            });
        }
        let suppressImageUpload = false;
        if (hasJQ && $.fn.summernote) {
            try {
                $msg.summernote({
                    placeholder: 'Type your message here... Use @username to mention someone.',
                    tabsize: 2,
                    height: isEmbed ? 80 : 200,
                    toolbar: [
                        ['style', ['style']],
                        ['font', ['bold', 'italic', 'underline', 'clear']],
                        ['fontname', ['fontname']],
                        ['color', ['color']],
                        ['para', ['ul', 'ol', 'paragraph']],
                        ['table', ['table']],
                        ['insert', ['link','picture','video']],
                        ['view', ['fullscreen','codeview','help']]
                    ],
                    callbacks: {
                        onInit: function() {
                            setTimeout(function() { enableToolbarKeyboardA11y($msg); }, 0);
                            setTimeout(function() { enableToolbarKeyboardA11y($msg); }, 200);
                        },
                        onKeydown: function(e) {
                            if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) {
                                e.preventDefault();
                                focusEditorToolbar($msg);
                            }
                        },
                        onImageUpload: function(files) {
                            if (suppressImageUpload) return;
                            var list = files || [];
                            for (var i = 0; i < list.length; i++) {
                                uploadAndInsertImage(list[i], $msg);
                            }
                        },
                        onPaste: function(e) {
                            const clipboard = e.originalEvent && e.originalEvent.clipboardData;
                            if (clipboard && clipboard.items) {
                                for (let i = 0; i < clipboard.items.length; i++) {
                                    const item = clipboard.items[i];
                                    if (item.type && item.type.indexOf('image') === 0) {
                                        e.preventDefault();
                                        suppressImageUpload = true;
                                        uploadAndInsertImage(item.getAsFile(), $msg);
                                        setTimeout(() => { suppressImageUpload = false; }, 300);
                                        break;
                                    }
                                }
                            }
                        }
                    }
                });
                summernoteReady = true;
                if (isEmbed && composeCollapsed) { $msg.summernote('reset'); }
            } catch (err) {
                console.warn('Summernote init failed, using plain textarea', err);
            }
        }

        function getMessageHtml() {
            if (summernoteReady) return $msg.summernote('code');
            if (hasJQ) return $msg.val();
            const el = document.getElementById('message');
            return el ? el.value : '';
        }

        function setMessageHtml(val) {
            if (summernoteReady) return $msg.summernote('code', val);
            if (hasJQ) return $msg.val(val);
            const el = document.getElementById('message');
            if (el) el.value = val;
        }

        function updateCharCount() {
            const raw = getMessageHtml();
            const text = (hasJQ ? $('<div>').html(raw).text() : (new DOMParser().parseFromString(raw, 'text/html').documentElement.textContent || ''));
            const length = text.length;
            if (hasJQ) {
                $('#charCount').text(length + '/1000');
                $('#message').toggleClass('is-invalid', length > 1000);
            } else {
                const cc = document.getElementById('charCount');
                if (cc) cc.textContent = length + '/1000';
                const msgEl = document.getElementById('message');
                if (msgEl) msgEl.classList.toggle('is-invalid', length > 1000);
            }
        }

        if (summernoteReady) {
            $msg.on('summernote.change', updateCharCount);
        } else if (hasJQ) {
            $msg.on('input', updateCharCount);
        } else {
            const el = document.getElementById('message');
            if (el) el.addEventListener('input', updateCharCount);
        }
        updateCharCount();

        const $editMsg = hasJQ ? $('#chatEditMessageInput') : null;
        let editSummernoteReady = false;
        function getEditMessageHtml() {
            if (editSummernoteReady && $editMsg && $editMsg.length) return $editMsg.summernote('code');
            const el = document.getElementById('chatEditMessageInput');
            return el ? el.value : '';
        }
        function setEditMessageHtml(val) {
            if (editSummernoteReady && $editMsg && $editMsg.length) return $editMsg.summernote('code', val || '');
            const el = document.getElementById('chatEditMessageInput');
            if (el) el.value = val || '';
        }
        function updateEditCharCount() {
            const raw = getEditMessageHtml();
            const text = (hasJQ ? $('<div>').html(raw).text() : (new DOMParser().parseFromString(raw, 'text/html').documentElement.textContent || ''));
            const countEl = document.getElementById('chatEditCharCount');
            if (countEl) countEl.textContent = text.length + '/1000';
        }

        if (hasJQ && $.fn.summernote && $editMsg && $editMsg.length) {
            try {
                $editMsg.summernote({
                    placeholder: 'Edit message...',
                    tabsize: 2,
                    height: 160,
                    toolbar: [
                        ['style', ['style']],
                        ['font', ['bold', 'italic', 'underline', 'clear']],
                        ['fontname', ['fontname']],
                        ['color', ['color']],
                        ['para', ['ul', 'ol', 'paragraph']],
                        ['table', ['table']],
                        ['insert', ['link', 'picture', 'video']],
                        ['view', ['fullscreen', 'codeview', 'help']]
                    ],
                    callbacks: {
                        onInit: function() {
                            editSummernoteReady = true;
                            setTimeout(function() { enableToolbarKeyboardA11y($editMsg); }, 0);
                            setTimeout(function() { enableToolbarKeyboardA11y($editMsg); }, 200);
                            updateEditCharCount();
                        },
                        onKeydown: function(e) {
                            if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) {
                                e.preventDefault();
                                focusEditorToolbar($editMsg);
                            }
                        },
                        onImageUpload: function(files) {
                            var list = files || [];
                            for (var i = 0; i < list.length; i++) {
                                uploadAndInsertImage(list[i], $editMsg);
                            }
                        },
                        onPaste: function(e) {
                            const clipboard = e.originalEvent && e.originalEvent.clipboardData;
                            if (clipboard && clipboard.items) {
                                for (let i = 0; i < clipboard.items.length; i++) {
                                    const item = clipboard.items[i];
                                    if (item.type && item.type.indexOf('image') === 0) {
                                        e.preventDefault();
                                        uploadAndInsertImage(item.getAsFile(), $editMsg);
                                        break;
                                    }
                                }
                            }
                        },
                        onChange: function() {
                            updateEditCharCount();
                        }
                    }
                });
                editSummernoteReady = true;
            } catch (e) {
                editSummernoteReady = false;
            }
        }

        if (hasJQ && $('#chatForm').length) {
            $('#chatForm').prepend('<input type="hidden" id="chatReplyTo" name="reply_to" value="">\n<div id="chatReplyPreview" style="display:none;" class="mb-2"><div class="small text-muted">Replying to <strong class="reply-user"></strong> <button type="button" class="btn btn-sm btn-link p-0" id="chatCancelReply">Cancel</button></div><div class="reply-preview p-2 rounded bg-light small"></div></div>');
        }

        if (hasJQ) {
            $(document).on('click', '.mention-user', function() {
                const username = $(this).data('username');
                const textarea = $('#message');
                const cursorPos = textarea[0].selectionStart;
                const current = textarea.val();
                textarea.val(current.substring(0, cursorPos) + username + ' ' + current.substring(cursorPos)).focus();
            });

            $(document).on('click', '.chat-reply', function(){
                const mid = $(this).data('mid');
                const username = $(this).data('username');
                const message = $(this).data('message');
                if(!mid) return;
                const parsedMid = parseInt(mid, 10);
                activeReplyToId = Number.isFinite(parsedMid) && parsedMid > 0 ? parsedMid : null;
                if (isEmbed && composeCollapsed) {
                    composeCollapsed = false;
                    updateComposeCollapse();
                }
                $('#chatReplyTo').val(activeReplyToId ? String(activeReplyToId) : '');
                $('#chatReplyPreview').attr('data-reply-id', activeReplyToId ? String(activeReplyToId) : '');
                $('#chatReplyPreview .reply-user').text(username);
                $('#chatReplyPreview .reply-preview').html(message);
                $('#chatReplyPreview').show();
                $('#message').summernote && $('#message').summernote('focus');
            });

            $(document).on('click', '#chatCancelReply', function(){
                activeReplyToId = null;
                $('#chatReplyTo').val('');
                $('#chatReplyPreview').attr('data-reply-id', '');
                $('#chatReplyPreview').hide();
                $('#chatReplyPreview .reply-preview').html('');
            });
            function clearReplyState() {
                activeReplyToId = null;
                $('#chatReplyTo').val('');
                $('#chatReplyPreview').attr('data-reply-id', '');
                $('#chatReplyPreview').hide();
                $('#chatReplyPreview .reply-user').text('');
                $('#chatReplyPreview .reply-preview').html('');
            }

            function updateMessageInDom(msg) {
                if (!msg || !msg.id) return;
                const $row = $('.message[data-id="' + msg.id + '"]');
                if (!$row.length) return;
                const $content = $row.find('.message-content').first();
                if (!$content.length) return;
                const replyHtml = $content.find('.reply-preview').first().prop('outerHTML') || '';
                const metaHtml = '<div class="message-meta">' + (msg.created_at || '') + '</div>';
                $content.html(replyHtml + (msg.message || '') + metaHtml);
                $row.find('.chat-edit').attr('data-message', msg.message || '');
                const deletedPlain = $('<div>').html(msg.message || '').text().replace(/\s+/g, ' ').trim().toLowerCase() === 'message deleted';
                if (!msg.can_edit || deletedPlain) $row.find('.chat-edit').remove();
                if (!msg.can_delete || deletedPlain) $row.find('.chat-delete').remove();
                const $actions = $row.find('.message-actions').first();
                const $historyBtn = $row.find('.chat-history');
                if (canViewHistoryAdmin) {
                    if (!$historyBtn.length && $actions.length) {
                        $actions.append('<button type="button" class="chat-action-btn chat-history" title="History" aria-label="View message history" data-mid="' + msg.id + '"><i class="fas fa-history"></i></button>');
                    } else if ($historyBtn.length) {
                        $historyBtn.attr('data-mid', msg.id);
                    }
                } else if ($historyBtn.length) {
                    $historyBtn.remove();
                }
                enhanceImages(hasJQ ? chatMessages[0] : chatMessages);
                applyEmbedTabOrder();
            }
            window.chatUpdateMessageInDom = updateMessageInDom;

            function showMessageHistory(messageId) {
                if (!canViewHistoryAdmin) return;
                const body = document.getElementById('chatHistoryBody');
                if (body) body.innerHTML = '<p class="text-muted mb-0">Loading...</p>';
                const historyUrl = new URL(window.location.href);
                historyUrl.searchParams.set('action', 'get_message_history');
                historyUrl.searchParams.set('message_id', String(messageId));
                fetch(historyUrl.toString(), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                }).then(function(res) {
                    return res.json();
                }).then(function(res) {
                    if (!body) return;
                    if (!res || !res.success) {
                        body.innerHTML = '<p class="text-danger mb-0">Failed to load history.</p>';
                        return;
                    }
                    const rows = res.history || [];
                    if (!rows.length) {
                        body.innerHTML = '<p class="text-muted mb-0">No history available.</p>';
                        return;
                    }
                    let html = '<div class="list-group">';
                    rows.forEach(function(h) {
                        html += '<div class="list-group-item">';
                        html += '<div class="d-flex justify-content-between mb-2">';
                        html += '<strong>' + escapeHtml((h.action_type || '').toUpperCase()) + '</strong>';
                        html += '<small class="text-muted">' + escapeHtml(h.acted_at || '') + ' by ' + escapeHtml(h.acted_by_name || 'Unknown') + '</small>';
                        html += '</div>';
                        html += '<div class="small text-muted mb-1">Old</div><div class="border rounded p-2 mb-2">' + (h.old_message || '') + '</div>';
                        html += '<div class="small text-muted mb-1">New</div><div class="border rounded p-2">' + (h.new_message || '') + '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                    body.innerHTML = html;
                }).catch(function() {
                    if (body) body.innerHTML = '<p class="text-danger mb-0">Failed to load history.</p>';
                });
                const modalEl = document.getElementById('chatHistoryModal');
                if (modalEl && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                }
            }

            function submitChatEdit(mid, edited) {
                const payload = new URLSearchParams();
                payload.append('message_id', String(mid));
                payload.append('message', edited);
                return fetch('<?php echo $baseDir; ?>/api/chat_actions.php?action=edit_message', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json'
                    },
                    body: payload.toString()
                }).then(function(res) { return res.json(); }).then(function(res) {
                    if (res && res.success && res.message) {
                        updateMessageInDom(res.message);
                        showToast('Message updated', 'success');
                        return true;
                    }
                    showToast((res && res.error) ? res.error : 'Failed to edit message', 'danger');
                    return false;
                }).catch(function() {
                    showToast('Failed to edit message', 'danger');
                    return false;
                });
            }

            function submitChatDelete(mid) {
                const payload = new URLSearchParams();
                payload.append('message_id', String(mid));
                return fetch('<?php echo $baseDir; ?>/api/chat_actions.php?action=delete_message', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json'
                    },
                    body: payload.toString()
                }).then(function(res) { return res.json(); }).then(function(res) {
                    if (res && res.success) {
                        if (res.message) {
                            updateMessageInDom(res.message);
                        } else {
                            fetchMessages();
                        }
                        showToast('Message deleted', 'success');
                        return true;
                    }
                    showToast((res && res.error) ? res.error : 'Failed to delete message', 'danger');
                    return false;
                }).catch(function() {
                    showToast('Failed to delete message', 'danger');
                    return false;
                });
            }

            $(document).on('click', '.chat-edit', function() {
                const mid = Number($(this).data('mid'));
                if (!mid) return;
                const current = $(this).data('message') || '';
                const editInput = document.getElementById('chatEditMessageInput');
                const editId = document.getElementById('chatEditMessageId');
                const modalEl = document.getElementById('chatEditModal');
                if (!editInput || !editId || !modalEl || !window.bootstrap) return;
                editId.value = String(mid);
                setEditMessageHtml(current);
                updateEditCharCount();
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
                setTimeout(function() {
                    try {
                        if (editSummernoteReady && $editMsg && $editMsg.length) {
                            $editMsg.summernote('focus');
                        } else {
                            editInput.focus();
                        }
                    } catch (e) {}
                }, 120);
            });

            $(document).on('click', '.chat-delete', function() {
                const mid = Number($(this).data('mid'));
                if (!mid) return;
                const deleteId = document.getElementById('chatDeleteMessageId');
                const modalEl = document.getElementById('chatDeleteModal');
                if (!deleteId || !modalEl || !window.bootstrap) return;
                deleteId.value = String(mid);
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });

            $(document).on('click', '.chat-history', function() {
                const mid = Number($(this).data('mid'));
                if (!mid) return;
                showMessageHistory(mid);
            });

            $('#chatEditModal').on('hidden.bs.modal', function() {
                const editId = document.getElementById('chatEditMessageId');
                if (editId) editId.value = '';
                setEditMessageHtml('');
                updateEditCharCount();
            });
        }

        function toastSafe(message, type) {
            if (typeof showToast === 'function') {
                showToast(message, type || 'info');
                return;
            }
            showChatActionStatusModal(message || 'Action failed', type || 'info');
        }

        function showChatActionStatusModal(message, type) {
            const textEl = document.getElementById('chatActionStatusText');
            const titleEl = document.getElementById('chatActionStatusTitle');
            const modalEl = document.getElementById('chatActionStatusModal');
            const isSuccess = String(type || '').toLowerCase() === 'success';
            if (titleEl) titleEl.textContent = isSuccess ? 'Success' : 'Action Failed';
            if (textEl) textEl.textContent = String(message || (isSuccess ? 'Action completed.' : 'Action failed'));
            if (modalEl && window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        }

        function saveEditedMessage() {
            const editId = document.getElementById('chatEditMessageId');
            const modalEl = document.getElementById('chatEditModal');
            const saveBtn = document.getElementById('chatEditSaveBtn');
            const mid = Number(editId ? editId.value : 0);
            const edited = getEditMessageHtml();
            const editedText = (hasJQ ? $('<div>').html(edited).text().trim() : (new DOMParser().parseFromString(edited, 'text/html').documentElement.textContent || '').trim());
            const hasImg = /<img\b[^>]*src=/i.test(edited || '');
            if (!mid) return;
            if (!editedText && !hasImg) { toastSafe('Message cannot be empty', 'warning'); return; }
            if (saveBtn) saveBtn.disabled = true;

            const payload = new URLSearchParams();
            payload.append('edit_message', '1');
            payload.append('message_id', String(mid));
            payload.append('message', edited);
            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: payload.toString()
            }).then(function(res) { return res.json(); }).then(function(res) {
                if (res && res.success) {
                    if (res.message && typeof window.chatUpdateMessageInDom === 'function') {
                        window.chatUpdateMessageInDom(res.message);
                    } else {
                        fetchMessages();
                    }
                    toastSafe('Message updated', 'success');
                    if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                } else {
                    toastSafe((res && res.error) ? res.error : 'Failed to edit message', 'danger');
                }
            }).catch(function() {
                toastSafe('Failed to edit message', 'danger');
            }).finally(function() {
                if (saveBtn) saveBtn.disabled = false;
            });
        }

        function confirmDeleteMessage() {
            const deleteId = document.getElementById('chatDeleteMessageId');
            const modalEl = document.getElementById('chatDeleteModal');
            const deleteBtn = document.getElementById('chatDeleteConfirmBtn');
            const mid = Number(deleteId ? deleteId.value : 0);
            if (!mid) return;
            if (deleteBtn) deleteBtn.disabled = true;

            const payload = new URLSearchParams();
            payload.append('delete_message', '1');
            payload.append('message_id', String(mid));
            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: payload.toString()
            }).then(function(res) { return res.json(); }).then(function(res) {
                if (res && res.success) {
                    if (res.message && typeof window.chatUpdateMessageInDom === 'function') {
                        window.chatUpdateMessageInDom(res.message);
                    } else {
                        fetchMessages();
                    }
                    toastSafe('Message deleted', 'success');
                    if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                } else {
                    toastSafe((res && res.error) ? res.error : 'Failed to delete message', 'danger');
                }
            }).catch(function() {
                toastSafe('Failed to delete message', 'danger');
            }).finally(function() {
                if (deleteBtn) deleteBtn.disabled = false;
            });
        }

        const chatEditSaveBtnEl = document.getElementById('chatEditSaveBtn');
        if (chatEditSaveBtnEl && !chatEditSaveBtnEl.dataset.boundClick) {
            chatEditSaveBtnEl.addEventListener('click', function(e) {
                e.preventDefault();
                saveEditedMessage();
            });
            chatEditSaveBtnEl.dataset.boundClick = '1';
        }

        const chatDeleteConfirmBtnEl = document.getElementById('chatDeleteConfirmBtn');
        if (chatDeleteConfirmBtnEl && !chatDeleteConfirmBtnEl.dataset.boundClick) {
            chatDeleteConfirmBtnEl.addEventListener('click', function(e) {
                e.preventDefault();
                confirmDeleteMessage();
            });
            chatDeleteConfirmBtnEl.dataset.boundClick = '1';
        }

        function sendChatMessage() {
            function hardFallbackSubmit(messageHtml, replyToVal) {
                try {
                    const f = document.createElement('form');
                    f.method = 'POST';
                    f.action = window.location.pathname + window.location.search;
                    f.style.display = 'none';
                    const fields = {
                        send_message: '1',
                        project_id: String(projectId || ''),
                        page_id: String(pageId || ''),
                        message: String(messageHtml || ''),
                        reply_to: String(replyToVal || ''),
                        reply_token: replyToVal ? ('r:' + String(replyToVal)) : ''
                    };
                    Object.keys(fields).forEach(function(k) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = k;
                        input.value = fields[k];
                        f.appendChild(input);
                    });
                    document.body.appendChild(f);
                    f.submit();
                } catch (e) {
                    if (typeof showToast === 'function') showToast('Failed to send message', 'danger');
                }
            }

            try {
                const msg = getMessageHtml();
                const textOnly = hasJQ ? $('<div>').html(msg).text().trim() : (new DOMParser().parseFromString(msg, 'text/html').documentElement.textContent || '').trim();
                const hasImg = /<img\b[^>]*src=/i.test(msg);
                if(!textOnly && !hasImg) {
                    if (typeof showToast === 'function') showToast('Type a message to send', 'warning');
                    return;
                }

                hideMentionDropdown();

                if (hasJQ) $('#sendBtn').prop('disabled', true); else { const b = document.getElementById('sendBtn'); if (b) b.disabled = true; }

                const hiddenReplyTo = hasJQ ? ($('#chatReplyTo').val() || '') : (document.getElementById('chatReplyTo') ? document.getElementById('chatReplyTo').value : '');
                const previewReplyTo = hasJQ ? ($('#chatReplyPreview').attr('data-reply-id') || '') : '';
                const parsedHiddenReplyTo = parseInt(hiddenReplyTo, 10);
                const parsedPreviewReplyTo = parseInt(previewReplyTo, 10);
                const replyTo = (Number.isFinite(activeReplyToId) && activeReplyToId > 0)
                    ? activeReplyToId
                    : (Number.isFinite(parsedHiddenReplyTo) && parsedHiddenReplyTo > 0 ? parsedHiddenReplyTo : (Number.isFinite(parsedPreviewReplyTo) && parsedPreviewReplyTo > 0 ? parsedPreviewReplyTo : null));
                const replyPreviewUser = hasJQ ? ($('#chatReplyPreview .reply-user').text() || '') : '';
                const replyPreviewMessage = hasJQ ? ($('#chatReplyPreview .reply-preview').html() || '') : '';
                const payload = new FormData();
                payload.append('project_id', String(projectId || ''));
                payload.append('page_id', String(pageId || ''));
                payload.append('message', msg);
                payload.append('reply_to', replyTo ? String(replyTo) : '');
                payload.append('reply_token', replyTo ? ('r:' + String(replyTo)) : '');

                function fallbackPostMessage() {
                    const params = new URLSearchParams();
                    params.append('send_message', '1');
                    params.append('project_id', String(projectId || ''));
                    params.append('page_id', String(pageId || ''));
                    params.append('message', msg);
                    if (replyTo) params.append('reply_to', String(replyTo));
                    if (replyTo) params.append('reply_token', 'r:' + String(replyTo));

                    return fetch(window.location.pathname + window.location.search, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        body: params.toString()
                    }).then(function(res) {
                        return res.text().then(function(text) {
                            try { return JSON.parse(text); } catch (e) { return { error: 'Fallback invalid response' }; }
                        });
                    }).then(function(res) {
                        if (res && res.success) {
                            if (replyTo && res.message && !res.message.reply_preview && replyPreviewMessage) {
                                res.message.reply_preview = {
                                    id: replyTo,
                                    user_id: null,
                                    username: '',
                                    full_name: replyPreviewUser || 'User',
                                    message: replyPreviewMessage,
                                    created_at: null
                                };
                            }
                            setMessageHtml('');
                            clearReplyState();
                            updateCharCount();
                            if (res.message) appendMessages([res.message]);
                            else fetchMessages();
                            if (isEmbed && composeCollapsed) {
                                updateComposeCollapse();
                            }
                        } else {
                            hardFallbackSubmit(msg, replyTo);
                        }
                    }).catch(function() {
                        hardFallbackSubmit(msg, replyTo);
                    });
                }

                fetch('<?php echo $baseDir; ?>/api/chat_actions.php?action=send_message', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: payload
                }).then(function(res) {
                    return res.text().then(function(text) {
                        try { return JSON.parse(text); } catch (e) { return { error: 'Invalid response', _raw: text }; }
                    });
                }).then(res => {
                    if (res && res.success) {
                        if (replyTo && res.message && !res.message.reply_preview && replyPreviewMessage) {
                            res.message.reply_preview = {
                                id: replyTo,
                                user_id: null,
                                username: '',
                                full_name: replyPreviewUser || 'User',
                                message: replyPreviewMessage,
                                created_at: null
                            };
                        }
                        setMessageHtml('');
                        clearReplyState();
                        updateCharCount();
                        if (res.message) appendMessages([res.message]);
                        else fetchMessages();
                        if (isEmbed && composeCollapsed) {
                            updateComposeCollapse();
                        }
                    } else if (res && res.error) {
                        // If host/WAF blocks API, fallback to direct form post.
                        if (String(res.error).toLowerCase().indexOf('invalid response') !== -1) {
                            return fallbackPostMessage();
                        }
                        return fallbackPostMessage();
                    } else {
                        return fallbackPostMessage();
                    }
                }).catch(err => {
                    console.error('Chat send failed', err);
                    return fallbackPostMessage();
                }).finally(() => {
                    if (hasJQ) $('#sendBtn').prop('disabled', false); else { const b = document.getElementById('sendBtn'); if (b) b.disabled = false; }
                });
            } catch (err) {
                console.error('sendChatMessage fatal error', err);
                const safeMsg = (typeof getMessageHtml === 'function') ? getMessageHtml() : '';
                const safeHiddenReply = (document.getElementById('chatReplyTo') ? document.getElementById('chatReplyTo').value : '');
                const safeReply = (Number.isFinite(activeReplyToId) && activeReplyToId > 0)
                    ? String(activeReplyToId)
                    : String(safeHiddenReply || '');
                hardFallbackSubmit(safeMsg, safeReply);
            }
        }

        function enableToolbarKeyboardA11y($editor) {
            if (!hasJQ || !$editor || !$editor.length) return;
            const $toolbar = $editor.next('.note-editor').find('.note-toolbar').first();
            if (!$toolbar.length || $toolbar.data('kbdA11yBound')) return;
            let syncingTabStops = false;

            function getItems() {
                return $toolbar.find('.note-btn-group button').filter(function() {
                    const $b = $(this);
                    return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length;
                });
            }

            function setActiveIndex(idx) {
                if (syncingTabStops) return;
                const $items = getItems();
                if (!$items.length) return;
                const next = Math.max(0, Math.min(idx, $items.length - 1));
                syncingTabStops = true;
                try {
                    $items.each(function(i) {
                        const val = i === next ? '0' : '-1';
                        if (this.getAttribute('tabindex') !== val) {
                            this.setAttribute('tabindex', val);
                        }
                    });
                    $toolbar.data('kbdIndex', next);
                } finally {
                    syncingTabStops = false;
                }
            }

            function ensureToolbarTabStops() {
                const $items = getItems();
                if (!$items.length) return;
                let idx = parseInt($toolbar.data('kbdIndex'), 10);
                if (isNaN(idx) || idx < 0 || idx >= $items.length) {
                    idx = $items.index(document.activeElement);
                }
                if (isNaN(idx) || idx < 0 || idx >= $items.length) idx = 0;
                setActiveIndex(idx);
            }

            function handleNav(e) {
                const key = e.key || (e.originalEvent && e.originalEvent.key);
                const code = e.keyCode || (e.originalEvent && e.originalEvent.keyCode);
                const isTab = key === 'Tab' || code === 9;
                if (isTab) {
                    const isComposeEditor = isEmbed && ($editor.attr('id') === 'message');
                    if (isComposeEditor && !e.shiftKey) {
                        e.preventDefault();
                        focusComposeEditable();
                        return;
                    }
                    if (isComposeEditor && e.shiftKey && composeToggle) {
                        e.preventDefault();
                        try { composeToggle.focus(); } catch (err) {}
                        return;
                    }
                }
                const isRight = key === 'ArrowRight' || code === 39;
                const isLeft = key === 'ArrowLeft' || code === 37;
                const isHome = key === 'Home' || code === 36;
                const isEnd = key === 'End' || code === 35;
                if (!isRight && !isLeft && !isHome && !isEnd) return;
                const $items = getItems();
                if (!$items.length) return;
                const activeEl = document.activeElement;
                let idx = $items.index(activeEl);
                if (idx < 0 && activeEl && activeEl.closest) {
                    const parentBtn = activeEl.closest('button');
                    if (parentBtn) idx = $items.index(parentBtn);
                }
                if (idx < 0) {
                    const saved = parseInt($toolbar.data('kbdIndex'), 10);
                    if (!isNaN(saved) && saved >= 0 && saved < $items.length) idx = saved;
                }
                if (isNaN(idx) || idx < 0) idx = 0;
                e.preventDefault();
                if (e.stopPropagation) e.stopPropagation();
                if (isHome) idx = 0;
                else if (isEnd) idx = $items.length - 1;
                else if (isRight) idx = (idx + 1) % $items.length;
                else if (isLeft) idx = (idx - 1 + $items.length) % $items.length;
                setActiveIndex(idx);
                $items.eq(idx).focus();
                if (document.activeElement !== $items.eq(idx).get(0)) {
                    setTimeout(function() { $items.eq(idx).focus(); }, 0);
                }
            }

            $toolbar.attr('role', 'toolbar');
            if (!$toolbar.attr('aria-label')) $toolbar.attr('aria-label', 'Editor toolbar');
            ensureToolbarTabStops();
            $toolbar.on('focusin', 'button, [role="button"], a.note-btn', function() {
                const $items = getItems();
                const idx = $items.index(this);
                if (idx >= 0) setActiveIndex(idx);
            });
            $toolbar.on('click', 'button, [role="button"], a.note-btn', function() {
                const $items = getItems();
                const idx = $items.index(this);
                if (idx >= 0) setActiveIndex(idx);
            });
            $toolbar.on('keydown', handleNav);
            if (!$toolbar.data('kbdA11yNativeKeyBound')) {
                $toolbar.get(0).addEventListener('keydown', handleNav, true);
                $toolbar.data('kbdA11yNativeKeyBound', true);
            }
            const observer = new MutationObserver(function() { ensureToolbarTabStops(); });
            observer.observe($toolbar[0], { subtree: true, attributes: true, attributeFilter: ['tabindex', 'class', 'disabled'] });
            $toolbar.data('kbdA11yObserver', observer);
            ensureToolbarTabStops();
            $toolbar.data('kbdA11yBound', true);
            const $editable = $editor.next('.note-editor').find('.note-editable');
            $editable.on('keydown', function(e) {
                if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) {
                    e.preventDefault();
                    focusEditorToolbar($editor);
                }
            });
        }

        function focusEditorToolbar($editor) {
            if (!hasJQ || !$editor || !$editor.length) return;
            const $toolbar = $editor.next('.note-editor').find('.note-toolbar').first();
            if (!$toolbar.length) return;
            const $items = $toolbar.find('.note-btn-group button').filter(function() {
                const $b = $(this);
                return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length;
            });
            if (!$items.length) return;
            $items.attr('tabindex', '-1');
            $items.eq(0).attr('tabindex', '0').focus();
            $toolbar.data('kbdIndex', 0);
        }

        if (hasJQ) {
            $('#chatForm').on('submit', function(e) { e.preventDefault(); sendChatMessage(); });
            $('#message').on('keydown', function(e) {
                mentionSearchDisabled = (e.key === 'Escape') ? mentionSearchDisabled : false;
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    sendChatMessage();
                }
                if (isMentionVisible()) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); moveMentionHighlight(1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); moveMentionHighlight(-1); }
                    else if (e.key === 'Enter') {
                        const username = getActiveMentionUsername();
                        if (username) { e.preventDefault(); e.stopImmediatePropagation(); insertMention(username); }
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        hideMentionDropdown();
                    }
                }
            });
            $(chatMessages).on('click', '.chat-image-full-btn', function(){
                const src = $(this).data('src');
                showImageModal(src);
            });
            $(chatMessages).on('click', '.message-content img', function(){
                const src = $(this).attr('src');
                showImageModal(src);
            });
            // Mention detection within Summernote editable area or textarea
            if (summernoteReady) {
                $msg.on('summernote.keyup', function() {
                    const plain = $('<div>').html($msg.summernote('code')).text();
                    const editable = $msg.next('.note-editor').find('.note-editable')[0];
                    handleMentionSearch(plain, editable || $msg.next('.note-editor')[0]);
                });
                $msg.on('summernote.keydown', function(e) {
                    mentionSearchDisabled = (e.key === 'Escape') ? mentionSearchDisabled : false;
                    if (isMentionVisible()) {
                        if (e.key === 'ArrowDown') { e.preventDefault(); moveMentionHighlight(1); }
                        else if (e.key === 'ArrowUp') { e.preventDefault(); moveMentionHighlight(-1); }
                        else if (e.key === 'Enter') {
                            const username = getActiveMentionUsername();
                            if (username) { e.preventDefault(); e.stopImmediatePropagation(); insertMention(username); }
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            hideMentionDropdown();
                        }
                    }
                });
            } else {
                $('#message').on('keyup', function(){
                    if (mentionSearchDisabled && !/@\w*$/.test($(this).val())) mentionSearchDisabled = false;
                    handleMentionSearch($(this).val(), $(this));
                });
            }
        } else {
            const form = document.getElementById('chatForm');
            const msgEl = document.getElementById('message');
            if (form) form.addEventListener('submit', function(e){ e.preventDefault(); sendChatMessage(); });
            if (msgEl) msgEl.addEventListener('keydown', function(e){
                mentionSearchDisabled = (e.key === 'Escape') ? mentionSearchDisabled : false;
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    sendChatMessage();
                }
                if (isMentionVisible()) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        hideMentionDropdown();
                    } else if (e.key === 'Enter') {
                        const username = getActiveMentionUsername();
                        if (username) {
                            e.preventDefault();
                            e.stopPropagation();
                            insertMention(username);
                        }
                    }
                }
            });
            if (chatMessages) {
                chatMessages.addEventListener('click', function(e){
                    const btn = e.target.closest('.chat-image-full-btn');
                    if (btn) {
                        const src = btn.getAttribute('data-src');
                        showImageModal(src);
                        return;
                    }
                    const img = e.target.closest('.message-content img');
                    if (img) {
                        const src = img.getAttribute('src');
                        showImageModal(src);
                    }
                });
            }
            if (msgEl) {
                msgEl.addEventListener('keyup', function(){
                    if (mentionSearchDisabled && !/@\w*$/.test(msgEl.value)) mentionSearchDisabled = false;
                    handleMentionSearch(msgEl.value, msgEl);
                });
            }
        }

        function getCaretRectWithin(el) {
            if (!el) return null;
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) return null;
            const range = sel.getRangeAt(0).cloneRange();
            if (!el.contains(range.commonAncestorContainer)) return null;
            range.collapse(true);
            let rect = range.getBoundingClientRect();
            if ((!rect || (rect.width === 0 && rect.height === 0))) {
                const span = document.createElement('span');
                span.textContent = '\u200b';
                range.insertNode(span);
                rect = span.getBoundingClientRect();
                span.parentNode && span.parentNode.removeChild(span);
                range.collapse(true);
                sel.removeAllRanges();
                sel.addRange(range);
            }
            return rect;
        }

        function handleMentionSearch(text, anchorEl) {
            if (mentionSearchDisabled) return;
            const match = /@([\w]*)$/.exec((text || ''));
            if (!match) { hideMentionDropdown(); return; }
            const query = match[1] || '';
            const list = mentionUsers.filter(u => 
                u.username.toLowerCase().startsWith(query.toLowerCase()) ||
                u.full_name.toLowerCase().includes(query.toLowerCase())
            ).slice(0, 8);
            if (!list.length) { hideMentionDropdown(); return; }
            const html = list.map(u => `<button type="button" class="dropdown-item mention-pick" data-username="${u.username}">@${u.username} — ${escapeHtml(u.full_name)}</button>`).join('');
            lastMentionAnchor = anchorEl;
            mentionIndex = 0;
            const formEl = document.getElementById('chatForm');
            if (hasJQ && mentionDropdown) {
                mentionDropdown.html(html).css({ display: 'block', position: 'absolute' });
                const sel = window.getSelection();
                if (sel && sel.rangeCount) { lastMentionRange = sel.getRangeAt(0).cloneRange(); }
                const caretRect = getCaretRectWithin(anchorEl);
                const rect = caretRect || anchorEl.getBoundingClientRect();
                const contRect = formEl ? formEl.getBoundingClientRect() : { top: 0, left: 0 };
                const top = rect.bottom - contRect.top + 6;
                const left = rect.left - contRect.left;
                mentionDropdown.css({ top: top, left: left, minWidth: rect.width });
                const items = mentionDropdown.find('.mention-pick');
                if (items.length) { items.removeClass('active'); items.eq(mentionIndex).addClass('active'); }
            } else if (mentionDropdown) {
                mentionDropdown.innerHTML = html;
                mentionDropdown.style.display = 'block';
                mentionDropdown.style.position = 'absolute';
                const sel = window.getSelection();
                if (sel && sel.rangeCount) { lastMentionRange = sel.getRangeAt(0).cloneRange(); }
                const caretRect = getCaretRectWithin(anchorEl);
                const rect = caretRect || anchorEl.getBoundingClientRect();
                const contRect = formEl ? formEl.getBoundingClientRect() : { top: 0, left: 0 };
                const top = rect.bottom - contRect.top + 6;
                mentionDropdown.style.top = top + 'px';
                mentionDropdown.style.left = (rect.left - contRect.left) + 'px';
                mentionDropdown.style.minWidth = rect.width + 'px';
                const items = mentionDropdown.querySelectorAll('.mention-pick');
                if (items.length) { items.forEach(i => i.classList.remove('active')); items[mentionIndex].classList.add('active'); }
            }
        }

        function hideMentionDropdown() {
            mentionIndex = -1;
            lastMentionRange = null;
            if (hasJQ) mentionDropdown.hide();
            else if (mentionDropdown) mentionDropdown.style.display = 'none';
        }

            if (hasJQ) {
                $(document).on('click', '.mention-pick', function(){
                    insertMention($(this).data('username'));
                    hideMentionDropdown();
                });
            } else if (mentionDropdown) {
                mentionDropdown.addEventListener('click', function(e){
                    const btn = e.target.closest('.mention-pick');
                    if (btn) {
                        insertMention(btn.getAttribute('data-username'));
                        hideMentionDropdown();
                    }
                });
            }

            // Capture-phase handler to beat editor default Enter behaviour when mentions are open
            document.addEventListener('keydown', function(e){
                if (!isMentionVisible()) return;
                const isEsc = (e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27);
                if (isEsc) { 
                    e.preventDefault(); 
                    e.stopImmediatePropagation(); 
                    mentionSearchDisabled = true;
                    hideMentionDropdown(); 
                    return; 
                }
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Tab' || e.keyCode === 9) {
                    const username = getActiveMentionUsername();
                    if (username) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        insertMention(username);
                        hideMentionDropdown();
                    }
                }
            }, true);

        function highlightMention(index) {
            if (hasJQ) {
                const items = mentionDropdown.find('.mention-pick');
                items.removeClass('active');
                if (items.length && index >= 0 && index < items.length) {
                    $(items[index]).addClass('active')[0].scrollIntoView({ block: 'nearest' });
                }
            } else if (mentionDropdown) {
                const items = mentionDropdown.querySelectorAll('.mention-pick');
                items.forEach(i => i.classList.remove('active'));
                if (items.length && index >= 0 && index < items.length) {
                    items[index].classList.add('active');
                    items[index].scrollIntoView({ block: 'nearest' });
                }
            }
        }

        function getActiveMentionUsername() {
            if (hasJQ) {
                const active = mentionDropdown.find('.mention-pick.active');
                if (active.length) return active.data('username');
                const first = mentionDropdown.find('.mention-pick').first();
                return first.length ? first.data('username') : null;
            } else if (mentionDropdown) {
                const active = mentionDropdown.querySelector('.mention-pick.active');
                if (active) return active.getAttribute('data-username');
                const first = mentionDropdown.querySelector('.mention-pick');
                return first ? first.getAttribute('data-username') : null;
            }
            return null;
        }

        function moveMentionHighlight(delta) {
            if (mentionIndex < 0) return;
            const items = hasJQ ? mentionDropdown.find('.mention-pick') : (mentionDropdown ? mentionDropdown.querySelectorAll('.mention-pick') : []);
            const len = items.length || 0;
            if (!len) return;
            mentionIndex = (mentionIndex + delta + len) % len;
            highlightMention(mentionIndex);
        }

        function insertMention(username) {
            function stripCurrentMentionToken(editable) {
                if (!editable || !window.getSelection) return;
                const sel = window.getSelection();
                if (!sel.rangeCount) return;
                const range = sel.getRangeAt(0);
                if (!editable.contains(range.commonAncestorContainer)) return;

                // Restore saved range if available to ensure we operate at the trigger position
                if (lastMentionRange) {
                    sel.removeAllRanges();
                    sel.addRange(lastMentionRange);
                }

                let cursorNode = range.startContainer;
                let cursorOffset = range.startOffset;
                let startNode = null;
                let startOffset = 0;

                function prevPosition(node, offset) {
                    if (!node) return null;
                    if (node.nodeType === 3 && offset > 0) {
                        return { node, offset: offset - 1, char: node.textContent[offset - 1] };
                    }
                    let cur = node;
                    while (cur) {
                        if (cur.previousSibling) {
                            cur = cur.previousSibling;
                            while (cur.lastChild) cur = cur.lastChild;
                            if (cur.nodeType === 3) {
                                const len = cur.textContent.length;
                                return { node: cur, offset: len, char: len ? cur.textContent[len - 1] : '' };
                            }
                        } else {
                            cur = cur.parentNode;
                            if (!cur || cur === editable) break;
                        }
                    }
                    return null;
                }

                let pos = { node: cursorNode, offset: cursorOffset };
                while (true) {
                    const prev = prevPosition(pos.node, pos.offset);
                    if (!prev) break;
                    if (prev.char === '@') {
                        startNode = prev.node;
                        startOffset = prev.offset;
                        break;
                    }
                    if (/\s/.test(prev.char || '')) break;
                    pos = prev;
                }

                if (startNode) {
                    const del = document.createRange();
                    del.setStart(startNode, startOffset);
                    del.setEnd(cursorNode, cursorOffset);
                    del.deleteContents();
                }
            }

            if (summernoteReady) {
                const editable = $msg.next('.note-editor').find('.note-editable')[0];
                const sel = window.getSelection();
                if (lastMentionRange) {
                    sel.removeAllRanges();
                    sel.addRange(lastMentionRange);
                }
                stripCurrentMentionToken(editable);
                const selAfter = window.getSelection();
                if (selAfter && selAfter.rangeCount) {
                    const r = selAfter.getRangeAt(0);
                    r.collapse(false);
                    selAfter.removeAllRanges();
                    selAfter.addRange(r);
                }
                $msg.summernote('editor.focus');
                try {
                    document.execCommand('insertText', false, '@' + username + ' ');
                } catch (e) {
                    $msg.summernote('editor.insertText', '@' + username + ' ');
                }
                lastMentionRange = null;
            } else if (hasJQ) {
                const ta = $msg.get(0);
                const start = ta.selectionStart;
                const end = ta.selectionEnd;
                const text = $msg.val();
                const atPos = text.lastIndexOf('@', start);
                const before = atPos >= 0 ? text.substring(0, atPos) : text.substring(0, start);
                const after = atPos >= 0 ? text.substring(end) : text.substring(start);
                const newText = before + '@' + username + ' ' + after;
                $msg.val(newText).focus();
                lastMentionRange = null;
            } else {
                const ta = document.getElementById('message');
                if (!ta) return;
                const start = ta.selectionStart;
                const end = ta.selectionEnd;
                const text = ta.value;
                const atPos = text.lastIndexOf('@', start);
                const before = atPos >= 0 ? text.substring(0, atPos) : text.substring(0, start);
                const after = atPos >= 0 ? text.substring(end) : text.substring(start);
                ta.value = before + '@' + username + ' ' + after;
                ta.focus();
                lastMentionRange = null;
            }
            updateCharCount();
            hideMentionDropdown();
        }

        function appendMessages(messages) {
            if (!messages || !messages.length) return;
            if (hasJQ) $('.no-messages').remove();
            else {
                const nm = document.querySelector('.no-messages');
                if (nm && nm.parentNode) nm.parentNode.removeChild(nm);
            }

            messages.forEach(msg => {
                if (!msg || !msg.id) return;
                const msgId = Number(msg.id);
                if (msgId <= Number(lastMessageId)) return;
                if (document.querySelector('.message[data-id="' + msgId + '"]')) return;
                lastMessageId = msgId;

                let isMentioned = false;
                if (msg.mentions) {
                    try {
                        const mentions = typeof msg.mentions === 'string' ? JSON.parse(msg.mentions) : msg.mentions;
                        if (Array.isArray(mentions) && mentions.includes(currentUserId)) isMentioned = true;
                    } catch (e) {}
                }

                const roleColor = msg.role === 'admin' ? 'danger' : (msg.role === 'project_lead' ? 'warning' : 'info');
                const ownClass = (Number(msg.user_id) === currentUserId) ? 'own-message' : 'other-message';
                const canEdit = !!msg.can_edit;
                const canDelete = !!msg.can_delete;
                const deletedByContent = (hasJQ ? $('<div>').html(msg.message || '').text() : (new DOMParser().parseFromString(msg.message || '', 'text/html').documentElement.textContent || ''))
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase() === 'message deleted';
                let replyBlock = '';
                if (msg.reply_preview) {
                    const rp = msg.reply_preview;
                    const rpTime = rp.created_at ? ' <small class="text-muted ms-2">' + escapeHtml(rp.created_at) + '</small>' : '';
                    replyBlock = `<div class="reply-preview small"><strong>${escapeHtml(rp.full_name)}</strong>${rpTime}: ${rp.message}</div>`;
                }
                const msgHtml = `
                    <div class="message ${ownClass} ${isMentioned ? 'border-start border-warning border-4 bg-light' : ''}" data-id="${msgId}">
                        <div class="message-header">
                            <div>
                                <span class="message-sender">
                                    <a href="../../modules/profile.php?id=${msg.user_id}" class="text-decoration-none">${escapeHtml(msg.full_name || '')}</a>
                                </span>
                                <span class="badge user-badge bg-${roleColor}">${capitalize(String(msg.role || '').replace('_', ' '))}</span>
                                <small class="text-muted">@${escapeHtml(msg.username || '')}</small>
                            </div>
                            <div class="message-header-right">
                                <div class="message-time">${msg.created_at || ''}</div>
                                <div class="message-actions">
                                    <button type="button" class="chat-action-btn chat-reply" title="Reply" aria-label="Reply to message" data-mid="${msgId}" data-username="${escapeHtml(msg.username || '')}" data-message="${escapeHtml(msg.message || '')}"><i class="fas fa-reply"></i></button>
                                    ${(canEdit && !deletedByContent) ? `<button type="button" class="chat-action-btn chat-edit" title="Edit" aria-label="Edit message" data-mid="${msgId}" data-message="${escapeHtml(msg.message || '')}"><i class="fas fa-pen"></i></button>` : ``}
                                    ${(canDelete && !deletedByContent) ? `<button type="button" class="chat-action-btn chat-delete" title="Delete" aria-label="Delete message" data-mid="${msgId}"><i class="fas fa-trash"></i></button>` : ``}
                                    ${canViewHistoryAdmin ? `<button type="button" class="chat-action-btn chat-history" title="History" aria-label="View message history" data-mid="${msgId}"><i class="fas fa-history"></i></button>` : ``}
                                </div>
                            </div>
                        </div>
                        <div class="message-content">
                            ${replyBlock}
                            ${msg.message || ''}
                            <div class="message-meta">${msg.created_at || ''}</div>
                        </div>
                    </div>`;

                if (hasJQ) {
                    chatMessages.append(msgHtml);
                } else if (chatMessages) {
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = msgHtml;
                    if (wrapper.firstElementChild) chatMessages.appendChild(wrapper.firstElementChild);
                }
            });

            enhanceImages(hasJQ ? chatMessages[0] : chatMessages);
            applyEmbedTabOrder();
            ensureRecentMessageAnchor(false);
            scrollToBottom();
        }

        function fetchMessages() {
            const params = new URLSearchParams({
                action: 'fetch_messages',
                project_id: projectId,
                page_id: pageId,
                last_id: lastMessageId
            });
            fetch('<?php echo $baseDir; ?>/api/chat_actions.php?' + params.toString(), {
                headers: { 'Accept': 'application/json' }
            })
                .then(res => res.text())
                .then(text => {
                    try { return JSON.parse(text); } catch (e) { return []; }
                })
                .then(messages => {
                    if (messages && Array.isArray(messages) && messages.length > 0) {
                        appendMessages(messages);
                    }
                });
        }

        function escapeHtml(text) {
            return (text || '').replace(/[&<>"']/g, function(m) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m] || m;
            });
        }
        
        function capitalize(s) { return (s && s.length) ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
        
        fetchMessages();
        setInterval(fetchMessages, 2000);
        applyEmbedTabOrder();
        bindMessageKeyboardNavigation();
        if (!initialRecentMessageFocused) {
            ensureRecentMessageAnchor(true);
            initialRecentMessageFocused = true;
        }
        
        if (hasJQ) {
            $('#refreshChat').click(() => location.reload());
            $('#clearMessage').click(() => { setMessageHtml(''); updateCharCount(); });
        } else {
            const r = document.getElementById('refreshChat');
            if (r) r.addEventListener('click', () => location.reload());
            const c = document.getElementById('clearMessage');
            if (c) c.addEventListener('click', () => { setMessageHtml(''); updateCharCount(); });
        }

        if (isEmbed) {
            document.addEventListener('keydown', function(e) {
                if (!e || e.key !== 'Escape') return;
                try {
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({ type: 'pms-chat-close' }, '*');
                    }
                } catch (err) {}
            });
        }
    });
    </script>

<?php if (!$embed): ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<?php else: ?>
    </body>
    </html>
<?php endif; ?>
