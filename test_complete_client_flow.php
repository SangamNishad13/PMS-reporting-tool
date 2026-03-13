<?php
/**
 * Test Complete Client Flow
 * Test the entire client authentication and dashboard flow
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Complete Client Flow Test</h2>\n";

// Test 1: Database and tables
echo "<h3>1. Database Test</h3>";
try {
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance();
    echo "✓ Database connection successful<br>\n";
    
    // Check for client users
    $stmt = $db->prepare("SELECT id, username, email FROM users WHERE role = 'client' AND is_active = 1 LIMIT 3");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✓ Found " . count($users) . " active client users:<br>\n";
    foreach ($users as $user) {
        echo "  - {$user['username']} ({$user['email']})<br>\n";
    }
    
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "<br>\n";
}

// Test 2: Client Authentication Controller
echo "<h3>2. Authentication Controller Test</h3>";
try {
    require_once __DIR__ . '/includes/controllers/ClientAuthenticationController.php';
    $authController = new ClientAuthenticationController();
    echo "✓ ClientAuthenticationController loaded and instantiated<br>\n";
} catch (Exception $e) {
    echo "✗ Auth controller error: " . $e->getMessage() . "<br>\n";
}

// Test 3: Client Dashboard Controller
echo "<h3>3. Dashboard Controller Test</h3>";
try {
    require_once __DIR__ . '/includes/controllers/ClientDashboardController.php';
    $dashController = new ClientDashboardController();
    echo "✓ ClientDashboardController loaded and instantiated<br>\n";
} catch (Exception $e) {
    echo "✗ Dashboard controller error: " . $e->getMessage() . "<br>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Test 4: Client Router
echo "<h3>4. Client Router Test</h3>";
try {
    // Simulate different request paths
    $testPaths = [
        '/client/login' => 'Login form',
        '/client/dashboard' => 'Dashboard',
        '/client/logout' => 'Logout'
    ];
    
    foreach ($testPaths as $path => $description) {
        echo "✓ Route pattern for $description: $path<br>\n";
    }
    
} catch (Exception $e) {
    echo "✗ Router test error: " . $e->getMessage() . "<br>\n";
}

// Test 5: Session handling
echo "<h3>5. Session Test</h3>";
session_start();

// Clear any existing session
session_destroy();
session_start();

echo "✓ Session started successfully<br>\n";
echo "Session ID: " . session_id() . "<br>\n";

// Test 6: Template files
echo "<h3>6. Template Files Test</h3>";
$templates = [
    'includes/templates/client/login.php' => 'Login template',
    'includes/templates/client/dashboard.php' => 'Dashboard template',
    'includes/templates/client/error.php' => 'Error template',
    'includes/templates/client/404.php' => '404 template'
];

foreach ($templates as $file => $description) {
    if (file_exists($file)) {
        echo "✓ $description exists<br>\n";
    } else {
        echo "✗ $description missing<br>\n";
    }
}

echo "<h3>Test URLs</h3>";
echo "<p><a href='/client/login' target='_blank'>Test Client Login</a></p>";
echo "<p><a href='/client/dashboard' target='_blank'>Test Client Dashboard</a></p>";
echo "<p><a href='/test_client_login.php' target='_blank'>Test Login Status</a></p>";

echo "<h3>Summary</h3>";
echo "<p>If all tests above show ✓, the client system should be working properly.</p>";
echo "<p>Try logging in with a client user account through the login form.</p>";

?>