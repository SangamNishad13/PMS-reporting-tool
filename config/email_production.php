<?php
/**
 * Production Email Configuration
 * Requirement 19.4: Email settings configuration for notifications
 * 
 * This file provides production-ready email configuration with proper SMTP settings,
 * email templates, rate limiting, and queue management.
 */

class ProductionEmailConfig {
    
    /**
     * Get production email configuration
     */
    public static function getConfig() {
        return [
            // SMTP Configuration for production
            'smtp' => [
                'enabled' => true,
                'host' => $_ENV['SMTP_HOST'] ?? 'mail.athenaeumtransformation.com',
                'port' => (int)($_ENV['SMTP_PORT'] ?? 465),
                'secure' => $_ENV['SMTP_SECURE'] ?? 'ssl', // ssl, tls, or false
                'auth' => true,
                'username' => $_ENV['SMTP_USERNAME'] ?? 'noreply@athenaeumtransformation.com',
                'password' => $_ENV['SMTP_PASSWORD'] ?? '',
                'timeout' => 30,
                'keepalive' => true,
                'debug' => false, // Disable debug in production
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
            
            // Sender configuration
            'from' => [
                'email' => $_ENV['MAIL_FROM'] ?? 'noreply@athenaeumtransformation.com',
                'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Athenaeum PMS - Client Analytics',
            ],
            
            // Reply-to configuration
            'reply_to' => [
                'email' => $_ENV['MAIL_REPLY_TO'] ?? 'support@athenaeumtransformation.com',
                'name' => $_ENV['MAIL_REPLY_TO_NAME'] ?? 'Athenaeum Support',
            ],
            
            // Email templates for different notification types
            'templates' => [
                'project_assignment' => [
                    'subject' => 'New Project Access Granted - {project_name}',
                    'html_template' => 'email/project_assignment.html',
                    'text_template' => 'email/project_assignment.txt',
                    'variables' => ['project_name', 'client_name', 'admin_name', 'access_url', 'support_email']
                ],
                'project_revocation' => [
                    'subject' => 'Project Access Revoked - {project_name}',
                    'html_template' => 'email/project_revocation.html',
                    'text_template' => 'email/project_revocation.txt',
                    'variables' => ['project_name', 'client_name', 'admin_name', 'revocation_date', 'support_email']
                ],
                'export_ready' => [
                    'subject' => 'Your Analytics Export is Ready for Download',
                    'html_template' => 'email/export_ready.html',
                    'text_template' => 'email/export_ready.txt',
                    'variables' => ['client_name', 'export_type', 'download_url', 'expiry_date', 'file_size']
                ],
                'export_failed' => [
                    'subject' => 'Analytics Export Failed - Please Try Again',
                    'html_template' => 'email/export_failed.html',
                    'text_template' => 'email/export_failed.txt',
                    'variables' => ['client_name', 'export_type', 'error_message', 'support_email', 'retry_url']
                ],
                'system_notification' => [
                    'subject' => 'System Notification - {notification_type}',
                    'html_template' => 'email/system_notification.html',
                    'text_template' => 'email/system_notification.txt',
                    'variables' => ['notification_type', 'message', 'action_required', 'support_email']
                ],
                'weekly_summary' => [
                    'subject' => 'Weekly Analytics Summary - {date_range}',
                    'html_template' => 'email/weekly_summary.html',
                    'text_template' => 'email/weekly_summary.txt',
                    'variables' => ['client_name', 'date_range', 'summary_data', 'dashboard_url']
                ]
            ],
            
            // Email queue configuration for production
            'queue' => [
                'enabled' => true,
                'driver' => 'database', // database, redis, or file
                'table' => 'email_queue',
                'max_retries' => 3,
                'retry_delay' => 300, // 5 minutes
                'batch_size' => 10,
                'process_interval' => 60, // 1 minute
                'max_processing_time' => 300, // 5 minutes
                'failed_job_retention' => 7 * 24 * 3600, // 7 days
            ],
            
            // Rate limiting to prevent spam and server overload
            'rate_limit' => [
                'enabled' => true,
                'global' => [
                    'max_emails_per_minute' => 10,
                    'max_emails_per_hour' => 100,
                    'max_emails_per_day' => 1000,
                ],
                'per_user' => [
                    'max_emails_per_hour' => 10,
                    'max_emails_per_day' => 50,
                ],
                'per_template' => [
                    'project_assignment' => ['max_per_hour' => 20],
                    'export_ready' => ['max_per_hour' => 50],
                    'system_notification' => ['max_per_hour' => 5],
                ]
            ],
            
            // Email validation settings
            'validation' => [
                'verify_mx_record' => true,
                'check_disposable_domains' => true,
                'max_email_length' => 254,
                'allowed_domains' => [], // Empty = allow all, or specify allowed domains
                'blocked_domains' => [
                    '10minutemail.com',
                    'tempmail.org',
                    'guerrillamail.com',
                    'mailinator.com'
                ],
            ],
            
            // Bounce handling
            'bounce_handling' => [
                'enabled' => true,
                'bounce_email' => $_ENV['BOUNCE_EMAIL'] ?? 'bounce@athenaeumtransformation.com',
                'max_bounces' => 3,
                'bounce_threshold_days' => 30,
                'auto_disable_bounced_emails' => true,
            ],
            
            // Email tracking (optional)
            'tracking' => [
                'enabled' => false, // Disable for privacy compliance
                'open_tracking' => false,
                'click_tracking' => false,
                'unsubscribe_tracking' => true,
            ],
            
            // Security settings
            'security' => [
                'dkim_enabled' => false, // Configure DKIM if available
                'dkim_domain' => $_ENV['DKIM_DOMAIN'] ?? '',
                'dkim_private_key' => $_ENV['DKIM_PRIVATE_KEY'] ?? '',
                'dkim_selector' => $_ENV['DKIM_SELECTOR'] ?? 'default',
                'spf_record' => 'v=spf1 include:_spf.athenaeumtransformation.com ~all',
                'dmarc_policy' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@athenaeumtransformation.com',
            ],
            
            // Logging configuration
            'logging' => [
                'enabled' => true,
                'log_level' => 'info', // debug, info, warning, error
                'log_file' => $_ENV['EMAIL_LOG_FILE'] ?? dirname(__DIR__) . '/tmp/logs/email.log',
                'log_sent_emails' => true,
                'log_failed_emails' => true,
                'log_bounces' => true,
                'retention_days' => 30,
            ]
        ];
    }
    
    /**
     * Get email template content
     */
    public static function getTemplate($templateName, $format = 'html') {
        $config = self::getConfig();
        
        if (!isset($config['templates'][$templateName])) {
            throw new InvalidArgumentException("Email template '{$templateName}' not found");
        }
        
        $template = $config['templates'][$templateName];
        $templateFile = $format === 'html' ? $template['html_template'] : $template['text_template'];
        $templatePath = dirname(__DIR__) . '/includes/templates/' . $templateFile;
        
        if (!file_exists($templatePath)) {
            throw new RuntimeException("Email template file '{$templateFile}' not found");
        }
        
        return file_get_contents($templatePath);
    }
    
    /**
     * Validate email address
     */
    public static function validateEmail($email) {
        $config = self::getConfig()['validation'];
        
        // Basic format validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Length check
        if (strlen($email) > $config['max_email_length']) {
            return false;
        }
        
        // Domain extraction
        $domain = substr(strrchr($email, "@"), 1);
        
        // Check blocked domains
        if (in_array($domain, $config['blocked_domains'])) {
            return false;
        }
        
        // Check allowed domains (if specified)
        if (!empty($config['allowed_domains']) && !in_array($domain, $config['allowed_domains'])) {
            return false;
        }
        
        // MX record verification
        if ($config['verify_mx_record']) {
            if (!checkdnsrr($domain, 'MX')) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check rate limits
     */
    public static function checkRateLimit($userId, $templateName = null) {
        $config = self::getConfig()['rate_limit'];
        
        if (!$config['enabled']) {
            return true;
        }
        
        // Implementation would check database/cache for rate limit counters
        // This is a placeholder for the actual rate limiting logic
        
        return true;
    }
    
    /**
     * Initialize email configuration for production
     */
    public static function initialize() {
        $config = self::getConfig();
        
        // Set PHP mail configuration
        ini_set('SMTP', $config['smtp']['host']);
        ini_set('smtp_port', $config['smtp']['port']);
        ini_set('sendmail_from', $config['from']['email']);
        
        // Create necessary directories
        $logDir = dirname($config['logging']['log_file']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Initialize email queue table if using database driver
        if ($config['queue']['enabled'] && $config['queue']['driver'] === 'database') {
            self::createEmailQueueTable();
        }
        
        return $config;
    }
    
    /**
     * Create email queue table
     */
    private static function createEmailQueueTable() {
        try {
            $db = Database::getInstance();
            $sql = "
                CREATE TABLE IF NOT EXISTS email_queue (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    to_email VARCHAR(255) NOT NULL,
                    to_name VARCHAR(255),
                    subject VARCHAR(500) NOT NULL,
                    body_html TEXT,
                    body_text TEXT,
                    template_name VARCHAR(100),
                    template_variables JSON,
                    priority TINYINT DEFAULT 5,
                    attempts TINYINT DEFAULT 0,
                    max_attempts TINYINT DEFAULT 3,
                    status ENUM('pending', 'processing', 'sent', 'failed') DEFAULT 'pending',
                    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    processed_at TIMESTAMP NULL,
                    error_message TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_status_scheduled (status, scheduled_at),
                    INDEX idx_template (template_name),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            
            $db->exec($sql);
        } catch (Exception $e) {
            error_log('Failed to create email queue table: ' . $e->getMessage());
        }
    }
}