<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$projectId = 41;
$db = Database::getInstance();

echo "--- RECOVERY DISCOVERY FOR PROJECT 41 ---\n\n";

// 1. Search for remove_page activity
echo "[1] Searching Activity Log for 'remove_page' in Project 41...\n";
$stmt = $db->prepare("
    SELECT id, action, entity_id, details, created_at, user_id
    FROM activity_log
    WHERE action = 'remove_page' AND entity_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$projectId]);
$removals = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($removals) . " removal activities.\n";
$deletedPageIds = [];
foreach ($removals as $r) {
    $details = json_decode($r['details'], true);
    $deletedPageId = $details['page_id'] ?? null;
    if ($deletedPageId) {
        $deletedPageIds[$deletedPageId] = $r['created_at'];
        echo "- Page ID $deletedPageId removed at " . $r['created_at'] . "\n";
    }
}

// 2. Try to find metadata for these deleted IDs in other logs
if (!empty($deletedPageIds)) {
    echo "\n[2] Attempting to find metadata for deleted IDs...\n";
    foreach ($deletedPageIds as $pid => $removedAt) {
        // Look for creation or assignment logs for this page_id
        $stmt = $db->prepare("
            SELECT details, action, created_at
            FROM activity_log
            WHERE entity_type = 'page' AND entity_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$pid]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($logs as $l) {
            $d = json_decode($l['details'], true);
            echo "  - Log for Page ID $pid ($l[action] at $l[created_at]): " . json_encode($d) . "\n";
        }
    }
}

// 3. Find orphaned issues in Project 41
echo "\n[3] Searching for issues in Project 41 with no mapped page...\n";
$stmt = $db->prepare("
    SELECT i.id, i.issue_key, i.title, i.page_id
    FROM issues i
    LEFT JOIN project_pages pp ON i.page_id = pp.id
    WHERE i.project_id = ? AND (i.page_id IS NULL OR pp.id IS NULL)
");
$stmt->execute([$projectId]);
$orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($orphans) . " issues without a valid page reference.\n";
foreach ($orphans as $o) {
    echo "  - Issue " . $o['issue_key'] . " (ID: " . $o['id'] . "): " . $o['title'] . ( $o['page_id'] ? " (Linked to invalid Page ID: " . $o['page_id'] . ")" : " (No page assigned)" ) . "\n";
}

// 4. Check Issue History for page_id changes
echo "\n[4] Checking Issue History for page_id changes in Project 41...\n";
$stmt = $db->prepare("
    SELECT h.issue_id, i.issue_key, h.old_value, h.new_value, h.created_at
    FROM issue_history h
    JOIN issues i ON h.issue_id = i.id
    WHERE i.project_id = ? AND h.field_name = 'page_id'
    ORDER BY h.created_at DESC
");
$stmt->execute([$projectId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($history) . " history entries for page_id.\n";
foreach ($history as $h) {
    if (!$h['new_value'] || $h['new_value'] == 'null') {
        echo "  - Issue " . $h['issue_key'] . " page_id cleared: " . $h['old_value'] . " -> NULL at " . $h['created_at'] . "\n";
    }
}

// 5. Keyword search for "MuthootOne" in issues
echo "\n[5] Keyword search for 'MuthootOne' in issues...\n";
$stmt = $db->prepare("
    SELECT id, issue_key, title
    FROM issues
    WHERE project_id = ? AND (title LIKE '%MuthootOne%' OR description LIKE '%MuthootOne%')
");
$stmt->execute([$projectId]);
$muthoots = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($muthoots) . " issues mentioning 'MuthootOne'.\n";

echo "\n--- Discovery Complete ---\n";
