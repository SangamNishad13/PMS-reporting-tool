<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();
$ids = [3534, 3547, 3561, 3564, 3567, 3570, 3574, 3576, 3577, 3589, 3592, 3596, 3613, 3617, 3620, 3792];
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($placeholders)");
$stmt->execute($ids);
$meta = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($meta, JSON_PRETTY_PRINT);
