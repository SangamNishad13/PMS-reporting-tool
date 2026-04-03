<?php
require_once __DIR__ . '/../config/database.php';

class PerformanceHelper {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Aggregates performance stats for a specific user or all users.
     */
    public function getResourceStats($userId = null, $projectId = null, $startDate = null, $endDate = null) {
        $stats = [];
        if (!$startDate) $startDate = date('Y-m-d', strtotime('-30 days'));
        if (!$endDate) $endDate = date('Y-m-d');

        $params = [];
        if ($userId) {
            $usersQuery = "SELECT id, full_name, email, role FROM users WHERE id = :user_id";
            $params[':user_id'] = $userId;
        } else if ($projectId) {
            $usersQuery = "SELECT DISTINCT u.id, u.full_name, u.email, u.role 
                           FROM users u
                           JOIN project_pages pp ON (pp.at_tester_id = u.id OR pp.ft_tester_id = u.id OR pp.qa_id = u.id 
                                OR (pp.at_tester_ids IS NOT NULL AND JSON_VALID(pp.at_tester_ids) AND JSON_CONTAINS(pp.at_tester_ids, JSON_ARRAY(u.id)))
                                OR (pp.ft_tester_ids IS NOT NULL AND JSON_VALID(pp.ft_tester_ids) AND JSON_CONTAINS(pp.ft_tester_ids, JSON_ARRAY(u.id))))
                           WHERE pp.project_id = :project_id AND u.is_active = 1";
            $params[':project_id'] = $projectId;
        } else {
            $usersQuery = "SELECT id, full_name, email, role FROM users WHERE role != 'admin' AND is_active = 1";
        }

        $stmt = $this->db->prepare($usersQuery);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($users)) return [];

        // If analyzing a single user, use the detailed per-user logic (fast)
        if ($userId) {
            $user = $users[0];
            return [[
                'user_id' => $user['id'],
                'name' => $user['full_name'],
                'role' => $user['role'],
                'stats' => [
                    'accuracy' => $this->getFindingStats($user['id'], $projectId, $startDate, $endDate),
                    'communication' => [
                        'total_comments' => count($this->getUserComments($user['id'], $projectId, $startDate, $endDate)),
                        'recent_samples' => $this->getUserComments($user['id'], $projectId, $startDate, $endDate)
                    ],
                    'activity' => ['total_actions' => $this->getActivityCount($user['id'], $projectId, $startDate, $endDate)]
                ]
            ]];
        }

        // BATCH MODE: Handle all users in 3 grouped queries
        $userIds = array_column($users, 'id');
        $idPlaceholder = implode(',', array_fill(0, count($userIds), '?'));

        // 1. Batch Accuracy
        $accSql = "SELECT created_by as user_id, COUNT(*) as total, 
                   SUM(CASE WHEN updated_at > DATE_ADD(created_at, INTERVAL 1 MINUTE) THEN 1 ELSE 0 END) as corrected 
                   FROM automated_a11y_findings 
                   WHERE created_by IN ($idPlaceholder)";
        $accParams = $userIds;
        if ($projectId) {
            $accSql .= " AND project_id = ?";
            $accParams[] = $projectId;
        }
        $accSql .= " AND DATE(created_at) BETWEEN ? AND ? GROUP BY created_by";
        $accParams[] = $startDate;
        $accParams[] = $endDate;
        
        $accStmt = $this->db->prepare($accSql);
        $accStmt->execute($accParams);
        $accLookup = $accStmt->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

        // 2. Batch Activity
        $actSql = "SELECT user_id, COUNT(*) FROM activity_log WHERE user_id IN ($idPlaceholder)";
        $actParams = $userIds;
        if ($projectId) {
            $actSql .= " AND entity_type = 'project' AND entity_id = ?";
            $actParams[] = $projectId;
        }
        $actSql .= " AND DATE(created_at) BETWEEN ? AND ? GROUP BY user_id";
        $actParams[] = $startDate;
        $actParams[] = $endDate;
        
        $actStmt = $this->db->prepare($actSql);
        $actStmt->execute($actParams);
        $actLookup = $actStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // 3. Batch Communication
        $comSql = "SELECT ic.user_id, COUNT(*) FROM issue_comments ic 
                   LEFT JOIN issues i ON ic.issue_id = i.id 
                   WHERE ic.user_id IN ($idPlaceholder)";
        $comParams = $userIds;
        if ($projectId) {
            $comSql .= " AND i.project_id = ?";
            $comParams[] = $projectId;
        }
        $comSql .= " AND DATE(ic.created_at) BETWEEN ? AND ? GROUP BY ic.user_id";
        $comParams[] = $startDate;
        $comParams[] = $endDate;

        $comStmt = $this->db->prepare($comSql);
        $comStmt->execute($comParams);
        $comLookup = $comStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Merge Everything
        foreach ($users as $user) {
            $uId = $user['id'];
            $a = $accLookup[$uId] ?? ['total' => 0, 'corrected' => 0];
            $accTotal = (int)$a['total'];
            $accCorrected = (int)$a['corrected'];
            $accuracy = $accTotal > 0 ? round((($accTotal - $accCorrected) / $accTotal) * 100, 2) : 100;

            $stats[] = [
                'user_id' => $uId,
                'name' => $user['full_name'],
                'role' => $user['role'],
                'stats' => [
                    'accuracy' => [
                        'total_findings' => $accTotal,
                        'corrected_count' => $accCorrected,
                        'accuracy_percentage' => $accuracy
                    ],
                    'communication' => [
                        'total_comments' => (int)($comLookup[$uId] ?? 0),
                        'recent_samples' => [] // Skip samples for batch listing
                    ],
                    'activity' => [
                        'total_actions' => (int)($actLookup[$uId] ?? 0)
                    ]
                ]
            ];
        }

        return $stats;
    }

    private function getFindingStats($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "SELECT COUNT(*) as total, 
                SUM(CASE WHEN updated_at > DATE_ADD(created_at, INTERVAL 1 MINUTE) THEN 1 ELSE 0 END) as corrected 
                FROM automated_a11y_findings 
                WHERE created_by = :user_id";
        
        $params = [':user_id' => $userId];
        
        if ($projectId) {
            $sql .= " AND project_id = :project_id";
            $params[':project_id'] = $projectId;
        }

        if ($startDate && $endDate) {
            $sql .= " AND DATE(created_at) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = (int)($res['total'] ?? 0);
            $corrected = (int)($res['corrected'] ?? 0);
            $accuracy = $total > 0 ? round((($total - $corrected) / $total) * 100, 2) : 100;
            
            return [
                'total_findings' => $total,
                'corrected_count' => $corrected,
                'accuracy_percentage' => $accuracy
            ];
        } catch (Exception $e) {
            return ['total_findings' => 0, 'corrected_count' => 0, 'accuracy_percentage' => 100];
        }
    }

    private function getUserComments($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "SELECT ic.comment_html as text, ic.created_at 
                FROM issue_comments ic 
                LEFT JOIN issues i ON ic.issue_id = i.id 
                WHERE ic.user_id = :user_id";
        $params = [':user_id' => $userId];
        
        if ($projectId) {
            $sql .= " AND i.project_id = :project_id";
            $params[':project_id'] = $projectId;
        }

        if ($startDate && $endDate) {
            $sql .= " AND DATE(ic.created_at) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }
        
        $sql .= " ORDER BY ic.created_at DESC LIMIT 20";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    private function getActivityCount($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "SELECT COUNT(*) FROM activity_log WHERE user_id = :user_id";
        $params = [':user_id' => $userId];
        
        if ($projectId) {
            $sql .= " AND entity_type = 'project' AND entity_id = :project_id";
            $params[':project_id'] = $projectId;
        }

        if ($startDate && $endDate) {
            $sql .= " AND DATE(created_at) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}
