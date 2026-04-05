<?php
/**
 * Client Email Preferences Management
 * 
 * Allows clients to manage their email notification preferences
 * including opt-out options and communication settings.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/NotificationManager.php';

// Ensure user is authenticated and has client role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header('Location: ' . getBaseDir() . '/modules/auth/login.php');
    exit;
}

$clientUserId = $_SESSION['user_id'];
$notificationManager = new NotificationManager();

// Get current preferences
$currentPreferences = $notificationManager->getCommunicationPreferences($clientUserId);

// Get app settings
$settings = include(__DIR__ . '/../../config/settings.php');
$appUrl = $settings['app_url'] ?? '';
$companyName = $_SESSION['role'] === 'client' ? '' : ($settings['company_name'] ?? 'Athenaeum Transformation');

// Include the preferences template
include __DIR__ . '/../../includes/templates/email/preferences_page.php';
?>