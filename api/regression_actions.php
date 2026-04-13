<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_permissions.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

enforceApiCsrf();

$db = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$pageId = (int)($_GET['page_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? '';

function ensureRegressionRoundsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS regression_rounds (
        id INT NOT NULL AUTO_INCREMENT,
        project_id INT DEFAULT NULL,
        started_by INT DEFAULT NULL,
        round_number INT NOT NULL,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        status ENUM('in_progress','completed') DEFAULT 'in_progress',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ended_at TIMESTAMP NULL DEFAULT NULL,
        admin_confirmed TINYINT(1) DEFAULT 0,
        confirmed_by INT DEFAULT NULL,
        confirmed_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_rr_project_active (project_id, is_active),
        KEY idx_rr_started_by (started_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

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
    if (in_array($action, ['get_stats', 'list_rounds', 'create_round', 'complete_round'], true)) {
        ensureRegressionRoundsTable($db);
    }

    if ($action === 'get_project_issues') {
        if ($pageId > 0) {
            $stmt = $db->prepare("\n                SELECT DISTINCT i.id, i.issue_key, i.title\n                FROM issues i\n                LEFT JOIN issue_pages ip ON ip.issue_id = i.id\n                WHERE i.project_id = ?\n                  AND (i.page_id = ? OR ip.page_id = ?)\n                ORDER BY i.issue_key ASC, i.id ASC\n            ");
            $stmt->execute([$projectId, $pageId, $pageId]);
        } else {
            $stmt = $db->prepare("\n                SELECT i.id, i.issue_key, i.title\n                FROM issues i\n                WHERE i.project_id = ?\n                ORDER BY i.issue_key ASC, i.id ASC\n            ");
            $stmt->execute([$projectId]);
        }

        echo json_encode([
            'success' => true,
            'issues' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
        exit;
    }

    if ($action === 'get_stats') {
        $activeRoundStmt = $db->prepare("\n            SELECT id, round_number, started_at\n            FROM regression_rounds\n            WHERE project_id = ?\n              AND status = 'in_progress'\n              AND is_active = 1\n            ORDER BY round_number DESC\n            LIMIT 1\n        ");
        $activeRoundStmt->execute([$projectId]);
        $activeRound = $activeRoundStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $totalStmt = $db->prepare("SELECT COUNT(*) FROM issues WHERE project_id = ?");
        $totalStmt->execute([$projectId]);
        $issuesTotal = (int)$totalStmt->fetchColumn();

        $newIssuesInRoundTotal = 0;
        if ($activeRound && !empty($activeRound['started_at'])) {
            $newIssuesStmt = $db->prepare("\n                SELECT COUNT(*)\n                FROM issues\n                WHERE project_id = ?\n                  AND created_at >= ?\n            ");
            $newIssuesStmt->execute([$projectId, $activeRound['started_at']]);
            $newIssuesInRoundTotal = (int)$newIssuesStmt->fetchColumn();
        }

        if ($activeRound && !empty($activeRound['started_at'])) {
            $regressionIssueCountStmt = $db->prepare("\n                SELECT COUNT(DISTINCT r.issue_id)\n                FROM (\n                    SELECT ic.issue_id\n                    FROM issue_comments ic\n                    INNER JOIN issues i1 ON i1.id = ic.issue_id\n                    WHERE i1.project_id = ?\n                      AND ic.comment_type = 'regression'\n                    UNION\n                    SELECT i2.id AS issue_id\n                    FROM issues i2\n                    WHERE i2.project_id = ?\n                      AND i2.created_at >= ?\n                ) r\n            ");
            $regressionIssueCountStmt->execute([$projectId, $projectId, $activeRound['started_at']]);

            $statusStmt = $db->prepare("\n                SELECT COALESCE(s.name, 'Unknown') AS status_name, COUNT(DISTINCT i.id) AS cnt\n                FROM issues i\n                LEFT JOIN issue_statuses s ON s.id = i.status_id\n                WHERE i.project_id = ?\n                  AND i.id IN (\n                      SELECT ic2.issue_id\n                      FROM issue_comments ic2\n                      INNER JOIN issues i3 ON i3.id = ic2.issue_id\n                      WHERE i3.project_id = ?\n                        AND ic2.comment_type = 'regression'\n                      UNION\n                      SELECT i4.id\n                      FROM issues i4\n                      WHERE i4.project_id = ?\n                        AND i4.created_at >= ?\n                  )\n                GROUP BY COALESCE(s.name, 'Unknown')\n                ORDER BY cnt DESC\n            ");
            $statusStmt->execute([$projectId, $projectId, $projectId, $activeRound['started_at']]);
        } else {
            $regressionIssueCountStmt = $db->prepare("\n                SELECT COUNT(DISTINCT ic.issue_id)\n                FROM issue_comments ic\n                INNER JOIN issues i ON i.id = ic.issue_id\n                WHERE i.project_id = ?\n                  AND ic.comment_type = 'regression'\n            ");
            $regressionIssueCountStmt->execute([$projectId]);

            $statusStmt = $db->prepare("\n                SELECT COALESCE(s.name, 'Unknown') AS status_name, COUNT(DISTINCT i.id) AS cnt\n                FROM issues i\n                INNER JOIN issue_comments ic ON ic.issue_id = i.id AND ic.comment_type = 'regression'\n                LEFT JOIN issue_statuses s ON s.id = i.status_id\n                WHERE i.project_id = ?\n                GROUP BY COALESCE(s.name, 'Unknown')\n                ORDER BY cnt DESC\n            ");
            $statusStmt->execute([$projectId]);
        }

        $regressionIssuesTotal = (int)$regressionIssueCountStmt->fetchColumn();
        $statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
        $attemptedStatusCounts = [];
        foreach ($statusRows as $row) {
            $attemptedStatusCounts[$row['status_name']] = (int)$row['cnt'];
        }

        echo json_encode([
            'success' => true,
            'issues_total' => $issuesTotal,
            'attempted_issues_total' => $regressionIssuesTotal,
            'regression_issues_total' => $regressionIssuesTotal,
            'attempted_status_counts' => $attemptedStatusCounts,
            'new_issues_in_round_total' => $newIssuesInRoundTotal,
            'active_round' => $activeRound ? [
                'id' => (int)($activeRound['id'] ?? 0),
                'round_number' => (int)($activeRound['round_number'] ?? 0),
                'started_at' => $activeRound['started_at'] ?? null,
            ] : null,
        ]);
        exit;
    }

    if ($action === 'list_rounds') {
        $stmt = $db->prepare("\n            SELECT rr.id, rr.round_number, rr.status, rr.is_active,\n                   rr.start_date, rr.end_date, rr.started_at, rr.ended_at,\n                   u.full_name AS started_by_name\n            FROM regression_rounds rr\n            LEFT JOIN users u ON rr.started_by = u.id\n            WHERE rr.project_id = ?\n            ORDER BY rr.round_number DESC\n        ");
        $stmt->execute([$projectId]);
        echo json_encode(['success' => true, 'rounds' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'create_round') {
        $allowedRoles = ['admin', 'project_lead', 'qa'];
        if (!in_array($userRole, $allowedRoles, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only admin, project_lead, or qa can create regression rounds']);
            exit;
        }

        $activeCheckStmt = $db->prepare("\n            SELECT id, round_number\n            FROM regression_rounds\n            WHERE project_id = ?\n              AND status = 'in_progress'\n              AND is_active = 1\n            ORDER BY round_number DESC\n            LIMIT 1\n        ");
        $activeCheckStmt->execute([$projectId]);
        $existingActiveRound = $activeCheckStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingActiveRound) {
            echo json_encode([
                'success' => false,
                'error' => 'Round ' . (int)$existingActiveRound['round_number'] . ' is already in progress. Complete it first.'
            ]);
            exit;
        }

        $numStmt = $db->prepare("SELECT COALESCE(MAX(round_number), 0) + 1 FROM regression_rounds WHERE project_id = ?");
        $numStmt->execute([$projectId]);
        $nextRound = (int)$numStmt->fetchColumn();

        $insertStmt = $db->prepare("\n            INSERT INTO regression_rounds (project_id, started_by, round_number, start_date, status, is_active)\n            VALUES (?, ?, ?, CURDATE(), 'in_progress', 1)\n        ");
        $insertStmt->execute([$projectId, $userId, $nextRound]);

        echo json_encode([
            'success' => true,
            'id' => (int)$db->lastInsertId(),
            'round_number' => $nextRound
        ]);
        exit;
    }

    if ($action === 'complete_round') {
        $allowedRoles = ['admin', 'project_lead', 'qa'];
        if (!in_array($userRole, $allowedRoles, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $roundId = (int)($_POST['round_id'] ?? 0);
        if ($roundId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid round_id']);
            exit;
        }

        $checkStmt = $db->prepare("SELECT id FROM regression_rounds WHERE id = ? AND project_id = ?");
        $checkStmt->execute([$roundId, $projectId]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Round not found']);
            exit;
        }

        $db->prepare("\n            UPDATE regression_rounds\n            SET status = 'completed', is_active = 0, ended_at = NOW(), end_date = CURDATE()\n            WHERE id = ?\n        ")->execute([$roundId]);

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);
} catch (Throwable $e) {
    error_log('regression_actions.php error: ' . $e->getMessage());
    $canSeeDetails = in_array($userRole, ['admin', 'project_lead'], true);
    $message = $canSeeDetails ? ('Server error: ' . $e->getMessage()) : 'Server error';
    echo json_encode(['success' => false, 'error' => $message]);
}
