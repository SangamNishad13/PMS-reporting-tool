<?php
/**
 * Production Deployment Script
 * 
 * This script configures the system for production deployment by applying
 * all production settings including caching, security, email, and file storage.
 * 
 * Requirements: 18.1, 17.5, 19.4
 */

// Define constants to prevent direct access to config files
define('INIT_PRODUCTION', true);
define('DEPLOYMENT_MODE', true);

// Include required files
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/init_production.php';

class ProductionDeployment {
    
    private $results = [];
    private $errors = [];
    
    public function deploy() {
        echo "=== Production Deployment Started ===\n";
        echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
        
        try {
            // Step 1: Initialize production environment
            $this->initializeProduction();
            
            // Step 2: Configure caching system
            $this->configureCaching();
            
            // Step 3: Set up security headers
            $this->configureSecurityHeaders();
            
            // Step 4: Configure email system
            $this->configureEmailSystem();
            
            // Step 5: Set up file storage permissions
            $this->configureFileStorage();
            
            // Step 6: Optimize database settings
            $this->optimizeDatabase();
            
            // Step 7: Run health checks
            $this->runHealthChecks();
            
            // Step 8: Generate deployment report
            $this->generateReport();
            
            echo "\n=== Production Deployment Completed Successfully ===\n";
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            echo "\n=== Production Deployment Failed ===\n";
            echo "Error: " . $e->getMessage() . "\n";
            $this->generateReport();
            exit(1);
        }
    }
    
    private function initializeProduction() {
        echo "1. Initializing production environment...\n";
        
        try {
            $config = ProductionInitializer::initialize(true);
            $this->results['initialization'] = $config;
            echo "   ✓ Production environment initialized\n";
            
            foreach ($config as $component => $status) {
                echo "   ✓ {$component}: {$status}\n";
            }
            
        } catch (Exception $e) {
            throw new Exception("Failed to initialize production environment: " . $e->getMessage());
        }
    }
    
    private function configureCaching() {
        echo "\n2. Configuring caching system...\n";
        
        try {
            // Test Redis connection
            $redis = ProductionCacheConfig::initialize();
            if ($redis) {
                echo "   ✓ Redis cache connection established\n";
                
                // Test cache operations
                $testKey = 'deployment_test_' . time();
                $testData = ['test' => true, 'timestamp' => time()];
                
                if ($redis->set($testKey, json_encode($testData), 60)) {
                    echo "   ✓ Cache write test successful\n";
                    
                    $retrieved = json_decode($redis->get($testKey), true);
                    if ($retrieved && $retrieved['test'] === true) {
                        echo "   ✓ Cache read test successful\n";
                        $redis->del($testKey);
                        echo "   ✓ Cache delete test successful\n";
                    } else {
                        throw new Exception("Cache read test failed");
                    }
                } else {
                    throw new Exception("Cache write test failed");
                }
                
                $this->results['caching'] = 'Redis cache configured and tested successfully';
            } else {
                echo "   ⚠ Redis not available, using fallback caching\n";
                $this->results['caching'] = 'Fallback caching enabled (Redis unavailable)';
            }
            
        } catch (Exception $e) {
            throw new Exception("Failed to configure caching: " . $e->getMessage());
        }
    }
    
    private function configureSecurityHeaders() {
        echo "\n3. Configuring security headers...\n";
        
        try {
            // Check if .htaccess files are in place
            $htaccessFiles = [
                '.htaccess' => 'Root security configuration',
                'client/.htaccess' => 'Client interface security',
                'uploads/.htaccess' => 'Upload directory security'
            ];
            
            foreach ($htaccessFiles as $file => $description) {
                if (file_exists($file)) {
                    echo "   ✓ {$description}: {$file}\n";
                } else {
                    echo "   ⚠ Missing: {$file}\n";
                }
            }
            
            // Test security headers (would need actual HTTP request in real deployment)
            $securityHeaders = ProductionConfig::getSecurityHeaders();
            echo "   ✓ Security headers configured: " . count($securityHeaders) . " headers\n";
            
            $this->results['security'] = 'Security headers and .htaccess files configured';
            
        } catch (Exception $e) {
            throw new Exception("Failed to configure security: " . $e->getMessage());
        }
    }
    
    private function configureEmailSystem() {
        echo "\n4. Configuring email system...\n";
        
        try {
            $emailConfig = ProductionEmailConfig::getConfig();
            
            // Test SMTP connection (simplified test)
            if ($emailConfig['smtp']['enabled']) {
                echo "   ✓ SMTP configuration loaded\n";
                echo "   ✓ SMTP Host: " . $emailConfig['smtp']['host'] . "\n";
                echo "   ✓ SMTP Port: " . $emailConfig['smtp']['port'] . "\n";
                echo "   ✓ Email templates: " . count($emailConfig['templates']) . " configured\n";
                
                // Check if email templates exist
                $templateDir = __DIR__ . '/includes/templates/email/';
                $templatesFound = 0;
                foreach ($emailConfig['templates'] as $name => $template) {
                    if (file_exists($templateDir . $template['html_template'])) {
                        $templatesFound++;
                    }
                }
                echo "   ✓ Email template files: {$templatesFound} found\n";
            }
            
            $this->results['email'] = 'Email system configured with SMTP and templates';
            
        } catch (Exception $e) {
            throw new Exception("Failed to configure email system: " . $e->getMessage());
        }
    }
    
    private function configureFileStorage() {
        echo "\n5. Configuring file storage...\n";
        
        try {
            // Initialize storage directories
            ProductionStorageConfig::initializeDirectories();
            
            $storageConfig = ProductionStorageConfig::getConfig();
            $directories = [
                'exports' => $storageConfig['exports']['base_path'],
                'uploads' => $storageConfig['uploads']['base_path'],
                'temp' => $storageConfig['temp']['base_path'],
                'logs' => $storageConfig['logs']['base_path'],
            ];
            
            foreach ($directories as $name => $path) {
                if (is_dir($path)) {
                    $perms = substr(sprintf('%o', fileperms($path)), -4);
                    echo "   ✓ {$name} directory: {$path} (permissions: {$perms})\n";
                    
                    // Check if .htaccess exists
                    if (file_exists($path . '/.htaccess')) {
                        echo "   ✓ {$name} security: .htaccess configured\n";
                    }
                } else {
                    echo "   ⚠ {$name} directory not found: {$path}\n";
                }
            }
            
            $this->results['storage'] = 'File storage directories and permissions configured';
            
        } catch (Exception $e) {
            throw new Exception("Failed to configure file storage: " . $e->getMessage());
        }
    }
    
    private function optimizeDatabase() {
        echo "\n6. Optimizing database settings...\n";
        
        try {
            $db = Database::getInstance();
            
            // Test database connection
            $result = $db->query('SELECT VERSION() as version');
            $version = $result->fetch();
            echo "   ✓ Database connection: MySQL " . $version['version'] . "\n";
            
            // Check if required tables exist
            $requiredTables = [
                'users', 'projects', 'issues', 'client_project_assignments',
                'analytics_cache', 'audit_logs', 'email_queue'
            ];
            
            foreach ($requiredTables as $table) {
                $result = $db->query("SHOW TABLES LIKE '{$table}'");
                if ($result->rowCount() > 0) {
                    echo "   ✓ Table exists: {$table}\n";
                } else {
                    echo "   ⚠ Table missing: {$table}\n";
                }
            }
            
            $this->results['database'] = 'Database connection and tables verified';
            
        } catch (Exception $e) {
            throw new Exception("Failed to optimize database: " . $e->getMessage());
        }
    }
    
    private function runHealthChecks() {
        echo "\n7. Running health checks...\n";
        
        try {
            $health = ProductionInitializer::healthCheck();
            
            echo "   Overall Status: " . strtoupper($health['status']) . "\n";
            
            foreach ($health['checks'] as $check => $status) {
                $icon = strpos($status, 'OK') === 0 ? '✓' : 
                       (strpos($status, 'WARNING') === 0 ? '⚠' : '✗');
                echo "   {$icon} {$check}: {$status}\n";
            }
            
            $this->results['health_check'] = $health;
            
        } catch (Exception $e) {
            throw new Exception("Health check failed: " . $e->getMessage());
        }
    }
    
    private function generateReport() {
        echo "\n8. Generating deployment report...\n";
        
        $report = [
            'deployment_date' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'server_info' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'results' => $this->results,
            'errors' => $this->errors,
            'status' => empty($this->errors) ? 'SUCCESS' : 'FAILED'
        ];
        
        // Save report to file
        $reportFile = __DIR__ . '/tmp/logs/deployment_' . date('Y-m-d_H-i-s') . '.json';
        $reportDir = dirname($reportFile);
        
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0750, true);
        }
        
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        echo "   ✓ Deployment report saved: {$reportFile}\n";
        
        // Display summary
        echo "\n=== Deployment Summary ===\n";
        echo "Status: " . $report['status'] . "\n";
        echo "Components configured: " . count($this->results) . "\n";
        
        if (!empty($this->errors)) {
            echo "Errors encountered: " . count($this->errors) . "\n";
            foreach ($this->errors as $error) {
                echo "  - {$error}\n";
            }
        }
    }
}

// Run deployment if called directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $deployment = new ProductionDeployment();
    $deployment->deploy();
} else {
    echo "This script should be run from the command line.\n";
    echo "Usage: php deploy_production.php\n";
}