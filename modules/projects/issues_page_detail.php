<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin']);

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
            (SELECT COUNT(*) FROM issues i WHERE i.project_id = pp.project_id AND i.page_id = pp.id) AS issues_count,
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
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>">
                        <?php echo htmlspecialchars($project['title']); ?>
                    </a></li>
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
                        <div>
                            <span class="text-muted">Prod Hours:</span>
                            <strong><?php echo number_format((float)($issuePageSummary['production_hours'] ?? 0), 2); ?></strong>
                        </div>
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
                            <strong class="small"><i class="fas fa-mobile-alt me-1"></i>AT (Env · Status)</strong>
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
                            <strong class="small"><i class="fas fa-desktop me-1"></i>FT (Env · Status)</strong>
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
                            <strong class="small"><i class="fas fa-check-circle me-1"></i>QA (Env · Status)</strong>
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
                                            <select class="form-select form-select-sm env-status-update" 
                                                    data-status-type="qa"
                                                    data-page-id="<?php echo $pageId; ?>" 
                                                    data-env-id="<?php echo $env['environment_id']; ?>"
                                                    style="font-size: 0.8em; min-width: 110px;">
                                                <option value="pending" <?php echo ($env['qa_status'] ?? '') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="na" <?php echo ($env['qa_status'] ?? '') == 'na' ? 'selected' : ''; ?>>N/A</option>
                                                <option value="completed" <?php echo ($env['qa_status'] ?? '') == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            </select>
                                            <?php else: ?>
                                            <span class="badge bg-secondary" style="font-size: 0.75em;"><?php echo htmlspecialchars(formatQAStatusLabel($env['qa_status'] ?? 'pending')); ?></span>
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

    <!-- Issues Table Card -->
    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <div>
                <strong>Page Issues</strong>
                <span class="small text-muted ms-2">Final issues</span>
            </div>
            <button class="btn btn-primary btn-sm" id="issueAddFinalBtn">
                <i class="fas fa-plus me-1"></i> Add Issue
            </button>
        </div>
        <div class="card-body p-0">
            <ul class="nav nav-tabs px-3 pt-2 mb-0" id="pageIssueTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active py-2" id="final-issues-tab" data-bs-toggle="tab" data-bs-target="#final_issues_tab" type="button">Final Issues <span class="badge bg-secondary ms-1" id="finalIssuesCountBadge">0</span></button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="final_issues_tab" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                        <div class="small text-muted">Issues for the final report</div>
                        <button class="btn btn-sm btn-outline-secondary" id="finalDeleteSelected" disabled>Delete Selected</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:30px;"><input type="checkbox" id="finalSelectAll"></th>
                                    <th style="width:80px;">Issue Key</th>
                                    <th>Issue Title</th>
                                    <th style="width:100px;">Severity</th>
                                    <th style="width:100px;">Priority</th>
                                    <th style="width:120px;">Status</th>
                                    <th style="width:120px;">QA Status</th>
                                    <th style="width:120px;">Reporter</th>
                                    <th style="width:120px;">QA Name</th>
                                    <th style="width:100px;">Pages</th>
                                    <th style="width:120px;">Actions</th>
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
        projectPages: <?php echo json_encode($projectPages ?? []); ?>,
        groupedUrls: <?php echo json_encode($groupedUrls ?? []); ?>,
        projectUsers: <?php echo json_encode($projectUsers ?? []); ?>,
        qaStatuses: <?php echo json_encode($qaStatuses ?? []); ?>,
        issueStatuses: <?php echo json_encode($issueStatuses ?? []); ?>
    };
    
    // Define issueMetadataFields globally for view_issues.js
    window.issueMetadataFields = <?php echo json_encode($metadataFields ?? []); ?>;
</script>

<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js?v=20260210180000"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js?v=<?php echo time(); ?>"></script>
<script>
document.addEventListener('pms:issues-changed', function () {
    if (typeof window.loadFinalIssues === 'function') {
        window.loadFinalIssues(<?php echo (int)$pageId; ?>);
    }
    if (typeof window.loadCommonIssues === 'function') {
        window.loadCommonIssues();
    }
});
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
