<?php
// Derive app URL dynamically when APP_URL is not provided.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '';
if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
    $scriptDir = '';
}
$derivedAppUrl = rtrim($scheme . '://' . $host . $scriptDir, '/');

// Application Settings
return [
    // Application
    'app_name' => 'Project Management System',
    'app_version' => '1.0.0',
    'app_url' => getenv('APP_URL') ?: $derivedAppUrl,
    
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
    'mail_from' => getenv('MAIL_FROM') ?: 'noreply@athenaeumtransformation.com',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'Athenaeum PMS',
    'smtp_host' => getenv('SMTP_HOST') ?: 'mail.athenaeumtransformation.com',
    'smtp_port' => (int)(getenv('SMTP_PORT') ?: 465),
    'smtp_secure' => getenv('SMTP_SECURE') ?: 'ssl',
    'smtp_auth' => (function () {
        $v = getenv('SMTP_AUTH');
        if ($v === false || $v === '') return true;
        return !in_array(strtolower(trim((string)$v)), ['0', 'false', 'no', 'off'], true);
    })(),
    'smtp_username' => getenv('SMTP_USERNAME') ?: 'noreply@athenaeumtransformation.com',
    'smtp_password' => getenv('SMTP_PASSWORD') ?: 'Sakshi@2026',
    
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
