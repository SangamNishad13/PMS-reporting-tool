<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin', 'client']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['project_id'] ?? 0);
$pageId = (int)($_GET['page_id'] ?? 0);

if (!$projectId || !$pageId) {
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

// Get page details
$pageStmt = $db->prepare("SELECT * FROM project_pages WHERE id = ? AND project_id = ?");
$pageStmt->execute([$pageId, $projectId]);
$page = $pageStmt->fetch();

if (!$page) {
    $_SESSION['error'] = 'Page not found.';
    header('Location: ' . $baseDir . '/modules/projects/issues_pages.php?project_id=' . $projectId);
    exit;
}

// Fetch project users
$projectUsersStmt = $db->prepare("
    SELECT DISTINCT u.id, u.full_name, u.username, u.role
    FROM user_assignments ua 
    JOIN users u ON ua.user_id = u.id 
    WHERE ua.project_id = ? 
      AND u.is_active = 1
      AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    UNION
    SELECT u.id, u.full_name, u.username, u.role
    FROM users u
    WHERE u.is_active = 1 AND u.role IN ('admin', 'super_admin')
    ORDER BY full_name
");
$projectUsersStmt->execute([$projectId]);
$projectUsers = $projectUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch QA statuses
$qaStatusesStmt = $db->query("SELECT id, status_key, status_label, badge_color FROM qa_status_master WHERE is_active = 1 ORDER BY display_order ASC, status_label ASC");
$qaStatuses = $qaStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Issue statuses from issue_statuses table
$issueStatusesStmt = $db->query("SELECT id, name, color, category FROM issue_statuses ORDER BY name ASC");
$issueStatuses = $issueStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch issue metadata fields - actual columns are: field_key, field_label, options_json
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

// Pre-fetch project pages with URLs
$pagesStmt = $db->prepare("SELECT id, page_name, url FROM project_pages WHERE project_id = ? ORDER BY page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get page metadata with correct issues count
$issuePageSummary = [];
try {
    $issuePageStmt = $db->prepare("
        SELECT 
            pp.id,
            pp.page_name,
            (SELECT GROUP_CONCAT(DISTINCT te.name SEPARATOR ', ') FROM page_environments pe2 JOIN testing_environments te ON pe2.environment_id = te.id WHERE pe2.page_id = pp.id) AS envs,
            (SELECT GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') FROM users u JOIN page_environments pe3 ON u.id = pe3.at_tester_id OR u.id = pe3.ft_tester_id OR u.id = pe3.qa_id WHERE pe3.page_id = pp.id) AS testers,
            (SELECT COUNT(*) FROM issues i WHERE i.project_id = pp.project_id AND i.page_id = pp.id" . ($userRole === 'client' ? ' AND i.client_ready = 1' : '') . ") AS issues_count,
            (SELECT COALESCE(SUM(ptl.hours_spent), 0) FROM project_time_logs ptl WHERE ptl.page_id = pp.id) AS production_hours
        FROM project_pages pp
        WHERE pp.id = ?
    ");
    $issuePageStmt->execute([$pageId]);
    $issuePageSummary = $issuePageStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
    $issuePageSummary = [];
    error_log("Error loading page summary: " . $e->getMessage());
}

// Get page environments for status update
$pageEnvironments = [];
try {
    $envStmt = $db->prepare("
        SELECT 
            pe.page_id,
            pe.environment_id,
            pe.status,
            pe.qa_status,
            pe.at_tester_id,
            pe.ft_tester_id,
            pe.qa_id,
            te.name as env_name,
            at_user.full_name as at_tester_name,
            ft_user.full_name as ft_tester_name,
            qa_user.full_name as qa_name
        FROM page_environments pe
        JOIN testing_environments te ON pe.environment_id = te.id
        LEFT JOIN users at_user ON pe.at_tester_id = at_user.id
        LEFT JOIN users ft_user ON pe.ft_tester_id = ft_user.id
        LEFT JOIN users qa_user ON pe.qa_id = qa_user.id
        WHERE pe.page_id = ?
        ORDER BY te.name
    ");
    $envStmt->execute([$pageId]);
    $pageEnvironments = $envStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pageEnvironments = [];
    error_log("Error loading page environments: " . $e->getMessage());
}

// Get grouped URLs only for this selected page (its unique page + grouped URLs)
$groupedUrls = [];
try {
    $matchedUniqueId = null;
    $pageUrl = trim((string)($page['url'] ?? ''));
    $pageName = trim((string)($page['page_name'] ?? ''));
    $pageNumber = trim((string)($page['page_number'] ?? ''));

    // Resolve which grouped bucket this project page belongs to.
    $uniqueMatchStmt = $db->prepare("
        SELECT DISTINCT up.id
        FROM project_pages up
        LEFT JOIN grouped_urls gu
            ON gu.project_id = up.project_id
           AND gu.unique_page_id = up.id
        WHERE up.project_id = ?
          AND (
               (? <> '' AND (gu.url = ? OR gu.normalized_url = ? OR up.url = ?))
               OR (? <> '' AND up.page_name = ?)
               OR (? <> '' AND up.page_name = ?)
          )
        LIMIT 1
    ");
    $uniqueMatchStmt->execute([
        $projectId,
        $pageUrl, $pageUrl, $pageUrl, $pageUrl,
        $pageName, $pageName,
        $pageNumber, $pageNumber
    ]);
    $matchedUniqueId = (int)($uniqueMatchStmt->fetchColumn() ?: 0);

    if ($matchedUniqueId > 0) {
        $groupedStmt = $db->prepare("
            SELECT 
                gu.id,
                gu.url,
                gu.normalized_url,
                gu.unique_page_id,
                ? AS mapped_page_id
            FROM grouped_urls gu
            WHERE gu.project_id = ?
              AND gu.unique_page_id = ?
            ORDER BY gu.url
        ");
        $groupedStmt->execute([$pageId, $projectId, $matchedUniqueId]);
        $groupedUrls = $groupedStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fallback: if no grouped mapping exists, still show page URL for this page.
    if (empty($groupedUrls) && $pageUrl !== '') {
        $groupedUrls[] = [
            'id' => null,
            'url' => $pageUrl,
            'normalized_url' => $pageUrl,
            'unique_page_id' => $matchedUniqueId > 0 ? $matchedUniqueId : null,
            'mapped_page_id' => $pageId
        ];
    }
} catch (Exception $e) {
    $groupedUrls = [];
}

$pageTitle = 'Issues - ' . htmlspecialchars($page['page_name']) . ' - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
.issue-image-thumb { max-width: 100%; max-height: 220px; height: auto; object-fit: contain; border-radius: 8px; box-shadow: 0 6px 14px rgba(16, 24, 40, 0.15); cursor: zoom-in; transition: transform 0.2s ease; }
.issue-image-thumb:hover { transform: scale(1.02); }
.modal { z-index: 10550; }
.modal-backdrop { z-index: 10540; }
.select2-container--open .select2-dropdown { z-index: 10600; }
.select2-results__options { max-height: 250px !important; overflow-y: auto !important; }
/* Issue key column - prevent text wrapping */
#finalIssuesTable td:nth-child(2),
#finalIssuesTable th:nth-child(2) {
    white-space: nowrap;
    min-width: 120px;
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

/* ── Final Issues Table ─────────────────────────────────────── */
#finalIssuesBody tr td,
#finalIssuesBody tr th {
    font-size: 12px;
    vertical-align: middle;
    padding: 0.35rem 0.5rem;
}
/* Sticky checkbox + issue-key columns */
.final-issues-table-wrap {
    overflow-x: auto;
    position: relative;
    border-radius: 0 0 6px 6px;
}
.final-issues-table-wrap table {
    min-width: 900px;
}
.final-issues-table-wrap thead th {
    position: sticky;
    top: 0;
    z-index: 3;
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6c757d;
}
/* Sticky first two columns on final issues table */
.final-issues-table-wrap table th:nth-child(1),
.final-issues-table-wrap table td:nth-child(1) {
    position: sticky;
    left: 0;
    z-index: 4;
    background: #f8f9fa;
}
.final-issues-table-wrap table td:nth-child(1) {
    background: #fff;
    z-index: 2;
}
.final-issues-table-wrap table th:nth-child(2),
.final-issues-table-wrap table td:nth-child(2) {
    position: sticky;
    left: 38px;
    z-index: 4;
    background: #f8f9fa;
    border-right: 1px solid #dee2e6;
}
.final-issues-table-wrap table td:nth-child(2) {
    background: #fff;
    z-index: 2;
}
/* Scroll shadow indicator */
.final-issues-table-wrap::after {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 24px; height: 100%;
    background: linear-gradient(to right, transparent, rgba(0,0,0,0.04));
    pointer-events: none;
    border-radius: 0 6px 6px 0;
}

/* ── Needs Review Table ─────────────────────────────────────── */
.needs-review-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6c757d;
}
.needs-review-table {
    table-layout: fixed;
}
.needs-review-table th,
.needs-review-table td {
    vertical-align: top;
    padding: 0.4rem 0.45rem;
    font-size: 12px;
}
.needs-review-table tbody tr {
    height: 96px;
}
.needs-review-table tbody td {
    height: 96px;
    max-height: 96px;
    overflow: hidden;
}
/* Sticky first 3 columns on needs-review table */
.needs-review-wrap {
    overflow-x: auto;
    position: relative;
}
.needs-review-table th:nth-child(1),
.needs-review-table td:nth-child(1) {
    position: sticky;
    left: 0;
    z-index: 3;
    background: #f8f9fa;
}
.needs-review-table td:nth-child(1) { background: #fff; }
.needs-review-table th:nth-child(2),
.needs-review-table td:nth-child(2) {
    position: sticky;
    left: 36px;
    z-index: 3;
    background: #f8f9fa;
}
.needs-review-table td:nth-child(2) { background: #fff; }
.needs-review-table th:nth-child(3),
.needs-review-table td:nth-child(3) {
    position: sticky;
    left: 76px;
    z-index: 3;
    background: #f8f9fa;
    border-right: 2px solid #dee2e6;
}
.needs-review-table td:nth-child(3) { background: #fff; }

.resizable-table { table-layout: fixed; width: 100%; min-width: 1600px; }
.resizable-table th { position: relative; overflow: visible; text-overflow: ellipsis; white-space: nowrap; }
.col-resizer { position: absolute; right: 0; top: 0; width: 8px; height: 100%; cursor: col-resize; z-index: 999; background: transparent; border-right: 1px solid rgba(0, 0, 0, 0.2); }
.col-resizer:hover { border-right-color: #007bff; border-right-width: 2px; background: rgba(0, 123, 255, 0.1); }
.needs-review-row {
    cursor: pointer;
}
.needs-review-row:hover {
    background: #f8fbff;
}
.needs-review-cell-scroll {
    max-height: 72px;
    overflow: hidden;
    line-height: 1.25;
}
.needs-review-code-wrap {
    height: 72px;
    max-height: 72px;
    overflow: hidden;
}
.needs-review-truncate {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
    line-clamp: 4;
    overflow: hidden;
    max-height: 5em;
    white-space: pre-line;
    line-height: 1.25;
}
.needs-review-rich-text ul {
    margin: 4px 0 0 16px;
    padding-left: 12px;
}
.needs-review-rich-text li {
    margin: 0;
}
.needs-review-cell-scroll pre,
.needs-review-cell-scroll code {
    font-size: 11px;
    line-height: 1.25;
}
.needs-review-inline-code {
    display: block;
    white-space: pre-wrap;
    word-break: break-word;
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    padding: 6px 8px;
    margin-bottom: 6px;
}
.needs-review-code-wrap .needs-review-inline-code {
    margin-bottom: 4px;
}
.needs-review-cell-scroll pre {
    max-height: 62px;
    overflow: hidden;
    white-space: pre-wrap;
}
.needs-review-shot-stack {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    max-height: 78px;
    overflow: auto;
}
.needs-review-shot {
    width: 62px;
    height: 42px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #dbe2ea;
    cursor: zoom-in;
}
.needs-review-extra-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 22px;
    padding: 0 6px;
    border-radius: 999px;
    background: #eef2f7;
    color: #4a5568;
    font-size: 11px;
    font-weight: 600;
}
.needs-review-actions {
    white-space: nowrap;
}
.needs-review-issue-title {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    overflow: hidden;
}
.needs-review-issue-meta {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 1;
    line-clamp: 1;
    overflow: hidden;
}
.needs-review-preview-image {
    cursor: zoom-in;
}

/* Scroll hint badge */
.scroll-hint {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    color: #6c757d;
    background: #f1f3f5;
    border: 1px solid #dee2e6;
    border-radius: 20px;
    padding: 2px 8px;
    user-select: none;
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
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/issues_pages.php?project_id=<?php echo $projectId; ?>">Pages</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($page['page_name']); ?></li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- Compact Page Header -->
    <div class="card mb-2">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-0">
                        <i class="fas fa-file-alt text-primary me-2"></i>
                        <?php echo htmlspecialchars($page['page_name']); ?>
                        <span class="badge bg-primary-subtle text-primary ms-2"><?php echo htmlspecialchars($page['page_number'] ?? '-'); ?></span>
                    </h5>
                    <div class="small text-muted text-truncate" style="max-width: 500px;" title="<?php echo htmlspecialchars($page['url'] ?? '-'); ?>">
                        <?php echo htmlspecialchars($page['url'] ?? '-'); ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex gap-3 small">
                        <div>
                            <span class="text-muted">Issues:</span>
                            <span class="badge <?php echo ($issuePageSummary['issues_count'] ?? 0) > 0 ? 'bg-warning' : 'bg-secondary'; ?>">
                                <?php echo (int)($issuePageSummary['issues_count'] ?? 0); ?>
                            </span>
                        </div>
                        <?php if ($userRole !== 'client'): ?>
                        <div>
                            <span class="text-muted">Prod Hours:</span>
                            <strong><?php echo number_format((float)($issuePageSummary['production_hours'] ?? 0), 2); ?></strong>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($groupedUrls)): ?>
                        <div>
                            <button class="btn btn-xs btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#pageUrlsList">
                                <i class="fas fa-link me-1"></i><?php echo count($groupedUrls); ?> URLs
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-3 text-md-end">
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_all.php?project_id=<?php echo $projectId; ?>" class="btn btn-primary btn-sm me-1">
                        <i class="fas fa-list"></i> All Issues
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_common.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-primary btn-sm me-1">
                        <i class="fas fa-layer-group"></i> Common
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_pages.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($groupedUrls)): ?>
    <!-- Collapsible Grouped URLs -->
    <div class="collapse mb-2" id="pageUrlsList">
        <div class="card">
            <div class="card-body py-2">
                <div class="small">
                    <strong class="text-muted"><i class="fas fa-link me-1"></i>Grouped URLs (<?php echo count($groupedUrls); ?>):</strong>
                    <div class="mt-1">
                        <?php foreach ($groupedUrls as $idx => $url): ?>
                            <a href="<?php echo htmlspecialchars($url['url']); ?>" target="_blank" class="badge bg-light text-dark text-decoration-none me-1 mb-1">
                                <?php echo htmlspecialchars($url['url']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($_SESSION['role'] !== 'client'): ?>
    <!-- Testing Status Section -->
    <div class="card mb-2">
        <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
            <div>
                <strong><i class="fas fa-tasks me-2"></i>Testing Status</strong>
                <span class="small text-muted ms-2">Update testing progress for each tester type</span>
            </div>
            <?php if (empty($pageEnvironments)): ?>
            <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>#pages" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-plus me-1"></i> Assign Environments
            </a>
            <?php endif; ?>
        </div>
        <?php if (!empty($pageEnvironments)): ?>
        <div class="card-body p-2">
            <div class="row g-2">
                <!-- AT Tester Section -->
                <div class="col-md-4">
                    <div class="card border">
                        <div class="card-header py-1 bg-info-subtle">
                            <strong class="small"><i class="fas fa-mobile-alt me-1"></i>AT (Env - Status)</strong>
                        </div>
                        <div class="card-body p-2">
                            <?php 
                            $atAssignments = array_filter($pageEnvironments, function($env) {
                                return !empty($env['at_tester_id']);
                            });
                            ?>
                            <?php if (!empty($atAssignments)): ?>
                                <div class="small">
                                    <?php foreach ($atAssignments as $env): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                        <div>
                                            <div><strong><?php echo htmlspecialchars($env['env_name']); ?></strong></div>
                                            <div class="text-muted" style="font-size: 0.85em;"><?php echo htmlspecialchars($env['at_tester_name']); ?></div>
                                        </div>
                                        <div>
                                            <?php if (in_array($userRole, ['admin', 'super_admin', 'project_lead']) || $env['at_tester_id'] == $userId): ?>
                                            <select class="form-select form-select-sm env-status-update" 
                                                    data-status-type="testing"
                                                    data-page-id="<?php echo $pageId; ?>" 
                                                    data-env-id="<?php echo $env['environment_id']; ?>"
                                                    style="font-size: 0.8em; min-width: 110px;">
                                                <option value="not_started" <?php echo ($env['status'] ?? '') == 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                                <option value="in_progress" <?php echo ($env['status'] ?? '') == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="completed" <?php echo ($env['status'] ?? '') == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="on_hold" <?php echo ($env['status'] ?? '') == 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                                <option value="needs_review" <?php echo ($env['status'] ?? '') == 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                                            </select>
                                            <?php else: ?>
                                            <span class="badge bg-secondary" style="font-size: 0.75em;"><?php echo htmlspecialchars(formatTestStatusLabel($env['status'] ?? 'not_started')); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-3 small">
                                    <i class="fas fa-user-slash mb-1"></i>
                                    <div>No AT assignment</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- FT Tester Section -->
                <div class="col-md-4">
                    <div class="card border">
                        <div class="card-header py-1 bg-success-subtle">
                            <strong class="small"><i class="fas fa-desktop me-1"></i>FT (Env - Status)</strong>
                        </div>
                        <div class="card-body p-2">
                            <?php 
                            $ftAssignments = array_filter($pageEnvironments, function($env) {
                                return !empty($env['ft_tester_id']);
                            });
                            ?>
                            <?php if (!empty($ftAssignments)): ?>
                                <div class="small">
                                    <?php foreach ($ftAssignments as $env): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                        <div>
                                            <div><strong><?php echo htmlspecialchars($env['env_name']); ?></strong></div>
                                            <div class="text-muted" style="font-size: 0.85em;"><?php echo htmlspecialchars($env['ft_tester_name']); ?></div>
                                        </div>
                                        <div>
                                            <?php if (in_array($userRole, ['admin', 'super_admin', 'project_lead']) || $env['ft_tester_id'] == $userId): ?>
                                            <select class="form-select form-select-sm env-status-update" 
                                                    data-status-type="testing"
                                                    data-page-id="<?php echo $pageId; ?>" 
                                                    data-env-id="<?php echo $env['environment_id']; ?>"
                                                    style="font-size: 0.8em; min-width: 110px;">
                                                <option value="not_started" <?php echo ($env['status'] ?? '') == 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                                <option value="in_progress" <?php echo ($env['status'] ?? '') == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="completed" <?php echo ($env['status'] ?? '') == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="on_hold" <?php echo ($env['status'] ?? '') == 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                                <option value="needs_review" <?php echo ($env['status'] ?? '') == 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                                            </select>
                                            <?php else: ?>
                                            <span class="badge bg-secondary" style="font-size: 0.75em;"><?php echo htmlspecialchars(formatTestStatusLabel($env['status'] ?? 'not_started')); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-3 small">
                                    <i class="fas fa-user-slash mb-1"></i>
                                    <div>No FT assignment</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- QA Section -->
                <div class="col-md-4">
                    <div class="card border">
                        <div class="card-header py-1 bg-warning-subtle">
                            <strong class="small"><i class="fas fa-check-circle me-1"></i>QA (Env - Status)</strong>
                        </div>
                        <div class="card-body p-2">
                            <?php 
                            $qaAssignments = array_filter($pageEnvironments, function($env) {
                                return !empty($env['qa_id']);
                            });
                            ?>
                            <?php if (!empty($qaAssignments)): ?>
                                <div class="small">
                                    <?php foreach ($qaAssignments as $env): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                        <div>
                                            <div><strong><?php echo htmlspecialchars($env['env_name']); ?></strong></div>
                                            <div class="text-muted" style="font-size: 0.85em;"><?php echo htmlspecialchars($env['qa_name']); ?></div>
                                        </div>
                                        <div>
                                            <?php if (in_array($userRole, ['admin', 'super_admin', 'project_lead', 'qa']) || $env['qa_id'] == $userId): ?>
                                            <?php
                                                $qaStatusRaw = strtolower(trim((string)($env['qa_status'] ?? 'not_started')));
                                                $qaStatusMap = [
                                                    'pending' => 'not_started',
                                                    'na' => 'on_hold',
                                                    'pass' => 'completed',
                                                    'fail' => 'needs_review'
                                                ];
                                                $qaStatus = $qaStatusMap[$qaStatusRaw] ?? $qaStatusRaw;
                                                if (!in_array($qaStatus, ['not_started', 'in_progress', 'completed', 'on_hold', 'needs_review'], true)) {
                                                    $qaStatus = 'not_started';
                                                }
                                            ?>
                                            <select class="form-select form-select-sm env-status-update" 
                                                    data-status-type="qa"
                                                    data-page-id="<?php echo $pageId; ?>" 
                                                    data-env-id="<?php echo $env['environment_id']; ?>"
                                                    style="font-size: 0.8em; min-width: 110px;">
                                                <option value="not_started" <?php echo $qaStatus === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                                <option value="in_progress" <?php echo $qaStatus === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="completed" <?php echo $qaStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="on_hold" <?php echo $qaStatus === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                                <option value="needs_review" <?php echo $qaStatus === 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                                            </select>
                                            <?php else: ?>
                                            <span class="badge bg-secondary" style="font-size: 0.75em;"><?php echo htmlspecialchars(formatQAStatusLabel($env['qa_status'] ?? 'not_started')); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-3 small">
                                    <i class="fas fa-user-slash mb-1"></i>
                                    <div>No QA assignment</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card-body text-center py-4">
            <i class="fas fa-info-circle fa-2x text-muted mb-2"></i>
            <p class="text-muted mb-2">No environments assigned to this page yet.</p>
            <p class="small text-muted">Go to the Pages tab in project view to assign environments and testers.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Issues Table Card -->
    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <div>
                <strong>Page Issues</strong>
                <span class="small text-muted ms-2">Final issues</span>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" id="pageIssuesRefreshBtn" title="Refresh issues">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <?php if ($_SESSION['role'] !== 'client'): ?>
                <button class="btn btn-primary btn-sm" id="issueAddFinalBtn">
                    <i class="fas fa-plus me-1"></i> Add Issue
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <ul class="nav nav-tabs px-3 pt-2 mb-0" id="pageIssueTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active py-2" id="final-issues-tab" data-bs-toggle="tab" data-bs-target="#final_issues_tab" type="button">Final Issues <span class="badge bg-secondary ms-1" id="finalIssuesCountBadge">0</span></button>
                </li>
                <?php if ($_SESSION['role'] !== 'client'): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link py-2" id="needs-review-tab" data-bs-toggle="tab" data-bs-target="#needs_review_tab" type="button">Needs Review <span class="badge bg-secondary ms-1" id="needsReviewCountBadge">0</span></button>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="final_issues_tab" role="tabpanel">
                    <?php if ($_SESSION['role'] !== 'client'): ?>
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                        <div class="d-flex align-items-center gap-2">
                            <span class="small text-muted">Issues for the final report</span>
                            <span class="scroll-hint"><i class="fas fa-arrows-left-right"></i> scroll</span>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-success me-1" id="finalMarkClientReadyBtn" disabled>
                                <i class="fas fa-check"></i> Mark Client Ready
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" id="finalDeleteSelected" disabled>Delete Selected</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="final-issues-table-wrap">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <?php if ($_SESSION['role'] !== 'client'): ?>
                                    <th style="width:30px;"><input type="checkbox" id="finalSelectAll"></th>
                                    <?php endif; ?>
                                    <th style="width:110px;">Issue Key</th>
                                    <th style="min-width:200px;">Issue Title</th>
                                    <th style="width:90px;">Severity</th>
                                    <th style="width:90px;">Priority</th>
                                    <th style="width:110px;">Status</th>
                                    <?php if ($_SESSION['role'] !== 'client'): ?>
                                    <th style="width:120px;">QA Status</th>
                                    <th style="width:110px;">Reporter</th>
                                    <th style="width:110px;">QA Name</th>
                                    <th style="width:95px;">Client Ready</th>
                                    <?php endif; ?>
                                    <th style="width:90px;">Pages</th>
                                    <?php if ($_SESSION['role'] !== 'client'): ?>
                                    <th style="width:110px;">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="finalIssuesBody">
                                <tr><td colspan="11" class="text-muted text-center py-4">
                                    <i class="fas fa-inbox fa-2x mb-2 opacity-25"></i>
                                    <div>No issues found for this page.</div>
                                    <div class="small mt-1">Click "Add Issue" to create one.</div>
                                </td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if ($_SESSION['role'] !== 'client'): ?>
                <div class="tab-pane fade" id="needs_review_tab" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                        <div class="d-flex align-items-center gap-2">
                            <span class="small text-muted">Automated tool findings for manual verification</span>
                            <span class="scroll-hint"><i class="fas fa-arrows-left-right"></i> scroll</span>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-danger" id="needsReviewDeleteSelectedBtn" type="button" disabled>
                                <i class="fas fa-trash me-1"></i> Delete Selected
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" id="needsReviewRefreshBtn" type="button">
                                <i class="fas fa-rotate me-1"></i> Refresh
                            </button>
                            <button class="btn btn-sm btn-outline-primary" id="needsReviewRunScanBtn" type="button">
                                <i class="fas fa-universal-access me-1"></i> Run Scan
                            </button>
                        </div>
                    </div>
                    <div class="px-3 py-2 border-bottom bg-white d-none" id="needsReviewScanProgressWrap">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted" id="needsReviewScanProgressText">Scanning...</span>
                            <span class="small fw-semibold" id="needsReviewScanProgressPercent">0%</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="needsReviewScanProgressBar" role="progressbar" style="width: 0%;"></div>
                        </div>
                    </div>
                    <div class="needs-review-wrap">
                        <table class="table table-sm table-hover align-middle mb-0 needs-review-table resizable-table" id="needsReviewResizableTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:36px;"><input type="checkbox" id="needsReviewSelectAll"><div class="col-resizer"></div></th>
                                    <th style="width:40px;">#<div class="col-resizer"></div></th>
                                    <th style="width:190px;">Issue<div class="col-resizer"></div></th>
                                    <th style="width:240px;">URLs<div class="col-resizer"></div></th>
                                    <th style="width:90px;">Severity<div class="col-resizer"></div></th>
                                    <th style="width:110px;">WCAG SC<div class="col-resizer"></div></th>
                                    <th style="width:170px;">WCAG Name<div class="col-resizer"></div></th>
                                    <th style="width:90px;">WCAG Level<div class="col-resizer"></div></th>
                                    <th style="width:320px;">Actual Results<div class="col-resizer"></div></th>
                                    <th style="width:260px;">Incorrect Code<div class="col-resizer"></div></th>
                                    <th style="width:150px;">Screenshots<div class="col-resizer"></div></th>
                                    <th style="width:260px;">Recommendation<div class="col-resizer"></div></th>
                                    <th style="width:260px;">Correct Code<div class="col-resizer"></div></th>
                                    <th style="width:150px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="needsReviewBody">
                                <tr><td colspan="14" class="text-muted text-center py-4">No automated findings yet. Run scan first.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="needsReviewPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Needs Review Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="needsReviewPreviewBody"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="needsReviewConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="needsReviewConfirmTitle">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="needsReviewConfirmMessage">Are you sure?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="needsReviewConfirmCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="needsReviewConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="needsReviewScanUrlModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select URLs For Auto Scan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="needsReviewScanSelectAll" checked>
                    <label class="form-check-label fw-semibold" for="needsReviewScanSelectAll">Select all URLs</label>
                </div>
                <div id="needsReviewScanUrlList" class="border rounded p-2" style="max-height: 360px; overflow:auto;"></div>
                <hr>
                <div class="small fw-semibold mb-2">Post-login URL Helper</div>
                <div class="input-group input-group-sm mb-2">
                    <input type="url" class="form-control" id="needsReviewCustomScanUrlInput" placeholder="https://example.com/protected/page">
                    <button type="button" class="btn btn-outline-secondary" id="needsReviewOpenCustomUrlBtn">Open</button>
                    <button type="button" class="btn btn-outline-primary" id="needsReviewAddCustomUrlBtn">Add For Scan</button>
                </div>
                <div id="needsReviewCustomUrlList" class="small text-muted"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="needsReviewRunSelectedScanBtn">Run Selected Scan</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/issues_modals.php'; ?>

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
        currentPageUrl: <?php echo json_encode($page['url'] ?? ''); ?>,
        projectPages: <?php echo json_encode($projectPages ?? []); ?>,
        groupedUrls: <?php echo json_encode($groupedUrls ?? []); ?>,
        projectUsers: <?php echo json_encode($projectUsers ?? []); ?>,
        pageEnvironments: <?php echo json_encode($pageEnvironments ?? []); ?>,
        qaStatuses: <?php echo json_encode($qaStatuses ?? []); ?>,
        issueStatuses: <?php echo json_encode($issueStatuses ?? []); ?>
    };
    
    // Define issueMetadataFields globally for view_issues.js
    window.issueMetadataFields = <?php echo json_encode($metadataFields ?? []); ?>;
</script>

<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js?v=20260210180000"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js?v=<?php echo time(); ?>"></script>
<script>
document.addEventListener('pms:issues-changed', function (e) {
    var detail = e.detail || {};
    if (detail.source === 'internal') {
        // view_issues.js already updated in-memory data and called renderAll()
        // Just reload common issues from API to stay in sync
        if (typeof window.loadCommonIssues === 'function') {
            window.loadCommonIssues({ silent: true });
        }
    } else {
        // External change (another tab/page) — reload everything from API
        if (typeof window.loadFinalIssues === 'function') {
            window.loadFinalIssues(<?php echo (int)$pageId; ?>, { silent: true });
        }
        if (typeof window.loadCommonIssues === 'function') {
            window.loadCommonIssues({ silent: true });
        }
    }
});

document.getElementById('pageIssuesRefreshBtn').addEventListener('click', function () {
    if (typeof window.loadFinalIssues === 'function') {
        window.loadFinalIssues(<?php echo (int)$pageId; ?>);
    }
});
</script>

<script>
// Automated findings -> Needs Review tab
(function () {
    var baseDir = <?php echo json_encode($baseDir); ?>;
    var projectId = <?php echo (int)$projectId; ?>;
    var pageId = <?php echo (int)$pageId; ?>;
    var reopenNeedsReviewPreviewOnImageClose = false;
    var scanProgressTimer = null;

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function toArray(v) {
        if (Array.isArray(v)) return v;
        if (!v) return [];
        return [v];
    }

    function getScreenshotUrls(finding) {
        return toArray(finding.screenshots).map(function (x) { return String(x || '').trim(); }).filter(Boolean);
    }

    function getFindingUrls(finding) {
        var out = [];
        if (Array.isArray(finding.scan_urls)) {
            out = out.concat(finding.scan_urls);
        } else if (finding.scan_url) {
            out.push(finding.scan_url);
        }
        var actual = String((finding && finding.actual_results) || '');
        var matches = actual.match(/(?:^|\n)\s*URL:\s*([^\s]+)\s*/gi) || [];
        matches.forEach(function (m) {
            var mm = String(m).match(/URL:\s*([^\s]+)/i);
            if (mm && mm[1]) out.push(mm[1]);
        });
        out = out.map(function (u) { return String(u || '').trim(); }).filter(Boolean);
        var seen = {};
        return out.filter(function (u) {
            var k = u.toLowerCase();
            if (seen[k]) return false;
            seen[k] = true;
            return true;
        });
    }

    function getIncorrectCodeSnippets(finding) {
        var raw = String((finding && finding.incorrect_code) || '').trim();
        if (!raw) return [];
        return raw
            .split(/\n\s*\n+/)
            .map(function (s) { return String(s || '').trim(); })
            .filter(Boolean);
    }

    function renderIncorrectCodeBlocks(finding, extraClass) {
        var cls = String(extraClass || '').trim();
        var fullClass = ('needs-review-inline-code ' + cls).trim();
        var snippets = getIncorrectCodeSnippets(finding);
        if (!snippets.length) return '<code class="' + esc(fullClass) + '">-</code>';
        return '<code class="' + esc(fullClass) + '">' + esc(snippets.join('\n\n')) + '</code>';
    }

    function renderRecommendationHtml(text, emptyFallback) {
        var raw = String(text || '').trim();
        if (!raw) return esc(String(emptyFallback || '-'));
        var lines = raw.split(/\r?\n/).map(function (l) { return String(l || '').trim(); }).filter(Boolean);
        if (!lines.length) return esc(String(emptyFallback || '-'));

        function renderTextWithCodeTags(inputText) {
            var txt = String(inputText || '');
            var tokens = txt.split(/(<\/?[a-z][^>]*>)/gi);
            return tokens.map(function (t) {
                if (/^<\/?[a-z][^>]*>$/i.test(t)) {
                    return '<code>' + esc(t) + '</code>';
                }
                var s = esc(t);
                // Wrap complete attribute assignments first, then standalone aria/role tokens.
                s = s.replace(/\b[a-zA-Z_:-]+\s*=\s*&quot;[^&]+&quot;/g, function (m) { return '<code>' + m + '</code>'; });
                s = s.replace(/\b[a-zA-Z_:-]+\s*=\s*&#39;[^&]+&#39;/g, function (m) { return '<code>' + m + '</code>'; });
                var parts = s.split(/(<code>[\s\S]*?<\/code>)/i);
                s = parts.map(function (part) {
                    if (/^<code>[\s\S]*<\/code>$/i.test(part)) return part;
                    var out = part;
                    out = out.replace(/\baria-[a-z-]+\b/gi, function (m) { return '<code>' + m + '</code>'; });
                    out = out.replace(/\brole\b/gi, function (m) { return '<code>' + m + '</code>'; });
                    return out;
                }).join('');
                return s;
            }).join('');
        }

        var heading = '';
        var subHeading = '';
        var paragraphs = [];
        var bullets = [];
        var seenRecLine = {};
        function pushUnique(target, line) {
            var t = String(line || '').trim();
            if (!t) return;
            var key = t.toLowerCase();
            if (seenRecLine[key]) return;
            seenRecLine[key] = true;
            target.push(t);
        }
        lines.forEach(function (line) {
            var cleaned = String(line || '').trim();
            var isBullet = /^\-\s+/.test(cleaned);
            if (isBullet) cleaned = cleaned.replace(/^\-\s+/, '').trim();
            if (/^apply the following changes:?$/i.test(cleaned)) {
                subHeading = line;
            } else if (!heading) {
                heading = cleaned;
            } else if (isBullet) {
                pushUnique(bullets, cleaned);
            } else {
                pushUnique(paragraphs, cleaned);
            }
        });

        if (!bullets.length && !paragraphs.length && !subHeading) {
            return renderTextWithCodeTags(raw).replace(/\n/g, '<br>');
        }

        var html = '';
        if (heading) {
            html += '<div class="mb-1">' + renderTextWithCodeTags(heading) + '</div>';
        }
        if (subHeading) {
            html += '<div class="mt-2 mb-1"><strong>' + renderTextWithCodeTags(subHeading.replace(/^\-\s+/, '').trim()) + '</strong></div>';
        }
        if (paragraphs.length) {
            html += paragraphs.map(function (p) {
                return '<div class="mb-2">' + renderTextWithCodeTags(p) + '</div>';
            }).join('');
        }
        if (bullets.length) {
            // Keep recommendation readable as paragraph blocks instead of dense bullets.
            html += bullets.map(function (b) {
                return '<div class="mb-2">' + renderTextWithCodeTags(b) + '</div>';
            }).join('');
        }
        return html;
    }

    function renderActualResultsHtml(text, emptyFallback) {
        var raw = String(text || '').trim();
        if (!raw) return esc(String(emptyFallback || '-'));
        var lines = raw.split(/\r?\n/);
        var parts = [];
        var pendingBullets = [];

        function renderTextWithCodeTags(inputText) {
            var txt = String(inputText || '');
            var tokens = txt.split(/(<\/?[a-z][^>]*>)/gi);
            return tokens.map(function (t) {
                if (/^<\/?[a-z][^>]*>$/i.test(t)) {
                    return '<code>' + esc(t) + '</code>';
                }
                var s = esc(t);
                s = s.replace(/\b[a-zA-Z_:-]+\s*=\s*&quot;[^&]+&quot;/g, function (m) { return '<code>' + m + '</code>'; });
                s = s.replace(/\b[a-zA-Z_:-]+\s*=\s*&#39;[^&]+&#39;/g, function (m) { return '<code>' + m + '</code>'; });
                var parts = s.split(/(<code>[\s\S]*?<\/code>)/i);
                s = parts.map(function (part) {
                    if (/^<code>[\s\S]*<\/code>$/i.test(part)) return part;
                    var out = part;
                    out = out.replace(/\baria-[a-z-]+\b/gi, function (m) { return '<code>' + m + '</code>'; });
                    out = out.replace(/\brole\b/gi, function (m) { return '<code>' + m + '</code>'; });
                    return out;
                }).join('');
                return s;
            }).join('');
        }

        function flushBullets() {
            if (!pendingBullets.length) return;
            parts.push('<ul class="mb-1 ps-3">' + pendingBullets.map(function (b) {
                return '<li>' + renderTextWithCodeTags(b) + '</li>';
            }).join('') + '</ul>');
            pendingBullets = [];
        }

        lines.forEach(function (line) {
            var t = String(line || '').trim();
            if (!t) {
                flushBullets();
                parts.push('<div style="height:8px;"></div>');
                return;
            }
            if (/^\-\s+/.test(t)) {
                pendingBullets.push(t.replace(/^\-\s+/, '').trim());
                return;
            }
            // Keep URL sections visually separated for easier scanning.
            if (/^URL:\s+/i.test(t) && parts.length > 0) {
                flushBullets();
                parts.push('<div style="height:10px;"></div>');
            }
            flushBullets();
            parts.push('<div>' + renderTextWithCodeTags(t) + '</div>');
        });
        flushBullets();
        return parts.join('');
    }

    function confirmNeedsReviewAction(opts) {
        var cfg = opts || {};
        var title = String(cfg.title || 'Confirm Action');
        var message = String(cfg.message || 'Are you sure?');
        var confirmText = String(cfg.confirmText || 'Confirm');
        var confirmClass = String(cfg.confirmClass || 'btn-danger');
        var modalEl = document.getElementById('needsReviewConfirmModal');
        var titleEl = document.getElementById('needsReviewConfirmTitle');
        var messageEl = document.getElementById('needsReviewConfirmMessage');
        var confirmBtn = document.getElementById('needsReviewConfirmBtn');

        if (!(modalEl && titleEl && messageEl && confirmBtn && window.bootstrap && bootstrap.Modal)) {
            return Promise.resolve(window.confirm(message));
        }

        titleEl.textContent = title;
        messageEl.textContent = message;
        confirmBtn.textContent = confirmText;
        confirmBtn.className = 'btn ' + confirmClass;

        return new Promise(function (resolve) {
            var done = false;
            var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

            function cleanup() {
                confirmBtn.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
            }

            function finish(value) {
                if (done) return;
                done = true;
                cleanup();
                resolve(value);
            }

            function onConfirm() {
                finish(true);
                bsModal.hide();
            }

            function onHidden() {
                finish(false);
            }

            confirmBtn.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            bsModal.show();
        });
    }

    function extractIncorrectCode(finding) {
        return '<div class="needs-review-code-wrap">' + renderIncorrectCodeBlocks(finding, 'mb-1') + '</div>';
    }

    function openNeedsReviewPreviewImage(imageSrc, imageAlt) {
        var src = String(imageSrc || '').trim();
        if (!src) return;
        var alt = String(imageAlt || 'Screenshot');
        var previewModalEl = document.getElementById('needsReviewPreviewModal');
        var imageModalEl = document.getElementById('issueImageModal');
        var canUseBootstrap = !!(window.bootstrap && bootstrap.Modal);

        var showImageModal = function () {
            if (typeof window.openImagePopup === 'function') {
                window.openImagePopup(src, alt);
            }
        };

        if (!(previewModalEl && imageModalEl && canUseBootstrap)) {
            showImageModal();
            return;
        }

        reopenNeedsReviewPreviewOnImageClose = true;
        if (previewModalEl.classList.contains('show')) {
            previewModalEl.addEventListener('hidden.bs.modal', function onPreviewHidden() {
                showImageModal();
            }, { once: true });
            bootstrap.Modal.getOrCreateInstance(previewModalEl).hide();
            return;
        }
        showImageModal();
    }

    function initNeedsReviewTableResizable() {
        var resizableTable = document.getElementById('needsReviewResizableTable');
        if (!resizableTable) return;
        if (resizableTable.getAttribute('data-resize-init') === '1') return;
        resizableTable.setAttribute('data-resize-init', '1');

        var resizers = resizableTable.querySelectorAll('.col-resizer');
        var currentTh = null;
        var startX = 0;
        var startWidth = 0;

        resizers.forEach(function (resizer) {
            resizer.addEventListener('mousedown', function (e) {
                e.preventDefault();
                e.stopPropagation();
                currentTh = this.parentElement;
                startX = e.pageX;
                startWidth = currentTh.offsetWidth;
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
            });
        });

        function onMouseMove(e) {
            if (!currentTh) return;
            var diff = e.pageX - startX;
            var newWidth = startWidth + diff;
            if (newWidth > 50) {
                currentTh.style.width = newWidth + 'px';
            }
        }

        function onMouseUp() {
            currentTh = null;
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
        }
    }

    function getAutoScanUrlOptions() {
        var urls = [];
        var currentPageUrl = String((window.ProjectConfig && window.ProjectConfig.currentPageUrl) || '').trim();
        if (currentPageUrl) urls.push(currentPageUrl);
        var grouped = (window.ProjectConfig && Array.isArray(window.ProjectConfig.groupedUrls)) ? window.ProjectConfig.groupedUrls : [];
        grouped.forEach(function (g) {
            var u = String((g && (g.url || g.normalized_url)) || '').trim();
            if (u) urls.push(u);
        });
        var seen = {};
        return urls.filter(function (u) {
            var key = u.toLowerCase();
            if (seen[key]) return false;
            seen[key] = true;
            return true;
        });
    }

    function getCustomScanStorageKey() {
        return 'pms_custom_scan_urls_' + String(projectId) + '_' + String(pageId);
    }

    function loadCustomScanUrls() {
        try {
            var raw = localStorage.getItem(getCustomScanStorageKey()) || '[]';
            var parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) return [];
            return parsed.map(function (u) { return String(u || '').trim(); }).filter(Boolean);
        } catch (_) {
            return [];
        }
    }

    function saveCustomScanUrls(urls) {
        try {
            var clean = (urls || []).map(function (u) { return String(u || '').trim(); }).filter(Boolean);
            localStorage.setItem(getCustomScanStorageKey(), JSON.stringify(clean));
        } catch (_) { }
    }

    function normalizeHttpUrl(inputUrl) {
        var url = String(inputUrl || '').trim();
        if (!url) return '';
        if (!/^https?:\/\//i.test(url)) {
            url = 'https://' + url;
        }
        return url;
    }

    function renderCustomScanUrlChips(urls) {
        var host = document.getElementById('needsReviewCustomUrlList');
        if (!host) return;
        if (!urls || !urls.length) {
            host.innerHTML = '<span class="text-muted">No custom URLs added yet.</span>';
            return;
        }
        host.innerHTML = urls.map(function (u, idx) {
            return ''
                + '<span class="badge bg-light text-dark border me-1 mb-1">' + esc(u)
                + ' <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-1 needs-review-remove-custom-url" data-index="' + idx + '" style="text-decoration:none;">&times;</button>'
                + '</span>';
        }).join('');

        host.querySelectorAll('.needs-review-remove-custom-url').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var i = parseInt(this.getAttribute('data-index') || '-1', 10);
                var current = loadCustomScanUrls();
                if (i >= 0 && i < current.length) {
                    current.splice(i, 1);
                    saveCustomScanUrls(current);
                    renderCustomScanUrlChips(current);
                    openScanUrlSelectionModal(true);
                }
            });
        });
    }

    function openScanUrlSelectionModal(keepOpen) {
        var modalEl = document.getElementById('needsReviewScanUrlModal');
        var listEl = document.getElementById('needsReviewScanUrlList');
        var selectAllEl = document.getElementById('needsReviewScanSelectAll');
        var customInput = document.getElementById('needsReviewCustomScanUrlInput');
        var customOpenBtn = document.getElementById('needsReviewOpenCustomUrlBtn');
        var customAddBtn = document.getElementById('needsReviewAddCustomUrlBtn');
        if (!(modalEl && listEl)) {
            runAutomatedScanForCurrentPage([]);
            return;
        }

        var customUrls = loadCustomScanUrls();
        renderCustomScanUrlChips(customUrls);
        var urls = getAutoScanUrlOptions().concat(customUrls);
        var seen = {};
        urls = urls.filter(function (u) {
            var key = String(u || '').toLowerCase();
            if (!key || seen[key]) return false;
            seen[key] = true;
            return true;
        });
        if (!urls.length) {
            if (typeof window.showToast === 'function') window.showToast('No URL available for scanning', 'warning');
            return;
        }

        listEl.innerHTML = urls.map(function (u, idx) {
            var id = 'scanUrlOption_' + idx;
            return ''
                + '<div class="form-check mb-1">'
                + '<input class="form-check-input needs-review-scan-url" type="checkbox" id="' + esc(id) + '" value="' + esc(u) + '" checked>'
                + '<label class="form-check-label small" for="' + esc(id) + '">' + esc(u) + '</label>'
                + '</div>';
        }).join('');

        if (selectAllEl) {
            selectAllEl.checked = true;
            selectAllEl.onchange = function () {
                var checked = !!this.checked;
                listEl.querySelectorAll('.needs-review-scan-url').forEach(function (cb) { cb.checked = checked; });
            };
        }

        if (customOpenBtn) {
            customOpenBtn.onclick = function () {
                var u = normalizeHttpUrl(customInput ? customInput.value : '');
                if (!u) return;
                window.open(u, '_blank', 'noopener');
            };
        }
        if (customAddBtn) {
            customAddBtn.onclick = function () {
                var u = normalizeHttpUrl(customInput ? customInput.value : '');
                if (!u) {
                    if (typeof window.showToast === 'function') window.showToast('Enter a valid URL first', 'warning');
                    return;
                }
                var current = loadCustomScanUrls();
                if (!current.some(function (x) { return x.toLowerCase() === u.toLowerCase(); })) {
                    current.push(u);
                    saveCustomScanUrls(current);
                }
                if (customInput) customInput.value = u;
                openScanUrlSelectionModal(true);
            };
        }

        if (window.bootstrap && bootstrap.Modal) {
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            if (!keepOpen || !modalEl.classList.contains('show')) {
                modal.show();
            }
        }
    }

    function buildDetailsHtml(finding) {
        var actual = String(finding.actual_results || '').trim();
        var incorrectCodeHtml = renderIncorrectCodeBlocks(finding, '');
        var screenshots = getScreenshotUrls(finding);
        var rec = String(finding.recommendation || '').trim();
        var correct = String(finding.correct_code || '').trim();

        // Keep final issue formatting stable: swap issue headline with recommendation text
        // (as requested) and preserve line breaks after moving to Final Issues.
        var actualLines = actual.split(/\r?\n/);
        var firstActualLine = '';
        for (var i = 0; i < actualLines.length; i++) {
            var ln = String(actualLines[i] || '').trim();
            if (ln !== '') { firstActualLine = ln; break; }
        }
        if (rec && firstActualLine && rec.toLowerCase() !== firstActualLine.toLowerCase()) {
            actual = actual.replace(firstActualLine, rec);
            rec = firstActualLine;
        }

        return [
            '<p><strong>[Actual Results]</strong></p>',
            '<pre class="mb-0" style="white-space: pre-wrap;"><code>' + esc(actual) + '</code></pre>',
            '<p><strong>[Incorrect Code]</strong></p>',
            incorrectCodeHtml,
            '<p><strong>[Screenshots]</strong></p>',
            screenshots.length
                ? ('<div class="issue-image-grid">' + screenshots.map(function (u, idx) {
                    return '<img src="' + esc(u) + '" alt="Screenshot ' + (idx + 1) + '" class="issue-image-thumb">';
                }).join('') + '</div>')
                : '<p></p>',
            '<p><strong>[Recommendation]</strong></p>',
            '<p>' + esc(rec) + '</p>',
            '<p><strong>[Correct Code]</strong></p>',
            '<code class="needs-review-inline-code">' + esc(correct || '-') + '</code>'
        ].join('\n');
    }

    function toIssueSeverity(findingSeverity) {
        var s = String(findingSeverity || '').toLowerCase();
        if (s === 'blocker' || s === 'critical' || s === 'high' || s === 'serious') return 'high';
        if (s === 'minor' || s === 'low') return 'low';
        return 'medium';
    }

    function toMetadataSeverity(findingSeverity) {
        var s = String(findingSeverity || '').toLowerCase().trim();
        if (s === 'blocker' || s === 'critical' || s === 'major' || s === 'minor') return s;
        if (s === 'serious' || s === 'high') return 'critical';
        if (s === 'moderate' || s === 'medium') return 'major';
        if (s === 'low') return 'minor';
        return 'major';
    }

    async function moveFindingToFinal(finding) {
        var fd = new FormData();
        fd.append('action', 'create');
        fd.append('project_id', String(projectId));
        fd.append('page_id', String(pageId));
        fd.append('title', String(finding.title || 'Automated accessibility issue'));
        fd.append('description', buildDetailsHtml(finding));
        fd.append('severity', toIssueSeverity(finding.severity));
        fd.append('priority', 'medium');
        fd.append('issue_status', 'Open');
        fd.append('pages[]', String(pageId));
        var findingUrls = getFindingUrls(finding);
        if (findingUrls.length) {
            fd.append('grouped_urls', JSON.stringify(findingUrls));
        }
        if (window.ProjectConfig && window.ProjectConfig.userId) {
            fd.append('reporters[]', String(window.ProjectConfig.userId));
        }
        var wcagScList = [];
        var wcagScRaw = String(finding.wcag_sc || '').trim();
        if (wcagScRaw) {
            wcagScList = wcagScRaw.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
        }
        var metadataSeverity = toMetadataSeverity(finding.severity);
        var metadata = {
            severity: [metadataSeverity],
            priority: ['medium'],
            grouped_urls: findingUrls,
            wcagsuccesscriteria: wcagScList,
            wcagsuccesscriterianame: (finding.wcag_name ? [String(finding.wcag_name)] : []),
            wcagsuccesscriterialevel: (finding.wcag_level ? [String(finding.wcag_level)] : [])
        };
        fd.append('metadata', JSON.stringify(metadata));

        var createRes = await fetch(baseDir + '/api/issues.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        var createJson = await createRes.json();
        if (!createJson || !createJson.success || !createJson.id) {
            throw new Error((createJson && createJson.error) ? createJson.error : 'Unable to create final issue');
        }

        var markFd = new FormData();
        markFd.append('action', 'mark_moved');
        markFd.append('project_id', String(projectId));
        markFd.append('finding_id', String(finding.id));
        markFd.append('issue_id', String(createJson.id));
        var markRes = await fetch(baseDir + '/api/accessibility_scan.php', {
            method: 'POST',
            body: markFd,
            credentials: 'same-origin'
        });
        var markJson = await markRes.json();
        if (!markJson || !markJson.success) {
            throw new Error((markJson && markJson.message) ? markJson.message : 'Unable to mark finding as moved');
        }
    }

    function syncFinalIssuesCountBadgeFallback() {
        var badge = document.getElementById('finalIssuesCountBadge');
        var body = document.getElementById('finalIssuesBody');
        if (!badge || !body) return;
        var rows = Array.from(body.querySelectorAll('tr.issue-expandable-row'));
        if (!rows.length) {
            var emptyState = body.querySelector('td');
            if (emptyState && /No final issues recorded yet|No issues found/i.test(String(emptyState.textContent || ''))) {
                badge.textContent = '0';
            }
            return;
        }
        badge.textContent = String(rows.length);
    }

    async function loadNeedsReviewFindings() {
        var tbody = document.getElementById('needsReviewBody');
        var badge = document.getElementById('needsReviewCountBadge');
        var selectAll = document.getElementById('needsReviewSelectAll');
        var deleteSelectedBtn = document.getElementById('needsReviewDeleteSelectedBtn');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="14" class="text-muted text-center py-3">Loading automated findings...</td></tr>';
        if (selectAll) selectAll.checked = false;
        if (deleteSelectedBtn) deleteSelectedBtn.disabled = true;
        try {
            var url = baseDir + '/api/accessibility_scan.php?action=list&project_id=' + encodeURIComponent(projectId) + '&page_id=' + encodeURIComponent(pageId);
            var res = await fetch(url, { credentials: 'same-origin' });
            var json = await res.json();
            var rows = (json && json.success && Array.isArray(json.findings)) ? json.findings : [];
            
            // Sync with global issueData for tab count updates
            if (window.issueData) {
                var pid = String(projectId);
                var pgid = String(pageId);
                if (!window.issueData.pages) window.issueData.pages = {};
                if (!window.issueData.pages[pgid]) window.issueData.pages[pgid] = {};
                window.issueData.pages[pgid].needsReview = rows;
                
                if (typeof window.updateIssueTabCounts === 'function') {
                    window.updateIssueTabCounts();
                }
            }

            if (badge) badge.textContent = String(rows.length);
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="14" class="text-muted text-center py-4">No automated findings in needs review.</td></tr>';
                return;
            }
            function openFindingPreviewById(findingId) {
                var finding = rows.find(function (x) { return String(x.id) === String(findingId); });
                if (!finding) return;
                var body = document.getElementById('needsReviewPreviewBody');
                if (!body) return;
                var shots = getScreenshotUrls(finding);
                var findingUrls = getFindingUrls(finding);
                body.innerHTML = ''
                    + '<div class="mb-2"><strong>' + esc(finding.title || '-') + '</strong></div>'
                    + '<div class="small text-muted mb-2">Rule: ' + esc(finding.rule_id || '-') + ' | Severity: ' + esc(finding.severity || '-') + '</div>'
                    + '<div class="mb-2"><strong>URLs</strong><div class="p-2 bg-light border rounded" style="white-space: pre-wrap;">' + esc(findingUrls.join('\n') || '-') + '</div></div>'
                    + '<div class="mb-2"><strong>Actual Results</strong><div class="p-2 bg-light border rounded needs-review-rich-text">' + renderActualResultsHtml(finding.actual_results, '-') + '</div></div>'
                    + '<div class="mb-2"><strong>Incorrect Code</strong><div class="p-2 bg-light border rounded">' + renderIncorrectCodeBlocks(finding, 'mb-2') + '</div></div>'
                    + '<div class="mb-2"><strong>Recommendation</strong><div class="p-2 bg-light border rounded">' + renderRecommendationHtml(finding.recommendation, '-') + '</div></div>'
                    + '<div class="mb-2"><strong>Correct Code</strong><div class="p-2 bg-light border rounded"><code class="needs-review-inline-code mb-0">' + esc(String(finding.correct_code || '-')) + '</code></div></div>'
                    + '<div><strong>Screenshots</strong><div class="mt-2 d-flex flex-wrap gap-2">' + (shots.length ? shots.map(function (u) { return '<img src="' + esc(u) + '" class="img-thumbnail needs-review-preview-image" style="max-height:180px;" data-src="' + esc(u) + '">'; }).join('') : '<span class="text-muted">No screenshots</span>') + '</div></div>';
                body.querySelectorAll('.needs-review-preview-image').forEach(function (img) {
                    img.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        ev.stopPropagation();
                        openNeedsReviewPreviewImage(this.getAttribute('data-src') || this.src || '', this.alt || 'Screenshot');
                    });
                });
                var modalEl = document.getElementById('needsReviewPreviewModal');
                if (modalEl && window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                }
            }
            tbody.innerHTML = rows.map(function (f, idx) {
                var shots = getScreenshotUrls(f);
                var findingUrls = getFindingUrls(f);
                var visibleShots = shots.slice(0, 2);
                var extraShots = shots.length - visibleShots.length;
                var shotHtml = shots.length
                    ? ('<div class="needs-review-shot-stack">'
                        + visibleShots.map(function (u) {
                            return '<img src="' + esc(u) + '" alt="Finding screenshot" class="needs-review-shot" onclick="event.stopPropagation(); if (typeof openImagePopup === \'function\') openImagePopup(this.src, this.alt);">';
                        }).join('')
                        + (extraShots > 0 ? '<span class="needs-review-extra-badge">+' + extraShots + '</span>' : '')
                        + '</div>')
                    : '<span class="text-muted">-</span>';
                var recommendation = 'Verify and fix this rule: ' + String(f.rule_id || '-');
                var urlsPreview = findingUrls.slice(0, 2);
                var urlsExtra = Math.max(0, findingUrls.length - urlsPreview.length);
                var urlsCellHtml = findingUrls.length
                    ? (esc(urlsPreview.join('\n')) + (urlsExtra > 0 ? ('\n+' + urlsExtra + ' more') : ''))
                    : esc((f.scan_url || '-'));
                return '<tr class="needs-review-row" data-finding-id="' + esc(f.id) + '">' +
                    '<td class="text-center"><input type="checkbox" class="form-check-input needs-review-select" value="' + esc(f.id) + '"></td>' +
                    '<td>' + (idx + 1) + '</td>' +
                    '<td><div class="fw-semibold needs-review-issue-title">' + esc(f.title || '-') + '</div><div class="small text-muted needs-review-issue-meta">' + esc(f.rule_id || '-') + ' | ' + esc(f.severity || '-') + ' | ' + esc(f.occurrence_count || 0) + ' hit(s)</div></td>' +
                    '<td class="small needs-review-cell-scroll"><div class="needs-review-truncate" title="' + esc(findingUrls.join('\n')) + '">' + urlsCellHtml + '</div></td>' +
                    '<td><span class="badge bg-light text-dark border">' + esc(f.severity || '-') + '</span></td>' +
                    '<td class="small">' + esc(f.wcag_sc || '-') + '</td>' +
                    '<td class="small">' + esc(f.wcag_name || '-') + '</td>' +
                    '<td class="small">' + esc(f.wcag_level || '-') + '</td>' +
                    '<td class="small needs-review-cell-scroll"><div class="needs-review-truncate needs-review-rich-text">' + renderActualResultsHtml(f.actual_results, '-') + '</div></td>' +
                    '<td class="small needs-review-cell-scroll">' + extractIncorrectCode(f) + '</td>' +
                    '<td class="small">' + shotHtml + '</td>' +
                    '<td class="small needs-review-cell-scroll"><div class="needs-review-truncate">' + renderRecommendationHtml(String(f.recommendation || recommendation || '-'), '-') + '</div></td>' +
                    '<td class="small needs-review-cell-scroll"><div class="needs-review-code-wrap"><code class="needs-review-inline-code mb-0">' + esc(String(f.correct_code || '-')) + '</code></div></td>' +
                    '<td class="needs-review-actions"><button type="button" class="btn btn-sm btn-outline-secondary needs-review-preview" data-finding-id="' + esc(f.id) + '"><i class="fas fa-eye"></i></button> <button type="button" class="btn btn-sm btn-success needs-review-move" data-finding-id="' + esc(f.id) + '"><i class="fas fa-arrow-right"></i></button> <button type="button" class="btn btn-sm btn-outline-danger needs-review-delete" data-finding-id="' + esc(f.id) + '"><i class="fas fa-trash"></i></button></td>' +
                '</tr>';
            }).join('');

            function updateDeleteBtnState() {
                if (!deleteSelectedBtn) return;
                var selected = tbody.querySelectorAll('.needs-review-select:checked').length;
                deleteSelectedBtn.disabled = selected < 1;
            }

            tbody.querySelectorAll('.needs-review-select').forEach(function (cb) {
                cb.addEventListener('change', updateDeleteBtnState);
            });
            if (selectAll) {
                selectAll.checked = false;
                selectAll.onchange = function () {
                    var checked = !!this.checked;
                    tbody.querySelectorAll('.needs-review-select').forEach(function (cb) { cb.checked = checked; });
                    updateDeleteBtnState();
                };
            }
            updateDeleteBtnState();

            tbody.querySelectorAll('.needs-review-move').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    var findingId = String(this.getAttribute('data-finding-id') || '');
                    var finding = rows.find(function (x) { return String(x.id) === findingId; });
                    if (!finding) return;
                    var ok = await confirmNeedsReviewAction({
                        title: 'Move Finding',
                        message: 'Move this finding to Final Issues?',
                        confirmText: 'Move',
                        confirmClass: 'btn-success'
                    });
                    if (!ok) return;
                    this.disabled = true;
                    try {
                        await moveFindingToFinal(finding);
                        if (typeof window.showToast === 'function') window.showToast('Moved to Final Issues', 'success');
                        if (window.issueData) {
                            window.issueData.selectedPageId = String(pageId);
                        }
                        if (typeof window.loadFinalIssues === 'function') {
                            await window.loadFinalIssues(String(pageId));
                        }
                        syncFinalIssuesCountBadgeFallback();
                        await loadNeedsReviewFindings();
                    } catch (err) {
                        if (typeof window.showToast === 'function') window.showToast(String(err.message || 'Move failed'), 'danger');
                        this.disabled = false;
                    }
                });
            });

            tbody.querySelectorAll('.needs-review-delete').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    var findingId = String(this.getAttribute('data-finding-id') || '');
                    if (!findingId) return;
                    var ok = await confirmNeedsReviewAction({
                        title: 'Delete Finding',
                        message: 'Delete this finding permanently?',
                        confirmText: 'Delete',
                        confirmClass: 'btn-danger'
                    });
                    if (!ok) return;
                    this.disabled = true;
                    try {
                        await deleteNeedsReviewFindings([findingId]);
                        if (typeof window.showToast === 'function') window.showToast('Finding deleted', 'success');
                        await loadNeedsReviewFindings();
                    } catch (err) {
                        if (typeof window.showToast === 'function') window.showToast(String(err.message || 'Delete failed'), 'danger');
                        this.disabled = false;
                    }
                });
            });

            tbody.querySelectorAll('.needs-review-preview').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var findingId = String(this.getAttribute('data-finding-id') || '');
                    openFindingPreviewById(findingId);
                });
            });

            tbody.querySelectorAll('.needs-review-row').forEach(function (rowEl) {
                rowEl.addEventListener('click', function (e) {
                    if (e.target.closest('button') || e.target.closest('input') || e.target.closest('a') || e.target.closest('img')) return;
                    var fid = String(this.getAttribute('data-finding-id') || '');
                    if (!fid) return;
                    openFindingPreviewById(fid);
                });
            });
        } catch (e) {
            if (badge) badge.textContent = '0';
            tbody.innerHTML = '<tr><td colspan="14" class="text-danger text-center py-4">Unable to load automated findings.</td></tr>';
        }
    }

    async function deleteNeedsReviewFindings(ids) {
        var cleanIds = (ids || []).map(function (x) { return String(x || '').trim(); }).filter(Boolean);
        if (!cleanIds.length) return;
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('project_id', String(projectId));
        fd.append('page_id', String(pageId));
        fd.append('ids', cleanIds.join(','));
        var res = await fetch(baseDir + '/api/accessibility_scan.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        var json = await res.json();
        if (!json || !json.success) {
            throw new Error((json && json.message) ? json.message : 'Delete failed');
        }
    }

    async function runAutomatedScanForCurrentPage(scanUrls) {
        var btn = document.getElementById('needsReviewRunScanBtn');
        var runSelectedBtn = document.getElementById('needsReviewRunSelectedScanBtn');
        var progressWrap = document.getElementById('needsReviewScanProgressWrap');
        var progressText = document.getElementById('needsReviewScanProgressText');
        var progressPercent = document.getElementById('needsReviewScanProgressPercent');
        var progressBar = document.getElementById('needsReviewScanProgressBar');
        if (btn) btn.disabled = true;
        if (runSelectedBtn) runSelectedBtn.disabled = true;
        var token = 'p' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
        var totalUrls = (Array.isArray(scanUrls) && scanUrls.length) ? scanUrls.length : 1;

        function setProgress(completed, total, percent, statusText) {
            var c = Math.max(0, parseInt(completed || 0, 10));
            var t = Math.max(1, parseInt(total || 1, 10));
            var p = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
            if (progressWrap) progressWrap.classList.remove('d-none');
            if (progressText) progressText.textContent = (statusText || 'Scanning...') + ' (' + c + '/' + t + ' URLs)';
            if (progressPercent) progressPercent.textContent = p + '%';
            if (progressBar) progressBar.style.width = p + '%';
        }

        if (scanProgressTimer) {
            clearInterval(scanProgressTimer);
            scanProgressTimer = null;
        }
        setProgress(0, totalUrls, 0, 'Starting scan');
        scanProgressTimer = setInterval(async function () {
            try {
                var pRes = await fetch(baseDir + '/api/accessibility_scan.php?action=progress&project_id=' + encodeURIComponent(projectId) + '&token=' + encodeURIComponent(token), { credentials: 'same-origin' });
                var pJson = await pRes.json();
                if (!pJson || !pJson.success) return;
                setProgress(pJson.completed || 0, pJson.total || totalUrls, pJson.percent || 0, 'Scanning');
                if (pJson.status === 'completed' || pJson.status === 'failed') {
                    clearInterval(scanProgressTimer);
                    scanProgressTimer = null;
                }
            } catch (_) { }
        }, 900);
        try {
            var fd = new FormData();
            fd.append('project_id', String(projectId));
            fd.append('page_id', String(pageId));
            fd.append('progress_token', token);
            if (Array.isArray(scanUrls) && scanUrls.length) {
                fd.append('scan_urls', JSON.stringify(scanUrls));
            }
            var res = await fetch(baseDir + '/api/accessibility_scan.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            var json = await res.json();
            if (!json || !json.success) {
                throw new Error((json && json.message) ? json.message : 'Scan failed');
            }
            setProgress(totalUrls, totalUrls, 100, 'Scan completed');
            if (typeof window.showToast === 'function') {
                var countUrls = Array.isArray(json.scan_urls) ? json.scan_urls.length : ((Array.isArray(scanUrls) && scanUrls.length) ? scanUrls.length : 1);
                window.showToast('Automated scan completed for ' + countUrls + ' URL(s)', 'success');
            }
            await loadNeedsReviewFindings();
        } catch (e) {
            if (progressText) progressText.textContent = 'Scan failed';
            if (typeof window.showToast === 'function') window.showToast(String(e.message || 'Scan failed'), 'danger');
        } finally {
            if (scanProgressTimer) {
                clearInterval(scanProgressTimer);
                scanProgressTimer = null;
            }
            if (btn) btn.disabled = false;
            if (runSelectedBtn) runSelectedBtn.disabled = false;
            setTimeout(function () {
                if (progressWrap) progressWrap.classList.add('d-none');
            }, 2500);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var refreshBtn = document.getElementById('needsReviewRefreshBtn');
        var runBtn = document.getElementById('needsReviewRunScanBtn');
        var deleteSelectedBtn = document.getElementById('needsReviewDeleteSelectedBtn');
        var runSelectedBtn = document.getElementById('needsReviewRunSelectedScanBtn');
        var scanModalEl = document.getElementById('needsReviewScanUrlModal');
        var issueImageModalEl = document.getElementById('issueImageModal');
        if (issueImageModalEl) {
            issueImageModalEl.addEventListener('hidden.bs.modal', function () {
                if (!reopenNeedsReviewPreviewOnImageClose) return;
                reopenNeedsReviewPreviewOnImageClose = false;
                var previewModalEl = document.getElementById('needsReviewPreviewModal');
                if (previewModalEl && window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(previewModalEl).show();
                }
            });
        }
        if (refreshBtn) refreshBtn.addEventListener('click', loadNeedsReviewFindings);
        if (runBtn) runBtn.addEventListener('click', openScanUrlSelectionModal);
        if (runSelectedBtn) {
            runSelectedBtn.addEventListener('click', async function () {
                var selectedUrls = Array.from(document.querySelectorAll('#needsReviewScanUrlList .needs-review-scan-url:checked'))
                    .map(function (cb) { return String(cb.value || '').trim(); })
                    .filter(Boolean);
                if (!selectedUrls.length) {
                    if (typeof window.showToast === 'function') window.showToast('Select at least one URL', 'warning');
                    return;
                }
                if (scanModalEl && window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(scanModalEl).hide();
                }
                await runAutomatedScanForCurrentPage(selectedUrls);
            });
        }
        if (deleteSelectedBtn) {
            deleteSelectedBtn.addEventListener('click', async function () {
                var selected = Array.from(document.querySelectorAll('#needsReviewBody .needs-review-select:checked'))
                    .map(function (cb) { return String(cb.value || '').trim(); })
                    .filter(Boolean);
                if (!selected.length) return;
                var ok = await confirmNeedsReviewAction({
                    title: 'Delete Selected Findings',
                    message: 'Delete ' + selected.length + ' selected finding(s) permanently?',
                    confirmText: 'Delete Selected',
                    confirmClass: 'btn-danger'
                });
                if (!ok) return;
                this.disabled = true;
                try {
                    await deleteNeedsReviewFindings(selected);
                    if (typeof window.showToast === 'function') window.showToast('Selected findings deleted', 'success');
                    await loadNeedsReviewFindings();
                } catch (e) {
                    if (typeof window.showToast === 'function') window.showToast(String(e.message || 'Delete failed'), 'danger');
                    this.disabled = false;
                }
            });
        }
        initNeedsReviewTableResizable();
        loadNeedsReviewFindings();
    });

    window.loadNeedsReviewFindings = loadNeedsReviewFindings;
})();
</script>

<script>
// Testing Status Update Handler
(function() {
    document.querySelectorAll('.env-status-update').forEach(function(select) {
        select.addEventListener('change', function() {
            const statusType = this.dataset.statusType; // 'testing' or 'qa'
            const pageId = this.dataset.pageId;
            const envId = this.dataset.envId;
            const newStatus = this.value;
            const selectElement = this;
            
            // Disable select during update
            selectElement.disabled = true;
            
            // Send update request
            fetch('<?php echo $baseDir; ?>/api/update_page_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    page_id: pageId,
                    environment_id: envId,
                    status_type: statusType,
                    status: newStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success feedback
                    selectElement.classList.add('border-success');
                    setTimeout(function() {
                        selectElement.classList.remove('border-success');
                    }, 1000);
                } else {
                    if (typeof showToast === 'function') showToast('Error updating status: ' + (data.message || 'Unknown error'), 'danger');
                    else console.error('Error updating status: ' + (data.message || 'Unknown error'));
                    // Revert to previous value
                    selectElement.value = selectElement.dataset.previousValue || '';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof showToast === 'function') showToast('Error updating status. Please try again.', 'danger');
                else console.error('Error updating status. Please try again.');
                // Revert to previous value
                selectElement.value = selectElement.dataset.previousValue || '';
            })
            .finally(function() {
                selectElement.disabled = false;
            });
            
            // Store current value as previous
            selectElement.dataset.previousValue = newStatus;
        });
        
        // Store initial value
        select.dataset.previousValue = select.value;
    });
})();

// Set selected page and auto-load issues
(function() {
    // Wait for view_issues.js to fully load and initialize
    var checkInterval = setInterval(function() {
        if (typeof window.issueData !== 'undefined') {
            clearInterval(checkInterval);
            
            // Set the selected page
            window.issueData.selectedPageId = <?php echo $pageId; ?>;
            
            // Update button state
            if (typeof window.updateEditingState === 'function') {
                window.updateEditingState();
            }
            
            // Load issues
            setTimeout(function() {
                if (typeof window.loadFinalIssues === 'function') {
                    window.loadFinalIssues(<?php echo $pageId; ?>);
                }
            }, 300);
        }
    }, 100);
    
    // Timeout after 5 seconds
    setTimeout(function() {
        clearInterval(checkInterval);
    }, 5000);
})();

// Compatibility guard: some legacy templates still call openImagePopup/closeImagePopup.
// Keep these null-safe so a missing element never breaks other page scripts.
(function () {
    function setElementHidden(el, hidden) {
        if (!el) return;
        if ('hidden' in el) el.hidden = !!hidden;
        if (el.style) el.style.display = hidden ? 'none' : '';
    }

    window.openImagePopup = function (imageSrc, imageAlt) {
        var modalEl = document.getElementById('issueImageModal');
        var previewEl = document.getElementById('issueImagePreview');
        var altWrapEl = document.getElementById('issueImageAltText');
        var altTextEl = document.getElementById('issueImageAltTextContent');
        var altValue = String(imageAlt || '').trim();

        if (previewEl) {
            previewEl.src = imageSrc || '';
            previewEl.alt = altValue;
        }
        if (altTextEl) altTextEl.textContent = altValue;
        if (altWrapEl) setElementHidden(altWrapEl, !altValue);

        if (modalEl && window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    };

    window.closeImagePopup = function () {
        var modalEl = document.getElementById('issueImageModal');
        if (modalEl && window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        }
    };
})();
</script>

<?php if ($userRole !== 'client'): ?>
<!-- Floating Project Chat (bottom-right) -->
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

    function openChatWidget() {
        widget.classList.add('open');
        launcher.style.display = 'none';
        setTimeout(function () { try { closeBtn.focus(); } catch (e) {} }, 0);
    }
    function closeChatWidget() {
        widget.classList.remove('open');
        launcher.style.display = 'inline-flex';
        setTimeout(function () { try { launcher.focus(); } catch (e) {} }, 0);
    }

    launcher.addEventListener('click', openChatWidget);
    closeBtn.addEventListener('click', closeChatWidget);
    fullscreenBtn.addEventListener('click', function () {
        window.location.href = '<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo (int)$projectId; ?>';
    });
    window.addEventListener('message', function (event) {
        if (!event || !event.data || event.data.type !== 'pms-chat-close') return;
        closeChatWidget();
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
