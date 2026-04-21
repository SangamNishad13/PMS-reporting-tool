<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$projectId = 24;

echo "--- Project 24 Issues (INTERNAL1-10) ---\n";
$stmt = $db->prepare("SELECT id, issue_key, status_id, client_ready FROM issues WHERE project_id = ? AND issue_key LIKE 'INTERNAL%' LIMIT 10");
$stmt->execute([$projectId]);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($issues as $issue) {
    $statusName = $db->prepare("SELECT name FROM issue_statuses WHERE id = ?");
    $statusName->execute([$issue['status_id']]);
    $name = $statusName->fetchColumn();
    echo "ID: {$issue['id']} | Key: {$issue['issue_key']} | Status: $name (ID: {$issue['status_id']}) | Client Ready: {$issue['client_ready']}\n";
    
    // Check snapshot
    $snap = $db->prepare("SELECT published_at, snapshot_json FROM issue_client_snapshots WHERE issue_id = ?");
    $snap->execute([$issue['id']]);
    $s = $snap->fetch(PDO::FETCH_ASSOC);
    if ($s) {
        $data = json_decode($s['snapshot_json'], true);
        $sName = $db->prepare("SELECT name FROM issue_statuses WHERE id = ?");
        $sName->execute([$data['status_id']]);
        $sn = $sName->fetchColumn();
        echo "  -> SNAPSHOT: Status: $sn (ID: {$data['status_id']}) | Published: {$s['published_at']}\n";
    } else {
        echo "  -> NO SNAPSHOT found.\n";
    }
}

echo "\n--- Client Editable Statuses ---\n";
$clientStatuses = getIssueStatusesForRole($db, 'client');
foreach ($clientStatuses as $cs) {
    echo "Status: {$cs['name']} (ID: {$cs['id']})\n";
}
