<?php
/**
 * Production File Storage Configuration
 * Requirement 19.4: File storage permissions for secure export handling
 * 
 * This file provides production-ready file storage configuration with proper
 * security settings, permissions, and cleanup policies.
 */

class ProductionStorageConfig {
    
    /**
     * Get production storage configuration
     */
    public static function getConfig() {
        $baseDir = dirname(__DIR__);
        
        return [
            // Export file storage
            'exports' => [
                'base_path' => $_ENV['EXPORT_PATH'] ?? $baseDir . '/tmp/exports/',
                'max_file_size' => 50 * 1024 * 1024, // 50MB
                'allowed_formats' => ['pdf', 'xlsx', 'csv'],
                'retention_days' => 7, // Keep exports for 7 days
                'cleanup_interval' => 3600, // Clean up every hour
                'permissions' => [
                    'directory' => 0750, // rwxr-x---
                    'file' => 0640,      // rw-r-----
                ],
                'user_subdirectories' => true,
                'max_files_per_user' => 50,
                'total_size_limit' => 1024 * 1024 * 1024, // 1GB total
            ],
            
            // Upload storage
            'uploads' => [
                'base_path' => $_ENV['UPLOAD_PATH'] ?? $baseDir . '/uploads/',
                'max_file_size' => 20 * 1024 * 1024, // 20MB
                'allowed_types' => [
                    'image' => [
                        'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                        'mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                        'max_size' => 10 * 1024 * 1024, // 10MB for images
                    ],
                    'document' => [
                        'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'],
                        'mime_types' => [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/plain',
                            'text/csv'
                        ],
                        'max_size' => 20 * 1024 * 1024, // 20MB for documents
                    ],
                    'archive' => [
                        'extensions' => ['zip', 'tar', 'gz'],
                        'mime_types' => ['application/zip', 'application/x-tar', 'application/gzip'],
                        'max_size' => 50 * 1024 * 1024, // 50MB for archives
                    ]
                ],
                'scan_uploads' => true, // Enable virus scanning if available
                'permissions' => [
                    'directory' => 0755, // rwxr-xr-x
                    'file' => 0644,      // rw-r--r--
                ],
                'quarantine_suspicious' => true,
                'max_files_per_user' => 100,
            ],
            
            // Temporary file storage
            'temp' => [
                'base_path' => $_ENV['TEMP_PATH'] ?? sys_get_temp_dir() . '/pms/',
                'max_age' => 3600, // 1 hour
                'cleanup_interval' => 1800, // Clean up every 30 minutes
                'permissions' => [
                    'directory' => 0700, // rwx------
                    'file' => 0600,      // rw-------
                ],
                'max_size' => 100 * 1024 * 1024, // 100MB total temp storage
            ],
            
            // Log file storage
            'logs' => [
                'base_path' => $_ENV['LOG_PATH'] ?? $baseDir . '/tmp/logs/',
                'max_file_size' => 100 * 1024 * 1024, // 100MB per log file
                'max_files' => 30, // Keep 30 log files
                'permissions' => [
                    'directory' => 0750, // rwxr-x---
                    'file' => 0640,      // rw-r-----
                ],
                'rotate_daily' => true,
                'compress_old_logs' => true,
                'retention_days' => 90,
            ],
            
            // Cache file storage
            'cache' => [
                'base_path' => $_ENV['CACHE_PATH'] ?? $baseDir . '/tmp/cache/',
                'max_size' => 500 * 1024 * 1024, // 500MB cache storage
                'permissions' => [
                    'directory' => 0750, // rwxr-x---
                    'file' => 0640,      // rw-r-----
                ],
                'cleanup_interval' => 3600, // Clean up every hour
                'max_age' => 86400, // 24 hours default TTL
            ],
            
            // Security settings
            'security' => [
                // Disable PHP execution in upload directories
                'disable_php_execution' => true,
                
                // Block dangerous file extensions
                'blocked_extensions' => [
                    'php', 'php3', 'php4', 'php5', 'phtml', 'phps',
                    'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb',
                    'exe', 'com', 'bat', 'cmd', 'scr', 'vbs', 'js',
                    'sh', 'bash', 'zsh', 'fish', 'ps1', 'psm1'
                ],
                
                // Content type validation
                'validate_mime_type' => true,
                'strict_mime_checking' => true,
                
                // Filename sanitization
                'sanitize_filenames' => true,
                'max_filename_length' => 255,
                'allowed_filename_chars' => 'a-zA-Z0-9._-',
                
                // Path traversal protection
                'prevent_path_traversal' => true,
                'normalize_paths' => true,
                
                // File content scanning
                'scan_for_malware' => true,
                'quarantine_suspicious_files' => true,
                
                // Access control
                'require_authentication' => true,
                'check_permissions' => true,
                'log_access_attempts' => true,
            ],
            
            // Backup settings
            'backup' => [
                'enabled' => true,
                'schedule' => 'daily', // daily, weekly, monthly
                'retention_days' => 30,
                'backup_path' => $_ENV['BACKUP_PATH'] ?? $baseDir . '/backups/',
                'compress_backups' => true,
                'encrypt_backups' => false, // Set to true if encryption key is available
                'exclude_patterns' => [
                    '*.tmp',
                    '*.log',
                    'cache/*',
                    'temp/*'
                ]
            ],
            
            // Monitoring and alerts
            'monitoring' => [
                'enabled' => true,
                'disk_usage_threshold' => 0.9, // Alert at 90% disk usage
                'file_count_threshold' => 10000, // Alert at 10k files
                'check_interval' => 3600, // Check every hour
                'alert_email' => $_ENV['ADMIN_EMAIL'] ?? 'admin@athenaeumtransformation.com',
                'metrics' => [
                    'disk_usage',
                    'file_count',
                    'upload_rate',
                    'download_rate',
                    'error_rate'
                ]
            ]
        ];
    }
    
    /**
     * Initialize storage directories with proper permissions
     */
    public static function initializeDirectories() {
        $config = self::getConfig();
        $directories = [
            'exports' => $config['exports']['base_path'],
            'uploads' => $config['uploads']['base_path'],
            'temp' => $config['temp']['base_path'],
            'logs' => $config['logs']['base_path'],
            'cache' => $config['cache']['base_path'],
        ];
        
        foreach ($directories as $type => $path) {
            if (!is_dir($path)) {
                if (!mkdir($path, $config[$type]['permissions']['directory'], true)) {
                    error_log("Failed to create directory: {$path}");
                    continue;
                }
            }
            
            // Set proper permissions
            chmod($path, $config[$type]['permissions']['directory']);
            
            // Create .htaccess for security
            self::createSecurityHtaccess($path, $type);
        }
    }
    
    /**
     * Create security .htaccess files
     */
    private static function createSecurityHtaccess($directory, $type) {
        $htaccessPath = $directory . '/.htaccess';
        
        $content = "# Security configuration for {$type} directory\n";
        $content .= "Options -Indexes -ExecCGI\n";
        $content .= "AddHandler cgi-script .php .pl .py .jsp .asp .sh .cgi\n";
        $content .= "\n";
        
        if ($type === 'uploads') {
            $content .= "# Route file access through secure endpoint\n";
            $content .= "<IfModule mod_rewrite.c>\n";
            $content .= "    RewriteEngine On\n";
            $content .= "    RewriteCond %{REQUEST_FILENAME} -f\n";
            $content .= "    RewriteRule ^(.+)$ ../api/secure_file.php?path={$type}/$1 [L,QSA]\n";
            $content .= "</IfModule>\n\n";
        } else {
            $content .= "# Deny all access\n";
            $content .= "<RequireAll>\n";
            $content .= "    Require all denied\n";
            $content .= "</RequireAll>\n\n";
        }
        
        $content .= "# Block dangerous file types\n";
        $content .= "<FilesMatch \"\\.(php|php3|php4|php5|phtml|phps|asp|aspx|jsp|cgi|pl|py|rb|exe|com|bat|cmd|scr|vbs|js|sh|bash)$\">\n";
        $content .= "    <RequireAll>\n";
        $content .= "        Require all denied\n";
        $content .= "    </RequireAll>\n";
        $content .= "</FilesMatch>\n\n";
        
        $content .= "# Block hidden files\n";
        $content .= "<FilesMatch \"^\\.*\">\n";
        $content .= "    <RequireAll>\n";
        $content .= "        Require all denied\n";
        $content .= "    </RequireAll>\n";
        $content .= "</FilesMatch>\n";
        
        file_put_contents($htaccessPath, $content);
        chmod($htaccessPath, 0644);
    }
    
    /**
     * Validate file upload
     */
    public static function validateUpload($file, $type = 'document') {
        $config = self::getConfig();
        $uploadConfig = $config['uploads'];
        $securityConfig = $config['security'];
        
        // Check if file type is allowed
        if (!isset($uploadConfig['allowed_types'][$type])) {
            return ['valid' => false, 'error' => 'Invalid file type category'];
        }
        
        $typeConfig = $uploadConfig['allowed_types'][$type];
        
        // Check file size
        if ($file['size'] > $typeConfig['max_size']) {
            return ['valid' => false, 'error' => 'File size exceeds limit'];
        }
        
        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $typeConfig['extensions'])) {
            return ['valid' => false, 'error' => 'File extension not allowed'];
        }
        
        // Check for blocked extensions
        if (in_array($extension, $securityConfig['blocked_extensions'])) {
            return ['valid' => false, 'error' => 'File extension is blocked for security'];
        }
        
        // Validate MIME type
        if ($securityConfig['validate_mime_type']) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $typeConfig['mime_types'])) {
                return ['valid' => false, 'error' => 'Invalid file content type'];
            }
        }
        
        // Sanitize filename
        if ($securityConfig['sanitize_filenames']) {
            $sanitizedName = self::sanitizeFilename($file['name'], $securityConfig);
            if ($sanitizedName !== $file['name']) {
                return [
                    'valid' => true,
                    'sanitized_name' => $sanitizedName,
                    'warning' => 'Filename was sanitized for security'
                ];
            }
        }
        
        return ['valid' => true];
    }
    
    /**
     * Sanitize filename
     */
    private static function sanitizeFilename($filename, $securityConfig) {
        // Remove path traversal attempts
        $filename = basename($filename);
        
        // Remove or replace invalid characters
        $pattern = '/[^' . $securityConfig['allowed_filename_chars'] . ']/';
        $filename = preg_replace($pattern, '_', $filename);
        
        // Limit filename length
        if (strlen($filename) > $securityConfig['max_filename_length']) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $maxNameLength = $securityConfig['max_filename_length'] - strlen($extension) - 1;
            $filename = substr($name, 0, $maxNameLength) . '.' . $extension;
        }
        
        // Ensure filename is not empty
        if (empty($filename) || $filename === '.') {
            $filename = 'file_' . uniqid() . '.txt';
        }
        
        return $filename;
    }
    
    /**
     * Clean up old files
     */
    public static function cleanup() {
        $config = self::getConfig();
        
        // Clean up exports
        self::cleanupDirectory(
            $config['exports']['base_path'],
            $config['exports']['retention_days'] * 86400
        );
        
        // Clean up temp files
        self::cleanupDirectory(
            $config['temp']['base_path'],
            $config['temp']['max_age']
        );
        
        // Clean up old logs
        self::cleanupLogs(
            $config['logs']['base_path'],
            $config['logs']['retention_days'] * 86400,
            $config['logs']['max_files']
        );
        
        // Clean up cache
        self::cleanupDirectory(
            $config['cache']['base_path'],
            $config['cache']['max_age']
        );
    }
    
    /**
     * Clean up directory
     */
    private static function cleanupDirectory($directory, $maxAge) {
        if (!is_dir($directory)) {
            return;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        $now = time();
        foreach ($iterator as $file) {
            if ($file->isFile() && ($now - $file->getMTime()) > $maxAge) {
                unlink($file->getPathname());
            }
        }
    }
    
    /**
     * Clean up log files
     */
    private static function cleanupLogs($directory, $maxAge, $maxFiles) {
        if (!is_dir($directory)) {
            return;
        }
        
        $files = glob($directory . '/*.log');
        
        // Sort by modification time (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $now = time();
        foreach ($files as $index => $file) {
            // Keep only the newest files up to maxFiles limit
            if ($index >= $maxFiles || ($now - filemtime($file)) > $maxAge) {
                unlink($file);
            }
        }
    }
}