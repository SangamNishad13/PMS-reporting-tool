<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $statusId = (int)$_POST['status_id'];
        $statusName = sanitizeInput($_POST['status_name']);
        $statusColor = sanitizeInput($_POST['status_color']);
        $statusCategory = sanitizeInput($_POST['status_category']);
        $statusPoints = (int)$_POST['status_points'];
        $isQa = isset($_POST['is_qa']) ? 1 : 0;
        
        $stmt = $db->prepare("
            UPDATE issue_statuses 
            SET name = ?, 
                color = ?,
                category = ?, 
                points = ?,
                is_qa = ?
            WHERE id = ?
        ");
        
        if ($stmt->execute([$statusName, $statusColor, $statusCategory, $statusPoints, $isQa, $statusId])) {
            $_SESSION['success'] = "Issue Status updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update issue status.";
        }
        
        header("Location: issue_statuses.php");
        exit;
    }
    
    if (isset($_POST['add_status'])) {
        $statusName = sanitizeInput($_POST['status_name']);
        $statusColor = sanitizeInput($_POST['status_color']);
        $statusCategory = sanitizeInput($_POST['status_category']);
        $statusPoints = (int)$_POST['status_points'];
        $isQa = isset($_POST['is_qa']) ? 1 : 0;
        
        $stmt = $db->prepare("
            INSERT INTO issue_statuses (name, color, category, points, is_qa)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$statusName, $statusColor, $statusCategory, $statusPoints, $isQa])) {
            $_SESSION['success'] = "Issue Status added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add issue status. Status name might already exist.";
        }
        
        header("Location: issue_statuses.php");
        exit;
    }
}

// Get all issue statuses
$statuses = $db->query("SELECT * FROM issue_statuses ORDER BY name ASC")->fetchAll();

// Get usage statistics
$usageStats = [];
foreach ($statuses as $status) {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM issues WHERE status_id = ?");
    $stmt->execute([$status['id']]);
    $usageStats[$status['id']] = $stmt->fetch()['count'];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-tags text-primary"></i> Issue Status Master</h2>
        <div>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStatusModal">
                <i class="fas fa-plus"></i> Add New Status
            </button>
            <a href="<?php echo $baseDir; ?>/modules/admin/dashboard.php" class="btn btn-secondary ms-2">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
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

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Issue Status Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Color</th>
                                    <th>Points</th>
                                    <th>QA Status</th>
                                    <th>Usage Count</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statuses as $status): ?>
                                    <tr>
                                        <td><?php echo $status['id']; ?></td>
                                        <td><?php echo htmlspecialchars($status['name']); ?></td>
                                        <td><?php echo htmlspecialchars($status['category'] ?? ''); ?></td>
                                        <td>
                                            <span class="badge" style="background-color: <?php echo $status['color']; ?>; color: white;">
                                                <?php echo htmlspecialchars($status['color']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $status['points']; ?></td>
                                        <td>
                                            <?php if ($status['is_qa']): ?>
                                                <span class="badge bg-info">QA</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Regular</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $usageStats[$status['id']] ?? 0; ?> issues</td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editStatus(<?php echo $status['id']; ?>, '<?php echo addslashes($status['name']); ?>', '<?php echo addslashes($status['category'] ?? ''); ?>', '<?php echo $status['color']; ?>', <?php echo $status['points']; ?>, <?php echo $status['is_qa']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Status Modal -->
<div class="modal fade" id="addStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Issue Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Status Name *</label>
                        <input type="text" class="form-control" name="status_name" required>
                        <small class="text-muted">Display name (e.g., 'Open', 'In Progress')</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control" name="status_category" placeholder="e.g., open, in_progress, closed">
                        <small class="text-muted">Optional category for grouping</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Color</label>
                        <input type="color" class="form-control" name="status_color" value="#6c757d">
                        <small class="text-muted">Badge color for the status</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Points</label>
                        <input type="number" class="form-control" name="status_points" value="0">
                        <small class="text-muted">Point value for performance tracking</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_qa" id="add_is_qa">
                            <label class="form-check-label" for="add_is_qa">
                                QA Status
                            </label>
                        </div>
                        <small class="text-muted">Check if this is a QA-specific status</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_status" class="btn btn-success">Add Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Status Modal -->
<div class="modal fade" id="editStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Issue Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="status_id" id="edit_status_id">
                    <div class="mb-3">
                        <label class="form-label">Status Name *</label>
                        <input type="text" class="form-control" name="status_name" id="edit_status_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control" name="status_category" id="edit_status_category">
                        <small class="text-muted">Optional category for grouping</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Color</label>
                        <input type="color" class="form-control" name="status_color" id="edit_status_color">
                        <small class="text-muted">Badge color for the status</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Points</label>
                        <input type="number" class="form-control" name="status_points" id="edit_status_points">
                        <small class="text-muted">Point value for performance tracking</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_qa" id="edit_is_qa">
                            <label class="form-check-label" for="edit_is_qa">
                                QA Status
                            </label>
                        </div>
                        <small class="text-muted">Check if this is a QA-specific status</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editStatus(id, name, category, color, points, isQa) {
    document.getElementById('edit_status_id').value = id;
    document.getElementById('edit_status_name').value = name;
    document.getElementById('edit_status_category').value = category || '';
    document.getElementById('edit_status_color').value = color;
    document.getElementById('edit_status_points').value = points;
    document.getElementById('edit_is_qa').checked = isQa == 1;
    
    new bootstrap.Modal(document.getElementById('editStatusModal')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>