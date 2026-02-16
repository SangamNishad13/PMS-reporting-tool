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
            up.name AS unique_name,
            up.canonical_url,
            MIN(pp.id) AS mapped_page_id
        FROM unique_pages up
        LEFT JOIN grouped_urls gu ON gu.project_id = up.project_id AND gu.unique_page_id = up.id
        LEFT JOIN project_pages pp ON pp.project_id = up.project_id
            AND (
                pp.url = gu.url
                OR pp.url = gu.normalized_url
                OR pp.url = up.canonical_url
                OR pp.page_name = up.name
                OR pp.page_number = up.name
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
