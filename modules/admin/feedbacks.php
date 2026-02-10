<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'super_admin']);
$db = Database::getInstance();
$baseDir = getBaseDir();

$pageTitle = 'Feedback Management';

// Get filter parameters
$projectFilter = $_GET['project_id'] ?? '';
$userFilter = $_GET['user_id'] ?? '';
$searchText = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query with filters
$whereConditions = [];
$params = [];

if (!empty($projectFilter)) {
    $whereConditions[] = "f.project_id = ?";
    $params[] = $projectFilter;
}

if (!empty($userFilter)) {
    $whereConditions[] = "(f.sender_id = ? OR fr.user_id = ?)";
    $params[] = $userFilter;
    $params[] = $userFilter;
}

if (!empty($searchText)) {
    $whereConditions[] = "(f.content LIKE ? OR f.subject LIKE ?)";
    $params[] = "%$searchText%";
    $params[] = "%$searchText%";
}

if (!empty($statusFilter)) {
    $whereConditions[] = "f.status = ?";
    $params[] = $statusFilter;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(f.created_at) >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(f.created_at) <= ?";
    $params[] = $dateTo;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get feedbacks with filters
$feedbackQuery = "
    SELECT DISTINCT f.*, 
           sender.full_name as sender_name,
           sender.username as sender_username,
           p.title as project_title,
           p.po_number as project_code,
           GROUP_CONCAT(DISTINCT recipient.full_name SEPARATOR ', ') as recipients
    FROM feedbacks f
    LEFT JOIN users sender ON f.sender_id = sender.id
    LEFT JOIN projects p ON f.project_id = p.id
    LEFT JOIN feedback_recipients fr ON f.id = fr.feedback_id
    LEFT JOIN users recipient ON fr.user_id = recipient.id
    $whereClause
    GROUP BY f.id
    ORDER BY f.created_at DESC
    LIMIT 100
";

$stmt = $db->prepare($feedbackQuery);
$stmt->execute($params);
$feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get projects for filter dropdown
$projects = $db->query("SELECT id, title, po_number FROM projects ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);

// Get users for filter dropdown
$users = $db->query("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Get feedback statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_feedbacks,
        COUNT(CASE WHEN f.status = 'open' THEN 1 END) as open_feedbacks,
        COUNT(CASE WHEN f.status = 'in_progress' THEN 1 END) as in_progress_feedbacks,
        COUNT(CASE WHEN f.status = 'resolved' THEN 1 END) as resolved_feedbacks,
        COUNT(CASE WHEN f.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_feedbacks
    FROM feedbacks f
    LEFT JOIN feedback_recipients fr ON f.id = fr.feedback_id
    $whereClause
";

$statsStmt = $db->prepare($statsQuery);
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-comment-dots"></i> Feedback Management</h2>
        <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#exportModal">
                <i class="fas fa-download"></i> Export
            </button>
            <a href="<?php echo $baseDir; ?>/modules/admin/dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center bg-primary text-white">
                <div class="card-body">
                    <h3><?php echo $stats['total_feedbacks']; ?></h3>
                    <p class="mb-0">Total Feedbacks</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center bg-warning text-dark">
                <div class="card-body">
                    <h3><?php echo $stats['open_feedbacks']; ?></h3>
                    <p class="mb-0">Open</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center bg-info text-dark">
                <div class="card-body">
                    <h3><?php echo $stats['in_progress_feedbacks']; ?></h3>
                    <p class="mb-0">In Progress</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center bg-success text-white">
                <div class="card-body">
                    <h3><?php echo $stats['resolved_feedbacks']; ?></h3>
                    <p class="mb-0">Resolved</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm">
                <div class="row">
                    <div class="col-md-3">
                        <label class="form-label">Project</label>
                        <select name="project_id" class="form-select">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $project): ?>
                            <option value="<?php echo $project['id']; ?>" <?php echo $projectFilter == $project['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['title']); ?> (<?php echo htmlspecialchars($project['po_number']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">User (Sender/Recipient)</label>
                        <select name="user_id" class="form-select">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $userFilter == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?> (@<?php echo htmlspecialchars($user['username']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $statusFilter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Search Text</label>
                        <input type="text" name="search" class="form-control" placeholder="Search in content..." value="<?php echo htmlspecialchars($searchText); ?>">
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Feedbacks List -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-list"></i> Feedbacks 
                <span class="badge bg-primary"><?php echo count($feedbacks); ?></span>
                <?php if (!empty($whereConditions)): ?>
                <small class="text-muted">(filtered)</small>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($feedbacks)): ?>
            <div class="p-4 text-center text-muted">
                <i class="fas fa-inbox fa-3x mb-3"></i>
                <h5>No feedbacks found</h5>
                <p>No feedbacks match your current filters.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 15%;">Sender</th>
                            <th style="width: 15%;">Project</th>
                            <th style="width: 35%;">Content</th>
                            <th style="width: 15%;">Recipients</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 10%;">Date</th>
                            <th style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feedbacks as $feedback): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2">
                                        <?php echo strtoupper(substr($feedback['sender_name'] ?? 'U', 0, 1)); ?>
                                    </div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($feedback['sender_name'] ?? 'Unknown'); ?></strong>
                                        <br><small class="text-muted">@<?php echo htmlspecialchars($feedback['sender_username'] ?? 'unknown'); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($feedback['project_title']): ?>
                                <div>
                                    <strong><?php echo htmlspecialchars($feedback['project_title']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($feedback['project_code']); ?></small>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">General Feedback</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="feedback-content" style="max-height: 100px; overflow: hidden;">
                                    <?php 
                                    $content = strip_tags($feedback['content']);
                                    echo htmlspecialchars(strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content);
                                    ?>
                                </div>
                                <?php if ($feedback['is_generic']): ?>
                                <span class="badge bg-info small mt-1">Project-wide</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($feedback['recipients']): ?>
                                <small><?php echo htmlspecialchars($feedback['recipients']); ?></small>
                                <?php else: ?>
                                <span class="text-muted small">No specific recipients</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select class="form-select form-select-sm feedback-status-update" 
                                        data-feedback-id="<?php echo $feedback['id']; ?>">
                                    <option value="open" <?php echo $feedback['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="in_progress" <?php echo $feedback['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="resolved" <?php echo $feedback['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="closed" <?php echo $feedback['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </td>
                            <td>
                                <small>
                                    <?php echo date('M d, Y', strtotime($feedback['created_at'])); ?>
                                    <br><?php echo date('H:i', strtotime($feedback['created_at'])); ?>
                                </small>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="viewFeedback(<?php echo $feedback['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                        onclick="deleteFeedback(<?php echo $feedback['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Feedback Modal -->
<div class="modal fade" id="viewFeedbackModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Feedback Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="feedbackDetails">
                    <p class="text-center text-muted">Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Export Feedbacks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="exportForm">
                    <div class="mb-3">
                        <label class="form-label">Export Format</label>
                        <select name="format" class="form-select">
                            <option value="csv">CSV</option>
                            <option value="excel">Excel</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Include Fields</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="fields[]" value="sender" checked>
                            <label class="form-check-label">Sender</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="fields[]" value="project" checked>
                            <label class="form-check-label">Project</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="fields[]" value="content" checked>
                            <label class="form-check-label">Content</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="fields[]" value="recipients" checked>
                            <label class="form-check-label">Recipients</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="fields[]" value="status" checked>
                            <label class="form-check-label">Status</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="fields[]" value="date" checked>
                            <label class="form-check-label">Date</label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="exportFeedbacks()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.avatar-sm {
    width: 32px;
    height: 32px;
    font-size: 14px;
}

.feedback-content {
    line-height: 1.4;
}

.table td {
    vertical-align: middle;
}
</style>

<script>
// Update feedback status
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('feedback-status-update')) {
        const feedbackId = e.target.dataset.feedbackId;
        const newStatus = e.target.value;
        const originalValue = e.target.dataset.originalValue || 'open';
        
        fetch('<?php echo $baseDir; ?>/api/feedback.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=update_status&feedback_id=${feedbackId}&status=${newStatus}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                const row = e.target.closest('tr');
                row.classList.add('table-success');
                setTimeout(() => row.classList.remove('table-success'), 2000);
                
                // Store new value as original
                e.target.dataset.originalValue = newStatus;
            } else {
                showToast('Failed to update status: ' + (data.message || 'Unknown error'), 'danger');
                // Revert to original value
                e.target.value = originalValue;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error updating status', 'danger');
            // Revert to original value
            e.target.value = originalValue;
        });
    }
});

// View feedback details
function viewFeedback(feedbackId) {
    fetch(`<?php echo $baseDir; ?>/api/feedback.php?action=get_feedback&feedback_id=${feedbackId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const feedback = data.feedback;
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Sender Information</h6>
                            <p><strong>Name:</strong> ${feedback.sender_name || 'Unknown'}</p>
                            <p><strong>Username:</strong> @${feedback.sender_username || 'unknown'}</p>
                        </div>
                        <div class="col-md-6">
                            <h6>Feedback Information</h6>
                            <p><strong>Date:</strong> ${new Date(feedback.created_at).toLocaleString()}</p>
                            <p><strong>Status:</strong> <span class="badge bg-primary">${feedback.status}</span></p>
                        </div>
                    </div>
                `;
                
                if (feedback.project_title) {
                    html += `
                        <div class="mt-3">
                            <h6>Project</h6>
                            <p><strong>${feedback.project_title}</strong> (${feedback.project_code})</p>
                        </div>
                    `;
                }
                
                if (feedback.recipients) {
                    html += `
                        <div class="mt-3">
                            <h6>Recipients</h6>
                            <p>${feedback.recipients}</p>
                        </div>
                    `;
                }
                
                html += `
                    <div class="mt-3">
                        <h6>Content</h6>
                        <div class="border p-3 rounded bg-light">
                            ${feedback.content}
                        </div>
                    </div>
                `;
                
                document.getElementById('feedbackDetails').innerHTML = html;
                
                const modal = new bootstrap.Modal(document.getElementById('viewFeedbackModal'));
                modal.show();
            } else {
                showToast('Failed to load feedback details', 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error loading feedback details', 'danger');
        });
}

// Delete feedback
function deleteFeedback(feedbackId) {
    confirmModal('Are you sure you want to delete this feedback? This action cannot be undone.', function() {
        fetch('<?php echo $baseDir; ?>/api/feedback.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete_feedback&feedback_id=${feedbackId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the row from table
                const row = document.querySelector(`[data-feedback-id="${feedbackId}"]`).closest('tr');
                row.remove();
                
                // Show success message
                showToast('Feedback deleted successfully', 'success');
            } else {
                showToast('Failed to delete feedback: ' + (data.message || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error deleting feedback', 'danger');
        });
    });
}

// Export feedbacks
function exportFeedbacks() {
    const form = document.getElementById('exportForm');
    const formData = new FormData(form);
    
    // Add current filters to export
    const urlParams = new URLSearchParams(window.location.search);
    for (const [key, value] of urlParams) {
        formData.append(key, value);
    }
    
    formData.append('action', 'export');
    
    // Create a temporary form to submit
    const tempForm = document.createElement('form');
    tempForm.method = 'POST';
    tempForm.action = '<?php echo $baseDir; ?>/api/feedback.php';
    tempForm.style.display = 'none';
    
    for (const [key, value] of formData) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        tempForm.appendChild(input);
    }
    
    document.body.appendChild(tempForm);
    tempForm.submit();
    document.body.removeChild(tempForm);
    
    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
    modal.hide();
}

// Store original values for status selects
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.feedback-status-update').forEach(select => {
        select.dataset.originalValue = select.value;
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>