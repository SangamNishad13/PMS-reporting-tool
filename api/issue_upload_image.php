<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No image uploaded']);
    exit;
}

$file = $_FILES['image'];

// Validate file
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload error: ' . $file['error']]);
    exit;
}

// Check file size (max 10MB)
$maxSize = 10 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large. Max 10MB allowed.']);
    exit;
}

// Check file type (robust on shared hosting with limited extensions)
$allowedMimeTypes = [
    'image/jpeg' => '.jpg',
    'image/jpg' => '.jpg',
    'image/pjpeg' => '.jpg',
    'image/png' => '.png',
    'image/x-png' => '.png',
    'image/gif' => '.gif',
    'image/webp' => '.webp'
];
$allowedNameExt = [
    'jpg' => '.jpg',
    'jpeg' => '.jpg',
    'png' => '.png',
    'gif' => '.gif',
    'webp' => '.webp'
];

$mime = '';
if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = @finfo_file($finfo, $file['tmp_name']);
        if (is_string($detected)) {
            $mime = $detected;
        }
        @finfo_close($finfo);
    }
}
if ($mime === '' && function_exists('mime_content_type')) {
    $detected = @mime_content_type($file['tmp_name']);
    if (is_string($detected)) {
        $mime = $detected;
    }
}
if ($mime === '') {
    $imgInfo = @getimagesize($file['tmp_name']);
    if (is_array($imgInfo) && isset($imgInfo['mime']) && is_string($imgInfo['mime'])) {
        $mime = $imgInfo['mime'];
    }
}
if ($mime === '' && function_exists('exif_imagetype')) {
    $imgType = @exif_imagetype($file['tmp_name']);
    if ($imgType) {
        $detected = @image_type_to_mime_type($imgType);
        if (is_string($detected)) {
            $mime = $detected;
        }
    }
}
$mime = strtolower(trim(explode(';', (string)$mime)[0]));
$nameExt = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
$ext = '';

if ($mime !== '' && isset($allowedMimeTypes[$mime])) {
    $ext = $allowedMimeTypes[$mime];
} elseif ($nameExt !== '' && isset($allowedNameExt[$nameExt])) {
    // Accept extension fallback only when file looks like an image.
    $imgInfo = @getimagesize($file['tmp_name']);
    if (is_array($imgInfo)) {
        $ext = $allowedNameExt[$nameExt];
    }
}

if ($ext === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only JPG, PNG, GIF, WEBP allowed.', 'detected_mime' => $mime]);
    exit;
}
$folder = __DIR__ . '/../uploads/issues/' . date('Ymd');

if (!is_dir($folder)) {
    if (!@mkdir($folder, 0755, true) && !is_dir($folder)) {
        http_response_code(500);
        echo json_encode(['error' => 'Upload directory is not writable']);
        exit;
    }
}

$filename = uniqid('issue_', true) . $ext;
$dest = $folder . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store image']);
    exit;
}

// Get base directory for URL
$baseDir = '';
if (function_exists('getBaseDir')) {
    $baseDir = getBaseDir();
}
$url = rtrim($baseDir, '/') . '/uploads/issues/' . date('Ymd') . '/' . $filename;

echo json_encode(['success' => true, 'url' => $url]);
