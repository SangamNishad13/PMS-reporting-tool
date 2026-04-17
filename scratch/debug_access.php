<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();
try {
    echo "--- Users ---\n";
    $s = $db->query("SELECT id, username, role FROM users");
    print_r($s->fetchAll(PDO::FETCH_ASSOC));

    echo "\n--- Projects ---\n";
    $s = $db->query("SELECT id, title FROM projects WHERE id = 26");
    print_r($s->fetchAll(PDO::FETCH_ASSOC));

    echo "\n--- Project Pages ---\n";
    $s = $db->query("SELECT id, page_name FROM project_pages WHERE id = 8263");
    print_r($s->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
