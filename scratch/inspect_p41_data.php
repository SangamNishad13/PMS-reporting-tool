<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

$projectId = 41;
$stmt = $db->prepare("SELECT id, page_number, page_name FROM project_pages WHERE project_id = ? ORDER BY id ASC LIMIT 20");
$stmt->execute([$projectId]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "--- RAW DATA FOR PROJECT 41 PAGES ---\n";
foreach ($results as $row) {
    $val = $row['page_number'];
    echo "ID: " . $row['id'] . " | Page Name: " . $row['page_name'] . " | Raw Page Num: [" . $val . "] | Hex: " . bin2hex($val) . "\n";
}
