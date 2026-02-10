<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$issueId = (int)($_GET['issue_id'] ?? 0);

if (!$issueId) {
    echo json_encode(['error' => 'issue_id required']);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT h.*, u.full_name as user_name 
        FROM issue_history h
        JOIN users u ON h.user_id = u.id
        WHERE h.issue_id = ?
        ORDER BY h.created_at DESC
    ");
    $stmt->execute([$issueId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'history' => $history]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
