<?php
/**
 * Migration Runner: Add account setup tracking columns
 * Run this file once to add the new columns to your existing database
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    
    echo "Starting migration: Add account setup tracking columns...\n";
    
    // Check if columns already exist
    $checkStmt = $db->query("SHOW COLUMNS FROM users LIKE 'account_setup_completed'");
    if ($checkStmt->rowCount() > 0) {
        echo "Column 'account_setup_completed' already exists. Skipping...\n";
    } else {
        echo "Adding column 'account_setup_completed'...\n";
        $db->exec("ALTER TABLE `users` ADD COLUMN `account_setup_completed` tinyint(1) DEFAULT 0 AFTER `can_manage_devices`");
        echo "✓ Column 'account_setup_completed' added successfully.\n";
    }
    
    $checkStmt = $db->query("SHOW COLUMNS FROM users LIKE 'temp_password'");
    if ($checkStmt->rowCount() > 0) {
        echo "Column 'temp_password' already exists. Skipping...\n";
    } else {
        echo "Adding column 'temp_password'...\n";
        $db->exec("ALTER TABLE `users` ADD COLUMN `temp_password` varchar(255) DEFAULT NULL AFTER `account_setup_completed`");
        echo "✓ Column 'temp_password' added successfully.\n";
    }
    
    // Mark existing users with force_password_reset=0 as having completed setup
    echo "Updating existing users...\n";
    $updateStmt = $db->exec("UPDATE `users` SET `account_setup_completed` = 1 WHERE `force_password_reset` = 0");
    echo "✓ Updated $updateStmt existing user(s) as setup completed.\n";
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\nYou can now:\n";
    echo "1. Create new users - they will have temp_password stored\n";
    echo "2. Send reset emails - temp_password will be visible to admin\n";
    echo "3. Track which users have completed account setup\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
