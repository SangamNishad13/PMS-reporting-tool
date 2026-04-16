<?php
require_once __DIR__ . '/config/settings.php';
$settings = require __DIR__ . '/config/settings.php';
echo "App URL: " . $settings['app_url'] . "\n";
echo "Company Logo: " . $settings['company_logo'] . "\n";

// Check if file exists in filesystem
$logoPath = __DIR__ . '/storage/SIS-Logo-3.png';
echo "File Exists: " . (file_exists($logoPath) ? 'Yes' : 'No') . "\n";
echo "File Size: " . filesize($logoPath) . " bytes\n";
