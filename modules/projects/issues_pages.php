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

// Fetch Issue statuses
$issueStatusesStmt = $db->query("SELECT id, status_key, status_label, badge_color FROM issue_status_master WHERE is_active = 1 ORDER BY display_order ASC, status_label ASC");
$issueStatuses = $issueStatusesStmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch project pages
$pagesStmt = $db->prepare("SELECT id, page_name, page_number, url FROM project_pages WHERE project_id = ? ORDER BY page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Issue page summaries - Count actual issues from issues table
$issuePageSummaries = [];
try {
    $issuePageStmt = $db->prepare("
        SELECT 
            pp.id,
            pp.page_name,
            pp.page_number,
            (SELECT GROUP_CONCAT(DISTINCT te.name SEPARATOR ', ') 
             FROM page_environments pe2 
             JOIN testing_environments te ON pe2.environment_id = te.id 
             WHERE pe2.page_id = pp.id) AS envs,
            (SELECT GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') 
             FROM users u 
             JOIN page_environments pe3 ON u.id = pe3.at_tester_id OR u.id = pe3.ft_tester_id OR u.id = pe3.qa_id 
             WHERE pe3.page_id = pp.id) AS testers,
            (SELECT COUNT(DISTINCT i.id) 
             FROM issues i 
             WHERE i.project_id = pp.project_id 
             AND i.page_id = pp.id) AS issues_count,
            (SELECT COALESCE(SUM(ptl.hours_spent), 0)
             FROM project_time_logs ptl
             WHERE ptl.page_id = pp.id) AS production_hours
        FROM project_pages pp
        WHERE pp.project_id = ?
        ORDER BY LENGTH(pp.page_number) ASC, CAST(pp.page_number AS UNSIGNED) ASC, pp.page_name ASC
    ");
    $issuePageStmt->execute([$projectId]);
    while ($row = $issuePageStmt->fetch(PDO::FETCH_ASSOC)) {
        $issuePageSummaries[(int)$row['id']] = $row;
    }
} catch (Exception $e) { 
    $issuePageSummaries = []; 
    error_log("Error loading issue summaries: " . $e->getMessage());
}

// Fetch grouped URLs - simplified
$groupedUrls = [];
$urlsByUniqueId = [];
try {
    $groupedStmt = $db->prepare("
        SELECT 
            gu.id AS grouped_id, 
            gu.url, 
            gu.normalized_url, 
            gu.unique_page_id,
            up.id AS unique_id,
            up.name AS unique_name,
            up.canonical_url,
            pp.id AS mapped_page_id
        FROM grouped_urls gu 
        LEFT JOIN unique_pages up ON gu.unique_page_id = up.id
        LEFT JOIN project_pages pp ON pp.project_id = gu.project_id AND (pp.url = gu.url OR pp.url = gu.normalized_url OR pp.url = up.canonical_url)
        WHERE gu.project_id = ? 
        ORDER BY gu.url
    ");
    $groupedStmt->execute([$projectId]);
    $groupedUrls = $groupedStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by page ID
    foreach ($groupedUrls as $g) {
        if (!empty($g['unique_page_id'])) {
            $urlsByUniqueId[(int)$g['unique_page_id']][] = $g;
        }
    }
} catch (Exception $e) {
    error_log("Error loading grouped URLs: " . $e->getMessage());
}

// Issues Pages view data - prefer unique_pages canonical URL mapping
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
        ORDER BY up.created_at ASC
    ");
    $uniqueIssueStmt->execute([$projectId]);
    $uniqueIssuePages = $uniqueIssueStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
    $uniqueIssuePages = []; 
    error_log("Error loading pages: " . $e->getMessage());
}

// Aggregate totals
$issuesPagesCount = count($uniqueIssuePages);
$issuesTotalCount = 0;
foreach ($uniqueIssuePages as $u) {
    if (isset($u['mapped_page_id']) && isset($issuePageSummaries[$u['mapped_page_id']])) {
        $issuesTotalCount += (int)($issuePageSummaries[$u['mapped_page_id']]['issues_count'] ?? 0);
    }
}

$pageTitle = 'Issues - Pages - ' . htmlspecialchars($project['title']);
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
.resizable-table { table-layout: fixed; width: 100%; min-width: 1200px; }
.resizable-table th { position: relative; overflow: visible; text-overflow: ellipsis; white-space: nowrap; }
.col-resizer { position: absolute; right: 0; top: 0; width: 8px; height: 100%; cursor: col-resize; z-index: 999; background: transparent; border-right: 1px solid rgba(0, 0, 0, 0.2); }
.col-resizer:hover { border-right-color: #007bff; border-right-width: 2px; background: rgba(0, 123, 255, 0.1); }
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
                    <li class="breadcrumb-item active">Pages</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1">
                        <i class="fas fa-file-alt text-primary me-2"></i>
                        Issues - Pages View
                    </h2>
                    <p class="text-muted mb-0">Pages-wise final issues</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_all.php?project_id=<?php echo $projectId; ?>" class="btn btn-primary btn-sm me-2">
                        <i class="fas fa-list me-1"></i> View All Issues
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_common.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-primary btn-sm me-2">
                        <i class="fas fa-layer-group me-1"></i> Common Issues
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Pages</h5>
                <div class="small text-muted"><?php echo count($uniqueIssuePages); ?> total pages</div>
            </div>
        </div>
        <div class="card-body border-bottom">
            <div class="d-flex flex-wrap gap-3">
                <div>
                    <div class="text-muted small">Total Pages</div>
                    <div class="fw-semibold"><?php echo (int)$issuesPagesCount; ?></div>
                </div>
                <div>
                    <div class="text-muted small">Total Issues</div>
                    <div class="fw-semibold"><?php echo (int)$issuesTotalCount; ?></div>
                </div>
            </div>
        </div>
        
        <div class="row g-3 p-3" id="issuesPagesRow">
            <div class="col-lg-12" id="issuesPagesCol">
                <div class="table-responsive" id="issuesPageList">
                    <table class="table table-hover table-sm align-middle mb-0 resizable-table">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;">#<div class="col-resizer"></div></th>
                                <th>Page Name<div class="col-resizer"></div></th>
                                <th style="width: 100px;">Page No<div class="col-resizer"></div></th>
                                <th style="width: 100px;">Issues<div class="col-resizer"></div></th>
                                <th style="width: 150px;">Members<div class="col-resizer"></div></th>
                                <th style="width: 120px;">Environment<div class="col-resizer"></div></th>
                                <th style="width: 120px;">Prod Hours<div class="col-resizer"></div></th>
                                <th style="width: 120px;">Grouped URLs</th>
                            </tr>
                        </thead>
                        <tbody>
<?php if (!empty($uniqueIssuePages)): 
    $rowNum = 1;
    foreach ($uniqueIssuePages as $u):
    $mappedPageId = (int)($u['mapped_page_id'] ?? 0);
    $sum = $mappedPageId ? ($issuePageSummaries[$mappedPageId] ?? []) : [];
    $tester = trim($sum['testers'] ?? "");
    $envs = trim($sum['envs'] ?? "");
    $count = isset($sum['issues_count']) ? (int)$sum['issues_count'] : 0;
    $prodHours = isset($sum['production_hours']) ? (float)$sum['production_hours'] : 0;
    $uniqueLabel = $u['canonical_url'] ?: ($u['unique_name'] ?? "");
    $pageNoLabel = $u['mapped_page_number'] ?? "";
    $displayName = $u['mapped_page_name'] ?? "";
    if (!$displayName) { $displayName = $u['unique_name'] ?? $uniqueLabel; }
    $pageUrls = $urlsByUniqueId[$u['unique_id']] ?? [];
    $hasUrls = !empty($pageUrls);
    $urlCount = count($pageUrls);
?>
                            <tr class="issues-page-row" 
                                data-unique-id="<?php echo (int)$u['unique_id']; ?>"
                                data-page-id="<?php echo (int)$mappedPageId; ?>"
                                data-page-name="<?php echo htmlspecialchars($displayName); ?>"
                                data-page-tester="<?php echo htmlspecialchars($tester ?: '-'); ?>"
                                data-page-env="<?php echo htmlspecialchars($envs ?: '-'); ?>"
                                data-page-issues="<?php echo $count; ?>"
                                style="cursor: pointer;"
                                onclick="window.location.href='<?php echo $baseDir; ?>/modules/projects/issues_page_detail.php?project_id=<?php echo $projectId; ?>&page_id=<?php echo (int)$mappedPageId; ?>'">
                                <td class="text-muted"><?php echo $rowNum++; ?></td>
                                <td>
                                    <div class="fw-semibold text-primary"><?php echo htmlspecialchars($displayName); ?></div>
                                    <div class="small text-muted text-truncate" style="max-width: 300px;" title="<?php echo htmlspecialchars($uniqueLabel); ?>">
                                        <?php echo htmlspecialchars($uniqueLabel ?: '-'); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary">
                                        <?php echo htmlspecialchars($pageNoLabel ?: '-'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $count > 0 ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary'; ?>">
                                        <?php echo $count; ?>
                                    </span>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($tester ?: '-'); ?></td>
                                <td class="small"><?php echo htmlspecialchars($envs ?: '-'); ?></td>
                                <td class="small"><?php echo number_format($prodHours, 2); ?> hrs</td>
                                <td>
                                    <?php if ($hasUrls): ?>
                                    <button class="btn btn-xs btn-outline-secondary" 
                                            type="button" 
                                            data-bs-toggle="collapse" 
                                            data-bs-target="#urls-<?php echo (int)$u['unique_id']; ?>" 
                                            aria-expanded="false"
                                            onclick="event.stopPropagation();">
                                        <i class="fas fa-link me-1"></i> <?php echo $urlCount; ?>
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($hasUrls): ?>
                            <tr class="collapse" id="urls-<?php echo (int)$u['unique_id']; ?>">
                                <td colspan="8" class="p-0 border-0">
                                    <div class="bg-light p-3 border-top">
                                        <div class="small fw-bold text-muted mb-2">
                                            <i class="fas fa-link me-1"></i> Grouped URLs (<?php echo $urlCount; ?>)
                                        </div>
                                        <ul class="list-unstyled mb-0 small">
                                            <?php foreach ($pageUrls as $pUrl): ?>
                                            <li class="mb-1 text-break">
                                                <i class="fas fa-angle-right text-muted me-2"></i>
                                                <?php echo htmlspecialchars($pUrl['url']); ?>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
<?php endforeach; else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                                    <div>No unique pages added yet.</div>
                                </td>
                            </tr>
<?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
// Include modals from the partial file
include __DIR__ . '/partials/issues_modals.php';
?>

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
</script>

<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js"></script>
<script src="<?php echo $baseDir; ?>/modules/projects/js/view_issues.js"></script>

<script>
// Column Resizer for Issues Pages Table
(function() {
    var resizableTable = document.querySelector('.resizable-table');
    if (!resizableTable) return;
    
    var resizers = resizableTable.querySelectorAll('.col-resizer');
    var currentResizer = null;
    var currentTh = null;
    var startX = 0;
    var startWidth = 0;
    
    resizers.forEach(function(resizer) {
        resizer.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            currentResizer = this;
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
        currentResizer = null;
        currentTh = null;
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    }
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
