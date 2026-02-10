<?php
// CLI: create_pages_no_assign.php --project=1 --count=3
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

$opts = getopt('', ['project:', 'count:']);
if (PHP_SAPI !== 'cli' || empty($opts['project'])) {
    echo "Usage: php create_pages_no_assign.php --project=1 --count=3\n";
    exit(1);
}

$projectId = (int)$opts['project'];
$count = isset($opts['count']) ? max(1, (int)$opts['count']) : 3;

$db = Database::getInstance();

// fetch available environments
$envs = $db->query("SELECT id, name FROM testing_environments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (empty($envs)) {
    echo "No testing_environments found. Create environments first.\n";
    exit(1);
}

// choose distinct envs (wrap if fewer than count)
$envIds = array_column($envs, 'id');
$selected = [];
for ($i = 0; $i < $count; $i++) {
    $selected[] = $envIds[$i % count($envIds)];
}

echo "Creating $count pages in project $projectId linked to environments: " . implode(',', $selected) . "\n";

$insertPage = $db->prepare("INSERT INTO project_pages (project_id, page_name, url, status, created_by) VALUES (?, ?, ?, ?, ?)");
$insertPE = $db->prepare("INSERT INTO page_environments (page_id, environment_id) VALUES (?, ?)");

for ($i = 0; $i < $count; $i++) {
    $name = 'Auto Page ' . ($i + 1) . ' - ' . date('YmdHis');
    $url = '/auto-page-' . ($i + 1) . '-' . time();
    $status = 'not_started';
    $createdBy = 1; // system/admin (adjust if needed)

    $insertPage->execute([$projectId, $name, $url, $status, $createdBy]);
    $pageId = $db->lastInsertId();

    // link to one environment (no tester assigned)
    $envId = $selected[$i];
    $insertPE->execute([$pageId, $envId]);

    echo "Created page $pageId -> '$name' linked to env $envId\n";
}

echo "Done. Pages created without tester assignments.\n";
exit(0);
