<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_permissions.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$action = $_GET['action'] ?? '';
$projectId = (int)($_GET['project_id'] ?? 0);
$pageId = (int)($_GET['page_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($projectId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid project_id']);
    exit;
}

if (!hasProjectAccess($db, $userId, $projectId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    if ($action === 'get_project_issues') {
        if ($pageId > 0) {
            $stmt = $db->prepare("
                SELECT DISTINCT i.id, i.issue_key, i.title
                FROM issues i
                LEFT JOIN issue_pages ip ON ip.issue_id = i.id
                WHERE i.project_id = ?
                  AND (i.page_id = ? OR ip.page_id = ?)
                ORDER BY i.issue_key ASC, i.id ASC
            ");
            $stmt->execute([$projectId, $pageId, $pageId]);
        } else {
            $stmt = $db->prepare("
                SELECT i.id, i.issue_key, i.title
                FROM issues i
                WHERE i.project_id = ?
                ORDER BY i.issue_key ASC, i.id ASC
            ");
            $stmt->execute([$projectId]);
        }

        echo json_encode([
            'success' => true,
            'issues' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
        exit;
    }

    if ($action === 'get_stats') {
        $totalStmt = $db->prepare("SELECT COUNT(*) FROM issues WHERE project_id = ?");
        $totalStmt->execute([$projectId]);
        $issuesTotal = (int)$totalStmt->fetchColumn();

        $attemptedStmt = $db->prepare("
            SELECT COUNT(DISTINCT ic.issue_id)
            FROM issue_comments ic
            INNER JOIN issues i ON i.id = ic.issue_id
            WHERE i.project_id = ?
              AND ic.comment_type = 'regression'
        ");
        $attemptedStmt->execute([$projectId]);
        $attemptedTotal = (int)$attemptedStmt->fetchColumn();

        $statusStmt = $db->prepare("
            SELECT COALESCE(s.name, 'Unknown') AS status_name, COUNT(DISTINCT i.id) AS cnt
            FROM issues i
            INNER JOIN issue_comments ic ON ic.issue_id = i.id AND ic.comment_type = 'regression'
            LEFT JOIN issue_statuses s ON s.id = i.status_id
            WHERE i.project_id = ?
            GROUP BY COALESCE(s.name, 'Unknown')
            ORDER BY cnt DESC
        ");
        $statusStmt->execute([$projectId]);
        $statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

        $attemptedStatusCounts = [];
        foreach ($statusRows as $row) {
            $attemptedStatusCounts[$row['status_name']] = (int)$row['cnt'];
        }

        echo json_encode([
            'success' => true,
            'issues_total' => $issuesTotal,
            'attempted_issues_total' => $attemptedTotal,
            'attempted_status_counts' => $attemptedStatusCounts
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);
} catch (Throwable $e) {
    error_log('regression_actions.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

