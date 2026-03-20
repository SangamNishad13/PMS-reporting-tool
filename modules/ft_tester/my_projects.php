<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['ft_tester', 'admin', 'super_admin']);

$baseDir = getBaseDir();
$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Get ALL FT Tester's assigned projects (including completed)
// Use simple query without JSON_CONTAINS for compatibility
$assignedProjectsQuery = "
    SELECT DISTINCT p.id, p.title, p.po_number, p.status, p.project_type,
           COUNT(DISTINCT pp.id) as total_pages,
           COUNT(DISTINCT CASE WHEN pe.ft_tester_id = ? THEN pp.id END) as assigned_pages,
           COUNT(DISTINCT CASE WHEN pe.status = 'tested' AND pe.ft_tester_id = ? THEN pp.id END) as completed_pages
    FROM projects p
    LEFT JOIN project_pages pp ON p.id = pp.project_id
    LEFT JOIN page_environments pe ON pp.id = pe.page_id
    WHERE (
        pe.ft_tester_id = ?
        OR pp.ft_tester_id = ?
    )
    GROUP BY p.id, p.title, p.po_number, p.status, p.project_type
    ORDER BY p.created_at DESC
";

$assignedProjects = $db->prepare($assignedProjectsQuery);
$assignedProjects->execute([$userId, $userId, $userId, $userId]);
$projects = $assignedProjects->fetchAll();

// DEBUG: check what columns exist and what data is there for this user
$debugInfo = [];
try {
    $d1 = $db->prepare("SELECT COUNT(*) FROM page_environments WHERE ft_tester_id = ?");
    $d1->execute([$userId]);
    $debugInfo['pe_ft_tester_id_count'] = $d1->fetchColumn();

    $d2 = $db->prepare("SELECT COUNT(*) FROM project_pages WHERE ft_tester_id = ?");
    $d2->execute([$userId]);
    $debugInfo['pp_ft_tester_id_count'] = $d2->fetchColumn();

    $d3 = $db->query("SELECT COUNT(*) FROM page_environments");
    $debugInfo['total_pe_rows'] = $d3->fetchColumn();

    $d4 = $db->query("SELECT COUNT(*) FROM project_pages");
    $debugInfo['total_pp_rows'] = $d4->fetchColumn();

    // Check if ft_tester_ids column exists
    $d5 = $db->query("SHOW COLUMNS FROM page_environments LIKE 'ft_tester_ids'");
    $debugInfo['pe_ft_tester_ids_col_exists'] = $d5->rowCount() > 0 ? 'yes' : 'no';

    $d6 = $db->query("SHOW COLUMNS FROM project_pages LIKE 'ft_tester_ids'");
    $debugInfo['pp_ft_tester_ids_col_exists'] = $d6->rowCount() > 0 ? 'yes' : 'no';

    // Sample a few page_environments rows
    $d7 = $db->query("SELECT id, page_id, ft_tester_id FROM page_environments LIMIT 5");
    $debugInfo['sample_pe'] = $d7->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $debugInfo['error'] = $e->getMessage();
}
// Show debug only to admin viewing as ft_tester (remove after fix)
if (in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    echo '<pre style="background:#fff;padding:10px;margin:10px;font-size:12px;z-index:9999;position:relative">';
    echo 'USER ID: ' . $userId . "\n";
    print_r($debugInfo);
    echo '</pre>';
}

// Also fetch projects assigned via JSON array fields (ft_tester_ids)
try {
    $jsonQuery = "
        SELECT DISTINCT p.id FROM projects p
        LEFT JOIN project_pages pp ON p.id = pp.project_id
        LEFT JOIN page_environments pe ON pp.id = pe.page_id
        WHERE (
            (pe.ft_tester_ids IS NOT NULL AND pe.ft_tester_ids LIKE ?)
            OR (pp.ft_tester_ids IS NOT NULL AND pp.ft_tester_ids LIKE ?)
        )
    ";
    $likeVal = '%' . $userId . '%';
    $jsonStmt = $db->prepare($jsonQuery);
    $jsonStmt->execute([$likeVal, $likeVal]);
    $jsonProjectIds = $jsonStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($jsonProjectIds)) {
        $existingIds = array_column($projects, 'id');
        $newIds = array_diff($jsonProjectIds, $existingIds);
        if (!empty($newIds)) {
            $placeholders = implode(',', array_fill(0, count($newIds), '?'));
            $extraStmt = $db->prepare("
                SELECT DISTINCT p.id, p.title, p.po_number, p.status, p.project_type,
                       COUNT(DISTINCT pp.id) as total_pages,
                       0 as assigned_pages, 0 as completed_pages
                FROM projects p
                LEFT JOIN project_pages pp ON p.id = pp.project_id
                WHERE p.id IN ($placeholders)
                GROUP BY p.id, p.title, p.po_number, p.status, p.project_type
            ");
            $extraStmt->execute(array_values($newIds));
            $extraProjects = $extraStmt->fetchAll();
            $projects = array_merge($projects, $extraProjects);
        }
    }
} catch (Exception $e) {
    // JSON fields may not exist on all installs — ignore
}
$projects = $assignedProjects->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-mobile-alt text-success"></i> My Projects</h2>
                <a href="<?php echo $baseDir; ?>/modules/ft_tester/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- All Projects Table with Filters -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list"></i> All Assigned Projects</h5>
            <div class="d-flex gap-2">
                <select id="statusFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All Status</option>
                    <option value="planning">Planning</option>
                    <option value="in_progress">In Progress</option>
                    <option value="on_hold">On Hold</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select id="typeFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All Types</option>
                    <option value="website">Website</option>
                    <option value="mobile_app">Mobile App</option>
                    <option value="web_app">Web App</option>
                    <option value="other">Other</option>
                </select>
                <input type="text" id="searchProject" class="form-control form-control-sm" placeholder="Search projects..." style="width: 200px;">
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($projects)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No projects assigned yet</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="projectsTable">
                        <thead>
                            <tr>
                                <th>Project Title</th>
                                <th>Project Code</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Assigned Pages</th>
                                <th>Completed</th>
                                <th>Progress</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                            <tr data-status="<?php echo htmlspecialchars($project['status']); ?>" 
                                data-type="<?php echo htmlspecialchars($project['project_type']); ?>"
                                data-title="<?php echo htmlspecialchars(strtolower($project['title'])); ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($project['po_number']); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo ucfirst(str_replace('_', ' ', $project['project_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'planning' => 'secondary',
                                        'in_progress' => 'primary',
                                        'on_hold' => 'warning',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                    $statusColor = $statusColors[$project['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $statusColor; ?>">
                                        <?php echo formatProjectStatusLabel($project['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $project['assigned_pages']; ?></td>
                                <td><?php echo $project['completed_pages']; ?></td>
                                <td>
                                    <?php 
                                    $progress = $project['assigned_pages'] > 0 ? 
                                        round(($project['completed_pages'] / $project['assigned_pages']) * 100) : 0;
                                    ?>
                                    <div class="progress" style="height: 20px; min-width: 100px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%">
                                            <?php echo $progress; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?php echo $baseDir; ?>/modules/at_tester/project_tasks.php?project_id=<?php echo $project['id']; ?>" 
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-tasks"></i> View Tasks
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/my-projects-filter.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
