<?php
/**
 * Debug Client Login Issues
 * Check database tables and configuration
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Client Login Debug</h2>\n";

try {
    // Test database connection
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance();
    echo "✓ Database connection successful<br>\n";
    
    // Check if client_audit_log table exists
    $stmt = $db->query("SHOW TABLES LIKE 'client_audit_log'");
    if ($stmt->rowCount() > 0) {
        echo "✓ client_audit_log table exists<br>\n";
        
        // Check table structure
        $stmt = $db->query("DESCRIBE client_audit_log");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Table structure:<br>\n";
        foreach ($columns as $column) {
            echo "- {$column['Field']} ({$column['Type']})<br>\n";
        }
    } else {
        echo "✗ client_audit_log table does NOT exist<br>\n";
        echo "Creating table...<br>\n";
        
        $createTable = "
        CREATE TABLE `client_audit_log` (
          `id` bigint(20) NOT NULL AUTO_INCREMENT,
          `client_user_id` int(11) NOT NULL COMMENT 'Client user performing the action',
          `action_type` varchar(50) NOT NULL COMMENT 'Type of action performed',
          `action_details` text DEFAULT NULL COMMENT 'JSON details of the action',
          `resource_type` varchar(50) DEFAULT NULL COMMENT 'Type of resource accessed',
          `resource_id` int(11) DEFAULT NULL COMMENT 'ID of resource accessed',
          `ip_address` varchar(45) DEFAULT NULL COMMENT 'Client IP address',
          `user_agent` text DEFAULT NULL COMMENT 'Client user agent',
          `success` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Whether action was successful',
          `error_message` text DEFAULT NULL COMMENT 'Error message if action failed',
          `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When action occurred',
          PRIMARY KEY (`id`),
          KEY `idx_client_user_id` (`client_user_id`),
          KEY `idx_action_type` (`action_type`),
          KEY `idx_created_at` (`created_at`),
          KEY `idx_success` (`success`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Client audit log for security and compliance';
        ";
        
        $db->exec($createTable);
        echo "✓ client_audit_log table created<br>\n";
    }
    
    // Check if users table has client users
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'client' AND is_active = 1");
    $stmt->execute();
    $result = $stmt->fetch();
    echo "✓ Active client users: {$result['count']}<br>\n";
    
    // Test ClientUser class
    require_once __DIR__ . '/includes/models/ClientUser.php';
    echo "✓ ClientUser class loaded<br>\n";
    
    // Test ClientAuthenticationController
    require_once __DIR__ . '/includes/controllers/ClientAuthenticationController.php';
    echo "✓ ClientAuthenticationController class loaded<br>\n";
    
    // Test Redis connection
    require_once __DIR__ . '/config/redis.php';
    $redis = RedisConfig::getInstance();
    if ($redis->isAvailable()) {
        echo "✓ Redis connection available<br>\n";
    } else {
        echo "⚠ Redis connection not available (will use session fallback)<br>\n";
    }
    
    echo "<br><strong>All checks passed! Client login should work now.</strong><br>\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "<br>\n";
    echo "Stack trace:<br>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "<br><a href='/client/login'>Test Client Login</a><br>\n";
?>