<?php
require_once 'config/database.php';
$db = Database::getInstance();

$sqlFile = 'database/migrations/20260409_add_issue_page_screenshots.sql';
if (!file_exists($sqlFile)) {
    die("Error: SQL file not found at $sqlFile\n");
}

$sql = file_get_contents($sqlFile);

try {
    // MySQL can't handle multiple statements in PDO->exec() by default in some configs,
    // so we split by semicolon (carefully) or use a multi-statement capable logic.
    // Given the file content, it has some comments and triggers.
    
    // We'll use a simple approach: if it fails with exec, try to parse it.
    // Actually, PDO::exec() on MySQL often works for multi-statements depending on driver config.
    $db->exec($sql);
    echo "Migration applied successfully.\n";
    
    // Record in history
    $filename = basename($sqlFile);
    $stmt = $db->prepare("INSERT INTO migration_history (migration_file, executed_by, status) VALUES (?, 'admin', 'success') ON DUPLICATE KEY UPDATE status='success', executed_at=NOW()");
    $stmt->execute([$filename]);
    
} catch (PDOException $e) {
    die("Error applying migration: " . $e->getMessage() . "\n");
}
