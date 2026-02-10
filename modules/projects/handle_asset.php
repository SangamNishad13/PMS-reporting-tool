<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_asset'])) {
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $assetName = sanitizeInput($_POST['asset_name']);
    $assetType = $_POST['asset_type']; // 'link', 'file', or 'text'
    
    if (!$projectId || !$assetName || !$assetType) {
        $_SESSION['error'] = "Missing required information.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    $mainUrl = null;
    $filePath = null;
    $linkType = null;
    $textContent = null;
    $description = null;

    if ($assetType === 'link') {
        $mainUrl = sanitizeInput($_POST['main_url']);
        $linkType = sanitizeInput($_POST['link_type']);
        $description = $_POST['link_description'] ?? null; // Summernote content
        if (!$mainUrl) {
            $_SESSION['error'] = "URL is required for links.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }
    } else if ($assetType === 'file') {
        $description = $_POST['file_description'] ?? null; // Summernote content
        if (!isset($_FILES['asset_file']) || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = "Error uploading file.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }

        $uploadDir = __DIR__ . '/../../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = time() . '_' . basename($_FILES['asset_file']['name']);
        $targetPath = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['asset_file']['tmp_name'], $targetPath)) {
            $filePath = 'assets/uploads/' . $fileName;
        } else {
            $_SESSION['error'] = "Failed to save uploaded file.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }
    } else if ($assetType === 'text') {
        $textContent = $_POST['text_content'] ?? null; // Summernote content
        $linkType = sanitizeInput($_POST['text_category']); // Use link_type field for category
        if (!$textContent || trim(strip_tags($textContent)) === '') {
            $_SESSION['error'] = "Text content is required for text assets.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO project_assets (project_id, asset_name, main_url, file_path, created_by, asset_type, link_type, description, text_content)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$projectId, $assetName, $mainUrl, $filePath, $userId, $assetType, $linkType, $description, $textContent]);

        // Log activity
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details)
            VALUES (?, ?, ?, ?, ?)
        ");
        $details = json_encode(['asset_name' => $assetName, 'asset_type' => $assetType]);
        $stmt->execute([$userId, "Added $assetType asset", 'project', $projectId, $details]);

        $_SESSION['success'] = "Asset added successfully!";
        // If AJAX request, return JSON instead of redirect
        if (!empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            echo json_encode(['success' => true, 'message' => 'Asset added']);
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        if (!empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
    }
    header("Location: view.php?id=$projectId#assets");
    exit;
}

// Handle asset deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_asset'])) {
    $assetId = isset($_POST['asset_id']) ? intval($_POST['asset_id']) : 0;
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;

    if (!$assetId || !$projectId) {
        $_SESSION['error'] = "Invalid request.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    // Check if user has permission to delete (Admin, Super Admin, or Project Lead of this project)
    $permissionCheck = $db->prepare("
        SELECT p.project_lead_id 
        FROM projects p 
        WHERE p.id = ?
    ");
    $permissionCheck->execute([$projectId]);
    $project = $permissionCheck->fetch();

    $userRole = $_SESSION['role'];
    $canDelete = in_array($userRole, ['admin', 'super_admin']) || 
                 ($userRole === 'project_lead' && $project['project_lead_id'] == $userId);

    if (!$canDelete) {
        $_SESSION['error'] = "You don't have permission to delete assets.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    try {
        // Get asset details before deletion
        $stmt = $db->prepare("SELECT * FROM project_assets WHERE id = ? AND project_id = ?");
        $stmt->execute([$assetId, $projectId]);
        $asset = $stmt->fetch();

        if (!$asset) {
            $_SESSION['error'] = "Asset not found.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }

        // Delete physical file if it exists
        if ($asset['asset_type'] === 'file' && $asset['file_path']) {
            $filePath = __DIR__ . '/../../' . $asset['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Delete from database
        $stmt = $db->prepare("DELETE FROM project_assets WHERE id = ?");
        $stmt->execute([$assetId]);

        // Log activity
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details)
            VALUES (?, ?, ?, ?, ?)
        ");
        $details = json_encode(['asset_name' => $asset['asset_name'], 'asset_type' => $asset['asset_type']]);
        $stmt->execute([$userId, "Deleted asset", 'project', $projectId, $details]);

        $_SESSION['success'] = "Asset deleted successfully!";
        if (!empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            echo json_encode(['success' => true, 'message' => 'Asset deleted']);
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        if (!empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
    }

    header("Location: view.php?id=$projectId#assets");
    exit;
}
?>
