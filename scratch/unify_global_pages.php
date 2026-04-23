<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

echo "Starting Global Page Unification...\n";

// 1. Find all pages that look Global
$sql = "SELECT id, project_id, page_name, page_number FROM project_pages WHERE page_number LIKE 'Global %' ORDER BY page_number ASC, id ASC";
$pages = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (empty($pages)) {
    echo "No global pages found to unify.\n";
    exit;
}

$globalGroups = [];
foreach ($pages as $p) {
    $key = trim($p['page_number']);
    if (!isset($globalGroups[$key])) $globalGroups[$key] = [];
    $globalGroups[$key][] = $p;
}

foreach ($globalGroups as $pageNo => $group) {
    echo "Processing $pageNo...\n";
    
    // Pick the first one as the 'Master' shared page
    $master = $group[0];
    $masterId = $master['id'];
    
    echo " - Setting Page ID $masterId as the SHARED Global (project_id = 0)\n";
    $db->prepare("UPDATE project_pages SET project_id = 0 WHERE id = ?")->execute([$masterId]);
    
    // If there are duplicates, merge them into the master
    for ($i = 1; $i < count($group); $i++) {
        $duplicateId = $group[$i]['id'];
        echo " - Merging duplicate Page ID $duplicateId into Master ID $masterId\n";
        
        // Re-map environments
        try {
            $db->prepare("UPDATE IGNORE page_environments SET page_id = ? WHERE page_id = ?")->execute([$masterId, $duplicateId]);
            $db->prepare("DELETE FROM page_environments WHERE page_id = ?")->execute([$duplicateId]);
        } catch (Exception $e) { echo "   [Warn] Env merge for $duplicateId: " . $e->getMessage() . "\n"; }
        
        // Re-map issues
        try {
            $db->prepare("UPDATE IGNORE issue_pages SET page_id = ? WHERE page_id = ?")->execute([$masterId, $duplicateId]);
            $db->prepare("DELETE FROM issue_pages WHERE page_id = ?")->execute([$duplicateId]);
        } catch (Exception $e) { echo "   [Warn] Issue merge for $duplicateId: " . $e->getMessage() . "\n"; }
        
        // Delete the duplicate page record
        $db->prepare("DELETE FROM project_pages WHERE id = ?")->execute([$duplicateId]);
    }
}

echo "Unification Complete. All 'Global' pages are now shared across all projects.\n";
