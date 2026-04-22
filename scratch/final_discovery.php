<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

echo "--- FINAL DISCOVERY FOR PROJECT 41 GAPS ---\n\n";

$projectId = 41;

// 1. Get current IDs around the gap
echo "[1] Current Page sequence around ID 8654:\n";
$stmt = $db->prepare("SELECT id, page_name, page_number FROM project_pages WHERE project_id = ? AND id BETWEEN 8600 AND 8700 ORDER BY id ASC");
$stmt->execute([$projectId]);
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($pages as $p) {
    echo "  - ID $p[id]: $p[page_name] ($p[page_number])\n";
}

// 2. Audit Activity Log for ALL page additions in Project 41
echo "\n[2] Auditing all page addition logs for Project 41 (Oldest to Newest):\n";
$stmt = $db->prepare("
    SELECT entity_id, details, created_at, action 
    FROM activity_log 
    WHERE (details LIKE '%\"project_id\":41%' OR details LIKE '%\"project_id\":\"41\"%') 
    AND action IN ('quick_add_page', 'assign_page', 'add_page')
    ORDER BY created_at ASC
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allFoundPageInfo = [];
foreach ($logs as $l) {
    $d = json_decode($l['details'], true);
    $pid = $l['entity_id'] ?: ($d['page_id'] ?? 'unknown');
    $name = $d['page_name'] ?? ($d['title'] ?? ($d['name'] ?? 'Unknown'));
    $url = $d['url'] ?? '';
    
    $allFoundPageInfo[$pid] = ['name' => $name, 'url' => $url, 'added_at' => $l['created_at']];
    echo "  - Page $pid: $name (Added $l[created_at])\n";
}

// 3. Identify which recorded IDs are NOT in current table
echo "\n[3] Identifying recorded pages that are now missing from Project 41:\n";
$currentIds = array_column($pages, 'id');
foreach ($allFoundPageInfo as $id => $info) {
    if (!in_array($id, $currentIds) && $id !== 'unknown') {
        echo "  - MISSING: ID $id: $info[name] ($info[url]) added at $info[added_at]\n";
    }
}

echo "\n--- Discovery Complete ---\n";
