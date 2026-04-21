<?php
require_once __DIR__ . '/../includes/functions.php';
$db = Database::getInstance();

function checkValueCounts($db, $tableName, $columnName) {
    echo "--- $tableName ($columnName) ---\n";
    try {
        $rows = $db->query("SELECT $columnName, COUNT(*) as count FROM $tableName GROUP BY $columnName")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            echo "{$row[$columnName]} - {$row['count']}\n";
        }
    } catch (Exception $e) {
        echo "Error check $tableName $columnName: " . $e->getMessage() . "\n";
    }
}

checkValueCounts($db, 'page_environments', 'status');
checkValueCounts($db, 'page_environments', 'qa_status');
