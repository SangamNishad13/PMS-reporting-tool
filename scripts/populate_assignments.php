<?php
// populate_assignments.php
// Usage: php populate_assignments.php
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getInstance();

$insertStmt = $pdo->prepare(
    "INSERT INTO assignments (project_id, page_id, environment_id, task_type, assigned_user_id, assigned_role, meta, created_by) VALUES (:project_id, :page_id, :environment_id, :task_type, :assigned_user_id, :assigned_role, :meta, :created_by)"
);

$checkStmt = $pdo->prepare(
    "SELECT 1 FROM assignments WHERE project_id = :project_id AND page_id <=> :page_id AND environment_id <=> :environment_id AND assigned_user_id = :assigned_user_id AND task_type = :task_type AND assigned_role <=> :assigned_role LIMIT 1"
);

try {
    $pdo->beginTransaction();

    echo "Processing project_pages...\n";
    $pages = $pdo->query("SELECT id, project_id, page_name, at_tester_id, ft_tester_id, at_tester_ids, ft_tester_ids FROM project_pages")->fetchAll();
    foreach ($pages as $p) {
        $pageId = $p['id'];
        $projectId = $p['project_id'];

        // scalar at_tester_id
        foreach (['at_tester_id' => 'at_tester', 'ft_tester_id' => 'ft_tester'] as $col => $role) {
            $uid = $p[$col];
            if ($uid !== null && $uid !== '') {
                $checkStmt->execute(['project_id'=>$projectId,'page_id'=>$pageId,'environment_id'=>null,'assigned_user_id'=>$uid,'task_type'=>'page_assignment','assigned_role'=>$role]);
                if (!$checkStmt->fetchColumn()) {
                    $insertStmt->execute(['project_id'=>$projectId,'page_id'=>$pageId,'environment_id'=>null,'task_type'=>'page_assignment','assigned_user_id'=>$uid,'assigned_role'=>$role,'meta'=>null,'created_by'=>null]);
                    echo "Inserted page assignment: page_id={$pageId} user={$uid} role={$role}\n";
                }
            }
        }

        // JSON arrays
        foreach (['at_tester_ids' => 'at_tester', 'ft_tester_ids' => 'ft_tester'] as $col => $role) {
            $val = $p[$col];
            if ($val !== null && trim($val) !== '') {
                $decoded = json_decode($val, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    foreach ($decoded as $uid) {
                        $uid = intval($uid);
                        if ($uid <= 0) continue;
                        $checkStmt->execute(['project_id'=>$projectId,'page_id'=>$pageId,'environment_id'=>null,'assigned_user_id'=>$uid,'task_type'=>'page_assignment','assigned_role'=>$role]);
                        if (!$checkStmt->fetchColumn()) {
                            $insertStmt->execute(['project_id'=>$projectId,'page_id'=>$pageId,'environment_id'=>null,'task_type'=>'page_assignment','assigned_user_id'=>$uid,'assigned_role'=>$role,'meta'=>json_encode(['source'=>$col]),'created_by'=>null]);
                            echo "Inserted page assignment from JSON: page_id={$pageId} user={$uid} role={$role}\n";
                        }
                    }
                } else {
                    // try CSV-like
                    $parts = preg_split('/[,\s]+/', $val);
                    foreach ($parts as $part) {
                        $uid = intval($part);
                        if ($uid <= 0) continue;
                        $checkStmt->execute(['project_id'=>$projectId,'page_id'=>$pageId,'environment_id'=>null,'assigned_user_id'=>$uid,'task_type'=>'page_assignment','assigned_role'=>$role]);
                        if (!$checkStmt->fetchColumn()) {
                            $insertStmt->execute(['project_id'=>$projectId,'page_id'=>$pageId,'environment_id'=>null,'task_type'=>'page_assignment','assigned_user_id'=>$uid,'assigned_role'=>$role,'meta'=>json_encode(['source'=>$col,'raw'=>$val]),'created_by'=>null]);
                            echo "Inserted page assignment from CSV string: page_id={$pageId} user={$uid} role={$role}\n";
                        }
                    }
                }
            }
        }
    }

    echo "Processing page_environments...\n";
    $envs = $pdo->query("SELECT page_id, environment_id, at_tester_id, ft_tester_id FROM page_environments")->fetchAll();
    foreach ($envs as $e) {
        $pageId = $e['page_id'];
        $envId = $e['environment_id'];
        foreach (['at_tester_id'=>'at_tester','ft_tester_id'=>'ft_tester'] as $col=>$role) {
            $uid = $e[$col];
            if ($uid !== null && $uid !== '') {
                // need project_id for this page
                $proj = $pdo->prepare('SELECT project_id FROM project_pages WHERE id = ?');
                $proj->execute([$pageId]);
                $projRow = $proj->fetch();
                $projectId = $projRow ? $projRow['project_id'] : null;
                $checkStmt->execute(['project_id'=>$projectId,'page_id'=>$pageId,'environment_id'=>$envId,'assigned_user_id'=>$uid,'task_type'=>'env_assignment','assigned_role'=>$role]);
                if (!$checkStmt->fetchColumn()) {
                    $insertStmt->execute(['project_id'=>$projectId,'page_id'=>$pageId,'environment_id'=>$envId,'task_type'=>'env_assignment','assigned_user_id'=>$uid,'assigned_role'=>$role,'meta'=>null,'created_by'=>null]);
                    echo "Inserted env assignment: page_id={$pageId} env_id={$envId} user={$uid} role={$role}\n";
                }
            }
        }
    }

    $pdo->commit();
    echo "Population complete.\n";
} catch (Exception $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Failed: " . $ex->getMessage() . "\n";
    exit(1);
}
