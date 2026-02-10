<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin', 'super_admin']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$baseDir = getBaseDir();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['grant_permissions'])) {
        $projectId = intval($_POST['project_id']);
        $targetUserId = intval($_POST['user_id']);
        $permissions = $_POST['permissions'] ?? [];
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $notes = trim($_POST['notes'] ?? '');
        
        if ($projectId && $targetUserId && !empty($permissions)) {
            try {
                $db->beginTransaction();
                
                // Get user and project details for logging
                $userStmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
                $userStmt->execute([$targetUserId]);
                $targetUser = $userStmt->fetch();
                
                $projectStmt = $db->prepare("SELECT title FROM projects WHERE id = ?");
                $projectStmt->execute([$projectId]);
                $project = $projectStmt->fetch();
                
                $grantedPermissions = [];
                
                foreach ($permissions as $permission) {
                    // Check if permission already exists
                    $checkStmt = $db->prepare("
                        SELECT id FROM project_permissions 
                        WHERE project_id = ? AND user_id = ? AND permission_type = ?
                    ");
                    $checkStmt->execute([$projectId, $targetUserId, $permission]);
                    
                    if (!$checkStmt->fetch()) {
                        // Grant new permission
                        $insertStmt = $db->prepare("
                            INSERT INTO project_permissions (project_id, user_id, permission_type, granted_by, expires_at, notes)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $insertStmt->execute([$projectId, $targetUserId, $permission, $userId, $expiresAt, $notes]);
                        $grantedPermissions[] = $permission;
                    } else {
                        // Update existing permission
                        $updateStmt = $db->prepare("
                            UPDATE project_permissions 
                            SET is_active = TRUE, granted_by = ?, expires_at = ?, notes = ?, updated_at = NOW()
                            WHERE project_id = ? AND user_id = ? AND permission_type = ?
                        ");
                        $updateStmt->execute([$userId, $expiresAt, $notes, $projectId, $targetUserId, $permission]);
                        $grantedPermissions[] = $permission;
                    }
                }
                
                // Log activity
                logActivity($db, $userId, 'grant_project_permissions', 'project', $projectId, [
                    'target_user_id' => $targetUserId,
                    'target_user_name' => $targetUser['full_name'],
                    'target_user_email' => $targetUser['email'],
                    'permissions' => $grantedPermissions,
                    'permissions_count' => count($grantedPermissions),
                    'expires_at' => $expiresAt,
                    'project_title' => $project['title']
                ]);
                
                $db->commit();
                $_SESSION['success'] = "Permissions granted successfully to " . htmlspecialchars($targetUser['full_name']) . "!";
                
            } catch (PDOException $e) {
                $db->rollBack();
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Please select a project, user, and at least one permission.";
        }
    }
    
    if (isset($_POST['revoke_permission'])) {
        $permissionId = intval($_POST['permission_id']);
        
        if ($permissionId) {
            try {
                // Get permission details before revoking
                $permStmt = $db->prepare("
                    SELECT pp.*, u.full_name as user_name, p.title as project_title 
                    FROM project_permissions pp
                    JOIN users u ON pp.user_id = u.id
                    JOIN projects p ON pp.project_id = p.id
                    WHERE pp.id = ?
                ");
                $permStmt->execute([$permissionId]);
                $permission = $permStmt->fetch();
                
                if ($permission) {
                    // Revoke permission (soft delete)
                    $revokeStmt = $db->prepare("
                        UPDATE project_permissions 
                        SET is_active = FALSE, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $revokeStmt->execute([$permissionId]);
                    
                    // Log activity
                    logActivity($db, $userId, 'revoke_project_permission', 'project', $permission['project_id'], [
                        'target_user_id' => $permission['user_id'],
                        'target_user_name' => $permission['user_name'],
                        'permission_type' => $permission['permission_type'],
                        'project_title' => $permission['project_title']
                    ]);
                    
                    $_SESSION['success'] = "Permission revoked successfully!";
                } else {
                    $_SESSION['error'] = "Permission not found.";
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['bulk_grant'])) {
        $selectedUsers = $_POST['selected_users'] ?? [];
        $bulkProjectId = intval($_POST['bulk_project_id']);
        $bulkPermissions = $_POST['bulk_permissions'] ?? [];
        $bulkExpiresAt = !empty($_POST['bulk_expires_at']) ? $_POST['bulk_expires_at'] : null;
        $bulkNotes = trim($_POST['bulk_notes'] ?? '');
        
        if (!empty($selectedUsers) && $bulkProjectId && !empty($bulkPermissions)) {
            try {
                $db->beginTransaction();
                
                $successCount = 0;
                $projectStmt = $db->prepare("SELECT title FROM projects WHERE id = ?");
                $projectStmt->execute([$bulkProjectId]);
                $project = $projectStmt->fetch();
                
                foreach ($selectedUsers as $bulkUserId) {
                    $bulkUserId = intval($bulkUserId);
                    if ($bulkUserId) {
                        foreach ($bulkPermissions as $permission) {
                            // Check if permission already exists
                            $checkStmt = $db->prepare("
                                SELECT id FROM project_permissions 
                                WHERE project_id = ? AND user_id = ? AND permission_type = ?
                            ");
                            $checkStmt->execute([$bulkProjectId, $bulkUserId, $permission]);
                            
                            if (!$checkStmt->fetch()) {
                                // Grant new permission
                                $insertStmt = $db->prepare("
                                    INSERT INTO project_permissions (project_id, user_id, permission_type, granted_by, expires_at, notes)
                                    VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                $insertStmt->execute([$bulkProjectId, $bulkUserId, $permission, $userId, $bulkExpiresAt, $bulkNotes]);
                            } else {
                                // Update existing permission
                                $updateStmt = $db->prepare("
                                    UPDATE project_permissions 
                                    SET is_active = TRUE, granted_by = ?, expires_at = ?, notes = ?, updated_at = NOW()
                                    WHERE project_id = ? AND user_id = ? AND permission_type = ?
                                ");
                                $updateStmt->execute([$userId, $bulkExpiresAt, $bulkNotes, $bulkProjectId, $bulkUserId, $permission]);
                            }
                        }
                        $successCount++;
                    }
                }
                
                // Log bulk activity
                logActivity($db, $userId, 'bulk_grant_project_permissions', 'project', $bulkProjectId, [
                    'users_count' => $successCount,
                    'permissions' => $bulkPermissions,
                    'permissions_count' => count($bulkPermissions),
                    'expires_at' => $bulkExpiresAt,
                    'project_title' => $project['title']
                ]);
                
                $db->commit();
                $_SESSION['success'] = "Permissions granted to $successCount users successfully!";
                
            } catch (PDOException $e) {
                $db->rollBack();
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Please select users, project, and permissions for bulk operation.";
        }
    }
}

// Get all projects
$projects = $db->query("SELECT id, title, po_number, status FROM projects ORDER BY title")->fetchAll();

// Get all users (excluding current admin)
$users = $db->prepare("SELECT id, full_name, email, role FROM users WHERE id != ? AND is_active = 1 ORDER BY full_name");
$users->execute([$userId]);
$users = $users->fetchAll();

// Get permission types
$permissionTypes = $db->query("
    SELECT permission_type, description, category 
    FROM project_permissions_types 
    WHERE is_active = 1 
    ORDER BY category, permission_type
")->fetchAll();

// Group permissions by category
$permissionsByCategory = [];
foreach ($permissionTypes as $perm) {
    $permissionsByCategory[$perm['category']][] = $perm;
}

// Get current permissions with filters
$selectedProject = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$selectedUser = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

$whereConditions = ["pp.is_active = 1"];
$params = [];

if ($selectedProject) {
    $whereConditions[] = "pp.project_id = ?";
    $params[] = $selectedProject;
}

if ($selectedUser) {
    $whereConditions[] = "pp.user_id = ?";
    $params[] = $selectedUser;
}

$currentPermissions = $db->prepare("
    SELECT pp.*, u.full_name as user_name, u.email as user_email, u.role as user_role,
           p.title as project_title, p.po_number,
           gb.full_name as granted_by_name,
           pt.description as permission_description, pt.category
    FROM project_permissions pp
    JOIN users u ON pp.user_id = u.id
    JOIN projects p ON pp.project_id = p.id
    LEFT JOIN users gb ON pp.granted_by = gb.id
    LEFT JOIN project_permissions_types pt ON pp.permission_type = pt.permission_type
    WHERE " . implode(" AND ", $whereConditions) . "
    ORDER BY p.title, u.full_name, pt.category, pp.permission_type
");
$currentPermissions->execute($params);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-user-shield"></i> Project-Specific Permissions</h2>
                <div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#grantPermissionModal">
                        <i class="fas fa-plus"></i> Grant Permissions
                    </button>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#bulkGrantModal">
                        <i class="fas fa-users"></i> Bulk Grant
                    </button>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Filter by Project</label>
                            <select name="project_id" class="form-select">
                                <option value="">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>" <?php echo $selectedProject == $project['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['title']); ?> (<?php echo htmlspecialchars($project['po_number']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Filter by User</label>
                            <select name="user_id" class="form-select">
                                <option value="">All Users</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $selectedUser == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-outline-primary d-block w-100">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Current Permissions -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Current Project Permissions</h5>
                </div>
                <div class="card-body">
                    <?php if ($currentPermissions->rowCount() > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>User</th>
                                    <th>Permission</th>
                                    <th>Category</th>
                                    <th>Granted By</th>
                                    <th>Granted Date</th>
                                    <th>Expires</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($perm = $currentPermissions->fetch()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($perm['project_title']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($perm['po_number']); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($perm['user_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($perm['user_email']); ?></small>
                                        <br><span class="badge bg-secondary"><?php echo ucfirst(str_replace('_', ' ', $perm['user_role'])); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo ucfirst(str_replace('_', ' ', $perm['permission_type'])); ?></strong>
                                        <?php if ($perm['permission_description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($perm['permission_description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo ucfirst($perm['category']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($perm['granted_by_name'] ?: 'System'); ?>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y H:i', strtotime($perm['granted_at'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($perm['expires_at']): ?>
                                            <?php 
                                            $isExpired = strtotime($perm['expires_at']) < time();
                                            $badgeClass = $isExpired ? 'bg-danger' : 'bg-warning';
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>">
                                                <?php echo date('M d, Y', strtotime($perm['expires_at'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form id="revokeForm_<?php echo $perm['id']; ?>" method="POST" style="display: inline;">
                                            <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                            <input type="hidden" name="revoke_permission" value="1">
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="confirmForm('revokeForm_<?php echo $perm['id']; ?>', 'Are you sure you want to revoke this permission?')">
                                                <i class="fas fa-times"></i> Revoke
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No project-specific permissions found. Use the filters above or grant new permissions.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Grant Permission Modal -->
<div class="modal fade" id="grantPermissionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Grant Project-Specific Permissions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Project *</label>
                                <select name="project_id" class="form-select" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>">
                                        <?php echo htmlspecialchars($project['title']); ?> (<?php echo htmlspecialchars($project['po_number']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">User *</label>
                                <select name="user_id" class="form-select" required>
                                    <option value="">Select User</option>
                                    <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Permissions *</label>
                        <div class="row">
                            <?php foreach ($permissionsByCategory as $category => $perms): ?>
                            <div class="col-md-6 mb-3">
                                <h6 class="text-primary"><?php echo ucfirst($category); ?></h6>
                                <?php foreach ($perms as $perm): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="permissions[]" 
                                           value="<?php echo $perm['permission_type']; ?>" 
                                           id="perm_<?php echo $perm['permission_type']; ?>">
                                    <label class="form-check-label" for="perm_<?php echo $perm['permission_type']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $perm['permission_type'])); ?>
                                        <?php if ($perm['description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($perm['description']); ?></small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Expires At (Optional)</label>
                                <input type="datetime-local" name="expires_at" class="form-control">
                                <small class="text-muted">Leave empty for permanent access</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Notes (Optional)</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Reason for granting permissions..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="grant_permissions" class="btn btn-primary">Grant Permissions</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Grant Modal -->
<div class="modal fade" id="bulkGrantModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Grant Permissions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Project *</label>
                                <select name="bulk_project_id" class="form-select" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>">
                                        <?php echo htmlspecialchars($project['title']); ?> (<?php echo htmlspecialchars($project['po_number']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Users *</label>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="selectAllUsers" onchange="toggleAllUsers(this)">
                                        <label class="form-check-label fw-bold" for="selectAllUsers">Select All Users</label>
                                    </div>
                                    <hr>
                                    <?php foreach ($users as $user): ?>
                                    <div class="form-check">
                                        <input class="form-check-input user-checkbox" type="checkbox" name="selected_users[]" 
                                               value="<?php echo $user['id']; ?>" id="bulk_user_<?php echo $user['id']; ?>">
                                        <label class="form-check-label" for="bulk_user_<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                            <small class="text-muted">(<?php echo htmlspecialchars($user['role']); ?>)</small>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Permissions *</label>
                                <div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                    <?php foreach ($permissionsByCategory as $category => $perms): ?>
                                    <div class="mb-3">
                                        <h6 class="text-primary"><?php echo ucfirst($category); ?></h6>
                                        <?php foreach ($perms as $perm): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="bulk_permissions[]" 
                                                   value="<?php echo $perm['permission_type']; ?>" 
                                                   id="bulk_perm_<?php echo $perm['permission_type']; ?>">
                                            <label class="form-check-label" for="bulk_perm_<?php echo $perm['permission_type']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $perm['permission_type'])); ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Expires At (Optional)</label>
                                <input type="datetime-local" name="bulk_expires_at" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Notes (Optional)</label>
                                <textarea name="bulk_notes" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="bulk_grant" class="btn btn-success">Bulk Grant Permissions</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleAllUsers(checkbox) {
    const userCheckboxes = document.querySelectorAll('.user-checkbox');
    userCheckboxes.forEach(cb => cb.checked = checkbox.checked);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>