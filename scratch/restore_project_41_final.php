<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

echo "=== PROJECT 41 FINAL RESTORATION ===\n\n";

$projectId = 41;
$loginPageId = 8806; // The one recovered in previous step

try {
    $db->beginTransaction();

    // 1. Create the missing "Home" page (Page 25)
    echo "[1/2] Creating 'Home' page (Page 25)...\n";
    $stmt = $db->prepare("
        INSERT INTO project_pages (project_id, page_name, url, notes, page_number)
        VALUES (?, 'Home', 'Home', 'Navigation - Home', 'Page 25')
    ");
    $stmt->execute([$projectId]);
    $homePageId = $db->lastInsertId();
    echo "Created 'Home' page with ID: $homePageId\n";

    // 2. Perform a total re-sequencing to ensure everything is in its correct place
    echo "[2/2] Performing master re-sequencing for Project 41...\n";
    
    // Fetch all pages except our new ones (which are at the end by ID)
    $stmt = $db->prepare("SELECT id, page_name FROM project_pages WHERE project_id = ? ORDER BY id ASC");
    $stmt->execute([$projectId]);
    $allPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter out the ones we want to handle specifically
    $fixedOrder = [];
    $others = [];
    
    foreach ($allPages as $p) {
        if ($p['id'] == $loginPageId) {
            // This is Page 1
            $fixedOrder[1] = $p['id'];
        } else if ($p['id'] == $homePageId) {
            // This is Page 25
            $fixedOrder[25] = $p['id'];
        } else {
            $others[] = $p['id'];
        }
    }

    // Now fill the gaps
    $results = [];
    $otherIndex = 0;
    
    for ($i = 1; $i <= count($allPages); $i++) {
        if (isset($fixedOrder[$i])) {
            $results[$i] = $fixedOrder[$i];
        } else {
            if (isset($others[$otherIndex])) {
                $results[$i] = $others[$otherIndex];
                $otherIndex++;
            }
        }
    }

    // Apply the new numbers
    foreach ($results as $num => $id) {
        $label = "Page " . $num;
        $upd = $db->prepare("UPDATE project_pages SET page_number = ? WHERE id = ?");
        $upd->execute([$label, $id]);
        // echo "  Mapping $label to ID $id\n";
    }

    echo "Successfully re-sequenced " . count($results) . " pages.\n";

    $db->commit();
    echo "\n=== ALL DATA RECOVERED SUCCESSFULLY ===\n";
    echo "Summary:\n";
    echo "- Page 1 restored: Login\n";
    echo "- Page 25 restored: Home (Navigation - Home)\n";
    echo "- Total Pages in Project 41: " . count($results) . "\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
