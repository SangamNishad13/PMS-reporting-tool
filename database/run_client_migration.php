<?php
/**
 * Migration Runner: Client Reporting System
 * Run this file once to add the client reporting system to your existing database
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    
    echo "Starting migration: Client Reporting and Analytics System...\n";
    
    // Read and execute the migration SQL
    $migrationFile = __DIR__ . '/migrations/add_client_reporting_system.sql';
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    if ($sql === false) {
        throw new Exception("Could not read migration file: $migrationFile");
    }
    
    // Split SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $successCount = 0;
    $skipCount = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue; // Skip empty statements and comments
        }
        
        try {
            $db->exec($statement);
            $successCount++;
            
            // Show progress for major operations
            if (strpos($statement, 'CREATE TABLE') !== false) {
                preg_match('/CREATE TABLE.*?`([^`]+)`/', $statement, $matches);
                $tableName = $matches[1] ?? 'unknown';
                echo "✓ Created table: $tableName\n";
            } elseif (strpos($statement, 'ALTER TABLE') !== false) {
                preg_match('/ALTER TABLE.*?`([^`]+)`/', $statement, $matches);
                $tableName = $matches[1] ?? 'unknown';
                echo "✓ Modified table: $tableName\n";
            }
            
        } catch (Exception $e) {
            // Check if it's a "already exists" or "duplicate" error - these are OK
            $errorMsg = strtolower($e->getMessage());
            if (strpos($errorMsg, 'already exists') !== false || 
                strpos($errorMsg, 'duplicate') !== false ||
                strpos($errorMsg, 'column already exists') !== false) {
                $skipCount++;
                echo "⚠ Skipped (already exists): " . substr($statement, 0, 50) . "...\n";
            } else {
                throw $e; // Re-throw if it's a real error
            }
        }
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "Executed: $successCount statements\n";
    echo "Skipped: $skipCount statements (already existed)\n";
    
    echo "\nClient Reporting System is now ready:\n";
    echo "1. Client role added to users table\n";
    echo "2. client_ready column added to issues table\n";
    echo "3. client_project_assignments table created\n";
    echo "4. analytics_reports table created for caching\n";
    echo "5. export_requests table created for export tracking\n";
    echo "6. client_audit_log table created for security\n";
    echo "7. Performance indexes added\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}