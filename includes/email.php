<?php
require_once __DIR__ . '/../config/settings.php';

class EmailSender {
    private $settings;
    
    public function __construct() {
        $this->settings = include(__DIR__ . '/../config/settings.php');
    }
    
    public function send($to, $subject, $body, $isHtml = true) {
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid email address: $to");
            return false;
        }
        
        // Sanitize subject and from fields
        $subject = filter_var($subject, FILTER_SANITIZE_STRING);
        $fromEmail = filter_var($this->settings['mail_from'], FILTER_VALIDATE_EMAIL);
        $fromName = filter_var($this->settings['mail_from_name'], FILTER_SANITIZE_STRING);
        
        if (!$fromEmail) {
            error_log("Invalid from email address in settings");
            return false;
        }
        
        // Use PHPMailer or similar in production
        // This is a basic implementation
        
        $headers = [];
        
        if ($isHtml) {
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-type: text/html; charset=utf-8';
        } else {
            $headers[] = 'Content-type: text/plain; charset=utf-8';
        }
        
        $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'X-Mailer: PHP/' . phpversion();
        $headers[] = 'X-Priority: 3';
        
        $fullHeaders = implode("\r\n", $headers);
        
        // Use additional parameters for security
        $additionalParams = '-f ' . escapeshellarg($fromEmail);
        
        return mail($to, $subject, $body, $fullHeaders, $additionalParams);
    }
    
    public function sendWelcomeEmail($userEmail, $userName) {
        $subject = "Welcome to Project Management System";
        $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f8f9fa; }
                    .footer { text-align: center; padding: 20px; color: #6c757d; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Welcome to Project Management System</h1>
                    </div>
                    <div class='content'>
                        <h2>Hello $userName,</h2>
                        <p>Your account has been successfully created.</p>
                        <p>You can now login to the system and start managing your projects.</p>
                        <p><strong>Important:</strong> Please change your password after first login.</p>
                        <p>If you have any questions, please contact your system administrator.</p>
                    </div>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " Project Management System. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        return $this->send($userEmail, $subject, $body, true);
    }
    
    public function sendAssignmentNotification($userEmail, $userName, $projectTitle, $role) {
        $subject = "New Assignment: $projectTitle";
        $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #28a745; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f8f9fa; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #007bff; 
                              color: white; text-decoration: none; border-radius: 5px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>New Assignment Notification</h1>
                    </div>
                    <div class='content'>
                        <h2>Hello $userName,</h2>
                        <p>You have been assigned a new role in the project:</p>
                        <div style='background-color: white; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                            <p><strong>Project:</strong> $projectTitle</p>
                            <p><strong>Role:</strong> " . ucfirst(str_replace('_', ' ', $role)) . "</p>
                        </div>
                        <p>Please login to the system to view your assignments and start working.</p>
                        <p style='text-align: center; margin-top: 30px;'>
                            <a href='" . $this->settings['app_url'] . "' class='button'>Go to System</a>
                        </p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        return $this->send($userEmail, $subject, $body, true);
    }
    
    public function sendMentionNotification($userEmail, $userName, $mentionedBy, $message, $link) {
        $subject = "You were mentioned in a conversation";
        $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #ffc107; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f8f9fa; }
                    .message { background-color: white; padding: 15px; border-left: 4px solid #ffc107; 
                              margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Mention Notification</h1>
                    </div>
                    <div class='content'>
                        <h2>Hello $userName,</h2>
                        <p>You were mentioned by <strong>$mentionedBy</strong> in a conversation.</p>
                        <div class='message'>
                            <p><em>$message</em></p>
                        </div>
                        <p>Click the link below to view the conversation:</p>
                        <p><a href='$link'>$link</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        return $this->send($userEmail, $subject, $body, true);
    }
}
?>