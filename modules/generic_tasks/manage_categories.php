<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'super_admin']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    $desc = trim($_POST['description']);
    
    if (!empty($name)) {
        $stmt = $db->prepare("INSERT INTO generic_task_categories (name, description, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$name, $desc, $userId]);
        $_SESSION['success'] = "Category added successfully.";
        header("Location: manage_categories.php");
        exit;
    }
}

// Handle Toggle Status
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    // Toggle active status
    $db->prepare("UPDATE generic_task_categories SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    header("Location: manage_categories.php");
    exit;
}

// Handle Delete Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $catId = $_POST['category_id'];
    
    // Check if category has tasks
    $stmt = $db->prepare("SELECT COUNT(*) FROM user_generic_tasks WHERE category_id = ?");
    $stmt->execute([$catId]);
    
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = "Cannot delete category with existing task logs. Try deactivating it instead.";
    } else {
        $stmt = $db->prepare("DELETE FROM generic_task_categories WHERE id = ?");
        if ($stmt->execute([$catId])) {
            $_SESSION['success'] = "Category deleted successfully.";
        } else {
            $_SESSION['error'] = "Failed to delete category.";
        }
    }
    header("Location: manage_categories.php");
    exit;
}

$categories = $db->query("SELECT * FROM generic_task_categories ORDER BY name")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Manage Task Categories</h2>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Tasks
        </a>
    </div>
    
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5>Add New Category</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Category Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <button type="submit" name="add_category" class="btn btn-primary w-100">Create Category</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>Existing Categories</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cat['description']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $cat['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="manage_categories.php?toggle=1&id=<?php echo $cat['id']; ?>" 
                                           class="btn btn-sm btn-<?php echo $cat['is_active'] ? 'warning' : 'success'; ?>">
                                            <?php echo $cat['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-danger" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#deleteCatModal<?php echo $cat['id']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>

                                        <!-- Delete Category Modal -->
                                        <div class="modal fade" id="deleteCatModal<?php echo $cat['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Delete Category</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body text-start">
                                                            <p>Are you sure you want to delete category <strong><?php echo htmlspecialchars($cat['name']); ?></strong>?</p>
                                                            <p class="text-danger small">You can only delete categories that have no tasks logged against them.</p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="delete_category" class="btn btn-danger">Delete</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
