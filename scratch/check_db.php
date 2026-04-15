<?php
require_once 'config/database.php';
$db = Database::getInstance();
$stmt = $db->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Tables:\n";
print_r($tables);

$history = [];
try {
    $stmt = $db->query("SELECT * FROM migration_history");
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\nMigration History:\n";
    print_r($history);
} catch (Exception $e) {
    echo "\nmigration_history table does not exist.\n";
}
