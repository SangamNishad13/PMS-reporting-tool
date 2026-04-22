<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

echo "=== FIXING PROJECT 41 HYPHENS ===\n\n";

$projectId = 41;

try {
    $db->beginTransaction();

    // Fetch all pages in Project 41 ordered by ID
    $stmt = $db->prepare("SELECT id, page_number, page_name FROM project_pages WHERE project_id = ? ORDER BY id ASC");
    $stmt->execute([$projectId]);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($pages) . " pages.\n";

    $updated = 0;
    foreach ($pages as $index => $row) {
        $expectedNumber = "Page " . ($index + 1);
        
        // If current number is empty or just a hyphen, update it
        if (empty(trim((string)$row['page_number'])) || trim((string)$row['page_number']) === '-') {
            $upd = $db->prepare("UPDATE project_pages SET page_number = ? WHERE id = ?");
            $upd->execute([$expectedNumber, $row['id']]);
            $updated++;
            // echo "  Updating ID {$row['id']} ('{$row['page_name']}') -> $expectedNumber\n";
        }
    }

    $db->commit();
    echo "\nSuccessfully fixed $updated missing page numbers.\n";
    echo "=== FIX COMPLETE ===\n";

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
