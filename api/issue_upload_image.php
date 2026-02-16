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

// Check file type
$allowedTypes = [
    'image/jpeg' => '.jpg',
    'image/png' => '.png',
    'image/gif' => '.gif',
    'image/webp' => '.webp'
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowedTypes[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only images allowed.']);
    exit;
}

$ext = $allowedTypes[$mime];
$folder = __DIR__ . '/../uploads/issues/' . date('Ymd');

if (!is_dir($folder)) {
    mkdir($folder, 0755, true);
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
