<?php
/**
 * Test Login Simulation
 * Simulate login process to see where it redirects
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Login Simulation Test</h2>\n";

// Start session
session_start();

// Include required files
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

echo "<h3>Testing Login Process</h3>";

try {
    // Create Auth instance
    $auth = new Auth();
    echo "<p>✓ Auth class loaded</p>";
    
    // Test credentials
    $username = 'sangamnishad13@gmail.com';
    $password = 'password';
    
    echo "<p><strong>Testing credentials:</strong></p>";
    echo "<p>Username: $username</p>";
    echo "<p>Password: [hidden]</p>";
    
    // Clear any existing session
    session_destroy();
    session_start();
    
    // Attempt login
    echo "<p><strong>Attempting login...</strong></p>";
    
    $loginResult = $auth->login($username, $password);
    
    if ($loginResult) {
        echo "<p style='color: green;'>✓ Login successful!</p>";
        
        // Check session data
        echo "<p><strong>Session Data:</strong></p>";
        echo "<pre>";
        print_r($_SESSION);
        echo "</pre>";
        
        // Check user role
        $role = $_SESSION['role'] ?? 'unknown';
        echo "<p><strong>User Role:</strong> $role</p>";
        
        // Simulate redirect logic from modules/auth/login.php
        echo "<p><strong>Redirect Logic Test:</strong></p>";
        
        if ($role === 'client') {
            $redirectUrl = "/PMS/client/dashboard";
            echo "<p style='color: green;'>✓ Client user detected</p>";
            echo "<p style='color: blue;'><strong>Would redirect to:</strong> <code>$redirectUrl</code></p>";
        } else {
            $moduleDir = getModuleDirectory($role);
            $redirectUrl = "/modules/{$moduleDir}/dashboard.php";
            echo "<p style='color: orange;'>⚠ Non-client user</p>";
            echo "<p style='color: blue;'><strong>Would redirect to:</strong> <code>$redirectUrl</code></p>";
        }
        
        // Test the actual redirect URL construction
        echo "<p><strong>Full URL would be:</strong></p>";
        $baseUrl = "http://localhost";
        echo "<p><code>$baseUrl$redirectUrl</code></p>";
        
    } else {
        echo "<p style='color: red;'>✗ Login failed</p>";
        echo "<p>Possible reasons:</p>";
        echo "<ul>";
        echo "<li>Invalid credentials</li>";
        echo "<li>User not found</li>";
        echo "<li>User not active</li>";
        echo "<li>Database connection issue</li>";
        echo "</ul>";
        
        // Check if user exists
        require_once __DIR__ . '/config/database.php';
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT id, username, email, role, is_active FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            echo "<p><strong>User found in database:</strong></p>";
            echo "<pre>";
            print_r($user);
            echo "</pre>";
            
            if ($user['is_active'] != 1) {
                echo "<p style='color: red;'>⚠ User is not active</p>";
            }
        } else {
            echo "<p style='color: red;'>✗ User not found in database</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<h3>Manual Test Instructions</h3>";
echo "<ol>";
echo "<li>Go to <a href='/PMS/modules/auth/login.php' target='_blank'>/PMS/modules/auth/login.php</a></li>";
echo "<li>Enter username: <code>sangamnishad13@gmail.com</code></li>";
echo "<li>Enter password: <code>password</code></li>";
echo "<li>Click Login</li>";
echo "<li>Check the URL after redirect</li>";
echo "</ol>";

echo "<p><strong>Expected Result:</strong> Should redirect to <code>http://localhost/PMS/client/dashboard</code></p>";

?>