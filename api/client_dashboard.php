<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

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
    
    // Calculate compliance score using WCAG criteria-based formula
    // Total WCAG 2.1 Success Criteria = 57 (Level A + AA + AAA)
    $totalWCAGCriteria = 57;
    
    if ($totalIssues == 0) {
        // No issues means 100% compliance
        $complianceScore = 100;
    } else {
        // Get all WCAG SC with their issues grouped by severity
        // For each SC, we take the highest severity weight
        $criteriaQuery = "
            SELECT 
                im.meta_value as wcag_sc,
                MAX(CASE 
                    WHEN i.severity = 'blocker' THEN 4
                    WHEN i.severity = 'critical' THEN 3
                    WHEN i.severity = 'major' THEN 2
                    WHEN i.severity = 'minor' THEN 1
                    ELSE 1
                END) as max_severity_weight,
                COUNT(DISTINCT i.id) as total_issues_in_sc,
                COUNT(DISTINCT CASE 
                    WHEN LOWER(ist.name) IN ('resolved', 'closed', 'fixed') THEN i.id 
                END) as resolved_issues_in_sc
            FROM issues i
            JOIN projects p ON i.project_id = p.id
            LEFT JOIN issue_statuses ist ON i.status_id = ist.id
            LEFT JOIN issue_metadata im ON i.id = im.issue_id AND im.meta_key = 'wcagsuccesscriteria'
            WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
            AND im.meta_value IS NOT NULL AND im.meta_value != ''
            GROUP BY im.meta_value
        ";
        $stmt = $db->prepare($criteriaQuery);
        $stmt->execute($params);
        $criteriaData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate weights based on WCAG criteria
        $totalWeight = 0;
        $earnedPoints = 0;
        $failedCriteriaCount = 0;
        
        foreach ($criteriaData as $criteria) {
            $weight = intval($criteria['max_severity_weight']);
            $totalIssuesInSC = intval($criteria['total_issues_in_sc']);
            $resolvedIssuesInSC = intval($criteria['resolved_issues_in_sc']);
            
            $totalWeight += $weight;
            
            // If ALL issues in this SC are resolved, earn full points
            if ($totalIssuesInSC > 0 && $resolvedIssuesInSC == $totalIssuesInSC) {
                $earnedPoints += $weight;
            } else {
                // SC has open issues, so it's failed
                $failedCriteriaCount++;
            }
        }
        
        // Add weight for all passing criteria (criteria with no issues at all)
        $passingCriteriaCount = $totalWCAGCriteria - count($criteriaData);
        $passingCriteriaWeight = $passingCriteriaCount * 1; // Weight of 1 for passing criteria
        $totalWeight += $passingCriteriaWeight;
        $earnedPoints += $passingCriteriaWeight; // All passing criteria earn full points
        
        // Excel Formula: Compliance % = (Points + Total Weight) / (2 × Total Weight) × 100
        if ($totalWeight > 0) {
            $complianceScore = (($earnedPoints + $totalWeight) / (2 * $totalWeight)) * 100;
            $complianceScore = round(max(0, min(100, $complianceScore)), 1);
        } else {
            $complianceScore = 100;
        }
    }
    
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
    $trend = [
        'daily' => getTrendData($db, $clientId, $projectId, 'daily', 30),
        'weekly' => getTrendData($db, $clientId, $projectId, 'weekly', 12),
        'monthly' => getTrendData($db, $clientId, $projectId, 'monthly', 12),
        'yearly' => getTrendData($db, $clientId, $projectId, 'yearly', 5)
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

function getTrendData($db, $clientId, $projectId, $period, $limit) {
    $projectFilter = $projectId ? "AND i.project_id = ?" : "";
    $params = $projectId ? [$clientId, $projectId] : [$clientId];
    $totalWCAGCriteria = 57;
    
    // Determine date format based on period
    switch($period) {
        case 'daily':
            $dateFormat = '%Y-%m-%d';
            break;
        case 'weekly':
            $dateFormat = '%Y-W%u';
            break;
        case 'monthly':
            $dateFormat = '%Y-%m';
            break;
        case 'yearly':
            $dateFormat = '%Y';
            break;
        default:
            $dateFormat = '%Y-%m-%d';
    }
    
    // Determine date interval based on period
    switch($period) {
        case 'daily':
            $dateInterval = 'DAY';
            break;
        case 'weekly':
            $dateInterval = 'WEEK';
            break;
        case 'monthly':
            $dateInterval = 'MONTH';
            break;
        case 'yearly':
            $dateInterval = 'YEAR';
            break;
        default:
            $dateInterval = 'DAY';
    }
    
    // Get all periods with issues
    $query = "
        SELECT DISTINCT DATE_FORMAT(i.created_at, '$dateFormat') as period
        FROM issues i
        JOIN projects p ON i.project_id = p.id
        WHERE p.client_id = ? $projectFilter AND i.client_ready = 1
        AND i.created_at >= DATE_SUB(NOW(), INTERVAL $limit $dateInterval)
        ORDER BY period ASC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $periods = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $labels = [];
    $values = [];
    
    foreach ($periods as $currentPeriod) {
        $labels[] = $currentPeriod;
        
        // Get WCAG criteria data for this period (cumulative up to this period)
        $criteriaQuery = "
            SELECT 
                im.meta_value as wcag_sc,
                MAX(CASE 
                    WHEN i.severity = 'blocker' THEN 4
                    WHEN i.severity = 'critical' THEN 3
                    WHEN i.severity = 'major' THEN 2
                    WHEN i.severity = 'minor' THEN 1
                    ELSE 1
                END) as max_severity_weight,
                COUNT(DISTINCT i.id) as total_issues_in_sc,
                COUNT(DISTINCT CASE 
                    WHEN LOWER(ist.name) IN ('resolved', 'closed', 'fixed') 
                    AND DATE_FORMAT(i.updated_at, '$dateFormat') <= ? 
                    THEN i.id 
                END) as resolved_issues_in_sc
            FROM issues i
            JOIN projects p ON i.project_id = p.id
            LEFT JOIN issue_statuses ist ON i.status_id = ist.id
            LEFT JOIN issue_metadata im ON i.id = im.issue_id AND im.meta_key = 'wcagsuccesscriteria'
            WHERE p.client_id = ? $projectFilter 
            AND i.client_ready = 1
            AND DATE_FORMAT(i.created_at, '$dateFormat') <= ?
            AND im.meta_value IS NOT NULL AND im.meta_value != ''
            GROUP BY im.meta_value
        ";
        
        $criteriaParams = $projectId ? [$currentPeriod, $clientId, $projectId, $currentPeriod] : [$currentPeriod, $clientId, $currentPeriod];
        $stmt = $db->prepare($criteriaQuery);
        $stmt->execute($criteriaParams);
        $criteriaData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($criteriaData) == 0) {
            $values[] = 100;
            continue;
        }
        
        $totalWeight = 0;
        $earnedPoints = 0;
        
        foreach ($criteriaData as $criteria) {
            $weight = intval($criteria['max_severity_weight']);
            $totalIssuesInSC = intval($criteria['total_issues_in_sc']);
            $resolvedIssuesInSC = intval($criteria['resolved_issues_in_sc']);
            
            $totalWeight += $weight;
            
            if ($totalIssuesInSC > 0 && $resolvedIssuesInSC == $totalIssuesInSC) {
                $earnedPoints += $weight;
            }
        }
        
        $passingCriteriaCount = $totalWCAGCriteria - count($criteriaData);
        $passingCriteriaWeight = $passingCriteriaCount * 1;
        $totalWeight += $passingCriteriaWeight;
        $earnedPoints += $passingCriteriaWeight;
        
        if ($totalWeight > 0) {
            $compliance = (($earnedPoints + $totalWeight) / (2 * $totalWeight)) * 100;
            $compliance = round(max(0, min(100, $compliance)), 1);
        } else {
            $compliance = 100;
        }
        
        $values[] = $compliance;
    }
    
    return [
        'labels' => $labels,
        'values' => $values
    ];
}
