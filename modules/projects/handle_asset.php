<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin']);

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

function canManageProjectAsset($db, $userId, $userRole, $projectId, $permissionType = 'assets_edit') {
    if (in_array($userRole, ['admin', 'super_admin'], true)) {
        return true;
    }

    // Project lead can always manage assets for their project
    $leadStmt = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ? LIMIT 1");
    $leadStmt->execute([$projectId]);
    $leadId = (int)($leadStmt->fetchColumn() ?? 0);
    if ($leadId === (int)$userId) {
        return true;
    }

    // Any active assigned team member can manage edit/delete as requested
    $teamStmt = $db->prepare("
        SELECT id
        FROM user_assignments
        WHERE project_id = ? AND user_id = ? AND (is_removed IS NULL OR is_removed = 0)
        LIMIT 1
    ");
    $teamStmt->execute([$projectId, $userId]);
    if ($teamStmt->fetch()) {
        return true;
    }

    // Fallback: explicit project-specific permission
    return hasProjectPermission($db, $userId, $projectId, $permissionType);
}

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

// Handle asset edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_asset'])) {
    $assetId = isset($_POST['asset_id']) ? intval($_POST['asset_id']) : 0;
    $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $assetName = sanitizeInput($_POST['asset_name'] ?? '');

    if (!$assetId || !$projectId || !$assetName) {
        $_SESSION['error'] = "Missing required information for asset update.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    if (!canManageProjectAsset($db, $userId, $userRole, $projectId, 'assets_edit')) {
        $_SESSION['error'] = "You don't have permission to edit assets.";
        header("Location: view.php?id=$projectId#assets");
        exit;
    }

    try {
        $assetStmt = $db->prepare("SELECT * FROM project_assets WHERE id = ? AND project_id = ? LIMIT 1");
        $assetStmt->execute([$assetId, $projectId]);
        $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$asset) {
            $_SESSION['error'] = "Asset not found.";
            header("Location: view.php?id=$projectId#assets");
            exit;
        }

        $fields = ['asset_name = ?'];
        $params = [$assetName];

        if ($asset['asset_type'] === 'link') {
            $mainUrl = sanitizeInput($_POST['main_url'] ?? '');
            if (!$mainUrl) {
                $_SESSION['error'] = "URL is required for link assets.";
                header("Location: view.php?id=$projectId#assets");
                exit;
            }
            $linkType = sanitizeInput($_POST['link_type'] ?? '');
            $description = $_POST['link_description'] ?? null;

            $fields[] = 'main_url = ?';
            $fields[] = 'link_type = ?';
            $fields[] = 'description = ?';
            $params[] = $mainUrl;
            $params[] = $linkType ?: null;
            $params[] = $description;
        } elseif ($asset['asset_type'] === 'text') {
            $textContent = $_POST['text_content'] ?? '';
            if (trim(strip_tags($textContent)) === '') {
                $_SESSION['error'] = "Text content is required for text assets.";
                header("Location: view.php?id=$projectId#assets");
                exit;
            }
            $category = sanitizeInput($_POST['text_category'] ?? '');

            $fields[] = 'text_content = ?';
            $fields[] = 'link_type = ?';
            $params[] = $textContent;
            $params[] = $category ?: null;
        } elseif ($asset['asset_type'] === 'file') {
            $description = $_POST['file_description'] ?? null;
            $fields[] = 'description = ?';
            $params[] = $description;

            // Optional file replacement
            if (isset($_FILES['asset_file']) && $_FILES['asset_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../assets/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $fileName = time() . '_' . basename($_FILES['asset_file']['name']);
                $targetPath = $uploadDir . $fileName;
                if (!move_uploaded_file($_FILES['asset_file']['tmp_name'], $targetPath)) {
                    $_SESSION['error'] = "Failed to replace uploaded file.";
                    header("Location: view.php?id=$projectId#assets");
                    exit;
                }

                // Remove old physical file after successful replacement
                if (!empty($asset['file_path'])) {
                    $oldPath = __DIR__ . '/../../' . $asset['file_path'];
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                $fields[] = 'file_path = ?';
                $params[] = 'assets/uploads/' . $fileName;
            }
        }

        $params[] = $assetId;
        $params[] = $projectId;
        $upd = $db->prepare("UPDATE project_assets SET " . implode(', ', $fields) . " WHERE id = ? AND project_id = ?");
        $upd->execute($params);

        $details = json_encode([
            'asset_id' => $assetId,
            'asset_name' => $assetName,
            'asset_type' => $asset['asset_type']
        ]);
        $log = $db->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details)
            VALUES (?, ?, ?, ?, ?)
        ");
        $log->execute([$userId, "Edited asset", 'project', $projectId, $details]);

        $_SESSION['success'] = "Asset updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
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

    if (!canManageProjectAsset($db, $userId, $userRole, $projectId, 'assets_delete')) {
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
        $details = json_encode([
            'asset_id' => $assetId,
            'asset_name' => $asset['asset_name'],
            'asset_type' => $asset['asset_type']
        ]);
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
