<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['super_admin', 'admin', 'project_lead']);

$baseDir = getBaseDir();
$projectId = (int)($_POST['project_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $projectId > 0) {
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    
    if ($projectId > 0) {
        try {
            $db = Database::getInstance();
            
            // Update project status
            $stmt = $db->prepare("
                UPDATE projects 
                SET status = 'completed', 
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$projectId]);
            
            // Log activity
            logActivity($db, $_SESSION['user_id'], 'archived_project', 'project', $projectId, [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s')
            ]);
            
            $_SESSION['success'] = "Project archived successfully!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to archive project: " . $e->getMessage();
        }
    }
}

// Redirect back to project edit when project_id is provided
if ($projectId > 0) {
    redirect($baseDir . "/modules/projects/edit.php?id=" . $projectId);
    exit;
}

// Fallback role-specific redirects
$userRole = $_SESSION['role'] ?? '';
if (in_array($userRole, ['admin', 'super_admin'])) {
    redirect($baseDir . "/modules/admin/projects.php");
} elseif ($userRole === 'project_lead') {
    redirect($baseDir . "/modules/project_lead/my_projects.php");
} else {
    redirect($baseDir . "/index.php");
}
exit;
?>
