<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/models/ClientAccessControlManager.php';
require_once __DIR__ . '/../includes/models/ClientComplianceScoreResolver.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$clientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;

if (!$clientId) {
    echo json_encode(['success' => false, 'error' => 'Client ID required']);
    exit;
}

// IDOR fix: non-admin users can only access their own client's data
$sessionRole = $_SESSION['role'] ?? '';
if (!in_array($sessionRole, ['admin'])) {
    $ownerCheck = $db->prepare("SELECT id FROM users WHERE id = ? AND client_id = ?");
    $ownerCheck->execute([$_SESSION['user_id'], $clientId]);
    if (!$ownerCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
}

try {
    $complianceResolver = new ClientComplianceScoreResolver();
    $complianceProjectScope = getComplianceProjectScope($db, $clientId, $projectId);
    $projectFilter = $projectId ? "AND i.project_id = ?" : "";
    $params = $projectId ? [$clientId, $projectId] : [$clientId];
    
    // Summary Statistics
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
    
    $totalIssues = $summary['total_issues'];
    $resolvedIssues = $summary['resolved_issues'];
    $complianceScore = $complianceResolver->resolveForScope($complianceProjectScope, 1);
    
    $summary['compliance'] = $complianceScore;
    $summary['total'] = $totalIssues;
    $summary['blockers'] = $summary['blocker_issues'];
    $summary['open'] = $summary['open_issues'];
    
    // User-Affected Data (from metadata usersaffected field)
    $userAffectedQuery = "
        SELECT 
            im.meta_value as user_type,
            COUNT(DISTINCT i.id) as count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_metadata im ON i.id = im.issue_id AND im.meta_key = 'usersaffected'
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        AND im.meta_value IS NOT NULL AND im.meta_value != ''
        GROUP BY im.meta_value
        ORDER BY count DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($userAffectedQuery);
    $stmt->execute($params);
    $userAffectedData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $userAffected = [
        'labels' => array_column($userAffectedData, 'user_type'),
        'values' => array_map('intval', array_column($userAffectedData, 'count')),
        'total' => $totalIssues
    ];
    
    // WCAG Levels (from metadata wcagsuccesscriterialevel field)
    $wcagLevelsQuery = "
        SELECT 
            im.meta_value as level,
            COUNT(DISTINCT i.id) as count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_metadata im ON i.id = im.issue_id AND im.meta_key = 'wcagsuccesscriterialevel'
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        AND im.meta_value IS NOT NULL AND im.meta_value != ''
        GROUP BY im.meta_value
        ORDER BY FIELD(im.meta_value, 'A', 'AA', 'AAA')
    ";
    $stmt = $db->prepare($wcagLevelsQuery);
    $stmt->execute($params);
    $wcagData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $wcagLevels = [
        'labels' => array_column($wcagData, 'level'),
        'values' => array_map('intval', array_column($wcagData, 'count'))
    ];
    
    // Severity Distribution
    $severityQuery = "
        SELECT 
            i.severity,
            COUNT(i.id) as count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        GROUP BY i.severity
        ORDER BY FIELD(i.severity, 'blocker', 'critical', 'major', 'minor', 'low')
    ";
    $stmt = $db->prepare($severityQuery);
    $stmt->execute($params);
    $severityData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $severity = [
        'labels' => array_map('ucfirst', array_column($severityData, 'severity')),
        'values' => array_map('intval', array_column($severityData, 'count'))
    ];
    
    // Most Common Issues (show all, not just those with count > 1)
    $commonIssuesQuery = "
        SELECT 
            i.common_issue_title as title,
            i.severity,
            COUNT(i.id) as count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        AND i.common_issue_title IS NOT NULL
        AND i.common_issue_title != ''
        GROUP BY i.common_issue_title, i.severity
        ORDER BY count DESC, 
            FIELD(i.severity, 'blocker', 'critical', 'major', 'minor', 'low')
        LIMIT 10
    ";
    $stmt = $db->prepare($commonIssuesQuery);
    $stmt->execute($params);
    $commonIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Critical Issues (Blocker and Critical severity)
    $topBlockersQuery = "
        SELECT 
            i.id,
            i.issue_key,
            i.title,
            i.severity,
            ist.name as status,
            pp.page_name,
            pp.id as page_id,
            p.id as project_id
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        LEFT JOIN project_pages pp ON i.page_id = pp.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        AND i.severity IN ('blocker', 'critical')
        ORDER BY FIELD(i.severity, 'blocker', 'critical'), i.created_at DESC
        LIMIT 10
    ";
    $stmt = $db->prepare($topBlockersQuery);
    $stmt->execute($params);
    $topBlockers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Pages with Most Issues
    $topPagesQuery = "
        SELECT 
            pp.id as page_id,
            pp.page_name,
            p.id as project_id,
            p.title as project_title,
            COUNT(i.id) as issue_count
        FROM project_pages pp
        JOIN projects p ON pp.project_id = p.id
        LEFT JOIN issues i ON pp.id = i.page_id AND i.client_ready = 1
        WHERE p.client_id = ? $projectFilter
        GROUP BY pp.id, pp.page_name, p.id, p.title
        HAVING issue_count > 0
        ORDER BY issue_count DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($topPagesQuery);
    $stmt->execute($params);
    $topPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Most Commented Issues
    $topCommentsQuery = "
        SELECT 
            i.id,
            i.issue_key,
            i.title,
            ist.name as status,
            pp.id as page_id,
            p.id as project_id,
            COUNT(ic.id) as comment_count
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
        LEFT JOIN issue_comments ic ON i.id = ic.issue_id
        LEFT JOIN project_pages pp ON i.page_id = pp.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        GROUP BY i.id, i.issue_key, i.title, ist.name, pp.id, p.id
        HAVING comment_count > 0
        ORDER BY comment_count DESC
        LIMIT 10
    ";
    $stmt = $db->prepare($topCommentsQuery);
    $stmt->execute($params);
    $topComments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Compliance Trend Analysis
    $trendIssues = fetchTrendIssues($db, $clientId, $projectId);
    $trend = [
        'daily' => getTrendData($trendIssues, $complianceResolver, 'daily', 30),
        'weekly' => getTrendData($trendIssues, $complianceResolver, 'weekly', 12),
        'monthly' => getTrendData($trendIssues, $complianceResolver, 'monthly', 12),
        'yearly' => getTrendData($trendIssues, $complianceResolver, 'yearly', 5)
    ];
    
    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'userAffected' => $userAffected,
        'wcagLevels' => $wcagLevels,
        'severity' => $severity,
        'commonIssues' => $commonIssues,
        'topBlockers' => $topBlockers,
        'topPages' => $topPages,
        'topComments' => $topComments,
        'trend' => $trend
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function getComplianceProjectScope($db, $clientId, $projectId) {
    if ($projectId) {
        return [(int) $projectId];
    }

    if (($_SESSION['role'] ?? '') === 'client') {
        $accessControl = new ClientAccessControlManager();
        $assignedProjects = $accessControl->getAssignedProjects((int) ($_SESSION['user_id'] ?? 0));
        return array_values(array_unique(array_map('intval', array_column($assignedProjects, 'id'))));
    }

    $stmt = $db->prepare('SELECT id FROM projects WHERE client_id = ?');
    $stmt->execute([(int) $clientId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function fetchTrendIssues($db, $clientId, $projectId) {
    $projectFilter = $projectId ? 'AND i.project_id = ?' : '';
    $params = $projectId ? [$clientId, $projectId] : [$clientId];

    $query = "
        SELECT i.id, i.title, i.description, i.created_at
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        ORDER BY i.created_at ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTrendData($issues, $complianceResolver, $period, $limit) {
    $issuesByPeriod = [];

    foreach ($issues as $issue) {
        $createdAt = strtotime((string) ($issue['created_at'] ?? 'now'));
        switch ($period) {
            case 'weekly':
                $periodKey = date('Y-W', $createdAt);
                break;
            case 'monthly':
                $periodKey = date('Y-m', $createdAt);
                break;
            case 'yearly':
                $periodKey = date('Y', $createdAt);
                break;
            case 'daily':
            default:
                $periodKey = date('Y-m-d', $createdAt);
                break;
        }

        $issuesByPeriod[$periodKey][] = $issue;
    }

    if (empty($issuesByPeriod)) {
        return ['labels' => [], 'values' => []];
    }

    ksort($issuesByPeriod);

    $series = [];
    $runningIssues = [];

    foreach ($issuesByPeriod as $periodKey => $periodIssues) {
        $runningIssues = array_merge($runningIssues, $periodIssues);
        $series[] = [
            'label' => $periodKey,
            'value' => $complianceResolver->calculateWcagComplianceFromIssues($runningIssues)
        ];
    }

    $series = array_slice($series, -$limit);

    return [
        'labels' => array_column($series, 'label'),
        'values' => array_column($series, 'value')
    ];
}
