<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    die('Unauthorized');
}

$db = Database::getInstance();
$clientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

if (!$clientId) {
    die('Client ID required');
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
$data = fetchDashboardData($db, $clientId, $projectId);

if ($format === 'excel') {
    exportToExcel($data, $client['name']);
} else {
    exportToPDF($data, $client['name']);
}

function fetchDashboardData($db, $clientId, $projectId) {
    $projectFilter = $projectId ? "AND i.project_id = ?" : "";
    $params = $projectId ? [$clientId, $projectId] : [$clientId];
    
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
        LEFT JOIN issues i ON pp.id = i.page_id
        WHERE p.client_id = ? $projectFilter
        GROUP BY pp.id
        HAVING issue_count > 0
        ORDER BY issue_count DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($pagesQuery);
    $stmt->execute($params);
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

function exportToExcel($data, $clientName) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . sanitizeFilename($clientName) . '_Dashboard_' . date('Y-m-d') . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    
    // Summary
    echo '<h2>' . htmlspecialchars($clientName) . ' - Dashboard Report</h2>';
    echo '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
    
    echo '<h3>Summary Statistics</h3>';
    echo '<table border="1">';
    echo '<tr><th>Metric</th><th>Value</th></tr>';
    echo '<tr><td>Total Issues</td><td>' . $data['summary']['total_issues'] . '</td></tr>';
    echo '<tr><td>Blocker Issues</td><td>' . $data['summary']['blocker_issues'] . '</td></tr>';
    echo '<tr><td>Open Issues</td><td>' . $data['summary']['open_issues'] . '</td></tr>';
    echo '<tr><td>Resolved Issues</td><td>' . $data['summary']['resolved_issues'] . '</td></tr>';
    $compliance = $data['summary']['total_issues'] > 0 ? 
        round(($data['summary']['resolved_issues'] / $data['summary']['total_issues']) * 100, 1) : 100;
    echo '<tr><td>Compliance Score</td><td>' . $compliance . '%</td></tr>';
    echo '</table><br>';
    
    // Severity
    echo '<h3>Issues by Severity</h3>';
    echo '<table border="1">';
    echo '<tr><th>Severity</th><th>Count</th></tr>';
    foreach ($data['severity'] as $row) {
        echo '<tr><td>' . ucfirst($row['severity']) . '</td><td>' . $row['count'] . '</td></tr>';
    }
    echo '</table><br>';
    
    // Common Issues
    echo '<h3>Most Common Issues</h3>';
    echo '<table border="1">';
    echo '<tr><th>Issue Title</th><th>Severity</th><th>Occurrences</th></tr>';
    foreach ($data['commonIssues'] as $row) {
        echo '<tr><td>' . htmlspecialchars($row['title']) . '</td><td>' . ucfirst($row['severity']) . '</td><td>' . $row['count'] . '</td></tr>';
    }
    echo '</table><br>';
    
    // Top Blockers
    echo '<h3>Top Blocker Issues</h3>';
    echo '<table border="1">';
    echo '<tr><th>Issue Key</th><th>Title</th><th>Status</th><th>Page</th></tr>';
    foreach ($data['topBlockers'] as $row) {
        echo '<tr><td>' . htmlspecialchars($row['issue_key']) . '</td><td>' . htmlspecialchars($row['title']) . '</td><td>' . $row['status'] . '</td><td>' . ($row['page_name'] ?: 'N/A') . '</td></tr>';
    }
    echo '</table><br>';
    
    // Top Pages
    echo '<h3>Top Pages with Most Issues</h3>';
    echo '<table border="1">';
    echo '<tr><th>Page Name</th><th>Project</th><th>Issue Count</th></tr>';
    foreach ($data['topPages'] as $row) {
        echo '<tr><td>' . htmlspecialchars($row['page_name']) . '</td><td>' . htmlspecialchars($row['project_title']) . '</td><td>' . $row['issue_count'] . '</td></tr>';
    }
    echo '</table><br>';
    
    // Top Comments
    echo '<h3>Most Commented Issues</h3>';
    echo '<table border="1">';
    echo '<tr><th>Issue Key</th><th>Title</th><th>Status</th><th>Comments</th></tr>';
    foreach ($data['topComments'] as $row) {
        echo '<tr><td>' . htmlspecialchars($row['issue_key']) . '</td><td>' . htmlspecialchars($row['title']) . '</td><td>' . $row['status'] . '</td><td>' . $row['comment_count'] . '</td></tr>';
    }
    echo '</table>';
    
    echo '</body></html>';
}

function exportToPDF($data, $clientName) {
    // Simple HTML to PDF conversion
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . sanitizeFilename($clientName) . '_Dashboard_' . date('Y-m-d') . '.pdf"');
    
    // For a proper PDF, you would use a library like TCPDF or mPDF
    // This is a simplified version that outputs HTML
    // You should install a PDF library for production use
    
    echo "PDF export requires a PDF library like TCPDF or mPDF to be installed.\n";
    echo "For now, please use Excel export or install a PDF library.\n\n";
    echo "Client: " . $clientName . "\n";
    echo "Total Issues: " . $data['summary']['total_issues'] . "\n";
    echo "Blocker Issues: " . $data['summary']['blocker_issues'] . "\n";
    echo "Open Issues: " . $data['summary']['open_issues'] . "\n";
}

function sanitizeFilename($filename) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
}
