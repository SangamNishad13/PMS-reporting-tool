<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'super_admin']);

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$db = Database::getInstance();

// Get report parameters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$projectId = (int)($_GET['project_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';

// Project statuses from status master (same source as project create/edit)
$projectStatusOptions = getStatusOptions('project');
if (empty($projectStatusOptions)) {
    $projectStatusOptions = [
        ['status_key' => 'planning', 'status_label' => 'Planning'],
        ['status_key' => 'in_progress', 'status_label' => 'In Progress'],
        ['status_key' => 'on_hold', 'status_label' => 'On Hold'],
        ['status_key' => 'completed', 'status_label' => 'Completed'],
        ['status_key' => 'cancelled', 'status_label' => 'Cancelled'],
    ];
}
$projectStatusLabelMap = [];
foreach ($projectStatusOptions as $opt) {
    $k = (string)($opt['status_key'] ?? '');
    if ($k !== '') $projectStatusLabelMap[$k] = (string)($opt['status_label'] ?? formatProjectStatusLabel($k));
}

// Base parameters for queries
$dateExtendedEnd = $endDate . ' 23:59:59';

// 1. Overall statistics (Ignore date filter for high-level counts unless project is specific)
$statsWhere = "1=1";
$statsParams = [];
if ($projectId > 0) {
    $statsWhere .= " AND p.id = ?";
    $statsParams[] = $projectId;
}
$statsStmt = $db->prepare("
    SELECT 
        COUNT(*) as total_projects,
        COALESCE(AVG(total_hours), 0) as avg_hours_per_project
    FROM projects p
    WHERE $statsWhere
");
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch();

$statusCountStmt = $db->prepare("
    SELECT COALESCE(NULLIF(TRIM(p.status), ''), 'not_started') AS status_key, COUNT(*) AS total
    FROM projects p
    WHERE $statsWhere
    GROUP BY COALESCE(NULLIF(TRIM(p.status), ''), 'not_started')
");
$statusCountStmt->execute($statsParams);
$statusCountRows = $statusCountStmt->fetchAll(PDO::FETCH_ASSOC);
$statusCounts = [];
foreach ($statusCountRows as $row) {
    $statusCounts[(string)$row['status_key']] = (int)$row['total'];
}

// 1b. Filtered Projects List (if status is clicked)
$filteredProjects = [];
if (!empty($filterStatus)) {
    $fpWhere = "(p.status = ? OR (? = 'not_started' AND (p.status IS NULL OR p.status = '' OR p.status = 'not_started')))";
    $fpParams = [$filterStatus, $filterStatus];
    if ($projectId > 0) {
        $fpWhere .= " AND p.id = ?";
        $fpParams[] = $projectId;
    }
    
    $fpStmt = $db->prepare(<<<SQL
        SELECT p.*, c.name as client_name, u.full_name as lead_name,
            (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) as current_phase
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        LEFT JOIN users u ON p.project_lead_id = u.id
        WHERE $fpWhere
        ORDER BY p.title ASC
    SQL);
    $fpStmt->execute($fpParams);
    $filteredProjects = $fpStmt->fetchAll();
}


// 2. Project completion by type
$whereType = "created_at BETWEEN ? AND ?";
$paramsType = [$startDate, $dateExtendedEnd];
if ($projectId > 0) {
    $whereType .= " AND id = ?";
    $paramsType[] = $projectId;
}
$completionByType = $db->prepare("
    SELECT 
        project_type,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        ROUND(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0), 2) as completion_rate
    FROM projects
    WHERE $whereType
    GROUP BY project_type
");
$completionByType->execute($paramsType);
$completionByType = $completionByType->fetchAll();

// 3. Tester performance
$whereTester = "u.role IN ('at_tester', 'ft_tester') AND u.is_active = 1 AND tr.tested_at BETWEEN ? AND ?";
$paramsTester = [$startDate, $dateExtendedEnd];
$joinTester = "";
if ($projectId > 0) {
    $joinTester = "JOIN project_pages pp ON tr.page_id = pp.id";
    $whereTester .= " AND pp.project_id = ?";
    $paramsTester[] = $projectId;
}
$testerPerformance = $db->prepare("
    SELECT 
        u.id, u.full_name, u.role,
        COUNT(DISTINCT tr.page_id) as pages_tested,
        SUM(tr.hours_spent) as total_hours,
        SUM(tr.issues_found) as total_issues
    FROM users u
    LEFT JOIN testing_results tr ON u.id = tr.tester_id
    $joinTester
    WHERE $whereTester
    GROUP BY u.id
    ORDER BY pages_tested DESC
");
$testerPerformance->execute($paramsTester);
$testerPerformance = $testerPerformance->fetchAll();

// 4. QA performance
$whereQA = "u.role = 'qa' AND u.is_active = 1 AND qr.qa_date BETWEEN ? AND ?";
$paramsQA = [$startDate, $dateExtendedEnd];
$joinQA = "";
if ($projectId > 0) {
    $joinQA = "JOIN project_pages pp ON qr.page_id = pp.id";
    $whereQA .= " AND pp.project_id = ?";
    $paramsQA[] = $projectId;
}
$qaPerformance = $db->prepare("
    SELECT 
        u.id, u.full_name,
        COUNT(DISTINCT qr.page_id) as pages_reviewed,
        SUM(qr.hours_spent) as total_hours,
        SUM(qr.issues_found) as total_issues
    FROM users u
    LEFT JOIN qa_results qr ON u.id = qr.qa_id
    $joinQA
    WHERE $whereQA
    GROUP BY u.id
    ORDER BY pages_reviewed DESC
");
$qaPerformance->execute($paramsQA);
$qaPerformance = $qaPerformance->fetchAll();

// 5. Recent project completions
$whereRecent = "p.status = 'completed' AND p.completed_at BETWEEN ? AND ?";
$paramsRecent = [$startDate, $dateExtendedEnd];
if ($projectId > 0) {
    $whereRecent .= " AND p.id = ?";
    $paramsRecent[] = $projectId;
}
$recentCompletions = $db->prepare("
    SELECT 
        p.title,
        p.po_number,
        c.name as client_name,
        p.project_type,
        p.completed_at,
        DATEDIFF(p.completed_at, p.created_at) as days_taken,
        p.total_hours
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    WHERE $whereRecent
    ORDER BY p.completed_at DESC
    LIMIT 10
");
$recentCompletions->execute($paramsRecent);
$recentCompletions = $recentCompletions->fetchAll();

// Get projects for filter
$projects = $db->query("SELECT id, title, po_number FROM projects ORDER BY title")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid">
    <h2>Reports & Analytics</h2>
    
    <!-- Report Filter -->
    <div class="card mb-3">
        <div class="card-header">
            <h5>Report Filter</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label>Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                </div>
                <div class="col-md-3">
                    <label>End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                </div>
                <div class="col-md-3">
                    <label>Project</label>
                    <select name="project_id" class="form-select">
                        <option value="0">All Projects</option>
                        <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>" 
                            <?php echo $projectId == $project['id'] ? 'selected' : ''; ?>>
                            <?php echo $project['title']; ?> (<?php echo $project['po_number']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <?php foreach ($projectStatusOptions as $opt): ?>
                            <?php $statusKey = (string)($opt['status_key'] ?? ''); if ($statusKey === '') continue; ?>
                            <option value="<?php echo htmlspecialchars($statusKey); ?>" <?php echo $filterStatus === $statusKey ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)($opt['status_label'] ?? formatProjectStatusLabel($statusKey))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row mb-3">
        <div class="col-md-3">
            <a href="?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&project_id=<?php echo (int)$projectId; ?>" class="text-decoration-none">
                <div class="card text-center bg-primary text-white dashboard-stat-card <?php echo empty($filterStatus) ? 'active-filter' : ''; ?>">
                    <div class="card-body">
                        <h3><?php echo $stats['total_projects']; ?></h3>
                        <p class="mb-0">Total Projects</p>
                    </div>
                </div>
            </a>
        </div>
        <?php foreach ($projectStatusOptions as $opt): ?>
            <?php
                $statusKey = (string)($opt['status_key'] ?? '');
                if ($statusKey === '') continue;
                $statusLabel = (string)($opt['status_label'] ?? formatProjectStatusLabel($statusKey));
                $statusCount = (int)($statusCounts[$statusKey] ?? 0);
                $badgeClass = projectStatusBadgeClass($statusKey);
                $textClass = in_array($badgeClass, ['warning', 'info', 'light'], true) ? 'text-dark' : 'text-white';
            ?>
            <div class="col-md-3">
                <a href="?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&project_id=<?php echo (int)$projectId; ?>&status=<?php echo urlencode($statusKey); ?>" class="text-decoration-none">
                    <div class="card text-center bg-<?php echo $badgeClass; ?> <?php echo $textClass; ?> dashboard-stat-card <?php echo $filterStatus === $statusKey ? 'active-filter' : ''; ?>">
                        <div class="card-body">
                            <h3><?php echo $statusCount; ?></h3>
                            <p class="mb-0"><?php echo htmlspecialchars($statusLabel); ?></p>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    
    <style>
        .dashboard-stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .dashboard-stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        .active-filter {
            border: 3px solid #fff;
            box-shadow: 0 0 10px rgba(255,255,255,0.5);
        }
    </style>

    <?php if (!empty($filterStatus)): ?>
    <!-- Filtered Project List -->
    <div class="card mb-4 border-<?php echo projectStatusBadgeClass($filterStatus); ?>">
        <div class="card-header bg-<?php echo projectStatusBadgeClass($filterStatus); ?> text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Projects: <?php echo htmlspecialchars($projectStatusLabelMap[$filterStatus] ?? formatProjectStatusLabel($filterStatus)); ?></h5>
            <a href="?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&project_id=<?php echo (int)$projectId; ?>" class="btn btn-sm btn-light">Clear Status Filter</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Project Title</th>
                            <th>Project Code</th>
                            <th>Client</th>
                            <th>Lead</th>
                            <th>Created</th>
                            <th>Phase</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($filteredProjects)): ?>
                        <tr><td colspan="6" class="text-center py-4">No projects found with status "<?php echo htmlspecialchars($projectStatusLabelMap[$filterStatus] ?? formatProjectStatusLabel($filterStatus)); ?>"</td></tr>
                        <?php else: ?>
                            <?php foreach ($filteredProjects as $fp): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($fp['title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($fp['po_number']); ?></td>
                                <td><?php echo htmlspecialchars($fp['client_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($fp['lead_name'] ?? 'Unassigned'); ?></td>
                                <td><?php echo date('M d, Y', strtotime($fp['created_at'])); ?></td>
                                <td>
                                    <?php if (!empty($fp['current_phase'])): ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($fp['current_phase']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $fp['id']; ?>" class="btn btn-xs btn-outline-primary">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    
    <div class="row">
        <!-- Project Completion by Type -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Project Completion by Type</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Project Type</th>
                                    <th>Total</th>
                                    <th>Completed</th>
                                    <th>Completion Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($completionByType as $type): ?>
                                <tr>
                                    <td><?php echo strtoupper($type['project_type']); ?></td>
                                    <td><?php echo $type['total']; ?></td>
                                    <td><?php echo $type['completed']; ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" role="progressbar" 
                                                 style="width: <?php echo $type['completion_rate']; ?>%;"
                                                 aria-valuenow="<?php echo $type['completion_rate']; ?>" 
                                                 aria-valuemin="0" aria-valuemax="100">
                                                <?php echo $type['completion_rate']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Project Completions -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Recent Project Completions</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Client</th>
                                    <th>Type</th>
                                    <th>Days Taken</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentCompletions as $project): ?>
                                <tr>
                                    <td><?php echo $project['title']; ?></td>
                                    <td><?php echo $project['client_name']; ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo strtoupper($project['project_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $project['days_taken']; ?> days</td>
                                    <td><?php echo $project['total_hours'] ?: 'N/A'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-3">
        <!-- Tester Performance -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Tester Performance</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Tester</th>
                                    <th>Role</th>
                                    <th>Pages Tested</th>
                                    <th>Total Hours</th>
                                    <th>Issues Found</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($testerPerformance as $tester): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $tester['id']; ?>">
                                            <?php echo $tester['full_name']; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo strtoupper($tester['role']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $tester['pages_tested']; ?></td>
                                    <td><?php echo $tester['total_hours'] ?: '0'; ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $tester['total_issues'] > 20 ? 'danger' : 
                                                 ($tester['total_issues'] > 10 ? 'warning' : 'success');
                                        ?>">
                                            <?php echo $tester['total_issues']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- QA Performance -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>QA Performance</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>QA</th>
                                    <th>Pages Reviewed</th>
                                    <th>Total Hours</th>
                                    <th>Issues Found</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($qaPerformance as $qa): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo $qa['id']; ?>">
                                            <?php echo $qa['full_name']; ?>
                                        </a>
                                    </td>
                                    <td><?php echo $qa['pages_reviewed']; ?></td>
                                    <td><?php echo $qa['total_hours'] ?: '0'; ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $qa['total_issues'] > 20 ? 'danger' : 
                                                 ($qa['total_issues'] > 10 ? 'warning' : 'success');
                                        ?>">
                                            <?php echo $qa['total_issues']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Export Options -->
    <div class="card mt-3">
        <div class="card-header">
            <h5>Export Reports</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=projects&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-primary w-100">
                        <i class="fas fa-file-excel"></i> Export Projects
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=tester&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-success w-100">
                        <i class="fas fa-file-excel"></i> Export Tester Stats
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=qa&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-info w-100">
                        <i class="fas fa-file-excel"></i> Export QA Stats
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="<?php echo $baseDir; ?>/modules/reports/export.php?type=all&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
                       class="btn btn-warning w-100">
                        <i class="fas fa-file-pdf"></i> Export Full Report
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
