<?php
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
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
