<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

echo "Starting Project-wise Global Restoration...\n";

// 1. Find the pages currently set as shared (project_id = 0)
$sql = "SELECT * FROM project_pages WHERE project_id = 0";
$sharedPages = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (empty($sharedPages)) {
    echo "No shared global pages (project_id=0) found to redistribute.\n";
}

foreach ($sharedPages as $shared) {
    $sharedId = $shared['id'];
    echo "Processing Shared Page: {$shared['page_number']} ({$shared['page_name']})...\n";

    // Trace projects from linked issues
    $projects = $db->prepare("
        SELECT DISTINCT i.project_id 
        FROM issue_pages ip 
        JOIN issues i ON ip.issue_id = i.id 
        WHERE ip.page_id = ?
    ");
    $projects->execute([$sharedId]);
    $pids = $projects->fetchAll(PDO::FETCH_COLUMN);

    // Trace projects from linked grouped_urls
    $projects2 = $db->prepare("SELECT DISTINCT project_id FROM grouped_urls WHERE unique_page_id = ?");
    $projects2->execute([$sharedId]);
    $pids = array_unique(array_merge($pids, $projects2->fetchAll(PDO::FETCH_COLUMN)));

    if (empty($pids)) {
        echo " - [Note] No linked data found for this page. It will remain project-neutral for now.\n";
        continue;
    }

    foreach ($pids as $index => $pid) {
        if ($pid == 0) continue;

        if ($index === 0) {
            // Re-assign the current master record to the first project found
            echo " - Re-assigning Master ID $sharedId to Project $pid\n";
            $db->prepare("UPDATE project_pages SET project_id = ? WHERE id = ?")->execute([$pid, $sharedId]);
            $currentId = $sharedId;
        } else {
            // Create a clone for the other projects
            echo " - Creating CLONE for Project $pid\n";
            $ins = $db->prepare("INSERT INTO project_pages (project_id, page_name, page_number, url, status, created_by, created_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$pid, $shared['page_name'], $shared['page_number'], $shared['url'], $shared['status'], $shared['created_by'], $shared['created_at'], $shared['notes']]);
            $currentId = $db->lastInsertId();
        }

        // Move issues belonging to this project to the correct page ID
        $db->prepare("
            UPDATE issue_pages ip 
            JOIN issues i ON ip.issue_id = i.id 
            SET ip.page_id = ? 
            WHERE ip.page_id = ? AND i.project_id = ?
        ")->execute([$currentId, $sharedId, $pid]);

        // Move grouped URLs belonging to this project
        $db->prepare("UPDATE grouped_urls SET unique_page_id = ? WHERE unique_page_id = ? AND project_id = ?")
           ->execute([$currentId, $sharedId, $pid]);

        // Clone environments (Best effort: clone ALL environments from the master to each project clone)
        $envs = $db->prepare("SELECT * FROM page_environments WHERE page_id = ?");
        $envs->execute([$sharedId]);
        $rows = $envs->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $env) {
            if ($currentId == $sharedId) continue; // Skip master re-assignments
            $db->prepare("INSERT IGNORE INTO page_environments (page_id, environment_id, at_tester_id, ft_tester_id, qa_id, status, qa_status) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$currentId, $env['environment_id'], $env['at_tester_id'], $env['ft_tester_id'], $env['qa_id'], $env['status'], $env['qa_status']]);
        }
    }
}

echo "Restoration Complete. All 'Global' pages have been redistributed to their respective projects.\n";
