<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username = sanitizeInput($_POST['username']);
        $email = sanitizeInput($_POST['email']);
        $fullName = sanitizeInput($_POST['full_name']);
        $role = sanitizeInput($_POST['role']);
        $rawPassword = isset($_POST['password']) ? $_POST['password'] : '';
        $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

        // Server-side password confirmation check
        if ($rawPassword === '' || $rawPassword !== $confirmPassword) {
            $_SESSION['error'] = "Passwords are empty or do not match.";
        } else {
            $password = password_hash($rawPassword, PASSWORD_DEFAULT);

            // Check for existing username/email to provide friendly message
            $chk = $db->prepare("SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
            $chk->execute([$username, $email]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if (strtolower($existing['username']) === strtolower($username)) {
                    $_SESSION['error'] = "Username already exists. Please choose a different username.";
                } elseif (strtolower($existing['email']) === strtolower($email)) {
                    $_SESSION['error'] = "Email already in use. Please use a different email.";
                } else {
                    $_SESSION['error'] = "A user with the same username or email already exists.";
                }
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO users (username, email, password, full_name, role, force_password_reset, can_manage_issue_config, can_manage_devices) VALUES (?, ?, ?, ?, ?, 1, ?, ?)"
                );
                $canManageConfig = isset($_POST['can_manage_issue_config']) ? 1 : 0;
                $canManageDevices = isset($_POST['can_manage_devices']) ? 1 : 0;

                try {
                    if ($stmt->execute([$username, $email, $password, $fullName, $role, $canManageConfig, $canManageDevices])) {
                        $_SESSION['success'] = "User added successfully! They will be asked to reset their password on first login.";
                    } else {
                        $_SESSION['error'] = "Failed to add user. Please try again.";
                    }
                } catch (PDOException $e) {
                    // Duplicate key race or other DB issue
                    if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                        $_SESSION['error'] = "Failed to add user: username or email already exists.";
                    } else {
                        // For other errors, log and show generic message
                        error_log('Add user error: ' . $e->getMessage());
                        $_SESSION['error'] = "An unexpected database error occurred while adding the user.";
                    }
                }
            }
        }
    } elseif (isset($_POST['update_user'])) {
        $userId = $_POST['user_id'];
        $fullName = sanitizeInput($_POST['full_name']);
        $role = sanitizeInput($_POST['role']);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $canManageConfig = isset($_POST['can_manage_issue_config']) ? 1 : 0;
        $canManageDevices = isset($_POST['can_manage_devices']) ? 1 : 0;

        // Fetch previous permissions for notification diff
        $prevStmt = $db->prepare("SELECT can_manage_issue_config, can_manage_devices FROM users WHERE id = ? LIMIT 1");
        $prevStmt->execute([$userId]);
        $prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: ['can_manage_issue_config' => 0, 'can_manage_devices' => 0];
        
        $stmt = $db->prepare("
            UPDATE users 
            SET full_name = ?, role = ?, is_active = ?, can_manage_issue_config = ?, can_manage_devices = ?
            WHERE id = ?
        ");
        
        $stmt->execute([$fullName, $role, $isActive, $canManageConfig, $canManageDevices, $userId]);

        // Notify user if permission changed
        $baseDir = getBaseDir();
        if ((int)$prev['can_manage_issue_config'] !== (int)$canManageConfig) {
            $msg = $canManageConfig ? 'You have been granted Issue Config access.' : 'Your Issue Config access has been removed.';
            createNotification($db, (int)$userId, 'system', $msg, $baseDir . "/modules/admin/issue_config.php");
        }
        if ((int)$prev['can_manage_devices'] !== (int)$canManageDevices) {
            $msg = $canManageDevices ? 'You have been granted Device Management access.' : 'Your Device Management access has been removed.';
            createNotification($db, (int)$userId, 'system', $msg, $baseDir . "/modules/admin/devices.php");
        }
        $_SESSION['success'] = "User updated successfully!";
    } elseif (isset($_POST['reset_password'])) {
        $userId = $_POST['user_id'];
        $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

        // Update password and force the user to reset their password on next login
        $db->prepare("UPDATE users SET password = ?, force_password_reset = 1 WHERE id = ?")
           ->execute([$password, $userId]);
        $_SESSION['success'] = "Password reset successfully! The user will be required to change their password on next login.";
    } elseif (isset($_POST['delete_user'])) {
        $userId = $_POST['user_id'];
        
        // Check if user is the current user
        if ($userId == $_SESSION['user_id']) {
            $_SESSION['error'] = "You cannot delete your own account!";
        } else {
            // Check for dependencies (Projects, Assignments, etc.)
            $hasData = false;
            
            // Check Projects
            $stmt = $db->prepare("SELECT COUNT(*) FROM projects WHERE created_by = ? OR project_lead_id = ?");
            $stmt->execute([$userId, $userId]);
            if ($stmt->fetchColumn() > 0) $hasData = true;
            
            if (!$hasData) {
                // Check Assignments
                $stmt = $db->prepare("SELECT COUNT(*) FROM user_assignments WHERE user_id = ? OR assigned_by = ?");
                $stmt->execute([$userId, $userId]);
                if ($stmt->fetchColumn() > 0) $hasData = true;
            }
            
            if (!$hasData) {
                // Check Testing/QA Results
                $stmt = $db->prepare("SELECT COUNT(*) FROM testing_results WHERE tester_id = ?");
                $stmt->execute([$userId]);
                if ($stmt->fetchColumn() > 0) $hasData = true;
                
                $stmt = $db->prepare("SELECT COUNT(*) FROM qa_results WHERE qa_id = ?");
                $stmt->execute([$userId]);
                if ($stmt->fetchColumn() > 0) $hasData = true;
            }

            if ($hasData) {
                $_SESSION['error'] = "Cannot delete user with existing data (projects/tasks). Please deactivate them instead.";
            } else {
                // Nullify references to this user in tables that should not block deletion
                // Use try/catch around each in case the table/column doesn't exist in some installs
                $nullifyQueries = [
                    "UPDATE activity_log SET user_id = NULL WHERE user_id = ?",
                    "UPDATE chat_messages SET user_id = NULL WHERE user_id = ?",
                    "UPDATE project_assets SET created_by = NULL WHERE created_by = ?",
                    "UPDATE project_pages SET created_by = NULL WHERE created_by = ?",
                    "UPDATE project_pages SET at_tester_id = NULL WHERE at_tester_id = ?",
                    "UPDATE project_pages SET ft_tester_id = NULL WHERE ft_tester_id = ?",
                    "UPDATE project_pages SET qa_id = NULL WHERE qa_id = ?",
                    "UPDATE projects SET created_by = NULL WHERE created_by = ?",
                    "UPDATE projects SET project_lead_id = NULL WHERE project_lead_id = ?",
                    "UPDATE testing_results SET tester_id = NULL WHERE tester_id = ?",
                    "UPDATE qa_results SET qa_id = NULL WHERE qa_id = ?",
                    "UPDATE user_assignments SET user_id = NULL WHERE user_id = ?",
                    "UPDATE user_assignments SET assigned_by = NULL WHERE assigned_by = ?",
                    "UPDATE notifications SET user_id = NULL WHERE user_id = ?",
                    "UPDATE generic_tasks SET user_id = NULL WHERE user_id = ?"
                ];

                foreach ($nullifyQueries as $q) {
                    try {
                        $db->prepare($q)->execute([$userId]);
                    } catch (PDOException $e) {
                        // ignore errors for missing tables/columns or other non-fatal issues
                    }
                }

                // Finally delete the user
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                if ($stmt->execute([$userId])) {
                    $_SESSION['success'] = "User deleted successfully!";
                } else {
                    $_SESSION['error'] = "Failed to delete user due to database constraints.";
                }
            }
        }
    }
    
    redirect($_SERVER['PHP_SELF']);
}

// AJAX: return user details (projects, pages, assignments, activity)
if (isset($_GET['action']) && $_GET['action'] === 'get_user_details' && isset($_GET['user_id'])) {
    try {
        $uid = intval($_GET['user_id']);
        $out = ['user' => null, 'projects' => [], 'pages' => [], 'assignments' => [], 'activity' => []];

        // Basic user info
        $stmt = $db->prepare("SELECT id, username, full_name, email, role, is_active, can_manage_issue_config, can_manage_devices FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $out['user'] = $stmt->fetch(PDO::FETCH_ASSOC);

        // Projects where user is created_by or project_lead
        $stmt = $db->prepare("SELECT id, title, po_number, project_lead_id, created_by FROM projects WHERE created_by = ? OR project_lead_id = ? ORDER BY title");
        $stmt->execute([$uid, $uid]);
        $out['projects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pages where user is involved (use correct column names: page_name)
        $stmt = $db->prepare(
            "SELECT pp.id, pp.page_name AS title, pp.page_number, pp.at_tester_id, pp.ft_tester_id, pp.qa_id, pp.created_by
             FROM project_pages pp
             WHERE pp.created_by = ? OR pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ?
             OR (pp.at_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.at_tester_ids, JSON_ARRAY(?)))
             OR (pp.ft_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.ft_tester_ids, JSON_ARRAY(?)))
             ORDER BY pp.page_name"
        );
        $stmt->execute([$uid, $uid, $uid, $uid, $uid, $uid]);
        $out['pages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Assignments
        $stmt = $db->prepare("
            SELECT ua.*, p.title as project_title, u.full_name as assigned_by_name 
            FROM user_assignments ua 
            LEFT JOIN projects p ON ua.project_id = p.id
            LEFT JOIN users u ON ua.assigned_by = u.id
            WHERE ua.user_id = ? OR ua.assigned_by = ? 
            ORDER BY ua.assigned_at DESC
        ");
        $stmt->execute([$uid, $uid]);
        $out['assignments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Activity (limit 100)
        $stmt = $db->prepare("
            SELECT al.*, p.title as project_title
            FROM activity_log al 
            LEFT JOIN projects p ON al.entity_type = 'project' AND al.entity_id = p.id
            WHERE al.user_id = ? 
            ORDER BY al.created_at DESC LIMIT 100
        ");
        $stmt->execute([$uid]);
        $out['activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($out);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Get all users
$users = $db->query("
    SELECT u.*, 
           COUNT(DISTINCT p.id) as project_count,
           COUNT(DISTINCT pp.id) as page_count
    FROM users u
    LEFT JOIN projects p ON u.id = p.project_lead_id
    LEFT JOIN project_pages pp ON (
        u.id = pp.at_tester_id OR u.id = pp.ft_tester_id OR u.id = pp.qa_id
        OR (pp.at_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.at_tester_ids, JSON_ARRAY(u.id)))
        OR (pp.ft_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.ft_tester_ids, JSON_ARRAY(u.id)))
    )
    GROUP BY u.id
    ORDER BY u.role, u.full_name
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<style>
#usersTable_wrapper .dataTables_length select {
    min-width: 86px;
    padding-right: 2rem !important;
    background-position: right 0.6rem center;
    text-overflow: clip;
}
</style>
<?php
// Render compact fixed-position flash messages (top-right) so they don't push content
if (!empty($_SESSION['error'])) {
    $err = htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8');
    echo '<div class="position-fixed top-0 end-0 p-3" style="z-index:10800; max-width:420px;">
            <div class="alert alert-danger alert-dismissible" role="alert" style="margin:0;padding:.5rem .75rem;font-size:.95rem;box-shadow:0 6px 18px rgba(0,0,0,.12);">
                ' . $err . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="margin-left:.5rem"></button>
            </div>
          </div>';
    unset($_SESSION['error']);
}
if (!empty($_SESSION['success'])) {
    $msg = htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8');
    echo '<div class="position-fixed top-0 end-0 p-3" style="z-index:10800; max-width:420px;">
            <div class="alert alert-info alert-dismissible" role="alert" style="margin:0;padding:.45rem .7rem;font-size:.9rem;box-shadow:0 6px 18px rgba(0,0,0,.08);">
                ' . $msg . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="margin-left:.5rem"></button>
            </div>
          </div>';
    unset($_SESSION['success']);
}
?>
<div class="container-fluid">
    <h2>User Management</h2>
    
    <!-- Add User Button -->
    <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="fas fa-user-plus"></i> Add New User
    </button>
    
    <!-- Users Table -->

<!-- Autofocus the close button of the top-most toast/alert for keyboard users -->
<?php
echo '<script>(function(){try{function focusClose(){var container=document.querySelector(".position-fixed.top-0.end-0");if(!container) return;var alertEl=container.querySelector(".alert");if(!alertEl) return;var btn=alertEl.querySelector(".btn-close");if(btn){btn.tabIndex=-1;btn.focus();}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",focusClose);}else{focusClose();}}catch(e){}})();</script>';
?>
<div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="usersTable" class="table table-striped dataTable">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Projects</th>
                            <th>Pages</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user['username']; ?></td>
                            <td><?php echo renderUserNameLink(['id'=>$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role']]); ?></td>
                            <td><?php echo $user['email']; ?></td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $user['role'] === 'admin' ? 'danger' : 
                                         ($user['role'] === 'project_lead' ? 'warning' : 'info');
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                </span>
                            </td>
                            <td><?php echo $user['project_count']; ?></td>
                            <td><?php echo $user['page_count']; ?></td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editUserModal<?php echo $user['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-info" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#resetPasswordModal<?php echo $user['id']; ?>">
                                    <i class="fas fa-key"></i>
                                </button>
                                    <button type="button" class="btn btn-sm btn-secondary view-user-btn" data-user-id="<?php echo $user['id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#deleteUserModal<?php echo $user['id']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        
                        <!-- Edit User Modal -->
                        <div class="modal fade" id="editUserModal<?php echo $user['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit User: <?php echo renderUserNameLink(['id'=>$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role']]); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label>Full Name *</label>
                                                <input type="text" name="full_name" class="form-control" 
                                                       value="<?php echo $user['full_name']; ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label>Role *</label>
                                                <select name="role" class="form-select" required>
                                                    <option value="super_admin" <?php echo $user['role'] === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                    <option value="project_lead" <?php echo $user['role'] === 'project_lead' ? 'selected' : ''; ?>>Project Lead</option>
                                                    <option value="qa" <?php echo $user['role'] === 'qa' ? 'selected' : ''; ?>>QA</option>
                                                    <option value="at_tester" <?php echo $user['role'] === 'at_tester' ? 'selected' : ''; ?>>AT Tester</option>
                                                    <option value="ft_tester" <?php echo $user['role'] === 'ft_tester' ? 'selected' : ''; ?>>FT Tester</option>
                                                </select>
                                            </div>
                                            <div class="mb-3 form-check">
                                                <input type="checkbox" name="is_active" class="form-check-input" 
                                                       id="active<?php echo $user['id']; ?>" 
                                                       <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="active<?php echo $user['id']; ?>">
                                                    Active User
                                                </label>
                                            </div>
                                            <div class="mb-3 form-check">
                                                <input type="checkbox" name="can_manage_issue_config" class="form-check-input" 
                                                       id="config<?php echo $user['id']; ?>" 
                                                       <?php echo !empty($user['can_manage_issue_config']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="config<?php echo $user['id']; ?>">
                                                    Can Manage Issue Config
                                                </label>
                                            </div>
                                            <div class="mb-3 form-check">
                                                <input type="checkbox" name="can_manage_devices" class="form-check-input" 
                                                       id="devicesPerm<?php echo $user['id']; ?>" 
                                                       <?php echo !empty($user['can_manage_devices']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="devicesPerm<?php echo $user['id']; ?>">
                                                    Can Manage Devices
                                                </label>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" name="update_user" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Reset Password Modal -->
                        <div class="modal fade" id="resetPasswordModal<?php echo $user['id']; ?>" tabindex="-1">
                            <!-- ... existing reset password modal content ... -->
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reset Password for <?php echo renderUserNameLink(['id'=>$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role']]); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label>New Password *</label>
                                                <input type="password" name="new_password" class="form-control" required>
                                            </div>
                                            <div class="mb-3">
                                                <label>Confirm Password *</label>
                                                <input type="password" name="confirm_password" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Delete User Modal -->
                        <div class="modal fade" id="deleteUserModal<?php echo $user['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title text-danger">Delete User</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Are you sure you want to delete user <strong><?php echo renderUserNameLink(['id'=>$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role']]); ?></strong>?</p>
                                            <p class="text-danger"><small>Note: This action cannot be undone. You can only delete users who have no associated projects or tasks.</small></p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Username *</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Email *</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Role *</label>
                        <select name="role" class="form-select" required>
                            <option value="super_admin">Super Admin</option>
                            <option value="admin">Admin</option>
                            <option value="project_lead">Project Lead</option>
                            <option value="qa">QA</option>
                            <option value="at_tester">AT Tester</option>
                            <option value="ft_tester">FT Tester</option>
                        </select>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="can_manage_issue_config" class="form-check-input" id="addConfigPerm">
                        <label class="form-check-label" for="addConfigPerm">
                            Can Manage Issue Config
                        </label>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="can_manage_devices" class="form-check-input" id="addDevicesPerm">
                        <label class="form-check-label" for="addDevicesPerm">
                            Can Manage Devices
                        </label>
                    </div>
                    <div class="mb-3">
                        <label>Password *</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Password confirmation validation
    $('form').on('submit', function() {
        var password = $('input[name="password"]').val();
        var confirmPassword = $('input[name="confirm_password"]').val();
        
        if (password && confirmPassword && password !== confirmPassword) {
            showToast('Passwords do not match!', 'warning');
            return false;
        }
        return true;
    });
});
</script>
<!-- User Details Modal -->
<div class="modal fade" id="viewUserModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">User Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="viewUserContent">
          <p><strong>Loading...</strong></p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
$(document).on('click', '.view-user-btn', function() {
    var uid = $(this).data('user-id');
    $('#viewUserContent').html('<p><strong>Loading...</strong></p>');
    var modal = new bootstrap.Modal(document.getElementById('viewUserModal'));
    modal.show();

    $.ajax({
        url: '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/users.php',
        method: 'GET',
        data: { action: 'get_user_details', user_id: uid },
        success: function(resp) {
            try {
                var data = typeof resp === 'object' ? resp : JSON.parse(resp);
                if (data.error) {
                    $('#viewUserContent').html('<p class="text-danger">Error: ' + $('<div>').text(data.error).html() + '</p>');
                    return;
                }
                if (!data.user) {
                    $('#viewUserContent').html('<p class="text-danger">User not found.</p>');
                    return;
                }

                var html = [];
                html.push('<h5>' + $('<div>').text(data.user.full_name).html() + ' <small class="text-muted">(' + $('<div>').text(data.user.username).html() + ')</small></h5>');
                html.push('<p><strong>Email:</strong> ' + $('<div>').text(data.user.email).html() + ' &nbsp; <strong>Role:</strong> ' + $('<div>').text(data.user.role).html() + '</p>');
                if (data.user.can_manage_issue_config == 1) {
                    html.push('<p><span class="badge bg-primary me-2">Has Issue Config Access</span></p>');
                }
                if (data.user.can_manage_devices == 1) {
                    html.push('<p><span class="badge bg-success">Can Manage Devices</span></p>');
                }

                // Projects
                html.push('<h6>Projects (' + (data.projects ? data.projects.length : 0) + ')</h6>');
                if (data.projects && data.projects.length) {
                    html.push('<ul>');
                    data.projects.forEach(function(p) {
                        html.push('<li>' + $('<div>').text(p.title).html() + ' <small class="text-muted">(' + $('<div>').text(p.po_number||'').html() + ')</small></li>');
                    });
                    html.push('</ul>');
                } else {
                    html.push('<p class="text-muted">No projects.</p>');
                }

                // Pages
                html.push('<h6>Pages (' + (data.pages ? data.pages.length : 0) + ')</h6>');
                if (data.pages && data.pages.length) {
                    html.push('<ul>');
                    data.pages.forEach(function(pg) {
                        html.push('<li>' + $('<div>').text(pg.title).html() + ' <small class="text-muted">(ID ' + pg.id + ')</small></li>');
                    });
                    html.push('</ul>');
                } else {
                    html.push('<p class="text-muted">No pages.</p>');
                }

                // Assignments
                html.push('<h6>Assignments (' + (data.assignments ? data.assignments.length : 0) + ')</h6>');
                if (data.assignments && data.assignments.length) {
                    html.push('<table class="table table-sm"><thead><tr><th>Project</th><th>Role</th><th>Assigned By</th><th>At</th></tr></thead><tbody>');
                    data.assignments.forEach(function(a) {
                        var proj = a.project_title ? $('<div>').text(a.project_title).html() : (a.project_id || 'N/A');
                        var by = a.assigned_by_name ? $('<div>').text(a.assigned_by_name).html() : (a.assigned_by || 'System');
                        html.push('<tr><td>' + proj + '</td><td>' + (a.role||'') + '</td><td>' + by + '</td><td>' + (a.assigned_at||'') + '</td></tr>');
                    });
                    html.push('</tbody></table>');
                } else {
                    html.push('<p class="text-muted">No assignments.</p>');
                }

                // Activity
                html.push('<h6>Recent Activity (' + (data.activity ? data.activity.length : 0) + ')</h6>');
                if (data.activity && data.activity.length) {
                    html.push('<ul>');
                    data.activity.forEach(function(a) {
                        var entity = '';
                        if (a.entity_type === 'project' && a.project_title) {
                            entity = ' - Project: <strong>' + $('<div>').text(a.project_title).html() + '</strong>';
                        } else if (a.entity_type && a.entity_id) {
                            entity = ' - ' + a.entity_type + ' ' + a.entity_id;
                        }
                        
                        // Parse details if available for more context
                        if (a.details) {
                            try {
                                var details = JSON.parse(a.details);
                                if (details.title) entity += ' ("' + $('<div>').text(details.title).html() + '")';
                                else if (details.page_name) entity += ' (Page: "' + $('<div>').text(details.page_name).html() + '")';
                                else if (details.asset_name) entity += ' (Asset: "' + $('<div>').text(details.asset_name).html() + '")';
                            } catch(e) {}
                        }

                        html.push('<li><small class="text-muted">[' + (a.created_at||'') + ']</small> ' + $('<div>').text(a.action).html() + entity + '</li>');
                    });
                    html.push('</ul>');
                } else {
                    html.push('<p class="text-muted">No recent activity.</p>');
                }

                $('#viewUserContent').html(html.join(''));
            } catch (e) {
                $('#viewUserContent').html('<p class="text-danger">Failed to load details. Server response:<pre>' + $('<div>').text(resp).html() + '</pre></p>');
                console.error('Failed parsing user-details response', resp, e);
            }
        },
        error: function(xhr) {
            var body = xhr.responseText || xhr.statusText || '';
            $('#viewUserContent').html('<p class="text-danger">Request failed: ' + xhr.status + ' ' + $('<div>').text(body).html() + '</p>');
            console.error('User details request failed', xhr.status, body);
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
