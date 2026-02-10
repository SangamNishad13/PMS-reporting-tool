<?php
// modules/projects/delete.php

session_start();

// Include configuration
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$baseDir = getBaseDir();
$userRole = $_SESSION['role'] ?? '';

// Check login and role
if (!isset($_SESSION['user_id']) || !hasAdminPrivileges()) {
    redirect($baseDir . "/modules/auth/login.php");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    
    if ($projectId > 0) {
        try {
            $db = Database::getInstance();
            
            // Start transaction
            $db->beginTransaction();
            
            // Delete project and related data (cascade should handle most)
            $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$projectId]);
            
            $db->commit();
            
            $_SESSION['success'] = "Project deleted successfully!";
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $_SESSION['error'] = "Failed to delete project: " . $e->getMessage();
        }
    }
}

// Redirect to role-specific projects page
if ($userRole === 'admin' || $userRole === 'super_admin') {
    redirect($baseDir . "/modules/admin/projects.php");
} elseif ($userRole === 'project_lead') {
    redirect($baseDir . "/modules/project_lead/my_projects.php");
} elseif ($userRole === 'at_tester') {
    redirect($baseDir . "/modules/at_tester/my_projects.php");
} elseif ($userRole === 'ft_tester') {
    redirect($baseDir . "/modules/ft_tester/my_projects.php");
} elseif ($userRole === 'qa') {
    redirect($baseDir . "/modules/qa/my_projects.php");
} else {
    redirect($baseDir . "/index.php");
}
?>