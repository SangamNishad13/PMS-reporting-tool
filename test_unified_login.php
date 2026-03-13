<?php
// test_unified_login.php

// 1. Setup exact same session configuration as auth.php
$preferredSessionPath = __DIR__ . '/tmp/sessions';
if (!is_dir($preferredSessionPath)) {
    mkdir($preferredSessionPath, 0777, true);
}
session_save_path($preferredSessionPath);
session_start();

// 2. Mock a successful login session
$_SESSION['user_id'] = 20;
$_SESSION['role'] = 'client';
$_SESSION['username'] = 'sangamnishad13@gmail.com';
$_SESSION['is_client'] = true; // This will trigger the bridging in client/index.php if client_user_id is missing

// 3. Mock a request to the dashboard
$_SERVER['REQUEST_URI'] = '/PMS/client/dashboard';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['DOCUMENT_ROOT'] = 'c:/xampp/htdocs';

echo "Testing guest access to client/dashboard with existing session in custom path...\n";

// Capture output and headers
ob_start();
try {
    // Including client/index.php which now includes auth.php early
    include 'client/index.php';
} catch (Exception $e) {
    echo "Caught Exception: " . $e->getMessage() . "\n";
}
$output = ob_get_clean();

$status = http_response_code();
$headers = headers_list();
$redirected = false;

echo "HTTP Status Code: $status\n";
foreach ($headers as $header) {
    echo "Header: $header\n";
    if (stripos($header, 'Location:') === 0) {
        $redirected = true;
    }
}

if ($status == 200 && !$redirected) {
    echo "SUCCESS: Dashboard loaded successfully with session!\n";
    // Check if bridging happened
    echo "SESSION client_user_id: " . ($_SESSION['client_user_id'] ?? 'MISSING') . "\n";
} else {
    echo "FAILED: Still getting redirect or error.\n";
}
?>
