<?php
/**
 * Migrate issue screenshots from uploads/chat/ to uploads/issues/
 * and update database URLs
 */

require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();

echo "=== Migrating Issue Screenshots ===\n\n";

// Get all issues with descriptions containing images
$stmt = $db->query("SELECT id, description FROM issues WHERE description LIKE '%<img%' OR description LIKE '%uploads/chat%'");
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($issues) . " issues with potential images\n\n";

$movedCount = 0;
$updatedCount = 0;
$errorCount = 0;

foreach ($issues as $issue) {
    $issueId = $issue['id'];
    $description = $issue['description'];
    $updated = false;
    
    // Find all image URLs in the description
    preg_match_all('/(uploads\/chat\/\d{8}\/[^"\'>\s]+)/', $description, $matches);
    
    if (empty($matches[1])) {
        continue;
    }
    
    $imageUrls = array_unique($matches[1]);
    
    foreach ($imageUrls as $oldUrl) {
        // Build full paths
        $oldPath = __DIR__ . '/../' . $oldUrl;
        
        if (!file_exists($oldPath)) {
            echo "  ⚠ Image not found: $oldUrl\n";
            continue;
        }
        
        // Extract date and filename
        preg_match('/uploads\/chat\/(\d{8})\/(chat_[^\/]+)$/', $oldUrl, $urlParts);
        
        if (empty($urlParts[1]) || empty($urlParts[2])) {
            echo "  ⚠ Could not parse URL: $oldUrl\n";
            continue;
        }
        
        $date = $urlParts[1];
        $oldFilename = $urlParts[2];
        
        // Create new filename (replace chat_ with issue_)
        $newFilename = str_replace('chat_', 'issue_', $oldFilename);
        
        // Create new directory structure
        $newDir = __DIR__ . '/../uploads/issues/' . $date;
        if (!is_dir($newDir)) {
            mkdir($newDir, 0755, true);
        }
        
        $newPath = $newDir . '/' . $newFilename;
        $newUrl = 'uploads/issues/' . $date . '/' . $newFilename;
        
        // Copy file (not move, to keep backup)
        if (copy($oldPath, $newPath)) {
            // Update URL in description
            $description = str_replace($oldUrl, $newUrl, $description);
            $updated = true;
            $movedCount++;
            echo "  ✓ Moved: $oldFilename -> $newUrl\n";
        } else {
            echo "  ✗ Failed to copy: $oldUrl\n";
            $errorCount++;
        }
    }
    
    // Update database if any URLs were changed
    if ($updated) {
        try {
            $updateStmt = $db->prepare("UPDATE issues SET description = ? WHERE id = ?");
            $updateStmt->execute([$description, $issueId]);
            $updatedCount++;
            echo "  ✓ Updated issue #$issueId in database\n";
        } catch (Exception $e) {
            echo "  ✗ Failed to update issue #$issueId: " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }
    
    echo "\n";
}

echo "\n=== Migration Summary ===\n";
echo "Images moved: $movedCount\n";
echo "Issues updated: $updatedCount\n";
echo "Errors: $errorCount\n";

if ($errorCount === 0) {
    echo "\n✓ Migration completed successfully!\n";
    echo "\nNote: Original files in uploads/chat/ are preserved as backup.\n";
    echo "You can manually delete them after verifying everything works.\n";
} else {
    echo "\n⚠ Migration completed with errors. Please review the output above.\n";
}
