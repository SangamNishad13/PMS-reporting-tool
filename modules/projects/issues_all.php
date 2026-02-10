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

// Get filter options
$pagesStmt = $db->prepare("SELECT id, page_name, page_number FROM project_pages WHERE project_id = ? ORDER BY page_number, page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

$statusesStmt = $db->query("SELECT id, name, color FROM issue_statuses ORDER BY id");
$issueStatuses = $statusesStmt->fetchAll(PDO::FETCH_ASSOC);

$qaStatusesStmt = $db->query("SELECT status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1 ORDER BY display_order");
$qaStatuses = $qaStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

$reportersStmt = $db->prepare("
    SELECT DISTINCT u.id, u.full_name 
    FROM users u
    INNER JOIN user_assignments ua ON u.id = ua.user_id
    WHERE ua.project_id = ? AND u.is_active = 1 AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    ORDER BY u.full_name
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
        up.name AS unique_name, 
        up.canonical_url, 
        pp.id AS mapped_page_id, 
        pp.page_name AS mapped_page_name 
    FROM grouped_urls gu 
    LEFT JOIN unique_pages up ON gu.unique_page_id = up.id 
    LEFT JOIN project_pages pp ON pp.project_id = gu.project_id 
        AND (pp.url = gu.url OR pp.url = gu.normalized_url) 
    WHERE gu.project_id = ? 
    ORDER BY gu.url
");
$groupedStmt->execute([$projectId]);
$groupedUrls = $groupedStmt->fetchAll(PDO::FETCH_ASSOC);

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
.status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
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
                    <button class="btn btn-primary me-2" id="addIssueBtn">
                        <i class="fas fa-plus me-1"></i> Add Issue
                    </button>
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
                <select class="form-select" id="filterPage">
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
                <select class="form-select" id="filterStatus">
                    <option value="">All Statuses</option>
                    <?php foreach ($issueStatuses as $status): ?>
                        <option value="<?php echo $status['id']; ?>"><?php echo htmlspecialchars($status['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-check-circle me-1"></i> QA Status</label>
                <select class="form-select" id="filterQAStatus">
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
                <select class="form-select" id="filterReporter">
                    <option value="">All Reporters</option>
                    <?php foreach ($projectUsers as $reporter): ?>
                        <option value="<?php echo $reporter['id']; ?>"><?php echo htmlspecialchars($reporter['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
                            <th style="width: 150px;">QA Status</th>
                            <th style="width: 120px;">Reporter</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="issuesTableBody">
                        <tr>
                            <td colspan="7" class="text-center py-5">
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
    projectPages: <?php echo json_encode($projectPages); ?>,
    groupedUrls: <?php echo json_encode($groupedUrls); ?>,
    baseDir: '<?php echo $baseDir; ?>',
    projectUsers: <?php echo json_encode($projectUsers); ?>,
    qaStatuses: <?php echo json_encode($qaStatuses); ?>,
    issueStatuses: <?php echo json_encode($issueStatuses); ?>
};
</script>

<!-- Include issue management JavaScript -->
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_core.js"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js"></script>

<script>
const projectId = <?php echo $projectId; ?>;
const baseDir = '<?php echo $baseDir; ?>';
let allIssues = [];
let filteredIssues = [];

// Load all issues
function loadIssues() {
    fetch(`${baseDir}/api/issues.php?action=get_all&project_id=${projectId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allIssues = data.issues;
                filteredIssues = allIssues;
                updateCounts();
                renderIssues();
            } else {
                showError('Failed to load issues');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Error loading issues');
        });
}

// Render issues table
function renderIssues() {
    const tbody = document.getElementById('issuesTableBody');
    
    if (filteredIssues.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No issues found</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = filteredIssues.map(issue => `
        <tr class="issue-row" data-issue-id="${issue.id}" style="cursor: pointer;">
            <td>
                <button class="btn btn-link p-0 me-2 text-muted chevron-toggle" style="border: none; background: none;">
                    <i class="fas fa-chevron-right chevron-icon" id="chevron-${issue.id}"></i>
                </button>
                <strong>${escapeHtml(issue.issue_key)}</strong>
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
            </td>
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
            </td>
        </tr>
        <tr id="issue-details-${issue.id}" style="display: none;">
            <td colspan="7" class="p-0">
                <div class="bg-light p-4 border-top">
                    <div class="row">
                        <div class="col-md-8">
                            <h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Issue Details</h6>
                            <div class="card">
                                <div class="card-body">
                                    ${issue.description || '<p class="text-muted">No details provided.</p>'}
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Metadata</h6>
                            <div class="card">
                                <div class="card-body">
                                    <div class="mb-2">
                                        <strong>Issue Key:</strong><br>
                                        <span class="badge bg-primary">${escapeHtml(issue.issue_key)}</span>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Status:</strong><br>
                                        <span class="status-badge" style="background-color: ${issue.status_color}; color: white;">${escapeHtml(issue.status_name)}</span>
                                    </div>
                                    <div class="mb-2">
                                        <strong>QA Status:</strong><br>
                                        ${issue.qa_statuses && issue.qa_statuses.length > 0 ? issue.qa_statuses.map(qs => {
                                            const bgColor = getBootstrapColor(qs.color || 'secondary');
                                            const textColor = getContrastColor(bgColor);
                                            return `<span class="qa-status-badge" style="background-color: ${bgColor} !important; color: ${textColor} !important;">${escapeHtml(qs.label)}</span>`;
                                        }).join(' ') : '<span class="text-muted">N/A</span>'}
                                    </div>
                                    <div class="mb-2">
                                        <strong>Severity:</strong><br>
                                        <span class="badge bg-warning text-dark">${escapeHtml((issue.severity || 'N/A').toUpperCase())}</span>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Priority:</strong><br>
                                        <span class="badge bg-info text-dark">${escapeHtml((issue.priority || 'N/A').toUpperCase())}</span>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Reporter(s):</strong><br>
                                        ${issue.reporters ? escapeHtml(issue.reporters) : '<span class="text-muted">N/A</span>'}
                                    </div>
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
                                    ` : ''}
                                    ${issue.common_title ? `
                                    <div class="mb-2">
                                        <strong>Common Title:</strong><br>
                                        ${escapeHtml(issue.common_title)}
                                    </div>
                                    ` : ''}
                                    <div class="mb-2">
                                        <strong>Created:</strong><br>
                                        <small class="text-muted">${new Date(issue.created_at).toLocaleString()}</small>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Updated:</strong><br>
                                        <small class="text-muted">${new Date(issue.updated_at).toLocaleString()}</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    `).join('');
    
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
    const pageFilter = document.getElementById('filterPage').value;
    const statusFilter = document.getElementById('filterStatus').value;
    const qaStatusFilter = document.getElementById('filterQAStatus').value;
    const reporterFilter = document.getElementById('filterReporter').value;
    
    filteredIssues = allIssues.filter(issue => {
        // Search filter
        if (searchTerm) {
            const searchableText = `${issue.issue_key} ${issue.title} ${issue.description || ''}`.toLowerCase();
            if (!searchableText.includes(searchTerm)) return false;
        }
        
        // Page filter
        if (pageFilter && issue.page_ids) {
            if (!issue.page_ids.includes(parseInt(pageFilter))) return false;
        }
        
        // Status filter
        if (statusFilter && issue.status_id != statusFilter) return false;
        
        // QA Status filter
        if (qaStatusFilter && issue.qa_status_keys) {
            if (!issue.qa_status_keys.includes(qaStatusFilter)) return false;
        }
        
        // Reporter filter
        if (reporterFilter && issue.reporter_ids) {
            if (!issue.reporter_ids.includes(parseInt(reporterFilter))) return false;
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
}

// Edit issue - open modal
function editIssue(issueId) {
    const issueData = allIssues.find(i => i.id == issueId);
    if (!issueData) {
        alert('Issue not found. ID: ' + issueId);
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
        priority: issueData.priority || 'medium'
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
        console.error('openFinalEditor function not found');
        alert('Issue editor not loaded. Please refresh the page.');
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
            loadIssues();
        } else {
            showError(data.message || data.error || 'Failed to delete issue');
        }
    })
    .catch(error => {
        console.error('Error:', error);
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
    // Implement your success notification
    alert(message);
}

function showError(message) {
    // Implement your error notification
    alert(message);
}

// Event listeners
document.getElementById('searchInput').addEventListener('input', applyFilters);
document.getElementById('filterPage').addEventListener('change', applyFilters);
document.getElementById('filterStatus').addEventListener('change', applyFilters);
document.getElementById('filterQAStatus').addEventListener('change', applyFilters);
document.getElementById('filterReporter').addEventListener('change', applyFilters);

document.getElementById('clearFilters').addEventListener('click', function() {
    document.getElementById('searchInput').value = '';
    document.getElementById('filterPage').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterQAStatus').value = '';
    document.getElementById('filterReporter').value = '';
    applyFilters();
});

document.getElementById('refreshBtn').addEventListener('click', loadIssues);

// Initial load
loadIssues();

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
        console.error('openFinalEditor function not found');
        alert('Issue editor not loaded. Please refresh the page.');
    }
});

// Override the save button handler to work without selectedPageId requirement
document.addEventListener('DOMContentLoaded', function() {
    // Wait for view_issues.js to load and attach its handler first
    setTimeout(function() {
        const saveBtn = document.getElementById('finalIssueSaveBtn');
        if (saveBtn) {
            // Remove the existing handler and add our custom one
            const newSaveBtn = saveBtn.cloneNode(true);
            saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
            
            newSaveBtn.addEventListener('click', async function() {
                const editId = document.getElementById('finalIssueEditId').value;
                
                // Get title from custom field
                let titleVal = '';
                const titleInput = document.getElementById('customIssueTitle');
                if (titleInput) {
                    titleVal = titleInput.value.trim();
                }
                
                // Get selected pages
                const selectedPages = jQuery('#finalIssuePages').val() || [];
                if (selectedPages.length === 0) {
                    alert('Please select at least one page for this issue.');
                    return;
                }
                
                // Build data object
                const data = {
                    title: titleVal,
                    description: jQuery('#finalIssueDetails').summernote('code'),
                    issue_status: document.getElementById('finalIssueStatus').value,
                    qa_status: jQuery('#finalIssueQaStatus').val() || [],
                    pages: selectedPages,
                    grouped_urls: jQuery('#finalIssueGroupedUrls').val() || [],
                    reporters: jQuery('#finalIssueReporters').val() || [],
                    common_title: document.getElementById('finalIssueCommonTitle').value.trim()
                };
                
                // Get dynamic metadata fields
                const metadata = {};
                
                // Get all metadata fields from the metadata container
                const metadataContainer = document.getElementById('finalIssueMetadataContainer');
                if (metadataContainer) {
                    // Find all input/select elements with IDs starting with 'finalIssueField_'
                    const metadataFields = metadataContainer.querySelectorAll('[id^="finalIssueField_"]');
                    metadataFields.forEach(function(field) {
                        const fieldKey = field.id.replace('finalIssueField_', '');
                        const value = jQuery(field).val();
                        // Handle arrays (multi-select) vs single values
                        if (Array.isArray(value)) {
                            metadata[fieldKey] = value.length === 1 ? value[0] : value;
                        } else {
                            metadata[fieldKey] = value;
                        }
                    });
                }
                
                // Also check for severity and priority if not already in metadata
                if (!metadata.severity) {
                    const severityEl = document.getElementById('finalIssueField_severity');
                    if (severityEl) {
                        const severityVal = jQuery(severityEl).val();
                        metadata.severity = Array.isArray(severityVal) ? (severityVal[0] || 'medium') : (severityVal || 'medium');
                    }
                }
                
                if (!metadata.priority) {
                    const priorityEl = document.getElementById('finalIssueField_priority');
                    if (priorityEl) {
                        const priorityVal = jQuery(priorityEl).val();
                        metadata.priority = Array.isArray(priorityVal) ? (priorityVal[0] || 'medium') : (priorityVal || 'medium');
                    }
                }
                
                if (!data.title) {
                    alert('Issue title is required.');
                    return;
                }
                
                try {
                    const fd = new FormData();
                    fd.append('action', editId ? 'update' : 'create');
                    fd.append('project_id', projectId);
                    if (editId) fd.append('id', editId);
                    fd.append('page_id', selectedPages[0]); // Use first selected page as primary
                    fd.append('metadata', JSON.stringify(metadata));
                    
                    // Append all data fields
                    Object.keys(data).forEach(function(k) {
                        const v = data[k];
                        if (Array.isArray(v)) {
                            fd.append(k, JSON.stringify(v));
                        } else {
                            fd.append(k, v);
                        }
                    });
                    
                    const res = await fetch(`${baseDir}/api/issues.php`, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    });
                    
                    const json = await res.json();
                    
                    if (!json || json.error) {
                        throw new Error(json && json.error ? json.error : 'Save failed');
                    }
                    
                    if (!json.success) {
                        throw new Error(json.message || 'Save failed - server returned unsuccessful response');
                    }
                    
                    // Stop draft autosave and reset form state BEFORE closing modal
                    if (window.issueData) {
                        // Stop autosave timer
                        if (window.issueData.draftTimer) {
                            clearInterval(window.issueData.draftTimer);
                            window.issueData.draftTimer = null;
                        }
                        // Reset initial form state to prevent "unsaved changes" detection
                        window.issueData.initialFormState = null;
                    }
                    
                    // Close modal
                    const modalEl = document.getElementById('finalIssueModal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                    
                    // Show success message
                    showSuccess(editId ? 'Issue updated successfully' : 'Issue created successfully');
                    
                    // Reload issues list
                    loadIssues();
                    
                } catch (e) {
                    console.error('Save error:', e);
                    alert('Unable to save issue: ' + e.message);
                }
            });
        }
        
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
                        alert('No default template sections configured for this project type.');
                        return;
                    }
                    
                    // Build HTML from sections
                    const html = sections.map(s => {
                        const escaped = String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        return '<p style="margin-bottom:0;"><strong>[' + escaped + ']</strong></p><p><br></p>';
                    }).join('');
                    
                    // Set the content
                    jQuery('#finalIssueDetails').summernote('code', html);
                    
                    if (window.showToast) {
                        showToast('Template sections loaded', 'success');
                    }
                })
                .catch(err => {
                    console.error('Error loading template:', err);
                    alert('Failed to load template sections. Please try again.');
                });
            });
        }
    }, 500);
});

</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
