<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '' || strpos($token, '.') === false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid token';
    exit;
}

$parts = explode('.', $token, 2);
$payloadB64 = (string)($parts[0] ?? '');
$sig = (string)($parts[1] ?? '');

$expected = hash_hmac('sha256', $payloadB64, get_public_image_token_secret());
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$decoded = base64url_decode($payloadB64);
if ($decoded === false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid token payload';
    exit;
}

$payload = json_decode($decoded, true);
$relPath = ltrim(str_replace('\\', '/', (string)($payload['p'] ?? '')), '/');
if ($relPath === '' || strpos($relPath, "\0") !== false || strpos($relPath, '..') !== false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid path';
    exit;
}

$allowedPrefixes = ['uploads/issues/', 'uploads/chat/', 'assets/uploads/'];
$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (strpos($relPath, $prefix) === 0) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$ext = strtolower((string)pathinfo($relPath, PATHINFO_EXTENSION));
$allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'];
if (!in_array($ext, $allowedExts, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error';
    exit;
}

$candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
$fullPath = realpath($candidate);
if ($fullPath === false || !is_file($fullPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$fullNorm = str_replace('\\', '/', $fullPath);
$baseNorm = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
if (strpos($fullNorm, $baseNorm . 'uploads/') !== 0 && strpos($fullNorm, $baseNorm . 'assets/uploads/') !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
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
if ($mime === 'application/octet-stream' && function_exists('mime_content_type')) {
    $detected = @mime_content_type($fullPath);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }
}
if ($mime === 'application/octet-stream') {
    $imgInfo = @getimagesize($fullPath);
    if (is_array($imgInfo) && isset($imgInfo['mime']) && is_string($imgInfo['mime'])) {
        $mime = $imgInfo['mime'];
    }
}
if (stripos($mime, 'image/') !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
readfile($fullPath);
exit;

