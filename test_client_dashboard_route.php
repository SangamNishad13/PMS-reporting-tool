<?php
/**
 * Test Client Dashboard Route
 * Test the new client router dashboard functionality
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Client Dashboard Route Test</h2>\n";

// Start session and simulate logged in client
session_start();

// Simulate a logged in client user
$_SESSION['client_user_id'] = 1; // Assuming user ID 1 exists
$_SESSION['client_role'] = 'client';
$_SESSION['username'] = 'test_client';
$_SESSION['role'] = 'client';
$_SESSION['is_client'] = true;
$_SESSION['login_time'] = time();
$_SESSION['last_activity'] = time();

echo "<p><strong>Session Setup:</strong></p>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Test database connection and user existence
try {
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance();
    
    $stmt = $db->prepare("SELECT id, username, email, role FROM users WHERE role = 'client' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "<p>✓ Found client user: " . $user['username'] . " (ID: " . $user['id'] . ")</p>";
        
        // Update session with real user data
        $_SESSION['client_user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        
    } else {
        echo "<p>✗ No client users found in database</p>";
    }
    
} catch (Exception $e) {
    echo "<p>✗ Database error: " . $e->getMessage() . "</p>";
}

// Test ClientDashboardController
try {
    require_once __DIR__ . '/includes/controllers/ClientDashboardController.php';
    echo "<p>✓ ClientDashboardController loaded successfully</p>";
    
    $controller = new ClientDashboardController();
    echo "<p>✓ ClientDashboardController instantiated successfully</p>";
    
} catch (Exception $e) {
    echo "<p>✗ Controller error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<h3>Test Links:</h3>";
echo "<p><a href='/client/dashboard' target='_blank'>Test Dashboard Route</a></p>";
echo "<p><a href='/client/login' target='_blank'>Test Login Route</a></p>";
echo "<p><a href='/client/logout' target='_blank'>Test Logout Route</a></p>";

echo "<h3>Current Session Status:</h3>";
if (isset($_SESSION['client_user_id']) && isset($_SESSION['client_role'])) {
    echo "<p style='color: green;'>✓ Session appears valid for client access</p>";
} else {
    echo "<p style='color: red;'>✗ Session not valid for client access</p>";
}

?>