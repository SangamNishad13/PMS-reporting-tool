<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

// Basic validations
$allowedTypes = [
    'image/jpeg' => '.jpg',
    'image/png'  => '.png',
    'image/gif'  => '.gif',
    'image/webp' => '.webp'
];
$mime = mime_content_type($file['tmp_name']);
if (!isset($allowedTypes[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Only JPG, PNG, GIF, WEBP allowed']);
    exit;
}

$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Image too large (max 5MB)']);
    exit;
}

$ext = $allowedTypes[$mime];
$folder = __DIR__ . '/../uploads/chat/' . date('Ymd');
if (!is_dir($folder)) {
    mkdir($folder, 0755, true);
}
$filename = uniqid('chat_', true) . $ext;
$dest = $folder . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store image']);
    exit;
}

$relativePath = 'uploads/chat/' . date('Ymd') . '/' . $filename;
if (!isset($baseDir)) {
    require_once __DIR__ . '/../includes/helpers.php';
    $baseDir = getBaseDir();
}
$url = rtrim($baseDir, '/') . '/api/secure_file.php?path=' . rawurlencode($relativePath);

echo json_encode(['success' => true, 'url' => $url]);
