<?php
/**
 * Test Dashboard Direct Access
 * Test accessing the dashboard controller directly
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Dashboard Direct Access Test</h2>\n";

// Start session and simulate logged in client
session_start();

// Simulate a logged in client user (use a real user ID from your database)
$_SESSION['client_user_id'] = 13; // sangam.client
$_SESSION['client_role'] = 'client';
$_SESSION['username'] = 'sangam.client';
$_SESSION['role'] = 'client';
$_SESSION['is_client'] = true;
$_SESSION['login_time'] = time();
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

echo "<p><strong>Session Setup Complete</strong></p>";

// Test the ClientDashboardController directly
try {
    require_once __DIR__ . '/includes/controllers/ClientDashboardController.php';
    
    echo "<p>✓ ClientDashboardController loaded</p>";
    
    $controller = new ClientDashboardController();
    echo "<p>✓ Controller instantiated</p>";
    
    echo "<p><strong>Calling dashboard() method...</strong></p>";
    
    // Capture output
    ob_start();
    $controller->dashboard();
    $output = ob_get_clean();
    
    if (!empty($output)) {
        echo "<p>✓ Dashboard method executed successfully</p>";
        echo "<p><strong>Output length:</strong> " . strlen($output) . " characters</p>";
        
        // Show first 500 characters of output
        echo "<h3>Output Preview:</h3>";
        echo "<pre>" . htmlspecialchars(substr($output, 0, 500)) . "...</pre>";
        
        // Check if it's HTML
        if (strpos($output, '<!DOCTYPE html') !== false) {
            echo "<p>✓ Output appears to be valid HTML</p>";
        } else {
            echo "<p>⚠ Output doesn't appear to be HTML</p>";
        }
        
    } else {
        echo "<p>⚠ No output from dashboard method</p>";
    }
    
} catch (Exception $e) {
    echo "<p>✗ Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<h3>Next Steps:</h3>";
echo "<p>If the test above shows success, try accessing <a href='/client/dashboard' target='_blank'>/client/dashboard</a></p>";

?>