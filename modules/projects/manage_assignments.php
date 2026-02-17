<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/hours_validation.php';

$auth = new Auth();
$auth->requireRole(['admin', 'super_admin', 'project_lead', 'qa']);

$db = Database::getInstance();
$projectManager = new ProjectManager();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$projectId = $_GET['project_id'] ?? ($_POST['project_id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'team';

// If no project selected, show selector
if (!$projectId) {
    if (hasAdminPrivileges()) {
        $projects = $db->query("SELECT id, title FROM projects WHERE status != 'cancelled' ORDER BY title")->fetchAll();
    } elseif ($userRole === 'project_lead') {
        $projects = $db->prepare("SELECT id, title FROM projects WHERE project_lead_id = ? AND status != 'cancelled' ORDER BY title");
        $projects->execute([$userId]);
        $projects = $projects->fetchAll();
    } else { // QA
        $projects = $db->prepare("
            SELECT DISTINCT p.id, p.title 
            FROM projects p
            JOIN user_assignments ua ON p.id = ua.project_id
            WHERE ua.user_id = ? AND ua.role = 'qa' AND p.status != 'cancelled'
        ");
        $projects->execute([$userId]);
        $projects = $projects->fetchAll();
    }
} else {
    // Validate project access
    $accessQuery = "
        SELECT p.*, c.name as client_name 
        FROM projects p 
        LEFT JOIN clients c ON p.client_id = c.id 
        WHERE p.id = ?
    ";
    $stmt = $db->prepare($accessQuery);
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        $_SESSION['error'] = "Project not found or access denied.";
        header("Location: manage_assignments.php");
        exit;
    }

    // Handle Team Assignment (Add/Remove) - ONLY for Lead/Admin
    if (hasProjectLeadPrivileges()) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_team'])) {
            $userIds = $_POST['user_ids'] ?? [];
            $hours = $_POST['hours_allocated'] ?? 0;
            
            if (!is_array($userIds)) $userIds = [$userIds];
            
            foreach ($userIds as $uId) {
                if (empty($uId)) continue;
                $uRoleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                $uRoleStmt->execute([$uId]);
                $uRole = $uRoleStmt->fetchColumn();
                
                if ($uRole) {
                    // Check existing assignment (active or removed)
                    $existingStmt = $db->prepare("SELECT id, is_removed FROM user_assignments WHERE project_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
                    $existingStmt->execute([$projectId, $uId]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing && (int)$existing['is_removed'] === 0) {
                        // already active, skip
                        continue;
                    }

                    // Get project details for notification
                    $projectStmt = $db->prepare("SELECT title, po_number FROM projects WHERE id = ?");
                    $projectStmt->execute([$projectId]);
                    $projectInfo = $projectStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing && (int)$existing['is_removed'] === 1) {
                        // Restore removed assignment
                        $restoreStmt = $db->prepare("UPDATE user_assignments SET is_removed = 0, removed_at = NULL, removed_by = NULL, assigned_by = ?, assigned_at = NOW(), hours_allocated = ? WHERE id = ? AND project_id = ?");
                        $restoreStmt->execute([$userId, $hours, $existing['id'], $projectId]);

                        $notificationMessage = "You have been restored to project: " . ($projectInfo['title'] ?? 'Unknown Project');
                        if (!empty($projectInfo['po_number'])) {
                            $notificationMessage .= " (" . $projectInfo['po_number'] . ")";
                        }
                        if ($hours > 0) {
                            $notificationMessage .= " with " . $hours . " hours allocated";
                        }

                        createNotification(
                            $db,
                            $uId,
                            'assignment',
                            $notificationMessage,
                            getBaseDir() . "/modules/projects/view.php?id=" . $projectId
                        );

                        logActivity($db, $userId, 'restore_team', 'project', $projectId, [
                            'assignment_id' => $existing['id'],
                            'restored_user_id' => $uId,
                            'role' => $uRole,
                            'hours' => $hours
                        ]);
                    } else {
                        // Insert new assignment
                        $insertStmt = $db->prepare("
                            INSERT INTO user_assignments (project_id, user_id, role, assigned_by, hours_allocated)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $insertStmt->execute([$projectId, $uId, $uRole, $userId, $hours]);

                        $notificationMessage = "You have been assigned to project: " . ($projectInfo['title'] ?? 'Unknown Project');
                        if (!empty($projectInfo['po_number'])) {
                            $notificationMessage .= " (" . $projectInfo['po_number'] . ")";
                        }
                        if ($hours > 0) {
                            $notificationMessage .= " with " . $hours . " hours allocated";
                        }

                        createNotification(
                            $db,
                            $uId,
                            'assignment',
                            $notificationMessage,
                            getBaseDir() . "/modules/projects/view.php?id=" . $projectId
                        );

                        // Log Activity
                        logActivity($db, $userId, 'assign_team', 'project', $projectId, [
                            'assigned_user_id' => $uId,
                            'role' => $uRole,
                            'hours' => $hours
                        ]);
                    }
                }
            }
            $_SESSION['success'] = "Team members assigned.";
            header("Location: manage_assignments.php?project_id=$projectId&tab=team");
            exit;
        }

        if (isset($_GET['remove_member'])) {
            $removeId = $_GET['remove_member'];
            
            // Get user_id before removing
            $userStmt = $db->prepare("SELECT user_id FROM user_assignments WHERE id = ?");
            $userStmt->execute([$removeId]);
            $removedUserId = $userStmt->fetchColumn();
            $removedUserRole = null;
            if ($removedUserId) {
                $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                $roleStmt->execute([$removedUserId]);
                $removedUserRole = $roleStmt->fetchColumn();
            }
            
            // Soft delete - mark as removed instead of hard delete
            $updateStmt = $db->prepare("UPDATE user_assignments SET is_removed = 1, removed_at = NOW(), removed_by = ? WHERE id = ? AND project_id = ?");
            $updateStmt->execute([$userId, $removeId, $projectId]);

            // Remove page-level assignments so removed user no longer appears in active projects list
            if ($removedUserId && $removedUserRole) {
                if ($removedUserRole === 'at_tester') {
                    $updPages = $db->prepare("UPDATE project_pages SET at_tester_id = NULL WHERE project_id = ? AND at_tester_id = ?");
                    $updPages->execute([$projectId, $removedUserId]);
                    $updEnvs = $db->prepare("UPDATE page_environments pe JOIN project_pages pp ON pp.id = pe.page_id SET pe.at_tester_id = NULL WHERE pp.project_id = ? AND pe.at_tester_id = ?");
                    $updEnvs->execute([$projectId, $removedUserId]);
                } elseif ($removedUserRole === 'ft_tester') {
                    $updPages = $db->prepare("UPDATE project_pages SET ft_tester_id = NULL WHERE project_id = ? AND ft_tester_id = ?");
                    $updPages->execute([$projectId, $removedUserId]);
                    $updEnvs = $db->prepare("UPDATE page_environments pe JOIN project_pages pp ON pp.id = pe.page_id SET pe.ft_tester_id = NULL WHERE pp.project_id = ? AND pe.ft_tester_id = ?");
                    $updEnvs->execute([$projectId, $removedUserId]);
                } elseif ($removedUserRole === 'qa') {
                    $updPages = $db->prepare("UPDATE project_pages SET qa_id = NULL WHERE project_id = ? AND qa_id = ?");
                    $updPages->execute([$projectId, $removedUserId]);
                    $updEnvs = $db->prepare("UPDATE page_environments pe JOIN project_pages pp ON pp.id = pe.page_id SET pe.qa_id = NULL WHERE pp.project_id = ? AND pe.qa_id = ?");
                    $updEnvs->execute([$projectId, $removedUserId]);
                }
            }
            
            // Get project details for notification
            if ($removedUserId) {
                $projectStmt = $db->prepare("SELECT title, po_number FROM projects WHERE id = ?");
                $projectStmt->execute([$projectId]);
                $projectInfo = $projectStmt->fetch(PDO::FETCH_ASSOC);
                
                // Create notification for removed user
                $notificationMessage = "You have been removed from project: " . ($projectInfo['title'] ?? 'Unknown Project');
                if (!empty($projectInfo['po_number'])) {
                    $notificationMessage .= " (" . $projectInfo['po_number'] . ")";
                }
                
                createNotification(
                    $db, 
                    $removedUserId, 
                    'system', 
                    $notificationMessage,
                    null // No link since they're removed
                );
            }
            
            // Log Activity
            logActivity($db, $userId, 'remove_team', 'project', $projectId, [
                'assignment_id' => $removeId,
                'removed_user_id' => $removedUserId
            ]);
            
            $_SESSION['success'] = "Team member removed from project.";
            header("Location: manage_assignments.php?project_id=$projectId&tab=team");
            exit;
        }

        // Handle restore member
        if (isset($_GET['restore_member'])) {
            $restoreId = $_GET['restore_member'];
            
            // Get user_id before restoring
            $userStmt = $db->prepare("SELECT user_id FROM user_assignments WHERE id = ?");
            $userStmt->execute([$restoreId]);
            $restoredUserId = $userStmt->fetchColumn();
            
            // Restore member - mark as active again
            $updateStmt = $db->prepare("UPDATE user_assignments SET is_removed = 0, removed_at = NULL, removed_by = NULL WHERE id = ? AND project_id = ?");
            $updateStmt->execute([$restoreId, $projectId]);
            
            // Get project details for notification
            if ($restoredUserId) {
                $projectStmt = $db->prepare("SELECT title, po_number FROM projects WHERE id = ?");
                $projectStmt->execute([$projectId]);
                $projectInfo = $projectStmt->fetch(PDO::FETCH_ASSOC);
                
                // Create notification for restored user
                $notificationMessage = "You have been restored to project: " . ($projectInfo['title'] ?? 'Unknown Project');
                if (!empty($projectInfo['po_number'])) {
                    $notificationMessage .= " (" . $projectInfo['po_number'] . ")";
                }
                
                createNotification(
                    $db, 
                    $restoredUserId, 
                    'assignment', 
                    $notificationMessage,
                    getBaseDir() . "/modules/projects/view.php?id=" . $projectId
                );
            }
            
            // Log Activity
            logActivity($db, $userId, 'restore_team', 'project', $projectId, [
                'assignment_id' => $restoreId
            ]);
            
            $_SESSION['success'] = "Team member restored to project.";
            header("Location: manage_assignments.php?project_id=$projectId&tab=team");
            exit;
        }

        // Handle Page Deletion (Lead/Admin)
        if (isset($_GET['remove_page'])) {
            $removePageId = (int)$_GET['remove_page'];

            // Start transaction for complete cleanup
            $db->beginTransaction();
            try {
                // Remove all related data first
                
                // Remove issue_pages entries for this page
                $delIssuePagesStmt = $db->prepare("DELETE FROM issue_pages WHERE page_id = ?");
                $delIssuePagesStmt->execute([$removePageId]);
                
                // Delete issues that are ONLY linked to this page (no other pages)
                // First, find issues that were only linked to this deleted page
                $orphanedIssuesStmt = $db->prepare("
                    SELECT i.id 
                    FROM issues i
                    WHERE i.page_id = ? 
                      AND NOT EXISTS (
                          SELECT 1 FROM issue_pages ip 
                          WHERE ip.issue_id = i.id AND ip.page_id != ?
                      )
                ");
                $orphanedIssuesStmt->execute([$removePageId, $removePageId]);
                $orphanedIssueIds = $orphanedIssuesStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete orphaned issues and their related data
                if (!empty($orphanedIssueIds)) {
                    $placeholders = implode(',', array_fill(0, count($orphanedIssueIds), '?'));
                    
                    // Delete issue metadata
                    $delIssueMeta = $db->prepare("DELETE FROM issue_metadata WHERE issue_id IN ($placeholders)");
                    $delIssueMeta->execute($orphanedIssueIds);
                    
                    // Delete issue comments
                    $delIssueComments = $db->prepare("DELETE FROM issue_comments WHERE issue_id IN ($placeholders)");
                    $delIssueComments->execute($orphanedIssueIds);
                    
                    // Delete issue history
                    $delIssueHistory = $db->prepare("DELETE FROM issue_history WHERE issue_id IN ($placeholders)");
                    $delIssueHistory->execute($orphanedIssueIds);
                    
                    // Delete the issues themselves
                    $delIssues = $db->prepare("DELETE FROM issues WHERE id IN ($placeholders)");
                    $delIssues->execute($orphanedIssueIds);
                }
                
                // Remove page environments
                $delEnv = $db->prepare("DELETE FROM page_environments WHERE page_id = ?");
                $delEnv->execute([$removePageId]);

                // Remove testing results
                $delTestResults = $db->prepare("DELETE FROM testing_results WHERE page_id = ?");
                $delTestResults->execute([$removePageId]);

                // Remove QA results
                $delQaResults = $db->prepare("DELETE FROM qa_results WHERE page_id = ?");
                $delQaResults->execute([$removePageId]);

                // Remove assignments related to this page
                $delAssignments = $db->prepare("DELETE FROM assignments WHERE page_id = ?");
                $delAssignments->execute([$removePageId]);

                // Remove any grouped URLs that reference this page
                $delGroupedUrls = $db->prepare("DELETE FROM grouped_urls WHERE project_id = ? AND url = (SELECT url FROM project_pages WHERE id = ?)");
                $delGroupedUrls->execute([$projectId, $removePageId]);

                // Finally remove the page record (ensure it belongs to this project)
                $delPage = $db->prepare("DELETE FROM project_pages WHERE id = ? AND project_id = ?");
                $delPage->execute([$removePageId, $projectId]);

                // Check if this was the last page in the project
                $remainingPagesStmt = $db->prepare("SELECT COUNT(*) as count FROM project_pages WHERE project_id = ?");
                $remainingPagesStmt->execute([$projectId]);
                $remainingCount = $remainingPagesStmt->fetch(PDO::FETCH_ASSOC)['count'];

                $db->commit();

                // Log Activity
                logActivity($db, $userId, 'remove_page', 'project', $projectId, [
                    'page_id' => $removePageId,
                    'remaining_pages' => $remainingCount
                ]);

                if ($remainingCount == 0) {
                    $_SESSION['success'] = "Page and all related data removed successfully. Next page added will start from 'Page 1'.";
                } else {
                    $_SESSION['success'] = "Page and all related data removed successfully.";
                }
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = "Error removing page: " . $e->getMessage();
            }

            header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
            exit;
        }
    }

    // Handle Page Assignment - Leads and QA
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_page'])) {
        $pageId = (int)$_POST['page_id'];
        $returnTo = trim($_POST['return_to'] ?? '');
        // Page-level defaults (may be left empty)
        $atTesterId = isset($_POST['at_tester_id']) && $_POST['at_tester_id'] !== '' ? (int)$_POST['at_tester_id'] : null;
        $ftTesterId = isset($_POST['ft_tester_id']) && $_POST['ft_tester_id'] !== '' ? (int)$_POST['ft_tester_id'] : null;
        $qaId = isset($_POST['qa_id']) && $_POST['qa_id'] !== '' ? (int)$_POST['qa_id'] : null;

        // envs[] contains the environment ids that should be linked to this page
        $selectedEnvs = $_POST['envs'] ?? [];

        // Save page-level columns on project_pages table
        $upd = $db->prepare("UPDATE project_pages SET at_tester_id = ?, ft_tester_id = ?, qa_id = ? WHERE id = ?");
        $upd->execute([$atTesterId, $ftTesterId, $qaId, $pageId]);

        // Handle per-environment assignments: iterate all known environments
        $allEnvStmt = $db->query("SELECT id FROM testing_environments")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allEnvStmt as $envId) {
            $envId = (int)$envId;
            $checked = in_array($envId, $selectedEnvs);
            if ($checked) {
                // read per-env tester selections
                $atEnv = isset($_POST['at_tester_env_' . $envId]) && $_POST['at_tester_env_' . $envId] !== '' ? (int)$_POST['at_tester_env_' . $envId] : null;
                $ftEnv = isset($_POST['ft_tester_env_' . $envId]) && $_POST['ft_tester_env_' . $envId] !== '' ? (int)$_POST['ft_tester_env_' . $envId] : null;
                $qaEnv = isset($_POST['qa_env_' . $envId]) && $_POST['qa_env_' . $envId] !== '' ? (int)$_POST['qa_env_' . $envId] : null;

                $stmt = $db->prepare("INSERT INTO page_environments (page_id, environment_id, at_tester_id, ft_tester_id, qa_id) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE at_tester_id = VALUES(at_tester_id), ft_tester_id = VALUES(ft_tester_id), qa_id = VALUES(qa_id)");
                $stmt->execute([$pageId, $envId, $atEnv, $ftEnv, $qaEnv]);
            } else {
                // remove link if exists
                $del = $db->prepare("DELETE FROM page_environments WHERE page_id = ? AND environment_id = ?");
                $del->execute([$pageId, $envId]);
            }
        }

        // Log Activity
        logActivity($db, $userId, 'assign_page', 'page', $pageId, [
            'at_tester' => $atTesterId,
            'ft_tester' => $ftTesterId,
            'qa' => $qaId,
            'environments' => $selectedEnvs
        ]);

        $_SESSION['success'] = "Page assignment updated.";
        if (!empty($returnTo)) {
            $sep = (strpos($returnTo, '?') === false) ? '?' : '&';
            header("Location: {$returnTo}{$sep}tab=pages&subtab=unique_pages_sub&focus_assign_btn=$pageId");
        } else {
            header("Location: manage_assignments.php?project_id=$projectId&tab=pages&focus_page_id=$pageId");
        }
        exit;
    }

    // Handle Unique Page Assignment - create/update project pages for grouped URLs
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_unique'])) {
        $uniqueId = (int)($_POST['unique_id'] ?? 0);
        $atTesterId = isset($_POST['at_tester_id']) && $_POST['at_tester_id'] !== '' ? (int)$_POST['at_tester_id'] : null;
        $ftTesterId = isset($_POST['ft_tester_id']) && $_POST['ft_tester_id'] !== '' ? (int)$_POST['ft_tester_id'] : null;
        $qaId = isset($_POST['qa_id']) && $_POST['qa_id'] !== '' ? (int)$_POST['qa_id'] : null;
        $selectedEnvs = $_POST['envs'] ?? [];

        if ($uniqueId) {
            // fetch grouped urls for this unique
            $gStmt = $db->prepare('SELECT * FROM grouped_urls WHERE project_id = ? AND unique_page_id = ?');
            $gStmt->execute([$projectId, $uniqueId]);
            $grouped = $gStmt->fetchAll(PDO::FETCH_ASSOC);

            $created = 0;
            foreach ($grouped as $g) {
                $url = $g['url'];
                // find or create a project_page for this url
                $find = $db->prepare('SELECT id FROM project_pages WHERE project_id = ? AND url = ? LIMIT 1');
                $find->execute([$projectId, $url]);
                $p = $find->fetch(PDO::FETCH_ASSOC);
                if ($p) {
                    $pageId = (int)$p['id'];
                } else {
                    // create page named after unique or url
                    $uStmt = $db->prepare('SELECT name FROM unique_pages WHERE id = ? LIMIT 1');
                    $uStmt->execute([$uniqueId]);
                    $urow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    $pname = $urow['name'] ?: substr($url, 0, 80);
                    $ins = $db->prepare('INSERT INTO project_pages (project_id, page_name, url, created_by, created_at) VALUES (?, ?, ?, ?, NOW())');
                    $ins->execute([$projectId, $pname, $url, $userId]);
                    $pageId = (int)$db->lastInsertId();
                    $created++;
                }

                // update page-level testers
                $upd = $db->prepare('UPDATE project_pages SET at_tester_id = ?, ft_tester_id = ?, qa_id = ? WHERE id = ?');
                $upd->execute([$atTesterId, $ftTesterId, $qaId, $pageId]);

                // apply environment assignments
                $allEnvStmt = $db->query("SELECT id FROM testing_environments")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($allEnvStmt as $envId) {
                    $envId = (int)$envId;
                    $checked = in_array($envId, $selectedEnvs);
                    if ($checked) {
                        $atEnv = $atTesterId ?: null;
                        $ftEnv = $ftTesterId ?: null;
                        $qaEnv = $qaId ?: null;
                        $stmt = $db->prepare("INSERT INTO page_environments (page_id, environment_id, at_tester_id, ft_tester_id, qa_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE at_tester_id = VALUES(at_tester_id), ft_tester_id = VALUES(ft_tester_id), qa_id = VALUES(qa_id)");
                        $stmt->execute([$pageId, $envId, $atEnv, $ftEnv, $qaEnv]);
                    }
                }
            }

            logActivity($db, $userId, 'assign_unique', 'project', $projectId, ['unique_id' => $uniqueId, 'created_pages' => $created]);
            $_SESSION['success'] = "Unique assignment applied (created $created pages).";
        } else {
            $_SESSION['error'] = 'Unique page not specified.';
        }
        header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
        exit;
    }

    // Handle Quick Add Page
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_add_page'])) {
        $pageName = trim($_POST['page_name']);
        $url = trim($_POST['url'] ?? '');
        $screenName = trim($_POST['screen_name'] ?? '');
        
        if (!empty($pageName)) {
            // Generate the next page number using the same logic as the API
            $maxStmt = $db->prepare("SELECT MAX(CAST(REPLACE(page_number, 'Page ', '') AS UNSIGNED)) as maxn FROM project_pages WHERE project_id = ? AND page_number LIKE 'Page %'");
            $maxStmt->execute([$projectId]);
            $maxRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
            $nextN = (int)($maxRow['maxn'] ?? 0) + 1;
            $pageNumber = 'Page ' . $nextN;
            
            $stmt = $db->prepare("INSERT INTO project_pages (project_id, page_name, page_number, url, screen_name, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$projectId, $pageName, $pageNumber, $url, $screenName, $userId]);
            $newPageId = $db->lastInsertId();
            
            // Log Activity
            logActivity($db, $userId, 'quick_add_page', 'page', $newPageId, [
                'project_id' => $projectId,
                'page_name' => $pageName,
                'page_number' => $pageNumber
            ]);
            
            $_SESSION['success'] = "Page '$pageName' added successfully as $pageNumber.";
        } else {
            $_SESSION['error'] = "Page Name is required.";
        }
        header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
        exit;
    }

    // Handle Bulk Assignment
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_assign'])) {
        $selectedPages = $_POST['selected_pages'] ?? [];
        $bulkAtTester = $_POST['bulk_at_tester'] ?? '';
        $bulkFtTester = $_POST['bulk_ft_tester'] ?? '';
        $bulkQa = $_POST['bulk_qa'] ?? '';
        $selectedEnvs = $_POST['bulk_envs'] ?? [];
        
        if (!empty($selectedPages) && !empty($selectedEnvs)) {
            $successCount = 0;
            
            foreach ($selectedPages as $pageId) {
                // Update page-level assignments if provided
                if ($bulkAtTester || $bulkFtTester || $bulkQa) {
                    $stmt = $db->prepare("UPDATE project_pages SET at_tester_id = ?, ft_tester_id = ?, qa_id = ? WHERE id = ?");
                    $stmt->execute([
                        $bulkAtTester ?: null,
                        $bulkFtTester ?: null, 
                        $bulkQa ?: null,
                        $pageId
                    ]);
                }
                
                // Handle environment assignments
                foreach ($selectedEnvs as $envId) {
                    $stmt = $db->prepare("INSERT INTO page_environments (page_id, environment_id, at_tester_id, ft_tester_id, qa_id) VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE at_tester_id = VALUES(at_tester_id), ft_tester_id = VALUES(ft_tester_id), qa_id = VALUES(qa_id)");
                    $stmt->execute([
                        $pageId, 
                        $envId, 
                        $bulkAtTester ?: null, 
                        $bulkFtTester ?: null, 
                        $bulkQa ?: null
                    ]);
                }
                $successCount++;
            }
            
            // Log Activity
            logActivity($db, $userId, 'bulk_assign', 'project', $projectId, [
                'pages_count' => $successCount,
                'environments' => $selectedEnvs,
                'at_tester' => $bulkAtTester,
                'ft_tester' => $bulkFtTester,
                'qa' => $bulkQa
            ]);
            
            $_SESSION['success'] = "Bulk assignment completed for $successCount pages.";
        } else {
            $_SESSION['error'] = "Please select at least one page and one environment.";
        }
        
        header("Location: manage_assignments.php?project_id=$projectId&tab=bulk");
        exit;
    }

    // Handle Quick Assign All
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_assign_all'])) {
        $quickAtTester = $_POST['quick_at_tester'] ?? '';
        $quickFtTester = $_POST['quick_ft_tester'] ?? '';
        $quickQa = $_POST['quick_qa'] ?? '';
        $quickEnvs = $_POST['quick_envs'] ?? [];
        
        if ($quickAtTester || $quickFtTester || $quickQa || !empty($quickEnvs)) {
            $affectedPages = 0;
            
            // Update page-level assignments if testers/QA are selected
            if ($quickAtTester || $quickFtTester || $quickQa) {
                $updateFields = [];
                $updateValues = [];
                
                if ($quickAtTester) {
                    $updateFields[] = "at_tester_id = ?";
                    $updateValues[] = $quickAtTester;
                }
                if ($quickFtTester) {
                    $updateFields[] = "ft_tester_id = ?";
                    $updateValues[] = $quickFtTester;
                }
                if ($quickQa) {
                    $updateFields[] = "qa_id = ?";
                    $updateValues[] = $quickQa;
                }
                
                if (!empty($updateFields)) {
                    $updateValues[] = $projectId;
                    $sql = "UPDATE project_pages SET " . implode(', ', $updateFields) . " WHERE project_id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($updateValues);
                    $affectedPages = $stmt->rowCount();
                }
            }
            
            // Handle environment assignments if environments are selected
            if (!empty($quickEnvs)) {
                // Get all pages in the project
                $pagesStmt = $db->prepare("SELECT id FROM project_pages WHERE project_id = ?");
                $pagesStmt->execute([$projectId]);
                $allPages = $pagesStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!$affectedPages) {
                    $affectedPages = count($allPages);
                }
                
                foreach ($allPages as $pageId) {
                    foreach ($quickEnvs as $envId) {
                        $stmt = $db->prepare("INSERT INTO page_environments (page_id, environment_id, at_tester_id, ft_tester_id, qa_id) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE at_tester_id = VALUES(at_tester_id), ft_tester_id = VALUES(ft_tester_id), qa_id = VALUES(qa_id)");
                        $stmt->execute([
                            $pageId, 
                            $envId, 
                            $quickAtTester ?: null, 
                            $quickFtTester ?: null, 
                            $quickQa ?: null
                        ]);
                    }
                }
            }
            
            // Log Activity
            logActivity($db, $userId, 'quick_assign_all', 'project', $projectId, [
                'pages_affected' => $affectedPages,
                'environments_count' => count($quickEnvs),
                'at_tester' => $quickAtTester,
                'ft_tester' => $quickFtTester,
                'qa' => $quickQa
            ]);
            
            $envText = !empty($quickEnvs) ? " and " . count($quickEnvs) . " environments" : "";
            $_SESSION['success'] = "Quick assignment completed for $affectedPages pages$envText.";
        } else {
            $_SESSION['error'] = "Please select at least one tester, QA, or environment to assign.";
        }
        
        header("Location: manage_assignments.php?project_id=$projectId&tab=pages");
        exit;
    }

    // Data for Tabs - Active team members only
    $team = $db->prepare("
        SELECT ua.*, u.full_name, u.email, u.role as user_role 
        FROM user_assignments ua
        JOIN users u ON ua.user_id = u.id
        WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
        UNION
        SELECT NULL as id, NULL as project_id, p.project_lead_id as user_id, 'project_lead' as role, 
               NULL as assigned_by, NULL as assigned_at, NULL as hours_allocated,
               NULL as is_removed, NULL as removed_at, NULL as removed_by,
               pl.full_name, pl.email, pl.role as user_role
        FROM projects p
        JOIN users pl ON p.project_lead_id = pl.id
        WHERE p.id = ? AND p.project_lead_id IS NOT NULL
        AND p.project_lead_id NOT IN (SELECT user_id FROM user_assignments WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0))
        ORDER BY 
            CASE role 
                WHEN 'project_lead' THEN 1
                WHEN 'qa' THEN 2
                WHEN 'at_tester' THEN 3
                WHEN 'ft_tester' THEN 4
            END, full_name
    ");
    $team->execute([$projectId, $projectId, $projectId]);
    $teamMembers = $team->fetchAll();

    // Get removed team members
    $removedTeam = $db->prepare("
        SELECT ua.*, u.full_name, u.email, u.role as user_role, ru.full_name as removed_by_name
        FROM user_assignments ua
        JOIN users u ON ua.user_id = u.id
        LEFT JOIN users ru ON ua.removed_by = ru.id
        WHERE ua.project_id = ? AND ua.is_removed = 1
        AND ua.user_id NOT IN (
            SELECT user_id 
            FROM user_assignments 
            WHERE project_id = ? 
            AND (is_removed IS NULL OR is_removed = 0)
        )
        ORDER BY ua.removed_at DESC
    ");
    $removedTeam->execute([$projectId, $projectId]);
    $removedMembers = $removedTeam->fetchAll();

    // project_pages table data is no longer used here (delete/ignore)

    // Fetch pages for this project (now using only project_pages table)
    $pagesStmt = $db->prepare("SELECT id, page_name, url, screen_name, at_tester_id, ft_tester_id, qa_id FROM project_pages WHERE project_id = ? ORDER BY id ASC");
    $pagesStmt->execute([$projectId]);
    $projectPages = $pagesStmt->fetchAll();

    // Get only users who are assigned to this project team (active members only)
    // This is used for page assignment dropdowns
    $availableUsers = $db->prepare("
        SELECT DISTINCT u.id, u.full_name, u.role 
        FROM users u
        JOIN user_assignments ua ON u.id = ua.user_id
        WHERE ua.project_id = ? 
        AND u.role IN ('qa', 'at_tester', 'ft_tester') 
        AND u.is_active = 1 
        AND (ua.is_removed IS NULL OR ua.is_removed = 0)
        ORDER BY u.full_name
    ");
    $availableUsers->execute([$projectId]);
    $availableUsers = $availableUsers->fetchAll();
    
    // Get ALL active users for "Add Team Member" dropdown (excluding already assigned team members)
    $allAvailableUsers = $db->prepare("
        SELECT DISTINCT u.id, u.full_name, u.role 
        FROM users u
        WHERE u.role IN ('qa', 'at_tester', 'ft_tester') 
        AND u.is_active = 1
        AND u.id NOT IN (
            SELECT user_id 
            FROM user_assignments 
            WHERE project_id = ? 
            AND (is_removed IS NULL OR is_removed = 0)
        )
        ORDER BY u.full_name
    ");
    $allAvailableUsers->execute([$projectId]);
    $allAvailableUsers = $allAvailableUsers->fetchAll();
    
    $allEnvironments = $db->query("SELECT * FROM testing_environments ORDER BY name")->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-tasks text-primary"></i> Assignment Center</h2>
        <?php if ($projectId): ?>
            <a href="manage_assignments.php" class="btn btn-outline-secondary">
                <i class="fas fa-exchange-alt"></i> Change Project
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$projectId): ?>
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Select Project to Manage</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET">
                            <div class="mb-3">
                                <label class="form-label">Project</label>
                                <select name="project_id" class="form-select form-select-lg" onchange="this.form.submit()">
                                    <option value="">-- Select Project --</option>
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Project Info Header -->
        <div class="card mb-4 border-start border-4 border-primary">
            <div class="card-body py-2">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h4 class="mb-0"><?php echo htmlspecialchars($project['title']); ?></h4>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($project['client_name']); ?> | Project Code: <?php echo $project['po_number']; ?></p>
                    </div>
                    <div class="col-md-3">
                        <?php
                        // Get project hours summary
                        $hoursSummary = getProjectHoursSummary($db, $projectId);
                        $totalHours = $hoursSummary['total_hours'] ?: 0;
                        $allocatedHours = $hoursSummary['allocated_hours'] ?: 0;
                        $utilizedHours = $hoursSummary['utilized_hours'] ?: 0;
                        $remainingHours = $totalHours - $utilizedHours;
                        $availableForAllocation = max(0, $totalHours - $allocatedHours);
                        ?>
                        <div class="text-center">
                        <div class="text-center">
                            <h6 class="mb-1 text-primary">Project Hours</h6>
                            <div class="d-flex justify-content-center gap-3">
                                <?php 
                                $remainingHours = $totalHours - $utilizedHours;
                                $isOvershoot = $remainingHours < 0;
                                ?>
                                <div class="text-center">
                                    <div class="fw-bold text-primary"><?php echo $totalHours; ?></div>
                                    <small class="text-muted">Total Hours</small>
                                </div>
                                <div class="text-center">
                                    <div class="fw-bold <?php echo $isOvershoot ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $utilizedHours; ?>
                                    </div>
                                    <small class="text-muted">Used Hours</small>
                                </div>
                                <div class="text-center">
                                    <div class="fw-bold <?php echo $isOvershoot ? 'text-danger' : 'text-warning'; ?>">
                                        <?php echo $isOvershoot ? abs($remainingHours) : $remainingHours; ?>
                                    </div>
                                    <small class="text-muted"><?php echo $isOvershoot ? 'Overshoot' : 'Remaining'; ?></small>
                                </div>
                            </div>
                            <?php if ($totalHours > 0): ?>
                            <div class="progress mt-2" style="height: 8px;">
                                <?php if ($isOvershoot): ?>
                                    <!-- Green bar for total hours (100% of container) -->
                                    <div class="progress-bar bg-success" style="width: 100%;" title="Budget: <?php echo $totalHours; ?> hours"></div>
                                    <!-- Red bar for overshoot hours (extends beyond 100%) -->
                                    <div class="progress-bar bg-danger" style="width: <?php echo (abs($remainingHours) / $totalHours) * 100; ?>%;" title="Overshoot: <?php echo abs($remainingHours); ?> hours"></div>
                                <?php else: ?>
                                    <!-- Normal green bar for used hours within budget -->
                                    <div class="progress-bar bg-success" style="width: <?php echo ($utilizedHours / $totalHours) * 100; ?>%;" title="Used: <?php echo $utilizedHours; ?> hours"></div>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">
                                <?php echo round(($utilizedHours / $totalHours) * 100, 1); ?>% used
                                <?php if ($isOvershoot): ?>
                                    <span class="text-danger">(<?php echo abs($remainingHours); ?> hours over budget!)</span>
                                <?php endif; ?>
                            </small>
                            <?php else: ?>
                            <small class="text-muted">No total hours set for this project</small>
                            <?php endif; ?>
                        </div>
                        </div>
                    </div>
                    <div class="col-md-3 text-end">
                        <span class="badge bg-info text-dark">
                            <?php echo ucfirst($project['project_type']); ?>
                        </span>
                        <div class="mt-2">
                            <a href="view.php?id=<?php echo (int)$projectId; ?>" class="btn btn-sm btn-outline-primary" title="Open Project View">Open Project</a>
                        </div>
                        </span>
                        <span class="badge bg-secondary"><?php echo formatProjectStatusLabel($project['status']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'team' ? 'active' : ''; ?>" id="pills-team-tab" data-bs-toggle="pill" data-bs-target="#pills-team" type="button">
                    <i class="fas fa-users"></i> Project Team
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'pages' ? 'active' : ''; ?>" id="pills-pages-tab" data-bs-toggle="pill" data-bs-target="#pills-pages" type="button">
                    <i class="fas fa-file-alt"></i> Page Assignments
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'bulk' ? 'active' : ''; ?>" id="pills-bulk-tab" data-bs-toggle="pill" data-bs-target="#pills-bulk" type="button">
                    <i class="fas fa-magic"></i> Bulk Assignment
                </button>
            </li>
        </ul>

        <div class="tab-content" id="pills-tabContent">
            <!-- TAB 1: TEAM STAFFING -->
            <div class="tab-pane fade <?php echo $activeTab === 'team' ? 'show active' : ''; ?>" id="pills-team">
                <div class="row">
                    <?php if (hasProjectLeadPrivileges()): ?>
                    <div class="col-md-4">
                        <div class="card" style="top: 20px;">
                            <div class="card-header">
                                <h5 class="mb-0">Add Team Member</h5>
                                <small class="text-muted">
                                    <?php if ($remainingHours >= 0): ?>
                                        Remaining Budget: <strong class="text-success"><?php echo $remainingHours; ?></strong> hours
                                        of <strong class="text-primary"><?php echo $totalHours; ?></strong> total
                                    <?php else: ?>
                                        Over Budget: <strong class="text-danger"><?php echo abs($remainingHours); ?></strong> hours
                                        (Total: <strong class="text-primary"><?php echo $totalHours; ?></strong>)
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="card-body">
                                <?php if ($totalHours <= 0): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    Project total hours not set. Please set project total hours first.
                                </div>
                                <?php elseif ($availableForAllocation <= 0): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-circle"></i> 
                                    No hours available for allocation. All project hours have been allocated.
                                    <br><small>Allocated: <?php echo $allocatedHours; ?> hours | Total Budget: <?php echo $totalHours; ?> hours</small>
                                </div>
                                <?php endif; ?>
                                
                                <form method="POST" id="teamAssignForm">
                                    <div class="mb-3">
                                        <label class="form-label">Select Users</label>
                                        <select name="user_ids[]" class="form-select" multiple size="10" required>
                                            <?php foreach ($allAvailableUsers as $au): ?>
                                                <option value="<?php echo $au['id']; ?>">
                                                    <?php echo htmlspecialchars($au['full_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $au['role'])); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Hold Ctrl to select multiple</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Allocated Hours</label>
                                        <input type="number" name="hours_allocated" class="form-control" value="0" step="0.5" max="<?php echo $availableForAllocation; ?>" id="hoursInput">
                                        <small class="text-muted">
                                            Max: <?php echo $availableForAllocation; ?> hours available for allocation
                                        </small>
                                        <div id="hoursValidation" class="mt-1"></div>
                                    </div>
                                    <button type="submit" name="assign_team" class="btn btn-primary w-100" <?php echo ($totalHours <= 0 || $availableForAllocation <= 0) ? 'disabled' : ''; ?>>
                                        Add to Project
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="<?php echo hasProjectLeadPrivileges() ? 'col-md-8' : 'col-md-12'; ?>">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Current Project Team</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Role</th>
                                                <th>Allocated Hours</th>
                                                <th>Utilized Hours</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($teamMembers as $m): 
                                                // Get utilized hours for this team member
                                                $utilizedStmt = $db->prepare("
                                                    SELECT COALESCE(SUM(hours_spent), 0) as utilized_hours 
                                                    FROM project_time_logs 
                                                    WHERE project_id = ? AND user_id = ? AND is_utilized = 1
                                                ");
                                                $utilizedStmt->execute([$projectId, $m['user_id']]);
                                                $memberUtilized = $utilizedStmt->fetchColumn() ?: 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo renderUserNameLink(['id' => $m['user_id'], 'full_name' => $m['full_name'], 'role' => $m['user_role']]); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($m['email']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $m['role'] === 'project_lead' ? 'warning' : 
                                                             ($m['role'] === 'qa' ? 'info' : 'primary');
                                                    ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $m['role'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($m['hours_allocated']) && $m['hours_allocated'] > 0): ?>
                                                        <span class="fw-bold text-primary"><?php echo $m['hours_allocated']; ?></span>
                                                        <small class="text-muted">hrs</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($memberUtilized > 0): ?>
                                                        <span class="fw-bold text-success"><?php echo $memberUtilized; ?></span>
                                                        <small class="text-muted">hrs</small>
                                                        <?php if ($m['hours_allocated'] > 0): ?>
                                                            <br><small class="text-muted">
                                                                (<?php echo round(($memberUtilized / $m['hours_allocated']) * 100, 1); ?>% used)
                                                            </small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">0 hrs</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (hasProjectLeadPrivileges() && $m['role'] !== 'project_lead'): ?>
                                                        <a href="?project_id=<?php echo $projectId; ?>&remove_member=<?php echo $m['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" title="Remove"
                                                           onclick="confirmModal('Remove <?php echo htmlspecialchars($m['full_name'], ENT_QUOTES); ?> from project? This action can be undone by restoring the member.', function(){ window.location.href='?project_id=<?php echo $projectId; ?>&remove_member=<?php echo $m['id']; ?>'; }); return false;">
                                                            <i class="fas fa-user-minus"></i>
                                                        </a>
                                                    <?php elseif ($m['role'] === 'project_lead'): ?>
                                                        <span class="text-muted small">Project Lead</span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($teamMembers)): ?>
                                            <tr><td colspan="5" class="text-center p-4">No team members assigned.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Removed Resources Section -->
                        <?php if (!empty($removedMembers)): ?>
                        <div class="card mt-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0 text-muted">
                                    <i class="fas fa-user-times"></i> Removed Resources
                                    <span class="badge bg-secondary ms-2"><?php echo count($removedMembers); ?></span>
                                </h5>
                                <small class="text-muted">Team members who have been removed from this project</small>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Role</th>
                                                <th>Allocated Hours</th>
                                                <th>Utilized Hours</th>
                                                <th>Removed</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($removedMembers as $rm): 
                                                // Get utilized hours for this removed member
                                                $utilizedStmt = $db->prepare("
                                                    SELECT COALESCE(SUM(hours_spent), 0) as utilized_hours 
                                                    FROM project_time_logs 
                                                    WHERE project_id = ? AND user_id = ? AND is_utilized = 1
                                                ");
                                                $utilizedStmt->execute([$projectId, $rm['user_id']]);
                                                $memberUtilized = $utilizedStmt->fetchColumn() ?: 0;
                                            ?>
                                            <tr class="text-muted">
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                                            <i class="fas fa-user text-white small"></i>
                                                        </div>
                                                        <div>
                                                            <div class="fw-medium"><?php echo htmlspecialchars($rm['full_name']); ?></div>
                                                            <small class="text-muted"><?php echo htmlspecialchars($rm['email']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        <?php echo ucfirst(str_replace('_', ' ', $rm['user_role'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($rm['hours_allocated']) && $rm['hours_allocated'] > 0): ?>
                                                        <span class="fw-bold text-muted"><?php echo $rm['hours_allocated']; ?></span>
                                                        <small class="text-muted">hrs</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-muted"><?php echo $memberUtilized; ?></span>
                                                    <small class="text-muted">hrs</small>
                                                    <?php if ($rm['hours_allocated'] > 0): ?>
                                                        <br><small class="text-muted">
                                                            (<?php echo round(($memberUtilized / $rm['hours_allocated']) * 100, 1); ?>% used)
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, Y', strtotime($rm['removed_at'])); ?><br>
                                                        by <?php echo htmlspecialchars($rm['removed_by_name'] ?: 'System'); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if (hasProjectLeadPrivileges()): ?>
                                                        <a href="?project_id=<?php echo $projectId; ?>&restore_member=<?php echo $rm['id']; ?>" 
                                                           class="btn btn-sm btn-outline-success" title="Restore to project"
                                                           onclick="confirmModal('Restore <?php echo htmlspecialchars($rm['full_name'], ENT_QUOTES); ?> back to the project?', function(){ window.location.href='?project_id=<?php echo $projectId; ?>&restore_member=<?php echo $rm['id']; ?>'; }); return false;">
                                                            <i class="fas fa-undo"></i> Restore
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- TAB 2: PAGE ASSIGNMENTS -->
            <div class="tab-pane fade <?php echo $activeTab === 'pages' ? 'show active' : ''; ?>" id="pills-pages">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Individual Page Assignments</h5>
                        <div>
                            <button class="btn btn-sm btn-warning me-2" onclick="showQuickAssignModal()" title="Quick assign same tester/QA and environments to all pages">
                                <i class="fas fa-bolt"></i> Quick Assign All
                            </button>
                            <button class="btn btn-sm btn-success me-2" data-bs-toggle="modal" data-bs-target="#addPageModal">
                                <i class="fas fa-plus"></i> Add Page
                            </button>
                            <button class="btn btn-sm btn-outline-info me-2" data-bs-toggle="collapse" data-bs-target="#legendCollapse" title="Show/Hide Legend">
                                <i class="fas fa-info-circle"></i> Legend
                            </button>
                            <small class="text-muted">Page assignments and status overview</small>
                        </div>
                    </div>
                    
                    <!-- Legend -->
                    <div class="collapse" id="legendCollapse">
                        <div class="card-body border-bottom bg-light">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <h6 class="mb-2">Assignment Status</h6>
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <span class="badge bg-success"><i class="fas fa-check-circle"></i> Ready</span>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle"></i> Partial</span>
                                        <span class="badge bg-secondary"><i class="fas fa-circle"></i> Not Started</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="mb-2">Assignment Count</h6>
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <span class="badge bg-success"><i class="fas fa-users"></i> 3/3</span>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-users"></i> 2/3</span>
                                        <span class="badge bg-secondary"><i class="fas fa-users"></i> 0/3</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="mb-2">Environment Count</h6>
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <span class="badge bg-info"><i class="fas fa-server"></i> 5</span>
                                        <span class="badge bg-secondary"><i class="fas fa-server"></i> 0</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="mb-2">Actions</h6>
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <button class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="fas fa-eye"></i></button>
                                        <span class="small">Show Details</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Project Summary -->
                    <div class="card-body border-bottom bg-light py-2">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <small class="text-muted">AT Assigned:</small>
                                <strong class="ms-1 text-primary">
                                    <?php 
                                    $atAssigned = 0;
                                    foreach ($projectPages as $page) {
                                        if (!empty($page['at_tester_id'])) $atAssigned++;
                                    }
                                    echo $atAssigned;
                                    ?>
                                </strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">FT Assigned:</small>
                                <strong class="ms-1 text-success">
                                    <?php 
                                    $ftAssigned = 0;
                                    foreach ($projectPages as $page) {
                                        if (!empty($page['ft_tester_id'])) $ftAssigned++;
                                    }
                                    echo $ftAssigned;
                                    ?>
                                </strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">QA Assigned:</small>
                                <strong class="ms-1 text-info">
                                    <?php 
                                    $qaAssigned = 0;
                                    foreach ($projectPages as $page) {
                                        if (!empty($page['qa_id'])) $qaAssigned++;
                                    }
                                    echo $qaAssigned;
                                    ?>
                                </strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Total Pages:</small>
                                <strong class="ms-1 text-dark"><?php echo count($projectPages); ?></strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 table-sm">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="text-start" style="width: 40%;">Page/Screen</th>
                                        <th class="text-center" style="width: 30%;">Assignments</th>
                                        <th class="text-center" style="width: 20%;">Status</th>
                                        <th class="text-center" style="width: 10%;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projectPages as $p): 
                                        $pAtIds = getAssignedIdsFromPage($p, 'at_tester');
                                        $pFtIds = getAssignedIdsFromPage($p, 'ft_tester');
                                        
                                        // Get environment count
                                        $envStmt = $db->prepare("SELECT COUNT(*) FROM page_environments pe WHERE pe.page_id = ?");
                                        $envStmt->execute([$p['id']]);
                                        $envCount = $envStmt->fetchColumn();
                                        
                                        // Calculate assignment status
                                        $hasAT = !empty($p['at_tester_id']) || !empty($pAtIds);
                                        $hasFT = !empty($p['ft_tester_id']) || !empty($pFtIds);
                                        $hasQA = !empty($p['qa_id']);
                                        $assignmentCount = ($hasAT ? 1 : 0) + ($hasFT ? 1 : 0) + ($hasQA ? 1 : 0);
                                    ?>
                                    <tr class="align-middle">
                                        <td class="text-start">
                                            <div>
                                                <strong class="d-block"><?php echo htmlspecialchars($p['page_name']); ?></strong>
                                                <?php if ($p['url'] || $p['screen_name']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($p['url'] ?: $p['screen_name']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center align-items-center gap-2">
                                                <!-- Assignment Summary -->
                                                <span class="badge <?php echo $assignmentCount == 3 ? 'bg-success' : ($assignmentCount > 0 ? 'bg-warning text-dark' : 'bg-secondary'); ?>" 
                                                      title="<?php echo $assignmentCount; ?> of 3 roles assigned">
                                                    <i class="fas fa-users"></i> <?php echo $assignmentCount; ?>/3
                                                </span>
                                                
                                                <!-- Environment Summary -->
                                                <span class="badge <?php echo $envCount > 0 ? 'bg-info' : 'bg-secondary'; ?>" 
                                                      title="<?php echo $envCount; ?> environments assigned">
                                                    <i class="fas fa-server"></i> <?php echo $envCount; ?>
                                                </span>
                                                
                                                <!-- Quick View Button -->
                                                <button class="btn btn-sm btn-outline-secondary py-0 px-1" 
                                                        onclick="toggleRowDetails(<?php echo $p['id']; ?>)" 
                                                        title="Show/Hide Details">
                                                    <i class="fas fa-eye" id="eye-<?php echo $p['id']; ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php
                                            $statusClass = 'secondary';
                                            $statusText = 'Not Started';
                                            $statusIcon = 'fas fa-circle';
                                            
                                            if ($assignmentCount == 3 && $envCount > 0) {
                                                $statusClass = 'success';
                                                $statusText = 'Ready';
                                                $statusIcon = 'fas fa-check-circle';
                                            } elseif ($assignmentCount > 0 || $envCount > 0) {
                                                $statusClass = 'warning text-dark';
                                                $statusText = 'Partial';
                                                $statusIcon = 'fas fa-exclamation-circle';
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $statusClass; ?>" title="Assignment Status">
                                                <i class="<?php echo $statusIcon; ?>"></i> <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-page-edit-id="<?php echo $p['id']; ?>" data-bs-toggle="modal" data-bs-target="#pageModal<?php echo $p['id']; ?>" title="Edit assignments">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if (hasProjectLeadPrivileges()): ?>
                                                <a href="?project_id=<?php echo $projectId; ?>&remove_page=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-danger ms-1" title="Delete page" onclick="confirmModal('Delete this page and its environment links? This cannot be undone.', function(){ window.location.href='?project_id=<?php echo $projectId; ?>&remove_page=<?php echo $p['id']; ?>'; }); return false;">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <!-- Expandable Details Row -->
                                    <tr class="collapse" id="details-<?php echo $p['id']; ?>">
                                        <td colspan="4" class="bg-light border-top-0">
                                            <div class="p-3">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <h6 class="mb-2 text-primary">
                                                            <i class="fas fa-users"></i> Team Assignments
                                                        </h6>
                                                        <div class="d-flex gap-2 flex-wrap">
                                                            <?php 
                                                            // Get assigned user names
                                                            $assignedUsers = [];
                                                            if (!empty($p['at_tester_id'])) {
                                                                $userStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                                                                $userStmt->execute([$p['at_tester_id']]);
                                                                $userName = $userStmt->fetchColumn();
                                                                if ($userName) {
                                                                    $assignedUsers[] = '<span class="badge bg-primary" title="AT Tester"><i class="fas fa-user-check"></i> AT: ' . htmlspecialchars($userName) . '</span>';
                                                                }
                                                            }
                                                            if (!empty($p['ft_tester_id'])) {
                                                                $userStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                                                                $userStmt->execute([$p['ft_tester_id']]);
                                                                $userName = $userStmt->fetchColumn();
                                                                if ($userName) {
                                                                    $assignedUsers[] = '<span class="badge bg-success" title="FT Tester"><i class="fas fa-user-cog"></i> FT: ' . htmlspecialchars($userName) . '</span>';
                                                                }
                                                            }
                                                            if (!empty($p['qa_id'])) {
                                                                $userStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                                                                $userStmt->execute([$p['qa_id']]);
                                                                $userName = $userStmt->fetchColumn();
                                                                if ($userName) {
                                                                    $assignedUsers[] = '<span class="badge bg-info" title="QA"><i class="fas fa-user-shield"></i> QA: ' . htmlspecialchars($userName) . '</span>';
                                                                }
                                                            }
                                                            
                                                            if (!empty($assignedUsers)) {
                                                                echo implode(' ', $assignedUsers);
                                                            } else {
                                                                echo '<span class="text-muted"><i class="fas fa-exclamation-circle"></i> No team assignments</span>';
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <h6 class="mb-2 text-primary">
                                                            <i class="fas fa-server"></i> Assigned Environments
                                                        </h6>
                                                        <div class="d-flex gap-1 flex-wrap">
                                                            <?php 
                                                            // Get assigned environments for this page
                                                            $envStmt = $db->prepare("
                                                                SELECT e.name, e.id 
                                                                FROM page_environments pe 
                                                                JOIN testing_environments e ON pe.environment_id = e.id 
                                                                WHERE pe.page_id = ? 
                                                                ORDER BY e.name
                                                            ");
                                                            $envStmt->execute([$p['id']]);
                                                            $assignedEnvs = $envStmt->fetchAll();
                                                            
                                                            if (!empty($assignedEnvs)) {
                                                                foreach ($assignedEnvs as $env) {
                                                                    echo '<span class="badge bg-warning text-dark" title="Environment: ' . htmlspecialchars($env['name']) . '">';
                                                                    echo '<i class="fas fa-server"></i> ' . htmlspecialchars($env['name']);
                                                                    echo '</span> ';
                                                                }
                                                            } else {
                                                                echo '<span class="text-muted"><i class="fas fa-exclamation-circle"></i> No environments assigned</span>';
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Quick Actions -->
                                                <div class="row mt-3">
                                                    <div class="col-12">
                                                        <div class="d-flex gap-2 justify-content-end">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    data-page-edit-id="<?php echo $p['id']; ?>" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#pageModal<?php echo $p['id']; ?>" 
                                                                    title="Edit assignments">
                                                                <i class="fas fa-edit"></i> Edit Assignments
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($projectPages)): ?>
                                    <tr><td colspan="4" class="text-center p-4">No pages found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: BULK ASSIGNMENT -->
            <div class="tab-pane fade <?php echo $activeTab === 'bulk' ? 'show active' : ''; ?>" id="pills-bulk">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Bulk Assignment Tool</h5>
                        <small class="text-muted">Assign same tester/QA and environments to multiple pages at once</small>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="mb-3">Select Pages</h6>
                                    <div class="mb-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllPages()">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllPages()">Clear All</button>
                                    </div>
                                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                        <?php foreach ($projectPages as $p): ?>
                                        <div class="form-check mb-1">
                                            <input class="form-check-input page-checkbox" type="checkbox" name="selected_pages[]" value="<?php echo $p['id']; ?>" id="page_<?php echo $p['id']; ?>">
                                            <label class="form-check-label" for="page_<?php echo $p['id']; ?>">
                                                <strong><?php echo htmlspecialchars($p['page_name']); ?></strong>
                                                <?php if ($p['url'] || $p['screen_name']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($p['url'] ?: $p['screen_name']); ?></small>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($projectPages)): ?>
                                        <p class="text-muted text-center py-2">No pages available.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <h6 class="mb-3">Assignment Details</h6>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">AT Tester</label>
                                                <select name="bulk_at_tester" class="form-select">
                                                    <option value="">-- Don't Change --</option>
                                                    <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'at_tester'): ?>
                                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                    <?php endif; endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">FT Tester</label>
                                                <select name="bulk_ft_tester" class="form-select">
                                                    <option value="">-- Don't Change --</option>
                                                    <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'ft_tester'): ?>
                                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                    <?php endif; endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">QA</label>
                                                <select name="bulk_qa" class="form-select" <?php echo !hasProjectLeadPrivileges() ? 'disabled' : ''; ?>>
                                                    <option value="">-- Don't Change --</option>
                                                    <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'qa'): ?>
                                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                    <?php endif; endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label class="form-label">Environments</label>
                                                <div class="mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllEnvs()">Select All</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllEnvs()">Clear All</button>
                                                </div>
                                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                                    <?php foreach ($allEnvironments as $env): ?>
                                                    <div class="form-check mb-1">
                                                        <input class="form-check-input env-checkbox" type="checkbox" name="bulk_envs[]" value="<?php echo $env['id']; ?>" id="env_<?php echo $env['id']; ?>">
                                                        <label class="form-check-label" for="env_<?php echo $env['id']; ?>">
                                                            <?php echo htmlspecialchars($env['name']); ?>
                                                        </label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($allEnvironments)): ?>
                                                    <p class="text-muted text-center py-2">No environments configured.</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" name="bulk_assign" class="btn btn-success btn-lg">
                                            <i class="fas fa-magic"></i> Apply Bulk Assignment
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Preview Section -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0">Preview: What will be assigned</h6>
                    </div>
                    <div class="card-body">
                        <div id="bulk-preview" class="text-muted">
                            Select pages, testers/QA, and environments to see preview...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.badge {
    font-size: 0.7rem;
    padding: 0.25em 0.4em;
}
.badge i {
    font-size: 0.6rem;
    margin-right: 2px;
}
.table-sm td {
    padding: 0.5rem 0.25rem;
    vertical-align: middle;
}
.table-sm th {
    padding: 0.5rem 0.25rem;
    font-size: 0.85rem;
    font-weight: 600;
}
.collapse {
    transition: all 0.3s ease;
}
.collapse.show {
    display: table-row !important;
}
</style>

<script>
// Keep selected tab on page refresh
document.addEventListener('DOMContentLoaded', function() {
    const activeTab = '<?php echo $activeTab; ?>';
    if (activeTab === 'pages') {
        var someTabTriggerEl = document.querySelector('#pills-pages-tab')
        var tab = new bootstrap.Tab(someTabTriggerEl)
        tab.show()
    } else if (activeTab === 'bulk') {
        var someTabTriggerEl = document.querySelector('#pills-bulk-tab')
        var tab = new bootstrap.Tab(someTabTriggerEl)
        tab.show()
    }
    
    // Auto-open modal or restore focus after assign
    const params = new URLSearchParams(window.location.search);
    const openPageId = params.get('open_page_id');
    if (openPageId) {
        const modalEl = document.getElementById('pageModal' + openPageId);
        if (modalEl) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }
    }
    const focusPageId = params.get('focus_page_id');
    if (focusPageId) {
        const btn = document.querySelector('[data-page-edit-id="' + focusPageId + '"]');
        if (btn) {
            btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            btn.focus();
        }
    }

    // Hours validation for team assignment
    const hoursInput = document.getElementById('hoursInput');
    const hoursValidation = document.getElementById('hoursValidation');
    const availableForAllocation = <?php echo $availableForAllocation; ?>;
    
    if (hoursInput && hoursValidation) {
        hoursInput.addEventListener('input', function() {
            const inputValue = parseFloat(this.value) || 0;
            
            if (inputValue > availableForAllocation) {
                hoursValidation.innerHTML = '<small class="text-danger"><i class="fas fa-exclamation-triangle"></i> Exceeds available allocation (' + availableForAllocation + ' hours)</small>';
                this.classList.add('is-invalid');
                document.querySelector('button[name="assign_team"]').disabled = true;
            } else if (inputValue < 0) {
                hoursValidation.innerHTML = '<small class="text-danger"><i class="fas fa-exclamation-triangle"></i> Hours cannot be negative</small>';
                this.classList.add('is-invalid');
                document.querySelector('button[name="assign_team"]').disabled = true;
            } else {
                hoursValidation.innerHTML = '<small class="text-success"><i class="fas fa-check"></i> Valid allocation</small>';
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
                document.querySelector('button[name="assign_team"]').disabled = false;
            }
        });
    }

    // Confirmation before adding team members
    const teamAssignForm = document.getElementById('teamAssignForm');
    if (teamAssignForm) {
        teamAssignForm.addEventListener('submit', function(e) {
            if (teamAssignForm.dataset.confirmed === '1') {
                return;
            }

            e.preventDefault();
            const selectedUsers = Array.from(teamAssignForm.querySelectorAll('select[name="user_ids[]"] option:checked'));
            const selectedCount = selectedUsers.length;
            const hours = teamAssignForm.querySelector('input[name="hours_allocated"]')?.value || '0';

            if (selectedCount === 0) {
                teamAssignForm.submit();
                return;
            }

            const msg = 'Add ' + selectedCount + ' team member(s) with ' + hours + ' allocated hours?';
            if (typeof window.confirmModal === 'function') {
                window.confirmModal(msg, function() {
                    teamAssignForm.dataset.confirmed = '1';
                    teamAssignForm.submit();
                }, {
                    title: 'Confirm Team Assignment',
                    confirmText: 'Add Members',
                    confirmClass: 'btn-primary'
                });
            } else if (window.confirm(msg)) {
                teamAssignForm.dataset.confirmed = '1';
                teamAssignForm.submit();
            }
        });
    }
});

// Bulk assignment helper functions
function selectAllPages() {
    document.querySelectorAll('.page-checkbox').forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkPreview();
}

function clearAllPages() {
    document.querySelectorAll('.page-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkPreview();
}

function selectAllEnvs() {
    document.querySelectorAll('.env-checkbox').forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkPreview();
}

function clearAllEnvs() {
    document.querySelectorAll('.env-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkPreview();
}

function updateBulkPreview() {
    const selectedPages = document.querySelectorAll('.page-checkbox:checked');
    const selectedEnvs = document.querySelectorAll('.env-checkbox:checked');
    const atTester = document.querySelector('select[name="bulk_at_tester"]').selectedOptions[0]?.text || 'None';
    const ftTester = document.querySelector('select[name="bulk_ft_tester"]').selectedOptions[0]?.text || 'None';
    const qa = document.querySelector('select[name="bulk_qa"]').selectedOptions[0]?.text || 'None';
    
    const previewDiv = document.getElementById('bulk-preview');
    
    if (selectedPages.length === 0 || selectedEnvs.length === 0) {
        previewDiv.innerHTML = '<span class="text-muted">Select pages, testers/QA, and environments to see preview...</span>';
        return;
    }
    
    let html = '<div class="row">';
    html += '<div class="col-md-6">';
    html += '<h6>Selected Pages (' + selectedPages.length + '):</h6>';
    html += '<ul class="list-unstyled small">';
    selectedPages.forEach(page => {
        const label = page.nextElementSibling.querySelector('strong').textContent;
        html += '<li>• ' + label + '</li>';
    });
    html += '</ul></div>';
    
    html += '<div class="col-md-6">';
    html += '<h6>Assignment Details:</h6>';
    html += '<p class="small mb-1"><strong>AT Tester:</strong> ' + atTester + '</p>';
    html += '<p class="small mb-1"><strong>FT Tester:</strong> ' + ftTester + '</p>';
    html += '<p class="small mb-1"><strong>QA:</strong> ' + qa + '</p>';
    html += '<p class="small mb-1"><strong>Environments (' + selectedEnvs.length + '):</strong></p>';
    html += '<ul class="list-unstyled small">';
    selectedEnvs.forEach(env => {
        const label = env.nextElementSibling.textContent;
        html += '<li>• ' + label + '</li>';
    });
    html += '</ul></div></div>';
    
    previewDiv.innerHTML = html;
}

// Add event listeners for real-time preview updates
document.addEventListener('DOMContentLoaded', function() {
    // Ensure modals are mounted under body to avoid hidden/stacking issues
    document.querySelectorAll('.modal').forEach(modalEl => {
        if (modalEl.parentElement !== document.body) {
            document.body.appendChild(modalEl);
        }
    });

    // Page checkboxes
    document.querySelectorAll('.page-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkPreview);
    });
    
    // Environment checkboxes
    document.querySelectorAll('.env-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkPreview);
    });
    
    // Tester/QA selects
    document.querySelectorAll('select[name^="bulk_"]').forEach(select => {
        select.addEventListener('change', updateBulkPreview);
    });
});

function showQuickAssignModal() {
    var modal = new bootstrap.Modal(document.getElementById('quickAssignModal'));
    modal.show();
}

function selectAllQuickEnvs() {
    document.querySelectorAll('.quick-env-checkbox').forEach(checkbox => {
        checkbox.checked = true;
    });
}

function clearAllQuickEnvs() {
    document.querySelectorAll('.quick-env-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
}

function toggleRowDetails(pageId, context = 'main') {
    const detailsRowId = context === 'nested' ? 'details-nested-' + pageId : 'details-' + pageId;
    const detailsRow = document.getElementById(detailsRowId);
    const eyeIcon = document.getElementById('eye-' + (context === 'nested' ? 'nested-' : '') + pageId);
    
    if (!detailsRow) {
        console.error('Details row not found:', detailsRowId);
        return;
    }
    
    if (detailsRow.classList.contains('show')) {
        detailsRow.classList.remove('show');
        // Update both eye icons if they exist
        const mainEyeIcon = document.getElementById('eye-' + pageId);
        const nestedEyeIcon = document.getElementById('eye-nested-' + pageId);
        
        if (mainEyeIcon) {
            mainEyeIcon.classList.remove('fa-eye-slash');
            mainEyeIcon.classList.add('fa-eye');
        }
        if (nestedEyeIcon) {
            nestedEyeIcon.classList.remove('fa-eye-slash');
            nestedEyeIcon.classList.add('fa-eye');
        }
    } else {
        // Hide all other details first (both main and nested)
        document.querySelectorAll('[id^="details-"]').forEach(row => {
            row.classList.remove('show');
        });
        document.querySelectorAll('[id^="eye-"]').forEach(icon => {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        });
        
        // Show this row's details
        detailsRow.classList.add('show');
        
        // Update the clicked eye icon
        if (eyeIcon) {
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
        }
    }
}
</script>

<!-- Quick Add Page Modal -->
<div class="modal fade" id="addPageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Page</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Page Name *</label>
                        <input type="text" name="page_name" class="form-control" required placeholder="e.g. Login Page">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">URL / Screen ID</label>
                        <input type="text" name="url" class="form-control" placeholder="e.g. /login or screen_001">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Screen Name (Optional)</label>
                        <input type="text" name="screen_name" class="form-control" placeholder="e.g. Primary Login">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="quick_add_page" class="btn btn-primary">Add Page</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Assign All Modal -->
<div class="modal fade" id="quickAssignModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-bolt"></i> Quick Assign All Pages</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> This will assign the selected tester/QA and environments to ALL pages in this project at once.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">AT Tester</label>
                                <select name="quick_at_tester" class="form-select">
                                    <option value="">-- Keep Current --</option>
                                    <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'at_tester'): ?>
                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">FT Tester</label>
                                <select name="quick_ft_tester" class="form-select">
                                    <option value="">-- Keep Current --</option>
                                    <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'ft_tester'): ?>
                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">QA</label>
                                <select name="quick_qa" class="form-select" <?php echo !hasProjectLeadPrivileges() ? 'disabled' : ''; ?>>
                                    <option value="">-- Keep Current --</option>
                                    <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'qa'): ?>
                                        <option value="<?php echo $tm['user_id']; ?>"><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Environments</label>
                                <div class="mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllQuickEnvs()">Select All</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllQuickEnvs()">Clear All</button>
                                </div>
                                <div style="max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                    <?php foreach ($allEnvironments as $env): ?>
                                    <div class="form-check mb-1">
                                        <input class="form-check-input quick-env-checkbox" type="checkbox" name="quick_envs[]" value="<?php echo $env['id']; ?>" id="quick_env_<?php echo $env['id']; ?>" checked>
                                        <label class="form-check-label" for="quick_env_<?php echo $env['id']; ?>">
                                            <?php echo htmlspecialchars($env['name']); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($allEnvironments)): ?>
                                    <p class="text-muted text-center py-2">No environments configured.</p>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">Selected environments will be linked to all pages with the assigned testers/QA.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="quick_assign_all" class="btn btn-warning">
                        <i class="fas fa-bolt"></i> Assign to All Pages
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Page Assignment Modals -->
<?php if (isset($projectPages) && is_array($projectPages)): ?>
<?php foreach ($projectPages as $p): ?>
<div class="modal fade" id="pageModal<?php echo $p['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content text-start">
            <form method="POST">
                <input type="hidden" name="page_id" value="<?php echo $p['id']; ?>">
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_GET['return_to'] ?? ''); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo htmlspecialchars($p['page_name'] ?: ($p['url'] ?: ('Page #'.$p['id']))); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">AT Tester</label>
                        <select name="at_tester_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'at_tester'): ?>
                                <option value="<?php echo $tm['user_id']; ?>" <?php echo $p['at_tester_id'] == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">FT Tester</label>
                        <select name="ft_tester_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'ft_tester'): ?>
                                <option value="<?php echo $tm['user_id']; ?>" <?php echo $p['ft_tester_id'] == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">QA</label>
                        <select name="qa_id" class="form-select" <?php echo !hasProjectLeadPrivileges() ? 'disabled' : ''; ?> >
                            <option value="">-- None --</option>
                            <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'qa'): ?>
                                <option value="<?php echo $tm['user_id']; ?>" <?php echo $p['qa_id'] == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                        <?php if (!hasProjectLeadPrivileges()): ?>
                            <input type="hidden" name="qa_id" value="<?php echo $p['qa_id']; ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Environments (per-environment assignments)</label>
                        <?php
                        $stmt = $db->prepare("SELECT pe.*, e.name FROM page_environments pe JOIN testing_environments e ON pe.environment_id = e.id WHERE pe.page_id = ?");
                        $stmt->execute([$p['id']]);
                        $pageEnvMap = [];
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pe) {
                            $pageEnvMap[$pe['environment_id']] = $pe;
                        }
                        foreach ($allEnvironments as $env):
                            $envId = $env['id'];
                            $linked = isset($pageEnvMap[$envId]);
                            $atSelected = $linked ? $pageEnvMap[$envId]['at_tester_id'] : '';
                            $ftSelected = $linked ? $pageEnvMap[$envId]['ft_tester_id'] : '';
                            $qaSelected = $linked ? $pageEnvMap[$envId]['qa_id'] : '';
                        ?>
                        <div class="border rounded p-2 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="envs[]" value="<?php echo $envId; ?>" id="env_chk_<?php echo $p['id']; ?>_<?php echo $envId; ?>" <?php echo $linked ? 'checked' : ''; ?> />
                                <label class="form-check-label fw-bold" for="env_chk_<?php echo $p['id']; ?>_<?php echo $envId; ?>"><?php echo htmlspecialchars($env['name']); ?></label>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-4">
                                    <label class="form-label">AT Tester</label>
                                    <select name="at_tester_env_<?php echo $envId; ?>" class="form-select">
                                        <option value="">-- None --</option>
                                        <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'at_tester'): ?>
                                            <option value="<?php echo $tm['user_id']; ?>" <?php echo $atSelected == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                        <?php endif; endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">FT Tester</label>
                                    <select name="ft_tester_env_<?php echo $envId; ?>" class="form-select">
                                        <option value="">-- None --</option>
                                        <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'ft_tester'): ?>
                                            <option value="<?php echo $tm['user_id']; ?>" <?php echo $ftSelected == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                        <?php endif; endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">QA</label>
                                    <select name="qa_env_<?php echo $envId; ?>" class="form-select" <?php echo !hasProjectLeadPrivileges() ? 'disabled' : ''; ?> >
                                        <option value="">-- None --</option>
                                        <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'qa'): ?>
                                            <option value="<?php echo $tm['user_id']; ?>" <?php echo $qaSelected == $tm['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                        <?php endif; endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="assign_page" class="btn btn-primary">Save Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
