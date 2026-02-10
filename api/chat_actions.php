<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    case 'send_message':
        $projectId = (isset($_POST['project_id']) && $_POST['project_id'] !== '' && $_POST['project_id'] !== 'null' && $_POST['project_id'] !== '0') ? intval($_POST['project_id']) : null;
        $pageId = (isset($_POST['page_id']) && $_POST['page_id'] !== '' && $_POST['page_id'] !== 'null' && $_POST['page_id'] !== '0') ? intval($_POST['page_id']) : null;
        $replyTo = (isset($_POST['reply_to']) && is_numeric($_POST['reply_to'])) ? intval($_POST['reply_to']) : null;
        $message = trim($_POST['message'] ?? '');
        
        if (empty($message)) {
            echo json_encode(['error' => 'Message cannot be empty']);
            exit;
        }
        
        $mentions = [];
        preg_match_all('/@(\w+)/', $message, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $username) {
                $stmt = $db->prepare("SELECT id, full_name FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($user = $stmt->fetch()) {
                    $mentions[] = $user['id'];
                    
                    // Create notification for mentioned user
                    if ($user['id'] != $userId) {
                        $notifyMsg = $_SESSION['full_name'] . " mentioned you in a chat.";
                        $link = "/modules/chat/project_chat.php";
                        $params = [];
                        if ($projectId) $params[] = "project_id=" . $projectId;
                        if ($pageId) $params[] = "page_id=" . $pageId;
                        if (!empty($params)) $link .= "?" . implode("&", $params);
                        
                        $nStmt = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'mention', ?, ?)");
                        $nStmt->execute([$user['id'], $notifyMsg, $link]);
                    }
                }
            }
        }
        
        // Ensure reply_to column exists if needed
        if ($replyTo !== null) {
            try {
                $db->exec("ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS reply_to INT NULL");
            } catch (Exception $e) {
                // Some MySQL versions don't support IF NOT EXISTS for ALTER ADD COLUMN; ignore errors
                try {
                    $db->exec("ALTER TABLE chat_messages ADD COLUMN reply_to INT NULL");
                } catch (Exception $e) {
                    // ignore
                }
            }
        }

        if ($replyTo !== null) {
            $stmt = $db->prepare("INSERT INTO chat_messages (project_id, page_id, user_id, message, mentions, reply_to) VALUES (?, ?, ?, ?, ?, ?)");
            $executed = $stmt->execute([$projectId, $pageId, $userId, $message, json_encode($mentions), $replyTo]);
        } else {
            $stmt = $db->prepare("INSERT INTO chat_messages (project_id, page_id, user_id, message, mentions) VALUES (?, ?, ?, ?, ?)");
            $executed = $stmt->execute([$projectId, $pageId, $userId, $message, json_encode($mentions)]);
        }
        if ($executed) {
            $lastId = $db->lastInsertId();
            echo json_encode(['success' => true, 'id' => $lastId]);
        } else {
            $errorInfo = $stmt->errorInfo();
            $err = $errorInfo[2] ?? 'Unknown error';
            echo json_encode(['error' => 'Failed to save message: ' . $err]);
        }
        break;

    case 'fetch_messages':
        $projectId = (isset($_GET['project_id']) && $_GET['project_id'] !== '' && $_GET['project_id'] !== 'null') ? intval($_GET['project_id']) : null;
        $pageId = (isset($_GET['page_id']) && $_GET['page_id'] !== '' && $_GET['page_id'] !== 'null') ? intval($_GET['page_id']) : null;
        $lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
        
        $sql = "SELECT cm.*, u.username, u.full_name, u.role FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id > ? ";
        $params = [$lastId];
        
        if ($pageId) {
            $sql .= "AND cm.page_id = ? ";
            $params[] = $pageId;
        } elseif ($projectId) {
            $sql .= "AND cm.project_id = ? AND cm.page_id IS NULL ";
            $params[] = $projectId;
        } else {
            $sql .= "AND cm.project_id IS NULL AND cm.page_id IS NULL ";
        }
        
        $sql .= "ORDER BY cm.created_at ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Sanitize message HTML for each row and include reply preview if available
        require_once __DIR__ . '/../includes/functions.php';
        foreach ($rows as &$r) {
            $r['message'] = sanitize_chat_html($r['message'] ?? '');
            if (!empty($r['reply_to'])) {
                try {
                    $pr = $db->prepare("SELECT cm.id, cm.user_id, cm.message, cm.created_at, u.username, u.full_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                    $pr->execute([$r['reply_to']]);
                    $pRow = $pr->fetch(PDO::FETCH_ASSOC);
                    if ($pRow) {
                        $pRow['message'] = sanitize_chat_html($pRow['message'] ?? '');
                        $r['reply_preview'] = [
                            'id' => $pRow['id'],
                            'user_id' => $pRow['user_id'],
                            'username' => $pRow['username'],
                            'full_name' => $pRow['full_name'],
                            'message' => $pRow['message'],
                            'created_at' => $pRow['created_at'] ?? null
                        ];
                    }
                } catch (Exception $e) {
                    // ignore preview errors
                }
            }
        }
        echo json_encode($rows);
        break;
        
    case 'get_notifications':
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$userId]);
        $unread = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $countStmt->execute([$userId]);
        $count = $countStmt->fetchColumn();
        
        echo json_encode(['notifications' => $unread, 'unread_count' => $count]);
        break;
        
    case 'mark_read':
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
        } else {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}
