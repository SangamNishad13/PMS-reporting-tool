<?php
/**
 * Initialize issue_page_screenshots table
 * Run this once to set up the screenshot storage capability
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect unauthorized users
try {
    $auth = new Auth();
    $auth->requireRole(['admin']);
} catch (Exception $e) {
    die('Unauthorized');
}

$db = Database::getInstance();

// Read and execute the migration SQL
$migrationFile = __DIR__ . '/database/migrations/20260409_add_issue_page_screenshots.sql';

if (!file_exists($migrationFile)) {
    die('Migration file not found');
}

$sql = file_get_contents($migrationFile);

try {
    // Execute the migration
    $db->exec($sql);
    echo 'Database migration completed successfully!';
    echo '<br>Table issue_page_screenshots created.';
} catch (Exception $e) {
    echo 'Migration error: ' . $e->getMessage();
}
