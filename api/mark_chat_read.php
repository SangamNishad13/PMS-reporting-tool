<?php
// API to mark chat messages as read
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chat_helpers.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$pageId = isset($_GET['page_id']) ? intval($_GET['page_id']) : null;

try {
    $success = markChatMessagesAsRead($db, $userId, $projectId, $pageId);
    
    echo json_encode([
        'success' => $success,
        'message' => 'Messages marked as read'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
