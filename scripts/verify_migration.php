<?php
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();

echo "=== Verifying Migration ===\n\n";

// Check for old URLs
$oldStmt = $db->query("SELECT COUNT(*) as count FROM issues WHERE description LIKE '%uploads/chat%'");
$oldCount = $oldStmt->fetch()['count'];

// Check for new URLs
$newStmt = $db->query("SELECT COUNT(*) as count FROM issues WHERE description LIKE '%uploads/issues%'");
$newCount = $newStmt->fetch()['count'];

echo "Issues with old URLs (uploads/chat/): $oldCount\n";
echo "Issues with new URLs (uploads/issues/): $newCount\n\n";

if ($oldCount > 0) {
    echo "⚠ Warning: Some issues still have old URLs. Run migration again.\n";
} else {
    echo "✓ All issue images migrated successfully!\n";
}
