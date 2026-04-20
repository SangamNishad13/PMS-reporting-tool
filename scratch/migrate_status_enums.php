<?php
require_once __DIR__ . '/../includes/functions.php';
$db = Database::getInstance();

try {
    $db->beginTransaction();

    // 1. Migrate existing 'pending' to 'not_started' if needed in qa_status
    $db->exec("UPDATE page_environments SET qa_status = 'pending' WHERE qa_status IS NULL OR qa_status = ''");
    
    // 2. Modify qa_status column
    // First, map existing values to their new equivalents temporarily to avoid truncation if we just alter
    // But since 'pending' -> 'not_started' etc, we need to alter the enum to include BOTH old and new, then update, then alter to remove old.
    
    $commonEnum = "'not_started', 'in_progress', 'completed', 'on_hold', 'needs_review'";
    
    // TEMPORARY: Expand enums to include both old and new
    $db->exec("ALTER TABLE page_environments MODIFY COLUMN status ENUM('not_started','in_progress','completed','on_hold','needs_review','pass','fail')");
    $db->exec("ALTER TABLE page_environments MODIFY COLUMN qa_status ENUM('pending','pass','fail','na','completed','not_started','in_progress','on_hold','needs_review')");

    // 3. Update qa_status mappings
    $db->exec("UPDATE page_environments SET qa_status = 'not_started' WHERE qa_status = 'pending'");
    $db->exec("UPDATE page_environments SET qa_status = 'completed' WHERE qa_status = 'pass'");
    $db->exec("UPDATE page_environments SET qa_status = 'needs_review' WHERE qa_status = 'fail'");
    $db->exec("UPDATE page_environments SET qa_status = 'on_hold' WHERE qa_status = 'na'");

    // 4. Update status mappings (mostly already standard, but handles pass/fail if any)
    $db->exec("UPDATE page_environments SET status = 'completed' WHERE status = 'pass'");
    $db->exec("UPDATE page_environments SET status = 'needs_review' WHERE status = 'fail'");

    // 5. Finalize Enum restriction
    $db->exec("ALTER TABLE page_environments MODIFY COLUMN status ENUM($commonEnum)");
    $db->exec("ALTER TABLE page_environments MODIFY COLUMN qa_status ENUM($commonEnum)");

    $db->commit();
    echo "Database migration completed successfully.\n";
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
