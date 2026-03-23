<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'admin']);

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
    
    try {
        $fpStmt = $db->prepare("
            SELECT p.*, c.name as client_name, u.full_name as lead_name,
                (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) as current_phase
            FROM projects p
            LEFT JOIN clients c ON p.client_id = c.id
            LEFT JOIN users u ON p.project_lead_id = u.id
            WHERE $fpWhere
            ORDER BY p.title ASC
        ");
        $fpStmt->execute($fpParams);
        $filteredProjects = $fpStmt->fetchAll();
    } catch (Throwable $e) {
        error_log("Filtered Projects Error: " . $e->getMessage());
    }
}


// 2. Project completion by type
$whereType = "(created_at BETWEEN ? AND ? OR updated_at BETWEEN ? AND ?)";
$paramsType = [$startDate, $dateExtendedEnd, $startDate, $dateExtendedEnd];
if ($projectId > 0) {
    $whereType .= " AND p.id = ?";
    $paramsType[] = $projectId;
}

$completionByType = [];
try {
    $completionByTypeStmt = $db->prepare("
        SELECT 
            p.id, 
            p.project_type, 
            p.title, 
            p.po_number as code, 
            p.status, 
            c.name as client
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        WHERE $whereType
    ");
    $completionByTypeStmt->execute($paramsType);
    $allProjects = $completionByTypeStmt->fetchAll(PDO::FETCH_ASSOC);

    $completionMap = [];
    foreach ($allProjects as $p) {
        $type = $p['project_type'] ?: 'N/A';
        if (!isset($completionMap[$type])) {
            $completionMap[$type] = [
                'project_type' => $type,
                'total' => 0,
                'completed' => 0,
                'completion_rate' => 0,
                'projects_list' => []
            ];
        }
        $completionMap[$type]['total']++;
        if ($p['status'] === 'completed') {
            $completionMap[$type]['completed']++;
        }
        $completionMap[$type]['projects_list'][] = [
            'id' => $p['id'],
            'title' => $p['title'],
            'code' => $p['code'],
            'status' => $p['status'],
            'client' => $p['client']
        ];
    }
    foreach ($completionMap as &$typeData) {
        if ($typeData['total'] > 0) {
            $typeData['completion_rate'] = round(($typeData['completed'] * 100.0) / $typeData['total'], 2);
        }
    }
    unset($typeData);
    $completionByType = array_values($completionMap);
} catch (PDOException $e) {
    error_log("Project Completion Type Error: " . $e->getMessage());
    $completionByType = [];
}

// 3. Tester performance
$testerPage = (int)(isset($_GET['t_page']) ? $_GET['t_page'] : 1);
if ($testerPage < 1) $testerPage = 1;
$perPage = 10;
$testerOffset = ($testerPage - 1) * $perPage;

$testerParams = [$startDate, $dateExtendedEnd, $startDate, $dateExtendedEnd];
$testerProjectFilter = "";
if ($projectId > 0) {
    $testerProjectFilter = " AND ptl.project_id = ? ";
    $testerParams[] = $projectId;
}
$testerPerformance = [];
$totalTesters = 0;
try {
    $testerCountStmt = $db->prepare("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $testerProjectFilter
        WHERE u.role IN ('at_tester', 'ft_tester') AND u.is_active = 1
    ");
    $testerCountStmt->execute(array_slice($testerParams, 0, count($testerParams) - 2)); 
    $totalTesters = $testerCountStmt->fetchColumn();

    $testerPerformanceStmt = $db->prepare("
        SELECT 
            u.id, u.full_name, u.role,
            COUNT(DISTINCT ptl.project_id) as pages_tested,
            COALESCE(SUM(ptl.hours_spent), 0) as total_hours,
            (SELECT COUNT(*) FROM issues i WHERE i.reporter_id = u.id AND i.created_at BETWEEN ? AND ?) as total_issues
        FROM users u
        LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $testerProjectFilter
        WHERE u.role IN ('at_tester', 'ft_tester') AND u.is_active = 1
        GROUP BY u.id
        ORDER BY total_hours DESC
        LIMIT $perPage OFFSET $testerOffset
    ");
    $testerPerformanceStmt->execute($testerParams);
    $testerPerformance = $testerPerformanceStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Tester Performance Error: " . $e->getMessage());
}

// 4. QA performance
$qaPage = (int)(isset($_GET['q_page']) ? $_GET['q_page'] : 1);
if ($qaPage < 1) $qaPage = 1;
$qaOffset = ($qaPage - 1) * $perPage;

$qaParams = [$startDate, $dateExtendedEnd, $startDate, $dateExtendedEnd];
$qaProjectFilter = "";
if ($projectId > 0) {
    $qaProjectFilter = " AND ptl.project_id = ? ";
    $qaParams[] = $projectId;
}
$qaPerformance = [];
$totalQAs = 0;
try {
    $qaCountStmt = $db->prepare("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $qaProjectFilter
        WHERE u.role = 'qa' AND u.is_active = 1
    ");
    $qaCountStmt->execute(array_slice($qaParams, 0, count($qaParams) - 2));
    $totalQAs = $qaCountStmt->fetchColumn();

    $qaPerformanceStmt = $db->prepare("
        SELECT 
            u.id, u.full_name,
            COUNT(DISTINCT ptl.project_id) as pages_reviewed,
            COALESCE(SUM(ptl.hours_spent), 0) as total_hours,
            (SELECT COUNT(*) FROM issues i WHERE i.reporter_id = u.id AND i.created_at BETWEEN ? AND ?) as total_issues
        FROM users u
        LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND ptl.log_date BETWEEN ? AND ? $qaProjectFilter
        WHERE u.role = 'qa' AND u.is_active = 1
        GROUP BY u.id
        ORDER BY total_hours DESC
        LIMIT $perPage OFFSET $qaOffset
    ");
    $qaPerformanceStmt->execute($qaParams);
    $qaPerformance = $qaPerformanceStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("QA Performance Error: " . $e->getMessage());
}

// 5. Recent project completions
$whereRecent = "p.status = 'completed' AND (p.completed_at BETWEEN ? AND ? OR (p.completed_at IS NULL AND p.updated_at BETWEEN ? AND ?))";
$paramsRecent = [$startDate, $dateExtendedEnd, $startDate, $dateExtendedEnd];
if ($projectId > 0) {
    $whereRecent .= " AND p.id = ?";
    $paramsRecent[] = $projectId;
}
$recentCompletions = [];
try {
    $recentCompletionsStmt = $db->prepare("
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
    $recentCompletionsStmt->execute($paramsRecent);
    $recentCompletions = $recentCompletionsStmt->fetchAll();
} catch (Throwable $e) {
    error_log("Recent Completions Error: " . $e->getMessage());
}

// Get projects for filter
$projects = [];
try {
    $projects = $db->query("SELECT id, title, po_number FROM projects ORDER BY title")->fetchAll();
} catch (Throwable $e) {
    error_log("Filter Projects Error: " . $e->getMessage());
}

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
                                <?php if (empty($completionByType)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3"><i class="fas fa-inbox"></i> No data found</td></tr>
                                <?php else: foreach ($completionByType as $type): 
                                    $typeProjects = isset($type['projects_list']) ? $type['projects_list'] : [];
                                ?>
                                <tr class="type-row" style="cursor:pointer" onclick="toggleTypeRow(this)">
                                    <td><i class="fas fa-chevron-right expand-icon" style="transition:transform 0.2s"></i> <strong><?php echo strtoupper($type['project_type'] ?: 'N/A'); ?></strong></td>
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
                                <tr class="type-detail-row d-none">
                                    <td colspan="4" class="p-0 bg-light">
                                        <div class="p-3">
                                            <?php if (empty($typeProjects)): ?>
                                                <p class="text-muted mb-0">No project details available.</p>
                                            <?php else: ?>
                                                <table class="table table-sm mb-0">
                                                    <thead><tr><th>Code</th><th>Title</th><th>Client</th><th>Status</th><th></th></tr></thead>
                                                    <tbody>
                                                    <?php foreach ($typeProjects as $tp): ?>
                                                    <tr>
                                                        <td><code><?php echo htmlspecialchars(isset($tp['code']) ? $tp['code'] : ''); ?></code></td>
                                                        <td><?php echo htmlspecialchars(isset($tp['title']) ? $tp['title'] : ''); ?></td>
                                                        <td><?php echo htmlspecialchars(isset($tp['client']) ? $tp['client'] : 'N/A'); ?></td>
                                                        <td><span class="badge bg-<?php echo projectStatusBadgeClass(isset($tp['status']) ? $tp['status'] : ''); ?>"><?php echo htmlspecialchars(isset($projectStatusLabelMap[isset($tp['status']) ? $tp['status'] : '']) ? $projectStatusLabelMap[isset($tp['status']) ? $tp['status'] : ''] : formatProjectStatusLabel(isset($tp['status']) ? $tp['status'] : '')); ?></span></td>
                                                        <td><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $tp['id']; ?>" class="btn btn-xs btn-outline-primary" target="_blank"><i class="fas fa-eye"></i></a></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
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
                                <?php if (empty($recentCompletions)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-check-circle fa-2x d-block mb-2"></i>No completed projects found.</td></tr>
                                <?php else: foreach ($recentCompletions as $project): ?>
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
                                <?php endforeach; endif; ?>
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
                                <?php if (empty($testerPerformance)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-user-check fa-2x d-block mb-2"></i>No tester activity found.</td></tr>
                                <?php else: foreach ($testerPerformance as $tester): ?>
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
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (isset($totalTesters) && isset($perPage) && $totalTesters > $perPage): 
                        $totalPages = ceil($totalTesters / $perPage);
                        $tPage = isset($testerPage) ? $testerPage : 1;
                        $qPage = isset($qaPage) ? $qaPage : 1;
                    ?>
                    <nav aria-label="Tester pagination" class="mt-3">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?php echo $tPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&project_id=<?php echo urlencode((string)$projectId); ?>&status=<?php echo urlencode($filterStatus); ?>&t_page=<?php echo $tPage - 1; ?>&q_page=<?php echo $qPage; ?>">Prev</a>
                            </li>
                            <?php for ($i = max(1, $tPage - 2); $i <= min($totalPages, $tPage + 2); $i++): ?>
                            <li class="page-item <?php echo $tPage == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&project_id=<?php echo urlencode((string)$projectId); ?>&status=<?php echo urlencode($filterStatus); ?>&t_page=<?php echo $i; ?>&q_page=<?php echo $qPage; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $tPage >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&project_id=<?php echo urlencode((string)$projectId); ?>&status=<?php echo urlencode($filterStatus); ?>&t_page=<?php echo $tPage + 1; ?>&q_page=<?php echo $qPage; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
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
                                <?php if (empty($qaPerformance)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3"><i class="fas fa-clipboard-check fa-2x d-block mb-2"></i>No QA activity found.</td></tr>
                                <?php else: foreach ($qaPerformance as $qa): ?>
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
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (isset($totalQAs) && isset($perPage) && $totalQAs > $perPage): 
                        $totalPagesQA = ceil($totalQAs / $perPage);
                        $tPage = isset($testerPage) ? $testerPage : 1;
                        $qPage = isset($qaPage) ? $qaPage : 1;
                    ?>
                    <nav aria-label="QA pagination" class="mt-3">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?php echo $qPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&project_id=<?php echo urlencode((string)$projectId); ?>&status=<?php echo urlencode($filterStatus); ?>&t_page=<?php echo $tPage; ?>&q_page=<?php echo $qPage - 1; ?>">Prev</a>
                            </li>
                            <?php for ($i = max(1, $qPage - 2); $i <= min($totalPagesQA, $qPage + 2); $i++): ?>
                            <li class="page-item <?php echo $qPage == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&project_id=<?php echo urlencode((string)$projectId); ?>&status=<?php echo urlencode($filterStatus); ?>&t_page=<?php echo $tPage; ?>&q_page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $qPage >= $totalPagesQA ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&project_id=<?php echo urlencode((string)$projectId); ?>&status=<?php echo urlencode($filterStatus); ?>&t_page=<?php echo $tPage; ?>&q_page=<?php echo $qPage + 1; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
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
</div>
<script>
function toggleTypeRow(row) {
    const detailRow = row.nextElementSibling;
    const icon = row.querySelector('.expand-icon');
    if (detailRow && detailRow.classList.contains('type-detail-row')) {
        detailRow.classList.toggle('d-none');
        if (icon) icon.style.transform = detailRow.classList.contains('d-none') ? 'rotate(0deg)' : 'rotate(90deg)';
    }
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
