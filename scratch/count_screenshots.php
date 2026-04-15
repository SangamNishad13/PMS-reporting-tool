<?php
require_once 'config/database.php';
$db = Database::getInstance();
$stmt = $db->query("SELECT COUNT(*) FROM issue_page_screenshots");
echo "Total screenshots: " . $stmt->fetchColumn() . "\n";

$stmt = $db->query("SELECT * FROM issue_page_screenshots ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
