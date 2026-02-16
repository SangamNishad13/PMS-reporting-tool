<?php
require_once '../../includes/auth.php';
requireAdmin();

$page_title = 'Device Permissions';
$baseDir = getBaseDir();
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_device_perm'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $allow = isset($_POST['can_manage_devices']) ? 1 : 0;
    if ($userId > 0) {
        $check = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $check->execute([$userId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['role'], ['admin','super_admin'])) {
            $_SESSION['error'] = "Role-based device access for admin users cannot be changed here.";
        } else {
            $prevStmt = $db->prepare("SELECT can_manage_devices FROM users WHERE id = ? LIMIT 1");
            $prevStmt->execute([$userId]);
            $prev = (int)($prevStmt->fetchColumn() ?? 0);
            $upd = $db->prepare("UPDATE users SET can_manage_devices = ? WHERE id = ?");
            $upd->execute([$allow, $userId]);
            if ($prev !== (int)$allow) {
                $msg = $allow ? 'You have been granted Device Management access.' : 'Your Device Management access has been removed.';
                createNotification($db, (int)$userId, 'system', $msg, $baseDir . "/modules/admin/devices.php");
            }
            $_SESSION['success'] = "Device permission updated.";
        }
    }
    header("Location: " . $baseDir . "/modules/admin/device_permissions.php");
    exit;
}

$stmt = $db->query("
    SELECT id, full_name, username, email, role, is_active, can_manage_devices
    FROM users
    ORDER BY role, full_name
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3 align-items-center">
        <div class="col">
            <h2><i class="fas fa-user-shield"></i> Device Permissions</h2>
            <p class="text-muted mb-0">Manage who can add/edit/delete/assign/return devices</p>
        </div>
        <div class="col-auto">
            <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/users.php" class="btn btn-outline-primary">
                Manage Users
            </a>
        </div>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Permission</th>
                            <th>Manage</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted p-4">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <?php
                                $roleLabel = ucfirst(str_replace('_', ' ', $u['role']));
                                $permLabel = in_array($u['role'], ['admin','super_admin']) ? 'Role-based' : 'Explicit';
                                $isRoleBased = in_array($u['role'], ['admin','super_admin']);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if (!empty($u['is_active'])): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo $permLabel; ?></span>
                                </td>
                                <td>
                                    <form method="POST" class="d-flex align-items-center gap-2" data-confirm="device-perm">
                                        <input type="hidden" name="update_device_perm" value="1">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <div class="form-check m-0">
                                            <input class="form-check-input" type="checkbox" name="can_manage_devices"
                                                id="perm_<?php echo (int)$u['id']; ?>"
                                                <?php echo (!empty($u['can_manage_devices']) || $isRoleBased) ? 'checked' : ''; ?>
                                                <?php echo $isRoleBased ? 'disabled' : ''; ?>>
                                        </div>
                                        <?php if (!$isRoleBased): ?>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                        <?php else: ?>
                                            <span class="text-muted small">Locked</span>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[data-confirm="device-perm"]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const onConfirm = () => form.submit();
            if (typeof confirmModal === 'function') {
                confirmModal('Save device permission changes for this user?', onConfirm);
            } else if (confirm('Save device permission changes for this user?')) {
                onConfirm();
            }
        });
    });
});
</script>
