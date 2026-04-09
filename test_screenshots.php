<?php
/**
 * Test script to check issue_page_screenshots table
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

try {
    $auth = new Auth();
    $loggedIn = $auth->isLoggedIn();
    $userRole = $auth->getUserRole();
    $userId = $auth->getUserId();

    echo "Logged in: " . ($loggedIn ? 'Yes' : 'No') . "<br>";
    echo "User ID: " . ($userId ?: 'None') . "<br>";
    echo "User Role: " . ($userRole ?: 'None') . "<br>";

    if (!$loggedIn) {
        die('Please login first.');
    }

    $allowedRoles = ['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester'];
    if (!in_array($userRole, $allowedRoles)) {
        die('Insufficient permissions. Your role: ' . $userRole . '. Required: ' . implode(', ', $allowedRoles));
    }

    $db = Database::getInstance();

    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'issue_page_screenshots'");
    $tableExists = $stmt->fetch();

    if ($tableExists) {
        echo 'Table issue_page_screenshots exists.<br>';

        // Check structure
        $stmt = $db->query("DESCRIBE issue_page_screenshots");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo 'Columns:<br>';
        foreach ($columns as $col) {
            echo $col['Field'] . ' - ' . $col['Type'] . ' - ' . ($col['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . '<br>';
        }

        // Check if issue_id is nullable
        $issueIdCol = array_filter($columns, fn($c) => $c['Field'] === 'issue_id');
        if ($issueIdCol) {
            $col = reset($issueIdCol);
            if ($col['Null'] === 'YES') {
                echo 'issue_id is nullable - OK<br>';
            } else {
                echo 'issue_id is NOT nullable - need to alter table<br>';
            }
        }
    } else {
        echo 'Table does not exist. Run setup_issue_screenshots.php as admin.<br>';

        // Try to create it without auth check
        echo 'Attempting to create table...<br>';
        $migrationFile = __DIR__ . '/database/migrations/20260409_add_issue_page_screenshots.sql';
        if (file_exists($migrationFile)) {
            $sql = file_get_contents($migrationFile);
            try {
                $db->exec($sql);
                echo 'Table created successfully!<br>';
            } catch (Exception $e) {
                echo 'Failed to create table: ' . $e->getMessage() . '<br>';
            }
        } else {
            echo 'Migration file not found.<br>';
        }
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}