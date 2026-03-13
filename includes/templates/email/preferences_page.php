<?php
/**
 * Email Preferences Management Page
 * 
 * Allows clients to manage their email notification preferences
 * including opt-out options and communication settings.
 * 
 * Variables available:
 * - $clientUserId: Client user ID
 * - $currentPreferences: Array of current preference settings
 * - $appUrl: Application URL
 * - $companyName: Company name from settings
 */

require_once __DIR__ . '/../../models/NotificationManager.php';

// Initialize notification manager
$notificationManager = new NotificationManager();

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preferences'])) {
    $preferences = [
        'summary_opt_out' => isset($_POST['summary_opt_out']) ? '1' : '0',
        'assignment_notifications' => isset($_POST['assignment_notifications']) ? '1' : '0',
        'revocation_notifications' => isset($_POST['revocation_notifications']) ? '1' : '0'
    ];
    
    $success = $notificationManager->updateCommunicationPreferences($clientUserId, $preferences);
    
    if ($success) {
        $message = 'Your email preferences have been updated successfully.';
        $messageType = 'success';
        $currentPreferences = $preferences;
    } else {
        $message = 'There was an error updating your preferences. Please try again.';
        $messageType = 'error';
    }
}

// Handle unsubscribe action
if (isset($_GET['action']) && $_GET['action'] === 'unsubscribe') {
    $preferences = [
        'summary_opt_out' => '1',
        'assignment_notifications' => '0',
        'revocation_notifications' => '0'
    ];
    
    $success = $notificationManager->updateCommunicationPreferences($clientUserId, $preferences);
    
    if ($success) {
        $message = 'You have been unsubscribed from all email notifications.';
        $messageType = 'success';
        $currentPreferences = $preferences;
    } else {
        $message = 'There was an error processing your unsubscribe request. Please try again.';
        $messageType = 'error';
    }
}

$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Preferences - <?php echo htmlspecialchars($companyName); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333333;
            background-color: #f8f9fa;
            min-height: 100vh;
        }
        
        .container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: #ffffff;
            padding: 40px 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 16px;
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            font-weight: 500;
        }
        
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-section {
            background-color: #f8f9fa;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .form-section h2 {
            color: #495057;
            font-size: 20px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .preference-item {
            background-color: #ffffff;
            padding: 20px;
            margin: 15px 0;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .preference-checkbox {
            margin-top: 2px;
        }
        
        .preference-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #007bff;
        }
        
        .preference-content {
            flex: 1;
        }
        
        .preference-title {
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
        }
        
        .preference-description {
            color: #6c757d;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .form-actions {
            text-align: center;
            margin: 30px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            font-size: 16px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            margin: 0 10px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: #ffffff;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: #ffffff;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }
        
        .info-section {
            background-color: #e3f2fd;
            padding: 25px;
            border-radius: 10px;
            margin: 30px 0;
            border-left: 5px solid #2196f3;
        }
        
        .info-section h3 {
            color: #1976d2;
            font-size: 18px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .info-section p {
            color: #495057;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .info-section ul {
            color: #495057;
            font-size: 14px;
            padding-left: 20px;
        }
        
        .info-section li {
            margin-bottom: 5px;
        }
        
        .footer {
            background-color: #343a40;
            color: #adb5bd;
            text-align: center;
            padding: 30px;
            font-size: 14px;
        }
        
        .footer a {
            color: #007bff;
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        @media only screen and (max-width: 600px) {
            .container {
                margin: 20px 10px;
            }
            
            .header {
                padding: 30px 20px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .content {
                padding: 30px 20px;
            }
            
            .form-section,
            .info-section {
                padding: 20px;
            }
            
            .preference-item {
                padding: 15px;
            }
            
            .btn {
                padding: 10px 25px;
                font-size: 15px;
                margin: 5px;
                display: block;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <h1>📧 Email Preferences</h1>
            <p>Manage your notification settings</p>
        </header>
        
        <div class="content">
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-section">
                    <h2>Notification Settings</h2>
                    <p style="color: #6c757d; margin-bottom: 20px;">Choose which email notifications you'd like to receive:</p>
                    
                    <div class="preference-item">
                        <div class="preference-checkbox">
                            <input type="checkbox" 
                                   id="assignment_notifications" 
                                   name="assignment_notifications" 
                                   value="1"
                                   <?php echo (!empty($currentPreferences['assignment_notifications']) && $currentPreferences['assignment_notifications'] !== '0') ? 'checked' : ''; ?>>
                        </div>
                        <div class="preference-content">
                            <div class="preference-title">Project Assignment Notifications</div>
                            <div class="preference-description">
                                Receive emails when you're granted access to new projects or when project access is modified.
                            </div>
                        </div>
                    </div>
                    
                    <div class="preference-item">
                        <div class="preference-checkbox">
                            <input type="checkbox" 
                                   id="revocation_notifications" 
                                   name="revocation_notifications" 
                                   value="1"
                                   <?php echo (!empty($currentPreferences['revocation_notifications']) && $currentPreferences['revocation_notifications'] !== '0') ? 'checked' : ''; ?>>
                        </div>
                        <div class="preference-content">
                            <div class="preference-title">Access Change Notifications</div>
                            <div class="preference-description">
                                Receive emails when your project access is revoked or when there are changes to your permissions.
                            </div>
                        </div>
                    </div>
                    
                    <div class="preference-item">
                        <div class="preference-checkbox">
                            <input type="checkbox" 
                                   id="summary_opt_out" 
                                   name="summary_opt_out" 
                                   value="1"
                                   <?php echo (!empty($currentPreferences['summary_opt_out']) && $currentPreferences['summary_opt_out'] === '1') ? 'checked' : ''; ?>>
                        </div>
                        <div class="preference-content">
                            <div class="preference-title">Opt Out of Summary Reports</div>
                            <div class="preference-description">
                                Check this box to stop receiving periodic summary emails with accessibility metrics and progress reports.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_preferences" class="btn btn-primary">
                        💾 Save Preferences
                    </button>
                    <a href="<?php echo htmlspecialchars($appUrl); ?>" class="btn btn-secondary">
                        🔙 Back to Dashboard
                    </a>
                </div>
            </form>
            
            <div class="info-section">
                <h3>ℹ️ About Email Notifications</h3>
                <p><strong>What emails will I receive?</strong></p>
                <ul>
                    <li><strong>Assignment notifications:</strong> When you gain access to new projects</li>
                    <li><strong>Revocation notifications:</strong> When project access is removed</li>
                    <li><strong>Summary reports:</strong> Weekly or monthly accessibility progress summaries</li>
                </ul>
                <p style="margin-top: 15px;"><strong>Important:</strong> Critical system notifications and security alerts cannot be disabled and will always be sent regardless of your preferences.</p>
            </div>
        </div>
        
        <footer class="footer">
            <p>&copy; <?php echo $currentYear; ?> <?php echo htmlspecialchars($companyName); ?>. All rights reserved.</p>
            <p>
                <a href="<?php echo htmlspecialchars($appUrl); ?>">Dashboard</a> | 
                <a href="mailto:support@<?php echo strtolower(str_replace(' ', '', $companyName)); ?>.com">Contact Support</a>
            </p>
        </footer>
    </div>
</body>
</html>