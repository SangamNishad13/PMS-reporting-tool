<?php
require_once __DIR__ . '/../../includes/auth.php';

$auth = new Auth();
$auth->requireRole('admin');
$db = Database::getInstance();
try { $db->exec("ALTER TABLE user_daily_status MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'not_updated'"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_pending_changes MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'not_updated'"); } catch (Exception $e) {}

$pageTitle = 'Edit Requests Management';

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
try { $db->exec("ALTER TABLE user_edit_requests ADD COLUMN request_type ENUM('edit','delete') NOT NULL DEFAULT 'edit'"); } catch (Exception $e) {}
try { $db->exec("UPDATE user_edit_requests SET request_type = 'delete' WHERE reason LIKE 'Deletion request for time log ID %'"); } catch (Exception $e) {}
try { $db->exec("UPDATE user_edit_requests SET request_type = 'edit' WHERE request_type IS NULL OR request_type = ''"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests DROP INDEX uq_user_date"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE user_edit_requests ADD UNIQUE KEY uq_user_date_type (user_id, req_date, request_type)"); } catch (Exception $e) {}

// Function to apply pending changes when request is approved
function applyPendingChanges($db, $userId, $date) {
    try {
        // Get pending changes
        $pendingStmt = $db->prepare("SELECT * FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
        $pendingStmt->execute([$userId, $date]);
        $pendingData = $pendingStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pendingData) {
            // Apply to user_daily_status
            $statusStmt = $db->prepare("INSERT INTO user_daily_status (user_id, status_date, status, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status=VALUES(status), notes=VALUES(notes), updated_at=NOW()");
            $statusStmt->execute([$userId, $date, $pendingData['status'], $pendingData['notes']]);
            
            // Apply to user_calendar_notes if personal note exists
            if (!empty($pendingData['personal_note'])) {
                $noteStmt = $db->prepare("INSERT INTO user_calendar_notes (user_id, note_date, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content=VALUES(content), updated_at=NOW()");
                $noteStmt->execute([$userId, $date, $pendingData['personal_note']]);
            }
            
            // Remove pending changes after applying
            // Apply pending time logs if present
            if (!empty($pendingData['pending_time_logs'])) {
                $logs = json_decode($pendingData['pending_time_logs'], true);
                if (is_array($logs)) {
                    // Insert each pending log as a new project_time_logs entry
                    foreach ($logs as $pl) {
                        // basic validation
                        $projId = isset($pl['project_id']) ? intval($pl['project_id']) : null;
                        if (!$projId) continue;
                        $taskType = $pl['task_type'] ?? 'other';
                        $pageIds = is_array($pl['page_ids']) ? $pl['page_ids'] : [];
                        $envIds = is_array($pl['environment_ids']) ? $pl['environment_ids'] : [];
                        $testingType = $pl['testing_type'] ?? null;
                        $issueId = !empty($pl['issue_id']) ? intval($pl['issue_id']) : null;
                        $hours = isset($pl['hours']) ? floatval($pl['hours']) : 0;
                        $desc = $pl['description'] ?? '';
                        $isUtilized = isset($pl['is_utilized']) ? intval($pl['is_utilized']) : 1;

                        if (!empty($pageIds)) {
                            // create entry per page
                            $perHour = count($pageIds) > 1 ? ($hours / count($pageIds)) : $hours;
                            foreach ($pageIds as $pid) {
                                $pid = intval($pid);
                                $envId = !empty($envIds) ? intval($envIds[0]) : null;
                                // choose insert based on schema
                                $columnsExist = false;
                                try { $check = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'task_type'"); $columnsExist = $check->rowCount() > 0; } catch (Exception $_) { $columnsExist = false; }
                                if ($columnsExist) {
                                    $ist = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, issue_id, task_type, testing_type, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    $ist->execute([$userId, $projId, $pid, $envId, $issueId, $taskType, $testingType, $date, $perHour, $desc, $isUtilized]);
                                } else {
                                    $ist = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                    $ist->execute([$userId, $projId, $pid, $envId, $date, $perHour, $desc, $isUtilized]);
                                }
                            }
                        } else {
                            // single entry without page
                            $envId = !empty($envIds) ? intval($envIds[0]) : null;
                            $columnsExist = false;
                            try { $check = $db->query("SHOW COLUMNS FROM project_time_logs LIKE 'task_type'"); $columnsExist = $check->rowCount() > 0; } catch (Exception $_) { $columnsExist = false; }
                            if ($columnsExist) {
                                $ist = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, issue_id, task_type, testing_type, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $ist->execute([$userId, $projId, null, $envId, $issueId, $taskType, $testingType, $date, $hours, $desc, $isUtilized]);
                            } else {
                                $ist = $db->prepare("INSERT INTO project_time_logs (user_id, project_id, page_id, environment_id, log_date, hours_spent, description, is_utilized) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                $ist->execute([$userId, $projId, null, $envId, $date, $hours, $desc, $isUtilized]);
                            }
                        }
                    }
                }
            }

            $deleteStmt = $db->prepare("DELETE FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
            $deleteStmt->execute([$userId, $date]);
        }

        // Apply pending log deletions for this date
        try {
            $delStmt = $db->prepare("SELECT id, log_id FROM user_pending_log_deletions WHERE user_id = ? AND req_date = ? AND status = 'pending'");
            $delStmt->execute([$userId, $date]);
            $pendingDeletes = $delStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pendingDeletes as $pd) {
                $logId = (int)($pd['log_id'] ?? 0);
                if ($logId <= 0) continue;
                $logRowStmt = $db->prepare("SELECT * FROM project_time_logs WHERE id = ? AND user_id = ? AND log_date = ? LIMIT 1");
                $logRowStmt->execute([$logId, $userId, $date]);
                $row = $logRowStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $db->prepare("DELETE FROM project_time_logs WHERE id = ? AND user_id = ?")->execute([$logId, $userId]);
                }
                $db->prepare("UPDATE user_pending_log_deletions SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([(int)$pd['id']]);
            }
        } catch (Exception $e) {
            error_log("Failed applying pending deletions: " . $e->getMessage());
        }

        // Apply pending log edits for this date
        try {
            $editStmt = $db->prepare("SELECT * FROM user_pending_log_edits WHERE user_id = ? AND req_date = ? AND status = 'pending'");
            $editStmt->execute([$userId, $date]);
            $pendingEdits = $editStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pendingEdits as $pe) {
                $logId = (int)($pe['log_id'] ?? 0);
                if ($logId <= 0) continue;
                $columns = [];
                try {
                    $colRows = $db->query("SHOW COLUMNS FROM project_time_logs")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($colRows as $cr) {
                        $columns[(string)$cr['Field']] = true;
                    }
                } catch (Exception $_) {
                    $columns = [];
                }

                $set = [];
                $params = [];
                if (!empty($columns['project_id'])) { $set[] = "project_id = ?"; $params[] = (int)($pe['new_project_id'] ?? 0); }
                if (!empty($columns['page_id'])) { $set[] = "page_id = ?"; $params[] = ($pe['new_page_id'] !== null ? (int)$pe['new_page_id'] : null); }
                if (!empty($columns['environment_id'])) { $set[] = "environment_id = ?"; $params[] = ($pe['new_environment_id'] !== null ? (int)$pe['new_environment_id'] : null); }
                if (!empty($columns['issue_id'])) { $set[] = "issue_id = ?"; $params[] = ($pe['new_issue_id'] !== null ? (int)$pe['new_issue_id'] : null); }
                if (!empty($columns['task_type'])) { $set[] = "task_type = ?"; $params[] = ($pe['new_task_type'] !== null ? (string)$pe['new_task_type'] : null); }
                if (!empty($columns['phase_id'])) { $set[] = "phase_id = ?"; $params[] = ($pe['new_phase_id'] !== null ? (int)$pe['new_phase_id'] : null); }
                if (!empty($columns['generic_category_id'])) { $set[] = "generic_category_id = ?"; $params[] = ($pe['new_generic_category_id'] !== null ? (int)$pe['new_generic_category_id'] : null); }
                if (!empty($columns['testing_type'])) { $set[] = "testing_type = ?"; $params[] = ($pe['new_testing_type'] !== null ? (string)$pe['new_testing_type'] : null); }
                if (!empty($columns['hours_spent'])) { $set[] = "hours_spent = ?"; $params[] = (float)$pe['new_hours']; }
                if (!empty($columns['description'])) { $set[] = "description = ?"; $params[] = (string)$pe['new_description']; }
                if (!empty($columns['is_utilized'])) {
                    $set[] = "is_utilized = ?";
                    $params[] = ($pe['new_is_utilized'] !== null ? (int)$pe['new_is_utilized'] : 1);
                }
                if (!empty($columns['updated_at'])) { $set[] = "updated_at = NOW()"; }

                if (!empty($set)) {
                    $sql = "UPDATE project_time_logs SET " . implode(', ', $set) . " WHERE id = ? AND user_id = ? AND log_date = ?";
                    $params[] = $logId;
                    $params[] = $userId;
                    $params[] = $date;
                    $upd = $db->prepare($sql);
                    $upd->execute($params);
                }
                $db->prepare("UPDATE user_pending_log_edits SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([(int)$pe['id']]);
            }
        } catch (Exception $e) {
            error_log("Failed applying pending edits: " . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log("Failed to apply pending changes: " . $e->getMessage());
    }
}

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = $_POST['request_id'] ?? null;
    $action = $_POST['action'] ?? null;
    $userId = $_POST['user_id'] ?? null;
    $date = $_POST['date'] ?? null;
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['request_ids']) && is_array($_POST['request_ids'])) {
        $bulkAction = $_POST['bulk_action'];
        $requestIds = array_map('intval', $_POST['request_ids']);
        
        if (in_array($bulkAction, ['approved', 'rejected']) && !empty($requestIds)) {
            try {
                $successCount = 0;
                $adminName = $_SESSION['full_name'] ?? 'Admin';
                
                foreach ($requestIds as $reqId) {
                    // Get request details
                    $reqStmt = $db->prepare("SELECT user_id, req_date FROM user_edit_requests WHERE id = ?");
                    $reqStmt->execute([$reqId]);
                    $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($reqData) {
                        // Update request status
                        $stmt = $db->prepare("UPDATE user_edit_requests SET status = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$bulkAction, $reqId]);
                        
                        // If approved, apply pending changes
                        if ($bulkAction === 'approved') {
                            applyPendingChanges($db, $reqData['user_id'], $reqData['req_date']);
                        } else {
                            // Mark pending delete requests as rejected for this user/date
                            $rejDel = $db->prepare("UPDATE user_pending_log_deletions SET status = 'rejected', updated_at = NOW() WHERE user_id = ? AND req_date = ? AND status = 'pending'");
                            $rejDel->execute([$reqData['user_id'], $reqData['req_date']]);
                            $rejEdit = $db->prepare("UPDATE user_pending_log_edits SET status = 'rejected', updated_at = NOW() WHERE user_id = ? AND req_date = ? AND status = 'pending'");
                            $rejEdit->execute([$reqData['user_id'], $reqData['req_date']]);
                        }
                        
                        // Send notification back to user
                        $message = "Your edit request for {$reqData['req_date']} has been {$bulkAction} by {$adminName}";
                        $link = "/modules/calendar.php?date={$reqData['req_date']}";
                        
                        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'edit_request_response', ?, ?)");
                        $notifStmt->execute([$reqData['user_id'], $message, $link]);
                        
                        $successCount++;
                    }
                }
                
                $_SESSION['success'] = "Successfully {$bulkAction} {$successCount} edit request(s)";
                
            } catch (Exception $e) {
                $_SESSION['error'] = "Failed to process bulk action: " . $e->getMessage();
            }
        }
    }
    // Handle single actions
    elseif ($requestId && $action && in_array($action, ['approved', 'rejected'])) {
        try {
            // Update request status
            $stmt = $db->prepare("UPDATE user_edit_requests SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$action, $requestId]);
            
            // If approved, apply pending changes
            if ($action === 'approved') {
                applyPendingChanges($db, $userId, $date);
            } else {
                // Mark pending delete requests as rejected for this user/date
                $rejDel = $db->prepare("UPDATE user_pending_log_deletions SET status = 'rejected', updated_at = NOW() WHERE user_id = ? AND req_date = ? AND status = 'pending'");
                $rejDel->execute([$userId, $date]);
                $rejEdit = $db->prepare("UPDATE user_pending_log_edits SET status = 'rejected', updated_at = NOW() WHERE user_id = ? AND req_date = ? AND status = 'pending'");
                $rejEdit->execute([$userId, $date]);
            }
            
            // Send notification back to user
            $adminName = $_SESSION['full_name'] ?? 'Admin';
            $message = "Your edit request for {$date} has been {$action} by {$adminName}";
            $link = "/modules/calendar.php?date={$date}";
            
            $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'edit_request_response', ?, ?)");
            $notifStmt->execute([$userId, $message, $link]);
            
            $_SESSION['success'] = "Edit request {$action} successfully";
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to update request: " . $e->getMessage();
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch all pending edit requests
$stmt = $db->prepare("
    SELECT uer.*, u.full_name, u.username 
    FROM user_edit_requests uer 
    JOIN users u ON uer.user_id = u.id 
    WHERE uer.status = 'pending' 
    ORDER BY uer.created_at DESC
");
$stmt->execute();
$pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent processed requests (last 30 days)
$stmt = $db->prepare("
    SELECT uer.*, u.full_name, u.username 
    FROM user_edit_requests uer 
    JOIN users u ON uer.user_id = u.id 
    WHERE uer.status IN ('approved', 'rejected') 
    AND uer.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY uer.updated_at DESC
    LIMIT 50
");
$stmt->execute();
$recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Edit Requests Management</h2>
        <div>
            <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/calendar.php" class="btn btn-secondary">Back to Calendar</a>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Pending Requests -->
    <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0">
                <i class="fas fa-clock"></i> Pending Edit Requests 
                <span class="badge bg-dark"><?php echo count($pendingRequests); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($pendingRequests)): ?>
                <p class="text-muted">No pending edit requests.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th>User</th>
                                <th>Date Requested</th>
                                <th>Request Date</th>
                                <th>Reason</th>
                                <th>Current Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingRequests as $req): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="request_ids[]" value="<?php echo $req['id']; ?>" class="form-check-input request-checkbox">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($req['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                        <small class="text-muted">@<?php echo htmlspecialchars($req['username'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($req['req_date'])); ?></strong><br>
                                        <small class="text-muted"><?php echo date('l', strtotime($req['req_date'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($req['reason'])): ?>
                                            <div class="text-muted small mb-1">
                                                <?php echo htmlspecialchars(substr($req['reason'], 0, 100), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if (strlen($req['reason']) > 100): ?>...<?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">No reason provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        // Get current status for this date
                                        $statusStmt = $db->prepare("SELECT status, notes FROM user_daily_status WHERE user_id = ? AND status_date = ?");
                                        $statusStmt->execute([$req['user_id'], $req['req_date']]);
                                        $currentStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);
                                        
                                        if ($currentStatus) {
                                            echo '<span class="badge bg-info">' . ucfirst($currentStatus['status']) . '</span>';
                                            if ($currentStatus['notes']) {
                                                echo '<br><small class="text-muted">' . htmlspecialchars(substr($currentStatus['notes'], 0, 50), ENT_QUOTES, 'UTF-8') . '...</small>';
                                            }
                                        } else {
                                            echo '<span class="badge bg-secondary">Not updated</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <form id="requestActionForm_<?php echo $req['id']; ?>" method="POST" class="d-inline">
                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $req['user_id']; ?>">
                                            <input type="hidden" name="date" value="<?php echo $req['req_date']; ?>">
                                            <input type="hidden" name="action" id="requestAction_<?php echo $req['id']; ?>" value="">
                                            <button type="button" class="btn btn-success btn-sm" onclick="document.getElementById('requestAction_<?php echo $req['id']; ?>').value='approved'; confirmModal('Approve this edit request?', function(){ var f=document.getElementById('requestActionForm_<?php echo $req['id']; ?>'); if(f){ f.submit(); } }, { title: 'Confirm Approval', confirmText: 'Approve', confirmClass: 'btn-success' });">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('requestAction_<?php echo $req['id']; ?>').value='rejected'; confirmModal('Reject this edit request?', function(){ var f=document.getElementById('requestActionForm_<?php echo $req['id']; ?>'); if(f){ f.submit(); } }, { title: 'Confirm Rejection', confirmText: 'Reject', confirmClass: 'btn-danger' });">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-info btn-sm" onclick="openAdminViewModal('<?php echo htmlspecialchars($req['req_date'], ENT_QUOTES, 'UTF-8'); ?>', <?php echo intval($req['user_id']); ?>, <?php echo intval($req['id']); ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bulk Actions -->
                <?php if (!empty($pendingRequests)): ?>
                <div class="mt-3 d-flex justify-content-between align-items-center">
                    <div>
                        <span id="selectedCount">0</span> requests selected
                    </div>
                    <div>
                        <button type="button" id="bulkApprove" class="btn btn-success" disabled>
                            <i class="fas fa-check"></i> Bulk Approve
                        </button>
                        <button type="button" id="bulkReject" class="btn btn-danger" disabled>
                            <i class="fas fa-times"></i> Bulk Reject
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Processed Requests -->
    <div class="card">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">
                <i class="fas fa-history"></i> Recent Processed Requests (Last 30 days)
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($recentRequests)): ?>
                <p class="text-muted">No recent processed requests.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Date Requested</th>
                                <th>Request Date</th>
                                <th>Status</th>
                                <th>Processed Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRequests as $req): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($req['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                        <small class="text-muted">@<?php echo htmlspecialchars($req['username'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($req['req_date'])); ?></strong><br>
                                        <small class="text-muted"><?php echo date('l', strtotime($req['req_date'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($req['status'] === 'approved'): ?>
                                            <span class="badge bg-success">Approved</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($req['updated_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Admin View Modal (same as calendar modal but with admin actions) -->
<div class="modal fade" id="adminViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Review Edit Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="adminRequestId">
                <input type="hidden" id="adminUserId">
                <input type="hidden" id="adminDate">
                
                <!-- Edit Request Info -->
                <div class="alert alert-warning mb-3">
                    <h6><i class="fas fa-info-circle"></i> Edit Request Details</h6>
                    <div><strong>User:</strong> <span id="adminUserName"></span></div>
                    <div><strong>Date:</strong> <span id="adminRequestDate"></span></div>
                    <div><strong>Reason:</strong> <span id="adminRequestReason"></span></div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-secondary text-white">
                                <h6 class="mb-0">Current Data</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Availability Status</label>
                                    <input type="text" id="currentStatus" class="form-control" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Work Notes</label>
                                    <textarea id="currentNotes" class="form-control" rows="4" readonly></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Personal Notes</label>
                                    <textarea id="currentPersonalNote" class="form-control" rows="3" readonly></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0">Requested Changes</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Availability Status</label>
                                    <input type="text" id="requestedStatus" class="form-control" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Work Notes</label>
                                    <textarea id="requestedNotes" class="form-control" rows="4" readonly></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Personal Notes</label>
                                    <textarea id="requestedPersonalNote" class="form-control" rows="3" readonly></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mt-3" id="adminLogDiffCard" style="display:none;">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="fas fa-exchange-alt"></i> Time Log Change Details</h6>
                    </div>
                    <div class="card-body">
                        <div id="adminLogDiffContent"></div>
                    </div>
                </div>
                
                <!-- Production Hours -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-clock"></i> Production Hours <span id="adminHoursDate"></span></h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h4 id="adminTotalHours">0.00 hrs</h4>
                            <div class="progress mb-2">
                                <div id="adminUtilizedProgress" class="progress-bar bg-success" role="progressbar" style="width: 0%">
                                    Utilized
                                </div>
                                <div id="adminBenchProgress" class="progress-bar bg-secondary" role="progressbar" style="width: 100%">
                                    Bench
                                </div>
                            </div>
                            <small class="text-muted">
                                Utilized: <span id="adminUtilizedHours">0.00</span>h | 
                                Bench: <span id="adminBenchHours">0.00</span>h
                            </small>
                        </div>
                        
                        <div id="adminHoursEntries" style="max-height: 200px; overflow-y: auto;">
                            <p class="text-muted text-center">Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-danger" onclick="adminRejectRequest()">
                    <i class="fas fa-times"></i> Reject
                </button>
                <button type="button" class="btn btn-success" onclick="adminApproveRequest()">
                    <i class="fas fa-check"></i> Approve
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
function openAdminViewModal(date, userId, requestId) {
    
    document.getElementById('adminRequestId').value = requestId;
    document.getElementById('adminUserId').value = userId;
    document.getElementById('adminDate').value = date;
    
    // Load request details first
    loadRequestDetails(requestId);
    
    // Load production hours
    loadAdminProductionHours(userId, date);
    
    // Load current data first, then pending data
    loadCurrentData(userId, date).then(() => {
        // After current data is loaded, load pending data
        loadPendingData(userId, date);
    });
    
    var modal = new bootstrap.Modal(document.getElementById('adminViewModal'));
    modal.show();
}

function loadCurrentData(userId, date) {
    return fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/get_original_data.php?user_id=' + userId + '&date=' + encodeURIComponent(date))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('currentStatus').value = data.status ? data.status.charAt(0).toUpperCase() + data.status.slice(1) : 'Not Updated';
                document.getElementById('currentNotes').value = data.notes || '';
                document.getElementById('currentPersonalNote').value = data.personal_note || '';
            } else {
                document.getElementById('currentStatus').value = 'Not Updated';
                document.getElementById('currentNotes').value = '';
                document.getElementById('currentPersonalNote').value = '';
            }
        })
        .catch(error => {
            console.error('Failed to load current data:', error);
            document.getElementById('currentStatus').value = 'Error loading data';
            document.getElementById('currentNotes').value = 'Error loading data';
            document.getElementById('currentPersonalNote').value = 'Error loading data';
        });
}

function loadPendingData(userId, date) {
    fetch('<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/get_pending_changes.php?user_id=' + userId + '&date=' + encodeURIComponent(date))
        .then(response => response.json())
        .then(data => {
            var logDiffCard = document.getElementById('adminLogDiffCard');
            var logDiffContent = document.getElementById('adminLogDiffContent');
            if (logDiffCard) logDiffCard.style.display = 'none';
            if (logDiffContent) logDiffContent.innerHTML = '';

            if (data.success && data.pending) {
                document.getElementById('requestedStatus').value = data.pending.status ? data.pending.status.charAt(0).toUpperCase() + data.pending.status.slice(1) : 'Not Updated';
                document.getElementById('requestedNotes').value = data.pending.notes || '';
                document.getElementById('requestedPersonalNote').value = data.pending.personal_note || '';
                // Show pending time logs if present
                var pendingLogs = data.pending.pending_time_logs_decoded || [];
                var hoursContainer = document.getElementById('adminHoursEntries');
                if (pendingLogs.length > 0) {
                    var html = '<div class="mb-2"><strong>Requested Time Log Changes:</strong></div>';
                    html += '<div class="list-group list-group-flush">';
                    pendingLogs.forEach(function(pl){
                        html += '<div class="list-group-item py-2">';
                        html += '<div><strong>Project:</strong> ' + (pl.project_id || 'N/A') + '</div>';
                        if (pl.page_ids && pl.page_ids.length) html += '<div><strong>Pages:</strong> ' + pl.page_ids.join(', ') + '</div>';
                        if (pl.environment_ids && pl.environment_ids.length) html += '<div><strong>Envs:</strong> ' + pl.environment_ids.join(', ') + '</div>';
                        html += '<div><strong>Testing Type:</strong> ' + (pl.testing_type || '') + '</div>';
                        html += '<div><strong>Hours:</strong> ' + (pl.hours || '') + '</div>';
                        html += '<div><strong>Description:</strong> ' + (pl.description || '') + '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                    // prepend to existing hours container
                    hoursContainer.innerHTML = html + hoursContainer.innerHTML;
                }
            } else {
                // If no pending changes, show "No changes requested"
                document.getElementById('requestedStatus').value = 'No changes requested';
                document.getElementById('requestedNotes').value = 'No changes requested';
                document.getElementById('requestedPersonalNote').value = 'No changes requested';
            }

            // Render pending time-log edit/delete diffs for clear before/after review
            var editDiffs = Array.isArray(data.pending_log_edit_diffs) ? data.pending_log_edit_diffs : [];
            var deleteDiffs = Array.isArray(data.pending_log_delete_diffs) ? data.pending_log_delete_diffs : [];
            if (logDiffCard && logDiffContent && (editDiffs.length > 0 || deleteDiffs.length > 0)) {
                var html = '';
                editDiffs.forEach(function(diff) {
                    html += renderLogDiffBlock(diff, false);
                });
                deleteDiffs.forEach(function(diff) {
                    html += renderLogDiffBlock(diff, true);
                });
                logDiffContent.innerHTML = html;
                logDiffCard.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Failed to load pending data:', error);
            // On error, show "No changes requested"
            document.getElementById('requestedStatus').value = 'No changes requested';
            document.getElementById('requestedNotes').value = 'No changes requested';
            document.getElementById('requestedPersonalNote').value = 'No changes requested';
            var logDiffCard = document.getElementById('adminLogDiffCard');
            var logDiffContent = document.getElementById('adminLogDiffContent');
            if (logDiffCard) logDiffCard.style.display = 'none';
            if (logDiffContent) logDiffContent.innerHTML = '';
        });
}

function formatTaskTypeLabel(taskType) {
    var t = String(taskType || '').trim();
    if (!t) return 'N/A';
    return t.replace(/_/g, ' ').replace(/\b\w/g, function(ch){ return ch.toUpperCase(); });
}

function renderLogSide(title, d, isDeleteSide) {
    d = d || {};
    var project = d.project_title || (d.project_id ? ('Project #' + d.project_id) : 'N/A');
    var page = d.page_name || (d.page_id ? ('Page #' + d.page_id) : 'N/A');
    var env = d.environment_name || (d.environment_id ? ('Environment #' + d.environment_id) : 'N/A');
    var issue = d.issue_id ? ('Issue #' + d.issue_id) : 'N/A';
    var taskType = formatTaskTypeLabel(d.task_type);
    var testingType = d.testing_type ? String(d.testing_type) : 'N/A';
    var hours = (d.hours_spent !== null && d.hours_spent !== undefined && d.hours_spent !== '') ? d.hours_spent : (isDeleteSide ? 'Will be deleted' : 'N/A');
    var desc = d.description || (isDeleteSide ? 'This log will be deleted' : '');
    var mode = (d.is_utilized === 0 || String(d.is_utilized) === '0') ? 'Off-Production/Bench' : 'Production';

    var html = '';
    html += '<div class="col-md-6">';
    html += '  <div class="border rounded p-2 h-100">';
    html += '    <h6 class="mb-2">' + escapeHtml(title) + '</h6>';
    html += '    <div class="small"><strong>Project:</strong> ' + escapeHtml(project) + '</div>';
    html += '    <div class="small"><strong>Task Type:</strong> ' + escapeHtml(taskType) + '</div>';
    html += '    <div class="small"><strong>Page/Task:</strong> ' + escapeHtml(page) + '</div>';
    html += '    <div class="small"><strong>Environment:</strong> ' + escapeHtml(env) + '</div>';
    html += '    <div class="small"><strong>Issue:</strong> ' + escapeHtml(issue) + '</div>';
    html += '    <div class="small"><strong>Testing Type:</strong> ' + escapeHtml(testingType) + '</div>';
    html += '    <div class="small"><strong>Mode:</strong> ' + escapeHtml(mode) + '</div>';
    html += '    <div class="small"><strong>Hours:</strong> ' + escapeHtml(String(hours)) + '</div>';
    html += '    <div class="small"><strong>Description:</strong> ' + escapeHtml(desc || 'N/A') + '</div>';
    html += '  </div>';
    html += '</div>';
    return html;
}

function renderLogDiffBlock(diff, isDelete) {
    var current = diff && diff.current ? diff.current : {};
    var requested = diff && diff.requested ? diff.requested : {};
    var titleBadge = isDelete
        ? '<span class="badge bg-danger ms-2">Delete Request</span>'
        : '<span class="badge bg-warning text-dark ms-2">Edit Request</span>';
    var html = '';
    html += '<div class="mb-3">';
    html += '  <div class="d-flex align-items-center mb-2"><strong>Time Log ID #' + escapeHtml(diff.log_id || '') + '</strong>' + titleBadge + '</div>';
    html += '  <div class="row g-2">';
    html += renderLogSide('Current', current, false);
    html += renderLogSide(isDelete ? 'Requested Action' : 'Requested', requested, isDelete);
    html += '  </div>';
    html += '</div>';
    return html;
}

function loadAdminProductionHours(userId, date) {
    document.getElementById('adminHoursDate').textContent = '(' + date + ')';
    
    var url = '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/api/user_hours.php?user_id=' + userId + '&date=' + encodeURIComponent(date);
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                var totalHours = parseFloat(data.total_hours || 0);
                var utilizedHours = 0;
                var benchHours = 0;
                
                document.getElementById('adminTotalHours').textContent = totalHours.toFixed(2) + ' hrs';
                
                if (data.entries && data.entries.length > 0) {
                    var html = '<div class="list-group list-group-flush">';
                    data.entries.forEach(function(entry) {
                        var hours = parseFloat(entry.hours_spent || 0);
                        var isUtilized = entry.is_utilized == 1 || entry.po_number !== 'OFF-PROD-001';
                        
                        if (isUtilized) {
                            utilizedHours += hours;
                        } else {
                            benchHours += hours;
                        }
                        
                        html += '<div class="list-group-item py-2">';
                        html += '<div class="d-flex justify-content-between align-items-start">';
                        html += '<div class="flex-grow-1">';
                        html += '<h6 class="mb-1">' + escapeHtml(entry.project_title || 'Unknown Project') + '</h6>';
                        if (entry.page_name) {
                            html += '<p class="mb-1 text-muted small">Page: ' + escapeHtml(entry.page_name) + '</p>';
                        }
                        if (entry.comments) {
                            html += '<p class="mb-0 small">' + escapeHtml(entry.comments) + '</p>';
                        }
                        html += '</div>';
                        html += '<div class="text-end">';
                        html += '<span class="badge ' + (isUtilized ? 'bg-success' : 'bg-secondary') + '">' + hours.toFixed(2) + 'h</span>';
                        html += '</div>';
                        html += '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                    document.getElementById('adminHoursEntries').innerHTML = html;
                } else {
                    document.getElementById('adminHoursEntries').innerHTML = '<p class="text-muted text-center">No time logged for this date</p>';
                }
                
                document.getElementById('adminUtilizedHours').textContent = utilizedHours.toFixed(2);
                document.getElementById('adminBenchHours').textContent = benchHours.toFixed(2);
                
                if (totalHours > 0) {
                    var utilizedPercent = (utilizedHours / totalHours) * 100;
                    var benchPercent = (benchHours / totalHours) * 100;
                    document.getElementById('adminUtilizedProgress').style.width = utilizedPercent + '%';
                    document.getElementById('adminBenchProgress').style.width = benchPercent + '%';
                }
            } else {
                document.getElementById('adminHoursEntries').innerHTML = '<p class="text-danger text-center">Failed to load production hours</p>';
            }
        })
        .catch(error => {
            document.getElementById('adminHoursEntries').innerHTML = '<p class="text-danger text-center">Error loading production hours</p>';
        });
}

function loadRequestDetails(requestId) {
    // This would load the request details like user name, reason, etc.
    // For now, we'll get this from the table row
    var row = document.querySelector('input[value="' + requestId + '"]').closest('tr');
    var cells = row.querySelectorAll('td');
    
    document.getElementById('adminUserName').textContent = cells[1].querySelector('strong').textContent;
    document.getElementById('adminRequestDate').textContent = cells[3].querySelector('strong').textContent;
    document.getElementById('adminRequestReason').textContent = cells[4].textContent.trim() || 'No reason provided';
}

function adminApproveRequest() {
    confirmModal('Are you sure you want to approve this edit request?', function() {
        var requestId = document.getElementById('adminRequestId').value;
        var userId = document.getElementById('adminUserId').value;
        var date = document.getElementById('adminDate').value;
        
        var formData = new FormData();
        formData.append('request_id', requestId);
        formData.append('user_id', userId);
        formData.append('date', date);
        formData.append('action', 'approved');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            var modalElement = document.getElementById('adminViewModal');
            var modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) modal.hide();
            location.reload();
        })
        .catch(error => {
            showToast('Failed to approve request. Please try again.', 'danger');
        });
    });
}

function adminRejectRequest() {
    confirmModal('Are you sure you want to reject this edit request?', function() {
        var requestId = document.getElementById('adminRequestId').value;
        var userId = document.getElementById('adminUserId').value;
        var date = document.getElementById('adminDate').value;
        
        var formData = new FormData();
        formData.append('request_id', requestId);
        formData.append('user_id', userId);
        formData.append('date', date);
        formData.append('action', 'rejected');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            var modalElement = document.getElementById('adminViewModal');
            var modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) modal.hide();
            location.reload();
        })
        .catch(error => {
            showToast('Failed to reject request. Please try again.', 'danger');
        });
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&"'<>]/g, function (s) {
        return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[s];
    });
}

// Bulk actions functionality
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const requestCheckboxes = document.querySelectorAll('.request-checkbox');
    const selectedCountSpan = document.getElementById('selectedCount');
    const bulkApproveBtn = document.getElementById('bulkApprove');
    const bulkRejectBtn = document.getElementById('bulkReject');
    
    // Handle select all checkbox
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            requestCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
    }
    
    // Handle individual checkboxes
    requestCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectAllState();
            updateBulkActions();
        });
    });
    
    // Update select all checkbox state
    function updateSelectAllState() {
        if (!selectAllCheckbox) return;
        
        const checkedCount = document.querySelectorAll('.request-checkbox:checked').length;
        const totalCount = requestCheckboxes.length;
        
        if (checkedCount === 0) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = false;
        } else if (checkedCount === totalCount) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = true;
        } else {
            selectAllCheckbox.indeterminate = true;
            selectAllCheckbox.checked = false;
        }
    }
    
    // Update bulk action buttons
    function updateBulkActions() {
        const checkedCount = document.querySelectorAll('.request-checkbox:checked').length;
        
        if (selectedCountSpan) {
            selectedCountSpan.textContent = checkedCount;
        }
        
        if (bulkApproveBtn && bulkRejectBtn) {
            const disabled = checkedCount === 0;
            bulkApproveBtn.disabled = disabled;
            bulkRejectBtn.disabled = disabled;
        }
    }
    
    // Handle bulk approve
    if (bulkApproveBtn) {
        bulkApproveBtn.addEventListener('click', function() {
            const checkedBoxes = document.querySelectorAll('.request-checkbox:checked');
            if (checkedBoxes.length === 0) return;
            
            confirmModal(`Are you sure you want to approve ${checkedBoxes.length} edit request(s)?`, function() {
                performBulkAction('approved', checkedBoxes);
            });
        });
    }
    
    // Handle bulk reject
    if (bulkRejectBtn) {
        bulkRejectBtn.addEventListener('click', function() {
            const checkedBoxes = document.querySelectorAll('.request-checkbox:checked');
            if (checkedBoxes.length === 0) return;
            
            confirmModal(`Are you sure you want to reject ${checkedBoxes.length} edit request(s)?`, function() {
                performBulkAction('rejected', checkedBoxes);
            });
        });
    }
    
    // Perform bulk action
    function performBulkAction(action, checkedBoxes) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        // Add bulk action input
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'bulk_action';
        actionInput.value = action;
        form.appendChild(actionInput);
        
        // Add request IDs
        checkedBoxes.forEach(checkbox => {
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'request_ids[]';
            idInput.value = checkbox.value;
            form.appendChild(idInput);
        });
        
        document.body.appendChild(form);
        form.submit();
    }
    
    // Initialize
    updateBulkActions();
});
</script>
