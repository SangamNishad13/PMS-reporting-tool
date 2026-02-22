<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin', 'super_admin']);

$db = Database::getInstance();
$baseDir = getBaseDir();

// Check if qa_status_master table exists
$hasQaStatusMaster = false;
try {
    $db->query("SELECT 1 FROM qa_status_master LIMIT 1");
    $hasQaStatusMaster = true;
} catch (Exception $e) {
    $hasQaStatusMaster = false;
}

$hasReporterQaStatusTable = false;
try {
    $db->query("SELECT 1 FROM issue_reporter_qa_status LIMIT 1");
    $hasReporterQaStatusTable = true;
} catch (Exception $e) {
    $hasReporterQaStatusTable = false;
}

// Filters
$filters = [
    'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
    'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
    'project_id' => isset($_GET['project_id']) ? (int)$_GET['project_id'] : null,
    'user_id' => isset($_GET['user_id']) ? (int)$_GET['user_id'] : null,
    'severity_level' => $_GET['severity_level'] ?? '',
];

// Get projects and users for filters
$projects = $db->query('SELECT id, title FROM projects ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query("SELECT id, full_name, username FROM users WHERE is_active = 1 AND role != 'admin' AND role != 'super_admin' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Get QA statuses for reference
$qaStatuses = [];
if ($hasQaStatusMaster) {
    $qaStatuses = $db->query('SELECT * FROM qa_status_master WHERE is_active = 1 ORDER BY display_order')->fetchAll(PDO::FETCH_ASSOC);
}

$messages = ['error' => null, 'info' => null];
$performanceData = [];
$recentActivities = [];

if (!$hasQaStatusMaster) {
    $messages['error'] = 'QA Status Master table not found. Please run migration 052 first.';
} else {
    // Build WHERE clause for filters
    $where = ['1=1'];
    $whereLegacy = ['1=1'];
    $params = [];
    
    if (!empty($filters['start_date'])) {
        $where[] = 'DATE(irqs.updated_at) >= ?';
        $whereLegacy[] = 'DATE(i.updated_at) >= ?';
        $params[] = $filters['start_date'];
    }
    if (!empty($filters['end_date'])) {
        $where[] = 'DATE(irqs.updated_at) <= ?';
        $whereLegacy[] = 'DATE(i.updated_at) <= ?';
        $params[] = $filters['end_date'];
    }
    if (!empty($filters['project_id'])) {
        $where[] = 'i.project_id = ?';
        $whereLegacy[] = 'i.project_id = ?';
        $params[] = $filters['project_id'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = 'u.id = ?';
        $whereLegacy[] = 'i.reporter_id = ?';
        $params[] = $filters['user_id'];
    }
    if (!empty($filters['severity_level'])) {
        $where[] = 'qsm.severity_level = ?';
        $whereLegacy[] = 'qsm.severity_level = ?';
        $params[] = $filters['severity_level'];
    }
    
    $whereSql = implode(' AND ', $where);
    $whereLegacySql = implode(' AND ', $whereLegacy);
    
    // Primary source: reporter-level QA status mapping.
    $sql = "SELECT 
                u.id as user_id,
                u.full_name,
                u.username,
                u.role,
                COUNT(irqs.id) as total_comments,
                COUNT(DISTINCT i.id) as total_issues,
                COUNT(DISTINCT i.project_id) as total_projects,
                SUM(COALESCE(qsm.error_points, 0)) as total_error_points,
                AVG(COALESCE(qsm.error_points, 0)) as avg_error_points,
                SUM(CASE WHEN qsm.severity_level = '1' THEN 1 ELSE 0 END) as minor_issues,
                SUM(CASE WHEN qsm.severity_level = '2' THEN 1 ELSE 0 END) as moderate_issues,
                SUM(CASE WHEN qsm.severity_level = '3' THEN 1 ELSE 0 END) as major_issues,
                MAX(irqs.updated_at) as last_activity_date
            FROM issues i
            JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id
            JOIN users u ON u.id = irqs.reporter_user_id
            JOIN qa_status_master qsm
                ON FIND_IN_SET(
                    LOWER(TRIM(qsm.status_key)),
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(LOWER(TRIM(irqs.qa_status_key)), ' ', ''),
                                '[', ''
                            ),
                            ']', ''
                        ),
                        CHAR(34), ''
                    )
                ) > 0
               AND qsm.is_active = 1
            WHERE $whereSql
            AND u.role NOT IN ('admin', 'super_admin')
            GROUP BY u.id, u.full_name, u.username, u.role
            ORDER BY total_error_points DESC, total_comments DESC";
    
    if ($hasReporterQaStatusTable) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $performanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('admin/performance reporter-level query failed: ' . $e->getMessage());
            $performanceData = [];
            $messages['info'] = 'Reporter-level QA data query failed, showing fallback data if available.';
        }
    }

    // Fallback for legacy issue metadata (single QA status shared across reporters).
    if (empty($performanceData)) {
        $legacySql = "SELECT 
                        u.id as user_id,
                        u.full_name,
                        u.username,
                        u.role,
                        COUNT(im.id) as total_comments,
                        COUNT(DISTINCT i.id) as total_issues,
                        COUNT(DISTINCT i.project_id) as total_projects,
                        SUM(COALESCE(qsm.error_points, 0)) as total_error_points,
                        AVG(COALESCE(qsm.error_points, 0)) as avg_error_points,
                        SUM(CASE WHEN qsm.severity_level = '1' THEN 1 ELSE 0 END) as minor_issues,
                        SUM(CASE WHEN qsm.severity_level = '2' THEN 1 ELSE 0 END) as moderate_issues,
                        SUM(CASE WHEN qsm.severity_level = '3' THEN 1 ELSE 0 END) as major_issues,
                        MAX(i.updated_at) as last_activity_date
                    FROM issues i
                    JOIN users u ON i.reporter_id = u.id
                    JOIN issue_metadata im ON im.issue_id = i.id AND im.meta_key = 'qa_status'
                    JOIN qa_status_master qsm
                        ON (
                            LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                            OR LOWER(TRIM(qsm.status_label)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                        )
                       AND qsm.is_active = 1
                    WHERE $whereLegacySql
                    AND u.role NOT IN ('admin', 'super_admin')
                    GROUP BY u.id, u.full_name, u.username, u.role
                    ORDER BY total_error_points DESC, total_comments DESC";
        try {
            $legacyStmt = $db->prepare($legacySql);
            $legacyStmt->execute($params);
            $performanceData = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('admin/performance legacy query failed: ' . $e->getMessage());
            $performanceData = [];
        }
    }
    
    // Calculate error rate and performance score for each user
    foreach ($performanceData as &$data) {
        $totalComments = (int)$data['total_comments'];
        $totalPoints = (float)$data['total_error_points'];
        
        // Error rate: average points per comment (0-3 scale)
        $data['error_rate'] = $totalComments > 0 ? round($totalPoints / $totalComments, 2) : 0;
        
        // Performance score: 100 - (error_rate * 33.33) to convert 0-3 scale to 0-100%
        // Lower error rate = higher performance score
        $data['performance_score'] = max(0, round(100 - ($data['error_rate'] * 33.33), 1));
        
        // Performance grade
        if ($data['performance_score'] >= 90) {
            $data['grade'] = 'A+';
            $data['grade_color'] = 'success';
        } elseif ($data['performance_score'] >= 80) {
            $data['grade'] = 'A';
            $data['grade_color'] = 'success';
        } elseif ($data['performance_score'] >= 70) {
            $data['grade'] = 'B';
            $data['grade_color'] = 'info';
        } elseif ($data['performance_score'] >= 60) {
            $data['grade'] = 'C';
            $data['grade_color'] = 'warning';
        } else {
            $data['grade'] = 'D';
            $data['grade_color'] = 'danger';
        }
    }
    unset($data);
    
    // Get recent activities (last 50) from reporter-level QA statuses
    $recentSql = "SELECT 
                    i.id as issue_id,
                    i.project_id,
                    u.full_name,
                    u.username,
                    qsm.status_label,
                    qsm.status_key,
                    qsm.severity_level,
                    qsm.badge_color,
                    qsm.error_points,
                    DATE(irqs.updated_at) as comment_date,
                    irqs.updated_at as created_at,
                    p.title as project_title
                FROM issues i
                JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id
                JOIN users u ON u.id = irqs.reporter_user_id
                JOIN qa_status_master qsm
                    ON FIND_IN_SET(
                        LOWER(TRIM(qsm.status_key)),
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(LOWER(TRIM(irqs.qa_status_key)), ' ', ''),
                                    '[', ''
                                ),
                                ']', ''
                            ),
                            CHAR(34), ''
                        )
                    ) > 0
                   AND qsm.is_active = 1
                LEFT JOIN projects p ON i.project_id = p.id
                WHERE $whereSql
                AND u.role NOT IN ('admin', 'super_admin')
                ORDER BY irqs.updated_at DESC
                LIMIT 50";
    
    if ($hasReporterQaStatusTable) {
        try {
            $recentStmt = $db->prepare($recentSql);
            $recentStmt->execute($params);
            $recentActivities = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('admin/performance recent reporter query failed: ' . $e->getMessage());
            $recentActivities = [];
        }
    }

    if (empty($recentActivities)) {
        $recentLegacySql = "SELECT 
                            i.id as issue_id,
                            i.project_id,
                            u.full_name,
                            u.username,
                            qsm.status_label,
                            qsm.status_key,
                            qsm.severity_level,
                            qsm.badge_color,
                            qsm.error_points,
                            DATE(i.updated_at) as comment_date,
                            i.updated_at as created_at,
                            p.title as project_title
                        FROM issues i
                        JOIN users u ON i.reporter_id = u.id
                        JOIN issue_metadata im ON im.issue_id = i.id AND im.meta_key = 'qa_status'
                        JOIN qa_status_master qsm
                            ON (
                                LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                                OR LOWER(TRIM(qsm.status_label)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                            )
                           AND qsm.is_active = 1
                        LEFT JOIN projects p ON i.project_id = p.id
                        WHERE $whereLegacySql
                        AND u.role NOT IN ('admin', 'super_admin')
                        ORDER BY i.updated_at DESC
                        LIMIT 50";
        try {
            $recentLegacyStmt = $db->prepare($recentLegacySql);
            $recentLegacyStmt->execute($params);
            $recentActivities = $recentLegacyStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('admin/performance recent legacy query failed: ' . $e->getMessage());
            $recentActivities = [];
        }
    }
    
    // Calculate overall statistics
    $overallStats = [
        'total_users' => count($performanceData),
        'total_comments' => array_sum(array_column($performanceData, 'total_comments')),
        'total_issues' => array_sum(array_column($performanceData, 'total_issues')),
        'avg_error_rate' => count($performanceData) > 0 ? round(array_sum(array_column($performanceData, 'error_rate')) / count($performanceData), 2) : 0,
        'avg_performance_score' => count($performanceData) > 0 ? round(array_sum(array_column($performanceData, 'performance_score')) / count($performanceData), 1) : 0,
    ];
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><i class="fas fa-chart-line text-primary"></i> Resource Performance</h3>
            <small class="text-muted">Track QA status comments and error rates for all resources</small>
        </div>
        <div>
            <a class="btn btn-outline-secondary" href="<?php echo $baseDir; ?>/modules/admin/qa_status_master.php">
                <i class="fas fa-cog"></i> Manage QA Statuses
            </a>
            <a class="btn btn-outline-secondary" href="<?php echo $baseDir; ?>/modules/admin/dashboard.php">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <?php if (!empty($messages['error'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($messages['error']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($messages['info'])): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($messages['info']); ?>
        </div>
    <?php endif; ?>

    <?php if ($hasQaStatusMaster): ?>
    
    <!-- Overall Statistics -->
    <?php if (!empty($performanceData)): ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-primary">
                <div class="card-body text-center">
                    <i class="fas fa-users fa-2x text-primary mb-2"></i>
                    <h4 class="mb-0"><?php echo $overallStats['total_users']; ?></h4>
                    <small class="text-muted">Total Users</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-info">
                <div class="card-body text-center">
                    <i class="fas fa-comments fa-2x text-info mb-2"></i>
                    <h4 class="mb-0"><?php echo $overallStats['total_comments']; ?></h4>
                    <small class="text-muted">Total QA Comments</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body text-center">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                    <h4 class="mb-0"><?php echo $overallStats['avg_error_rate']; ?></h4>
                    <small class="text-muted">Avg Error Rate (0-3)</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body text-center">
                    <i class="fas fa-trophy fa-2x text-success mb-2"></i>
                    <h4 class="mb-0"><?php echo $overallStats['avg_performance_score']; ?>%</h4>
                    <small class="text-muted">Avg Performance Score</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filters['start_date']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filters['end_date']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Project</label>
                    <select name="project_id" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo $filters['project_id'] == $p['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">User</label>
                    <select name="user_id" class="form-select">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filters['user_id'] == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Severity Level</label>
                    <select name="severity_level" class="form-select">
                        <option value="">All Levels</option>
                        <option value="1" <?php echo $filters['severity_level'] === '1' ? 'selected' : ''; ?>>Level 1 - Minor</option>
                        <option value="2" <?php echo $filters['severity_level'] === '2' ? 'selected' : ''; ?>>Level 2 - Moderate</option>
                        <option value="3" <?php echo $filters['severity_level'] === '3' ? 'selected' : ''; ?>>Level 3 - Major</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <a href="<?php echo $baseDir; ?>/modules/admin/performance.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
            <div class="mt-3">
                <small class="text-muted">
                    <strong>Scoring System:</strong> 
                    Level 1 (Minor): 0.25-0.75 points | 
                    Level 2 (Moderate): 0.75-1.50 points | 
                    Level 3 (Major): 2.00-3.00 points
                </small>
            </div>
        </div>
    </div>

    <!-- Performance Table -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-table"></i> User Performance Summary</h5>
            <span class="badge bg-light text-dark"><?php echo count($performanceData); ?> Users</span>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($performanceData)): ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th class="text-center">Grade</th>
                            <th class="text-center">Performance Score</th>
                            <th class="text-center">Error Rate</th>
                            <th class="text-center">Total Points</th>
                            <th class="text-center">Comments</th>
                            <th class="text-center">Issues</th>
                            <th class="text-center">Projects</th>
                            <th class="text-center">Minor</th>
                            <th class="text-center">Moderate</th>
                            <th class="text-center">Major</th>
                            <th>Last Activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($performanceData as $data): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($data['full_name']); ?></strong><br>
                                <small class="text-muted">@<?php echo htmlspecialchars($data['username']); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo ucfirst(str_replace('_', ' ', $data['role'])); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $data['grade_color']; ?> fs-6">
                                    <?php echo $data['grade']; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="progress" style="height: 25px;">
                                    <div class="progress-bar bg-<?php echo $data['grade_color']; ?>" 
                                         style="width: <?php echo $data['performance_score']; ?>%"
                                         title="<?php echo $data['performance_score']; ?>%">
                                        <strong><?php echo $data['performance_score']; ?>%</strong>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $data['error_rate'] > 2 ? 'danger' : ($data['error_rate'] > 1 ? 'warning' : 'success'); ?>">
                                    <?php echo $data['error_rate']; ?> / 3
                                </span>
                            </td>
                            <td class="text-center">
                                <strong class="text-danger"><?php echo number_format($data['total_error_points'], 2); ?></strong>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info"><?php echo $data['total_comments']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary"><?php echo $data['total_issues']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?php echo $data['total_projects']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info"><?php echo $data['minor_issues']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-warning text-dark"><?php echo $data['moderate_issues']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-danger"><?php echo $data['major_issues']; ?></span>
                            </td>
                            <td>
                                <small><?php echo $data['last_activity_date'] ? date('M d, Y', strtotime($data['last_activity_date'])) : '—'; ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <p class="text-muted">No performance data found for the selected filters.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activities -->
    <div class="card mb-4">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-history"></i> Recent QA Status Comments</h5>
            <span class="badge bg-light text-dark">Last 50</span>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($recentActivities)): ?>
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>Date</th>
                            <th>User</th>
                            <th>Project</th>
                            <th>Issue ID</th>
                            <th>QA Status</th>
                            <th>Severity</th>
                            <th class="text-center">Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivities as $activity): ?>
                        <tr>
                            <td>
                                <small><?php echo date('M d, Y', strtotime($activity['comment_date'])); ?></small>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong><br>
                                <small class="text-muted">@<?php echo htmlspecialchars($activity['username']); ?></small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($activity['project_title'] ?: 'N/A'); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">#<?php echo $activity['issue_id']; ?></span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $activity['badge_color']; ?>">
                                    <?php echo htmlspecialchars($activity['status_label']); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php
                                $severityBadge = 'secondary';
                                $severityText = 'Unknown';
                                if ($activity['severity_level'] == '1') {
                                    $severityBadge = 'info';
                                    $severityText = 'Minor';
                                } elseif ($activity['severity_level'] == '2') {
                                    $severityBadge = 'warning';
                                    $severityText = 'Moderate';
                                } elseif ($activity['severity_level'] == '3') {
                                    $severityBadge = 'danger';
                                    $severityText = 'Major';
                                }
                                ?>
                                <span class="badge bg-<?php echo $severityBadge; ?>">
                                    <?php echo $severityText; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <strong class="text-danger"><?php echo number_format($activity['error_points'], 2); ?></strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <p class="text-muted">No recent activities found.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- QA Status Reference -->
    <div class="card">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="fas fa-info-circle"></i> QA Status Reference</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <?php 
                $severityGroups = [
                    '1' => ['title' => 'Level 1 - Minor Issues', 'color' => 'info', 'statuses' => []],
                    '2' => ['title' => 'Level 2 - Moderate Issues', 'color' => 'warning', 'statuses' => []],
                    '3' => ['title' => 'Level 3 - Major Issues', 'color' => 'danger', 'statuses' => []],
                ];
                
                foreach ($qaStatuses as $status) {
                    $severityGroups[$status['severity_level']]['statuses'][] = $status;
                }
                ?>
                
                <?php foreach ($severityGroups as $level => $group): ?>
                <div class="col-md-4">
                    <h6 class="text-<?php echo $group['color']; ?>">
                        <i class="fas fa-circle"></i> <?php echo $group['title']; ?>
                    </h6>
                    <ul class="list-unstyled">
                        <?php foreach ($group['statuses'] as $status): ?>
                        <li class="mb-1">
                            <span class="badge bg-<?php echo $status['badge_color']; ?> me-1">
                                <?php echo number_format($status['error_points'], 2); ?>
                            </span>
                            <small><?php echo htmlspecialchars($status['status_label']); ?></small>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
.sticky-top {
    position: sticky;
    top: 0;
    z-index: 10;
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
