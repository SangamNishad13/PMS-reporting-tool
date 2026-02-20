<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    header('Location: ' . $baseDir . '/index.php');
    exit;
}
$canUpdateIssueQaStatus = hasIssueQaStatusUpdateAccess($db, $userId, $projectId);

// Get project details
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

// Pre-fetch project pages
$pagesStmt = $db->prepare("SELECT id, page_name, page_number, url FROM project_pages WHERE project_id = ? ORDER BY page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch grouped URLs for the project
$groupedUrls = [];
try {
    $groupedStmt = $db->prepare("
        SELECT gu.id, gu.url, gu.normalized_url, gu.unique_page_id
        FROM grouped_urls gu 
        WHERE gu.project_id = ?
        ORDER BY gu.url
    ");
$groupedStmt->execute([$projectId]);
$groupedUrls = $groupedStmt->fetchAll(PDO::FETCH_ASSOC);

// Unique page mapping for canonical URL fallback when grouped URLs are missing
$uniqueIssuePages = [];
try {
    $uniqueIssueStmt = $db->prepare("
        SELECT 
            up.id AS unique_id,
            up.page_name AS unique_name,
            up.url AS canonical_url,
            MIN(pp.id) AS mapped_page_id
        FROM project_pages up
        LEFT JOIN grouped_urls gu ON gu.project_id = up.project_id AND gu.unique_page_id = up.id
        LEFT JOIN project_pages pp ON pp.project_id = up.project_id
            AND (
                pp.url = gu.url
                OR pp.url = gu.normalized_url
                OR pp.url = up.url
                OR pp.page_name = up.page_name
                OR pp.page_number = up.page_name
            )
        WHERE up.project_id = ?
        GROUP BY up.id
    ");
    $uniqueIssueStmt->execute([$projectId]);
    $uniqueIssuePages = $uniqueIssueStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $uniqueIssuePages = [];
}
} catch (Exception $e) {
    $groupedUrls = [];
}

// Fetch QA statuses
$qaStatuses = [];
try {
    $qaStmt = $db->prepare("SELECT id, status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1 ORDER BY display_order");
    $qaStmt->execute();
    $qaStatuses = $qaStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $qaStatuses = [];
}

// Fetch issue statuses from issue_statuses table (admin managed)
$issueStatuses = [];
try {
    $issueStatusStmt = $db->prepare("SELECT id, name, color FROM issue_statuses ORDER BY name");
    $issueStatusStmt->execute();
    $issueStatuses = $issueStatusStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $issueStatuses = [];
}

// Fetch project users (project team) for reporters dropdown
$projectUsers = [];
try {
    $usersStmt = $db->prepare("
        SELECT DISTINCT u.id, u.full_name, u.username, u.role 
        FROM users u
        INNER JOIN user_assignments ua ON u.id = ua.user_id
        WHERE ua.project_id = ? AND u.is_active = 1 AND (ua.is_removed IS NULL OR ua.is_removed = 0)
        UNION
        SELECT u.id, u.full_name, u.username, u.role
        FROM users u
        WHERE u.is_active = 1 AND u.role IN ('admin', 'super_admin')
        ORDER BY full_name
    ");
    $usersStmt->execute([$projectId]);
    $projectUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $projectUsers = [];
}

// Fetch metadata fields for this project type
$metadataFields = [];
try {
    $metaStmt = $db->prepare("
        SELECT field_key, field_label, field_type, field_options, is_required, display_order
        FROM issue_metadata_fields
        WHERE (project_type = ? OR project_type IS NULL)
        AND is_active = 1
        ORDER BY display_order, field_label
    ");
    $metaStmt->execute([$project['type'] ?? 'web']);
    $metadataFields = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $metadataFields = [];
}

$pageTitle = 'Issues - Common - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
.modal { z-index: 10550; }
.modal-backdrop { z-index: 10540; }
.select2-container--open .select2-dropdown { z-index: 10600; }
.select2-results__options { max-height: 250px !important; overflow-y: auto !important; }
</style>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>">
                        <?php echo htmlspecialchars($project['title']); ?>
                    </a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>">Accessibility Report</a></li>
                    <li class="breadcrumb-item active">Common Issues</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1">
                        <i class="fas fa-layer-group text-primary me-2"></i>
                        Common Issues
                    </h2>
                    <p class="text-muted mb-0">Manage issues that apply to multiple pages</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_pages.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-file-alt me-1"></i> Pages View
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Common Issues</h5>
                <div class="small text-muted">Issues that apply across multiple pages</div>
            </div>
            <button class="btn btn-primary" id="commonAddBtn">
                <i class="fas fa-plus me-1"></i> Add Common Issue
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="commonSelectAll"></th>
                            <th>Common Issue Title</th>
                            <th style="width:200px;">Pages</th>
                            <th style="width:150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="commonIssuesBody">
                        <tr>
                            <td colspan="4" class="text-center text-muted py-5">
                                <i class="fas fa-layer-group fa-3x mb-3 opacity-25"></i>
                                <div>No common issues added yet.</div>
                                <div class="small mt-2">Click "Add Common Issue" to create one</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="alert alert-info small mt-3 mb-0">
                <i class="fas fa-info-circle me-1"></i>
                <strong>Tip:</strong> If a final issue applies to more than one page, fill the "Common Issue Title" field while adding it.
            </div>
        </div>
    </div>
</div>

<?php 
// Include the final issue modal from issues_modals.php
include __DIR__ . '/partials/issues_modals.php'; 
?>

<!-- Common Issue Modal -->
<div class="modal fade" id="commonIssueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="commonEditorTitle">New Common Issue</h5>
                    <div class="small text-muted">Title + pages + details.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="commonIssueEditId" value="">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label">Common Issue Title</label>
                        <input type="text" class="form-control" id="commonIssueTitle" placeholder="Common issue title">
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label">Page Name(s)</label>
                        <select id="commonIssuePages" class="form-select issue-select2" multiple>
                            <?php foreach ($projectPages as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['page_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Details</label>
                        <textarea id="commonIssueDetails" class="issue-summernote"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="commonIssueSaveBtn">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    window.ProjectConfig = {
        projectId: <?php echo json_encode($projectId); ?>,
        userId: <?php echo json_encode($userId); ?>,
        userRole: <?php echo json_encode($userRole); ?>,
        canUpdateIssueQaStatus: <?php echo $canUpdateIssueQaStatus ? 'true' : 'false'; ?>,
        baseDir: '<?php echo $baseDir; ?>',
        projectType: '<?php echo $project['type'] ?? 'web'; ?>',
        projectPages: <?php echo json_encode($projectPages ?? []); ?>,
        uniqueIssuePages: <?php echo json_encode($uniqueIssuePages ?? []); ?>,
        groupedUrls: <?php echo json_encode($groupedUrls ?? []); ?>,
        projectUsers: <?php echo json_encode($projectUsers ?? []); ?>,
        qaStatuses: <?php echo json_encode($qaStatuses ?? []); ?>,
        issueStatuses: <?php echo json_encode($issueStatuses ?? []); ?>
    };
    
    // Define issueMetadataFields globally for view_issues.js
    window.issueMetadataFields = <?php echo json_encode($metadataFields ?? []); ?>;
</script>

<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js"></script>

<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js"></script>
<script>
document.addEventListener('pms:issues-changed', function () {
    if (typeof window.loadCommonIssues === 'function') {
        window.loadCommonIssues();
    }
});
</script>

<!-- Floating Project Chat -->
<style>
.chat-launcher { position: fixed; bottom: 20px; right: 20px; z-index: 1060; border-radius: 999px; box-shadow: 0 10px 24px rgba(0,0,0,0.18); padding: 12px 18px; display: flex; align-items: center; gap: 8px; }
.chat-launcher i { font-size: 1.1rem; }
.chat-widget { position: fixed; bottom: 86px; right: 20px; width: 360px; max-width: 92vw; height: 520px; max-height: 78vh; background: #fff; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,0.25); border: 1px solid #e5e7eb; overflow: hidden; z-index: 1060; display: none; }
.chat-widget.open { display: block; }
.chat-widget iframe { width: 100%; height: calc(100% - 48px); border: 0; }
.chat-widget .chat-widget-header { height: 48px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #0d6efd, #4dabf7); color: #fff; }
.chat-widget .chat-widget-header .btn { color: #fff; border-color: rgba(255,255,255,0.3); }
.chat-widget .chat-widget-header .btn:hover { background: rgba(255,255,255,0.12); }
@media (max-width: 576px) {
    .chat-widget { width: 94vw; height: 70vh; bottom: 76px; right: 3vw; }
    .chat-launcher { bottom: 14px; right: 14px; }
}
</style>

<div class="chat-widget" id="projectChatWidget" aria-label="Project Chat">
    <div class="chat-widget-header">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-comments"></i>
            <strong>Project Chat</strong>
        </div>
        <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetClose" aria-label="Close chat">
                <i class="fas fa-times"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetFullscreen" aria-label="Open full chat">
                <i class="fas fa-up-right-and-down-left-from-center"></i>
            </button>
        </div>
    </div>
    <iframe src="<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo (int)$projectId; ?>&embed=1" title="Project Chat"></iframe>
</div>

<button type="button" class="btn btn-primary chat-launcher" id="chatLauncher">
    <i class="fas fa-comments"></i>
    <span>Project Chat</span>
</button>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var launcher = document.getElementById('chatLauncher');
    var widget = document.getElementById('projectChatWidget');
    var closeBtn = document.getElementById('chatWidgetClose');
    var fullscreenBtn = document.getElementById('chatWidgetFullscreen');
    if (!launcher || !widget || !closeBtn || !fullscreenBtn) return;
    launcher.addEventListener('click', function () {
        widget.classList.add('open');
        launcher.style.display = 'none';
        setTimeout(function () { try { closeBtn.focus(); } catch (e) {} }, 0);
    });
    closeBtn.addEventListener('click', function () {
        widget.classList.remove('open');
        launcher.style.display = 'inline-flex';
        setTimeout(function () { try { launcher.focus(); } catch (e) {} }, 0);
    });
    fullscreenBtn.addEventListener('click', function () {
        window.location.href = '<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo (int)$projectId; ?>';
    });
    window.addEventListener('message', function (event) {
        if (!event || !event.data || event.data.type !== 'pms-chat-close') return;
        widget.classList.remove('open');
        launcher.style.display = 'inline-flex';
        setTimeout(function () { try { launcher.focus(); } catch (e) {} }, 0);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
