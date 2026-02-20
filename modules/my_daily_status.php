<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Restrict admin access - they don't need daily status (except for AJAX requests)
$isAjaxRequest = isset($_GET['action']) && in_array($_GET['action'], ['get_personal_note', 'check_edit_request']);
$isPostRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']);
if (hasAdminPrivileges() && !$isAjaxRequest && !$isPostRequest) {
    header("Location: " . getBaseDir() . "/modules/admin/calendar.php");
    exit;
}

$userId = $_SESSION['user_id'];
$isAdmin = hasAdminPrivileges();
$db = Database::getInstance();
$baseDir = getBaseDir();
$date = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d');
$availabilityStatuses = getAvailabilityStatusOptions(false);
$availabilityStatusKeys = array_values(array_unique(array_map(static function ($row) {
    return strtolower((string)($row['status_key'] ?? ''));
}, $availabilityStatuses)));
if (empty($availabilityStatusKeys)) {
    $availabilityStatusKeys = ['not_updated', 'available', 'working', 'busy', 'on_leave', 'sick_leave'];
}
try {
    $db->exec("ALTER TABLE user_daily_status MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'not_updated'");
} catch (Exception $e) {}

// Ensure edit requests table exists (safe to run if migration not applied)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_edit_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        req_date DATE NOT NULL,
        request_type ENUM('edit','delete') NOT NULL DEFAULT 'edit',
        reason TEXT,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_date_type (user_id, req_date, request_type),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests ADD COLUMN request_type ENUM('edit','delete') NOT NULL DEFAULT 'edit'"); } catch (Exception $e) {}
try { $db->exec("UPDATE user_edit_requests SET request_type = 'delete' WHERE reason LIKE 'Deletion request for time log ID %'"); } catch (Exception $e) {}
try { $db->exec("UPDATE user_edit_requests SET request_type = 'edit' WHERE request_type IS NULL OR request_type = ''"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests DROP INDEX uq_user_date"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests ADD UNIQUE KEY uq_user_date_type (user_id, req_date, request_type)"); } catch (Exception $e) {}
$hasRequestTypeColumn = false;
try {
    $colStmt = $db->query("SHOW COLUMNS FROM user_edit_requests LIKE 'request_type'");
    $hasRequestTypeColumn = ($colStmt && $colStmt->rowCount() > 0);
} catch (Exception $e) {
    $hasRequestTypeColumn = false;
}

// Ensure time log history table exists (for audit trail visible to clients)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS project_time_log_history (
        id INT PRIMARY KEY AUTO_INCREMENT,
        time_log_id INT NULL,
        project_id INT NOT NULL,
        user_id INT NOT NULL,
        action_type ENUM('created','deleted','updated') NOT NULL,
        old_log_date DATE NULL,
        new_log_date DATE NULL,
        old_hours DECIMAL(10,2) NULL,
        new_hours DECIMAL(10,2) NULL,
        old_description TEXT NULL,
        new_description TEXT NULL,
        changed_by INT NOT NULL,
        context_json LONGTEXT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_time_log_id (time_log_id),
        INDEX idx_project_id (project_id),
        INDEX idx_user_id (user_id),
        INDEX idx_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}

if (!function_exists('recordProjectTimeLogHistory')) {
    function recordProjectTimeLogHistory($db, array $data) {
        try {
            $stmt = $db->prepare("
                INSERT INTO project_time_log_history
                (time_log_id, project_id, user_id, action_type, old_log_date, new_log_date, old_hours, new_hours, old_description, new_description, changed_by, context_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['time_log_id'] ?? null,
                $data['project_id'] ?? 0,
                $data['user_id'] ?? 0,
                $data['action_type'] ?? 'updated',
                $data['old_log_date'] ?? null,
                $data['new_log_date'] ?? null,
                $data['old_hours'] ?? null,
                $data['new_hours'] ?? null,
                $data['old_description'] ?? null,
                $data['new_description'] ?? null,
                $data['changed_by'] ?? 0,
                $data['context_json'] ?? null
            ]);
        } catch (Exception $e) {
            // Keep hours logging resilient even if history insert fails.
        }
    }
}

// Handle AJAX: check if edit request is pending for this date
if (isset($_GET['action']) && $_GET['action'] === 'check_edit_request') {
    $reqDate = $_GET['date'] ?? $date;
    $stmt = $db->prepare("
        SELECT status
        FROM user_edit_requests
        WHERE user_id = ?
          AND req_date = ?
          AND request_type = 'edit'
    ");
    $stmt->execute([$userId, $reqDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $status = $row['status'] ?? null;
    $pending = ($status === 'pending');
    $approved = ($status === 'approved');
    $rejected = ($status === 'rejected');
    $used = ($status === 'used');
    header('Content-Type: application/json');
    echo json_encode([
        'pending' => $pending,
        'approved' => $approved,
        'rejected' => $rejected,
        'used' => $used,
        'status' => $status
    ]);
    exit;
}

// Handle AJAX: user requests edit for a past date
if (isset($_POST['action']) && $_POST['action'] === 'request_edit') {
    $reqDate = $_POST['date'] ?? $date;
    $reason = $_POST['reason'] ?? '';
    
    try {
        // Insert or update request to pending
        $stmt = $db->prepare("INSERT INTO user_edit_requests (user_id, req_date, request_type, status, reason) VALUES (?, ?, 'edit', 'pending', ?) ON DUPLICATE KEY UPDATE request_type='edit', status='pending', reason=VALUES(reason), updated_at=NOW()");
        $stmt->execute([$userId, $reqDate, $reason]);

        // Insert notification for all admins
        $adminStmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin','super_admin') AND is_active = 1");
        $adminStmt->execute();
        $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($admins)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No active admins found']);
            exit;
        }
        
        $userName = $_SESSION['full_name'] ?? 'User';
        $msg = $userName . " requested edit for " . $reqDate;
        if ($reason) {
            $msg .= " - Reason: " . substr($reason, 0, 100) . (strlen($reason) > 100 ? '...' : '');
        }
        $link = "/modules/admin/edit_requests.php";
        
        foreach ($admins as $admin) {
            $nStmt = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'edit_request', ?, ?)");
            $nStmt->execute([$admin['id'], $msg, $link]);
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Edit request sent to ' . count($admins) . ' admin(s)']);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle AJAX: save pending changes for edit request
if (isset($_POST['action']) && $_POST['action'] === 'save_pending') {
    $reqDate = $_POST['date'] ?? $date;
    $status = normalizeAvailabilityStatusKey($_POST['status'] ?? 'not_updated', $availabilityStatusKeys, 'not_updated');
    $notes = $_POST['notes'] ?? '';
    $personal_note = $_POST['personal_note'] ?? '';
    
    try {
        // Create pending changes table if not exists
        $db->exec("CREATE TABLE IF NOT EXISTS user_pending_changes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            req_date DATE NOT NULL,
            status ENUM('not_updated','available','working','busy','on_leave','sick_leave') DEFAULT 'not_updated',
            notes TEXT,
            personal_note TEXT,
            pending_time_logs TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_date (user_id, req_date),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        // Keep status storage flexible when new master keys are introduced.
        $db->exec("ALTER TABLE user_pending_changes MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'not_updated'");
        
        // Save pending changes
        $pendingLogs = $_POST['pending_time_logs'] ?? '[]';
        $stmt = $db->prepare("INSERT INTO user_pending_changes (user_id, req_date, status, notes, personal_note, pending_time_logs) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status=VALUES(status), notes=VALUES(notes), personal_note=VALUES(personal_note), pending_time_logs=VALUES(pending_time_logs), updated_at=NOW()");
        $stmt->execute([$userId, $reqDate, $status, $notes, $personal_note, $pendingLogs]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Pending changes saved successfully']);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Ensure personal notes table exists (safe to run if migration not applied)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_calendar_notes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        note_date DATE NOT NULL,
        content TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_date (user_id, note_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    // If creation fails (permissions), we'll surface real errors later when used.
}

if (!function_exists('ensureOffProdProjectId')) {
    function ensureOffProdProjectId($db, $userId) {
        try {
            $findByPo = $db->prepare("SELECT id, status FROM projects WHERE po_number = 'OFF-PROD-001' ORDER BY id DESC LIMIT 1");
            $findByPo->execute();
            $row = $findByPo->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['id'])) {
                $id = (int)$row['id'];
                $status = (string)($row['status'] ?? '');
                if (in_array($status, ['cancelled', 'archived'], true)) {
                    $upd = $db->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ?");
                    $upd->execute([$id]);
                }
                return $id;
            }

            $findByTitle = $db->prepare("SELECT id, status FROM projects WHERE UPPER(title) LIKE '%OFF%PROD%' ORDER BY id DESC LIMIT 1");
            $findByTitle->execute();
            $row2 = $findByTitle->fetch(PDO::FETCH_ASSOC);
            if ($row2 && !empty($row2['id'])) {
                $id2 = (int)$row2['id'];
                $status2 = (string)($row2['status'] ?? '');
                if (in_array($status2, ['cancelled', 'archived'], true)) {
                    $upd2 = $db->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ?");
                    $upd2->execute([$id2]);
                }
                return $id2;
            }

            $ins = $db->prepare("
                INSERT INTO projects (po_number, title, description, project_type, priority, status, created_by)
                VALUES ('OFF-PROD-001', 'Off-Production / Bench', 'System project for off-production and bench hour logging.', 'web', 'medium', 'in_progress', ?)
            ");
            $ins->execute([(int)$userId]);
            return (int)$db->lastInsertId();
        } catch (Exception $e) {
            return 0;
        }
    }
}

// Ensure pending log deletion request table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_pending_log_deletions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        req_date DATE NOT NULL,
        log_id INT NOT NULL,
        reason TEXT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_log (user_id, log_id),
        INDEX idx_user_date_status (user_id, req_date, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}

// Ensure pending log edit request table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_pending_log_edits (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        req_date DATE NOT NULL,
        log_id INT NOT NULL,
        new_hours DECIMAL(10,2) NOT NULL,
        new_description TEXT NOT NULL,
        new_project_id INT NULL,
        new_task_type VARCHAR(50) NULL,
        new_page_id INT NULL,
        new_environment_id INT NULL,
        new_issue_id INT NULL,
        new_phase_id INT NULL,
        new_generic_category_id INT NULL,
        new_testing_type VARCHAR(50) NULL,
        new_phase_activity VARCHAR(100) NULL,
        new_generic_task_detail TEXT NULL,
        new_is_utilized TINYINT(1) NULL,
        reason TEXT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_log_edit (user_id, log_id),
        INDEX idx_user_date_status (user_id, req_date, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_project_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_task_type VARCHAR(50) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_page_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_environment_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_issue_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_phase_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_generic_category_id INT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_testing_type VARCHAR(50) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_phase_activity VARCHAR(100) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_generic_task_detail TEXT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_log_edits ADD COLUMN new_is_utilized TINYINT(1) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_changes ADD COLUMN pending_time_logs TEXT NULL"); } catch (Exception $e) {}

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $isAdmin = hasAdminPrivileges();
    $targetUser = $userId;
    
    $status = normalizeAvailabilityStatusKey($_POST['status'] ?? 'not_updated', $availabilityStatusKeys, 'not_updated');
    $notes = $_POST['notes'];
    $personal_note = isset($_POST['personal_note']) ? trim($_POST['personal_note']) : null;
    
    // Allow admin to update another user's status by providing user_id
    if ($isAdmin && isset($_POST['user_id']) && $_POST['user_id'] !== '') {
        $targetUser = intval($_POST['user_id']);
        
        // For admin updates, we don't need to check edit request approval
        $stmt = $db->prepare("
            INSERT INTO user_daily_status (user_id, status_date, status, notes)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes)
        ");
        
        $stmt->execute([$targetUser, $date, $status, $notes]);

        // Save personal note (visible only to this user)
        if ($personal_note !== null) {
            $trimmedNote = trim($personal_note);
            if ($trimmedNote !== '') {
                $noteStmt = $db->prepare("INSERT INTO user_calendar_notes (user_id, note_date, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)");
                $noteStmt->execute([$targetUser, $date, $trimmedNote]);
            } else {
                // Delete empty personal note if it exists
                $deleteStmt = $db->prepare("DELETE FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
                $deleteStmt->execute([$targetUser, $date]);
            }
        }

        // If AJAX request, return JSON instead of redirecting
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }

        $_SESSION['success'] = "Status updated successfully.";
        header("Location: " . getBaseDir() . "/modules/admin/calendar.php");
        exit;
    }

    // Prevent non-admin users from updating past dates unless approved
    $today = date('Y-m-d');
    if (!$isAdmin && $date < $today) {
        // Check if user has approved edit request for this date
        $requestStmt = $db->prepare("
            SELECT status
            FROM user_edit_requests
            WHERE user_id = ?
              AND req_date = ?
              AND status = 'approved'
              AND request_type = 'edit'
        ");
        $requestStmt->execute([$userId, $date]);
        $approvedRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$approvedRequest) {
            $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Cannot update past dates without admin approval']);
                exit;
            }
            $_SESSION['error'] = 'Cannot update past dates without admin approval.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
            exit;
        }
    }
    
    $stmt = $db->prepare("
        INSERT INTO user_daily_status (user_id, status_date, status, notes)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes)
    ");
    
    $stmt->execute([$targetUser, $date, $status, $notes]);

    // Save personal note (visible only to this user)
    if ($personal_note !== null) {
        $trimmedNote = trim($personal_note);
        if ($trimmedNote !== '') {
            $noteStmt = $db->prepare("INSERT INTO user_calendar_notes (user_id, note_date, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)");
            $noteStmt->execute([$targetUser, $date, $trimmedNote]);
        } else {
            // Delete empty personal note if it exists
            $deleteStmt = $db->prepare("DELETE FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
            $deleteStmt->execute([$targetUser, $date]);
        }
    }

    // If this was an approved edit request, mark it as used
    if (!$isAdmin && $date < $today) {
        $updateRequestStmt = $db->prepare("
            UPDATE user_edit_requests
            SET status = 'used', updated_at = NOW()
            WHERE user_id = ?
              AND req_date = ?
              AND status = 'approved'
              AND request_type = 'edit'
        ");
        $updateRequestStmt->execute([$userId, $date]);
    }

    // If AJAX request, return JSON instead of redirecting
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    $_SESSION['success'] = "Status updated successfully.";
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

// Handle Time Log
$isTimeLogPost = (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    (
        isset($_POST['log_time']) ||
        (
            !isset($_POST['update_status']) &&
            isset($_POST['hours_spent']) &&
            isset($_POST['description']) &&
            (isset($_POST['project_id']) || isset($_POST['bench_activity']))
        )
    )
);
if ($isTimeLogPost) {

    
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $hours = floatval($_POST['hours_spent']);
    $desc = $_POST['description'];
    $isBenchRequest = (isset($_POST['bench_activity']) && trim((string)$_POST['bench_activity']) !== '');
    $taskTypeInput = trim((string)($_POST['task_type'] ?? ''));
    $taskTypeMap = [
        'regression_testing' => 'regression',
        'page_qa' => 'page_testing'
    ];
    $taskType = $taskTypeMap[$taskTypeInput] ?? $taskTypeInput;
    $allowedTaskTypes = ['page_testing', 'project_phase', 'generic_task', 'regression', 'other'];
    if (!in_array($taskType, $allowedTaskTypes, true)) {
        $taskType = 'other';
    }
    

    
    // Initialize variables
    $pageIds = [];
    $envId = null;
    $phaseId = null;
    $genericCategoryId = null;
    $taskDetails = '';
    
    // Process based on task type
    switch ($taskTypeInput) {
        case 'page_testing':
            // Handle multiple pages
            if (isset($_POST['page_ids']) && is_array($_POST['page_ids'])) {
                $pageIds = array_filter($_POST['page_ids'], function($id) { return !empty($id); });
                $pageIds = array_map('intval', $pageIds);
            }
            
            // Handle multiple environments
            if (isset($_POST['environment_ids']) && is_array($_POST['environment_ids'])) {
                $envIds = array_filter($_POST['environment_ids'], function($id) { return !empty($id); });
                if (!empty($envIds)) {
                    // For now, store the first environment ID (we can enhance this later)
                    $envId = intval($envIds[0]);
                    // Add environment info to description
                    if (count($envIds) > 1) {
                        $desc .= ' (Multiple environments: ' . count($envIds) . ')';
                    }
                }
            }
            $testingType = $_POST['testing_type'] ?? '';
            if ($testingType) {
                $desc = ucfirst(str_replace('_', ' ', $testingType)) . ': ' . $desc;
            }
            
            // Add page info to description
            if (!empty($pageIds)) {
                if (count($pageIds) > 1) {
                    $desc .= ' (Multiple pages: ' . count($pageIds) . ')';
                }
            }
            break;
            
        case 'project_phase':
            $phaseId = isset($_POST['phase_id']) && $_POST['phase_id'] !== '' ? intval($_POST['phase_id']) : null;
            $phaseActivity = $_POST['phase_activity'] ?? '';
            if ($phaseActivity) {
                $desc = ucfirst($phaseActivity) . ': ' . $desc;
            }
            break;
            
        case 'generic_task':
            $genericCategoryId = isset($_POST['generic_category_id']) && $_POST['generic_category_id'] !== '' ? intval($_POST['generic_category_id']) : null;
            $taskDetails = $_POST['generic_task_detail'] ?? '';
            if ($taskDetails) {
                $desc .= ' - ' . $taskDetails;
            }
            break;
    }
    
    // Handle bench activity description enhancement
    if ($isBenchRequest) {
        $benchActivity = $_POST['bench_activity'];
        if ($projectId <= 0) {
            $projectId = ensureOffProdProjectId($db, $userId);
        }
        $desc = ucfirst($benchActivity) . ': ' . $desc;
    }
    // Issue link (optional) for regression hours
    $issueId = isset($_POST['issue_id']) && $_POST['issue_id'] !== '' ? intval($_POST['issue_id']) : null;
    
    if ($projectId <= 0) {
        $_SESSION['error'] = $isBenchRequest
            ? "Unable to log off-production hours: OFF-PROD project was not found. Create or activate an OFF-PROD project first."
            : "Unable to log hours: project is not selected.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
        exit;
    }

    // Check if off-production
    $isUtilized = $isBenchRequest ? 0 : 1;
    if (!$isBenchRequest) {
        $stmt = $db->prepare("SELECT po_number FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $po = $stmt->fetchColumn();
        if (strcasecmp(trim((string)$po), 'OFF-PROD-001') === 0) {
            $isUtilized = 0;
        }
    }
    

    
    try {
        // Prevent logging for past dates unless admin or approved edit request
        $today = date('Y-m-d');
        if (!$isAdmin && $date < $today) {
            if ($hasRequestTypeColumn) {
                $reqCheck = $db->prepare("SELECT status FROM user_edit_requests WHERE user_id = ? AND req_date = ? AND status = 'approved' AND request_type = 'edit'");
            } else {
                $reqCheck = $db->prepare("SELECT status FROM user_edit_requests WHERE user_id = ? AND req_date = ? AND status = 'approved' AND (reason IS NULL OR reason NOT LIKE 'Deletion request for time log ID %')");
            }
            $reqCheck->execute([$userId, $date]);
            $approved = $reqCheck->fetch(PDO::FETCH_ASSOC);
            if (!$approved) {
                // Capture current date values so admin can review and apply pending logs on approval.
                $statusRowStmt = $db->prepare("SELECT status, notes FROM user_daily_status WHERE user_id = ? AND status_date = ?");
                $statusRowStmt->execute([$userId, $date]);
                $statusRow = $statusRowStmt->fetch(PDO::FETCH_ASSOC);
                $currStatus = $statusRow['status'] ?? 'not_updated';
                $currNotes = $statusRow['notes'] ?? '';
                $noteRowStmt = $db->prepare("SELECT content FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
                $noteRowStmt->execute([$userId, $date]);
                $currPersonal = (string)($noteRowStmt->fetchColumn() ?: '');

                $pendingEntry = [
                    'project_id' => $projectId,
                    'task_type' => $taskType,
                    'page_ids' => array_values(array_map('intval', $pageIds)),
                    'environment_ids' => $envId ? [(int)$envId] : [],
                    'testing_type' => $_POST['testing_type'] ?? null,
                    'issue_id' => $issueId ?: null,
                    'hours' => $hours,
                    'description' => $desc,
                    'is_utilized' => (int)$isUtilized
                ];

                $pendingStmt = $db->prepare("SELECT pending_time_logs FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
                $pendingStmt->execute([$userId, $date]);
                $existingPendingRaw = $pendingStmt->fetchColumn();
                $existingPending = json_decode((string)$existingPendingRaw, true);
                if (!is_array($existingPending)) {
                    $existingPending = [];
                }
                $existingPending[] = $pendingEntry;

                $savePendingStmt = $db->prepare("
                    INSERT INTO user_pending_changes (user_id, req_date, status, notes, personal_note, pending_time_logs)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        notes = VALUES(notes),
                        personal_note = VALUES(personal_note),
                        pending_time_logs = VALUES(pending_time_logs),
                        updated_at = NOW()
                ");
                $savePendingStmt->execute([$userId, $date, $currStatus, $currNotes, $currPersonal, json_encode($existingPending, JSON_UNESCAPED_UNICODE)]);

                $reason = 'Time log edit request for date ' . $date;
                if ($hasRequestTypeColumn) {
                    $reqStmt = $db->prepare("
                        INSERT INTO user_edit_requests (user_id, req_date, request_type, status, reason)
                        VALUES (?, ?, 'edit', 'pending', ?)
                        ON DUPLICATE KEY UPDATE
                            request_type = 'edit',
                            status = 'pending',
                            reason = VALUES(reason),
                            updated_at = NOW()
                    ");
                } else {
                    $reqStmt = $db->prepare("
                        INSERT INTO user_edit_requests (user_id, req_date, status, reason)
                        VALUES (?, ?, 'pending', ?)
                        ON DUPLICATE KEY UPDATE
                            status = 'pending',
                            reason = VALUES(reason),
                            updated_at = NOW()
                    ");
                }
                $reqStmt->execute([$userId, $date, $reason]);

                $adminStmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin','super_admin') AND is_active = 1");
                $adminStmt->execute();
                $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
                $userName = $_SESSION['full_name'] ?? 'User';
                $msg = $userName . " requested edit approval for time log on " . $date;
                $link = "/modules/admin/edit_requests.php";
                foreach ($admins as $admin) {
                    $nStmt = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'edit_request', ?, ?)");
                    $nStmt->execute([$admin['id'], $msg, $link]);
                }

                $_SESSION['success'] = "Edit request sent to admin for approval. Your log will be applied after approval.";
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;
            }
        }
        $db->beginTransaction();
        
        // Check if enhanced columns exist
        $columnsExist = false;
        try {
            $checkStmt = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'task_type'");
            $columnsExist = $checkStmt->rowCount() > 0;
        } catch (Exception $e) {
            $columnsExist = false;
        }
        

        
        // If multiple pages are selected, create separate entries for each page
        if ($taskType === 'page_testing' && !empty($pageIds)) {

            foreach ($pageIds as $pageId) {

                if ($columnsExist) {
                    // Insert with enhanced columns
                    $stmt = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, issue_id, task_type, phase_id, generic_category_id, testing_type, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $testingType = isset($_POST['testing_type']) ? $_POST['testing_type'] : null;

                    // Adjust hours for multiple pages (divide equally)
                    $adjustedHours = count($pageIds) > 1 ? $hours / count($pageIds) : $hours;

                    $stmt->execute([$userId, $projectId, $pageId, $envId, $issueId, $taskType, $phaseId, $genericCategoryId, $testingType, $date, $adjustedHours, $desc, $isUtilized]);
                    $newLogId = (int)$db->lastInsertId();
                    recordProjectTimeLogHistory($db, [
                        'time_log_id' => $newLogId,
                        'project_id' => $projectId,
                        'user_id' => $userId,
                        'action_type' => 'created',
                        'new_log_date' => $date,
                        'new_hours' => $adjustedHours,
                        'new_description' => $desc,
                        'changed_by' => $userId,
                        'context_json' => json_encode([
                            'task_type' => $taskType,
                            'environment_id' => $envId,
                            'page_id' => $pageId,
                            'issue_id' => $issueId,
                            'testing_type' => $testingType
                        ], JSON_UNESCAPED_UNICODE)
                    ]);

                } else {
                    // Insert with basic columns
                    $stmt = $db->prepare("
                        INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, log_date, hours_spent, description, is_utilized)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    // Adjust hours for multiple pages (divide equally)
                    $adjustedHours = count($pageIds) > 1 ? $hours / count($pageIds) : $hours;
                    
                    $stmt->execute([$userId, $projectId, $pageId, $envId, $date, $adjustedHours, $desc, $isUtilized]);
                    $newLogId = (int)$db->lastInsertId();
                    recordProjectTimeLogHistory($db, [
                        'time_log_id' => $newLogId,
                        'project_id' => $projectId,
                        'user_id' => $userId,
                        'action_type' => 'created',
                        'new_log_date' => $date,
                        'new_hours' => $adjustedHours,
                        'new_description' => $desc,
                        'changed_by' => $userId,
                        'context_json' => json_encode([
                            'task_type' => $taskType,
                            'environment_id' => $envId,
                            'page_id' => $pageId
                        ], JSON_UNESCAPED_UNICODE)
                    ]);

                }
            }
        } else {

            // Single entry for non-page tasks or single page
            $pageId = !empty($pageIds) ? $pageIds[0] : null;
            

            if ($columnsExist) {
                // Insert with enhanced columns
                $stmt = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, issue_id, task_type, phase_id, generic_category_id, testing_type, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $testingType = isset($_POST['testing_type']) ? $_POST['testing_type'] : null;
                $stmt->execute([$userId, $projectId, $pageId, $envId, $issueId, $taskType, $phaseId, $genericCategoryId, $testingType, $date, $hours, $desc, $isUtilized]);
                $newLogId = (int)$db->lastInsertId();
                recordProjectTimeLogHistory($db, [
                    'time_log_id' => $newLogId,
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'action_type' => 'created',
                    'new_log_date' => $date,
                    'new_hours' => $hours,
                    'new_description' => $desc,
                    'changed_by' => $userId,
                    'context_json' => json_encode([
                        'task_type' => $taskType,
                        'environment_id' => $envId,
                        'page_id' => $pageId,
                        'issue_id' => $issueId,
                        'testing_type' => $testingType
                    ], JSON_UNESCAPED_UNICODE)
                ]);

            } else {
                // Insert with basic columns
                $stmt = $db->prepare("
                    INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, log_date, hours_spent, description, is_utilized)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $projectId, $pageId, $envId, $date, $hours, $desc, $isUtilized]);
                $newLogId = (int)$db->lastInsertId();
                recordProjectTimeLogHistory($db, [
                    'time_log_id' => $newLogId,
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'action_type' => 'created',
                    'new_log_date' => $date,
                    'new_hours' => $hours,
                    'new_description' => $desc,
                    'changed_by' => $userId,
                    'context_json' => json_encode([
                        'task_type' => $taskType,
                        'environment_id' => $envId,
                        'page_id' => $pageId
                    ], JSON_UNESCAPED_UNICODE)
                ]);

            }
        }
        
        // Update project phase actual hours if phase is specified
        if ($phaseId) {
            $updatePhaseStmt = $db->prepare("
                UPDATE project_phases 
                SET actual_hours = actual_hours + ? 
                WHERE id = ? AND project_id = ?
            ");
            $updatePhaseStmt->execute([$hours, $phaseId, $projectId]);
        }
        
        // Update project total actual hours (for utilized hours only)
        if ($isUtilized) {
            // Get current total utilized hours for this project
            $totalStmt = $db->prepare("
                SELECT COALESCE(SUM(hours_spent), 0) 
                FROM project_time_logs 
                WHERE project_id = ? AND is_utilized = 1
            ");
            $totalStmt->execute([$projectId]);
            $totalUtilizedHours = $totalStmt->fetchColumn();
            
            // Update project's total hours (this could be used for tracking)
            $updateProjectStmt = $db->prepare("
                UPDATE projects 
                SET total_hours = ? 
                WHERE id = ?
            ");
            $updateProjectStmt->execute([$totalUtilizedHours, $projectId]);
        }
        
        // Log generic task if applicable
        if ($taskType === 'generic_task' && $genericCategoryId) {
            $genericStmt = $db->prepare("
                INSERT INTO user_generic_tasks (user_id, category_id, task_description, hours_spent, task_date)
                VALUES (?, ?, ?, ?, ?)
            ");
            $genericStmt->execute([$userId, $genericCategoryId, $desc, $hours, $date]);
        }
        
        $db->commit();
        $_SESSION['success'] = "Time logged successfully and project hours updated.";
        
    } catch (Exception $e) {
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $rollbackError) {
            // Ignore rollback errors and keep original exception message for user feedback.
        }
        $_SESSION['error'] = "Error logging time: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

// Handle Delete Log
if (isset($_GET['delete_log_request'])) {
    $logId = (int)$_GET['delete_log_request'];
    if ($logId > 0) {
        if ($isAdmin) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date&delete_log=$logId");
            exit;
        }
        try {
            $logStmt = $db->prepare("SELECT id FROM project_time_logs WHERE id = ? AND user_id = ? AND log_date = ? LIMIT 1");
            $logStmt->execute([$logId, $userId, $date]);
            $existing = $logStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $_SESSION['error'] = "Log not found for selected date.";
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;
            }

            $reason = "Deletion request for time log ID {$logId}";
            $reqStmt = $db->prepare("
                INSERT INTO user_edit_requests (user_id, req_date, request_type, status, reason)
                VALUES (?, ?, 'delete', 'pending', ?)
                ON DUPLICATE KEY UPDATE request_type = 'delete', status = 'pending', reason = VALUES(reason), updated_at = NOW()
            ");
            $reqStmt->execute([$userId, $date, $reason]);

            $delReqStmt = $db->prepare("
                INSERT INTO user_pending_log_deletions (user_id, req_date, log_id, reason, status)
                VALUES (?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE req_date = VALUES(req_date), reason = VALUES(reason), status = 'pending', updated_at = NOW()
            ");
            $delReqStmt->execute([$userId, $date, $logId, $reason]);

            $adminStmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin','super_admin') AND is_active = 1");
            $adminStmt->execute();
            $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
            $userName = $_SESSION['full_name'] ?? 'User';
            $msg = $userName . " requested deletion approval for time log on " . $date . " (Log ID: " . $logId . ")";
            $link = "/modules/admin/edit_requests.php";
            foreach ($admins as $admin) {
                $nStmt = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'edit_request', ?, ?)");
                $nStmt->execute([$admin['id'], $msg, $link]);
            }

            $_SESSION['success'] = "Deletion request sent to admin for approval.";
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to send deletion request: " . $e->getMessage();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

if (isset($_GET['edit_log_request'])) {
    $logId = (int)$_GET['edit_log_request'];
    $newHours = isset($_REQUEST['new_hours']) ? (float)$_REQUEST['new_hours'] : 0;
    $newDescription = trim((string)($_REQUEST['new_description'] ?? ''));
    $newProjectId = isset($_REQUEST['new_project_id']) ? (int)$_REQUEST['new_project_id'] : 0;
    $newTaskTypeInput = trim((string)($_REQUEST['new_task_type'] ?? ''));
    $taskTypeMap = ['regression_testing' => 'regression', 'page_qa' => 'page_testing'];
    $newTaskType = $taskTypeMap[$newTaskTypeInput] ?? $newTaskTypeInput;
    $allowedTaskTypes = ['page_testing', 'project_phase', 'generic_task', 'regression', 'other'];
    if (!in_array($newTaskType, $allowedTaskTypes, true)) {
        $newTaskType = 'other';
    }
    $newPageId = (isset($_REQUEST['new_page_id']) && $_REQUEST['new_page_id'] !== '') ? (int)$_REQUEST['new_page_id'] : null;
    $newEnvironmentId = (isset($_REQUEST['new_environment_id']) && $_REQUEST['new_environment_id'] !== '') ? (int)$_REQUEST['new_environment_id'] : null;
    $newIssueId = (isset($_REQUEST['new_issue_id']) && $_REQUEST['new_issue_id'] !== '') ? (int)$_REQUEST['new_issue_id'] : null;
    $newPhaseId = (isset($_REQUEST['new_phase_id']) && $_REQUEST['new_phase_id'] !== '') ? (int)$_REQUEST['new_phase_id'] : null;
    $newGenericCategoryId = (isset($_REQUEST['new_generic_category_id']) && $_REQUEST['new_generic_category_id'] !== '') ? (int)$_REQUEST['new_generic_category_id'] : null;
    $newTestingType = trim((string)($_REQUEST['new_testing_type'] ?? ''));
    $newPhaseActivity = trim((string)($_REQUEST['new_phase_activity'] ?? ''));
    $newGenericTaskDetail = trim((string)($_REQUEST['new_generic_task_detail'] ?? ''));
    $newIsUtilized = isset($_REQUEST['new_is_utilized']) ? (int)$_REQUEST['new_is_utilized'] : null;
    if ($logId > 0) {
        if ($isAdmin) {
            $_SESSION['error'] = 'Admins can edit logs directly from admin tools.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
            exit;
        }
        if ($newHours <= 0 || $newDescription === '') {
            $_SESSION['error'] = 'Invalid edit request. Hours and description are required.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
            exit;
        }
        try {
            $logStmt = $db->prepare("SELECT * FROM project_time_logs WHERE id = ? AND user_id = ? AND log_date = ? LIMIT 1");
            $logStmt->execute([$logId, $userId, $date]);
            $existingLog = $logStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingLog) {
                $_SESSION['error'] = "Log not found for selected date.";
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;
            }
            if ($newProjectId <= 0) {
                $newProjectId = (int)($existingLog['project_id'] ?? 0);
            }
            if ($newHours <= 0) {
                $newHours = (float)($existingLog['hours_spent'] ?? 0);
            }
            if ($newDescription === '') {
                $newDescription = (string)($existingLog['description'] ?? '');
            }
            if ($newTaskType === 'other' && !empty($existingLog['task_type'])) {
                $newTaskType = (string)$existingLog['task_type'];
            }
            if ($newPageId === null && isset($existingLog['page_id'])) {
                $newPageId = $existingLog['page_id'] !== null ? (int)$existingLog['page_id'] : null;
            }
            if ($newEnvironmentId === null && isset($existingLog['environment_id'])) {
                $newEnvironmentId = $existingLog['environment_id'] !== null ? (int)$existingLog['environment_id'] : null;
            }
            if ($newIssueId === null && isset($existingLog['issue_id'])) {
                $newIssueId = $existingLog['issue_id'] !== null ? (int)$existingLog['issue_id'] : null;
            }
            if ($newPhaseId === null && isset($existingLog['phase_id'])) {
                $newPhaseId = $existingLog['phase_id'] !== null ? (int)$existingLog['phase_id'] : null;
            }
            if ($newGenericCategoryId === null && isset($existingLog['generic_category_id'])) {
                $newGenericCategoryId = $existingLog['generic_category_id'] !== null ? (int)$existingLog['generic_category_id'] : null;
            }
            if ($newTestingType === '' && isset($existingLog['testing_type'])) {
                $newTestingType = (string)$existingLog['testing_type'];
            }
            if ($newIsUtilized === null) {
                $newIsUtilized = isset($existingLog['is_utilized']) ? (int)$existingLog['is_utilized'] : 1;
            }
            if ($newProjectId <= 0 || $newHours <= 0 || $newDescription === '') {
                $_SESSION['error'] = 'Invalid edit request. Required fields are missing.';
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;
            }

            $reason = "Edit request for time log ID {$logId}";
            $reqStmt = $db->prepare("
                INSERT INTO user_edit_requests (user_id, req_date, request_type, status, reason)
                VALUES (?, ?, 'edit', 'pending', ?)
                ON DUPLICATE KEY UPDATE request_type = 'edit', status = 'pending', reason = VALUES(reason), updated_at = NOW()
            ");
            $reqStmt->execute([$userId, $date, $reason]);

            $editReqStmt = $db->prepare("
                INSERT INTO user_pending_log_edits (
                    user_id, req_date, log_id, new_hours, new_description, new_project_id, new_task_type,
                    new_page_id, new_environment_id, new_issue_id, new_phase_id, new_generic_category_id,
                    new_testing_type, new_phase_activity, new_generic_task_detail, new_is_utilized, reason, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE
                    req_date = VALUES(req_date),
                    new_hours = VALUES(new_hours),
                    new_description = VALUES(new_description),
                    new_project_id = VALUES(new_project_id),
                    new_task_type = VALUES(new_task_type),
                    new_page_id = VALUES(new_page_id),
                    new_environment_id = VALUES(new_environment_id),
                    new_issue_id = VALUES(new_issue_id),
                    new_phase_id = VALUES(new_phase_id),
                    new_generic_category_id = VALUES(new_generic_category_id),
                    new_testing_type = VALUES(new_testing_type),
                    new_phase_activity = VALUES(new_phase_activity),
                    new_generic_task_detail = VALUES(new_generic_task_detail),
                    new_is_utilized = VALUES(new_is_utilized),
                    reason = VALUES(reason),
                    status = 'pending',
                    updated_at = NOW()
            ");
            $editReqStmt->execute([
                $userId, $date, $logId, $newHours, $newDescription, $newProjectId, $newTaskType,
                $newPageId, $newEnvironmentId, $newIssueId, $newPhaseId, $newGenericCategoryId,
                ($newTestingType !== '' ? $newTestingType : null),
                ($newPhaseActivity !== '' ? $newPhaseActivity : null),
                ($newGenericTaskDetail !== '' ? $newGenericTaskDetail : null),
                $newIsUtilized,
                $reason
            ]);

            $adminStmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin','super_admin') AND is_active = 1");
            $adminStmt->execute();
            $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
            $userName = $_SESSION['full_name'] ?? 'User';
            $msg = $userName . " requested edit approval for time log on " . $date . " (Log ID: " . $logId . ")";
            $link = "/modules/admin/edit_requests.php";
            foreach ($admins as $admin) {
                $nStmt = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'edit_request', ?, ?)");
                $nStmt->execute([$admin['id'], $msg, $link]);
            }

            $_SESSION['success'] = "Edit request sent to admin for approval.";
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to send edit request: " . $e->getMessage();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

if (isset($_GET['delete_log'])) {
    $logId = $_GET['delete_log'];
    if (!$isAdmin) {
        $_SESSION['error'] = 'Delete request must be approved by admin.';
        header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
        exit;
    }
    // Prevent deletion for any past date unless admin or approved request
    $canDelete = true;
    if (!$isAdmin) {
        $today = date('Y-m-d');
        // Any date before today requires approved edit request
        if ($date < $today) {
            $reqCheck = $db->prepare("
                SELECT status
                FROM user_edit_requests
                WHERE user_id = ?
                  AND req_date = ?
                  AND status = 'approved'
                  AND (reason IS NULL OR reason NOT LIKE 'Deletion request for time log ID %')
            ");
            $reqCheck->execute([$userId, $date]);
            $approved = $reqCheck->fetch(PDO::FETCH_ASSOC);
            if (!$approved) $canDelete = false;
        }
    }
    if (!$canDelete) {
        $_SESSION['error'] = 'Cannot delete logs for past dates without admin approval request.';
        header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
        exit;
    }

    try {
        $db->beginTransaction();
        $logStmt = $db->prepare("SELECT * FROM project_time_logs WHERE id = ? AND user_id = ? LIMIT 1");
        $logStmt->execute([$logId, $userId]);
        $existingLog = $logStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingLog) {
            $db->prepare("DELETE FROM project_time_logs WHERE id = ? AND user_id = ?")->execute([$logId, $userId]);
            recordProjectTimeLogHistory($db, [
                'time_log_id' => (int)$existingLog['id'],
                'project_id' => (int)$existingLog['project_id'],
                'user_id' => (int)$existingLog['user_id'],
                'action_type' => 'deleted',
                'old_log_date' => $existingLog['log_date'] ?? null,
                'old_hours' => $existingLog['hours_spent'] ?? null,
                'old_description' => $existingLog['description'] ?? null,
                'changed_by' => $userId,
                'context_json' => json_encode([
                    'page_id' => $existingLog['page_id'] ?? null,
                    'environment_id' => $existingLog['environment_id'] ?? null,
                    'issue_id' => $existingLog['issue_id'] ?? null,
                    'task_type' => $existingLog['task_type'] ?? null
                ], JSON_UNESCAPED_UNICODE)
            ]);
            $_SESSION['success'] = "Log deleted.";
        } else {
            $_SESSION['error'] = "Log not found.";
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "Error deleting log: " . $e->getMessage();
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
    exit;
}

// AJAX: return status and personal note for given date (supports admin querying other users via user_id)
if (isset($_GET['action']) && $_GET['action'] === 'get_personal_note') {
    $queriedDate = $_GET['date'] ?? $date;
    $targetUser = $userId;
    if ($isAdmin && isset($_GET['user_id']) && $_GET['user_id'] !== '') {
        $targetUser = intval($_GET['user_id']);
    }



    // Check if there's a pending edit request for this date
    $editRequestStmt = $db->prepare("SELECT status FROM user_edit_requests WHERE user_id = ? AND req_date = ? AND request_type = 'edit'");
    $editRequestStmt->execute([$targetUser, $queriedDate]);
    $editRequest = $editRequestStmt->fetch(PDO::FETCH_ASSOC);
    $hasPendingRequest = ($editRequest && $editRequest['status'] === 'pending');



    // If there's a pending request, load pending changes instead of current data
    if ($hasPendingRequest) {
        $pendingData = null;
        try {
            $pendingStmt = $db->prepare("SELECT * FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
            $pendingStmt->execute([$targetUser, $queriedDate]);
            $pendingData = $pendingStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pendingData = null;
        }
        
        if ($pendingData) {
            // Get user role
            $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $roleStmt->execute([$targetUser]);
            $userRole = $roleStmt->fetchColumn();
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'status' => $pendingData['status'],
                'notes' => $pendingData['notes'],
                'personal_note' => $pendingData['personal_note'],
                'role' => $userRole,
                'is_pending' => true
            ]);
            exit;
        }
    }

    // Load current/approved data
    $statusStmt = $db->prepare("SELECT uds.*, u.role FROM user_daily_status uds JOIN users u ON uds.user_id = u.id WHERE uds.user_id = ? AND uds.status_date = ?");
    $statusStmt->execute([$targetUser, $queriedDate]);
    $currentStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);

    $noteStmt = $db->prepare("SELECT content FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
    $noteStmt->execute([$targetUser, $queriedDate]);
    $personalNoteRow = $noteStmt->fetch(PDO::FETCH_ASSOC);
    $personalNote = $personalNoteRow ? $personalNoteRow['content'] : '';



    // Get user role if status doesn't exist
    $userRole = $currentStatus['role'] ?? null;
    if (!$userRole) {
        $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $roleStmt->execute([$targetUser]);
        $userRole = $roleStmt->fetchColumn();
    }

    $response = [
        'success' => true,
        'status' => $currentStatus['status'] ?? null,
        'notes' => $currentStatus['notes'] ?? null,
        'personal_note' => $personalNote,
        'role' => $userRole,
        'is_pending' => false,
        'debug_info' => [
            'queried_date' => $queriedDate,
            'target_user' => $targetUser,
            'current_user' => $userId,
            'has_pending_request' => $hasPendingRequest,
            'status_found' => !empty($currentStatus)
        ]
    ];



    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Handle AJAX: check if edit request is pending or approved for this date
if (isset($_GET['action']) && $_GET['action'] === 'check_edit_request') {
    $reqDate = $_GET['date'] ?? $date;
    $stmt = $db->prepare("
        SELECT status
        FROM user_edit_requests
        WHERE user_id = ?
          AND req_date = ?
          AND request_type = 'edit'
    ");
    $stmt->execute([$userId, $reqDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $pending = ($row && $row['status'] === 'pending');
    $approved = ($row && $row['status'] === 'approved');
    header('Content-Type: application/json');
    echo json_encode(['pending' => $pending, 'approved' => $approved]);
    exit;
}

// Get Current Status
$statusStmt = $db->prepare("SELECT * FROM user_daily_status WHERE user_id = ? AND status_date = ?");
$statusStmt->execute([$userId, $date]);
$currentStatus = $statusStmt->fetch();

// Get personal note for this date (if any)
$noteStmt = $db->prepare("SELECT content FROM user_calendar_notes WHERE user_id = ? AND note_date = ?");
$noteStmt->execute([$userId, $date]);
$personalNoteRow = $noteStmt->fetch();
$personalNote = $personalNoteRow ? $personalNoteRow['content'] : '';

// Check if there's a pending edit request for this user/date to adjust UI
$editReqStmt = $db->prepare("SELECT * FROM user_edit_requests WHERE user_id = ? AND req_date = ? AND request_type = 'edit'");
$editReqStmt->execute([$userId, $date]);
$editReq = $editReqStmt->fetch(PDO::FETCH_ASSOC);
$hasPendingRequest = ($editReq && $editReq['status'] === 'pending');
$pendingData = null;
if ($hasPendingRequest) {
    try {
        $pendingStmt = $db->prepare("SELECT * FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
        $pendingStmt->execute([$userId, $date]);
        $pendingData = $pendingStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $pendingData = null;
    }
}

// Is this a past date (and non-admin)? used to make fields readonly initially
$isPastDateReadonly = (!$isAdmin && $date < date('Y-m-d'));
$selectedDateLabel = date('M d, Y', strtotime($date));
$isViewingToday = ($date === date('Y-m-d'));

// Get Time Logs
$logsStmt = $db->prepare("
    SELECT ptl.*, p.title, p.po_number
    FROM project_time_logs ptl
    LEFT JOIN projects p ON ptl.project_id = p.id
    WHERE ptl.user_id = ? AND ptl.log_date = ?
");
$logsStmt->execute([$userId, $date]);
$logs = $logsStmt->fetchAll();

$pendingDeletionLogIds = [];
try {
    $pendingDelStmt = $db->prepare("SELECT log_id FROM user_pending_log_deletions WHERE user_id = ? AND req_date = ? AND status = 'pending'");
    $pendingDelStmt->execute([$userId, $date]);
    $pendingDeletionLogIds = array_map('intval', $pendingDelStmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {
    $pendingDeletionLogIds = [];
}

$pendingEditLogIds = [];
try {
    $pendingEditStmt = $db->prepare("SELECT log_id FROM user_pending_log_edits WHERE user_id = ? AND req_date = ? AND status = 'pending'");
    $pendingEditStmt->execute([$userId, $date]);
    $pendingEditLogIds = array_map('intval', $pendingEditStmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {
    $pendingEditLogIds = [];
}

// Get assigned projects for this user.
// Include all active project statuses (exclude cancelled/archived),
// and include assignment paths used across the app (team/page/env/unique page mappings).
$hasProjectPageAtIdsJson = false;
$hasProjectPageFtIdsJson = false;
try {
    $colStmt = $db->query("SHOW COLUMNS FROM project_pages LIKE 'at_tester_ids'");
    $hasProjectPageAtIdsJson = ($colStmt && $colStmt->rowCount() > 0);
} catch (Exception $e) {
    $hasProjectPageAtIdsJson = false;
}
try {
    $colStmt = $db->query("SHOW COLUMNS FROM project_pages LIKE 'ft_tester_ids'");
    $hasProjectPageFtIdsJson = ($colStmt && $colStmt->rowCount() > 0);
} catch (Exception $e) {
    $hasProjectPageFtIdsJson = false;
}

$jsonUniqueMembershipSql = '';
$jsonUniqueMembershipParams = [];
if ($hasProjectPageAtIdsJson) {
    $jsonUniqueMembershipSql .= " OR JSON_CONTAINS(COALESCE(up.at_tester_ids, JSON_ARRAY()), JSON_ARRAY(CAST(? AS UNSIGNED)))";
    $jsonUniqueMembershipParams[] = $userId;
}
if ($hasProjectPageFtIdsJson) {
    $jsonUniqueMembershipSql .= " OR JSON_CONTAINS(COALESCE(up.ft_tester_ids, JSON_ARRAY()), JSON_ARRAY(CAST(? AS UNSIGNED)))";
    $jsonUniqueMembershipParams[] = $userId;
}

$projectsSql = "
    SELECT DISTINCT
        p.id,
        p.title,
        p.po_number,
        ua.role
    FROM projects p
    LEFT JOIN user_assignments ua
        ON p.id = ua.project_id
       AND ua.user_id = ?
       AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    WHERE p.status NOT IN ('cancelled', 'archived')
      AND (
            ua.id IS NOT NULL
            OR p.project_lead_id = ?
            OR EXISTS (
                SELECT 1
                FROM project_pages pp
                WHERE pp.project_id = p.id
                  AND (
                        pp.at_tester_id = ?
                        OR pp.ft_tester_id = ?
                        OR pp.qa_id = ?
                      )
            )
            OR EXISTS (
                SELECT 1
                FROM project_pages up
                WHERE up.project_id = p.id
                  AND (
                        up.at_tester_id = ?
                        OR up.ft_tester_id = ?
                        OR up.qa_id = ?
                        {$jsonUniqueMembershipSql}
                      )
            )
            OR EXISTS (
                SELECT 1
                FROM project_pages pp2
                JOIN page_environments pe ON pe.page_id = pp2.id
                WHERE pp2.project_id = p.id
                  AND (
                        pe.at_tester_id = ?
                        OR pe.ft_tester_id = ?
                        OR pe.qa_id = ?
                      )
            )
            OR p.po_number = 'OFF-PROD-001'
      )
    ORDER BY (p.po_number = 'OFF-PROD-001') DESC, p.title
";

$projectsStmt = $db->prepare($projectsSql);
$projectParams = [
    $userId,
    $userId,
    $userId, $userId, $userId,
    $userId, $userId, $userId
];
if (!empty($jsonUniqueMembershipParams)) {
    $projectParams = array_merge($projectParams, $jsonUniqueMembershipParams);
}
$projectParams = array_merge($projectParams, [
    $userId, $userId, $userId
]);

$projectsStmt->execute($projectParams);
$assignedProjects = $projectsStmt->fetchAll();

$offProdProjectId = 0;
foreach ($assignedProjects as $p) {
    if (strcasecmp(trim((string)($p['po_number'] ?? '')), 'OFF-PROD-001') === 0) {
        $offProdProjectId = (int)$p['id'];
        break;
    }
}
if ($offProdProjectId <= 0) {
    $offProdProjectId = ensureOffProdProjectId($db, $userId);
}

// Note: If no projects assigned, ensure OFF-PROD is available.
// The SQL above handles it if OFF-PROD is 'in_progress'. 
// If OFF-PROD is not showing up for some reason (e.g. status), check the DB. 
// We inserted OFF-PROD with 'in_progress'.

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Daily Status & Time Log</h2>
        <div>
            <input type="date" class="form-control" value="<?php echo $date; ?>" 
                   onchange="window.location.href='?date='+this.value">
        </div>
    </div>

    <div class="row">
        <!-- Status Section -->
        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-header bg-info text-dark">
                    <h5 class="mb-0">My Status (<?php echo date('M d', strtotime($date)); ?>)</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="statusForm">
                        <div class="mb-3">
                            <label>Availability</label>
                            <select name="status" class="form-select" id="statusSelect" <?php echo $isPastDateReadonly ? 'disabled' : ''; ?>>
                                <?php foreach ($availabilityStatuses as $st): ?>
                                    <?php $stKey = (string)($st['status_key'] ?? ''); ?>
                                    <?php if ($stKey === '') continue; ?>
                                    <option value="<?php echo htmlspecialchars($stKey); ?>" <?php echo (($currentStatus['status'] ?? '') === $stKey) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string)($st['status_label'] ?? ucfirst(str_replace('_', ' ', $stKey)))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2" id="notesField" <?php echo $isPastDateReadonly ? 'disabled' : ''; ?>><?php echo htmlspecialchars($currentStatus['notes'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3" id="personalNoteContainer" style="display: <?php echo $isPastDateReadonly ? 'none' : 'block'; ?>;">
                            <label>Personal Note (private)</label>
                            <textarea name="personal_note" id="personal_note" class="form-control" rows="2"><?php echo htmlspecialchars($personalNote); ?></textarea>
                        </div>

                        <?php if ($isPastDateReadonly): ?>
                            <?php if ($hasPendingRequest): ?>
                                <div class="alert alert-warning">An edit request is pending for this date. Your pending changes will be shown once admin reviews.</div>
                            <?php else: ?>
                                <div class="alert alert-secondary">This date is read-only. Click <button type="button" id="editToggleBtn" class="btn btn-sm btn-outline-primary">Edit</button> to make changes and submit an edit request to admins.</div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <button type="submit" name="update_status" id="updateStatusBtn" class="btn btn-info text-dark w-100" style="<?php echo $isPastDateReadonly ? 'display:none;' : ''; ?>">Update Status</button>
                        <button type="button" id="saveRequestBtn" class="btn btn-warning text-dark w-100" style="display:none;">Save & Request Edit</button>
                    </form>
                </div>
            </div>
    
            <?php if ($personalNote): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0">Your Personal Note for <?php echo date('M d, Y', strtotime($date)); ?></h6>
                </div>
                <div class="card-body">
                    <p><?php echo nl2br(htmlspecialchars($personalNote)); ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Time Summary</h5>
                </div>
                <div class="card-body">
                    <?php
                    $total = 0;
                    $utilized = 0;
                    foreach ($logs as $l) {
                        $total += $l['hours_spent'];
                        if ($l['is_utilized']) $utilized += $l['hours_spent'];
                    }
                    ?>
                    <h3 class="text-center"><?php echo $total; ?> hrs</h3>
                    <div class="progress mb-2">
                        <?php 
                        $utilPct = $total > 0 ? ($utilized / $total) * 100 : 0;
                        ?>
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $utilPct; ?>%">
                            Utilized
                        </div>
                        <div class="progress-bar bg-secondary" role="progressbar" style="width: <?php echo 100 - $utilPct; ?>%">
                            Bench/Off
                        </div>
                    </div>
                    <p class="text-center small text-muted">
                        Utilized: <?php echo $utilized; ?>h | Off-Prod: <?php echo $total - $utilized; ?>h
                    </p>
                </div>
            </div>
        </div>

        <!-- Time Logs Section -->
        <div class="col-md-8">
            <!-- Production Hours Section -->
            <div class="card mb-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Log Production Hours</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row align-items-end mb-4" id="logProductionHoursForm">
                        <div class="col-md-3">
                            <label>Project</label>
                            <select name="project_id" class="form-select" required>
                                <option value="">Select Project</option>
                                <?php foreach ($assignedProjects as $p): ?>
                                    <?php if ($p['po_number'] !== 'OFF-PROD-001'): ?>
                                    <option value="<?php echo $p['id']; ?>">
                                        <?php echo $p['title']; ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Task Type</label>
                            <select name="task_type" id="taskTypeSelect" class="form-select" required>
                                <option value="">Select Task Type</option>
                                <option value="page_testing">Page Testing</option>
                                <option value="page_qa">Page QA</option>
                                <option value="regression_testing">Regression Testing</option>
                                <option value="project_phase">Project Phase</option>
                                <option value="generic_task">Generic Task</option>
                            </select>
                        </div>
                        
                        <!-- Page Testing Options -->
                        <div class="col-md-12 mt-2" id="pageTestingContainer" style="display:none;">
                            <div class="row">
                                <div class="col-md-4">
                                    <label>Page/Screen (Multiple)</label>
                                    <select name="page_ids[]" id="productionPageSelect" class="form-select" multiple size="4">
                                        <option value="">Select pages</option>
                                    </select>
                                    <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                                </div>
                                <div class="col-md-4">
                                    <label>Environments (Multiple)</label>
                                    <select name="environment_ids[]" id="productionEnvSelect" class="form-select" multiple size="3">
                                        <option value="">Select environments</option>
                                    </select>
                                    <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                                </div>
                                <div class="col-md-4">
                                    <label>Testing Type</label>
                                    <select name="testing_type" id="testingTypeSelect" class="form-select">
                                        <option value="at_testing">AT Testing</option>
                                        <option value="ft_testing">FT Testing</option>
                                    </select>
                                </div>
                                <div class="col-md-4" id="productionIssueContainer" style="display:none;">
                                    <label>Issue (optional)</label>
                                    <select name="issue_id" id="productionIssueSelect" class="form-select">
                                        <option value="">Select issue (optional)</option>
                                    </select>
                                    <small class="text-muted">Select an issue when logging regression hours</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Regression Options -->
                        <div class="col-md-12 mt-2" id="regressionContainer" style="display:none;">
                            <div class="row">
                                <div class="col-md-12">
                                    <label>Regression Summary</label>
                                    <div id="regressionSummary" class="border rounded p-2">
                                        Loading…
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Project Phase Options -->
                        <div class="col-md-12 mt-2" id="projectPhaseContainer" style="display:none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <label>Project Phase</label>
                                    <select name="phase_id" id="projectPhaseSelect" class="form-select">
                                        <option value="">Select project phase</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label>Phase Activity</label>
                                    <select name="phase_activity" class="form-select">
                                        <option value="scoping">Scoping & Analysis</option>
                                        <option value="setup">Setup & Configuration</option>
                                        <option value="testing">Testing Activities</option>
                                        <option value="review">Review & Documentation</option>
                                        <option value="training">Training & Knowledge Transfer</option>
                                        <option value="reporting">Reporting & VPAT</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Generic Task Options -->
                        <div class="col-md-12 mt-2" id="genericTaskContainer" style="display:none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <label>Task Category</label>
                                    <select name="generic_category_id" id="genericCategorySelect" class="form-select">
                                        <option value="">Select category</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label>Task Details</label>
                                    <input type="text" name="generic_task_detail" class="form-control" placeholder="Specific task details">
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-2 mt-2">
                            <label>Hours</label>
                            <input type="number" id="logHoursInput" name="hours_spent" class="form-control" step="0.25" min="0.25" max="24" required <?php echo $isPastDateReadonly ? 'disabled' : ''; ?> >
                        </div>
                        <div class="col-md-4 mt-2">
                            <label>Description</label>
                            <input type="text" id="logDescriptionInput" name="description" class="form-control" placeholder="What did you work on?" required <?php echo $isPastDateReadonly ? 'disabled' : ''; ?> >
                        </div>
                        <div class="col-md-2 mt-2 d-grid">
                            <button type="submit" id="logTimeBtn" name="log_time" class="btn btn-success w-100" <?php echo $isPastDateReadonly ? 'disabled' : ''; ?>>Log Hours</button>
                        </div>
                    </form>
                    <script>
                    (function(){
                        const isPast = <?php echo $isPastDateReadonly ? 'true' : 'false'; ?>;
                        const hasPending = <?php echo $hasPendingRequest ? 'true' : 'false'; ?>;
                        if (!isPast) return;

                        const editBtn = document.getElementById('editToggleBtn');
                        const saveBtn = document.getElementById('saveRequestBtn');
                        const updateBtn = document.getElementById('updateStatusBtn');
                        const statusSelect = document.getElementById('statusSelect');
                        const notesField = document.getElementById('notesField');
                        const personalNote = document.getElementById('personal_note');

                        function setEditable(on){
                            if (statusSelect) statusSelect.disabled = !on;
                            if (notesField) notesField.disabled = !on;
                            if (personalNote) personalNote.disabled = !on;
                            // Time-log fields
                            var prodProj = document.querySelector('#logProductionHoursForm select[name="project_id"]');
                            var pageSel = document.getElementById('productionPageSelect');
                            var envSel = document.getElementById('productionEnvSelect');
                            var testingSel = document.getElementById('testingTypeSelect');
                            var issueSel = document.getElementById('productionIssueSelect');
                            var hoursInput = document.getElementById('logHoursInput');
                            var descInput = document.getElementById('logDescriptionInput');
                            var logBtn = document.getElementById('logTimeBtn');
                            if (prodProj) prodProj.disabled = !on;
                            if (pageSel) pageSel.disabled = !on;
                            if (envSel) envSel.disabled = !on;
                            if (testingSel) testingSel.disabled = !on;
                            if (issueSel) issueSel.disabled = !on;
                            if (hoursInput) hoursInput.disabled = !on;
                            if (descInput) descInput.disabled = !on;
                            if (logBtn) logBtn.disabled = !on;
                            if (on) {
                                saveBtn.style.display = 'block';
                                if (updateBtn) updateBtn.style.display = 'none';
                                document.getElementById('personalNoteContainer').style.display = 'block';
                            } else {
                                saveBtn.style.display = 'none';
                                if (updateBtn) updateBtn.style.display = 'none';
                                document.getElementById('personalNoteContainer').style.display = 'none';
                            }
                        }

                        if (hasPending) {
                            // Load pending values via AJAX and show them read-only
                            fetch(window.location.pathname + '?action=get_personal_note&date=' + encodeURIComponent('<?php echo $date; ?>'))
                                .then(r => r.json())
                                .then(data => {
                                    if (data.success && data.is_pending) {
                                        if (statusSelect) statusSelect.value = data.status || statusSelect.value;
                                        if (notesField) notesField.value = data.notes || '';
                                        if (personalNote) personalNote.value = data.personal_note || '';
                                    }
                                }).catch(()=>{});
                            // leave fields disabled
                            setEditable(false);
                        } else {
                            // No pending request; fields are readonly until Edit clicked
                            setEditable(false);
                        }

                        if (editBtn) {
                            editBtn.addEventListener('click', function(){
                                setEditable(true);
                                editBtn.style.display = 'none';
                            });
                        }

                        if (saveBtn) {
                            saveBtn.addEventListener('click', function(){
                                // gather values and POST to save_pending then request_edit
                                const fd = new FormData();
                                fd.append('action','save_pending');
                                fd.append('date','<?php echo $date; ?>');
                                fd.append('status', statusSelect ? statusSelect.value : '');
                                fd.append('notes', notesField ? notesField.value : '');
                                fd.append('personal_note', personalNote ? personalNote.value : '');
                                // capture current time-log form values as pending time log (single entry from the quick form)
                                try {
                                    var pendingLogs = [];
                                    var proj = document.querySelector('#logProductionHoursForm select[name="project_id"]');
                                    if (proj) {
                                        var entry = {
                                            project_id: proj.value || null,
                                            task_type: document.querySelector('#logProductionHoursForm select[name="task_type"]')?.value || null,
                                            page_ids: Array.from(document.querySelectorAll('#productionPageSelect option:checked')).map(o => o.value).filter(Boolean),
                                            environment_ids: Array.from(document.querySelectorAll('#productionEnvSelect option:checked')).map(o => o.value).filter(Boolean),
                                            testing_type: document.querySelector('#testingTypeSelect')?.value || null,
                                            issue_id: document.querySelector('#productionIssueSelect')?.value || null,
                                            hours: document.getElementById('logHoursInput') ? document.getElementById('logHoursInput').value : null,
                                            description: document.getElementById('logDescriptionInput') ? document.getElementById('logDescriptionInput').value : null,
                                            is_utilized: document.querySelector('#logProductionHoursForm input[name="is_utilized"]') ? (document.querySelector('#logProductionHoursForm input[name="is_utilized"]').checked ? 1 : 0) : 1
                                        };
                                        pendingLogs.push(entry);
                                    }
                                    fd.append('pending_time_logs', JSON.stringify(pendingLogs));
                                } catch (e) { fd.append('pending_time_logs', '[]'); }

                                saveBtn.disabled = true;
                                saveBtn.textContent = 'Saving...';

                                fetch(window.location.pathname + '?action=save_pending', {method:'POST', body: fd})
                                .then(r => r.json())
                                .then(resp => {
                                    if (resp.success) {
                                        // Now request admin approval
                                        const fd2 = new FormData();
                                        fd2.append('action','request_edit');
                                        fd2.append('date','<?php echo $date; ?>');
                                        fd2.append('reason','User requested edit via calendar');
                                        return fetch(window.location.pathname + '?action=request_edit', {method:'POST', body: fd2});
                                    } else {
                                        throw new Error(resp.error || 'Failed to save pending');
                                    }
                                })
                                .then(r => r.json())
                                .then(resp2 => {
                                    if (resp2.success) {
                                            // Show confirmation and mark readonly
                                            showToast('Edit request submitted to admins. You will be notified when approved.', 'success');
                                            setEditable(false);
                                            // show pending message
                                            location.reload();
                                        } else {
                                            throw new Error(resp2.error || 'Failed to request edit');
                                        }
                                })
                                .catch(err => {
                                    showToast('Error: ' + (err.message || 'unknown'), 'danger');
                                })
                                .finally(()=>{
                                    saveBtn.disabled = false;
                                    saveBtn.textContent = 'Save & Request Edit';
                                });
                            });
                        }
                    })();
                    </script>
                </div>
            </div>

            <!-- Off-Production/Bench Hours Section -->
            <div class="card mb-3">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Log Off-Production/Bench Hours</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row align-items-end mb-4" id="logBenchHoursForm">
                        <input type="hidden" name="project_id" value="<?php echo (int)$offProdProjectId; ?>">
                        <div class="col-md-4">
                            <label>Activity Type</label>
                            <select name="bench_activity" class="form-select" required>
                                <option value="">Select Activity</option>
                                <option value="training">Training</option>
                                <option value="learning">Learning/Research</option>
                                <option value="documentation">Documentation</option>
                                <option value="meetings">Meetings</option>
                                <option value="admin">Administrative Tasks</option>
                                <option value="waiting">Waiting for Assignment</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Hours</label>
                            <input type="number" name="hours_spent" class="form-control" step="0.5" min="0.5" max="24" required>
                        </div>
                        <div class="col-md-4">
                            <label>Description</label>
                            <input type="text" name="description" class="form-control" placeholder="Describe the activity" required>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" name="log_time" class="btn btn-secondary w-100">Log</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Logged Hours Display -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        Logged <?php echo $isViewingToday ? 'Today' : 'for ' . htmlspecialchars($selectedDateLabel); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Production Hours -->
                    <?php 
                    $productionLogs = array_filter($logs, function($log) { 
                        return (int)($log['is_utilized'] ?? 1) === 1;
                    });
                    $benchLogs = array_filter($logs, function($log) { 
                        return (int)($log['is_utilized'] ?? 1) === 0;
                    });
                    ?>
                    
                    <?php if (!empty($productionLogs)): ?>
                    <h6 class="text-success">Production Hours</h6>
                    <table class="table table-striped table-sm mb-4">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Page/Task</th>
                                <th>Environment</th>
                                <th>Description</th>
                                <th>Hours</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productionLogs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['title']); ?></td>
                                <td>
                                    <?php 
                                    if ($log['page_id']) {
                                        // Get page name
                                        $pageStmt = $db->prepare("SELECT page_name FROM project_pages WHERE id = ?");
                                        $pageStmt->execute([$log['page_id']]);
                                        $pageName = $pageStmt->fetchColumn();
                                        echo htmlspecialchars($pageName ?: 'Page #' . $log['page_id']);
                                        
                                        // Check if this is part of multiple pages (same description and time)
                                        $multiPageStmt = $db->prepare("
                                            SELECT COUNT(*) as count, GROUP_CONCAT(pp.page_name SEPARATOR ', ') as page_names
                                            FROM project_time_logs ptl 
                                            JOIN project_pages pp ON ptl.page_id = pp.id
                                            WHERE ptl.user_id = ? AND ptl.log_date = ? AND ptl.description = ? AND ptl.hours_spent = ?
                                        ");
                                        $multiPageStmt->execute([$userId, $date, $log['description'], $log['hours_spent']]);
                                        $multiPageResult = $multiPageStmt->fetch();
                                        
                                        if ($multiPageResult['count'] > 1) {
                                            echo '<br><small class="text-muted">+ ' . ($multiPageResult['count'] - 1) . ' more pages</small>';
                                        }
                                    } else {
                                        // Check if it's a project phase or generic task
                                        $desc = $log['description'];
                                        if (strpos($desc, 'Phase:') !== false || strpos($desc, 'Scoping') !== false || strpos($desc, 'Training') !== false) {
                                            echo '<em>Project Phase</em>';
                                        } elseif (strpos($desc, 'Generic:') !== false) {
                                            echo '<em>Generic Task</em>';
                                        } else {
                                            echo '<em>General</em>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($log['environment_id']) {
                                        $envStmt = $db->prepare("SELECT name FROM testing_environments WHERE id = ?");
                                        $envStmt->execute([$log['environment_id']]);
                                        $envName = $envStmt->fetchColumn();
                                        echo htmlspecialchars($envName ?: 'Env #' . $log['environment_id']);
                                    } else {
                                        echo '<em>N/A</em>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $desc = htmlspecialchars($log['description']);
                                    // Truncate long descriptions
                                    if (strlen($desc) > 50) {
                                        echo substr($desc, 0, 50) . '...';
                                    } else {
                                        echo $desc;
                                    }
                                    ?>
                                </td>
                                <td><span class="badge bg-success"><?php echo $log['hours_spent']; ?>h</span></td>
                                <td>
                                    <?php if (in_array((int)$log['id'], $pendingEditLogIds, true)): ?>
                                    <span class="text-info small fw-semibold">Waiting for edit approval</span>
                                    <?php elseif (in_array((int)$log['id'], $pendingDeletionLogIds, true)): ?>
                                    <span class="text-warning small fw-semibold">Waiting for deletion approval</span>
                                    <?php else: ?>
                                      <a href="javascript:void(0)"
                                       class="text-primary me-2" onclick="return handleEditLogRequest(<?php echo (int)$log['id']; ?>, '<?php echo $date; ?>', <?php echo htmlspecialchars(json_encode([
                                           'project_id' => (int)($log['project_id'] ?? 0),
                                           'task_type' => (string)($log['task_type'] ?? 'other'),
                                           'page_id' => isset($log['page_id']) ? (int)$log['page_id'] : null,
                                           'environment_id' => isset($log['environment_id']) ? (int)$log['environment_id'] : null,
                                           'issue_id' => isset($log['issue_id']) ? (int)$log['issue_id'] : null,
                                           'phase_id' => isset($log['phase_id']) ? (int)$log['phase_id'] : null,
                                           'generic_category_id' => isset($log['generic_category_id']) ? (int)$log['generic_category_id'] : null,
                                           'testing_type' => (string)($log['testing_type'] ?? ''),
                                           'hours_spent' => (float)($log['hours_spent'] ?? 0),
                                           'description' => (string)($log['description'] ?? ''),
                                           'is_utilized' => isset($log['is_utilized']) ? (int)$log['is_utilized'] : 1
                                       ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="javascript:void(0)" 
                                       class="text-danger" onclick="return handleDeleteLog(<?php echo $log['id']; ?>, '<?php echo $date; ?>')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if (!empty($benchLogs)): ?>
                    <h6 class="text-secondary">Off-Production/Bench Hours</h6>
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Activity</th>
                                <th>Description</th>
                                <th>Hours</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($benchLogs as $log): ?>
                            <tr>
                                <td>
                                    <?php 
                                    // Extract activity type from description or show generic
                                    $desc = $log['description'];
                                    $activityTypes = ['training', 'learning', 'documentation', 'meetings', 'admin', 'waiting', 'other'];
                                    $activity = 'General';
                                    foreach ($activityTypes as $type) {
                                        if (stripos($desc, $type) !== false) {
                                            $activity = ucfirst($type);
                                            break;
                                        }
                                    }
                                    echo htmlspecialchars($activity);
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['description']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo $log['hours_spent']; ?>h</span></td>
                                <td>
                                    <?php if (in_array((int)$log['id'], $pendingEditLogIds, true)): ?>
                                    <span class="text-info small fw-semibold">Waiting for edit approval</span>
                                    <?php elseif (in_array((int)$log['id'], $pendingDeletionLogIds, true)): ?>
                                    <span class="text-warning small fw-semibold">Waiting for deletion approval</span>
                                    <?php else: ?>
                                    <a href="javascript:void(0)"
                                       class="text-primary me-2" onclick="return handleEditLogRequest(<?php echo (int)$log['id']; ?>, '<?php echo $date; ?>', <?php echo htmlspecialchars(json_encode([
                                           'project_id' => (int)($log['project_id'] ?? 0),
                                           'task_type' => (string)($log['task_type'] ?? 'other'),
                                           'page_id' => isset($log['page_id']) ? (int)$log['page_id'] : null,
                                           'environment_id' => isset($log['environment_id']) ? (int)$log['environment_id'] : null,
                                           'issue_id' => isset($log['issue_id']) ? (int)$log['issue_id'] : null,
                                           'phase_id' => isset($log['phase_id']) ? (int)$log['phase_id'] : null,
                                           'generic_category_id' => isset($log['generic_category_id']) ? (int)$log['generic_category_id'] : null,
                                           'testing_type' => (string)($log['testing_type'] ?? ''),
                                           'hours_spent' => (float)($log['hours_spent'] ?? 0),
                                           'description' => (string)($log['description'] ?? ''),
                                           'is_utilized' => isset($log['is_utilized']) ? (int)$log['is_utilized'] : 1
                                       ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="javascript:void(0)" 
                                       class="text-danger" onclick="return handleDeleteLog(<?php echo $log['id']; ?>, '<?php echo $date; ?>')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if (empty($logs)): ?>
                    <p class="text-muted text-center">
                        No hours logged <?php echo $isViewingToday ? 'for today' : 'for ' . htmlspecialchars($selectedDateLabel); ?>.
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    // Production hours form handling
    var productionProjectSelect = document.querySelector('#logProductionHoursForm select[name="project_id"]');
    var taskTypeSelect = document.getElementById('taskTypeSelect');
    var pageTestingContainer = document.getElementById('pageTestingContainer');
    var projectPhaseContainer = document.getElementById('projectPhaseContainer');
    var genericTaskContainer = document.getElementById('genericTaskContainer');
    var regressionContainer = document.getElementById('regressionContainer');
    
    var productionPageSelect = document.getElementById('productionPageSelect');
    var productionEnvSelect = document.getElementById('productionEnvSelect');
    var productionIssueSelect = document.getElementById('productionIssueSelect');
    var projectPhaseSelect = document.getElementById('projectPhaseSelect');
    var genericCategorySelect = document.getElementById('genericCategorySelect');
    var testingTypeSelect = document.getElementById('testingTypeSelect');
    
    var productionDescInput = document.querySelector('#logProductionHoursForm input[name="description"]');

    // Bench hours form handling
    var benchActivitySelect = document.querySelector('#logBenchHoursForm select[name="bench_activity"]');
    var benchDescInput = document.querySelector('#logBenchHoursForm input[name="description"]');

    function clearSelect(sel) {
        if (sel) {
            sel.innerHTML = '<option value="">Select</option>';
        }
    }

    function hideAllTaskContainers() {
        pageTestingContainer.style.display = 'none';
        projectPhaseContainer.style.display = 'none';
        genericTaskContainer.style.display = 'none';
        if (regressionContainer) regressionContainer.style.display = 'none';
    }

    // Task type selection
    if (taskTypeSelect) {
        taskTypeSelect.addEventListener('change', function(){
            var taskType = this.value;
            var projectId = productionProjectSelect ? productionProjectSelect.value : '';
            
            hideAllTaskContainers();
            
            if (!projectId) {
                showToast('Please select a project first', 'warning');
                return;
            }
            
            switch(taskType) {
                case 'page_testing':
                case 'page_qa':
                    pageTestingContainer.style.display = 'block';
                    loadProjectPages();
                    break;
                case 'regression_testing':
                    regressionContainer.style.display = 'block';
                    loadRegressionSummary();
                    break;
                case 'project_phase':
                    projectPhaseContainer.style.display = 'block';
                    loadProjectPhases();
                    break;
                case 'generic_task':
                    genericTaskContainer.style.display = 'block';
                    loadGenericCategories();
                    break;
            }
        });
    }

    // Production project selection
    if (productionProjectSelect) {
        productionProjectSelect.addEventListener('change', function(){
            var projectId = this.value;
            var taskType = taskTypeSelect ? taskTypeSelect.value : '';
            
            clearSelect(productionPageSelect);
            clearSelect(productionEnvSelect);
            clearSelect(projectPhaseSelect);
            
            if (!projectId) {
                hideAllTaskContainers();
                return;
            }
            
            // Show appropriate container based on selected task type
            if (taskType === 'page_testing' || taskType === 'page_qa') {
                pageTestingContainer.style.display = 'block';
                loadProjectPages();
            } else if (taskType === 'regression_testing') {
                regressionContainer.style.display = 'block';
                loadRegressionSummary();
            } else if (taskType === 'project_phase') {
                projectPhaseContainer.style.display = 'block';
                loadProjectPhases();
            } else if (taskType === 'generic_task') {
                genericTaskContainer.style.display = 'block';
                loadGenericCategories();
            }
        });
    }

    function loadProjectPages() {
        var projectId = productionProjectSelect ? productionProjectSelect.value : '';
        if (!projectId) return;
        
        // Show loading message
        productionPageSelect.innerHTML = '<option value="">Loading pages...</option>';
        
        fetch('<?php echo $baseDir; ?>/api/tasks.php?project_id=' + encodeURIComponent(projectId), {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        })
        .then(r => {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status + ': ' + r.statusText);
            }
            return r.json();
        })
        .then(function(pages){
            productionPageSelect.innerHTML = '';
            if (pages && Array.isArray(pages) && pages.length > 0) {
                pages.forEach(function(pg){
                    var opt = document.createElement('option');
                    opt.value = pg.id;
                    // Show only page name, not tester names
                    opt.textContent = pg.page_name || pg.title || pg.url || ('Page ' + pg.id);
                    productionPageSelect.appendChild(opt);
                });
            } else if (pages && pages.error) {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'Error: ' + pages.error;
                productionPageSelect.appendChild(opt);
            } else {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'No pages found for this project';
                productionPageSelect.appendChild(opt);
            }
        }).catch(function(error){ 
            productionPageSelect.innerHTML = '<option value="">Error: ' + error.message + '</option>';
        });
    }

    function loadProjectPhases() {
        var projectId = productionProjectSelect ? productionProjectSelect.value : '';
        if (!projectId) return;
        function formatPhaseLabel(raw) {
            var txt = String(raw || '').trim();
            if (!txt) return '';
            var known = {
                'po_received': 'PO received',
                'scoping_confirmation': 'Scoping confirmation',
                'testing': 'Testing',
                'regression': 'Regression',
                'training': 'Training',
                'vpat_acr': 'VPAT ACR'
            };
            if (known[txt]) return known[txt];
            return txt
                .replace(/[_-]+/g, ' ')
                .split(/\s+/)
                .map(function (w) {
                    var lw = w.toLowerCase();
                    if (lw === 'po') return 'PO';
                    if (lw === 'qa') return 'QA';
                    if (lw === 'uat') return 'UAT';
                    if (lw === 'ui') return 'UI';
                    if (lw === 'ux') return 'UX';
                    if (lw === 'vpat') return 'VPAT';
                    if (lw === 'acr') return 'ACR';
                    return lw.charAt(0).toUpperCase() + lw.slice(1);
                })
                .join(' ');
        }
        
        // Show loading message
        projectPhaseSelect.innerHTML = '<option value="">Loading phases...</option>';
        
        fetch('<?php echo $baseDir; ?>/api/projects.php?action=get_phases&project_id=' + encodeURIComponent(projectId), {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        })
        .then(function(res){
            return res.text().then(function(txt){
                if (!res.ok) throw new Error('HTTP ' + res.status + ': ' + txt);
                try {
                    return JSON.parse(txt);
                } catch (e) {
                    throw new Error('Invalid JSON response: ' + (txt.length > 500 ? txt.slice(0,500) + '...' : txt));
                }
            });
        })
        .then(function(phases){
            projectPhaseSelect.innerHTML = '<option value="">Select project phase</option>';
                if (phases && Array.isArray(phases) && phases.length > 0) {
                phases.forEach(function(phase){
                    var opt = document.createElement('option');
                    opt.value = phase.id;
                    var displayName = formatPhaseLabel(phase.phase_name || phase.name || phase.id);
                    opt.textContent = displayName + ' (' + (phase.actual_hours || 0) + '/' + (phase.planned_hours || 0) + ' hrs)';
                    projectPhaseSelect.appendChild(opt);
                });
            } else if (phases && phases.error) {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'Error: ' + phases.error;
                projectPhaseSelect.appendChild(opt);
            } else {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'No phases found for this project';
                projectPhaseSelect.appendChild(opt);
            }
        }).catch(function(error){ 
            projectPhaseSelect.innerHTML = '<option value="">Error: ' + (error.message || 'Unknown') + '</option>';
        });
    }

    function loadRegressionSummary() {
        function escapeHtml(s){ if(!s) return ''; return String(s).replace(/[&"'<>]/g, function (m) { return ({'&':'&amp;','"':'&quot;','\'':'&#39;','<':'&lt;','>':'&gt;'}[m]); }); }
        var projectId = productionProjectSelect ? productionProjectSelect.value : '';
        var container = document.getElementById('regressionSummary');
        if (!container) return;
        if (!projectId) { container.textContent = 'Select a project to view regression summary'; return; }
        container.textContent = 'Loading...';
        fetch('<?php echo $baseDir; ?>/api/regression_actions.php?action=get_stats&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function(json){
                if (!json || !json.success) {
                    container.textContent = 'Error loading regression summary';
                    return;
                }
                var s = json || {};
                var total = s.issues_total || 0;
                var statusCounts = s.status_counts || {};
                var attemptedTotal = s.attempted_issues_total || 0;
                var attemptedStatus = s.attempted_status_counts || {};

                var html = '<div><strong>Total issues:</strong> ' + total + '</div>';
                html += '<div><strong>Attempted during regression:</strong> ' + attemptedTotal + '</div>';
                html += '<div class="mt-1"><strong>Attempted status breakdown:</strong><br/>';
                if (Object.keys(attemptedStatus).length === 0) {
                    html += '<small class="text-muted">No attempted issues logged yet</small>';
                } else {
                    for (var k in attemptedStatus) {
                        html += '<div>' + k + ': ' + attemptedStatus[k] + '</div>';
                    }
                }
                html += '</div>';

                // also show regression task status counts
                html += '<div class="mt-2"><strong>Regression tasks:</strong><br/>';
                if (Object.keys(statusCounts).length === 0) html += '<small class="text-muted">No regression tasks</small>'; else {
                    for (var st in statusCounts) {
                        html += '<div>' + st + ': ' + statusCounts[st] + '</div>';
                    }
                }
                html += '</div>';

                // per-user attempted issues (if available)
                var attemptsByUser = s.attempts_by_user || {};
                var userCounts = s.user_counts || [];
                var userMap = {};
                userCounts.forEach(function(u){ userMap[u.id] = u.full_name || ('User '+u.id); });
                html += '<div class="mt-2"><strong>Attempts by user:</strong><br/>';
                if (Object.keys(attemptsByUser).length === 0) {
                    html += '<small class="text-muted">No attempts recorded</small>';
                } else {
                    for (var uid in attemptsByUser) {
                        var issues = attemptsByUser[uid] || [];
                        var uname = userMap[uid] || (uid === '0' ? 'System' : ('User '+uid));
                        html += '<div class="mt-1"><strong>' + escapeHtml(uname) + '</strong> — ' + issues.length + ' issue(s)';
                        html += '<div class="ms-3">';
                        issues.forEach(function(it){
                            html += '<div><strong>' + escapeHtml(it.issue_key || ('#'+it.issue_id)) + '</strong> — ' + escapeHtml(it.last_status || '') + ' <small class="text-muted">' + escapeHtml(it.last_changed_at || '') + '</small></div>';
                        });
                        html += '</div></div>';
                    }
                }
                html += '</div>';

                container.innerHTML = html;
            }).catch(function(){ container.textContent = 'Error loading regression summary'; });
    }

    function loadGenericCategories() {
        // Show loading message
        genericCategorySelect.innerHTML = '<option value="">Loading categories...</option>';
        
        fetch('<?php echo $baseDir; ?>/api/generic_tasks.php?action=get_categories', {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        })
        .then(r => {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status + ': ' + r.statusText);
            }
            return r.json();
        })
        .then(function(categories){
            genericCategorySelect.innerHTML = '<option value="">Select category</option>';
            if (categories && Array.isArray(categories) && categories.length > 0) {
                categories.forEach(function(cat){
                    var opt = document.createElement('option');
                    opt.value = cat.id;
                    opt.textContent = cat.name + (cat.description ? ' - ' + cat.description : '');
                    genericCategorySelect.appendChild(opt);
                });
            } else if (categories && categories.error) {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'Error: ' + categories.error;
                genericCategorySelect.appendChild(opt);
            } else {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'No categories found';
                genericCategorySelect.appendChild(opt);
            }
        }).catch(function(error){ 
            genericCategorySelect.innerHTML = '<option value="">Error: ' + error.message + '</option>';
        });
    }

    // Production page selection (multiple pages)
    if (productionPageSelect) {
        productionPageSelect.addEventListener('change', function(){
            var selectedPages = Array.from(this.selectedOptions).map(option => option.value).filter(val => val !== '');
            clearSelect(productionEnvSelect);
            
            if (selectedPages.length === 0) return;
            
            // If multiple pages selected, load environments from the first page
            // (or we could load environments common to all selected pages)
            var firstPageId = selectedPages[0];
            
            fetch('<?php echo $baseDir; ?>/api/tasks.php?page_id=' + encodeURIComponent(firstPageId), {credentials: 'same-origin'})
                .then(r => r.json())
                .then(function(page){
                    productionEnvSelect.innerHTML = '';
                    if (page.environments && page.environments.length > 0) {
                        page.environments.forEach(function(env){
                            var opt = document.createElement('option');
                            opt.value = env.id;
                            opt.textContent = env.name + (env.status ? ' ('+env.status+')' : '');
                            productionEnvSelect.appendChild(opt);
                        });
                    } else {
                        var opt = document.createElement('option');
                        opt.value = '';
                        opt.textContent = 'No environments found';
                        productionEnvSelect.appendChild(opt);
                    }
                    
                    // Pre-fill description with page names for convenience
                    if (productionDescInput && (!productionDescInput.value || productionDescInput.value.trim() === '')) {
                        if (selectedPages.length === 1) {
                            productionDescInput.value = page.page_name || page.title || '';
                        } else {
                            productionDescInput.value = 'Multiple pages testing';
                        }
                    }
                    // Load issues if regression selected
                    if (testingTypeSelect && testingTypeSelect.value === 'regression') {
                        loadProjectIssues(productionProjectSelect.value, firstPageId);
                        var issueCont = document.getElementById('productionIssueContainer');
                        if (issueCont) issueCont.style.display = 'block';
                    } else {
                        var issueCont = document.getElementById('productionIssueContainer');
                        if (issueCont) issueCont.style.display = 'none';
                    }
                    // If regression testing type selected, load issues for this page
                    if (testingTypeSelect && testingTypeSelect.value === 'regression') {
                        loadProjectIssues(projectId, pageId);
                    }
                }).catch(function(error){ 
                    productionEnvSelect.innerHTML = '<option value="">Error loading environments</option>';
                });
        });

    function loadProjectIssues(projectId, pageId) {
        if (!projectId) return;
        if (!productionIssueSelect) return;
        productionIssueSelect.innerHTML = '<option value="">Issues subsystem disabled</option>';
        fetch('<?php echo $baseDir; ?>/api/regression_actions.php?action=get_project_issues&project_id=' + encodeURIComponent(projectId) + (pageId ? '&page_id='+encodeURIComponent(pageId) : ''), {credentials: 'same-origin'})
            .then(r => r.json())
            .then(function(data){
                productionIssueSelect.innerHTML = '<option value="">Select issue (optional)</option>';
                if (data && data.issues && Array.isArray(data.issues)) {
                    data.issues.forEach(function(it){
                        var opt = document.createElement('option');
                        opt.value = it.id;
                        opt.textContent = (it.issue_key ? (it.issue_key + ' - ') : '') + (it.title || ('Issue ' + it.id));
                        productionIssueSelect.appendChild(opt);
                    });
                }
            }).catch(function(){
                productionIssueSelect.innerHTML = '<option value="">Issues subsystem disabled</option>';
            });
    }
    }

    // Bench activity selection
    if (benchActivitySelect) {
        benchActivitySelect.addEventListener('change', function(){
            var activity = this.value;
            if (benchDescInput && (!benchDescInput.value || benchDescInput.value.trim() === '')) {
                // Pre-fill description based on activity type
                var descriptions = {
                    'training': 'Training session on ',
                    'learning': 'Learning/Research on ',
                    'documentation': 'Documentation work on ',
                    'meetings': 'Meeting: ',
                    'admin': 'Administrative task: ',
                    'waiting': 'Waiting for assignment',
                    'other': 'Other activity: '
                };
                benchDescInput.value = descriptions[activity] || '';
            }
        });
    }

    // Show/hide issue selector when testing type changes
    if (testingTypeSelect) {
        testingTypeSelect.addEventListener('change', function(){
            var v = this.value;
            var issueCont = document.getElementById('productionIssueContainer');
            if (v === 'regression') {
                if (issueCont) issueCont.style.display = 'block';
                // Load issues for first selected page (if any)
                var firstPage = productionPageSelect && productionPageSelect.selectedOptions && productionPageSelect.selectedOptions.length ? productionPageSelect.selectedOptions[0].value : null;
                loadProjectIssues(productionProjectSelect.value, firstPage);
            } else {
                if (issueCont) issueCont.style.display = 'none';
            }
        });
    }

    function ensureLogPreviewModal() {
        var existing = document.getElementById('logPreviewModal');
        if (existing) return existing;
        var wrap = document.createElement('div');
        wrap.innerHTML = '' +
            '<div class="modal fade" id="logPreviewModal" tabindex="-1" aria-hidden="true">' +
            '  <div class="modal-dialog modal-dialog-centered">' +
            '    <div class="modal-content">' +
            '      <div class="modal-header">' +
            '        <h5 class="modal-title">Confirm Log Submission</h5>' +
            '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '      </div>' +
            '      <div class="modal-body">' +
            '        <div id="logPreviewBody" class="small"></div>' +
            '      </div>' +
            '      <div class="modal-footer">' +
            '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
            '        <button type="button" class="btn btn-primary" id="logPreviewConfirmBtn">Confirm & Submit</button>' +
            '      </div>' +
            '    </div>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(wrap.firstChild);
        return document.getElementById('logPreviewModal');
    }

    function escapeHtml(val) {
        if (val === null || val === undefined) return '';
        return String(val)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getSelectedText(form, selector, multiple) {
        var el = form.querySelector(selector);
        if (!el) return '';
        if (multiple) {
            var vals = Array.from(el.selectedOptions || []).map(function (o) { return o.textContent.trim(); }).filter(Boolean);
            return vals.join(', ');
        }
        var opt = el.options && el.selectedIndex >= 0 ? el.options[el.selectedIndex] : null;
        return opt ? opt.textContent.trim() : '';
    }

    function rowHtml(label, value) {
        return '<tr><th class="pe-3 text-nowrap">' + escapeHtml(label) + '</th><td>' + escapeHtml(value || '-') + '</td></tr>';
    }

    function buildProductionPreview(form) {
        var taskType = getSelectedText(form, 'select[name=\"task_type\"]', false);
        var html = '<table class="table table-sm mb-0"><tbody>';
        html += rowHtml('Section', 'Log Production Hours');
        html += rowHtml('Project', getSelectedText(form, 'select[name=\"project_id\"]', false));
        html += rowHtml('Task Type', taskType);
        var pages = getSelectedText(form, '#productionPageSelect', true);
        if (pages) html += rowHtml('Page/Screen', pages);
        var envs = getSelectedText(form, '#productionEnvSelect', true);
        if (envs) html += rowHtml('Environments', envs);
        var testingType = getSelectedText(form, '#testingTypeSelect', false);
        if (testingType) html += rowHtml('Testing Type', testingType);
        var issueText = getSelectedText(form, '#productionIssueSelect', false);
        if (issueText) html += rowHtml('Issue', issueText);
        var phaseText = getSelectedText(form, '#projectPhaseSelect', false);
        if (phaseText) html += rowHtml('Project Phase', phaseText);
        var phaseActivity = getSelectedText(form, 'select[name=\"phase_activity\"]', false);
        if (phaseActivity) html += rowHtml('Phase Activity', phaseActivity);
        var genericCat = getSelectedText(form, '#genericCategorySelect', false);
        if (genericCat) html += rowHtml('Task Category', genericCat);
        var genericDetailEl = form.querySelector('input[name=\"generic_task_detail\"]');
        if (genericDetailEl && genericDetailEl.value.trim()) html += rowHtml('Task Details', genericDetailEl.value.trim());
        var hoursEl = form.querySelector('input[name=\"hours_spent\"]');
        html += rowHtml('Hours', hoursEl ? hoursEl.value : '');
        var descEl = form.querySelector('input[name=\"description\"]');
        html += rowHtml('Description', descEl ? descEl.value : '');
        html += '</tbody></table>';
        return html;
    }

    function buildBenchPreview(form) {
        var html = '<table class="table table-sm mb-0"><tbody>';
        html += rowHtml('Section', 'Log Off-Production/Bench Hours');
        html += rowHtml('Activity Type', getSelectedText(form, 'select[name=\"bench_activity\"]', false));
        var hoursEl = form.querySelector('input[name=\"hours_spent\"]');
        html += rowHtml('Hours', hoursEl ? hoursEl.value : '');
        var descEl = form.querySelector('input[name=\"description\"]');
        html += rowHtml('Description', descEl ? descEl.value : '');
        html += '</tbody></table>';
        return html;
    }

    function setupLogPreview(form, mode) {
        if (!form) return;
        form.addEventListener('submit', function (e) {
            if (form.dataset.previewApproved === '1') {
                form.dataset.previewApproved = '';
                return;
            }
            if (!form.checkValidity()) {
                return;
            }
            e.preventDefault();
            var modalEl = ensureLogPreviewModal();
            var bodyEl = document.getElementById('logPreviewBody');
            var confirmBtn = document.getElementById('logPreviewConfirmBtn');
            if (!modalEl || !bodyEl || !confirmBtn) {
                form.dataset.previewApproved = '1';
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
                return;
            }
            bodyEl.innerHTML = mode === 'bench' ? buildBenchPreview(form) : buildProductionPreview(form);
            confirmBtn.onclick = function () {
                form.dataset.previewApproved = '1';
                try {
                    var inst = bootstrap.Modal.getOrCreateInstance(modalEl);
                    inst.hide();
                } catch (err) {}
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            };
            try {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } catch (err) {
                form.dataset.previewApproved = '1';
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            }
        });
    }

    setupLogPreview(document.getElementById('logProductionHoursForm'), 'production');
    setupLogPreview(document.getElementById('logBenchHoursForm'), 'bench');
});
</script>

<script>
var reqEditProjects = <?php
echo json_encode(
    array_values(array_map(static function ($p) {
        return [
            'id' => (int)($p['id'] ?? 0),
            'title' => (string)($p['title'] ?? '')
        ];
    }, $assignedProjects ?? [])),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
?>;

function handleDeleteLog(logId, dateStr) {
    var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    var msg = isAdmin
        ? 'Delete this log?'
        : 'Log deletion requires admin approval. A request will be sent to admin. Do you still want to delete?';
    confirmModal(msg, function() {
        var key = isAdmin ? 'delete_log' : 'delete_log_request';
        window.location.href = '?date=' + encodeURIComponent(dateStr) + '&' + key + '=' + encodeURIComponent(logId);
    }, {
        title: isAdmin ? 'Delete Log' : 'Request Deletion Approval',
        confirmText: isAdmin ? 'Delete' : 'Send Request',
        confirmClass: isAdmin ? 'btn-danger' : 'btn-primary'
    });
    return false;
}

function ensureEditRequestModal() {
    var existing = document.getElementById('logEditRequestModal');
    if (existing) return existing;
    var wrap = document.createElement('div');
    wrap.innerHTML = '' +
        '<div class="modal fade" id="logEditRequestModal" tabindex="-1" aria-hidden="true">' +
        '  <div class="modal-dialog modal-lg modal-dialog-scrollable">' +
        '    <div class="modal-content">' +
        '      <div class="modal-header">' +
        '        <h5 class="modal-title">Request Log Edit Approval</h5>' +
        '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
        '      </div>' +
        '      <div class="modal-body">' +
        '        <div class="row g-2">' +
        '          <div class="col-md-6"><label class="form-label">Project</label><select class="form-select" id="reqEditProject"></select></div>' +
        '          <div class="col-md-6"><label class="form-label">Task Type</label><select class="form-select" id="reqEditTaskType"><option value="">Select Task Type</option><option value="page_testing">Page Testing</option><option value="page_qa">Page QA</option><option value="regression_testing">Regression Testing</option><option value="project_phase">Project Phase</option><option value="generic_task">Generic Task</option><option value="other">Other</option></select></div>' +
        '          <div class="col-12" id="reqEditPageTestingWrap" style="display:none;"><div class="row g-2"><div class="col-md-4"><label class="form-label">Page/Screen</label><select class="form-select" id="reqEditPage"></select></div><div class="col-md-4"><label class="form-label">Environment</label><select class="form-select" id="reqEditEnvironment"></select></div><div class="col-md-4"><label class="form-label">Testing Type</label><select class="form-select" id="reqEditTestingType"><option value="at_testing">AT Testing</option><option value="ft_testing">FT Testing</option><option value="regression">Regression</option></select></div><div class="col-md-6" id="reqEditIssueWrap" style="display:none;"><label class="form-label">Issue (optional)</label><select class="form-select" id="reqEditIssue"></select></div></div></div>' +
        '          <div class="col-12" id="reqEditPhaseWrap" style="display:none;"><div class="row g-2"><div class="col-md-6"><label class="form-label">Project Phase</label><select class="form-select" id="reqEditPhase"></select></div><div class="col-md-6"><label class="form-label">Phase Activity</label><select class="form-select" id="reqEditPhaseActivity"><option value="scoping">Scoping & Analysis</option><option value="setup">Setup & Configuration</option><option value="testing">Testing Activities</option><option value="review">Review & Documentation</option><option value="training">Training & Knowledge Transfer</option><option value="reporting">Reporting & VPAT</option></select></div></div></div>' +
        '          <div class="col-12" id="reqEditGenericWrap" style="display:none;"><div class="row g-2"><div class="col-md-6"><label class="form-label">Task Category</label><select class="form-select" id="reqEditGenericCategory"></select></div><div class="col-md-6"><label class="form-label">Task Details</label><input type="text" class="form-control" id="reqEditGenericDetail" placeholder="Specific task details"></div></div></div>' +
        '          <div class="col-md-4"><label class="form-label">Hours</label><input type="number" step="0.25" min="0.25" max="24" class="form-control" id="reqEditHours"></div>' +
        '          <div class="col-md-8"><label class="form-label">Description</label><input type="text" class="form-control" id="reqEditDesc"></div>' +
        '          <div class="col-12"><div class="small text-muted">Admin approval is required. This will send an edit request.</div></div>' +
        '        </div>' +
        '      </div>' +
        '      <div class="modal-footer">' +
        '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
        '        <button type="button" class="btn btn-primary" id="reqEditSubmitBtn">Send Request</button>' +
        '      </div>' +
        '    </div>' +
        '  </div>' +
        '</div>';
    document.body.appendChild(wrap.firstChild);
    return document.getElementById('logEditRequestModal');
}

function reqEditClearSelect(sel, placeholder) {
    if (!sel) return;
    sel.innerHTML = '<option value="">' + (placeholder || 'Select') + '</option>';
}

function reqEditSetTaskContainers(taskType) {
    var pageWrap = document.getElementById('reqEditPageTestingWrap');
    var phaseWrap = document.getElementById('reqEditPhaseWrap');
    var genericWrap = document.getElementById('reqEditGenericWrap');
    if (pageWrap) pageWrap.style.display = (taskType === 'page_testing' || taskType === 'page_qa' || taskType === 'regression_testing') ? 'block' : 'none';
    if (phaseWrap) phaseWrap.style.display = taskType === 'project_phase' ? 'block' : 'none';
    if (genericWrap) genericWrap.style.display = taskType === 'generic_task' ? 'block' : 'none';
}

function reqEditFillProjectOptions(selectedProjectId) {
    var projectSel = document.getElementById('reqEditProject');
    if (!projectSel) return;
    projectSel.innerHTML = '';
    var hadOption = false;

    if (Array.isArray(reqEditProjects) && reqEditProjects.length > 0) {
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select Project';
        projectSel.appendChild(placeholder);
        reqEditProjects.forEach(function (p) {
            if (!p || !p.id) return;
            var opt = document.createElement('option');
            opt.value = String(p.id);
            opt.textContent = p.title || ('Project #' + p.id);
            projectSel.appendChild(opt);
            if (String(p.id) === String(selectedProjectId || '')) {
                hadOption = true;
            }
        });
    } else {
        var srcSel = document.querySelector('#logProductionHoursForm select[name="project_id"]');
        if (!srcSel) return;
        Array.prototype.forEach.call(srcSel.options, function (opt) {
            var clone = document.createElement('option');
            clone.value = opt.value;
            clone.textContent = opt.textContent;
            projectSel.appendChild(clone);
            if (String(opt.value || '') === String(selectedProjectId || '')) {
                hadOption = true;
            }
        });
    }

    if (selectedProjectId && !hadOption) {
        var clone = document.createElement('option');
        clone.value = String(selectedProjectId);
        clone.textContent = 'Project #' + selectedProjectId;
        projectSel.appendChild(clone);
    }

    projectSel.value = String(selectedProjectId || '');
}

function reqEditLoadPages(projectId, selectedPageId) {
    var pageSel = document.getElementById('reqEditPage');
    if (!pageSel || !projectId) return Promise.resolve();
    pageSel.innerHTML = '<option value="">Loading pages...</option>';
    return fetch('<?php echo $baseDir; ?>/api/tasks.php?project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (pages) {
            reqEditClearSelect(pageSel, 'Select page');
            if (Array.isArray(pages)) {
                pages.forEach(function (pg) {
                    var opt = document.createElement('option');
                    opt.value = pg.id;
                    opt.textContent = pg.page_name || pg.title || pg.url || ('Page ' + pg.id);
                    pageSel.appendChild(opt);
                });
            }
            if (selectedPageId !== null && selectedPageId !== undefined && selectedPageId !== '') {
                pageSel.value = String(selectedPageId);
            }
        })
        .catch(function () { reqEditClearSelect(pageSel, 'No pages'); });
}

function reqEditLoadEnvironments(pageId, selectedEnvId) {
    var envSel = document.getElementById('reqEditEnvironment');
    if (!envSel || !pageId) {
        reqEditClearSelect(envSel, 'Select environment');
        return Promise.resolve();
    }
    envSel.innerHTML = '<option value="">Loading environments...</option>';
    return fetch('<?php echo $baseDir; ?>/api/tasks.php?page_id=' + encodeURIComponent(pageId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (page) {
            reqEditClearSelect(envSel, 'Select environment');
            var envs = (page && page.environments) ? page.environments : [];
            envs.forEach(function (env) {
                var opt = document.createElement('option');
                opt.value = env.id;
                opt.textContent = env.name || ('Env ' + env.id);
                envSel.appendChild(opt);
            });
            if (selectedEnvId !== null && selectedEnvId !== undefined && selectedEnvId !== '') {
                envSel.value = String(selectedEnvId);
            }
        })
        .catch(function () { reqEditClearSelect(envSel, 'No environments'); });
}

function reqEditLoadIssues(projectId, pageId, selectedIssueId) {
    var issueWrap = document.getElementById('reqEditIssueWrap');
    var issueSel = document.getElementById('reqEditIssue');
    var testingTypeSel = document.getElementById('reqEditTestingType');
    if (!issueWrap || !issueSel || !testingTypeSel) return Promise.resolve();
    if (testingTypeSel.value !== 'regression') {
        issueWrap.style.display = 'none';
        reqEditClearSelect(issueSel, 'Select issue (optional)');
        return Promise.resolve();
    }
    issueWrap.style.display = 'block';
    issueSel.innerHTML = '<option value="">Loading issues...</option>';
    var url = '<?php echo $baseDir; ?>/api/regression_actions.php?action=get_project_issues&project_id=' + encodeURIComponent(projectId || '');
    if (pageId) url += '&page_id=' + encodeURIComponent(pageId);
    return fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            reqEditClearSelect(issueSel, 'Select issue (optional)');
            if (data && Array.isArray(data.issues)) {
                data.issues.forEach(function (it) {
                    var opt = document.createElement('option');
                    opt.value = it.id;
                    opt.textContent = (it.issue_key ? (it.issue_key + ' - ') : '') + (it.title || ('Issue ' + it.id));
                    issueSel.appendChild(opt);
                });
            }
            if (selectedIssueId !== null && selectedIssueId !== undefined && selectedIssueId !== '') {
                issueSel.value = String(selectedIssueId);
            }
        })
        .catch(function () { reqEditClearSelect(issueSel, 'Select issue (optional)'); });
}

function reqEditLoadPhases(projectId, selectedPhaseId) {
    var phaseSel = document.getElementById('reqEditPhase');
    if (!phaseSel || !projectId) {
        reqEditClearSelect(phaseSel, 'Select project phase');
        return Promise.resolve();
    }
    phaseSel.innerHTML = '<option value="">Loading phases...</option>';
    return fetch('<?php echo $baseDir; ?>/api/projects.php?action=get_phases&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (txt) {
            var phases = [];
            try { phases = JSON.parse(txt); } catch (e) { phases = []; }
            reqEditClearSelect(phaseSel, 'Select project phase');
            if (Array.isArray(phases)) {
                phases.forEach(function (phase) {
                    var opt = document.createElement('option');
                    opt.value = phase.id;
                    opt.textContent = phase.phase_name || phase.name || ('Phase ' + phase.id);
                    phaseSel.appendChild(opt);
                });
            }
            if (selectedPhaseId !== null && selectedPhaseId !== undefined && selectedPhaseId !== '') {
                phaseSel.value = String(selectedPhaseId);
            }
        })
        .catch(function () { reqEditClearSelect(phaseSel, 'Select project phase'); });
}

function reqEditLoadGenericCategories(selectedCategoryId) {
    var catSel = document.getElementById('reqEditGenericCategory');
    if (!catSel) return Promise.resolve();
    catSel.innerHTML = '<option value="">Loading categories...</option>';
    return fetch('<?php echo $baseDir; ?>/api/generic_tasks.php?action=get_categories', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (categories) {
            reqEditClearSelect(catSel, 'Select category');
            if (Array.isArray(categories)) {
                categories.forEach(function (cat) {
                    var opt = document.createElement('option');
                    opt.value = cat.id;
                    opt.textContent = cat.name + (cat.description ? (' - ' + cat.description) : '');
                    catSel.appendChild(opt);
                });
            }
            if (selectedCategoryId !== null && selectedCategoryId !== undefined && selectedCategoryId !== '') {
                catSel.value = String(selectedCategoryId);
            }
        })
        .catch(function () { reqEditClearSelect(catSel, 'Select category'); });
}

function reqEditWireEvents() {
    var modalEl = document.getElementById('logEditRequestModal');
    if (!modalEl || modalEl.dataset.eventsWired === '1') return;
    modalEl.dataset.eventsWired = '1';

    var projectSel = document.getElementById('reqEditProject');
    var taskTypeSel = document.getElementById('reqEditTaskType');
    var pageSel = document.getElementById('reqEditPage');
    var testingTypeSel = document.getElementById('reqEditTestingType');

    if (projectSel) {
        projectSel.addEventListener('change', function () {
            var projectId = this.value || '';
            var taskType = taskTypeSel ? taskTypeSel.value : '';
            if (!projectId) return;
            if (taskType === 'page_testing' || taskType === 'page_qa' || taskType === 'regression_testing') {
                reqEditLoadPages(projectId, null);
                reqEditClearSelect(document.getElementById('reqEditEnvironment'), 'Select environment');
                reqEditLoadIssues(projectId, null, null);
            } else if (taskType === 'project_phase') {
                reqEditLoadPhases(projectId, null);
            } else if (taskType === 'generic_task') {
                reqEditLoadGenericCategories(null);
            }
        });
    }

    if (taskTypeSel) {
        taskTypeSel.addEventListener('change', function () {
            var taskType = this.value;
            var projectId = projectSel ? projectSel.value : '';
            reqEditSetTaskContainers(taskType);
            if (!projectId) return;
            if (taskType === 'page_testing' || taskType === 'page_qa' || taskType === 'regression_testing') {
                reqEditLoadPages(projectId, null);
                reqEditClearSelect(document.getElementById('reqEditEnvironment'), 'Select environment');
                reqEditLoadIssues(projectId, null, null);
            } else if (taskType === 'project_phase') {
                reqEditLoadPhases(projectId, null);
            } else if (taskType === 'generic_task') {
                reqEditLoadGenericCategories(null);
            }
        });
    }

    if (pageSel) {
        pageSel.addEventListener('change', function () {
            var pageId = this.value || '';
            var projectId = projectSel ? projectSel.value : '';
            reqEditLoadEnvironments(pageId, null);
            reqEditLoadIssues(projectId, pageId, null);
        });
    }

    if (testingTypeSel) {
        testingTypeSel.addEventListener('change', function () {
            var projectId = projectSel ? projectSel.value : '';
            var pageId = pageSel ? pageSel.value : '';
            reqEditLoadIssues(projectId, pageId, null);
        });
    }
}

function handleEditLogRequest(logId, dateStr, logData) {
    var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    if (isAdmin) {
        showToast('Admins can edit logs from admin tools.', 'info');
        return false;
    }
    var modalEl = ensureEditRequestModal();
    reqEditWireEvents();
    var data = logData || {};
    reqEditFillProjectOptions(data.project_id || '');

    var projectSel = document.getElementById('reqEditProject');
    var taskTypeSel = document.getElementById('reqEditTaskType');
    var pageSel = document.getElementById('reqEditPage');
    var envSel = document.getElementById('reqEditEnvironment');
    var issueSel = document.getElementById('reqEditIssue');
    var testingTypeSel = document.getElementById('reqEditTestingType');
    var phaseSel = document.getElementById('reqEditPhase');
    var phaseActivitySel = document.getElementById('reqEditPhaseActivity');
    var genericCategorySel = document.getElementById('reqEditGenericCategory');
    var genericDetailEl = document.getElementById('reqEditGenericDetail');
    var hoursEl = document.getElementById('reqEditHours');
    var descEl = document.getElementById('reqEditDesc');
    var submitBtn = document.getElementById('reqEditSubmitBtn');
    if (!modalEl || !projectSel || !taskTypeSel || !hoursEl || !descEl || !submitBtn) return false;

    var rawTaskType = String(data.task_type || 'other');
    if (rawTaskType === 'regression') rawTaskType = 'regression_testing';
    taskTypeSel.value = rawTaskType;
    reqEditSetTaskContainers(rawTaskType);

    if (testingTypeSel) testingTypeSel.value = data.testing_type ? String(data.testing_type) : 'at_testing';
    if (phaseActivitySel) phaseActivitySel.value = 'testing';
    hoursEl.value = String(data.hours_spent || '');
    descEl.value = String(data.description || '');
    if (genericDetailEl) genericDetailEl.value = '';

    var selectedProjectId = projectSel.value;
    var selectedPageId = (data.page_id !== null && data.page_id !== undefined) ? data.page_id : null;
    var selectedEnvId = (data.environment_id !== null && data.environment_id !== undefined) ? data.environment_id : null;
    var selectedIssueId = (data.issue_id !== null && data.issue_id !== undefined) ? data.issue_id : null;
    var selectedPhaseId = (data.phase_id !== null && data.phase_id !== undefined) ? data.phase_id : null;
    var selectedCategoryId = (data.generic_category_id !== null && data.generic_category_id !== undefined) ? data.generic_category_id : null;

    if (selectedProjectId && (rawTaskType === 'page_testing' || rawTaskType === 'page_qa' || rawTaskType === 'regression_testing')) {
        reqEditLoadPages(selectedProjectId, selectedPageId).then(function () {
            return reqEditLoadEnvironments(selectedPageId, selectedEnvId);
        }).then(function () {
            return reqEditLoadIssues(selectedProjectId, selectedPageId, selectedIssueId);
        });
    } else if (selectedProjectId && rawTaskType === 'project_phase') {
        reqEditLoadPhases(selectedProjectId, selectedPhaseId);
    } else if (rawTaskType === 'generic_task') {
        reqEditLoadGenericCategories(selectedCategoryId);
    } else {
        reqEditClearSelect(pageSel, 'Select page');
        reqEditClearSelect(envSel, 'Select environment');
        reqEditClearSelect(issueSel, 'Select issue (optional)');
        reqEditClearSelect(phaseSel, 'Select project phase');
        reqEditClearSelect(genericCategorySel, 'Select category');
    }

    submitBtn.onclick = function () {
        var projectId = projectSel.value || '';
        var taskType = taskTypeSel.value || '';
        var pageId = pageSel ? (pageSel.value || '') : '';
        var environmentId = envSel ? (envSel.value || '') : '';
        var issueId = issueSel ? (issueSel.value || '') : '';
        var phaseId = phaseSel ? (phaseSel.value || '') : '';
        var genericCategoryId = genericCategorySel ? (genericCategorySel.value || '') : '';
        var testingType = testingTypeSel ? (testingTypeSel.value || '') : '';
        var phaseActivity = phaseActivitySel ? (phaseActivitySel.value || '') : '';
        var genericDetail = genericDetailEl ? (genericDetailEl.value || '').trim() : '';
        var h = parseFloat(hoursEl.value || '0');
        var d = (descEl.value || '').trim();
        if (!projectId || !taskType || !(h > 0) || !d) {
            showToast('Project, task type, hours and description are required.', 'warning');
            return;
        }
        var url = '?date=' + encodeURIComponent(dateStr) +
            '&edit_log_request=' + encodeURIComponent(logId) +
            '&new_project_id=' + encodeURIComponent(projectId) +
            '&new_task_type=' + encodeURIComponent(taskType) +
            '&new_page_id=' + encodeURIComponent(pageId) +
            '&new_environment_id=' + encodeURIComponent(environmentId) +
            '&new_issue_id=' + encodeURIComponent(issueId) +
            '&new_phase_id=' + encodeURIComponent(phaseId) +
            '&new_generic_category_id=' + encodeURIComponent(genericCategoryId) +
            '&new_testing_type=' + encodeURIComponent(testingType) +
            '&new_phase_activity=' + encodeURIComponent(phaseActivity) +
            '&new_generic_task_detail=' + encodeURIComponent(genericDetail) +
            '&new_hours=' + encodeURIComponent(h) +
            '&new_description=' + encodeURIComponent(d);
        window.location.href = url;
    };

    try {
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    } catch (e) {}
    return false;
}
</script>
