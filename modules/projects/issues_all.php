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

<script>
const projectId = <?php echo $projectId; ?>;
const baseDir = '<?php echo $baseDir; ?>';
let allIssues = [];
let filteredIssues = [];
let loadIssuesDebounceTimer = null;

// Fallback decorateIssueImages function if not loaded from view_issues.js
function decorateIssueImages(html) {
    if (!html) return '';
    return String(html).replace(/<img\b([^>]*)>/gi, function (_, attrs) {
        // Add issue-image-thumb class
        let newAttrs = attrs;
        if (/class\s*=/.test(attrs)) {
            newAttrs = attrs.replace(/class\s*=(["\'])([^"\']*)\1/, 'class="$2 issue-image-thumb"');
        } else {
            newAttrs = 'class="issue-image-thumb" ' + attrs;
        }
        
        // Add lazy loading if not present
        if (!/loading\s*=/.test(newAttrs)) {
            newAttrs += ' loading="lazy"';
        }
        
        // Ensure images have proper styling and error handling
        if (!/style\s*=/.test(newAttrs)) {
            newAttrs += ' style="max-width: 100%; height: auto; cursor: pointer;"';
        }
        
        return '<img ' + newAttrs + '>';
    });
}

// Fallback openIssueImageModal function
function openIssueImageModal(src) {
    var modal = document.getElementById('issueImageModal');
    var previewImg = document.getElementById('issueImagePreview');
    
    if (modal && previewImg) {
        previewImg.src = src;
        previewImg.onerror = function() {
            this.alt = 'Failed to load image: ' + src;
            this.style.border = '2px solid #dc3545';
            this.style.padding = '20px';
            this.style.backgroundColor = '#f8d7da';
        };
        previewImg.onload = function() {
            this.style.border = '';
            this.style.padding = '';
            this.style.backgroundColor = '';
        };
        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    } else {
        // Fallback - open in new tab
        window.open(src, '_blank');
    }
}

// Load all issues with debouncing
function loadIssues(options) {
    const opts = options || {};
    const preserveFilters = !!opts.preserveFilters;
    const silentErrors = !!opts.silentErrors;
    const immediate = !!opts.immediate;
    
    // Clear existing debounce timer
    if (loadIssuesDebounceTimer) {
        clearTimeout(loadIssuesDebounceTimer);
        loadIssuesDebounceTimer = null;
    }
    
    // If immediate, load right away
    if (immediate) {
        return performLoadIssues(preserveFilters, silentErrors);
    }
    
    // Otherwise debounce by 300ms
    return new Promise((resolve) => {
        loadIssuesDebounceTimer = setTimeout(() => {
            performLoadIssues(preserveFilters, silentErrors).then(resolve);
        }, 300);
    });
}

function performLoadIssues(preserveFilters, silentErrors, retryCount = 0) {
    const maxRetries = 3;
    
    // Add timeout and retry logic
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
    return fetch(`${baseDir}/api/issues.php?action=get_all&project_id=${projectId}`, {
        signal: controller.signal,
        headers: {
            'Cache-Control': 'no-cache'
        }
    })
    .then(response => {
        clearTimeout(timeoutId);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            allIssues = data.issues;
            if (preserveFilters) {
                applyFilters();
            } else {
                filteredIssues = allIssues;
                updateCounts();
                renderIssues();
            }
        } else {
            throw new Error(data.message || 'Failed to load issues');
        }
    })
    .catch(error => {
        clearTimeout(timeoutId);
        
        // Retry logic for connection issues
        if (retryCount < maxRetries && (error.name === 'AbortError' || error.message.includes('Failed to fetch') || error.message.includes('CONNECTION_RESET'))) {
            return new Promise((resolve) => {
                setTimeout(() => {
                    performLoadIssues(preserveFilters, true, retryCount + 1).then(resolve);
                }, Math.pow(2, retryCount) * 1000); // Exponential backoff: 1s, 2s, 4s
            });
        }
        
        if (!silentErrors) {
            showError('Failed to load issues: ' + error.message);
        }
        throw error;
    });
}

// Render issues table
function renderIssues() {
    const tbody = document.getElementById('issuesTableBody');
    const userRole = window.ProjectConfig?.userRole || '';
    const isClient = (userRole === 'client');
    const colspan = isClient ? 4 : 7;
    
    if (filteredIssues.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="${colspan}" class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No issues found</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = filteredIssues.map(issue => {
        let mainRow = `<tr class="issue-row" data-issue-id="${issue.id}" style="cursor: pointer;">
            <td>
                <button class="btn btn-link p-0 me-2 text-muted chevron-toggle" style="border: none; background: none;">
                    <i class="fas fa-chevron-right chevron-icon" id="chevron-${issue.id}"></i>
                </button>
                <span class="badge bg-primary">${escapeHtml(issue.issue_key)}</span>
            </td>
            <td>
                ${issue.common_title ? 
                    `<div>${escapeHtml(issue.common_title)}</div>
                     <small class="text-muted">${escapeHtml(issue.title)}</small>` 
                    : 
                    `<div>${escapeHtml(issue.title)}</div>`
                }
            </td>
            <td>
                <small>${issue.pages ? escapeHtml(issue.pages) : '<span class="text-muted">No pages</span>'}</small>
            </td>
            <td>
                <span class="status-badge" style="background-color: ${issue.status_color}; color: white;">
                    ${escapeHtml(issue.status_name)}
                </span>
            </td>`;
        
        // QA Status, Reporter, Actions columns - hide for client
        if (!isClient) {
            mainRow += `
            <td>
                ${issue.qa_statuses && issue.qa_statuses.length > 0 ? issue.qa_statuses.map(qs => {
                    const bgColor = getBootstrapColor(qs.color || 'secondary');
                    const textColor = getContrastColor(bgColor);
                    return `<span class="qa-status-badge" style="background-color: ${bgColor} !important; color: ${textColor} !important;">${escapeHtml(qs.label)}</span>`;
                }).join(' ') : '<span class="text-muted">-</span>'}
            </td>
            <td>
                <small>${issue.reporters ? escapeHtml(issue.reporters) : '<span class="text-muted">-</span>'}</small>
            </td>
            <td>
                <button class="btn btn-sm btn-outline-primary edit-btn me-1" data-issue-id="${issue.id}" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger delete-btn" data-issue-id="${issue.id}" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </td>`;
        }
        
        mainRow += `</tr>
        <tr id="issue-details-${issue.id}" style="display: none;">
            <td colspan="${colspan}" class="p-0">
                <div class="bg-light p-4 border-top">
                    <div class="row">
                        <div class="col-md-8">
                            <h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Issue Details</h6>
                            <div class="card">
                                <div class="card-body issue-content">
                                    ${decorateIssueImages(issue.description || '') || '<p class="text-muted">No details provided.</p>'}
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Metadata</h6>
                            <div class="card">
                                <div class="card-body">
                                    ${issue.common_title ? `
                                    <div class="mb-2">
                                        <strong>Common Title:</strong><br>
                                        ${escapeHtml(issue.common_title)}
                                    </div>
                                    ` : ''}
                                    <div class="mb-2">
                                        <strong>Issue Key:</strong><br>
                                        <span class="badge bg-primary">${escapeHtml(issue.issue_key)}</span>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Status:</strong><br>
                                        <span class="status-badge" style="background-color: ${issue.status_color}; color: white;">${escapeHtml(issue.status_name)}</span>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Severity:</strong><br>
                                        <span class="badge bg-warning text-dark">${escapeHtml((issue.severity || 'N/A').toUpperCase())}</span>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Priority:</strong><br>
                                        <span class="badge bg-info text-dark">${escapeHtml((issue.priority || 'N/A').toUpperCase())}</span>
                                    </div>`;
        
        // QA Status, Reporter(s) - hide for clients
        if (!isClient) {
            mainRow += `
                                    <div class="mb-2">
                                        <strong>QA Status:</strong><br>
                                        ${issue.qa_statuses && issue.qa_statuses.length > 0 ? issue.qa_statuses.map(qs => {
                                            const bgColor = getBootstrapColor(qs.color || 'secondary');
                                            const textColor = getContrastColor(bgColor);
                                            return `<span class="qa-status-badge" style="background-color: ${bgColor} !important; color: ${textColor} !important;">${escapeHtml(qs.label)}</span>`;
                                        }).join(' ') : '<span class="text-muted">N/A</span>'}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Reporter(s):</strong><br>
                                        ${issue.reporters ? escapeHtml(issue.reporters) : '<span class="text-muted">N/A</span>'}
                                    </div>`;
        }
        
        mainRow += `
                                    <div class="mb-2">
                                        <strong>Page(s):</strong><br>
                                        ${issue.pages ? escapeHtml(issue.pages) : '<span class="text-muted">No pages</span>'}
                                    </div>
                                    ${issue.grouped_urls && issue.grouped_urls.length > 0 ? `
                                    <div class="mb-2">
                                        <strong>Grouped URLs:</strong>
                                        <button class="btn btn-link p-0 ms-2 text-primary" style="font-size: 12px; text-decoration: none;" onclick="toggleGroupedUrls(${issue.id}, event)">
                                            <i class="fas fa-chevron-down" id="grouped-urls-icon-${issue.id}"></i>
                                            <span id="grouped-urls-text-${issue.id}">Show (${issue.grouped_urls.length})</span>
                                        </button>
                                        <div id="grouped-urls-content-${issue.id}" style="display: none; margin-top: 8px;">
                                            <small>${issue.grouped_urls.map(url => escapeHtml(url)).join('<br>')}</small>
                                        </div>
                                    </div>
                                    ` : ''}`;
        
        // Created and Updated - hide for client
        if (!isClient) {
            mainRow += `
                                    <div class="mb-2">
                                        <strong>Created:</strong><br>
                                        <small class="text-muted">${new Date(issue.created_at).toLocaleString()}</small>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Updated:</strong><br>
                                        <small class="text-muted">${new Date(issue.updated_at).toLocaleString()}</small>
                                    </div>`;
        }
        
        // Add custom metadata fields
        if (window.ProjectConfig && window.ProjectConfig.metadataFields && issue.metadata) {
            window.ProjectConfig.metadataFields.forEach(field => {
                // Skip severity and priority as they're already shown above for all users
                if (field.field_key === 'severity' || field.field_key === 'priority') return;
                
                const value = issue.metadata[field.field_key];
                if (value && value.length > 0) {
                    const displayValue = Array.isArray(value) ? value.join(', ') : value;
                    mainRow += `
                                    <div class="mb-2">
                                        <strong>${escapeHtml(field.field_label)}:</strong><br>
                                        ${escapeHtml(displayValue)}
                                    </div>`;
                }
            });
        }
        
        mainRow += `
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </td>
        </tr>`;
        
        return mainRow;
    }).join('');
    
    // Attach event listeners
    attachEventListeners();
}

// Update counts
function updateCounts() {
    document.getElementById('totalCount').textContent = allIssues.length;
    document.getElementById('filteredCount').textContent = filteredIssues.length;
}

// Apply filters
function applyFilters() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const pageFilter = $('#filterPage').val() || []; // Get array of selected values
    const statusFilter = $('#filterStatus').val() || []; // Get array of selected values
    const qaStatusFilterEl = document.getElementById('filterQAStatus');
    const qaStatusFilter = qaStatusFilterEl ? ($(qaStatusFilterEl).val() || []) : [];
    const reporterFilterEl = document.getElementById('filterReporter');
    const reporterFilter = reporterFilterEl ? ($(reporterFilterEl).val() || []) : [];
    
    filteredIssues = allIssues.filter(issue => {
        // Search filter — covers all visible columns: key, title, page(s), status, QA status, reporter
        if (searchTerm) {
            // Strip HTML tags from description for plain-text search
            const stripHtmlTags = (html) => {
                if (!html) return '';
                return String(html).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            };
            // qa_statuses is an array of {key, label, color} objects
            const qaLabels = Array.isArray(issue.qa_statuses)
                ? issue.qa_statuses.map(qs => String(qs.label || '')).join(' ')
                : String(issue.qa_statuses || '');
            const commonTitle = Array.isArray(issue.common_title)
                ? issue.common_title.join(' ')
                : String(issue.common_title || '');
            const searchableText = [
                issue.issue_key,
                issue.title,
                commonTitle,
                issue.pages,        // "1 - Home, 2 - About"
                issue.status_name,  // "Need Review"
                qaLabels,           // "Passed Failed"
                issue.reporters,    // "John Doe, Jane Smith"
                stripHtmlTags(issue.description)
            ].filter(v => v && String(v).trim()).join(' ').toLowerCase();
            if (!searchableText.includes(searchTerm)) return false;
        }
        
        // Page filter - check if any selected page matches any of the issue's pages
        if (pageFilter.length > 0 && !pageFilter.includes('')) {
            if (!issue.page_ids || !pageFilter.some(pid => issue.page_ids.includes(parseInt(pid)))) {
                return false;
            }
        }
        
        // Status filter - check if issue status matches any selected status
        if (statusFilter.length > 0 && !statusFilter.includes('')) {
            if (!statusFilter.includes(String(issue.status_id))) return false;
        }
        
        // QA Status filter - check if any issue QA status matches any selected QA status
        if (qaStatusFilter.length > 0 && !qaStatusFilter.includes('')) {
            if (!issue.qa_status_keys || !qaStatusFilter.some(qas => issue.qa_status_keys.includes(qas))) {
                return false;
            }
        }
        
        // Reporter filter - check if any issue reporter matches any selected reporter
        if (reporterFilter.length > 0 && !reporterFilter.includes('')) {
            if (!issue.reporter_ids || !reporterFilter.some(rid => issue.reporter_ids.includes(parseInt(rid)))) {
                return false;
            }
        }
        
        return true;
    });
    
    updateCounts();
    renderIssues();
}

// Attach event listeners
function attachEventListeners() {
    // Edit buttons
    const editButtons = document.querySelectorAll('.edit-btn');
    
    editButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const issueId = this.dataset.issueId;
            editIssue(issueId);
        });
    });
    
    // Delete buttons
    const deleteButtons = document.querySelectorAll('.delete-btn');
    
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const issueId = this.dataset.issueId;
            deleteIssue(issueId);
        });
    });
    
    // Row click to view details - open in detail page
    document.querySelectorAll('.issue-row').forEach(row => {
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on buttons
            if (e.target.closest('.edit-btn') || e.target.closest('.delete-btn')) {
                return;
            }
            const issueId = this.dataset.issueId;
            
            // Toggle expand/collapse
            const detailsRow = document.getElementById(`issue-details-${issueId}`);
            const chevron = document.getElementById(`chevron-${issueId}`);
            
            if (detailsRow) {
                if (detailsRow.style.display === 'none' || !detailsRow.style.display) {
                    // Expand
                    detailsRow.style.display = 'table-row';
                    if (chevron) {
                        chevron.classList.remove('fa-chevron-right');
                        chevron.classList.add('fa-chevron-down');
                    }
                    
                    // Re-attach image handlers after expanding (in case they weren't attached)
                    setTimeout(() => {
                        attachImageHandlers();
                    }, 100);
                } else {
                    // Collapse
                    detailsRow.style.display = 'none';
                    if (chevron) {
                        chevron.classList.remove('fa-chevron-down');
                        chevron.classList.add('fa-chevron-right');
                    }
                }
            }
        });
    });
    
    // Attach image handlers
    attachImageHandlers();
}

// Separate function for image handlers with throttling
function attachImageHandlers() {
    // Image click handlers for issue images with throttling
    const images = document.querySelectorAll('#issuesTableBody img, #issuesTableBody .issue-image-thumb');
    
    // Process images in batches to avoid overwhelming the server
    let imageIndex = 0;
    const batchSize = 10;
    
    function processBatch() {
        const batch = Array.from(images).slice(imageIndex, imageIndex + batchSize);
        
        batch.forEach(img => {
            img.style.cursor = 'pointer';
            
            // Remove existing handler to avoid duplicates
            if (img._imageClickHandler) {
                img.removeEventListener('click', img._imageClickHandler);
            }
            
            img._imageClickHandler = function(e) {
                e.stopPropagation();
                e.preventDefault();
                
                var src = this.getAttribute('src');
                if (src && typeof openIssueImageModal === 'function') {
                    openIssueImageModal(src);
                } else if (src) {
                    // Fallback if openIssueImageModal is not available
                    window.open(src, '_blank');
                }
            };
            
            img.addEventListener('click', img._imageClickHandler);
            
            // Add lazy loading attribute if not present
            if (!img.hasAttribute('loading')) {
                img.setAttribute('loading', 'lazy');
            }
            
            // Add error handling for broken images
            if (!img._errorHandlerAttached) {
                img.onerror = function() {
                    this.style.border = '2px solid #dc3545';
                    this.style.backgroundColor = '#f8d7da';
                    this.title = 'Image failed to load: ' + this.src;
                    this.alt = 'Failed to load image';
                };
                
                // Add load success handler
                img.onload = function() {
                    this.style.border = '';
                    this.style.backgroundColor = '';
                    this.title = 'Click to view full size';
                };
                
                img._errorHandlerAttached = true;
            }
        });
        
        imageIndex += batchSize;
        
        // Process next batch after a small delay
        if (imageIndex < images.length) {
            setTimeout(processBatch, 50);
        }
    }
    
    // Start processing batches
    if (images.length > 0) {
        processBatch();
    }
}

// Edit issue - open modal
function editIssue(issueId) {
    const issueData = allIssues.find(i => i.id == issueId);
    if (!issueData) {
        showError('Issue not found. ID: ' + issueId);
        return;
    }
    
    // Transform API data format to match what openFinalEditor expects
    const issue = {
        id: issueData.id,
        issue_key: issueData.issue_key,
        title: issueData.title,
        details: issueData.description, // API returns 'description', modal expects 'details'
        common_title: issueData.common_title || '',
        status_id: issueData.status_id,
        status: issueData.status_name,
        pages: issueData.page_ids || [], // Array of page IDs
        grouped_urls: Array.isArray(issueData.grouped_urls) ? issueData.grouped_urls : [], // Grouped URLs as array
        reporters: issueData.reporter_ids || [], // Array of reporter IDs
        qa_status: issueData.qa_status_keys || [], // Array of QA status keys
        severity: issueData.severity || 'medium',
        priority: issueData.priority || 'medium',
        updated_at: issueData.updated_at || null,
        latest_history_id: issueData.latest_history_id != null ? issueData.latest_history_id : 0,
        reporter_qa_status_map: (function() {
            var raw = issueData.reporter_qa_status_map;
            // API may return array of JSON strings - parse to plain object
            if (Array.isArray(raw)) {
                for (var i = 0; i < raw.length; i++) {
                    try {
                        var parsed = (typeof raw[i] === 'string') ? JSON.parse(raw[i]) : raw[i];
                        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) return parsed;
                    } catch(e) {}
                }
                return {};
            }
            if (typeof raw === 'string') {
                try { return JSON.parse(raw); } catch(e) { return {}; }
            }
            return (raw && typeof raw === 'object') ? raw : {};
        })(),
        assignee_ids: Array.isArray(issueData.assignee_ids) && issueData.assignee_ids.length
            ? issueData.assignee_ids.map(String)
            : (issueData.assignee_id ? [String(issueData.assignee_id)] : [])
    };
    
    // Add any additional metadata fields from the metadata object
    if (issueData.metadata) {
        Object.keys(issueData.metadata).forEach(key => {
            // Skip fields we've already mapped
            if (!['common_title', 'severity', 'priority', 'grouped_urls', 'page_ids', 'qa_status', 'reporter_ids'].includes(key)) {
                issue[key] = issueData.metadata[key];
            }
        });
    }
    
    // Set a default selectedPageId (required by view_issues.js)
    if (window.issueData) {
        // Use first page from issue
        if (issue.pages && issue.pages.length > 0) {
            window.issueData.selectedPageId = issue.pages[0];
        } else if (window.ProjectConfig && window.ProjectConfig.projectPages && window.ProjectConfig.projectPages.length > 0) {
            // Fallback to first project page
            window.issueData.selectedPageId = window.ProjectConfig.projectPages[0].id;
        }
    }
    
    // Open the modal with transformed issue data
    if (typeof openFinalEditor === 'function') {
        openFinalEditor(issue);
    } else {
        showError('Issue editor not loaded. Please refresh the page.');
    }
}

// Open edit modal (legacy function for compatibility)
function openEditModal(issueId) {
    // This will use the existing modal from issues_modals.php
    const issue = allIssues.find(i => i.id == issueId);
    if (issue) {
        // Trigger the edit modal (you'll need to implement this based on your existing modal code)
        window.location.href = `${baseDir}/modules/projects/issues_page_detail.php?project_id=${projectId}&issue_id=${issueId}`;
    }
}

// Delete issue
function deleteIssue(issueId) {
    // Use Bootstrap modal for confirmation instead of browser confirm
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        // Create a custom confirmation modal
        const modalHtml = `
            <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title">Confirm Delete</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to delete this issue?</p>
                            <p class="text-muted mb-0"><small>This action cannot be undone.</small></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('deleteConfirmModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Show modal
        const modalEl = document.getElementById('deleteConfirmModal');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
        
        // Handle confirm button
        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            modal.hide();
            performDelete(issueId);
        });
        
        // Clean up modal after it's hidden
        modalEl.addEventListener('hidden.bs.modal', function() {
            modalEl.remove();
        });
    } else {
        // Fallback to browser confirm
        if (!window.confirm('Are you sure you want to delete this issue?')) {
            return;
        }
        performDelete(issueId);
    }
}

// Perform the actual delete operation
function performDelete(issueId) {
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('ids', String(issueId));
    fd.append('project_id', projectId);
    
    fetch(`${baseDir}/api/issues.php`, {
        method: 'POST',
        body: fd
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess('Issue deleted successfully');
            // Debounced reload
            loadIssues({ preserveFilters: true, silentErrors: true });
        } else {
            showError(data.message || data.error || 'Failed to delete issue');
        }
    })
    .catch(error => {
        showError('Error deleting issue');
    });
}

// Helper functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Toggle grouped URLs visibility
function toggleGroupedUrls(issueId, event) {
    event.stopPropagation();
    const content = document.getElementById(`grouped-urls-content-${issueId}`);
    const icon = document.getElementById(`grouped-urls-icon-${issueId}`);
    const text = document.getElementById(`grouped-urls-text-${issueId}`);
    
    if (content && icon && text) {
        if (content.style.display === 'none') {
            content.style.display = 'block';
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
            text.textContent = 'Hide';
        } else {
            content.style.display = 'none';
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
            const urlCount = content.querySelectorAll('small')[0]?.innerHTML.split('<br>').length || 0;
            text.textContent = `Show (${urlCount})`;
        }
    }
}

// Function to determine if a color is light or dark
function getContrastColor(hexColor) {
    // Remove # if present
    const hex = hexColor.replace('#', '');
    
    // Convert to RGB
    const r = parseInt(hex.substr(0, 2), 16);
    const g = parseInt(hex.substr(2, 2), 16);
    const b = parseInt(hex.substr(4, 2), 16);
    
    // Calculate luminance
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    
    // Return black for light backgrounds, white for dark backgrounds
    return luminance > 0.5 ? '#000000' : '#ffffff';
}

// Convert Bootstrap color names to hex colors
function getBootstrapColor(colorName) {
    const colorMap = {
        'primary': '#0d6efd',
        'secondary': '#6c757d',
        'success': '#198754',
        'danger': '#dc3545',
        'warning': '#ffc107',
        'info': '#0dcaf0',
        'light': '#f8f9fa',
        'dark': '#212529'
    };
    
    // If it's already a hex color, return it
    if (colorName && colorName.startsWith('#')) {
        return colorName;
    }
    
    // Return mapped color or default
    return colorMap[colorName] || colorMap['secondary'];
}

function showSuccess(message) {
    if (typeof showToast === 'function') showToast(message, 'success');
}

function showError(message) {
    if (typeof showToast === 'function') showToast(message, 'danger');
}

// Event listeners
document.getElementById('searchInput').addEventListener('input', applyFilters);
$('#filterPage').on('change', applyFilters);
$('#filterStatus').on('change', applyFilters);

// QA Status and Reporter filters - only for non-client users
const qaStatusFilterEl = document.getElementById('filterQAStatus');
if (qaStatusFilterEl) {
    $(qaStatusFilterEl).on('change', applyFilters);
}
const reporterFilterEl = document.getElementById('filterReporter');
if (reporterFilterEl) {
    $(reporterFilterEl).on('change', applyFilters);
}

document.getElementById('clearFilters').addEventListener('click', function() {
    document.getElementById('searchInput').value = '';
    $('#filterPage').val([]).trigger('change');
    $('#filterStatus').val([]).trigger('change');
    if (qaStatusFilterEl) $(qaStatusFilterEl).val([]).trigger('change');
    if (reporterFilterEl) $(reporterFilterEl).val([]).trigger('change');
    applyFilters();
});

document.getElementById('refreshBtn').addEventListener('click', function () {
    loadIssues({ preserveFilters: true });
});

// Reload full table whenever an issue is added, edited, or deleted
document.addEventListener('pms:issues-changed', function (e) {
    var detail = e.detail || {};
    var action = detail.action || '';
    var issueId = String(detail.issue_id || '');

    // For internal delete: remove from allIssues and re-render instantly
    if (detail.source === 'internal' && action === 'delete' && issueId) {
        allIssues = allIssues.filter(function(i) { return String(i.id) !== issueId; });
        applyFilters();
        return;
    }

    // For all other changes (create/update from internal or external): reload from API
    // This ensures correct field format (status_name, page_ids, etc.) for renderIssues
    loadIssues({ preserveFilters: true, silentErrors: true });
});

// Initial load - wait for view_issues.js to load
function initializeAllIssuesPage() {
    // Initialize Select2 for multiselect dropdowns
    $('#filterPage').select2({
        placeholder: 'All Pages',
        allowClear: true,
        width: '100%'
    });
    
    $('#filterStatus').select2({
        placeholder: 'All Statuses',
        allowClear: true,
        width: '100%'
    });
    
    const qaStatusFilterEl = document.getElementById('filterQAStatus');
    if (qaStatusFilterEl) {
        $(qaStatusFilterEl).select2({
            placeholder: 'All QA Statuses',
            allowClear: true,
            width: '100%'
        });
    }
    
    const reporterFilterEl = document.getElementById('filterReporter');
    if (reporterFilterEl) {
        $(reporterFilterEl).select2({
            placeholder: 'All Reporters',
            allowClear: true,
            width: '100%'
        });
    }
    
    // Check if decorateIssueImages is available
    if (typeof decorateIssueImages === 'function') {
        loadIssues({ immediate: true });
    } else {
        setTimeout(initializeAllIssuesPage, 100);
    }
}

// Start initialization
initializeAllIssuesPage();

// Handle expand parameter from URL (for QA breakdown links)
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const expandIssueId = urlParams.get('expand');
    
    if (expandIssueId) {
        // Wait for issues to load, then expand the specified issue
        const checkAndExpand = function() {
            if (allIssues.length > 0) {
                const issueRow = document.querySelector(`[data-issue-id="${expandIssueId}"]`);
                if (issueRow) {
                    // Scroll to the issue
                    issueRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    
                    // Expand the issue details
                    setTimeout(function() {
                        issueRow.click();
                        
                        // Highlight the expanded issue
                        issueRow.style.backgroundColor = '#fff3cd';
                        setTimeout(function() {
                            issueRow.style.backgroundColor = '';
                        }, 3000);
                    }, 500);
                } else {
                    // Issue not found in current filter, clear filters and try again
                    document.getElementById('clearFilters').click();
                    setTimeout(checkAndExpand, 1000);
                }
            } else {
                // Issues not loaded yet, wait and try again
                setTimeout(checkAndExpand, 500);
            }
        };
        
        checkAndExpand();
    }
});

// Also attach image handlers on DOM ready as a fallback
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        attachImageHandlers();
    }, 500);
});

// Handle Add Issue button - open finalIssueModal for new issue
document.getElementById('addIssueBtn').addEventListener('click', function() {
    // Set a default selectedPageId if pages exist (required by view_issues.js)
    if (window.issueData && window.ProjectConfig && window.ProjectConfig.projectPages && window.ProjectConfig.projectPages.length > 0) {
        // Set to first page as default, but user can change it in the modal
        window.issueData.selectedPageId = window.ProjectConfig.projectPages[0].id;
    }
    
    // Call openFinalEditor from view_issues.js with null to create new issue
    if (typeof openFinalEditor === 'function') {
        openFinalEditor(null);
    } else {
        showError('Issue editor not loaded. Please refresh the page.');
    }
});

// Override the save button handler to work without selectedPageId requirement
document.addEventListener('DOMContentLoaded', function() {
    if (!window.ProjectConfig.canUpdateIssueQaStatus) {
        jQuery('#finalIssueQaStatus').prop('disabled', true).trigger('change.select2');
        jQuery('#finalIssueQaStatus').attr('title', 'Only authorized users can update QA status.');
    }
    // Wait for view_issues.js to load and attach its handler first
    setTimeout(function() {
        // NOTE: We do NOT replace the save button handler here.
        // view_issues.js's addOrUpdateFinalIssue handles saving correctly.
        // Replacing it caused the description to be wiped because the custom
        // handler called summernote('code') before the editor was ready.
        // The pms:issues-changed event below handles reloading the list after save.
        
        // Ensure reset template button works
        const resetBtn = document.getElementById('btnResetToTemplate');
        if (resetBtn) {
            // Remove any existing handlers
            const newResetBtn = resetBtn.cloneNode(true);
            resetBtn.parentNode.replaceChild(newResetBtn, resetBtn);
            
            // Add our handler
            newResetBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Check if there's already content
                const currentContent = jQuery('#finalIssueDetails').summernote('code');
                const plainText = String(currentContent || '').replace(/<[^>]*>/g, '').trim();
                
                if (plainText && !confirm('This will replace the current content with the default template. Continue?')) {
                    return;
                }
                
                // Fetch default sections from API
                fetch(`${baseDir}/api/issue_templates.php?action=list&project_type=<?php echo $project['type'] ?? 'web'; ?>`, {
                    credentials: 'same-origin'
                })
                .then(res => res.json())
                .then(data => {
                    const sections = data.default_sections || [];
                    if (sections.length === 0) {
                        showError('No default template sections configured for this project type.');
                        return;
                    }
                    
                    // Build HTML from sections with 2 empty lines between sections
                    const html = sections.map(s => {
                        const escaped = String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        return '<p><strong>[' + escaped + ']</strong></p><p><br></p><p><br></p>';
                    }).join('');
                    
                    // Set the content
                    jQuery('#finalIssueDetails').summernote('code', html);
                    
                    if (window.showToast) {
                        showToast('Template sections loaded', 'success');
                    }
                })
                .catch(err => {
                    showError('Failed to load template sections. Please try again.');
                });
            });
        }
    }, 500);
});

</script>

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

    // Prevent chat overlapping any bootstrap modal on this page.
    function syncModalState() {
        var hasOpenModal = document.querySelector('.modal.show') !== null;
        document.body.classList.toggle('chat-modal-open', hasOpenModal);
        if (hasOpenModal) {
            widget.classList.remove('open');
            launcher.style.display = 'none';
        } else {
            launcher.style.display = 'inline-flex';
        }
    }

    document.addEventListener('show.bs.modal', syncModalState, true);
    document.addEventListener('shown.bs.modal', syncModalState, true);
    document.addEventListener('hidden.bs.modal', syncModalState, true);
    syncModalState();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
