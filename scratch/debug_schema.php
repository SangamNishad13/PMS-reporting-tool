<?php
require_once __DIR__ . '/../includes/functions.php';
$db = Database::getInstance();
try {
    $stmt = $db->query("SHOW COLUMNS FROM page_environments");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if ($col['Field'] === 'qa_status' || $col['Field'] === 'status') {
            echo "Column: {$col['Field']}, Type: {$col['Type']}\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
