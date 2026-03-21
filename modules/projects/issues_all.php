<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin', 'client']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$normalizedUserRole = strtolower(str_replace(' ', '_', trim((string)$userRole)));

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    header('Location: ' . $baseDir . '/index.php');
    exit;
}
$canUpdateIssueQaStatus = hasIssueQaStatusUpdateAccess($db, $userId, $projectId);
if (in_array($normalizedUserRole, ['at_tester', 'ft_tester'], true)) {
    $canUpdateIssueQaStatus = false;
}

// Get project details
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

// Get filter options
$pagesStmt = $db->prepare("SELECT id, page_name, page_number, url FROM project_pages WHERE project_id = ? ORDER BY page_number, page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

$statusesStmt = $db->query("SELECT id, name, color FROM issue_statuses ORDER BY id");
$issueStatuses = $statusesStmt->fetchAll(PDO::FETCH_ASSOC);

$qaStatusesStmt = $db->query("SELECT status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1 ORDER BY display_order");
$qaStatuses = $qaStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

$reportersStmt = $db->prepare("
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
$reportersStmt->execute([$projectId]);
$projectUsers = $reportersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch grouped URLs for auto-populating when pages are selected
$groupedStmt = $db->prepare("
    SELECT 
        gu.id AS grouped_id, 
        gu.url, 
        gu.normalized_url, 
        gu.unique_page_id, 
        up.id AS unique_id, 
        up.page_name AS unique_name,
        up.url AS canonical_url,
        pp.id AS mapped_page_id, 
        pp.page_name AS mapped_page_name 
    FROM grouped_urls gu 
    LEFT JOIN project_pages up ON gu.unique_page_id = up.id
    LEFT JOIN project_pages pp ON pp.project_id = gu.project_id 
        AND (pp.url = gu.url OR pp.url = gu.normalized_url) 
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

// Fetch issue metadata fields
$metadataFieldsStmt = $db->query("SELECT id, field_key, field_label, options_json FROM issue_metadata_fields WHERE is_active = 1 ORDER BY sort_order ASC");
$metadataFields = $metadataFieldsStmt->fetchAll(PDO::FETCH_ASSOC);

// Parse options_json for each field
foreach ($metadataFields as &$field) {
    if (!empty($field['options_json'])) {
        $field['options'] = json_decode($field['options_json'], true);
    } else {
        $field['options'] = [];
    }
}

$pageTitle = 'All Issues - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
.filter-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
.issue-row {
    cursor: pointer;
    transition: all 0.2s;
}
.issue-row:hover {
    background-color: #f8f9fa;
}
/* Make all badges consistent size */
.badge,
.status-badge {
    padding: 3px 10px !important;
    font-size: 10px !important;
    font-weight: 500 !important;
    border-radius: 10px !important;
    white-space: nowrap;
    display: inline-block;
}
.qa-status-badge {
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 500;
    display: inline-block;
    margin: 2px;
    white-space: nowrap;
}
/* Reduce Summernote paragraph spacing */
.note-editable p {
    margin: 0 !important;
    line-height: 1.5 !important;
}
</style>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <?php if ($_SESSION['role'] !== 'client'): ?>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>">
                        <?php echo htmlspecialchars($project['title']); ?>
                    </a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>">Accessibility Report</a></li>
                    <li class="breadcrumb-item active">All Issues</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1">
                        <i class="fas fa-list text-primary me-2"></i>
                        All Issues
                    </h2>
                    <p class="text-muted mb-0">Complete list of all accessibility issues in this project</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <?php if ($_SESSION['role'] !== 'client'): ?>
                    <button class="btn btn-primary me-2" id="addIssueBtn">
                        <i class="fas fa-plus me-1"></i> Add Issue
                    </button>
                    <?php endif; ?>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="filter-section">
        <div class="row align-items-end g-3">
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-search me-1"></i> Search</label>
                <input type="text" class="form-control" id="searchInput" placeholder="Search by title, key, or description...">
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-file-alt me-1"></i> Page</label>
                <select class="form-select" id="filterPage" multiple>
                    <option value="">All Pages</option>
                    <?php foreach ($projectPages as $page): ?>
                        <option value="<?php echo $page['id']; ?>">
                            <?php echo htmlspecialchars($page['page_number'] . ' - ' . $page['page_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-flag me-1"></i> Status</label>
                <select class="form-select" id="filterStatus" multiple>
                    <option value="">All Statuses</option>
                    <?php foreach ($issueStatuses as $status): ?>
                        <option value="<?php echo $status['id']; ?>"><?php echo htmlspecialchars($status['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($_SESSION['role'] !== 'client'): ?>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-check-circle me-1"></i> QA Status</label>
                <select class="form-select" id="filterQAStatus" multiple>
                    <option value="">All QA Statuses</option>
                    <?php foreach ($qaStatuses as $qaStatus): ?>
                        <option value="<?php echo htmlspecialchars($qaStatus['status_key']); ?>">
                            <?php echo htmlspecialchars($qaStatus['status_label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-user me-1"></i> Reporter</label>
                <select class="form-select" id="filterReporter" multiple>
                    <option value="">All Reporters</option>
                    <?php foreach ($projectUsers as $reporter): ?>
                        <option value="<?php echo $reporter['id']; ?>"><?php echo htmlspecialchars($reporter['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-1">
                <button class="btn btn-secondary w-100" id="clearFilters">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </div>
    </div>

    <!-- Issues Table -->
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <span class="text-muted">Total Issues: <strong id="totalCount">0</strong></span>
                    <span class="text-muted ms-3">Showing: <strong id="filteredCount">0</strong></span>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-primary" id="refreshBtn">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover" id="issuesTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 100px;">Issue Key</th>
                            <th>Title</th>
                            <th style="width: 150px;">Page(s)</th>
                            <th style="width: 120px;">Status</th>
                            <?php if ($_SESSION['role'] !== 'client'): ?>
                            <th style="width: 150px;">QA Status</th>
                            <th style="width: 120px;">Reporter</th>
                            <th style="width: 100px;">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="issuesTableBody">
                        <tr>
                            <td colspan="<?php echo ($_SESSION['role'] === 'client') ? '4' : '7'; ?>" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading issues...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Include modals from partials -->
<?php include __DIR__ . '/partials/issues_modals.php'; ?>

<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Project Configuration for view_issues.js -->
<script>
// Global configuration object required by view_issues.js
window.ProjectConfig = {
    projectId: <?php echo $projectId; ?>,
    projectType: '<?php echo $project['type'] ?? 'web'; ?>',
    userRole: '<?php echo htmlspecialchars((string)$normalizedUserRole, ENT_QUOTES, 'UTF-8'); ?>',
    canUpdateIssueQaStatus: <?php echo $canUpdateIssueQaStatus ? 'true' : 'false'; ?>,
    projectPages: <?php echo json_encode($projectPages); ?>,
    uniqueIssuePages: <?php echo json_encode($uniqueIssuePages ?? []); ?>,
    groupedUrls: <?php echo json_encode($groupedUrls); ?>,
    baseDir: '<?php echo $baseDir; ?>',
    projectUsers: <?php echo json_encode($projectUsers); ?>,
    qaStatuses: <?php echo json_encode($qaStatuses); ?>,
    issueStatuses: <?php echo json_encode($issueStatuses); ?>,
    metadataFields: <?php echo json_encode($metadataFields); ?>
};

// Define issueMetadataFields globally for view_issues.js
window.issueMetadataFields = <?php echo json_encode($metadataFields ?? []); ?>;
</script>

<!-- Include issue management JavaScript -->
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_core.js"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js?v=<?php echo time(); ?>"></script>

<script src="<?php echo $baseDir; ?>/assets/js/issues-all.js?v=<?php echo time(); ?>"></script>


<!-- Floating Project Chat -->
<style>
.chat-launcher { position: fixed; bottom: 20px; right: 20px; z-index: 1040; border-radius: 999px; box-shadow: 0 10px 24px rgba(0,0,0,0.18); padding: 12px 18px; display: flex; align-items: center; gap: 8px; }
.chat-launcher i { font-size: 1.1rem; }
.chat-widget { position: fixed; bottom: 86px; right: 20px; width: 360px; max-width: 92vw; height: 520px; max-height: 78vh; background: #fff; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,0.25); border: 1px solid #e5e7eb; overflow: hidden; z-index: 1040; display: none; }
.chat-widget.open { display: block; }
.chat-widget iframe { width: 100%; height: calc(100% - 48px); border: 0; }
.chat-widget .chat-widget-header { height: 48px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #0d6efd, #4dabf7); color: #fff; }
.chat-widget .chat-widget-header .btn { color: #fff; border-color: rgba(255,255,255,0.3); }
.chat-widget .chat-widget-header .btn:hover { background: rgba(255,255,255,0.12); }
body.chat-modal-open .chat-launcher,
body.chat-modal-open .chat-widget { visibility: hidden !important; pointer-events: none !important; }
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

<script src="<?php echo $baseDir; ?>/assets/js/chat-widget.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
