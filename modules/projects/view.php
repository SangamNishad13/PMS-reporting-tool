<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['id'] ?? 0);
if (!$projectId) {
    // Redirect to role-specific projects page
    if ($userRole === 'admin' || $userRole === 'super_admin') {
        header('Location: ' . $baseDir . '/modules/admin/projects.php');
    } elseif ($userRole === 'project_lead') {
        header('Location: ' . $baseDir . '/modules/project_lead/my_projects.php');
    } elseif ($userRole === 'at_tester') {
        header('Location: ' . $baseDir . '/modules/at_tester/my_projects.php');
    } elseif ($userRole === 'ft_tester') {
        header('Location: ' . $baseDir . '/modules/ft_tester/my_projects.php');
    } elseif ($userRole === 'qa') {
        header('Location: ' . $baseDir . '/modules/qa/my_projects.php');
    } else {
        header('Location: ' . $baseDir . '/index.php');
    }
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    // Redirect to role-specific projects page
    if ($userRole === 'admin' || $userRole === 'super_admin') {
        header('Location: ' . $baseDir . '/modules/admin/projects.php');
    } elseif ($userRole === 'project_lead') {
        header('Location: ' . $baseDir . '/modules/project_lead/my_projects.php');
    } elseif ($userRole === 'at_tester') {
        header('Location: ' . $baseDir . '/modules/at_tester/my_projects.php');
    } elseif ($userRole === 'ft_tester') {
        header('Location: ' . $baseDir . '/modules/ft_tester/my_projects.php');
    } elseif ($userRole === 'qa') {
        header('Location: ' . $baseDir . '/modules/qa/my_projects.php');
    } else {
        header('Location: ' . $baseDir . '/index.php');
    }
    exit;
}

$stmt = $db->prepare(" 
    SELECT 
        p.*,
        c.name as client_name,
        pl.full_name as project_lead_name,
        creator.full_name as created_by_name,
        COUNT(DISTINCT pp.id) as total_pages,
        COUNT(DISTINCT CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN pp.id END) as completed_pages,
        ROUND(COUNT(DISTINCT CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN pp.id END) * 100.0 / NULLIF(COUNT(DISTINCT pp.id), 0), 2) as completion_percentage,
        COUNT(DISTINCT tr.page_id) as total_tests,
        COUNT(DISTINCT qr.page_id) as total_qa
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN users pl ON p.project_lead_id = pl.id
    LEFT JOIN users creator ON p.created_by = creator.id
    LEFT JOIN project_pages pp ON p.id = pp.project_id
    LEFT JOIN testing_results tr ON pp.id = tr.page_id
    LEFT JOIN qa_results qr ON pp.id = qr.page_id
    WHERE p.id = ?
    GROUP BY p.id
");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    // Redirect to role-specific projects page
    if ($userRole === 'admin' || $userRole === 'super_admin') {
        header('Location: ' . $baseDir . '/modules/admin/projects.php');
    } elseif ($userRole === 'project_lead') {
        header('Location: ' . $baseDir . '/modules/project_lead/my_projects.php');
    } elseif ($userRole === 'at_tester') {
        header('Location: ' . $baseDir . '/modules/at_tester/my_projects.php');
    } elseif ($userRole === 'ft_tester') {
        header('Location: ' . $baseDir . '/modules/ft_tester/my_projects.php');
    } elseif ($userRole === 'qa') {
        header('Location: ' . $baseDir . '/modules/qa/my_projects.php');
    } else {
        header('Location: ' . $baseDir . '/index.php');
    }
    exit;
}

// Get project hours summary using the same function as manage_assignments
require_once __DIR__ . '/../../includes/hours_validation.php';
$hoursData = getProjectHoursSummary($db, $projectId);

// Calculate hours metrics
$totalHours = $project['total_hours'] ?: 0;
// Show the project's originally assigned total hours as Allocated by default
$allocatedHours = $totalHours ?: ($hoursData['allocated_hours'] ?: 0);
$utilizedHours = $hoursData['utilized_hours'] ?: 0;
// Use allocated hours as the primary budget baseline in the project view UI
$availableHours = max(0, $allocatedHours - $utilizedHours);
$overshootHours = max(0, ($utilizedHours - $allocatedHours));
$utilizationPercentage = $allocatedHours > 0 ? ($utilizedHours / $allocatedHours) * 100 : 0;
$allocationPercentage = $totalHours > 0 ? ($allocatedHours / $totalHours) * 100 : 0;

// Fetch child projects (only if this is a parent)
$childProjects = [];
$isParent = empty($project['parent_project_id']);
if ($isParent) {
    $childStmt = $db->prepare("
        SELECT 
            p.*,
            c.name as client_name,
            pl.full_name as project_lead_name,
            COUNT(DISTINCT pp.id) as total_pages,
            COUNT(DISTINCT CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN pp.id END) as completed_pages,
            ROUND(COUNT(DISTINCT CASE WHEN pp.status IN ('completed', 'qa_in_progress', 'qa_review', 'needs_review') THEN pp.id END) * 100.0 / NULLIF(COUNT(DISTINCT pp.id), 0), 2) as completion_percentage
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        LEFT JOIN users pl ON p.project_lead_id = pl.id
        LEFT JOIN project_pages pp ON p.id = pp.project_id
        WHERE p.parent_project_id = ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $childStmt->execute([$projectId]);
    $childProjects = $childStmt->fetchAll();
}

// Fetch project users (assignments + project lead)
$projectUsersStmt = $db->prepare("SELECT u.id, u.full_name FROM user_assignments ua JOIN users u ON ua.user_id = u.id WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0) UNION SELECT pl.id, pl.full_name FROM projects p JOIN users pl ON p.project_lead_id = pl.id WHERE p.id = ? AND p.project_lead_id IS NOT NULL AND p.project_lead_id NOT IN (SELECT user_id FROM user_assignments WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0))");
$projectUsersStmt->execute([$projectId, $projectId, $projectId]);
$projectUsers = $projectUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch QA statuses from master table
$qaStatusesStmt = $db->query("SELECT id, status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1 ORDER BY display_order ASC, status_label ASC");
$qaStatuses = $qaStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Issue statuses from master table
$issueStatusesStmt = $db->query("SELECT id, status_key, status_label, badge_color FROM issue_status_master WHERE is_active = 1 ORDER BY display_order ASC, status_label ASC");
$issueStatuses = $issueStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

// Team members with roles (used for inline assignment modals)
$teamMemberStmt = $db->prepare("
    SELECT u.id, u.full_name, u.role 
    FROM user_assignments ua 
    JOIN users u ON ua.user_id = u.id 
    WHERE ua.project_id = ? 
      AND ua.role IN ('qa','at_tester','ft_tester')
      AND u.is_active = 1
      AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    ORDER BY u.full_name
");
$teamMemberStmt->execute([$projectId]);
$teamMembers = $teamMemberStmt->fetchAll(PDO::FETCH_ASSOC);

// Environment list for assignment modal
$allEnvironments = $db->query("SELECT id, name FROM testing_environments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch project pages
$pagesStmt = $db->prepare("SELECT id, page_name FROM project_pages WHERE project_id = ? ORDER BY page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Issue page summaries for Issues tab
$issuePageSummaries = [];
try {
    $issuePageStmt = $db->prepare("
        SELECT 
            pp.id,
            pp.page_name,
            (SELECT GROUP_CONCAT(DISTINCT te.name SEPARATOR ', ') FROM page_environments pe2 JOIN testing_environments te ON pe2.environment_id = te.id WHERE pe2.page_id = pp.id) AS envs,
            (SELECT GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') FROM users u JOIN page_environments pe3 ON u.id = pe3.at_tester_id OR u.id = pe3.ft_tester_id OR u.id = pe3.qa_id WHERE pe3.page_id = pp.id) AS testers,
            ((SELECT COALESCE(SUM(tr.issues_found), 0) FROM testing_results tr WHERE tr.page_id = pp.id) + (SELECT COALESCE(SUM(qr.issues_found), 0) FROM qa_results qr WHERE qr.page_id = pp.id)) AS issues_count
        FROM project_pages pp
        WHERE pp.project_id = ?
        ORDER BY pp.page_name
    ");
    $issuePageStmt->execute([$projectId]);
    while ($row = $issuePageStmt->fetch(PDO::FETCH_ASSOC)) {
        $issuePageSummaries[(int)$row['id']] = $row;
    }
} catch (Exception $e) { $issuePageSummaries = []; }

// Fetch unique pages and grouped URLs for the project
$uniqueStmt = $db->prepare("SELECT up.*, COUNT(gu.id) as url_count FROM unique_pages up LEFT JOIN grouped_urls gu ON up.id = gu.unique_page_id WHERE up.project_id = ? GROUP BY up.id ORDER BY up.created_at ASC");
$uniqueStmt->execute([$projectId]);
$uniquePages = $uniqueStmt->fetchAll(PDO::FETCH_ASSOC);

$groupedStmt = $db->prepare("SELECT gu.id AS grouped_id, gu.url, gu.normalized_url, gu.unique_page_id, up.id AS unique_id, up.name AS unique_name, up.canonical_url, pp.id AS mapped_page_id, pp.page_name AS mapped_page_name FROM grouped_urls gu LEFT JOIN unique_pages up ON gu.unique_page_id = up.id LEFT JOIN project_pages pp ON pp.project_id = gu.project_id AND (pp.url = gu.url OR pp.url = gu.normalized_url) WHERE gu.project_id = ? ORDER BY gu.url");
$groupedStmt->execute([$projectId]);
$groupedUrls = $groupedStmt->fetchAll(PDO::FETCH_ASSOC);

// URLs by unique ID
$urlsByUniqueId = [];
if (!empty($groupedUrls)) {
    foreach ($groupedUrls as $g) {
        if (!empty($g['unique_page_id'])) {
            $urlsByUniqueId[(int)$g['unique_page_id']][] = $g;
        }
    }
}

// Issues Pages view data
$uniqueIssuePages = [];
try {
    $uniqueIssueStmt = $db->prepare("
        SELECT 
            up.id AS unique_id,
            up.name AS unique_name,
            up.canonical_url,
            COUNT(gu.id) AS grouped_count,
            MIN(pp.id) AS mapped_page_id,
            MIN(pp.page_number) AS mapped_page_number,
            MIN(pp.page_name) AS mapped_page_name
        FROM unique_pages up
        LEFT JOIN grouped_urls gu ON gu.project_id = up.project_id AND gu.unique_page_id = up.id
        LEFT JOIN project_pages pp ON pp.project_id = up.project_id AND (pp.url = gu.url OR pp.url = gu.normalized_url OR pp.url = up.canonical_url OR pp.page_name = up.name OR pp.page_number = up.name)
        WHERE up.project_id = ?
        GROUP BY up.id
        ORDER BY up.created_at ASC
    ");
    $uniqueIssueStmt->execute([$projectId]);
    $uniqueIssuePages = $uniqueIssueStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $uniqueIssuePages = []; }

// Aggregate totals for Issues > Pages view
$issuesPagesCount = count($uniqueIssuePages);
$issuesTotalCount = 0;
foreach ($uniqueIssuePages as $u) {
    if (isset($u['mapped_page_id']) && isset($issuePageSummaries[$u['mapped_page_id']])) {
        $issuesTotalCount += (int)($issuePageSummaries[$u['mapped_page_id']]['issues_count'] ?? 0);
    }
}

// Load QA statuses for issue modal
$qaStatuses = $db->query("SELECT status_key, status_label FROM qa_status_master WHERE is_active = 1 ORDER BY display_order ASC, status_label ASC")->fetchAll(PDO::FETCH_ASSOC);

// Load issue statuses for issue modal
$issueStatuses = $db->query("SELECT id, name, color FROM issue_statuses ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Load project users for issue modal
$projectUsersStmt = $db->prepare("
    SELECT DISTINCT u.id, u.full_name 
    FROM user_assignments ua 
    JOIN users u ON ua.user_id = u.id 
    WHERE ua.project_id = ? 
      AND u.is_active = 1
      AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    ORDER BY u.full_name
");
$projectUsersStmt->execute([$projectId]);
$projectUsers = $projectUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get current running phase
$currentPhaseStmt = $db->prepare("
    SELECT phase_name, start_date, end_date, status 
    FROM project_phases 
    WHERE project_id = ? AND status = 'in_progress' 
    ORDER BY start_date DESC 
    LIMIT 1
");
$currentPhaseStmt->execute([$projectId]);
$currentPhase = $currentPhaseStmt->fetch(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<!-- Select2 CSS/JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
/* Core Styles */
.timeline-marker { width: 40px; height: 40px; border-radius: 50%; background: #f8f9fa; border: 2px solid #dee2e6; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.timeline-content { padding: 8px 16px; background: #f8f9fa; border-radius: 8px; border-left: 3px solid #007bff; }
.page-toggle-btn { transition: all 0.3s ease; border: 1px solid #dee2e6; background: transparent; }
.page-toggle-btn:hover { background-color: #e9ecef; }
#projectTabsContent { scrollbar-width: thin; scrollbar-color: #6c757d #f8f9fa; }
#projectTabsContent::-webkit-scrollbar { width: 8px; }
#projectTabsContent .table-responsive { 
    max-height: none; 
    overflow-x: auto; 
    overflow-y: visible;
    min-width: 100%;
}
#projectTabsContent .table-responsive thead th { position: sticky; top: 0; z-index: 5; background: #fff; }
.col-resizer { position: absolute; right: 0; top: 0; width: 8px; height: 100%; cursor: col-resize; z-index: 9999; }
.issues-page-list .list-group-item { cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; background: #ffffff; border: 1px solid #e6e9f2; border-left: 5px solid #6c8cff; border-radius: 12px; margin: 10px 0; box-shadow: 0 6px 16px rgba(16, 24, 40, 0.06); }
.issues-page-list .list-group-item:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(16, 24, 40, 0.10); }
.issues-page-list .list-group-item.active { background: #f2f6ff; color: #1b3a8a; border-color: #c6d3ff; border-left-color: #3d6bff; }
.issue-image-thumb { max-width: 100%; max-height: 220px; height: auto; object-fit: contain; border-radius: 8px; box-shadow: 0 6px 14px rgba(16, 24, 40, 0.15); cursor: zoom-in; transition: transform 0.2s ease; }
.issue-image-thumb:hover { transform: scale(1.02); }
.modal { z-index: 10550; }
.modal-backdrop { z-index: 10540; }
.select2-container--open .select2-dropdown { z-index: 10600; }
.select2-results__options { max-height: 250px !important; overflow-y: auto !important; }

/* MAIN PROJECT TABS - keep Bootstrap defaults */
#projectTabsContent {
    min-height: 200px;
}
#projectTabsContent > .tab-pane {
    padding: 1rem;
}

/* Ensure tabs are always reachable (wrap/scroll on smaller widths) */
#projectTabs {
    flex-wrap: wrap;
    row-gap: 0.25rem;
}
#projectTabs .nav-link {
    white-space: nowrap;
}
@media (max-width: 1200px) {
    #projectTabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
    }
}

/* Remove any potential spacing from child elements of hidden tabs */
/* (Bootstrap already handles visibility; keep this block empty on purpose) */

/* Specifically target pages sub-tabs to avoid conflicts */
#pagesSubTabs + .tab-content > .tab-pane {
    display: none !important;
    height: 0;
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
}
#pagesSubTabs + .tab-content > .tab-pane.active {
    display: block !important;
    height: auto;
    overflow: visible;
    opacity: 1;
    visibility: visible;
}

/* Ensure proper Bootstrap tab behavior */
#pagesSubTabs .nav-link.active {
    background-color: #fff;
    border-color: #dee2e6 #dee2e6 #fff;
}

/* Enhanced styling for unique pages filters and buttons */
.btn-group .btn {
    border-radius: 0.375rem;
}
.btn-group .btn:not(:last-child) {
    margin-right: 0.5rem;
}

/* Column resizer functionality */
.resizable-table {
    table-layout: fixed;
    width: 100%;
    min-width: 1200px; /* Ensure table has enough width for all columns */
}
.resizable-table th {
    position: relative;
    overflow: visible;
    text-overflow: ellipsis;
    white-space: nowrap;
}



/* Dropdown column styling */
.resizable-table td.dropdown-cell {
    overflow: visible !important;
    white-space: nowrap;
    width: auto !important;
    min-width: 180px; /* More space for dropdown */
}

.resizable-table td.dropdown-cell select {
    width: 100%;
    min-width: 160px; /* Proper dropdown width */
    font-size: 0.875rem;
    display: block; /* Force dropdown to new line */
    margin-top: 0.25rem;
}


.col-resizer {
    position: absolute;
    right: 0;
    top: 0;
    width: 8px;
    height: 100%;
    cursor: col-resize;
    z-index: 999;
    background: transparent;
    border-right: 1px solid rgba(0, 0, 0, 0.2); /* Subtle black line */
    outline: none;
}
.col-resizer:hover {
    border-right-color: #007bff;
    border-right-width: 2px;
    background: rgba(0, 123, 255, 0.1);
}
.col-resizer.resizing {
    border-right-color: #007bff;
    border-right-width: 2px;
    background: rgba(0, 123, 255, 0.2);
}
.col-resizer.focused {
    border-right-color: #28a745;
    border-right-width: 2px;
    background: rgba(40, 167, 69, 0.1);
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.25);
}
.col-resizer.selected {
    border-right-color: #ffc107;
    border-right-width: 2px;
    background: rgba(255, 193, 7, 0.2);
    box-shadow: 0 0 0 2px rgba(255, 193, 7, 0.5);
}
.col-resizer.focused.selected {
    border-right-color: #fd7e14;
    border-right-width: 2px;
    background: rgba(253, 126, 20, 0.2);
    box-shadow: 0 0 0 2px rgba(253, 126, 20, 0.5);
}


/* Better spacing for filter labels */
.form-label.small {
    font-weight: 500;
    margin-bottom: 0.25rem;
}

/* Improved table styling */
.resizable-table td {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding: 0.5rem 0.75rem;
}

/* Special handling for dropdown columns - keep dropdowns visible */
.resizable-table td.dropdown-cell {
    overflow: visible !important;
    white-space: nowrap;
}

.resizable-table td.dropdown-cell select {
    width: 100%;
    min-width: 120px;
    font-size: 0.875rem;
}
</style>

<?php if ($isParent && count($childProjects) > 0): ?>
    <!-- Sub-Projects Card (collapsed view recommended for huge lists, but showing here) -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Sub-Projects</h5>
            <span class="text-muted">Total <?php echo count($childProjects); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead><tr><th>Code</th><th>Title</th><th>Lead</th><th>Status</th><th>Progress</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($childProjects as $child): 
                            $progress = $child['completion_percentage'] ?? 0;
                            $statusBadge = ($child['status'] === 'completed') ? 'success' : (($child['status'] === 'in_progress') ? 'primary' : 'secondary');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($child['project_code'] ?: $child['po_number']); ?></td>
                            <td><?php echo htmlspecialchars($child['title']); ?></td>
                            <td><?php echo htmlspecialchars($child['project_lead_name'] ?: 'N/A'); ?></td>
                            <td><span class="badge bg-<?php echo $statusBadge; ?>"><?php echo formatProjectStatusLabel($child['status']); ?></span></td>
                            <td>
                                <div class="progress" style="height: 10px; width: 100px;">
                                    <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                                <small><?php echo $progress; ?>%</small>
                            </td>
                            <td><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $child['id']; ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Project Overview Card -->
<div class="card mb-3 shadow-sm">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-lg-8 col-md-7">
                <h2 class="mb-1"><?php echo htmlspecialchars($project['title']); ?> <small class="text-muted">(<?php echo htmlspecialchars($project['po_number'] ?? ''); ?>)</small></h2>
                <div class="mb-2 text-muted small"><?php echo htmlspecialchars($project['client_name'] ?? ''); ?></div>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <span class="badge bg-light text-dark border">Lead: <?php echo htmlspecialchars($project['project_lead_name'] ?? 'N/A'); ?></span>
                    <span class="badge bg-secondary text-white"><?php echo formatProjectStatusLabel($project['status'] ?? 'Draft'); ?></span>
                    <?php 
                    // Priority badge with appropriate color
                    $priority = $project['priority'] ?? 'medium';
                    $priorityColors = [
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'secondary'
                    ];
                    $priorityIcons = [
                        'critical' => 'fa-exclamation-circle',
                        'high' => 'fa-arrow-up',
                        'medium' => 'fa-minus',
                        'low' => 'fa-arrow-down'
                    ];
                    $priorityColor = $priorityColors[$priority] ?? 'secondary';
                    $priorityIcon = $priorityIcons[$priority] ?? 'fa-flag';
                    ?>
                    <span class="badge bg-<?php echo $priorityColor; ?> text-white">
                        <i class="fas <?php echo $priorityIcon; ?> me-1"></i>
                        Priority: <?php echo ucfirst($priority); ?>
                    </span>
                    <?php if ($currentPhase): ?>
                        <span class="badge bg-primary text-white">
                            <i class="fas fa-play-circle me-1"></i>
                            Current Phase: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $currentPhase['phase_name']))); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($currentPhase && ($currentPhase['start_date'] || $currentPhase['end_date'])): ?>
                <div class="small text-muted mb-2">
                    <i class="fas fa-calendar-alt me-1"></i>
                    <?php if ($currentPhase['start_date']): ?>
                        <strong>Start:</strong> <?php echo date('M d, Y', strtotime($currentPhase['start_date'])); ?>
                    <?php endif; ?>
                    <?php if ($currentPhase['start_date'] && $currentPhase['end_date']): ?>
                        <span class="mx-2">|</span>
                    <?php endif; ?>
                    <?php if ($currentPhase['end_date']): ?>
                        <strong>End:</strong> <?php echo date('M d, Y', strtotime($currentPhase['end_date'])); ?>
                        <?php 
                        $daysRemaining = ceil((strtotime($currentPhase['end_date']) - time()) / 86400);
                        if ($daysRemaining > 0): ?>
                            <span class="badge bg-info text-dark ms-2"><?php echo $daysRemaining; ?> days remaining</span>
                        <?php elseif ($daysRemaining < 0): ?>
                            <span class="badge bg-danger ms-2"><?php echo abs($daysRemaining); ?> days overdue</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark ms-2">Due today</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($project['description'])): ?>
                <div class="mb-2">
                    <div class="small text-muted mb-1"><strong><i class="fas fa-info-circle me-1"></i>Description:</strong></div>
                    <div class="text-muted" style="font-size: 0.95rem; line-height: 1.5;">
                        <?php 
                        // Decode HTML entities and display safely
                        $description = html_entity_decode($project['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        echo nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')); 
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="small text-muted">Created by <strong><?php echo htmlspecialchars($project['created_by_name'] ?? ''); ?></strong> on <?php echo date('M d, Y', strtotime($project['created_at'])); ?></div>
            </div>
            <div class="col-lg-4 col-md-5 mt-3 mt-md-0">
                <?php if (in_array($userRole, ['admin','super_admin']) || ($userRole === 'project_lead' && $project['project_lead_id'] == $userId)): ?>
                <div class="d-flex justify-content-md-end mb-2">
                    <a href="<?php echo $baseDir; ?>/modules/projects/edit.php?id=<?php echo $projectId; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</a>
                </div>
                <?php endif; ?>
                
                <?php 
                $isOvershoot = $overshootHours > 0;
                $remainingHours = $allocatedHours - $utilizedHours;
                ?>
                
                <div class="d-flex justify-content-md-end gap-3">
                    <div style="min-width:220px;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="text-center flex-fill">
                                <div class="fw-bold text-primary"><?php echo number_format($allocatedHours, 1); ?></div>
                                <small class="text-muted">Budget</small>
                            </div>
                            <div class="text-center flex-fill">
                                <div class="fw-bold <?php echo $isOvershoot ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo number_format($utilizedHours, 1); ?>
                                </div>
                                <small class="text-muted">Used</small>
                            </div>
                            <div class="text-center flex-fill">
                                <div class="fw-bold <?php echo $isOvershoot ? 'text-danger' : 'text-warning'; ?>">
                                    <?php echo $isOvershoot ? number_format($overshootHours, 1) : number_format($remainingHours, 1); ?>
                                </div>
                                <small class="text-muted"><?php echo $isOvershoot ? 'Overshoot' : 'Remaining'; ?></small>
                            </div>
                        </div>
                        
                        <?php if ($allocatedHours > 0): ?>
                        <div class="progress" style="height: 8px;">
                            <?php if ($isOvershoot): ?>
                                <!-- Green bar for budget (100% of container) -->
                                <div class="progress-bar bg-success" style="width: 100%;" title="Budget: <?php echo number_format($allocatedHours, 1); ?> hours"></div>
                                <!-- Red bar for overshoot hours -->
                                <div class="progress-bar bg-danger" style="width: <?php echo ($overshootHours / $allocatedHours) * 100; ?>%;" title="Overshoot: <?php echo number_format($overshootHours, 1); ?> hours"></div>
                            <?php else: ?>
                                <!-- Normal green bar for used hours within budget -->
                                <div class="progress-bar bg-success" style="width: <?php echo ($utilizedHours / $allocatedHours) * 100; ?>%;" title="Used: <?php echo number_format($utilizedHours, 1); ?> hours"></div>
                            <?php endif; ?>
                        </div>
                        <div class="text-center mt-1">
                            <small class="text-muted">
                                <?php echo round(($utilizedHours / $allocatedHours) * 100, 1); ?>% used
                                <?php if ($isOvershoot): ?>
                                    <span class="text-danger">(<?php echo number_format($overshootHours, 1); ?>h over!)</span>
                                <?php endif; ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$isParent): ?>
<div class="row mb-3">
    <div class="col-md-2"><div class="card text-center"><div class="card-body"><h1 class="display-6"><?php echo $project['completion_percentage'] ?? 0; ?>%</h1><p class="text-muted mb-0">Overall Progress</p></div></div></div>
    <div class="col-md-2"><div class="card text-center"><div class="card-body"><h1 class="display-6"><?php echo count($uniquePages); ?></h1><p class="text-muted mb-0">Total Pages</p></div></div></div>
</div>
<?php endif; ?>

<!-- Quick Access Card for Accessibility Report -->
<div class="card mb-3 border-primary">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h5 class="mb-1">
                    <i class="fas fa-universal-access text-primary me-2"></i>
                    Accessibility Report
                </h5>
                <p class="text-muted small mb-0">View detailed accessibility issues, findings, and compliance reports</p>
            </div>
            <div class="col-md-4 text-md-end">
                <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" 
                   class="btn btn-primary">
                    <i class="fas fa-file-alt me-1"></i> View Report
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mt-3" id="projectTabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" id="phases-tab" data-bs-toggle="tab" data-bs-target="#phases" type="button"><i class="fas fa-layer-group"></i> Phases</button></li>
    <li class="nav-item"><button class="nav-link" id="pages-tab" data-bs-toggle="tab" data-bs-target="#pages" type="button"><i class="fas fa-file-alt"></i> Pages/Screens</button></li>
    <li class="nav-item"><button class="nav-link" id="team-tab" data-bs-toggle="tab" data-bs-target="#team" type="button"><i class="fas fa-users"></i> Team</button></li>
    <li class="nav-item"><button class="nav-link" id="performance-tab" data-bs-toggle="tab" data-bs-target="#performance" type="button"><i class="fas fa-chart-line"></i> Performance</button></li>
    <li class="nav-item"><button class="nav-link" id="assets-tab" data-bs-toggle="tab" data-bs-target="#assets" type="button"><i class="fas fa-paperclip"></i> Assets</button></li>
    <li class="nav-item"><button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button"><i class="fas fa-history"></i> Activity</button></li>
    <li class="nav-item"><button class="nav-link" id="feedback-tab" data-bs-toggle="tab" data-bs-target="#feedback" type="button"><i class="fas fa-comment-dots"></i> Feedback</button></li>
    <li class="nav-item"><button class="nav-link" id="production-hours-tab" data-bs-toggle="tab" data-bs-target="#production-hours" type="button"><i class="fas fa-clock"></i> Hours</button></li>
</ul>

<div class="tab-content border border-top-0" id="projectTabsContent">
    <span id="project_tabs_probe" data-file="view.php" style="display:none;"></span>
    <?php include 'partials/tab_phases.php'; ?>
    <?php include 'partials/tab_pages.php'; ?>
    <?php include 'partials/tab_team.php'; ?>
    <?php include 'partials/tab_performance.php'; ?>
    <?php include 'partials/tab_assets.php'; ?>
    <?php include 'partials/tab_activity.php'; ?>
    <?php include 'partials/tab_feedback.php'; ?>
    <?php include 'partials/tab_production_hours.php'; ?>
</div>

<?php include 'partials/modals.php'; ?>

<script>
    window.ProjectConfig = {
        projectId: <?php echo json_encode($projectId); ?>,
        userId: <?php echo json_encode($userId); ?>,
        userRole: <?php echo json_encode($userRole); ?>,
        baseDir: '<?php echo $baseDir; ?>',
        projectType: '<?php echo $project['type'] ?? 'web'; ?>',
        projectPages: <?php echo json_encode($projectPages ?? []); ?>,
        groupedUrls: <?php echo json_encode($groupedUrls ?? []); ?>,
        projectUsers: <?php echo json_encode($projectUsers ?? []); ?>,
        qaStatuses: <?php echo json_encode($qaStatuses ?? []); ?>,
        issueStatuses: <?php echo json_encode($issueStatuses ?? []); ?>
    };
    
    // Handle focus on assign button after redirect from manage_assignments
    document.addEventListener('DOMContentLoaded', function() {
        // Clear all filters on page load
        clearAllFiltersOnLoad();
        
        // Initialize proper tab behavior for pages sub-tabs
        const pagesSubTabs = document.querySelectorAll('#pagesSubTabs .nav-link');
        const pagesTabPanes = document.querySelectorAll('#pages_main, #unique_pages_sub, #all_urls_sub');
        
        // Function to completely hide inactive panes
        function hideAllPanes() {
            pagesTabPanes.forEach(pane => {
                pane.classList.remove('show', 'active');
                pane.style.display = 'none';
                pane.style.height = '0';
                pane.style.overflow = 'hidden';
                pane.style.opacity = '0';
                pane.style.visibility = 'hidden';
            });
        }
        
        // Function to show active pane
        function showPane(pane) {
            pane.classList.add('show', 'active');
            pane.style.display = 'block';
            pane.style.height = 'auto';
            pane.style.overflow = 'visible';
            pane.style.opacity = '1';
            pane.style.visibility = 'visible';
        }
        
        // Function to activate a specific tab
        function activateTab(tabId, paneId) {
            // Remove active from all tabs
            pagesSubTabs.forEach(t => t.classList.remove('active'));
            
            // Hide all panes
            hideAllPanes();
            
            // Activate the specified tab
            const tab = document.querySelector(tabId);
            if (tab) {
                tab.classList.add('active');
            }
            
            // Show the specified pane
            const pane = document.querySelector(paneId);
            if (pane) {
                showPane(pane);
            }
        }
        
        // Initialize - check URL hash or localStorage for last active tab
        hideAllPanes();
        
        // Check URL hash first (e.g., #all_urls_sub)
        let activeTabPane = '#unique_pages_sub'; // default
        let activeTabBtn = '#unique-sub-tab';
        
        if (window.location.hash) {
            const hash = window.location.hash;
            if (hash === '#all_urls_sub' || hash === '#allurls-sub-tab') {
                activeTabPane = '#all_urls_sub';
                activeTabBtn = '#allurls-sub-tab';
            } else if (hash === '#unique_pages_sub' || hash === '#unique-sub-tab') {
                activeTabPane = '#unique_pages_sub';
                activeTabBtn = '#unique-sub-tab';
            }
        } else {
            // Check localStorage for last active tab
            const lastActiveTab = localStorage.getItem('pagesSubTab_' + <?php echo $projectId; ?>);
            if (lastActiveTab === 'all_urls') {
                activeTabPane = '#all_urls_sub';
                activeTabBtn = '#allurls-sub-tab';
            } else if (lastActiveTab === 'unique_pages') {
                activeTabPane = '#unique_pages_sub';
                activeTabBtn = '#unique-sub-tab';
            }
        }
        
        // Activate the determined tab
        activateTab(activeTabBtn, activeTabPane);
        
        // Add click handlers for proper tab switching
        pagesSubTabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Get target pane ID
                const targetId = this.getAttribute('data-bs-target');
                const tabId = '#' + this.id;
                
                // Save to localStorage
                if (targetId === '#all_urls_sub') {
                    localStorage.setItem('pagesSubTab_' + <?php echo $projectId; ?>, 'all_urls');
                    window.location.hash = 'all_urls_sub';
                } else if (targetId === '#unique_pages_sub') {
                    localStorage.setItem('pagesSubTab_' + <?php echo $projectId; ?>, 'unique_pages');
                    window.location.hash = 'unique_pages_sub';
                }
                
                // Activate the tab
                activateTab(tabId, targetId);
            });
        });
        
        // Initialize column resizing functionality
        function initColumnResize() {
            const tables = document.querySelectorAll('#uniquePagesTable, #allUrlsTable, #issuesPageList table.resizable-table');
            if (!tables.length) return;
            
            tables.forEach(table => {
                const resizers = table.querySelectorAll('.col-resizer');
                let isResizing = false;
                let currentResizer = null;
                let startX = 0;
                let startWidth = 0;
                let selectedResizer = null;
                
                // Make resizers focusable and add keyboard support
                resizers.forEach((resizer, index) => {
                    resizer.setAttribute('tabindex', '0');
                    resizer.setAttribute('role', 'button');
                    resizer.setAttribute('aria-label', `Resize column ${index + 1}. Use arrow keys to resize, Enter to select, Escape to deselect`);
                    
                    // Mouse events
                    resizer.addEventListener('mousedown', function(e) {
                        startResize(this, e.clientX);
                        e.preventDefault();
                    });
                    
                    // Keyboard events
                    resizer.addEventListener('keydown', function(e) {
                        const th = this.parentElement;
                        const currentWidth = parseInt(window.getComputedStyle(th).width, 10);
                        let newWidth = currentWidth;
                        
                        switch(e.key) {
                            case 'ArrowLeft':
                                newWidth = Math.max(50, currentWidth - 10);
                                break;
                            case 'ArrowRight':
                                newWidth = currentWidth + 10;
                                break;
                            case 'ArrowUp':
                                newWidth = Math.max(50, currentWidth - 5);
                                break;
                            case 'ArrowDown':
                                newWidth = currentWidth + 5;
                                break;
                            case 'Home':
                                newWidth = 50; // Minimum width
                                break;
                            case 'End':
                                newWidth = 300; // Maximum reasonable width
                                break;
                            case 'Enter':
                            case ' ':
                                // Toggle selection for fine-tuning
                                if (selectedResizer === this) {
                                    selectedResizer = null;
                                    this.classList.remove('selected');
                                    this.setAttribute('aria-label', `Resize column ${index + 1}. Use arrow keys to resize, Enter to select, Escape to deselect`);
                                } else {
                                    // Deselect previous
                                    if (selectedResizer) {
                                        selectedResizer.classList.remove('selected');
                                    }
                                    selectedResizer = this;
                                    this.classList.add('selected');
                                    this.setAttribute('aria-label', `Column ${index + 1} selected. Use arrow keys for fine control, Enter to deselect`);
                                }
                                e.preventDefault();
                                return;
                            case 'Escape':
                                if (selectedResizer) {
                                    selectedResizer.classList.remove('selected');
                                    selectedResizer = null;
                                    this.setAttribute('aria-label', `Resize column ${index + 1}. Use arrow keys to resize, Enter to select, Escape to deselect`);
                                }
                                e.preventDefault();
                                return;
                            default:
                                return; // Don't prevent default for other keys
                        }
                        
                        // Apply the new width
                        if (newWidth !== currentWidth) {
                            th.style.width = newWidth + 'px';
                            
                            // Visual feedback
                            this.classList.add('resizing');
                            setTimeout(() => {
                                this.classList.remove('resizing');
                            }, 200);
                            
                            // Announce change to screen readers
                            const announcement = document.createElement('div');
                            announcement.setAttribute('aria-live', 'polite');
                            announcement.setAttribute('aria-atomic', 'true');
                            announcement.style.position = 'absolute';
                            announcement.style.left = '-10000px';
                            announcement.textContent = `Column ${index + 1} width changed to ${newWidth} pixels`;
                            document.body.appendChild(announcement);
                            setTimeout(() => document.body.removeChild(announcement), 1000);
                        }
                        
                        e.preventDefault();
                    });
                    
                    // Focus styling
                    resizer.addEventListener('focus', function() {
                        this.classList.add('focused');
                    });
                    
                    resizer.addEventListener('blur', function() {
                        this.classList.remove('focused');
                        if (selectedResizer === this) {
                            selectedResizer = null;
                            this.classList.remove('selected');
                        }
                    });
                });
                
                function startResize(resizer, clientX) {
                    isResizing = true;
                    currentResizer = resizer;
                    startX = clientX;
                    
                    const th = resizer.parentElement;
                    startWidth = parseInt(window.getComputedStyle(th).width, 10);
                    
                    resizer.classList.add('resizing');
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                }
                
                document.addEventListener('mousemove', function(e) {
                    if (!isResizing || !currentResizer) return;
                    
                    const diff = e.clientX - startX;
                    const newWidth = Math.max(50, startWidth + diff);
                    
                    const th = currentResizer.parentElement;
                    th.style.width = newWidth + 'px';
                    
                    e.preventDefault();
                });
                
                document.addEventListener('mouseup', function() {
                    if (isResizing && currentResizer) {
                        currentResizer.classList.remove('resizing');
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                        
                        isResizing = false;
                        currentResizer = null;
                    }
                });
                
                // Add keyboard shortcut help BEFORE the table
                const helpText = document.createElement('div');
                helpText.className = 'alert alert-info small mb-3';
                helpText.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="fas fa-keyboard me-2"></i>
                        <div>
                            <strong>Column Resize Instructions:</strong> 
                            Tab to column borders, use ←→ arrows (±10px), ↑↓ arrows (±5px), 
                            Home (min width), End (max width), Enter (select for fine control), Esc (deselect)
                        </div>
                    </div>
                `;
                
                const tableContainer = table.closest('.table-responsive');
                if (tableContainer && !tableContainer.previousElementSibling?.classList.contains('alert-info')) {
                    tableContainer.parentNode.insertBefore(helpText, tableContainer);
                }
            });
        }
        
        // Initialize tooltips for truncated table content
        function initTableTooltips() {
            const tables = document.querySelectorAll('#uniquePagesTable, #allUrlsTable');
            if (!tables.length) return;
            
            tables.forEach(table => {
                const cells = table.querySelectorAll('td:not(.dropdown-cell)'); // Exclude dropdown cells
                cells.forEach(cell => {
                    // Check if content is truncated
                    if (cell.scrollWidth > cell.clientWidth) {
                        const fullText = cell.textContent.trim();
                        if (fullText.length > 30) { // Only add tooltip for longer content
                            cell.setAttribute('title', fullText);
                            cell.style.cursor = 'help';
                        }
                    }
                });
                
                // Re-check tooltips when window is resized
                window.addEventListener('resize', () => {
                    setTimeout(() => {
                        cells.forEach(cell => {
                            if (cell.scrollWidth > cell.clientWidth) {
                                const fullText = cell.textContent.trim();
                                if (fullText.length > 30) {
                                    cell.setAttribute('title', fullText);
                                    cell.style.cursor = 'help';
                                }
                            } else {
                                cell.removeAttribute('title');
                                cell.style.cursor = '';
                            }
                        });
                    }, 100);
                });
            });
        }
        
        // Clear all filters on page load
        function clearAllFiltersOnLoad() {
            // Clear Unique Pages filters
            const uniqueFilter = document.getElementById('uniqueFilter');
            const uniqueFilterUser = document.getElementById('uniqueFilterUser');
            const uniqueFilterEnv = document.getElementById('uniqueFilterEnv');
            const uniqueFilterQa = document.getElementById('uniqueFilterQa');
            
            if (uniqueFilter) uniqueFilter.value = '';
            if (uniqueFilterUser) uniqueFilterUser.value = '';
            if (uniqueFilterEnv) uniqueFilterEnv.value = '';
            if (uniqueFilterQa) uniqueFilterQa.value = '';
            
            // Clear All URLs filters
            const allUrlsFilter = document.getElementById('allUrlsFilter');
            const allUrlsUniqueFilter = document.getElementById('allUrlsUniqueFilter');
            const allUrlsMappingFilter = document.getElementById('allUrlsMappingFilter');
            
            if (allUrlsFilter) allUrlsFilter.value = '';
            if (allUrlsUniqueFilter) allUrlsUniqueFilter.value = '';
            if (allUrlsMappingFilter) allUrlsMappingFilter.value = '';
        }
        
        // Initialize column resizing after DOM is loaded
        setTimeout(() => {
            initColumnResize();
            initTableTooltips();
        }, 500);
        
        const urlParams = new URLSearchParams(window.location.search);
        const focusAssignBtn = urlParams.get('focus_assign_btn');
        
        if (focusAssignBtn) {
            // First, ensure we're on the correct tab and subtab
            const pagesTab = document.querySelector('#pages-tab');
            const uniquePagesSubTab = document.querySelector('#unique-sub-tab');
            
            if (pagesTab && uniquePagesSubTab) {
                // Activate pages tab
                const pagesTabInstance = new bootstrap.Tab(pagesTab);
                pagesTabInstance.show();
                
                // Wait a bit for tab to show, then activate subtab
                setTimeout(() => {
                    const uniquePagesTabInstance = new bootstrap.Tab(uniquePagesSubTab);
                    uniquePagesTabInstance.show();
                    
                    // Wait a bit more for subtab to show, then focus on assign button
                    setTimeout(() => {
                        const assignBtn = document.querySelector(`button[data-bs-target="#assignPageModal-${focusAssignBtn}"]`);
                        if (assignBtn) {
                            assignBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            assignBtn.focus();
                            
                            // Add a temporary highlight effect
                            assignBtn.classList.add('btn-warning');
                            setTimeout(() => {
                                assignBtn.classList.remove('btn-warning');
                                assignBtn.classList.add('btn-outline-primary');
                            }, 2000);
                        }
                    }, 300);
                }, 300);
            }
            
            // Clean up URL parameter
            urlParams.delete('focus_assign_btn');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '') + window.location.hash;
            window.history.replaceState({}, '', newUrl);
        }
        
        // Let view_core.js handle all tab functionality
        // Just ensure proper initial state
        
        // Handle phase status updates
        $('.phase-status-update').on('change', function () {
            var phaseId = $(this).data('phase-id');
            var projectId = $(this).data('project-id');
            var newStatus = $(this).val();
            var $select = $(this);

            $.ajax({
                url: '<?php echo $baseDir; ?>/api/update_phase.php',
                type: 'POST',
                data: {
                    phase_id: phaseId,
                    project_id: projectId,
                    field: 'status',
                    value: newStatus
                },
                success: function (response) {
                    if (response.success) {
                        var $row = $select.closest('tr');
                        $row.addClass('table-success');
                        setTimeout(function () {
                            $row.removeClass('table-success');
                        }, 2000);
                        if (typeof showToast === 'function') showToast('Phase status updated', 'success');
                    } else {
                        if (typeof showToast === 'function') showToast('Failed to update phase status: ' + (response.message || 'Unknown error'), 'danger');
                        $select.val($select.data('original-value') || 'not_started');
                    }
                },
                error: function (xhr, status, error) {
                    if (typeof showToast === 'function') showToast('Error updating phase status: ' + error, 'danger');
                    $select.val($select.data('original-value') || 'not_started');
                }
            });
        });

        // Store original values for phase status selects
        $('.phase-status-update').each(function () {
            $(this).data('original-value', $(this).val());
        });

        // Handle page status updates
        $('.page-status-update').on('change', function () {
            var pageId = $(this).data('page-id');
            var projectId = $(this).data('project-id');
            var newStatus = $(this).val();
            var $select = $(this);

            $.ajax({
                url: '<?php echo $baseDir; ?>/api/update_page_status.php',
                type: 'POST',
                data: {
                    page_id: pageId,
                    project_id: projectId,
                    status: newStatus
                },
                success: function (response) {
                    if (response.success) {
                        var $row = $select.closest('tr');
                        $row.addClass('table-success');
                        setTimeout(function () {
                            $row.removeClass('table-success');
                        }, 2000);
                        if (typeof showToast === 'function') showToast('Page status updated', 'success');
                    } else {
                        if (typeof showToast === 'function') showToast('Failed to update page status: ' + (response.message || 'Unknown error'), 'danger');
                        $select.val($select.data('original-value') || 'not_started');
                    }
                },
                error: function (xhr, status, error) {
                    if (typeof showToast === 'function') showToast('Error updating page status: ' + error, 'danger');
                    $select.val($select.data('original-value') || 'not_started');
                }
            });
        });

        // Handle environment status updates
        $('.env-status-update').on('change', function () {
            var pageId = $(this).data('page-id');
            var envId = $(this).data('env-id');
            var testerType = $(this).data('tester-type');
            var newStatus = $(this).val();
            var $select = $(this);

            $.ajax({
                url: '<?php echo $baseDir; ?>/api/update_env_status.php',
                type: 'POST',
                data: {
                    page_id: pageId,
                    env_id: envId,
                    tester_type: testerType,
                    status: newStatus
                },
                success: function (response) {
                    if (response.success) {
                        var $row = $select.closest('tr');
                        $row.addClass('table-success');
                        setTimeout(function () {
                            $row.removeClass('table-success');
                        }, 2000);
                        if (typeof showToast === 'function') showToast('Status updated', 'success');
                    } else {
                        if (typeof showToast === 'function') showToast('Failed to update status: ' + (response.message || 'Unknown error'), 'danger');
                        $select.val($select.data('original-value') || $select.find('option:first').val());
                    }
                },
                error: function (xhr, status, error) {
                    if (typeof showToast === 'function') showToast('Error updating status: ' + error, 'danger');
                    $select.val($select.data('original-value') || $select.find('option:first').val());
                }
            });
        });

        // Store original values for environment status selects
        $('.env-status-update').each(function () {
            $(this).data('original-value', $(this).val());
        });

        // Handle page expand/collapse functionality
        $('.page-toggle-btn').on('click', function () {
            var $button = $(this);
            var $icon = $button.find('.toggle-icon');
            var $collapse = $($(this).data('bs-target'));

            $collapse.on('show.bs.collapse', function () {
                $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                $button.attr('title', 'Collapse Details');
            });

            $collapse.on('hide.bs.collapse', function () {
                $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                $button.attr('title', 'Expand Details');
            });
        });

        // Handle Edit Phase modal
        $('.edit-phase-btn').on('click', function () {
            var phaseId = $(this).data('phase-id');
            var phaseName = $(this).data('phase-name');
            var startDate = $(this).data('start-date');
            var endDate = $(this).data('end-date');
            var plannedHours = $(this).data('planned-hours');
            var status = $(this).data('status');
            
            $('#edit_phase_id').val(phaseId);
            $('#edit_phase_name').val(phaseName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
            $('#edit_start_date').val(startDate || '');
            $('#edit_end_date').val(endDate || '');
            $('#edit_planned_hours').val(plannedHours || '');
            $('#edit_status').val(status || 'not_started');
        });

        // Add date validation for Edit Phase modal
        $('#edit_start_date, #edit_end_date').on('change', function() {
            var startDate = $('#edit_start_date').val();
            var endDate = $('#edit_end_date').val();
            
            if (startDate && endDate) {
                var start = new Date(startDate);
                var end = new Date(endDate);
                
                if (end < start) {
                    if (typeof showToast === 'function') {
                        showToast('End date cannot be before start date', 'danger');
                    } else {
                        alert('End date cannot be before start date');
                    }
                    // Reset the field that was just changed
                    if ($(this).attr('id') === 'edit_end_date') {
                        $('#edit_end_date').val('');
                    } else {
                        $('#edit_start_date').val('');
                    }
                }
            }
        });

        // Set min attribute on end date when start date changes
        $('#edit_start_date').on('change', function() {
            var startDate = $(this).val();
            if (startDate) {
                $('#edit_end_date').attr('min', startDate);
            } else {
                $('#edit_end_date').removeAttr('min');
            }
        });

        // Handle asset type toggle in Add Asset modal
        $('input[name="asset_type"]').on('change', function () {
            var assetType = $(this).val();
            $('#link_fields').hide();
            $('#file_fields').hide();
            $('#text_fields').hide();
            
            if (assetType === 'link') {
                $('#link_fields').show();
                $('#main_url').prop('required', true);
                $('#asset_file').prop('required', false);
            } else if (assetType === 'file') {
                $('#file_fields').show();
                $('#main_url').prop('required', false);
                $('#asset_file').prop('required', true);
            } else if (assetType === 'text') {
                $('#text_fields').show();
                $('#main_url').prop('required', false);
                $('#asset_file').prop('required', false);
                
                // Initialize Summernote for text content if not already initialized
                if (!$('#text_content_editor').data('summernote')) {
                    $('#text_content_editor').summernote({
                        height: 200,
                        toolbar: [
                            ['style', ['style']],
                            ['font', ['bold', 'italic', 'underline', 'clear']],
                            ['para', ['ul', 'ol', 'paragraph']],
                            ['insert', ['link']],
                            ['view', ['codeview']]
                        ]
                    });
                }
            }
        });

        // Handle View Text Content modal
        $('#viewTextModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var title = button.data('title');
            var content = button.data('content');
            
            $('#viewTextModalTitle').text(title);
            $('#viewTextModalContent').html(content);
        });

        // Handle chat widget
        var $chatLauncher = $('#chatLauncher');
        var $chatWidget = $('#projectChatWidget');
        var $chatClose = $('#chatWidgetClose');
        var $chatFullscreen = $('#chatWidgetFullscreen');

        function openChatWidget() {
            $chatWidget.addClass('open');
            $chatLauncher.hide();
        }

        function closeChatWidget() {
            $chatWidget.removeClass('open');
            $chatLauncher.show();
        }

        $chatLauncher.on('click', function () { openChatWidget(); });
        $chatClose.on('click', function () { closeChatWidget(); });
        $chatFullscreen.on('click', function () {
            window.location.href = '<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo $projectId; ?>';
        });

        // Initialize production hours when tab is shown
        var productionHoursTab = document.getElementById('production-hours-tab');
        if (productionHoursTab) {
            productionHoursTab.addEventListener('shown.bs.tab', function () {
                // Ensure tab pane is visible
                var pane = document.getElementById('production-hours');
                if (pane) {
                    pane.classList.add('show', 'active');
                }
                if (typeof window.initProductionHours === 'function') {
                    window.initProductionHours();
                }
            });
            
            // Also add click handler to ensure proper activation
            productionHoursTab.addEventListener('click', function() {
                setTimeout(function() {
                    var pane = document.getElementById('production-hours');
                    if (pane && pane.classList.contains('active')) {
                        if (typeof window.initProductionHours === 'function') {
                            window.initProductionHours();
                        }
                    }
                }, 100);
            });
        }

        // Check if production hours tab is already active on page load
        setTimeout(function() {
            var productionHoursPane = document.getElementById('production-hours');
            if (productionHoursPane && productionHoursPane.classList.contains('active')) {
                if (typeof window.initProductionHours === 'function') {
                    window.initProductionHours();
                }
            }
        }, 500);
    });
</script>
<?php
    $viewJsBase = __DIR__ . '/js/';
    $viewJsVersion = function ($file) use ($viewJsBase) {
        $path = $viewJsBase . $file;
        return file_exists($path) ? filemtime($path) : time();
    };
?>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_core.js?v=<?php echo $viewJsVersion('view_core.js'); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_pages.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_feedback.js?v=<?php echo $viewJsVersion('view_feedback.js'); ?>"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_production.js?v=<?php echo $viewJsVersion('view_production.js'); ?>"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
