<?php
/**
 * Test Client Login Functionality
 * Simple test page to verify client authentication
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

echo "<h2>Client Login Test</h2>\n";

// Check if user is logged in
if (isset($_SESSION['client_user_id']) && isset($_SESSION['client_role'])) {
    echo "<div style='color: green;'>";
    echo "<h3>✓ User is logged in as client</h3>";
    echo "<p><strong>User ID:</strong> " . $_SESSION['client_user_id'] . "</p>";
    echo "<p><strong>Username:</strong> " . ($_SESSION['username'] ?? 'N/A') . "</p>";
    echo "<p><strong>Role:</strong> " . $_SESSION['client_role'] . "</p>";
    echo "<p><strong>Login Time:</strong> " . date('Y-m-d H:i:s', $_SESSION['login_time'] ?? time()) . "</p>";
    echo "<p><strong>Last Activity:</strong> " . date('Y-m-d H:i:s', $_SESSION['last_activity'] ?? time()) . "</p>";
    echo "</div>";
    
    echo "<p><a href='/client/logout'>Logout</a></p>";
    echo "<p><a href='/client/dashboard'>Go to Dashboard</a></p>";
} else {
    echo "<div style='color: red;'>";
    echo "<h3>✗ User is not logged in</h3>";
    echo "</div>";
    
    echo "<p><a href='/client/login'>Go to Login</a></p>";
}

echo "<h3>Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Server Info:</h3>";
echo "<p><strong>Request URI:</strong> " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "</p>";
echo "<p><strong>HTTP Host:</strong> " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "</p>";
echo "<p><strong>Server Name:</strong> " . ($_SERVER['SERVER_NAME'] ?? 'N/A') . "</p>";

?>