<?php
/**
 * ClientAccessControlManager
 * Manages client access control, project assignments, and permission validation
 * Implements hasProjectAccess() and getAssignedProjects() with active assignment filtering
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/redis.php';
require_once __DIR__ . '/WCAGComplianceAnalytics.php';
require_once __DIR__ . '/ComplianceTrendAnalytics.php';

class ClientAccessControlManager {
    private $db;
    private $redis;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->redis = RedisConfig::getInstance();
    }
    
    /**
     * Check if client user has access to a specific project
     * @param int $clientUserId Client user ID
     * @param int $projectId Project ID to check access for
     * @return bool True if client has access, false otherwise
     */
    public function hasProjectAccess($clientUserId, $projectId) {
        // Validate inputs
        if (!$clientUserId || !$projectId) {
            return false;
        }
        
        // Check cache first
        $cacheKey = "client_access_{$clientUserId}_{$projectId}";
        if ($this->redis->isAvailable()) {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                return (bool)$cached;
            }
        }
        
        try {
            // Check if user is active
            $stmt = $this->db->prepare("
                SELECT role FROM users 
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$clientUserId]);
            $dbRole = $stmt->fetchColumn();
            
            if (!$dbRole) {
                return false;
            }
            
            // Check primary assignments table first
            $hasAccess = false;
            try {
                $stmt = $this->db->prepare("
                    SELECT 1 
                    FROM client_project_assignments cpa
                    INNER JOIN projects p ON cpa.project_id = p.id
                    WHERE cpa.client_user_id = ?
                    AND cpa.project_id = ?
                    AND cpa.is_active = 1
                    AND (cpa.expires_at IS NULL OR cpa.expires_at > NOW())
                    AND p.status NOT IN ('cancelled', 'archived')
                    LIMIT 1
                ");
                $stmt->execute([$clientUserId, $projectId]);
                if ($stmt->fetchColumn() !== false) {
                    $hasAccess = true;
                }
            } catch (Exception $e) {
                error_log('ClientAccessControlManager hasProjectAccess (cpa) error: ' . $e->getMessage());
            }

            // Also check legacy client_permissions table if not found yet
            if (!$hasAccess) {
                try {
                    $stmt = $this->db->prepare("
                        SELECT 1 
                        FROM client_permissions cp
                        INNER JOIN projects p ON cp.project_id = p.id
                        WHERE cp.user_id = ?
                        AND cp.project_id = ?
                        AND cp.is_active = 1
                        AND (cp.expires_at IS NULL OR cp.expires_at > NOW())
                        AND p.status NOT IN ('cancelled', 'archived')
                        LIMIT 1
                    ");
                    $stmt->execute([$clientUserId, $projectId]);
                    if ($stmt->fetchColumn() !== false) {
                        $hasAccess = true;
                    }
                } catch (Exception $e) {
                    error_log('ClientAccessControlManager hasProjectAccess (cp) error: ' . $e->getMessage());
                }
            }
            
            // Cache result for 5 minutes
            if ($this->redis->isAvailable()) {
                $this->redis->set($cacheKey, $hasAccess, 300);
            }
            
            return $hasAccess;
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager hasProjectAccess error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all projects assigned to a client user
     * @param int $clientUserId Client user ID
     * @return array Array of assigned projects with details
     */
    public function getAssignedProjects($clientUserId) {
        // Validate input
        if (!$clientUserId) {
            return [];
        }
        
        // Check cache first
        $cacheKey = "client_projects_{$clientUserId}";
        if ($this->redis->isAvailable()) {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        try {
            // Check if user is active
            $stmt = $this->db->prepare("
                SELECT role FROM users 
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$clientUserId]);
            $dbRole = $stmt->fetchColumn();
            
            if (!$dbRole) {
                return [];
            }
            
            // First try to get projects from client_project_assignments
            $projects = [];
            try {
                $stmt = $this->db->prepare("
                    SELECT 
                        p.id,
                        p.po_number,
                        p.project_code,
                        p.title,
                        p.description,
                        p.project_type,
                        p.priority,
                        p.status,
                        p.created_at,
                        p.completed_at,
                        p.client_id,
                        c.name as client_name,
                        cpa.assigned_at,
                        cpa.expires_at,
                        cpa.notes as assignment_notes,
                        admin.full_name as assigned_by_name,
                        (SELECT COUNT(*) FROM issues i 
                         WHERE i.project_id = p.id AND i.client_ready = 1) as client_ready_issues_count,
                        (SELECT COUNT(*) FROM issues i 
                         WHERE i.project_id = p.id) as total_issues_count
                    FROM client_project_assignments cpa
                    INNER JOIN projects p ON cpa.project_id = p.id
                    LEFT JOIN clients c ON p.client_id = c.id
                    LEFT JOIN users admin ON cpa.assigned_by_admin_id = admin.id
                    WHERE cpa.client_user_id = ?
                    AND cpa.is_active = 1
                    AND (cpa.expires_at IS NULL OR cpa.expires_at > NOW())
                    AND p.status NOT IN ('cancelled', 'archived')
                    ORDER BY cpa.assigned_at DESC, p.title ASC
                ");
                $stmt->execute([$clientUserId]);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Table may not exist on all installations - fall back to legacy table only
                error_log('ClientAccessControlManager getAssignedProjects (cpa) error: ' . $e->getMessage());
                $projects = [];
            }
            
            // Also get projects from legacy client_permissions table
            try {
                $legacyStmt = $this->db->prepare("
                    SELECT 
                        p.id,
                        p.po_number,
                        p.project_code,
                        p.title,
                        p.description,
                        p.project_type,
                        p.priority,
                        p.status,
                        p.created_at,
                        p.completed_at,
                        p.client_id,
                        c.name as client_name,
                        cp.created_at as assigned_at,
                        cp.expires_at,
                        '' as assignment_notes,
                        NULL as assigned_by_name,
                        (SELECT COUNT(*) FROM issues i 
                         WHERE i.project_id = p.id AND i.client_ready = 1) as client_ready_issues_count,
                        (SELECT COUNT(*) FROM issues i 
                         WHERE i.project_id = p.id) as total_issues_count
                    FROM client_permissions cp
                    INNER JOIN projects p ON cp.project_id = p.id
                    LEFT JOIN clients c ON p.client_id = c.id
                    WHERE cp.user_id = ?
                    AND cp.project_id IS NOT NULL
                    AND cp.is_active = 1
                    AND (cp.expires_at IS NULL OR cp.expires_at > NOW())
                    AND p.status NOT IN ('cancelled', 'archived')
                    ORDER BY cp.created_at DESC, p.title ASC
                ");
                $legacyStmt->execute([$clientUserId]);
                $legacyProjects = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Merge: add legacy projects not already in the list
                $existingIds = array_column($projects, 'id');
                foreach ($legacyProjects as $lp) {
                    if (!in_array($lp['id'], $existingIds)) {
                        $projects[] = $lp;
                        $existingIds[] = $lp['id'];
                    }
                }
            } catch (Exception $legacyEx) {
                // client_permissions table may not have expected columns - ignore
                error_log('ClientAccessControlManager legacy permissions lookup error: ' . $legacyEx->getMessage());
            }
            
            // Process and enrich project data
            $enrichedProjects = [];
            foreach ($projects as $project) {
                $enrichedProjects[] = [
                    'id' => (int)$project['id'],
                    'po_number' => $project['po_number'],
                    'project_code' => $project['project_code'],
                    'title' => $project['title'],
                    'description' => $project['description'],
                    'project_type' => $project['project_type'],
                    'priority' => $project['priority'],
                    'status' => $project['status'],
                    'client_id' => (int)$project['client_id'],
                    'client_name' => $project['client_name'],
                    'assigned_at' => $project['assigned_at'],
                    'expires_at' => $project['expires_at'],
                    'assignment_notes' => $project['assignment_notes'],
                    'assigned_by_name' => $project['assigned_by_name'],
                    'client_ready_issues_count' => (int)$project['client_ready_issues_count'],
                    'total_issues_count' => (int)$project['total_issues_count'],
                    'created_at' => $project['created_at'],
                    'completed_at' => $project['completed_at']
                ];
            }
            
            // Cache result for 10 minutes
            if ($this->redis->isAvailable()) {
                $this->redis->set($cacheKey, $enrichedProjects, 600);
            }
            
            return $enrichedProjects;
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager getAssignedProjects error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Filter issues to show only client-ready ones
     * @param array $issues Array of issues to filter
     * @return array Filtered array containing only client-ready issues
     */
    public function filterClientReadyIssues($issues) {
        if (!is_array($issues)) {
            return [];
        }
        
        return array_filter($issues, function($issue) {
            // Handle both array and object formats
            if (is_array($issue)) {
                return isset($issue['client_ready']) && $issue['client_ready'] == 1;
            } elseif (is_object($issue)) {
                return isset($issue->client_ready) && $issue->client_ready == 1;
            }
            return false;
        });
    }
    
    /**
     * Get client-ready issues for specific projects
     * @param int $clientUserId Client user ID
     * @param array $projectIds Array of project IDs (optional, gets all if empty)
     * @return array Array of client-ready issues
     */
    public function getClientReadyIssues($clientUserId, $projectIds = []) {
        // Validate input
        if (!$clientUserId) {
            return [];
        }
        
        try {
            // Get assigned projects if no specific projects provided
            if (empty($projectIds)) {
                $assignedProjects = $this->getAssignedProjects($clientUserId);
                $projectIds = array_column($assignedProjects, 'id');
            } else {
                // Validate client has access to all requested projects
                foreach ($projectIds as $projectId) {
                    if (!$this->hasProjectAccess($clientUserId, $projectId)) {
                        error_log("Client $clientUserId attempted to access unauthorized project $projectId");
                        return [];
                    }
                }
            }
            
            if (empty($projectIds)) {
                return [];
            }
            
            // Build query with placeholders
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            
            $stmt = $this->db->prepare("
                SELECT 
                    i.id,
                    i.project_id,
                    i.issue_key,
                    i.title,
                    i.description,
                    i.severity,
                    i.created_at,
                    i.updated_at,
                    i.resolved_at,
                    p.title as project_title,
                    p.project_code,
                    s.name as status_name,
                    s.color as status_color,
                    pr.name as priority_name,
                    reporter.full_name as reporter_name,
                    -- Get metadata
                    GROUP_CONCAT(DISTINCT CONCAT(im.meta_key, ':', im.meta_value) SEPARATOR '|') as metadata,
                    -- Count comments
                    (SELECT COUNT(*) FROM issue_comments ic WHERE ic.issue_id = i.id) as comment_count
                FROM issues i
                INNER JOIN projects p ON i.project_id = p.id
                LEFT JOIN issue_statuses s ON i.status_id = s.id
                LEFT JOIN issue_priorities pr ON i.priority_id = pr.id
                LEFT JOIN users reporter ON i.reporter_id = reporter.id
                LEFT JOIN issue_metadata im ON i.id = im.issue_id
                WHERE i.project_id IN ($placeholders)
                AND i.client_ready = 1
                GROUP BY i.id
                ORDER BY i.created_at DESC
            ");
            
            $stmt->execute($projectIds);
            $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process metadata
            foreach ($issues as &$issue) {
                $issue['metadata'] = $this->parseMetadata($issue['metadata']);
                $issue['comment_count'] = (int)$issue['comment_count'];
            }
            
            return $issues;
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager getClientReadyIssues error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if client can access specific issue
     * @param int $clientUserId Client user ID
     * @param int $issueId Issue ID to check
     * @return bool True if client can access the issue
     */
    public function canAccessIssue($clientUserId, $issueId) {
        if (!$clientUserId || !$issueId) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT i.project_id, i.client_ready
                FROM issues i
                WHERE i.id = ?
            ");
            $stmt->execute([$issueId]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$issue || $issue['client_ready'] != 1) {
                return false;
            }
            
            return $this->hasProjectAccess($clientUserId, $issue['project_id']);
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager canAccessIssue error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalidate cache for client access
     * @param int $clientUserId Client user ID
     * @param int $projectId Optional specific project ID
     */
    public function invalidateCache($clientUserId, $projectId = null) {
        if (!$this->redis->isAvailable()) {
            return;
        }
        
        try {
            // Clear project list cache
            $this->redis->delete("client_projects_{$clientUserId}");
            
            if ($projectId) {
                // Clear specific project access cache
                $this->redis->delete("client_access_{$clientUserId}_{$projectId}");
            } else {
                // Clear all project access cache for this client
                $keys = $this->redis->keys("client_access_{$clientUserId}_*");
                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        $this->redis->delete($key);
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager invalidateCache error: ' . $e->getMessage());
        }
    }
    
    /**
     * Log client access attempt
     * @param int $clientUserId Client user ID
     * @param string $resourceType Type of resource accessed
     * @param int $resourceId ID of resource accessed
     * @param bool $success Whether access was granted
     * @param string $details Additional details
     */
    public function logAccess($clientUserId, $resourceType, $resourceId, $success = true, $details = '') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log 
                (client_user_id, action_type, resource_type, resource_id, action_details, 
                 ip_address, user_agent, success, created_at)
                VALUES (?, 'access_check', ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $actionDetails = json_encode([
                'details' => $details,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $clientUserId,
                $resourceType,
                $resourceId,
                $actionDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $success ? 1 : 0
            ]);
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager logAccess error: ' . $e->getMessage());
        }
    }
    
    /**
     * Parse metadata string into associative array
     * @param string $metadataString Pipe-separated metadata string
     * @return array Parsed metadata
     */
    private function parseMetadata($metadataString) {
        $metadata = [];
        
        if (empty($metadataString)) {
            return $metadata;
        }
        
        $pairs = explode('|', $metadataString);
        foreach ($pairs as $pair) {
            if (strpos($pair, ':') !== false) {
                list($key, $value) = explode(':', $pair, 2);
                $metadata[trim($key)] = trim($value);
            }
        }
        
        return $metadata;
    }
    
    /**
     * Get project statistics for client dashboard
     * @param int $clientUserId Client user ID
     * @return array Project statistics
     */
    public function getProjectStatistics($clientUserId, $projectId = null) {
        $assignedProjects = $this->getAssignedProjects($clientUserId);
        
        // If specific project requested, filter the list
        if ($projectId !== null) {
            $assignedProjects = array_filter($assignedProjects, function($p) use ($projectId) {
                return $p['id'] == $projectId;
            });
        }
        
        if (empty($assignedProjects)) {
            return [
                'total_projects' => 0,
                'total_issues' => 0,
                'client_ready_issues' => 0,
                'open_issues' => 0,
                'resolved_issues' => 0,
                'compliance_score' => 0,
                'projects_by_status' => [],
                'issues_by_severity' => []
            ];
        }
        
        $projectIds = array_column($assignedProjects, 'id');
        
        try {
            // Get project status distribution
            $projectsByStatus = [];
            foreach ($assignedProjects as $project) {
                $status = $project['status'] ?? 'Unknown';
                if (is_array($status)) {
                    $status = 'Multiple';
                }
                $projectsByStatus[(string)$status] = ($projectsByStatus[(string)$status] ?? 0) + 1;
            }
            
            // Get issue severity distribution
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $stmt = $this->db->prepare("
                SELECT 
                    i.severity,
                    COUNT(*) as count
                FROM issues i
                WHERE i.project_id IN ($placeholders)
                AND i.client_ready = 1
                GROUP BY i.severity
            ");
            $stmt->execute($projectIds);
            $issuesBySeverity = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $issuesBySeverity[$row['severity']] = (int)$row['count'];
            }

            $statusStmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as client_ready_issues,
                    COUNT(CASE WHEN LOWER(COALESCE(ist.name, '')) IN ('open', 'in progress', 'reopened', 'in_progress') THEN 1 END) as open_issues,
                    COUNT(CASE WHEN LOWER(COALESCE(ist.name, '')) IN ('resolved', 'closed', 'fixed') THEN 1 END) as resolved_issues
                FROM issues i
                LEFT JOIN issue_statuses ist ON i.status_id = ist.id
                WHERE i.project_id IN ($placeholders)
                AND i.client_ready = 1
            ");
            $statusStmt->execute($projectIds);
            $statusCounts = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $clientReadyIssues = (int) ($statusCounts['client_ready_issues'] ?? 0);
            $openIssues = (int) ($statusCounts['open_issues'] ?? 0);
            $resolvedIssues = (int) ($statusCounts['resolved_issues'] ?? 0);

            $analyticsProjectId = count($projectIds) === 1 ? (int) reset($projectIds) : null;
            $complianceScore = 0;

            try {
                $wcagAnalytics = new WCAGComplianceAnalytics();
                $wcagReport = $wcagAnalytics->generateReport($analyticsProjectId, $clientUserId);
                $wcagData = $wcagReport ? $wcagReport->getData() : [];
                $complianceScore = round((float) ($wcagData['summary']['overall_compliance_score'] ?? 0), 1);
            } catch (Exception $analyticsException) {
                error_log('ClientAccessControlManager WCAG compliance stats error: ' . $analyticsException->getMessage());
            }

            if ($complianceScore == 0) {
                try {
                    $trendAnalytics = new ComplianceTrendAnalytics();
                    $trendReport = $trendAnalytics->generateReport($analyticsProjectId, $clientUserId);
                    $trendData = $trendReport ? $trendReport->getData() : [];
                    $complianceScore = round((float) ($trendData['summary']['overall_resolution_rate'] ?? 0), 1);
                } catch (Exception $trendException) {
                    error_log('ClientAccessControlManager compliance trend stats error: ' . $trendException->getMessage());
                }
            }
            
            return [
                'total_projects' => count($assignedProjects),
                'total_issues' => array_sum(array_column($assignedProjects, 'total_issues_count')),
                'client_ready_issues' => $clientReadyIssues,
                'open_issues' => $openIssues,
                'resolved_issues' => $resolvedIssues,
                'compliance_score' => $complianceScore,
                'projects_by_status' => $projectsByStatus,
                'issues_by_severity' => $issuesBySeverity
            ];
            
        } catch (Exception $e) {
            error_log('ClientAccessControlManager getProjectStatistics error: ' . $e->getMessage());
            return [
                'total_projects' => count($assignedProjects),
                'total_issues' => 0,
                'client_ready_issues' => 0,
                'open_issues' => 0,
                'resolved_issues' => 0,
                'compliance_score' => 0,
                'projects_by_status' => [],
                'issues_by_severity' => []
            ];
        }
    }
}