<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$baseDir = getBaseDir();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$pageTitle = 'Feedback';

// Get view parameter
$view = $_GET['view'] ?? 'send';

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $recipientIds = $_POST['recipient_ids'] ?? [];
    $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    $isGeneric = isset($_POST['is_generic']) ? 1 : 0;
    $sendToAdmin = isset($_POST['send_to_admin']) ? 1 : 0;
    $sendToLead = isset($_POST['send_to_lead']) ? 1 : 0;
    $content = $_POST['content'] ?? '';

    if (!empty($content)) {
        try {
            // Sanitize HTML content
            $clean = sanitize_chat_html($content);

            $stmt = $db->prepare("INSERT INTO feedbacks (sender_id, target_user_id, send_to_admin, send_to_lead, content, project_id, is_generic, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$userId, $sendToAdmin, $sendToLead, $clean, $projectId, $isGeneric]);
            $feedbackId = $db->lastInsertId();

            // Insert recipients mapping
            if (!empty($recipientIds)) {
                $ins = $db->prepare("INSERT INTO feedback_recipients (feedback_id, user_id) VALUES (?, ?)");
                foreach ($recipientIds as $rid) {
                    if (!empty($rid)) {
                        $ins->execute([$feedbackId, (int)$rid]);
                    }
                }
            }

            // Log activity
            logActivity($db, $userId, 'submit_feedback', 'feedback', $feedbackId, [
                'recipients' => $recipientIds,
                'send_to_admin' => $sendToAdmin,
                'send_to_lead' => $sendToLead,
                'project_id' => $projectId,
                'is_generic' => $isGeneric
            ]);

            $_SESSION['success'] = 'Feedback submitted successfully!';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?view=my');
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = 'Failed to submit feedback. Please try again.';
        }
    } else {
        $_SESSION['error'] = 'Feedback content cannot be empty.';
    }
}

// Get user's feedback for "My Feedback" view
if ($view === 'my') {
    $isAdmin = in_array($userRole, ['admin', 'super_admin']) ? 1 : 0;
    $myFeedbackQuery = "
        SELECT DISTINCT f.*, 
               sender.full_name as sender_name,
               p.title as project_title,
               p.po_number as project_code,
               GROUP_CONCAT(DISTINCT recipient.full_name SEPARATOR ', ') as recipients
        FROM feedbacks f
        LEFT JOIN users sender ON f.sender_id = sender.id
        LEFT JOIN projects p ON f.project_id = p.id
        LEFT JOIN feedback_recipients fr ON f.id = fr.feedback_id
        LEFT JOIN users recipient ON fr.user_id = recipient.id
        WHERE (
                ? = 1
                OR f.sender_id = ?
                OR fr.user_id = ?
                OR (f.send_to_admin = 1 AND ? = 1)
                OR (f.send_to_lead = 1 AND p.project_lead_id = ?)
                OR (
                    f.is_generic = 1
                    AND (
                        f.project_id IS NULL
                        OR p.project_lead_id = ?
                        OR EXISTS (
                            SELECT 1
                            FROM user_assignments ua
                            WHERE ua.project_id = f.project_id
                              AND ua.user_id = ?
                              AND (ua.is_removed IS NULL OR ua.is_removed = 0)
                        )
                    )
                )
              )
        GROUP BY f.id
        ORDER BY f.created_at DESC
    ";
    
    $stmt = $db->prepare($myFeedbackQuery);
    $stmt->execute([$isAdmin, $userId, $userId, $isAdmin, $userId, $userId, $userId]);
    $myFeedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get projects for dropdown (only projects user has access to)
if (in_array($userRole, ['admin', 'super_admin'])) {
    $projects = $db->query("SELECT id, title, po_number FROM projects ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $projectsQuery = "
        SELECT DISTINCT p.id, p.title, p.po_number 
        FROM projects p
        LEFT JOIN user_assignments ua ON p.id = ua.project_id
        WHERE p.project_lead_id = ? OR ua.user_id = ? OR p.created_by = ?
        ORDER BY p.title
    ";
    $stmt = $db->prepare($projectsQuery);
    $stmt->execute([$userId, $userId, $userId]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get users for recipient dropdown (exclude current user)
$users = $db->prepare("SELECT id, full_name, username FROM users WHERE is_active = 1 AND id != ? ORDER BY full_name");
$users->execute([$userId]);
$allUsers = $users->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2><i class="fas fa-comment-dots"></i> Feedback</h2>
                <div class="btn-group" role="group">
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=send" 
                       class="btn <?php echo $view === 'send' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        <i class="fas fa-paper-plane"></i> Send Feedback
                    </a>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=my" 
                       class="btn <?php echo $view === 'my' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        <i class="fas fa-list"></i> My Feedback
                    </a>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($view === 'send'): ?>
            <!-- Send Feedback Form -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-paper-plane"></i> Send New Feedback</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Project (Optional)</label>
                                    <select name="project_id" class="form-select">
                                        <option value="">General Feedback</option>
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
                                    <label class="form-label">Recipients (Optional)</label>
                                    <select name="recipient_ids[]" class="form-select" multiple size="4">
                                        <?php foreach ($allUsers as $user): ?>
                                        <option value="<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['full_name']); ?> (@<?php echo htmlspecialchars($user['username']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Hold Ctrl/Cmd to select multiple recipients</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_generic" id="is_generic">
                                        <label class="form-check-label" for="is_generic">
                                            Project-wide feedback (visible to all project members)
                                        </label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="send_to_admin" id="send_to_admin">
                                        <label class="form-check-label" for="send_to_admin">
                                            Send to Admin
                                        </label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="send_to_lead" id="send_to_lead">
                                        <label class="form-check-label" for="send_to_lead">
                                            Send to Project Lead
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Feedback Content *</label>
                            <textarea name="content" id="feedbackContent" required></textarea>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button type="submit" name="submit_feedback" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Feedback
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-undo"></i> Reset Form
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php else: ?>
            <!-- My Feedback View -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-list"></i> My Feedback 
                        <span class="badge bg-primary"><?php echo count($myFeedbacks); ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($myFeedbacks)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-3x mb-3"></i>
                        <h5>No feedback found</h5>
                        <p>You have no sent or received feedback yet.</p>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=send" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Send Your First Feedback
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="row g-2 mb-3" id="myFeedbackFilters">
                        <div class="col-md-4">
                            <label for="myFeedbackSearch" class="form-label mb-1">Search</label>
                            <input type="text" id="myFeedbackSearch" class="form-control form-control-sm" placeholder="Search sender, project, recipients, preview">
                        </div>
                        <div class="col-md-3">
                            <label for="myFeedbackStatusFilter" class="form-label mb-1">Status</label>
                            <select id="myFeedbackStatusFilter" class="form-select form-select-sm">
                                <option value="">All Status</option>
                                <option value="open">Open</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="myFeedbackTypeFilter" class="form-label mb-1">Type</label>
                            <select id="myFeedbackTypeFilter" class="form-select form-select-sm">
                                <option value="">All Types</option>
                                <option value="project">Project-wide</option>
                                <option value="private">Private</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary w-100" id="myFeedbackClearFilters">
                                Clear Filters
                            </button>
                        </div>
                    </div>
                    <div class="small text-muted mb-2">
                        Showing <span id="myFeedbackVisibleCount"><?php echo count($myFeedbacks); ?></span> of <?php echo count($myFeedbacks); ?> feedbacks
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle" id="myFeedbackTable">
                            <thead>
                                <?php
                                    $rowStatus = strtolower((string)($feedback['status'] ?? 'open'));
                                    $rowType = !empty($feedback['is_generic']) ? 'project' : 'private';
                                    $rowSearchText = strtolower(trim(
                                        ($feedback['sender_name'] ?? '') . ' ' .
                                        ($feedback['project_title'] ?? 'General') . ' ' .
                                        ($feedback['recipients'] ?? '') . ' ' .
                                        trim(strip_tags($feedback['content'] ?? ''))
                                    ));
                                ?>
                                <tr data-status="<?php echo htmlspecialchars($rowStatus, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-type="<?php echo htmlspecialchars($rowType, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-search="<?php echo htmlspecialchars($rowSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                                    <th>Date</th>
                                    <th>From</th>
                                    <th>Project</th>
                                    <th>Recipients</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Preview</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myFeedbacks as $feedback): ?>
                                <tr>
                                    <td class="text-nowrap"><?php echo date('M d, Y H:i', strtotime($feedback['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($feedback['sender_name'] ?? 'Unknown'); ?></td>
                                    <td>
                                        <?php if ($feedback['project_title']): ?>
                                            <?php echo htmlspecialchars($feedback['project_title']); ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars($feedback['project_code']); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">General</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($feedback['recipients'])): ?>
                                            <span class="small"><?php echo htmlspecialchars($feedback['recipients']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($feedback['is_generic'])): ?>
                                            <span class="badge bg-info">Project-wide</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Private</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = 'secondary';
                                        if (($feedback['status'] ?? '') === 'open') $statusClass = 'warning';
                                        if (($feedback['status'] ?? '') === 'in_progress') $statusClass = 'info';
                                        if (($feedback['status'] ?? '') === 'resolved') $statusClass = 'success';
                                        if (($feedback['status'] ?? '') === 'closed') $statusClass = 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $statusClass; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $feedback['status'] ?? 'open')); ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 320px;">
                                        <?php
                                        $content = trim(strip_tags($feedback['content'] ?? ''));
                                        echo htmlspecialchars(strlen($content) > 140 ? substr($content, 0, 140) . '...' : $content);
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="viewFeedbackDetails(<?php echo (int)$feedback['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
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
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Feedback Details Modal -->
<div class="modal fade" id="viewFeedbackDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Feedback Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="feedbackDetailsContent">
                    <p class="text-center text-muted">Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Summernote
    $('#feedbackContent').summernote({
        height: 200,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link']],
            ['view', ['fullscreen', 'codeview']]
        ],
        placeholder: 'Enter your feedback here...'
    });
    
    // Handle form reset
    $('button[type="reset"]').on('click', function() {
        $('#feedbackContent').summernote('code', '');
    });

    // My Feedback table filters
    function applyMyFeedbackFilters() {
        const $table = $('#myFeedbackTable');
        if (!$table.length) return;
        const search = String($('#myFeedbackSearch').val() || '').toLowerCase().trim();
        const status = String($('#myFeedbackStatusFilter').val() || '').toLowerCase().trim();
        const type = String($('#myFeedbackTypeFilter').val() || '').toLowerCase().trim();

        let visible = 0;
        $table.find('tbody tr').each(function() {
            const $row = $(this);
            const rowSearch = String($row.data('search') || '');
            const rowStatus = String($row.data('status') || '');
            const rowType = String($row.data('type') || '');

            const matchSearch = !search || rowSearch.indexOf(search) !== -1;
            const matchStatus = !status || rowStatus === status;
            const matchType = !type || rowType === type;
            const show = matchSearch && matchStatus && matchType;
            $row.toggle(show);
            if (show) visible++;
        });
        $('#myFeedbackVisibleCount').text(visible);
    }

    $('#myFeedbackSearch, #myFeedbackStatusFilter, #myFeedbackTypeFilter').on('input change', applyMyFeedbackFilters);
    $('#myFeedbackClearFilters').on('click', function() {
        $('#myFeedbackSearch').val('');
        $('#myFeedbackStatusFilter').val('');
        $('#myFeedbackTypeFilter').val('');
        applyMyFeedbackFilters();
    });
});

// View feedback details
function viewFeedbackDetails(feedbackId) {
    fetch(`<?php echo $baseDir; ?>/api/feedback.php?action=get_user_feedback&feedback_id=${feedbackId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const feedback = data.feedback;
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Feedback Information</h6>
                            <p><strong>Date:</strong> ${new Date(feedback.created_at).toLocaleString()}</p>
                            <p><strong>Status:</strong> <span class="badge bg-primary">${feedback.status || 'open'}</span></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Project Information</h6>
                            ${feedback.project_title ? 
                                `<p><strong>Project:</strong> ${feedback.project_title} (${feedback.project_code})</p>` : 
                                '<p><strong>Type:</strong> General Feedback</p>'
                            }
                        </div>
                    </div>
                `;
                
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
                
                document.getElementById('feedbackDetailsContent').innerHTML = html;
                
                const modal = new bootstrap.Modal(document.getElementById('viewFeedbackDetailsModal'));
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
