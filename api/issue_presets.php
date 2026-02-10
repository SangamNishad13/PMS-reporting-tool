<?php
// api/issue_presets.php
// API for fetching issue preset details
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$action = $_GET['action'] ?? '';

try {
    $db = Database::getInstance();
    
    if ($action === 'get_by_title') {
        $title = trim($_GET['title'] ?? '');
        $projectType = trim($_GET['project_type'] ?? 'web');
        
        if (empty($title)) {
            echo json_encode(['error' => 'Title is required']);
            exit;
        }
        
        $stmt = $db->prepare('SELECT * FROM issue_presets WHERE project_type = ? AND title = ? LIMIT 1');
        $stmt->execute([$projectType, $title]);
        $preset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($preset) {
            echo json_encode(['success' => true, 'preset' => $preset]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Preset not found']);
        }
    } else {
        echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('issue_presets.php error: ' . $e->getMessage());
    echo json_encode(['error' => 'Server error']);
}
