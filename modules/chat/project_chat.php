<?php
// modules/chat/project_chat.php

// Include configuration
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$baseDir = getBaseDir();

$embed = isset($_GET['embed']) && $_GET['embed'] === '1';

// Get project and page IDs
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$pageId = isset($_GET['page_id']) ? intval($_GET['page_id']) : 0;

// Connect to database
$db = Database::getInstance();

// Send message (non-AJAX fallback; AJAX handled via api/chat_actions, but keep safe here)
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    
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
            
            // If embed or ajax, return JSON to stay within widget
            if ($embed || $isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
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
        <style>body{background:#f8f9fa;} .container-embed{padding:12px;}</style>
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
                if (mentionDropdown && mentionDropdown.style.display === 'block') {
                    if (e.key === 'ArrowDown') { e.preventDefault(); moveMentionHighlight(1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); moveMentionHighlight(-1); }
                    else if (e.key === 'Enter') {
                        const active = mentionDropdown.querySelector('.mention-pick.active');
                        if (active) { e.preventDefault(); insertMention(active.getAttribute('data-username')); }
                    }
                }
        margin-bottom: 5px;
    }
    .message-sender { font-weight: bold; color: #333; }
    .message-time { font-size: 0.85em; color: #6c757d; }
    .message-content { word-wrap: break-word; }
    .reply-preview { border-left: 3px solid #e9ecef; background: #f8f9fa; padding:6px; margin-bottom:8px; }
    .mention { background-color: #fff3cd; padding: 2px 4px; border-radius: 3px; font-weight: bold; }
    .user-badge { font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
    .message-meta { font-size: 0.8em; color: #6c757d; margin-top: 4px; text-align: right; }

    /* Embed chat layout */
    .chat-embed body { background: #ece5dd; }
    .chat-embed .chat-shell { background: #ece5dd; border-radius: 16px; padding: 8px; }
    .chat-embed .chat-embed-wrapper { display: flex; flex-direction: column; height: 520px; background: #ece5dd; position: relative; }
    .chat-embed .chat-container { background: transparent; border: 0; box-shadow: none; padding: 6px; flex: 1; overflow-y: auto; }
    .chat-embed .message { box-shadow: none; border: 0; padding: 8px 12px; position: relative; }
    .chat-embed .message.other-message { background: #fff; margin-right: auto; }
    .chat-embed .message.own-message { background: #dcf8c6; margin-left: auto; }
    .chat-embed .message .message-content { font-size: 0.95rem; }
    .chat-embed .message .message-meta { font-size: 0.78rem; color: #6c757d; text-align: right; margin-top: 4px; }
    .chat-embed .message-header .user-badge { display: none; }
    .chat-embed .chat-embed-form { background: #f0f2f5; border-radius: 14px; padding: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); position: sticky; bottom: 0; }
    .chat-embed .chat-embed-form.collapsed { background: transparent; box-shadow: none; padding: 0; border-radius: 0; }
    .chat-embed #chatForm { position: relative; }
    #chatForm { position: relative; }
    .chat-embed .note-editor.note-frame { background: transparent; }
    .chat-embed .note-statusbar { display: none; }
    .chat-embed .note-toolbar { border: 0; background: transparent; padding: 4px 0 0 0; display: flex; flex-wrap: nowrap; overflow-x: auto; gap: 4px; }
    .chat-embed .note-toolbar .note-btn-group { float: none; display: inline-flex; flex-wrap: nowrap; }
    .chat-embed .note-editor { border: 0; box-shadow: none; resize: vertical; overflow: auto; }
    .chat-embed .note-editable { min-height: 40px; background: #fff; border-radius: 10px; }
    .chat-embed .btn { border-radius: 999px; }
    .chat-embed .chat-compose-toggle { position: fixed; bottom: 16px; left: 50%; transform: translateX(-50%); z-index: 1200; width: auto; min-width: 140px; }
    .chat-embed .chat-container { padding-bottom: 90px; }
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
                        <div class="d-flex align-items-center">
                            <div class="message-time me-2"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                        </div>
                    </div>
                    <div class="message-content">
                        <?php
                        $messageHtml = sanitize_chat_html($msg['message']);
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
                                $pstmt = $db->prepare("SELECT cm.id, cm.user_id, cm.message, u.username, u.full_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                                $pstmt->execute([$msg['reply_to']]);
                                $pr = $pstmt->fetch();
                            } catch (Exception $e) { $pr = null; }
                            if ($pr) {
                                $pmsg = sanitize_chat_html($pr['message']);
                                echo '<div class="reply-preview"><strong>' . htmlspecialchars($pr['full_name']) . '</strong>: ' . $pmsg . '</div>';
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

        <button type="button" class="btn btn-outline-primary btn-sm chat-compose-toggle mb-2" id="composeToggle">
            <i class="fas fa-comment-dots"></i> Compose
        </button>

        <form id="chatForm" class="chat-embed-form" action="javascript:void(0);" onsubmit="return false;">
            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
            <input type="hidden" name="page_id" value="<?php echo $pageId; ?>">
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
                    <button type="button" class="btn btn-success btn-sm" id="sendBtn">
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
                                        <div class="d-flex align-items-center">
                                            <div class="message-time me-2"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                                            <button class="btn btn-sm btn-link chat-reply" data-mid="<?php echo $msg['id']; ?>" data-username="<?php echo htmlspecialchars($msg['username']); ?>" data-message="<?php echo htmlspecialchars($msg['message']); ?>">Reply</button>
                                        </div>
                                    </div>
                                    <div class="message-content">
                                        <?php
                                        $messageHtml = sanitize_chat_html($msg['message']);
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
                                                $pstmt = $db->prepare("SELECT cm.id, cm.user_id, cm.message, u.username, u.full_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                                                $pstmt->execute([$msg['reply_to']]);
                                                $pr = $pstmt->fetch();
                                            } catch (Exception $e) { $pr = null; }
                                            if ($pr) {
                                                $pmsg = sanitize_chat_html($pr['message']);
                                                echo '<div class="reply-preview"><strong>' . htmlspecialchars($pr['full_name']) . '</strong>: ' . $pmsg . '</div>';
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
                        <form id="chatForm" class="mt-3" action="javascript:void(0);" onsubmit="return false;">
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
                                    <button type="button" class="btn btn-primary" id="sendBtn">
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

        // Upload image and insert URL into editor
        function uploadAndInsertImage(file) {
            if (!file || !file.type || !file.type.startsWith('image/')) return;
            const formData = new FormData();
            formData.append('image', file);
            return fetch('<?php echo $baseDir; ?>/api/chat_upload_image.php', {
                method: 'POST',
                body: formData
            }).then(res => res.json()).then(res => {
                if (res && res.success && res.url) {
                    if (summernoteReady) {
                        $msg.summernote('pasteHTML', '<p><img src="' + res.url + '" alt="image" /></p>');
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
        const composeToggle = document.getElementById('composeToggle');
        const composeForm = document.getElementById('chatForm');
        let composeCollapsed = isEmbed ? true : false;
        function updateComposeCollapse() {
            if (!composeBody) return;
            if (composeCollapsed) {
                composeBody.classList.remove('open');
                hideMentionDropdown();
            } else {
                composeBody.classList.add('open');
                if (summernoteReady && $msg) { try { $msg.summernote('focus'); } catch(e) {} }
            }
            if (composeForm) {
                if (composeCollapsed) composeForm.classList.add('collapsed');
                else composeForm.classList.remove('collapsed');
            }
            if (composeToggle) {
                composeToggle.innerHTML = composeCollapsed ? '<i class="fas fa-comment-dots"></i> Compose' : '<i class="fas fa-chevron-down"></i> Hide';
            }
        }
        updateComposeCollapse();
        if (composeToggle) {
            composeToggle.addEventListener('click', function(){
                composeCollapsed = !composeCollapsed;
                updateComposeCollapse();
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
                        onImageUpload: function(files) {
                            if (suppressImageUpload) return;
                            (files || []).forEach(uploadAndInsertImage);
                        },
                        onPaste: function(e) {
                            const clipboard = e.originalEvent && e.originalEvent.clipboardData;
                            if (clipboard && clipboard.items) {
                                for (let i = 0; i < clipboard.items.length; i++) {
                                    const item = clipboard.items[i];
                                    if (item.type && item.type.indexOf('image') === 0) {
                                        e.preventDefault();
                                        suppressImageUpload = true;
                                        uploadAndInsertImage(item.getAsFile());
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
                $('#chatReplyTo').val(mid);
                $('#chatReplyPreview .reply-user').text(username);
                $('#chatReplyPreview .reply-preview').html(message);
                $('#chatReplyPreview').show();
                $('#message').summernote && $('#message').summernote('focus');
            });

            $(document).on('click', '#chatCancelReply', function(){
                $('#chatReplyTo').val('');
                $('#chatReplyPreview').hide();
                $('#chatReplyPreview .reply-preview').html('');
            });
        }

        function sendChatMessage() {
            const msg = getMessageHtml();
            const textOnly = hasJQ ? $('<div>').html(msg).text().trim() : (new DOMParser().parseFromString(msg, 'text/html').documentElement.textContent || '').trim();
            const hasImg = /<img\b[^>]*src=/i.test(msg);
            if(!textOnly && !hasImg) {
                showToast('Type a message to send', 'warning');
                return;
            }

            hideMentionDropdown();

            if (hasJQ) $('#sendBtn').prop('disabled', true); else { const b = document.getElementById('sendBtn'); if (b) b.disabled = true; }

            const replyTo = hasJQ ? ($('#chatReplyTo').val() || '') : (document.getElementById('chatReplyTo') ? document.getElementById('chatReplyTo').value : '');
            const payload = new URLSearchParams({ project_id: projectId, page_id: pageId, message: msg, reply_to: replyTo });

            fetch('<?php echo $baseDir; ?>/api/chat_actions.php?action=send_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString()
            }).then(res => res.json()).then(res => {
                if (res && res.success) {
                    setMessageHtml('');
                    updateCharCount();
                    fetchMessages();
                    scrollToBottom();
                    if (isEmbed) {
                        composeCollapsed = true;
                        updateComposeCollapse();
                    }
                } else if (res && res.error) {
                    showToast(res.error, 'danger');
                } else {
                    showToast('Failed to send message', 'danger');
                }
            }).catch(err => {
                console.error('Chat send failed', err);
                showToast('Failed to send message', 'danger');
            }).finally(() => {
                if (hasJQ) $('#sendBtn').prop('disabled', false); else { const b = document.getElementById('sendBtn'); if (b) b.disabled = false; }
            });
        }

        if (hasJQ) {
            $('#sendBtn').on('click', function() { sendChatMessage(); });
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
            const btn = document.getElementById('sendBtn');
            const form = document.getElementById('chatForm');
            const msgEl = document.getElementById('message');
            if (btn) btn.addEventListener('click', sendChatMessage);
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
                if (e.key === 'Enter' || e.key === ' ') {
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

        function fetchMessages() {
            const params = new URLSearchParams({
                action: 'fetch_messages',
                project_id: projectId,
                page_id: pageId,
                last_id: lastMessageId
            });
            fetch('<?php echo $baseDir; ?>/api/chat_actions.php?' + params.toString())
                .then(res => res.json())
                .then(messages => {
                    if(messages && messages.length > 0) {
                        if (hasJQ) {
                            $('.no-messages').remove();
                        } else {
                            const nm = document.querySelector('.no-messages');
                            if (nm && nm.parentNode) nm.parentNode.removeChild(nm);
                        }

                        messages.forEach(msg => {
                            if(msg.id > lastMessageId) lastMessageId = msg.id;

                            let isMentioned = false;
                            if(msg.mentions) {
                                try {
                                    const mentions = JSON.parse(msg.mentions);
                                    if(Array.isArray(mentions) && mentions.includes(currentUserId)) isMentioned = true;
                                } catch(e) {}
                            }

                            const roleColor = msg.role === 'admin' ? 'danger' : (msg.role === 'project_lead' ? 'warning' : 'info');
                            const ownClass = (Number(msg.user_id) === currentUserId) ? 'own-message' : 'other-message';
                            let replyBlock = '';
                            if (msg.reply_preview) {
                                const rp = msg.reply_preview;
                                const rpTime = rp.created_at ? ' <small class="text-muted ms-2">' + escapeHtml(rp.created_at) + '</small>' : '';
                                replyBlock = `<div class="reply-preview small"><strong>${escapeHtml(rp.full_name)}</strong>${rpTime}: ${rp.message}</div>`;
                            }
                            const msgHtml = `
                                <div class="message ${ownClass} ${isMentioned ? 'border-start border-warning border-4 bg-light' : ''}" data-id="${msg.id}">
                                    <div class="message-header">
                                        <div>
                                            <span class="message-sender">
                                                <a href="../../modules/profile.php?id=${msg.user_id}" class="text-decoration-none">${escapeHtml(msg.full_name)}</a>
                                            </span>
                                            <span class="badge user-badge bg-${roleColor}">${capitalize(msg.role.replace('_', ' '))}</span>
                                            <small class="text-muted">@${escapeHtml(msg.username)}</small>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <div class="message-time me-2">${msg.created_at}</div>
                                            <button class="btn btn-sm btn-link chat-reply" data-mid="${msg.id}" data-username="${escapeHtml(msg.username)}">Reply</button>
                                        </div>
                                    </div>
                                    <div class="message-content">
                                        ${replyBlock}
                                        ${msg.message}
                                        <div class="message-meta">${msg.created_at}</div>
                                    </div>
                                </div>`;

                            if (hasJQ) {
                                chatMessages.append(msgHtml);
                            } else if (chatMessages) {
                                const wrapper = document.createElement('div');
                                wrapper.innerHTML = msgHtml;
                                chatMessages.appendChild(wrapper.firstElementChild);
                            }
                        });
                        enhanceImages(hasJQ ? chatMessages[0] : chatMessages);
                        scrollToBottom();
                    }
                });
        }

        function escapeHtml(text) {
            return (text || '').replace(/[&<>"']/g, function(m) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m] || m;
            });
        }
        
        function capitalize(s) { return (s && s.length) ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
        
        setInterval(fetchMessages, 5000);
        
        if (hasJQ) {
            $('#refreshChat').click(() => location.reload());
            $('#clearMessage').click(() => { setMessageHtml(''); updateCharCount(); });
        } else {
            const r = document.getElementById('refreshChat');
            if (r) r.addEventListener('click', () => location.reload());
            const c = document.getElementById('clearMessage');
            if (c) c.addEventListener('click', () => { setMessageHtml(''); updateCharCount(); });
        }
    });
    </script>

<?php if (!$embed): ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<?php else: ?>
    </body>
    </html>
<?php endif; ?>
