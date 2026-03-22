<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Only log errors, not every request
if (!isset($_SESSION['user_id'])) {
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

// Always perform database ownership check - never skip for any file type
// Skipping checks based on file extension is a security anti-pattern
$skipDbCheck = false;

if (!$skipDbCheck) {
    // Only do database checks for non-image files or files outside assets/uploads
    try {
        $db = Database::getInstance();
        $userId = (int)$_SESSION['user_id'];
        
        // Check if file is a project asset
        $assetStmt = $db->prepare("SELECT project_id FROM project_assets WHERE file_path = ? LIMIT 1");
        $assetStmt->execute([$relPath]);
        $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($asset) {
            // This is a project asset - check if user has access to the project
            $projectId = (int)$asset['project_id'];
            
            // Use the standard hasProjectAccess function for consistent permission checking
            require_once __DIR__ . '/../includes/project_permissions.php';
            if (!hasProjectAccess($db, $userId, $projectId)) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Forbidden: You do not have access to this project asset';
                exit;
            }
        }
    } catch (Exception $e) {
        // If database check fails, deny access (fail-closed for security)
        error_log('secure_file.php: Failed to check project asset permissions: ' . $e->getMessage());
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden: Permission check failed';
        exit;
    }
}

// Set appropriate MIME type
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

// Fallback to extension check if finfo fails or returns generic octet-stream
$fileExt = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
if ($mime === 'application/octet-stream' || $mime === '') {
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv'
    ];
    if (isset($mimeTypes[$fileExt])) {
        $mime = $mimeTypes[$fileExt];
    }
}

// Add caching headers for images to reduce server load
$fileExt = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$commonImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'];
if (in_array($fileExt, $commonImageExts)) {
    header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
}

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');

// Use output buffering to prevent connection issues
ob_start();
readfile($fullPath);
ob_end_flush();
exit;
