<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();
try {
    $sql = "ALTER TABLE page_environments 
            MODIFY COLUMN status ENUM('not_started', 'in_progress', 'completed', 'on_hold', 'needs_review', 'pass', 'fail') 
            DEFAULT 'not_started'";
    $db->exec($sql);
    echo "Successfully updated page_environments table schema.\n";
    
    $res = $db->query("DESCRIBE page_environments");
    print_r($res->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
