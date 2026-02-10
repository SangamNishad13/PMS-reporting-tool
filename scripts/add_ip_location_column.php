<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();
try {
    $db->exec("ALTER TABLE user_sessions ADD COLUMN ip_location TEXT NULL AFTER ip_address");
    echo "Added ip_location column to user_sessions (if not exists).\n";
} catch (Exception $e) {
    echo "Alter table error (may already exist): " . $e->getMessage() . "\n";
}

?>
