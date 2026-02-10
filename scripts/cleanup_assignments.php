<?php
// CLI script to preview and optionally remove unintended page assignments
// Usage: php cleanup_assignments.php --project=1 --user=5 [--apply]

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

$opts = getopt('', ['project:', 'user:', 'apply']);
if (PHP_SAPI !== 'cli' || empty($opts['project']) || empty($opts['user'])) {
    echo "Usage: php cleanup_assignments.php --project=1 --user=5 [--apply]\n";
    exit(1);
}

$projectId = (int)$opts['project'];
$userId = (int)$opts['user'];
$apply = isset($opts['apply']);

$db = Database::getInstance();

echo "Project ID: $projectId\nUser ID: $userId\n";
echo $apply ? "Mode: APPLY (will modify DB)\n" : "Mode: DRY-RUN (no changes)\n";
echo str_repeat('=', 60) . "\n";

// 1) Check project-level assignment
$projAssign = $db->prepare("SELECT * FROM user_assignments WHERE project_id = ? AND user_id = ?");
$projAssign->execute([$projectId, $userId]);
$pa = $projAssign->fetchAll(PDO::FETCH_ASSOC);
echo "Project-level assignments (user_assignments): " . count($pa) . "\n";
foreach ($pa as $r) { echo json_encode($r) . "\n"; }
echo str_repeat('-', 60) . "\n";

// 2) Check project_pages for scalar and JSON fields
$pagesStmt = $db->prepare("SELECT id, page_name, at_tester_id, ft_tester_id, qa_id, at_tester_ids, ft_tester_ids FROM project_pages WHERE project_id = ? ORDER BY id");
$pagesStmt->execute([$projectId]);
$pages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);
echo "Pages in project: " . count($pages) . "\n";
$pagesWithUser = [];
foreach ($pages as $p) {
    $has = false;
    if (!empty($p['at_tester_id']) && (int)$p['at_tester_id'] === $userId) $has = 'at_tester_id';
    if (!empty($p['ft_tester_id']) && (int)$p['ft_tester_id'] === $userId) $has = 'ft_tester_id';
    // JSON arrays (if present)
    if (!$has && !empty($p['at_tester_ids'])) {
        $arr = json_decode($p['at_tester_ids'], true);
        if (is_array($arr) && in_array($userId, array_map('intval', $arr))) $has = 'at_tester_ids';
    }
    if (!$has && !empty($p['ft_tester_ids'])) {
        $arr = json_decode($p['ft_tester_ids'], true);
        if (is_array($arr) && in_array($userId, array_map('intval', $arr))) $has = 'ft_tester_ids';
    }
    if ($has) {
        $pagesWithUser[] = ['page' => $p, 'field' => $has];
        echo "Page {$p['id']} ({$p['page_name']}) assigned via: $has\n";
    }
}
echo str_repeat('-', 60) . "\n";

// 3) Check page_environments rows for user
$peStmt = $db->prepare("SELECT pe.* FROM page_environments pe JOIN project_pages pp ON pe.page_id = pp.id WHERE pp.project_id = ? AND (pe.at_tester_id = ? OR pe.ft_tester_id = ?) ORDER BY pe.page_id");
$peStmt->execute([$projectId, $userId, $userId]);
$peRows = $peStmt->fetchAll(PDO::FETCH_ASSOC);
echo "page_environments rows referencing user: " . count($peRows) . "\n";
foreach ($peRows as $r) { echo json_encode($r) . "\n"; }
echo str_repeat('=', 60) . "\n";

if (!$apply) {
    echo "DRY RUN complete. To remove found assignments run with --apply.\n";
    exit(0);
}

// APPLY changes
// 1) Remove page_environments assignments for this user in project
if (count($peRows) > 0) {
    $ids = array_column($peRows, 'id');
    echo "Removing " . count($ids) . " page_environments rows...\n";
    $delStmt = $db->prepare("DELETE FROM page_environments WHERE id = ?");
    foreach ($ids as $id) { $delStmt->execute([$id]); }
}

// 2) Null scalar columns in project_pages
$updAt = $db->prepare("UPDATE project_pages SET at_tester_id = NULL WHERE project_id = ? AND at_tester_id = ?");
$updFt = $db->prepare("UPDATE project_pages SET ft_tester_id = NULL WHERE project_id = ? AND ft_tester_id = ?");
$updAt->execute([$projectId, $userId]);
$updFt->execute([$projectId, $userId]);
echo "Cleared scalar at_tester_id/ft_tester_id where present. Rows affected: at=" . $updAt->rowCount() . ", ft=" . $updFt->rowCount() . "\n";

// 3) Clean JSON arrays if present
$jsonCleanStmt = $db->prepare("SELECT id, at_tester_ids, ft_tester_ids FROM project_pages WHERE project_id = ?");
$jsonCleanStmt->execute([$projectId]);
$toUpdate = [];
while ($r = $jsonCleanStmt->fetch(PDO::FETCH_ASSOC)) {
    $changed = false;
    $update = [];
    if (!empty($r['at_tester_ids'])) {
        $arr = json_decode($r['at_tester_ids'], true);
        if (is_array($arr)) {
            $new = array_values(array_filter($arr, function($v) use ($userId){ return (int)$v !== $userId; }));
            if (count($new) !== count($arr)) { $changed = true; $update['at_tester_ids'] = empty($new)?null:json_encode($new); }
        }
    }
    if (!empty($r['ft_tester_ids'])) {
        $arr = json_decode($r['ft_tester_ids'], true);
        if (is_array($arr)) {
            $new = array_values(array_filter($arr, function($v) use ($userId){ return (int)$v !== $userId; }));
            if (count($new) !== count($arr)) { $changed = true; $update['ft_tester_ids'] = empty($new)?null:json_encode($new); }
        }
    }
    if ($changed) $toUpdate[$r['id']] = $update;
}

if (!empty($toUpdate)) {
    $updStmt = $db->prepare("UPDATE project_pages SET at_tester_ids = ?, ft_tester_ids = ? WHERE id = ?");
    foreach ($toUpdate as $pid => $cols) {
        $atVal = $cols['at_tester_ids'] ?? null;
        $ftVal = $cols['ft_tester_ids'] ?? null;
        $updStmt->execute([$atVal, $ftVal, $pid]);
        echo "Updated page $pid JSON fields.\n";
    }
}

echo "Apply complete.\n";
exit(0);
