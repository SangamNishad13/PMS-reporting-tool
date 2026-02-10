<?php
// Application Settings
return [
    // Application
    'app_name' => 'Project Management System',
    'app_version' => '1.0.0',
    'app_url' => 'http://localhost/project-management-system',
    
    // Security
    'session_timeout' => 1800, // 30 minutes
    'password_min_length' => 6,
    'max_login_attempts' => 5,
    'lockout_time' => 900, // 15 minutes
    
    // Upload Settings
    'upload_max_size' => 5242880, // 5MB
    'allowed_file_types' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'],
        'archive' => ['zip', 'rar']
    ],
    
    // Email Settings
    'mail_from' => 'noreply@example.com',
    'mail_from_name' => 'Project Management System',
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_auth' => true,
    'smtp_username' => 'user@example.com',
    'smtp_password' => 'password',
    
    // Features
    'allow_registration' => false,
    'allow_file_uploads' => true,
    'enable_chat' => true,
    'enable_reports' => true,
    'enable_api' => true,
    
    // Display
    'items_per_page' => 25,
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',
    'timezone' => 'UTC',
    
    // Notifications
    'notify_new_project' => true,
    'notify_assignment' => true,
    'notify_mention' => true,
    'notify_status_change' => true,
];
?>