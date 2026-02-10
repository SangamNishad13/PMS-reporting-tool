<?php
// restore_page_assignment.php
// Usage: php restore_page_assignment.php [page_id] [user_id]
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getInstance();
$pageId = isset($argv[1]) ? intval($argv[1]) : 1;
$userId = isset($argv[2]) ? intval($argv[2]) : 5;
$projectId = 1; // known from earlier
$assigned_role = 'ft_tester';
$created_at = '2026-02-03 14:59:21';

$check = $pdo->prepare("SELECT 1 FROM assignments WHERE page_id = ? AND assigned_user_id = ? AND task_type = 'page_assignment' LIMIT 1");
$check->execute([$pageId, $userId]);
if ($check->fetchColumn()) {
    echo "Page-level assignment already exists for page {$pageId} user {$userId}\n";
    exit(0);
}

$insert = $pdo->prepare("INSERT INTO assignments (project_id,page_id,environment_id,task_type,assigned_user_id,assigned_role,meta,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?)");
$insert->execute([$projectId, $pageId, null, 'page_assignment', $userId, $assigned_role, null, null, $created_at]);
$id = $pdo->lastInsertId();
echo "Inserted page_assignment id={$id} for page {$pageId} user {$userId}\n";
