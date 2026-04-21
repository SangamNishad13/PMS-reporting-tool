<?php
require_once __DIR__ . '/../includes/functions.php';
$db = Database::getInstance();
try {
    $stmt = $db->query("SELECT project_type, COUNT(*) as count FROM issue_presets GROUP BY project_type");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- issue_presets counts ---\n";
    foreach ($rows as $row) {
        echo "Type: {$row['project_type']}, Count: {$row['count']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
