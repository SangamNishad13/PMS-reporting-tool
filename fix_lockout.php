<?php
require_once __DIR__ . '/config/database.php';
try {
    $db = Database::getInstance();
    $stmt = $db->query("SHOW TABLES LIKE 'login_attempts'");
    $exists = $stmt->fetch();
    if ($exists) {
        echo "Table login_attempts EXISTS.\n";
    } else {
        echo "Table login_attempts DOES NOT EXIST.\n";
        
        // Try to create it
        $sql = file_get_contents(__DIR__ . '/database/migrations/add_login_attempts.sql');
        if ($sql) {
            echo "Attempting to create table...\n";
            $db->exec($sql);
            echo "Table created successfully!\n";
        } else {
            echo "Migration file not found.\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
