<?php
// Get project-specific performance data
$projectId = $project['id'];

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

$performanceData = [];
$projectStats = [
    'total_comments' => 0,
    'total_issues' => 0,
    'total_project_issues' => 0,
    'avg_error_rate' => 0,
    'avg_error_rate_percent' => 0,
    'total_resources' => 0
];

try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM issues WHERE project_id = ?");
    $countStmt->execute([$projectId]);
    $projectStats['total_project_issues'] = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $projectStats['total_project_issues'] = 0;
}

if ($hasQaStatusMaster) {
    // Primary source: reporter-level QA status mapping.
    if ($hasReporterQaStatusTable) {
        $perfSql = "SELECT
                        u.id AS user_id,
                        u.full_name,
                        u.username,
                        u.role,
                        COUNT(irqs.id) AS total_comments,
                        COUNT(DISTINCT i.id) AS total_issues,
                        SUM(COALESCE(qsm.error_points, 0)) AS total_error_points,
                        AVG(COALESCE(qsm.error_points, 0)) AS avg_error_points,
                        SUM(CASE WHEN qsm.severity_level = '1' THEN 1 ELSE 0 END) AS minor_issues,
                        SUM(CASE WHEN qsm.severity_level = '2' THEN 1 ELSE 0 END) AS moderate_issues,
                        SUM(CASE WHEN qsm.severity_level = '3' THEN 1 ELSE 0 END) AS major_issues,
                        MAX(irqs.updated_at) AS last_activity_date
                    FROM issues i
                    INNER JOIN issue_reporter_qa_status irqs ON irqs.issue_id = i.id
                    INNER JOIN users u ON u.id = irqs.reporter_user_id
                    INNER JOIN qa_status_master qsm
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
                    WHERE i.project_id = ?
                      AND u.role NOT IN ('admin', 'super_admin')
                    GROUP BY u.id, u.full_name, u.username, u.role
                    ORDER BY total_error_points DESC, total_comments DESC";

        try {
            $perfStmt = $db->prepare($perfSql);
            $perfStmt->execute([$projectId]);
            $performanceData = $perfStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('tab_performance reporter-level query failed: ' . $e->getMessage());
            $performanceData = [];
        }
    }

    // Fallback: legacy issue metadata qa_status (single QA status shared across reporters).
    if (empty($performanceData)) {
        $metaSql = "SELECT
                        u.id AS user_id,
                        u.full_name,
                        u.username,
                        u.role,
                        COUNT(im.id) AS total_comments,
                        COUNT(DISTINCT i.id) AS total_issues,
                        SUM(COALESCE(qsm.error_points, 0)) AS total_error_points,
                        AVG(COALESCE(qsm.error_points, 0)) AS avg_error_points,
                        SUM(CASE WHEN qsm.severity_level = '1' THEN 1 ELSE 0 END) AS minor_issues,
                        SUM(CASE WHEN qsm.severity_level = '2' THEN 1 ELSE 0 END) AS moderate_issues,
                        SUM(CASE WHEN qsm.severity_level = '3' THEN 1 ELSE 0 END) AS major_issues,
                        MAX(i.updated_at) AS last_activity_date
                    FROM issues i
                    INNER JOIN users u ON i.reporter_id = u.id
                    INNER JOIN issue_metadata im ON im.issue_id = i.id AND im.meta_key = 'qa_status'
                    INNER JOIN qa_status_master qsm
                        ON (
                            LOWER(TRIM(qsm.status_key)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                            OR LOWER(TRIM(qsm.status_label)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(im.meta_value)) COLLATE utf8mb4_unicode_ci
                        )
                       AND qsm.is_active = 1
                    WHERE i.project_id = ?
                      AND u.role NOT IN ('admin', 'super_admin')
                    GROUP BY u.id, u.full_name, u.username, u.role
                    ORDER BY total_error_points DESC, total_comments DESC";
        try {
            $metaStmt = $db->prepare($metaSql);
            $metaStmt->execute([$projectId]);
            $performanceData = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('tab_performance legacy meta query failed: ' . $e->getMessage());
            $performanceData = [];
        }
    }

    // Fallback for very old rows stored in user_qa_performance.
    if (empty($performanceData)) {
        $legacySql = "SELECT 
                        u.id as user_id,
                        u.full_name,
                        u.username,
                        u.role,
                        COUNT(DISTINCT uqp.id) as total_comments,
                        COUNT(DISTINCT uqp.issue_id) as total_issues,
                        SUM(uqp.error_points) as total_error_points,
                        AVG(uqp.error_points) as avg_error_points,
                        SUM(CASE WHEN qsm.severity_level = '1' THEN 1 ELSE 0 END) as minor_issues,
                        SUM(CASE WHEN qsm.severity_level = '2' THEN 1 ELSE 0 END) as moderate_issues,
                        SUM(CASE WHEN qsm.severity_level = '3' THEN 1 ELSE 0 END) as major_issues,
                        MAX(uqp.comment_date) as last_activity_date
                    FROM user_qa_performance uqp
                    JOIN users u ON uqp.user_id = u.id
                    JOIN qa_status_master qsm ON uqp.qa_status_id = qsm.id
                    WHERE uqp.project_id = ?
                    AND u.role NOT IN ('admin', 'super_admin')
                    GROUP BY u.id, u.full_name, u.username, u.role
                    ORDER BY total_error_points DESC, total_comments DESC";
        try {
            $legacyStmt = $db->prepare($legacySql);
            $legacyStmt->execute([$projectId]);
            $performanceData = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('tab_performance legacy performance table query failed: ' . $e->getMessage());
            $performanceData = [];
        }
    }
    
    // Calculate error rate and performance score for each user
    foreach ($performanceData as &$data) {
        $totalComments = (int)$data['total_comments'];
        $totalPoints = (float)$data['total_error_points'];
        
        $data['error_rate'] = $totalComments > 0 ? round($totalPoints / $totalComments, 2) : 0;
        $data['performance_score'] = max(0, round(100 - ($data['error_rate'] * 33.33), 1));
        
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
    
    // Calculate project statistics
    if (!empty($performanceData)) {
        $projectStats['total_resources'] = count($performanceData);
        $projectStats['total_comments'] = array_sum(array_column($performanceData, 'total_comments'));
        $projectStats['total_issues'] = array_sum(array_column($performanceData, 'total_issues'));
        $projectStats['avg_error_rate'] = round(array_sum(array_column($performanceData, 'error_rate')) / count($performanceData), 2);
        $projectStats['avg_error_rate_percent'] = round(min(100, ($projectStats['avg_error_rate'] / 3) * 100), 1);
    }
}
?>

<div class="tab-pane fade" id="performance" role="tabpanel">
    <div class="p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="mb-1"><i class="fas fa-chart-line text-primary"></i> Resource Performance</h5>
                <small class="text-muted">Performance metrics for resources working on this project</small>
            </div>
            <?php if (hasAdminPrivileges()): ?>
            <a href="<?php echo $baseDir; ?>/modules/admin/performance.php?project_id=<?php echo $projectId; ?>" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-external-link-alt"></i> View Full Report
            </a>
            <?php endif; ?>
        </div>

        <?php if (!$hasQaStatusMaster): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> QA Status Master system not configured. Please run migration 052.
        </div>
        <?php elseif (empty($performanceData)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> No performance data available for this project yet.
        </div>
        <?php else: ?>
        
        <!-- Project Statistics -->
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3 mb-4">
            <div class="col">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-2x text-primary mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['total_resources']; ?></h4>
                        <small class="text-muted">Resources</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <i class="fas fa-comments fa-2x text-info mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['total_comments']; ?></h4>
                        <small class="text-muted">QA Comments</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <i class="fas fa-bug fa-2x text-secondary mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['total_issues']; ?></h4>
                        <small class="text-muted">Issues Reviewed</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-dark">
                    <div class="card-body text-center">
                        <i class="fas fa-list-check fa-2x text-dark mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['total_project_issues']; ?></h4>
                        <small class="text-muted">Total Issues</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                        <h4 class="mb-0"><?php echo $projectStats['avg_error_rate_percent']; ?>%</h4>
                        <small class="text-muted">Avg Error Rate (%)</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Table -->
        <div class="card">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-table"></i> Resource Performance Summary</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Resource</th>
                                <th>Role</th>
                                <th class="text-center">Grade</th>
                                <th class="text-center">Performance</th>
                                <th class="text-center">Error Rate</th>
                                <th class="text-center">Comments</th>
                                <th class="text-center">Issues</th>
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
                                    <div class="progress" style="height: 20px; min-width: 100px;">
                                        <div class="progress-bar bg-<?php echo $data['grade_color']; ?>" 
                                             style="width: <?php echo $data['performance_score']; ?>%"
                                             title="<?php echo $data['performance_score']; ?>%">
                                            <small><strong><?php echo $data['performance_score']; ?>%</strong></small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $data['error_rate'] > 2 ? 'danger' : ($data['error_rate'] > 1 ? 'warning' : 'success'); ?>">
                                        <?php echo $data['error_rate']; ?> / 3
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info"><?php echo $data['total_comments']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?php echo $data['total_issues']; ?></span>
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
            </div>
        </div>

        <!-- Performance Insights -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="fas fa-lightbulb"></i> Performance Insights</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <h6 class="text-success">Top Performers</h6>
                                <ul class="list-unstyled">
                                    <?php
                                    $topPerformers = array_slice($performanceData, 0, 3);
                                    usort($topPerformers, function($a, $b) {
                                        return $b['performance_score'] - $a['performance_score'];
                                    });
                                    foreach ($topPerformers as $performer):
                                        if ($performer['performance_score'] >= 70):
                                    ?>
                                    <li class="mb-1">
                                        <i class="fas fa-trophy text-warning"></i>
                                        <strong><?php echo htmlspecialchars($performer['full_name']); ?></strong>
                                        - <?php echo $performer['performance_score']; ?>% (<?php echo $performer['grade']; ?>)
                                    </li>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6 class="text-warning">Needs Attention</h6>
                                <ul class="list-unstyled">
                                    <?php
                                    $needsAttention = array_filter($performanceData, function($d) {
                                        return $d['performance_score'] < 70;
                                    });
                                    foreach (array_slice($needsAttention, 0, 3) as $resource):
                                    ?>
                                    <li class="mb-1">
                                        <i class="fas fa-exclamation-circle text-warning"></i>
                                        <strong><?php echo htmlspecialchars($resource['full_name']); ?></strong>
                                        - <?php echo $resource['performance_score']; ?>% (<?php echo $resource['grade']; ?>)
                                    </li>
                                    <?php endforeach; ?>
                                    <?php if (empty($needsAttention)): ?>
                                    <li class="text-muted"><i class="fas fa-check-circle text-success"></i> All resources performing well!</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6 class="text-danger">High Error Rate</h6>
                                <ul class="list-unstyled">
                                    <?php
                                    $highErrors = array_filter($performanceData, function($d) {
                                        return $d['error_rate'] > 1.5;
                                    });
                                    usort($highErrors, function($a, $b) {
                                        return $b['error_rate'] - $a['error_rate'];
                                    });
                                    foreach (array_slice($highErrors, 0, 3) as $resource):
                                    ?>
                                    <li class="mb-1">
                                        <i class="fas fa-times-circle text-danger"></i>
                                        <strong><?php echo htmlspecialchars($resource['full_name']); ?></strong>
                                        - Error Rate: <?php echo $resource['error_rate']; ?>
                                    </li>
                                    <?php endforeach; ?>
                                    <?php if (empty($highErrors)): ?>
                                    <li class="text-muted"><i class="fas fa-check-circle text-success"></i> No high error rates detected!</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>
