<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();
$stmt = $db->prepare("SELECT id, page_name, url, page_number FROM project_pages WHERE project_id = 41 ORDER BY page_number ASC");
$stmt->execute();
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($pages, JSON_PRETTY_PRINT);
