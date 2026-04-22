<?php
require_once __DIR__ . '/../config/database.php';
// The database.php file usually defines Database class.

try {
    $db = Database::getInstance();
    $stmt = $db->query("DESCRIBE issues");
    echo "ISSUES TABLE:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['Field']} ({$row['Type']}) - Null: {$row['Null']}\n";
    }

    $stmt = $db->query("DESCRIBE common_issues");
    echo "\nCOMMON_ISSUES TABLE:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['Field']} ({$row['Type']}) - Null: {$row['Null']}\n";
    }

    $stmt = $db->prepare("SELECT id, issue_key FROM issues WHERE project_id = 10 LIMIT 10");
    $stmt->execute();
    echo "\nSAMPLE KEYS FOR PROJECT 10 (LIMIT 10):\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $keyStr = ($row['issue_key'] === null) ? 'NULL' : "'{$row['issue_key']}'";
        echo "ID: {$row['id']} | Key: $keyStr\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
