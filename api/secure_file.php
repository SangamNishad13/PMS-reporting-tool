<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_permissions.php';

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

function escapeLikeValue($value) {
    return strtr((string)$value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_'
    ]);
}

function userCanAccessReferencedIssueProject(PDO $db, int $userId, string $role, string $relPath): bool {
    $like = '%' . escapeLikeValue($relPath) . '%';

    $issueSql = "
        SELECT DISTINCT i.project_id
        FROM issues i
        WHERE i.description LIKE ? ESCAPE '\\\\'
    ";
    $params = [$like];
    if ($role === 'client') {
        $issueSql .= " AND i.client_ready = 1";
    }
    $issueStmt = $db->prepare($issueSql);
    $issueStmt->execute($params);
    while (($projectId = (int)$issueStmt->fetchColumn()) > 0) {
        if (hasProjectAccess($db, $userId, $projectId)) {
            return true;
        }
    }

    $commentSql = "
        SELECT DISTINCT i.project_id
        FROM issue_comments ic
        JOIN issues i ON i.id = ic.issue_id
        WHERE ic.comment_html LIKE ? ESCAPE '\\\\'
    ";
    $commentParams = [$like];
    if ($role === 'client') {
        $commentSql .= " AND i.client_ready = 1";
    }
    $commentStmt = $db->prepare($commentSql);
    $commentStmt->execute($commentParams);
    while (($projectId = (int)$commentStmt->fetchColumn()) > 0) {
        if (hasProjectAccess($db, $userId, $projectId)) {
            return true;
        }
    }

    return false;
}

function userCanAccessReferencedChat(PDO $db, int $userId, string $role, string $relPath): bool {
    $like = '%' . escapeLikeValue($relPath) . '%';

    $chatStmt = $db->prepare("SELECT DISTINCT project_id FROM chat_messages WHERE project_id IS NOT NULL AND message LIKE ? ESCAPE '\\\\'");
    $chatStmt->execute([$like]);
    while (($projectId = (int)$chatStmt->fetchColumn()) > 0) {
        if (hasProjectAccess($db, $userId, $projectId)) {
            return true;
        }
    }

    if ($role === 'admin') {
        $adminStmt = $db->prepare("SELECT 1 FROM chat_messages WHERE message LIKE ? ESCAPE '\\\\' LIMIT 1");
        $adminStmt->execute([$like]);
        return (bool)$adminStmt->fetchColumn();
    }

    $ownStmt = $db->prepare("SELECT 1 FROM chat_messages WHERE project_id IS NULL AND user_id = ? AND message LIKE ? ESCAPE '\\\\' LIMIT 1");
    $ownStmt->execute([$userId, $like]);
    return (bool)$ownStmt->fetchColumn();
}

function userCanAccessFilePath(PDO $db, int $userId, string $role, string $relPath): bool {
    $assetStmt = $db->prepare("SELECT project_id FROM project_assets WHERE file_path = ? LIMIT 1");
    $assetStmt->execute([$relPath]);
    $projectId = (int)$assetStmt->fetchColumn();
    if ($projectId > 0) {
        return hasProjectAccess($db, $userId, $projectId);
    }

    if (strpos($relPath, 'uploads/issues/') === 0) {
        return userCanAccessReferencedIssueProject($db, $userId, $role, $relPath);
    }

    if (strpos($relPath, 'uploads/chat/') === 0) {
        return userCanAccessReferencedIssueProject($db, $userId, $role, $relPath)
            || userCanAccessReferencedChat($db, $userId, $role, $relPath);
    }

    if (strpos($relPath, 'assets/uploads/') === 0) {
        return false;
    }

    if (strpos($relPath, 'uploads/automated_findings/project_') === 0) {
        if (preg_match('/^uploads\/automated_findings\/project_(\d+)\//', $relPath, $m)) {
            $projectIdCheck = (int)$m[1];
            return hasProjectAccess($db, $userId, $projectIdCheck);
        }
    }

    return false;
}

try {
    $db = Database::getInstance();
    $userId = (int)$_SESSION['user_id'];
    $userRole = (string)($_SESSION['role'] ?? '');

    if (!userCanAccessFilePath($db, $userId, $userRole, $relPath)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
} catch (Exception $e) {
    error_log('secure_file.php: permission check failed: ' . $e->getMessage());
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

// Set appropriate MIME type
$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = @$finfo->file($fullPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    } catch (Exception $e) {
        // Fallback to extension-based MIME if finfo fails
    }
}

// Force extension check for web files to prevent strict nosniff blocking
$fileExt = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'pdf' => 'application/pdf',
    'avif' => 'image/avif'
];

if (isset($mimeTypes[$fileExt])) {
    $mime = $mimeTypes[$fileExt];
} elseif ($mime === 'application/octet-stream' || $mime === '') {
    if ($fileExt === 'txt') $mime = 'text/plain';
    if ($fileExt === 'csv') $mime = 'text/csv';
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

// Clear any accidental whitespace or output from included files
while (ob_get_level()) {
    ob_end_clean();
}

readfile($fullPath);
exit;
