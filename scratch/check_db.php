<?php
require_once __DIR__ . '/../includes/functions.php';
$db = Database::getInstance();

function checkTable($db, $tableName) {
    echo "--- $tableName ---\n";
    try {
        $cols = $db->query("DESCRIBE $tableName")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            echo "{$col['Field']} - {$col['Type']}\n";
        }
    } catch (Exception $e) {
        echo "Error describe $tableName: " . $e->getMessage() . "\n";
    }
}

checkTable($db, 'page_environments');
checkTable($db, 'activity_log');
checkTable($db, 'project_pages');
