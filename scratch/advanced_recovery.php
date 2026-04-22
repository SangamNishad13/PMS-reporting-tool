<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

echo "--- ADVANCED RECOVERY DISCOVERY ---\n\n";

// 1. Identify the Gap IDs
echo "[1] Identifying potential deleted Page IDs...\n";
// From previous logs, we know 8631 is current Page 1.
// Let's look for issues that were linked to IDs < 8631 or around 8654.
$stmt = $db->query("SELECT DISTINCT old_value FROM issue_history WHERE field_name = 'page_id' AND old_value IS NOT NULL AND old_value != ''");
$historicalIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Found " . count($historicalIds) . " distinct historical Page IDs in history.\n";

// 2. Search Activity Log for the creation of IDs that are now missing
echo "\n[2] Searching for CREATION logs of pages that no longer exist...\n";
$stmt = $db->query("SELECT id FROM project_pages WHERE project_id = 41");
$currentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Look for 'quick_add_page' or 'assign_page' in Project 41
$stmt = $db->prepare("
    SELECT entity_id, details, created_at 
    FROM activity_log 
    WHERE action IN ('quick_add_page', 'assign_page') 
    AND (details LIKE '%\"project_id\":41%' OR details LIKE '%\"project_id\":\"41\"%')
    ORDER BY created_at ASC
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$recoveredMetadata = [];
foreach ($logs as $l) {
    $details = json_decode($l['details'], true);
    $pageId = $l['entity_id'] ?: ($details['page_id'] ?? null);
    if ($pageId && !in_array($pageId, $currentIds)) {
        $recoveredMetadata[$pageId] = [
            'name' => $details['page_name'] ?? ($details['title'] ?? 'Unknown'),
            'url' => $details['url'] ?? '',
            'num' => $details['page_number'] ?? '',
            'created_at' => $l['created_at']
        ];
        echo "Found Deleted Page ID $pageId: '" . $recoveredMetadata[$pageId]['name'] . "' (" . $recoveredMetadata[$pageId]['url'] . ") created at " . $l['created_at'] . "\n";
    }
}

// 3. Match 16 Orphaned Issues to these IDs
echo "\n[3] Matching orphaned issues to deleted IDs...\n";
$orphans = [3534, 3547, 3561, 3564, 3567, 3570, 3574, 3576, 3577, 3589, 3592, 3596, 3613, 3617, 3620, 3792];
$placeholders = implode(',', array_fill(0, count($orphans), '?'));
$stmt = $db->prepare("SELECT id, issue_key, created_at FROM issues WHERE id IN ($placeholders) ORDER BY created_at ASC");
$stmt->execute($orphans);
$issueData = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($issueData as $i) {
    echo "  - Issue " . $i['issue_key'] . " (ID: $i[id]) created at " . $i['created_at'] . "\n";
}

echo "\n--- Discovery Complete ---\n";
