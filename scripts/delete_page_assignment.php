<?php
// delete_page_assignment.php
// Usage: php delete_page_assignment.php [page_id] [user_id]
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getInstance();
$pageId = isset($argv[1]) ? intval($argv[1]) : 1;
$userId = isset($argv[2]) ? intval($argv[2]) : 5;

$stmt = $pdo->prepare('DELETE FROM assignments WHERE page_id = ? AND assigned_user_id = ? AND task_type = ?');
$stmt->execute([$pageId, $userId, 'page_assignment']);
$deleted = $stmt->rowCount();
echo "Deleted records: $deleted\n";
