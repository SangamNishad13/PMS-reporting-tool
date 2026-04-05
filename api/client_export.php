<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/models/ClientAccessControlManager.php';
require_once __DIR__ . '/../includes/models/ClientComplianceScoreResolver.php';
require_once __DIR__ . '/../includes/models/SecurityValidator.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    die('Unauthorized');
}

$securityValidator = new SecurityValidator();
$csrfToken = (string) ($_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!$securityValidator->validateCSRFToken($csrfToken, (string) ($_SESSION['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$db = Database::getInstance();
$clientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';
$allowedProjectIds = null;

if (!$clientId) {
    if ($_SESSION['role'] === 'client') {
        $accessControl = new ClientAccessControlManager();
        $assignedProjects = $accessControl->getAssignedProjects((int) ($_SESSION['user_id'] ?? 0));

        if ($projectId) {
            foreach ($assignedProjects as $assignedProject) {
                if ((int) ($assignedProject['id'] ?? 0) === (int) $projectId) {
                    $clientId = (int) ($assignedProject['client_id'] ?? 0);
                    break;
                }
            }
        }

        if (!$clientId && !empty($assignedProjects)) {
            $clientId = (int) ($assignedProjects[0]['client_id'] ?? 0);
        }
    } else {
        die('Client ID required');
    }
}

if (!$clientId) {
    die('Client ID required');
}

if (($_SESSION['role'] ?? '') === 'client') {
    $accessControl = $accessControl ?? new ClientAccessControlManager();
    $assignedProjects = $assignedProjects ?? $accessControl->getAssignedProjects((int) ($_SESSION['user_id'] ?? 0));
    $allowedProjectIds = array_values(array_unique(array_map('intval', array_column($assignedProjects, 'id'))));

    if ($projectId !== null) {
        if (!in_array((int) $projectId, $allowedProjectIds, true)) {
            die('Unauthorized project access');
        }

        $allowedProjectIds = [(int) $projectId];
    }

    if (empty($allowedProjectIds)) {
        die('No assigned projects found');
    }
}

if ($projectId) {
    header('Location: export_client_report.php?' . http_build_query([
        'project_id' => (int) $projectId,
        'format' => $format,
        'client_ready_only' => 1,
        'csrf_token' => $csrfToken,
    ]));
    exit;
}

// Get client info
$stmt = $db->prepare("SELECT name FROM clients WHERE id = ?");
$stmt->execute([$clientId]);
$client = $stmt->fetch();

if (!$client) {
    die('Client not found');
}

$projectFilter = $projectId ? "AND i.project_id = ?" : "";
$params = $projectId ? [$clientId, $projectId] : [$clientId];

// Fetch all data
$data = fetchDashboardData($db, $clientId, $projectId, $allowedProjectIds);

if ($format === 'excel') {
    $displayClientName = ($_SESSION['role'] ?? '') === 'client' ? '' : $client['name'];
    exportToExcel($data, $displayClientName);
} else {
    $displayClientName = ($_SESSION['role'] ?? '') === 'client' ? '' : $client['name'];
    exportToPDF($data, $displayClientName);
}

function fetchDashboardData($db, $clientId, $projectId, $allowedProjectIds = null) {
    $issueScope = buildProjectScopeSql($projectId, $allowedProjectIds, 'p.id', 'i.project_id');
    $pageScope = buildProjectScopeSql($projectId, $allowedProjectIds, 'p.id', 'p.id');
    $projectFilter = $issueScope['sql'];
    $params = array_merge([$clientId], $issueScope['params']);
    
    // Summary
    $summaryQuery = "
        SELECT 
            COUNT(DISTINCT i.id) as total_issues,
            COUNT(DISTINCT CASE WHEN i.severity = 'blocker' THEN i.id END) as blocker_issues,
            COUNT(DISTINCT CASE WHEN LOWER(ist.name) IN ('open', 'in progress', 'reopened', 'in_progress') THEN i.id END) as open_issues,
            COUNT(DISTINCT CASE WHEN LOWER(ist.name) IN ('resolved', 'closed', 'fixed') THEN i.id END) as resolved_issues
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
    ";
    $stmt = $db->prepare($summaryQuery);
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    $complianceResolver = new ClientComplianceScoreResolver();
    $summary['compliance_score'] = $complianceResolver->resolveForScope(
        $allowedProjectIds !== null ? $allowedProjectIds : ($projectId ? [(int) $projectId] : fetchClientProjectIds($db, $clientId)),
        1
    );
    
    // Severity
    $severityQuery = "
        SELECT i.severity, COUNT(i.id) as count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        GROUP BY i.severity
    ";
    $stmt = $db->prepare($severityQuery);
    $stmt->execute($params);
    $severity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Common Issues
    $commonQuery = "
        SELECT i.common_issue_title as title, i.severity, COUNT(i.id) as count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        AND i.common_issue_title IS NOT NULL
        GROUP BY i.common_issue_title, i.severity
        HAVING count > 1
        ORDER BY count DESC
        LIMIT 10
    ";
    $stmt = $db->prepare($commonQuery);
    $stmt->execute($params);
    $commonIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Blockers
    $blockersQuery = "
        SELECT i.issue_key, i.title, ist.name as status, pp.page_name
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        LEFT JOIN project_pages pp ON i.page_id = pp.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        AND i.severity = 'blocker'
        ORDER BY i.created_at DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($blockersQuery);
    $stmt->execute($params);
    $topBlockers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Pages
    $pagesQuery = "
        SELECT pp.page_name, p.title as project_title, COUNT(i.id) as issue_count
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        LEFT JOIN issues i ON pp.id = i.page_id AND i.client_ready = 1
        WHERE p.client_id = ? {$pageScope['sql']}
        GROUP BY pp.id
        HAVING issue_count > 0
        ORDER BY issue_count DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($pagesQuery);
    $stmt->execute(array_merge([$clientId], $pageScope['params']));
    $topPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Comments
    $commentsQuery = "
        SELECT i.issue_key, i.title, ist.name as status, COUNT(ic.id) as comment_count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        LEFT JOIN issue_comments ic ON i.id = ic.issue_id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        GROUP BY i.id
        HAVING comment_count > 0
        ORDER BY comment_count DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($commentsQuery);
    $stmt->execute($params);
    $topComments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'summary' => $summary,
        'severity' => $severity,
        'commonIssues' => $commonIssues,
        'topBlockers' => $topBlockers,
        'topPages' => $topPages,
        'topComments' => $topComments
    ];
}

function buildProjectScopeSql($projectId, $allowedProjectIds, $projectColumn = 'p.id', $issueProjectColumn = 'i.project_id') {
    $sql = '';
    $params = [];

    if ($allowedProjectIds !== null) {
        $allowedProjectIds = array_values(array_unique(array_map('intval', $allowedProjectIds)));

        if (empty($allowedProjectIds)) {
            return ['sql' => ' AND 1 = 0', 'params' => []];
        }

        $placeholders = implode(',', array_fill(0, count($allowedProjectIds), '?'));
        $scopeColumn = $issueProjectColumn ?: $projectColumn;
        $sql .= " AND {$scopeColumn} IN ($placeholders)";
        $params = array_merge($params, $allowedProjectIds);
    } elseif ($projectId) {
        $scopeColumn = $issueProjectColumn ?: $projectColumn;
        $sql .= " AND {$scopeColumn} = ?";
        $params[] = (int) $projectId;
    }

    return ['sql' => $sql, 'params' => $params];
}

function fetchClientProjectIds($db, $clientId) {
    $stmt = $db->prepare('SELECT id FROM projects WHERE client_id = ?');
    $stmt->execute([(int) $clientId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function exportToExcel($data, $clientName) {
    ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitizeFilename($clientName) . '_Dashboard_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Summary
    fputcsv($output, [$clientName . ' - Dashboard Report']);
    fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    fputcsv($output, ['Summary Statistics']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Issues', $data['summary']['total_issues']]);
    fputcsv($output, ['Blocker Issues', $data['summary']['blocker_issues']]);
    fputcsv($output, ['Open Issues', $data['summary']['open_issues']]);
    fputcsv($output, ['Resolved Issues', $data['summary']['resolved_issues']]);
    fputcsv($output, ['Compliance Score', round((float) ($data['summary']['compliance_score'] ?? 0), 1) . '%']);
    fputcsv($output, []);
    
    // Severity
    fputcsv($output, ['Issues by Severity']);
    fputcsv($output, ['Severity', 'Count']);
    foreach ($data['severity'] as $row) {
        fputcsv($output, [ucfirst($row['severity']), $row['count']]);
    }
    fputcsv($output, []);
    
    // Common Issues
    fputcsv($output, ['Most Common Issues']);
    fputcsv($output, ['Issue Title', 'Severity', 'Occurrences']);
    foreach ($data['commonIssues'] as $row) {
        fputcsv($output, [$row['title'], ucfirst($row['severity']), $row['count']]);
    }
    fputcsv($output, []);
    
    // Top Blockers
    fputcsv($output, ['Top Blocker Issues']);
    fputcsv($output, ['Issue Key', 'Title', 'Status', 'Page']);
    foreach ($data['topBlockers'] as $row) {
        fputcsv($output, [$row['issue_key'], $row['title'], $row['status'], $row['page_name'] ?: 'N/A']);
    }
    fputcsv($output, []);
    
    // Top Pages
    fputcsv($output, ['Top Pages with Most Issues']);
    fputcsv($output, ['Page Name', 'Project', 'Issue Count']);
    foreach ($data['topPages'] as $row) {
        fputcsv($output, [$row['page_name'], $row['project_title'], $row['issue_count']]);
    }
    fputcsv($output, []);
    
    // Top Comments
    fputcsv($output, ['Most Commented Issues']);
    fputcsv($output, ['Issue Key', 'Title', 'Status', 'Comments']);
    foreach ($data['topComments'] as $row) {
        fputcsv($output, [$row['issue_key'], $row['title'], $row['status'], $row['comment_count']]);
    }
    
    fclose($output);
    exit;
}

function exportToPDF($data, $clientName) {
    ob_clean();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($clientName); ?> - Dashboard Report</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; max-width: 1000px; margin: 0 auto; padding: 40px; }
            .header { border-bottom: 2px solid #2563eb; margin-bottom: 30px; padding-bottom: 10px; }
            h1 { color: #2563eb; margin: 0; }
            .meta { color: #666; margin-bottom: 30px; }
            .section { margin-bottom: 40px; page-break-inside: avoid; }
            h2 { border-left: 4px solid #2563eb; padding-left: 10px; background: #f8fafc; padding-top: 5px; padding-bottom: 5px; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th, td { border: 1px solid #e2e8f0; padding: 12px; text-align: left; }
            th { background-color: #f1f5f9; font-weight: 600; }
            .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
            .stat-card { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; text-align: center; }
            .stat-value { font-size: 24px; font-weight: bold; color: #2563eb; }
            .stat-label { font-size: 12px; color: #64748b; text-transform: uppercase; }
            @media print {
                .no-print { display: none; }
                body { padding: 0; }
                button { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 20px;">
            <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 5px; cursor: pointer;">Print to PDF</button>
            <button onclick="window.close()" style="padding: 10px 20px; background: #64748b; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">Close</button>
        </div>

        <div class="header">
            <h1>Accessibility Dashboard Report</h1>
            <div class="meta">
                <?php if (($_SESSION['role'] ?? '') !== 'client'): ?>
                    <strong>Client:</strong> <?php echo htmlspecialchars($clientName); ?><br>
                <?php endif; ?>
                <strong>Generated:</strong> <?php echo date('F j, Y, g:i a'); ?>
            </div>
        </div>

        <div class="section">
            <h2>Executive Summary</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $data['summary']['total_issues']; ?></div>
                    <div class="stat-label">Total Issues</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $data['summary']['blocker_issues']; ?></div>
                    <div class="stat-label">Blockers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $data['summary']['open_issues']; ?></div>
                    <div class="stat-label">Active Issues</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php 
                        echo round((float) ($data['summary']['compliance_score'] ?? 0), 1) . '%';
                        ?>
                    </div>
                    <div class="stat-label">Compliance Score</div>
                </div>
            </div>
        </div>

        <div class="section">
            <h2>Issue Severity Distribution</h2>
            <table>
                <thead>
                    <tr><th>Severity Level</th><th>Issue Count</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($data['severity'] as $row): ?>
                    <tr>
                        <td><strong><?php echo ucfirst($row['severity']); ?></strong></td>
                        <td><?php echo $row['count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Most Common Issues</h2>
            <table>
                <thead>
                    <tr><th>Issue Type</th><th>Severity</th><th>Occurrences</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($data['commonIssues'] as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                        <td><?php echo ucfirst($row['severity']); ?></td>
                        <td><?php echo $row['count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Top Blocker Issues</h2>
            <table>
                <thead>
                    <tr><th>ID</th><th>Title</th><th>Status</th><th>Location</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($data['topBlockers'] as $row): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($row['issue_key']); ?></code></td>
                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                        <td><?php echo htmlspecialchars($row['status']); ?></td>
                        <td><?php echo htmlspecialchars($row['page_name'] ?: 'Global/Unknown'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Page Complexity Analysis</h2>
            <table>
                <thead>
                    <tr><th>Page Name</th><th>Project</th><th>Issue Concentration</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($data['topPages'] as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['page_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['project_title']); ?></td>
                        <td><?php echo $row['issue_count']; ?> issues</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
            // Auto-trigger print after a short delay to allow rendering
            window.onload = function() {
                setTimeout(function() {
                    // window.print();
                }, 500);
            };
        </script>
    </body>
    </html>
    <?php
    exit;
}

function sanitizeFilename($filename) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
}
