<?php
require_once __DIR__ . '/includes/db.php';
$db = Database::getInstance();
$stmt = $db->query("SELECT title, created_at, updated_at, completed_at FROM projects WHERE status='completed'");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($projects) . "\n";
print_r($projects);
