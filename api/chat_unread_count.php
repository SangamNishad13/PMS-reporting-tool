<?php
// API to get unread chat count
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chat_helpers.php';
require_once __DIR__ . '/../config/database.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$pageId = isset($_GET['page_id']) ? intval($_GET['page_id']) : null;

try {
    $unreadCount = getUnreadChatCount($db, $userId, $projectId, $pageId);
    
    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
