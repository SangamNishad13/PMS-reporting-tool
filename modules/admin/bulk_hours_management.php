<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hours_validation.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead']); // Admin and Project Lead can manage hours

$db = Database::getInstance();

// Handle bulk operations
if ($_POST) {
    if (isset($_POST['bulk_update'])) {
        $updates = $_POST['updates'] ?? [];
        $reason = $_POST['bulk_reason'] ?? '';
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($updates as $assignmentId => $newHours) {
            if (!empty($newHours) && is_numeric($newHours)) {
                try {
                    // Get current assignment details
                    $getQuery = "
                        SELECT ua.*, u.full_name, p.title as project_title, p.id as project_id
                        FROM user_assignments ua 
                        JOIN users u ON ua.user_id = u.id 
                        JOIN projects p ON ua.project_id = p.id 
                        WHERE ua.id = ?
                    ";
                    $stmt = $db->prepare($getQuery);
                    $stmt->execute([$assignmentId]);
                    $assignment = $stmt->fetch();
                    
                    if ($assignment) {
                        // Validate hours allocation
                        $validation = validateHoursAllocation($db, $assignment['project_id'], $newHours, $assignmentId);
                        
                        if (!$validation['valid']) {
                            $errorCount++;
                            continue; // Skip this assignment
                        }
                        
                        // Update hours
                        $updateQuery = "UPDATE user_assignments SET hours_allocated = ?, updated_at = NOW() WHERE id = ?";
                        $stmt = $db->prepare($updateQuery);
                        $stmt->execute([$newHours, $assignmentId]);
                        
                        // Log the change
                        logHoursActivity($db, $_SESSION['user_id'], 'bulk_hours_updated', $assignmentId, [
                            'target_user_id' => $assignment['user_id'],
                            'target_user_name' => $assignment['full_name'],
                            'project_id' => $assignment['project_id'],
                            'project_title' => $assignment['project_title'],
                            'old_hours' => $assignment['hours_allocated'],
                            'new_hours' => $newHours,
                            'reason' => $reason,
                            'updated_by' => $_SESSION['user_id']
                        ]);
                        $stmt->execute([$_SESSION['user_id'], $assignmentId, $logDetails]);
                        
                        $successCount++;
                    }
                } catch (Exception $e) {
                    $errorCount++;
                }
            }
        }
        
        if ($successCount > 0) {
            $_SESSION['success'] = "Successfully updated $successCount assignments.";
        }
        if ($errorCount > 0) {
            $_SESSION['error'] = "Failed to update $errorCount assignments.";
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Get filters
$projectFilter = $_GET['project_filter'] ?? '';
$userFilter = $_GET['user_filter'] ?? '';
$roleFilter = $_GET['role_filter'] ?? '';

// Build query
$whereConditions = ["p.status NOT IN ('completed', 'cancelled')"];
$params = [];

if ($projectFilter) {
    $whereConditions[] = "ua.project_id = ?";
    $params[] = $projectFilter;
}

if ($userFilter) {
    $whereConditions[] = "ua.user_id = ?";
    $params[] = $userFilter;
}

if ($roleFilter) {
    $whereConditions[] = "ua.role = ?";
    $params[] = $roleFilter;
}

$whereClause = implode(' AND ', $whereConditions);

// Get assignments with project hours info
$assignmentsQuery = "
    SELECT ua.*, u.full_name, u.role as user_role, p.title as project_title, p.po_number, p.status as project_status, p.total_hours,
           COALESCE(ptl.utilized_hours, 0) as utilized_hours,
           (SELECT COALESCE(SUM(hours_allocated), 0) FROM user_assignments WHERE project_id = ua.project_id) as total_allocated_hours
    FROM user_assignments ua
    JOIN users u ON ua.user_id = u.id
    JOIN projects p ON ua.project_id = p.id
    LEFT JOIN (
        SELECT user_id, project_id, SUM(hours_spent) as utilized_hours
        FROM project_time_logs
        WHERE is_utilized = 1
        GROUP BY user_id, project_id
    ) ptl ON ua.user_id = ptl.user_id AND ua.project_id = ptl.project_id
    WHERE $whereClause
    ORDER BY u.full_name, p.title
";

$stmt = $db->prepare($assignmentsQuery);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Get filter options
$projects = $db->query("SELECT id, title, po_number FROM projects WHERE status NOT IN ('completed', 'cancelled') ORDER BY title")->fetchAll();
$users = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 AND role IN ('project_lead', 'qa', 'at_tester', 'ft_tester') ORDER BY full_name")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Bulk Hours Management</h2>
        <a href="<?php echo $baseDir; ?>/modules/admin/dashboard.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
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
        <div class="card-header">
            <h5><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Project</label>
                    <select name="project_filter" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo $project['id']; ?>" <?php echo $projectFilter == $project['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['title'] . ' (' . $project['po_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">User</label>
                    <select name="user_filter" class="form-select">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $userFilter == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Role</label>
                    <select name="role_filter" class="form-select">
                        <option value="">All Roles</option>
                        <option value="project_lead" <?php echo $roleFilter === 'project_lead' ? 'selected' : ''; ?>>Project Lead</option>
                        <option value="qa" <?php echo $roleFilter === 'qa' ? 'selected' : ''; ?>>QA</option>
                        <option value="at_tester" <?php echo $roleFilter === 'at_tester' ? 'selected' : ''; ?>>AT Tester</option>
                        <option value="ft_tester" <?php echo $roleFilter === 'ft_tester' ? 'selected' : ''; ?>>FT Tester</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Update Form -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5>Hours Assignments (<?php echo count($assignments); ?> records)</h5>
            <div>
                <button type="button" class="btn btn-success btn-sm" onclick="applyBulkUpdate()">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="resetChanges()">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($assignments)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No assignments found matching the selected filters.</p>
                </div>
            <?php else: ?>
                <form id="bulkUpdateForm" method="POST">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Project</th>
                                    <th>Role</th>
                                    <th>Current Hours</th>
                                    <th>Utilized Hours</th>
                                    <th>Remaining</th>
                                    <th>New Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($assignment['full_name']); ?></strong>
                                            <br>
                                            <span class="badge bg-<?php 
                                                echo match($assignment['user_role']) {
                                                    'project_lead' => 'primary',
                                                    'qa' => 'success',
                                                    'at_tester' => 'info',
                                                    'ft_tester' => 'warning',
                                                    default => 'secondary'
                                                };
                                            ?> badge-sm">
                                                <?php echo ucfirst(str_replace('_', ' ', $assignment['user_role'])); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($assignment['project_title']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($assignment['po_number']); ?></small>
                                            <br>
                                            <small class="text-info">
                                                <?php echo number_format($assignment['total_hours'], 1); ?>h total, 
                                                <?php echo number_format($assignment['total_allocated_hours'], 1); ?>h allocated
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo ucfirst(str_replace('_', ' ', $assignment['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo number_format($assignment['hours_allocated'], 1); ?>h
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success">
                                            <?php echo number_format($assignment['utilized_hours'], 1); ?>h
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $remaining = $assignment['hours_allocated'] - $assignment['utilized_hours'];
                                        $badgeClass = $remaining > 0 ? 'warning' : 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $badgeClass; ?>">
                                            <?php echo number_format($remaining, 1); ?>h
                                        </span>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="updates[<?php echo $assignment['id']; ?>]" 
                                               class="form-control form-control-sm hours-input" 
                                               step="0.5" 
                                               min="0" 
                                               max="<?php echo $assignment['total_hours'] - ($assignment['total_allocated_hours'] - $assignment['hours_allocated']); ?>"
                                               placeholder="<?php echo $assignment['hours_allocated']; ?>"
                                               data-original="<?php echo $assignment['hours_allocated']; ?>"
                                               data-project-total="<?php echo $assignment['total_hours']; ?>"
                                               data-project-allocated="<?php echo $assignment['total_allocated_hours']; ?>"
                                               data-assignment-id="<?php echo $assignment['id']; ?>"
                                               style="width: 80px;"
                                               onchange="validateHours(this)">
                                        <small class="text-muted hours-info" style="font-size: 0.7em;"></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($assignment['project_status']) {
                                                'in_progress' => 'success',
                                                'on_hold' => 'warning',
                                                'not_started' => 'secondary',
                                                default => 'info'
                                            };
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $assignment['project_status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Reason for Bulk Update</label>
                                <textarea name="bulk_reason" class="form-control" rows="2" placeholder="Reason for these changes..." required></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Quick Actions</label>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="increaseAll(5)">+5h All</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="increaseAll(10)">+10h All</button>
                                    <button type="button" class="btn btn-outline-warning btn-sm" onclick="decreaseAll(5)">-5h All</button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearAll()">Clear All</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function validateHours(input) {
    const newHours = parseFloat(input.value) || 0;
    const originalHours = parseFloat(input.dataset.original);
    const projectTotal = parseFloat(input.dataset.projectTotal);
    const projectAllocated = parseFloat(input.dataset.projectAllocated);
    const maxAllowed = projectTotal - (projectAllocated - originalHours);
    
    const infoElement = input.nextElementSibling;
    
    if (newHours > maxAllowed) {
        input.style.borderColor = '#dc3545';
        input.style.backgroundColor = '#f8d7da';
        infoElement.textContent = `Max: ${maxAllowed.toFixed(1)}h`;
        infoElement.className = 'text-danger hours-info';
        input.setCustomValidity(`Cannot exceed ${maxAllowed.toFixed(1)} hours`);
    } else if (newHours > 0 && newHours !== originalHours) {
        input.style.borderColor = '#198754';
        input.style.backgroundColor = '#d1e7dd';
        infoElement.textContent = `${(maxAllowed - newHours).toFixed(1)}h left`;
        infoElement.className = 'text-success hours-info';
        input.setCustomValidity('');
    } else {
        input.style.borderColor = '';
        input.style.backgroundColor = '';
        infoElement.textContent = '';
        input.setCustomValidity('');
    }
}

function applyBulkUpdate() {
    const form = document.getElementById('bulkUpdateForm');
    const inputs = form.querySelectorAll('.hours-input');
    let hasChanges = false;
    let hasErrors = false;
    
    inputs.forEach(input => {
        if (input.value && input.value !== input.dataset.original) {
            hasChanges = true;
        }
        if (!input.checkValidity()) {
            hasErrors = true;
        }
    });
    
    if (!hasChanges) {
        showToast('No changes detected. Please modify some hours before saving.', 'warning');
        return;
    }
    
    if (hasErrors) {
        showToast('Please fix the validation errors before saving.', 'warning');
        return;
    }
    
    const reason = form.querySelector('textarea[name="bulk_reason"]').value;
    if (!reason.trim()) {
        showToast('Please provide a reason for the bulk update.', 'warning');
        return;
    }
    
    confirmModal('Are you sure you want to apply these changes?', function() {
        form.querySelector('input[name="bulk_update"]').value = '1';
        form.submit();
    });
}

function resetChanges() {
    const inputs = document.querySelectorAll('.hours-input');
    inputs.forEach(input => {
        input.value = '';
        input.style.borderColor = '';
        input.style.backgroundColor = '';
        input.nextElementSibling.textContent = '';
        input.setCustomValidity('');
    });
    document.querySelector('textarea[name="bulk_reason"]').value = '';
}

function increaseAll(amount) {
    const inputs = document.querySelectorAll('.hours-input');
    inputs.forEach(input => {
        const current = parseFloat(input.dataset.original) || 0;
        const projectTotal = parseFloat(input.dataset.projectTotal);
        const projectAllocated = parseFloat(input.dataset.projectAllocated);
        const maxAllowed = projectTotal - (projectAllocated - current);
        const newValue = Math.min(maxAllowed, current + amount);
        
        input.value = newValue.toFixed(1);
        validateHours(input);
    });
}

function decreaseAll(amount) {
    const inputs = document.querySelectorAll('.hours-input');
    inputs.forEach(input => {
        const current = parseFloat(input.dataset.original) || 0;
        const newValue = Math.max(0, current - amount);
        input.value = newValue.toFixed(1);
        validateHours(input);
    });
}

function clearAll() {
    const inputs = document.querySelectorAll('.hours-input');
    inputs.forEach(input => {
        input.value = '';
        input.style.borderColor = '';
        input.style.backgroundColor = '';
        input.nextElementSibling.textContent = '';
        input.setCustomValidity('');
    });
}

// Add hidden input for form submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('bulkUpdateForm');
    if (form) {
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'bulk_update';
        hiddenInput.value = '';
        form.appendChild(hiddenInput);
    }
});
</script>

<style>
.badge-sm {
    font-size: 0.7em;
}

.card {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.hours-input:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.hours-input[value]:not([value=""]) {
    background-color: #fff3cd;
    border-color: #ffc107;
}

.hours-info {
    font-size: 0.7em;
    display: block;
    margin-top: 2px;
}

.project-hours-summary {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 4px;
    padding: 4px 8px;
    margin-top: 4px;
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>