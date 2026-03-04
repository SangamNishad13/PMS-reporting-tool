<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Debug logging
error_log("secure_file.php: Session user_id = " . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log("secure_file.php: Requested path = " . ($_GET['path'] ?? 'NOT SET'));

if (!isset($_SESSION['user_id'])) {
    error_log("secure_file.php: Access denied - No user session");
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden: Not logged in';
    exit;
}

$relPath = trim((string)($_GET['path'] ?? ''));
if ($relPath === '' || strpos($relPath, "\0") !== false) {
    http_response_code(400);
    echo 'Invalid path';
    exit;
}

$relPath = ltrim(str_replace('\\', '/', $relPath), '/');
if (strpos($relPath, '..') !== false) {
    http_response_code(400);
    echo 'Invalid path';
    exit;
}

$allowedPrefixes = ['uploads/', 'assets/uploads/'];
$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (strpos($relPath, $prefix) === 0) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$parts = explode('/', $relPath);
foreach ($parts as $part) {
    if ($part !== '' && $part[0] === '.') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$baseDir = realpath(__DIR__ . '/..');
$fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath));
if ($fullPath === false || !is_file($fullPath)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$uploadsRoot = realpath($baseDir . DIRECTORY_SEPARATOR . 'uploads');
$assetsUploadsRoot = realpath($baseDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads');
$fullNorm = str_replace('\\', '/', $fullPath);
$insideAllowed = false;
if ($uploadsRoot !== false) {
    $uNorm = rtrim(str_replace('\\', '/', $uploadsRoot), '/') . '/';
    if (strpos($fullNorm, $uNorm) === 0) {
        $insideAllowed = true;
    }
}
if (!$insideAllowed && $assetsUploadsRoot !== false) {
    $aNorm = rtrim(str_replace('\\', '/', $assetsUploadsRoot), '/') . '/';
    if (strpos($fullNorm, $aNorm) === 0) {
        $insideAllowed = true;
    }
}
if (!$insideAllowed) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Check if this is a project asset and verify user has access to the project
try {
    $db = Database::getInstance();
    $userId = (int)$_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? '';
    
    // Check if file is a project asset
    $assetStmt = $db->prepare("SELECT project_id FROM project_assets WHERE file_path = ? LIMIT 1");
    $assetStmt->execute([$relPath]);
    $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($asset) {
        // This is a project asset - check if user has access to the project
        $projectId = (int)$asset['project_id'];
        
        error_log("secure_file.php: Found project asset - Project ID: $projectId, User ID: $userId");
        
        // Use the standard hasProjectAccess function for consistent permission checking
        require_once __DIR__ . '/../includes/project_permissions.php';
        if (!hasProjectAccess($db, $userId, $projectId)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            error_log("secure_file.php: User $userId denied access to project $projectId asset: $relPath");
            echo 'Forbidden: You do not have access to this project asset';
            exit;
        }
        error_log("secure_file.php: User $userId granted access to project $projectId asset: $relPath");
    } else {
        error_log("secure_file.php: Not a project asset, allowing access: $relPath");
    }
    // If not a project asset, allow access (for other uploads like chat images, issue screenshots, etc.)
} catch (Exception $e) {
    // If database check fails, log error but allow access to avoid breaking existing functionality
    error_log('secure_file.php: Failed to check project asset permissions: ' . $e->getMessage());
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $fi = @finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $detected = @finfo_file($fi, $fullPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
        @finfo_close($fi);
    }
}

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
readfile($fullPath);
exit;
