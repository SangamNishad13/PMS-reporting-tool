<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();
try {
    $res = $db->query("SELECT DISTINCT status FROM page_environments");
    print_r($res->fetchAll(PDO::FETCH_COLUMN));
    
    $res2 = $db->query("DESCRIBE page_environments");
    print_r($res2->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
