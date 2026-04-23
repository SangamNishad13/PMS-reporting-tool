<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

echo "Starting Global Page Recovery Audit...\n";

// 1. Check for orphaned page_environments
$sql = "SELECT DISTINCT pe.page_id FROM page_environments pe LEFT JOIN project_pages pp ON pe.page_id = pp.id WHERE pp.id IS NULL";
$orphans = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);

if (empty($orphans)) {
    echo "No orphaned page_environments found. This is good.\n";
} else {
    echo "Found " . count($orphans) . " orphaned page IDs in page_environments.\n";
    foreach ($orphans as $oid) {
        echo " - Orphaned Page ID: $oid\n";
    }
}

// 2. Check for orphaned issue_pages
$sql = "SELECT DISTINCT ip.page_id FROM issue_pages ip LEFT JOIN project_pages pp ON ip.page_id = pp.id WHERE pp.id IS NULL";
$orphans2 = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);

if (empty($orphans2)) {
    echo "No orphaned pages found in issue_pages.\n";
} else {
    echo "Found " . count($orphans2) . " orphaned page IDs in issue_pages.\n";
}

// 3. Scan for pages that look 'Global' but are assigned to specific projects
$sql = "SELECT id, project_id, page_name, page_number FROM project_pages WHERE page_number LIKE 'Global %'";
$globals = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$projectCounts = [];
foreach ($globals as $g) {
    $pid = $g['project_id'];
    if (!isset($projectCounts[$pid])) $projectCounts[$pid] = 0;
    $projectCounts[$pid]++;
}

echo "Global-labeled pages count by project:\n";
foreach ($projectCounts as $pid => $count) {
    if ($pid == 0) echo " - Shared Global (project_id 0): $count\n";
    else echo " - Project $pid: $count\n";
}

echo "Done.\n";
