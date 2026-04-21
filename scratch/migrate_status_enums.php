<?php
require_once __DIR__ . '/../includes/functions.php';
$db = Database::getInstance();

try {
    // Note: DDL statements (ALTER TABLE) in MySQL cause implicit commits, 
    // so transactions cannot be used to roll them back.
    
    // 1. Migrate existing 'pending' to 'not_started' if needed in qa_status
    $db->exec("UPDATE page_environments SET qa_status = 'pending' WHERE qa_status IS NULL OR qa_status = ''");
    
    $commonEnum = "'not_started', 'in_progress', 'completed', 'on_hold', 'needs_review'";
    
    // 2. Expand enums to include both old and new
    $db->exec("ALTER TABLE page_environments MODIFY COLUMN status ENUM('not_started','in_progress','completed','on_hold','needs_review','pass','fail')");
    $db->exec("ALTER TABLE page_environments MODIFY COLUMN qa_status ENUM('pending','pass','fail','na','completed','not_started','in_progress', 'on_hold', 'needs_review')");

    // 3. Update qa_status mappings
    $db->exec("UPDATE page_environments SET qa_status = 'not_started' WHERE qa_status = 'pending'");
    $db->exec("UPDATE page_environments SET qa_status = 'completed' WHERE qa_status = 'pass'");
    $db->exec("UPDATE page_environments SET qa_status = 'needs_review' WHERE qa_status = 'fail'");
    $db->exec("UPDATE page_environments SET qa_status = 'on_hold' WHERE qa_status = 'na'");

    // 4. Update status mappings
    $db->exec("UPDATE page_environments SET status = 'completed' WHERE status = 'pass'");
    $db->exec("UPDATE page_environments SET status = 'needs_review' WHERE status = 'fail'");

    // 5. Finalize Enum restriction
    $db->exec("ALTER TABLE page_environments MODIFY COLUMN status ENUM($commonEnum)");
    $db->exec("ALTER TABLE page_environments MODIFY COLUMN qa_status ENUM($commonEnum)");

    echo "Database migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
