<?php
/**
 * Test Client Router
 * Minimal version to debug routing issues
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Client Router Test</h2>\n";

// Start session
session_start();

echo "<p><strong>Request URI:</strong> " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "</p>";
echo "<p><strong>Request Method:</strong> " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A') . "</p>";
echo "<p><strong>Script Name:</strong> " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A') . "</p>";

// Get request path
$requestPath = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestPath, PHP_URL_PATH);

echo "<p><strong>Parsed Path:</strong> $path</p>";

// Remove base path if running in subdirectory
$basePath = '/client';
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

echo "<p><strong>Clean Path:</strong> $path</p>";

// Ensure path starts with /
if (empty($path) || $path[0] !== '/') {
    $path = '/' . $path;
}

echo "<p><strong>Final Path:</strong> $path</p>";

// Test database connection
try {
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance();
    echo "<p>✓ Database connection successful</p>";
} catch (Exception $e) {
    echo "<p>✗ Database error: " . $e->getMessage() . "</p>";
}

// Test class loading
try {
    require_once __DIR__ . '/includes/controllers/ClientAuthenticationController.php';
    echo "<p>✓ ClientAuthenticationController loaded</p>";
    
    $controller = new ClientAuthenticationController();
    echo "<p>✓ ClientAuthenticationController instantiated</p>";
    
} catch (Exception $e) {
    echo "<p>✗ Controller error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Test specific routes
echo "<h3>Route Tests:</h3>";
echo "<p><a href='/client/login'>Test Login Form</a></p>";
echo "<p><a href='/client/dashboard'>Test Dashboard</a></p>";
echo "<p><a href='/client/'>Test Root</a></p>";

?>