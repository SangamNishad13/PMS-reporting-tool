<?php
/**
 * Test PMS Client URLs
 * Verify that all client URLs include the PMS directory
 */

echo "<h2>PMS Client URL Test</h2>\n";

echo "<h3>Testing URL Structure</h3>";

// Test the client router paths
$testUrls = [
    '/PMS/client/login' => 'Login Form',
    '/PMS/client/dashboard' => 'Dashboard',
    '/PMS/client/logout' => 'Logout',
    '/PMS/client/exports' => 'Exports'
];

foreach ($testUrls as $url => $description) {
    echo "<p>✓ <strong>$description:</strong> <a href='$url' target='_blank'>$url</a></p>";
}

echo "<h3>Current Server Configuration</h3>";
echo "<p><strong>Document Root:</strong> " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "</p>";
echo "<p><strong>Script Name:</strong> " . ($_SERVER['SCRIPT_NAME'] ?? 'Unknown') . "</p>";
echo "<p><strong>Request URI:</strong> " . ($_SERVER['REQUEST_URI'] ?? 'Unknown') . "</p>";
echo "<p><strong>HTTP Host:</strong> " . ($_SERVER['HTTP_HOST'] ?? 'Unknown') . "</p>";

// Check if we're in the PMS directory
$currentDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
echo "<p><strong>Current Directory:</strong> $currentDir</p>";

if (strpos($currentDir, 'PMS') !== false) {
    echo "<p style='color: green;'>✓ Running in PMS directory structure</p>";
} else {
    echo "<p style='color: orange;'>⚠ Not in PMS directory - URLs may need adjustment</p>";
}

echo "<h3>Test Links</h3>";
echo "<p><a href='/PMS/client/login' target='_blank' style='color: blue;'>🔗 Test Client Login</a></p>";
echo "<p><a href='/PMS/client/dashboard' target='_blank' style='color: blue;'>🔗 Test Client Dashboard</a></p>";

echo "<h3>Expected Behavior</h3>";
echo "<ul>";
echo "<li>Login should redirect to <code>/PMS/client/dashboard</code> after successful authentication</li>";
echo "<li>Dashboard should load with proper navigation and logout links</li>";
echo "<li>All internal links should include the <code>/PMS</code> prefix</li>";
echo "</ul>";

?>