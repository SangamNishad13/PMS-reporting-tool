<?php
/**
 * FIX SCRIPT FOR PROJECT 41 - DATA INTEGRITY & NUMBERING
 * 
 * This script addresses:
 * 1. Missing Pages: Identifies and reports issues linked to deleted pages.
 * 2. Inconsistent Numbering: Populates the 'page_number' column in the database
 *    so it matches the desired sequence (Global 1, Page 1...) permanently.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$projectId = 41;
$db = Database::getInstance();

echo "=== PROJECT 41 FIX & DIAGNOSTIC SCRIPT ===\n\n";

// --- PART 1: DIAGNOSTIC ---

echo "[1/3] Checking for orphaned issues...\n";
$stmt = $db->prepare("
    SELECT i.id, i.issue_key, i.title, i.page_id
    FROM issues i
    LEFT JOIN project_pages pp ON i.page_id = pp.id
    WHERE i.project_id = ? AND i.page_id IS NOT NULL AND pp.id IS NULL
");
$stmt->execute([$projectId]);
$orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($orphans)) {
    echo "!!! WARNING: Found " . count($orphans) . " issues linked to DELETED pages.\n";
    echo "These issues exist but their associated pages are gone from the database.\n";
    foreach ($orphans as $o) {
        echo "  - Issue " . $o['issue_key'] . " (ID: " . $o['id'] . ") was linked to Page ID: " . $o['page_id'] . "\n";
    }
} else {
    echo "OK: No orphaned issues found in issues table.\n";
}

// --- PART 2: NUMBERING FIX ---

echo "\n[2/3] Re-sequencing and persisting page numbers in database...\n";

// Fetch pages in the exact order they should appear
$stmt = $db->prepare("
    SELECT id, page_name, page_number 
    FROM project_pages 
    WHERE project_id = ? 
    ORDER BY 
        CASE 
            WHEN page_number LIKE 'Global%' THEN 0 
            WHEN page_number LIKE 'Page%' THEN 1 
            ELSE 2 
        END, 
        CAST(SUBSTRING_INDEX(page_number, ' ', -1) AS UNSIGNED), 
        page_number, 
        page_name, 
        id
");
$stmt->execute([$projectId]);
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$globalSeq = 1;
$pageSeq = 1;

$updateStmt = $db->prepare("UPDATE project_pages SET page_number = ? WHERE id = ?");

foreach ($pages as $p) {
    $oldNum = $p['page_number'];
    $newNum = '';
    
    if (stripos($oldNum, 'Global') === 0 || stripos($p['page_name'], 'Global') === 0) {
        $newNum = 'Global ' . $globalSeq++;
    } else {
        $newNum = 'Page ' . $pageSeq++;
    }
    
    if ($oldNum !== $newNum) {
        echo "Updating Page ID " . $p['id'] . ": '" . $oldNum . "' -> '" . $newNum . "'\n";
        $updateStmt->execute([$newNum, $p['id']]);
    }
}

echo "DONE: Page numbers have been persisted in the database.\n";

// --- PART 3: RECOVERY (OPTIONAL/REPORT) ---

echo "\n[3/3] Checking if 'missing' pages can be recovered from other projects...\n";
$stmt = $db->prepare("
    SELECT i.id, i.issue_key, pp.id as actual_page_id, pp.project_id as page_project_id
    FROM issues i
    JOIN project_pages pp ON i.page_id = pp.id
    WHERE i.project_id = ? AND pp.project_id != ?
");
$stmt->execute([$projectId, $projectId]);
$mismatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($mismatches)) {
    echo "!!! WARNING: Found " . count($mismatches) . " issues whose pages belong to OTHER projects.\n";
    foreach ($mismatches as $m) {
        echo "  - Issue " . $m['issue_key'] . " is linked to Page " . $m['actual_page_id'] . " in Project " . $m['page_project_id'] . "\n";
    }
} else {
    echo "OK: No mismatched project associations found.\n";
}

echo "\n=== SCRIPT COMPLETE ===\n";
echo "The pages should now have consistent numbering across all views.\n";
echo "If pages are still 'missing', they were likely permanently deleted from the project_pages table.\n";
