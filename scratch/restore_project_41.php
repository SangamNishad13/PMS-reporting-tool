<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

echo "=== PROJECT 41 RESTORATION SCRIPT ===\n\n";

$projectId = 41;
$lostPageName = "Login";
$lostPageUrl = "Login";
$issueIds = [3534, 3547, 3561, 3564, 3567, 3570, 3574, 3576, 3577, 3589, 3592, 3596, 3613, 3617, 3620, 3792];

try {
    $db->beginTransaction();

    // 1. Create the missing "Login" page
    echo "[1/3] Creating missing 'Login' page...\n";
    $stmt = $db->prepare("
        INSERT INTO project_pages (project_id, page_name, url, page_number)
        VALUES (?, ?, ?, 'Page 1')
    ");
    $stmt->execute([$projectId, $lostPageName, $lostPageUrl]);
    $newPageId = $db->lastInsertId();
    echo "Successfully created 'Login' page with new ID: $newPageId\n";

    // 2. Relink the 16 issues to this new page
    echo "[2/3] Relinking 16 orphaned issues to Page ID $newPageId...\n";
    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $stmt = $db->prepare("UPDATE issues SET page_id = ? WHERE id IN ($placeholders)");
    $stmt->execute(array_merge([$newPageId], $issueIds));
    
    $affected = $stmt->rowCount();
    echo "Successfully relinked $affected issues.\n";

    // 3. Fix numbering for all pages in Project 41
    echo "[3/3] Fixing page numbering sequence...\n";
    $stmt = $db->prepare("SELECT id FROM project_pages WHERE project_id = ? ORDER BY id ASC");
    $stmt->execute([$projectId]);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pages as $index => $page) {
        $pageNum = "Page " . ($index + 1);
        $upd = $db->prepare("UPDATE project_pages SET page_number = ? WHERE id = ?");
        $upd->execute([$pageNum, $page['id']]);
    }
    echo "Fixed numbering for " . count($pages) . " pages.\n";

    $db->commit();
    echo "\n=== RESTORATION COMPLETE ===\n";
    echo "The 'Login' page has been restored and 16 issues have been moved back to it.\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
