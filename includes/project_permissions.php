<?php
/**
 * Project-Specific Permissions Helper
 * This file contains functions to check and manage project-specific permissions
 */

/**
 * Check if a user has a specific permission for a project
 */
function hasProjectPermission($db, $userId, $projectId, $permission) {
    try {
        // Admin and super_admin always have all permissions
        $userStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        
        if ($user && in_array($user['role'], ['admin', 'super_admin'])) {
            return true;
        }
        
        // Check if user is project lead (has most permissions)
        $projectStmt = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ?");
        $projectStmt->execute([$projectId]);
        $project = $projectStmt->fetch();
        
        if ($project && $project['project_lead_id'] == $userId) {
            // Project leads have most permissions except project deletion and advanced settings
            $restrictedPermissions = ['project_delete', 'project_settings'];
            if (!in_array($permission, $restrictedPermissions)) {
                return true;
            }
        }
        
        // Check team assignments for basic permissions
        $teamStmt = $db->prepare("SELECT role FROM user_assignments WHERE project_id = ? AND user_id = ? AND (is_removed IS NULL OR is_removed = 0)");
        $teamStmt->execute([$projectId, $userId]);
        $teamRole = $teamStmt->fetch();
        
        if ($teamRole) {
            $basicPermissions = getBasicPermissionsForRole($teamRole['role']);
            if (in_array($permission, $basicPermissions)) {
                return true;
            }
        }
        
        // Check project-specific permissions
        $permStmt = $db->prepare("
            SELECT id FROM project_permissions 
            WHERE project_id = ? AND user_id = ? AND permission_type = ? 
            AND is_active = 1 
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $permStmt->execute([$projectId, $userId, $permission]);
        
        return $permStmt->fetch() !== false;
        
    } catch (PDOException $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get basic permissions for team roles
 */
function getBasicPermissionsForRole($role) {
    $rolePermissions = [
        'qa' => [
            'project_view', 'pages_view', 'status_view', 'qa_status_update', 
            'team_view', 'assets_view', 'phases_view', 'chat_view', 'chat_send',
            'feedback_view', 'feedback_submit', 'activity_log_view'
        ],
        'at_tester' => [
            'project_view', 'pages_view', 'status_update', 'status_view',
            'team_view', 'assets_view', 'phases_view', 'chat_view', 'chat_send',
            'feedback_submit', 'activity_log_view'
        ],
        'ft_tester' => [
            'project_view', 'pages_view', 'status_update', 'status_view',
            'team_view', 'assets_view', 'phases_view', 'chat_view', 'chat_send',
            'feedback_submit', 'activity_log_view'
        ],
        'project_lead' => [
            'project_view', 'project_edit', 'project_duplicate',
            'pages_view', 'pages_create', 'pages_edit', 'pages_assign',
            'status_update', 'status_view', 'qa_status_update',
            'team_view', 'team_assign', 'team_remove', 'team_manage_roles',
            'assets_view', 'assets_upload', 'assets_edit', 'assets_delete',
            'phases_view', 'phases_edit', 'phases_create',
            'chat_view', 'chat_send', 'feedback_view', 'feedback_submit',
            'reports_view', 'activity_log_view', 'bulk_operations'
        ]
    ];
    
    return $rolePermissions[$role] ?? [];
}

/**
 * Check if user has any of the specified permissions for a project
 */
function hasAnyProjectPermission($db, $userId, $projectId, $permissions) {
    foreach ($permissions as $permission) {
        if (hasProjectPermission($db, $userId, $projectId, $permission)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if a project lead has resource workload access
 */
function hasResourceWorkloadAccess($db, $userId) {
    try {
        // Admin and super_admin always have access
        $userStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        
        if ($user && in_array($user['role'], ['admin', 'super_admin'])) {
            return true;
        }
        
        // For project leads, check explicit permission
        if ($user && $user['role'] === 'project_lead') {
            $permissionStmt = $db->prepare("
                SELECT COUNT(*) as has_permission
                FROM project_permissions pp
                WHERE pp.user_id = ? 
                AND pp.permission_type = 'resource_workload_access'
                AND pp.is_active = 1
                AND (pp.expires_at IS NULL OR pp.expires_at > NOW())
            ");
            $permissionStmt->execute([$userId]);
            return $permissionStmt->fetchColumn() > 0;
        }
        
        return false;
        
    } catch (PDOException $e) {
        error_log("Resource workload access check error: " . $e->getMessage());
        return false;
    }
}

/**
function hasAnyProjectPermission($db, $userId, $projectId, $permissions) {
    foreach ($permissions as $permission) {
        if (hasProjectPermission($db, $userId, $projectId, $permission)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user has all of the specified permissions for a project
 */
function hasAllProjectPermissions($db, $userId, $projectId, $permissions) {
    foreach ($permissions as $permission) {
        if (!hasProjectPermission($db, $userId, $projectId, $permission)) {
            return false;
        }
    }
    return true;
}

/**
 * Get all permissions a user has for a specific project
 */
function getUserProjectPermissions($db, $userId, $projectId) {
    try {
        // Admin and super_admin have all permissions
        $userStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        
        if ($user && in_array($user['role'], ['admin', 'super_admin'])) {
            // Return all available permissions
            $allPermsStmt = $db->query("SELECT permission_type FROM project_permissions_types WHERE is_active = 1");
            return $allPermsStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $permissions = [];
        
        // Check if user is project lead
        $projectStmt = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ?");
        $projectStmt->execute([$projectId]);
        $project = $projectStmt->fetch();
        
        if ($project && $project['project_lead_id'] == $userId) {
            // Project leads get most permissions
            $leadPermsStmt = $db->query("
                SELECT permission_type FROM project_permissions_types 
                WHERE is_active = 1 AND permission_type NOT IN ('project_delete', 'project_settings')
            ");
            $permissions = array_merge($permissions, $leadPermsStmt->fetchAll(PDO::FETCH_COLUMN));
        }
        
        // Check team assignments for basic permissions
        $teamStmt = $db->prepare("SELECT role FROM user_assignments WHERE project_id = ? AND user_id = ? AND (is_removed IS NULL OR is_removed = 0)");
        $teamStmt->execute([$projectId, $userId]);
        $teamRole = $teamStmt->fetch();
        
        if ($teamRole) {
            $basicPermissions = getBasicPermissionsForRole($teamRole['role']);
            $permissions = array_merge($permissions, $basicPermissions);
        }
        
        // Get project-specific permissions
        $permStmt = $db->prepare("
            SELECT permission_type FROM project_permissions 
            WHERE project_id = ? AND user_id = ? AND is_active = 1 
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $permStmt->execute([$projectId, $userId]);
        $specificPerms = $permStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $permissions = array_merge($permissions, $specificPerms);
        
        return array_unique($permissions);
        
    } catch (PDOException $e) {
        error_log("Get permissions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if user has access to a project (any permission or team membership)
 */
function hasProjectAccess($db, $userId, $projectId) {
    try {
        // Admin and super_admin always have access
        $userStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        
        if ($user && in_array($user['role'], ['admin', 'super_admin'])) {
            return true;
        }
        
        // Check if user is project lead or creator
        $projectStmt = $db->prepare("SELECT project_lead_id, created_by FROM projects WHERE id = ?");
        $projectStmt->execute([$projectId]);
        $project = $projectStmt->fetch();
        
        if ($project && ($project['project_lead_id'] == $userId || $project['created_by'] == $userId)) {
            return true;
        }
        
        // Check team assignments
        $teamStmt = $db->prepare("SELECT id FROM user_assignments WHERE project_id = ? AND user_id = ? AND (is_removed IS NULL OR is_removed = 0)");
        $teamStmt->execute([$projectId, $userId]);
        
        if ($teamStmt->fetch()) {
            return true;
        }
        
        // Check project-specific permissions
        $permStmt = $db->prepare("
            SELECT id FROM project_permissions 
            WHERE project_id = ? AND user_id = ? AND is_active = 1 
            AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $permStmt->execute([$projectId, $userId]);
        
        return $permStmt->fetch() !== false;
        
    } catch (PDOException $e) {
        error_log("Project access check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all projects a user has access to
 */
function getUserAccessibleProjects($db, $userId) {
    try {
        // Admin and super_admin have access to all projects
        $userStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        
        if ($user && in_array($user['role'], ['admin', 'super_admin'])) {
            return $db->query("SELECT id, title, po_number, status FROM projects ORDER BY title")->fetchAll();
        }
        
        // Get projects where user has access
        $projectsStmt = $db->prepare("
            SELECT DISTINCT p.id, p.title, p.po_number, p.status
            FROM projects p
            WHERE p.id IN (
                -- Projects where user is lead or creator
                SELECT id FROM projects WHERE project_lead_id = ? OR created_by = ?
                UNION
                -- Projects where user is team member
                SELECT project_id FROM user_assignments WHERE user_id = ?
                UNION
                -- Projects where user has specific permissions
                SELECT project_id FROM project_permissions 
                WHERE user_id = ? AND is_active = 1 
                AND (expires_at IS NULL OR expires_at > NOW())
            )
            ORDER BY p.title
        ");
        $projectsStmt->execute([$userId, $userId, $userId, $userId]);
        
        return $projectsStmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Get accessible projects error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user's role in a specific project
 */
function getUserProjectRole($db, $userId, $projectId) {
    try {
        // Check if user is project lead
        $projectStmt = $db->prepare("SELECT project_lead_id, created_by FROM projects WHERE id = ?");
        $projectStmt->execute([$projectId]);
        $project = $projectStmt->fetch();
        
        if ($project) {
            if ($project['project_lead_id'] == $userId) {
                return 'project_lead';
            }
            if ($project['created_by'] == $userId) {
                return 'creator';
            }
        }
        
        // Check team assignments
        $teamStmt = $db->prepare("SELECT role FROM user_assignments WHERE project_id = ? AND user_id = ? AND (is_removed IS NULL OR is_removed = 0)");
        $teamStmt->execute([$projectId, $userId]);
        $teamRole = $teamStmt->fetch();
        
        if ($teamRole) {
            return $teamRole['role'];
        }
        
        // Check if user has any specific permissions
        $permStmt = $db->prepare("
            SELECT COUNT(*) as perm_count FROM project_permissions 
            WHERE project_id = ? AND user_id = ? AND is_active = 1 
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $permStmt->execute([$projectId, $userId]);
        $permCount = $permStmt->fetch();
        
        if ($permCount && $permCount['perm_count'] > 0) {
            return 'custom_permissions';
        }
        
        return null;
        
    } catch (PDOException $e) {
        error_log("Get user project role error: " . $e->getMessage());
        return null;
    }
}

/**
 * Grant permission to a user for a project
 */
function grantProjectPermission($db, $userId, $projectId, $permission, $grantedBy, $expiresAt = null, $notes = '') {
    try {
        // Check if permission already exists
        $checkStmt = $db->prepare("
            SELECT id FROM project_permissions 
            WHERE project_id = ? AND user_id = ? AND permission_type = ?
        ");
        $checkStmt->execute([$projectId, $userId, $permission]);
        
        if ($checkStmt->fetch()) {
            // Update existing permission
            $updateStmt = $db->prepare("
                UPDATE project_permissions 
                SET is_active = TRUE, granted_by = ?, expires_at = ?, notes = ?, updated_at = NOW()
                WHERE project_id = ? AND user_id = ? AND permission_type = ?
            ");
            return $updateStmt->execute([$grantedBy, $expiresAt, $notes, $projectId, $userId, $permission]);
        } else {
            // Grant new permission
            $insertStmt = $db->prepare("
                INSERT INTO project_permissions (project_id, user_id, permission_type, granted_by, expires_at, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            return $insertStmt->execute([$projectId, $userId, $permission, $grantedBy, $expiresAt, $notes]);
        }
        
    } catch (PDOException $e) {
        error_log("Grant permission error: " . $e->getMessage());
        return false;
    }
}

/**
 * Revoke permission from a user for a project
 */
function revokeProjectPermission($db, $userId, $projectId, $permission) {
    try {
        $stmt = $db->prepare("
            UPDATE project_permissions 
            SET is_active = FALSE, updated_at = NOW()
            WHERE project_id = ? AND user_id = ? AND permission_type = ?
        ");
        return $stmt->execute([$projectId, $userId, $permission]);
        
    } catch (PDOException $e) {
        error_log("Revoke permission error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all permissions for a user across all projects
 */
function getUserAllProjectPermissions($db, $userId) {
    try {
        $stmt = $db->prepare("
            SELECT pp.project_id, pp.permission_type, p.title as project_title,
                   pp.expires_at, pp.is_active, pp.granted_at
            FROM project_permissions pp
            JOIN projects p ON pp.project_id = p.id
            WHERE pp.user_id = ? AND pp.is_active = 1
            AND (pp.expires_at IS NULL OR pp.expires_at > NOW())
            ORDER BY p.title, pp.permission_type
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Get user all permissions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get permission statistics for a project
 */
function getProjectPermissionStats($db, $projectId) {
    try {
        $stats = [];
        
        // Total users with permissions
        $totalStmt = $db->prepare("
            SELECT COUNT(DISTINCT user_id) as total_users
            FROM project_permissions 
            WHERE project_id = ? AND is_active = 1
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $totalStmt->execute([$projectId]);
        $stats['total_users'] = $totalStmt->fetch()['total_users'];
        
        // Permissions by category
        $categoryStmt = $db->prepare("
            SELECT pt.category, COUNT(*) as permission_count
            FROM project_permissions pp
            JOIN project_permissions_types pt ON pp.permission_type = pt.permission_type
            WHERE pp.project_id = ? AND pp.is_active = 1
            AND (pp.expires_at IS NULL OR pp.expires_at > NOW())
            GROUP BY pt.category
            ORDER BY permission_count DESC
        ");
        $categoryStmt->execute([$projectId]);
        $stats['by_category'] = $categoryStmt->fetchAll();
        
        // Expiring permissions (next 30 days)
        $expiringStmt = $db->prepare("
            SELECT COUNT(*) as expiring_count
            FROM project_permissions 
            WHERE project_id = ? AND is_active = 1
            AND expires_at IS NOT NULL 
            AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
        ");
        $expiringStmt->execute([$projectId]);
        $stats['expiring_soon'] = $expiringStmt->fetch()['expiring_count'];
        
        return $stats;
        
    } catch (PDOException $e) {
        error_log("Get permission stats error: " . $e->getMessage());
        return [];
    }
}

/**
 * Clean up expired permissions
 */
function cleanupExpiredPermissions($db) {
    try {
        $stmt = $db->prepare("
            UPDATE project_permissions 
            SET is_active = FALSE, updated_at = NOW()
            WHERE expires_at IS NOT NULL AND expires_at < NOW() AND is_active = 1
        ");
        $result = $stmt->execute();
        $affectedRows = $stmt->rowCount();
        
        if ($affectedRows > 0) {
            error_log("Cleaned up $affectedRows expired permissions");
        }
        
        return $affectedRows;
        
    } catch (PDOException $e) {
        error_log("Cleanup expired permissions error: " . $e->getMessage());
        return false;
    }
}

/**
 * Require specific project permission or redirect
 */
function requireProjectPermission($db, $userId, $projectId, $permission, $redirectUrl = null) {
    if (!hasProjectPermission($db, $userId, $projectId, $permission)) {
        if ($redirectUrl) {
            $_SESSION['error'] = "You don't have permission to perform this action.";
            header("Location: $redirectUrl");
        } else {
            http_response_code(403);
            die("Access denied: You don't have permission to perform this action.");
        }
        exit;
    }
}

/**
 * Require project access or redirect
 */
function requireProjectAccess($db, $userId, $projectId, $redirectUrl = null) {
    if (!hasProjectAccess($db, $userId, $projectId)) {
        if ($redirectUrl) {
            $_SESSION['error'] = "You don't have access to this project.";
            header("Location: $redirectUrl");
        } else {
            http_response_code(403);
            die("Access denied: You don't have access to this project.");
        }
        exit;
    }
}

/**
 * Get permission categories and their permissions
 */
function getPermissionCategories($db) {
    try {
        $stmt = $db->query("
            SELECT permission_type, description, category 
            FROM project_permissions_types 
            WHERE is_active = 1 
            ORDER BY category, permission_type
        ");
        
        $permissions = $stmt->fetchAll();
        $categories = [];
        
        foreach ($permissions as $perm) {
            $categories[$perm['category']][] = $perm;
        }
        
        return $categories;
        
    } catch (PDOException $e) {
        error_log("Get permission categories error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all available permission types
 */
function getAllPermissionTypes($db) {
    try {
        $stmt = $db->query("
            SELECT permission_type, description, category 
            FROM project_permissions_types 
            WHERE is_active = 1 
            ORDER BY category, permission_type
        ");
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Get all permission types error: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if a permission type exists
 */
function permissionTypeExists($db, $permissionType) {
    try {
        $stmt = $db->prepare("
            SELECT id FROM project_permissions_types 
            WHERE permission_type = ? AND is_active = 1
        ");
        $stmt->execute([$permissionType]);
        return $stmt->fetch() !== false;
        
    } catch (PDOException $e) {
        error_log("Permission type exists check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log permission-related activity
 */
function logPermissionActivity($db, $userId, $action, $projectId, $details = []) {
    require_once __DIR__ . '/helpers.php';
    return logActivity($db, $userId, $action, 'project', $projectId, $details);
}

/**
 * Get permission display name
 */
function getPermissionDisplayName($permissionType) {
    $displayNames = [
        'project_view' => 'View Project',
        'project_edit' => 'Edit Project',
        'project_delete' => 'Delete Project',
        'project_duplicate' => 'Duplicate Project',
        'pages_view' => 'View Pages',
        'pages_create' => 'Create Pages',
        'pages_edit' => 'Edit Pages',
        'pages_delete' => 'Delete Pages',
        'pages_assign' => 'Assign Pages',
        'status_update' => 'Update Status',
        'status_view' => 'View Status',
        'qa_status_update' => 'Update QA Status',
        'team_view' => 'View Team',
        'team_assign' => 'Assign Team',
        'team_remove' => 'Remove Team',
        'team_manage_roles' => 'Manage Roles',
        'assets_view' => 'View Assets',
        'assets_upload' => 'Upload Assets',
        'assets_edit' => 'Edit Assets',
        'assets_delete' => 'Delete Assets',
        'phases_view' => 'View Phases',
        'phases_edit' => 'Edit Phases',
        'phases_create' => 'Create Phases',
        'phases_delete' => 'Delete Phases',
        'chat_view' => 'View Chat',
        'chat_send' => 'Send Messages',
        'feedback_view' => 'View Feedback',
        'feedback_submit' => 'Submit Feedback',
        'reports_view' => 'View Reports',
        'activity_log_view' => 'View Activity Log',
        'bulk_operations' => 'Bulk Operations',
        'project_settings' => 'Project Settings'
    ];
    
    return $displayNames[$permissionType] ?? ucfirst(str_replace('_', ' ', $permissionType));
}

/**
 * Validate permission data
 */
function validatePermissionData($projectId, $userId, $permissionType) {
    $errors = [];
    
    if (!is_numeric($projectId) || $projectId <= 0) {
        $errors[] = "Invalid project ID";
    }
    
    if (!is_numeric($userId) || $userId <= 0) {
        $errors[] = "Invalid user ID";
    }
    
    if (empty($permissionType) || !is_string($permissionType)) {
        $errors[] = "Invalid permission type";
    }
    
    return $errors;
}
?>