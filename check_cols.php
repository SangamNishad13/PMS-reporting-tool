<?php
require 'config/database.php';
$db = Database::getInstance();
$stmt = $db->query("SHOW COLUMNS FROM issue_comments");
echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
