<?php
/**
 * Test Auth Login Redirect
 * Test if the main auth login redirects client users correctly
 */

echo "<h2>Auth Login Redirect Test</h2>\n";

// Test the getModuleDirectory function
require_once __DIR__ . '/includes/helpers.php';

echo "<h3>Module Directory Mapping Test</h3>";
$roles = ['client', 'admin', 'super_admin', 'project_lead', 'qa'];

foreach ($roles as $role) {
    $moduleDir = getModuleDirectory($role);
    echo "<p><strong>$role</strong> → modules/$moduleDir/dashboard.php</p>";
}

echo "<h3>Client Role Special Handling</h3>";
echo "<p>Client users should now be redirected to: <code>/PMS/client/dashboard</code></p>";
echo "<p>Other users will still use: <code>/modules/{moduleDir}/dashboard.php</code></p>";

echo "<h3>Test URLs</h3>";
echo "<p><a href='/PMS/modules/auth/login.php' target='_blank'>🔗 Test Main Auth Login</a></p>";
echo "<p><a href='/PMS/client/login' target='_blank'>🔗 Test Client Router Login</a></p>";

echo "<h3>Expected Behavior</h3>";
echo "<ul>";
echo "<li>If you login with <strong>sangamnishad13@gmail.com</strong> from main auth login, it should redirect to <code>/PMS/client/dashboard</code></li>";
echo "<li>If you login with admin credentials, it should redirect to <code>/modules/admin/dashboard.php</code></li>";
echo "</ul>";

?>