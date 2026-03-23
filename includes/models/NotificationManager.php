<?php
/**
 * NotificationManager Class
 * 
 * Handles email notifications for client project assignments and revocations.
 * Integrates with the existing EmailSender infrastructure and provides
 * professional email templates with branding.
 * 
 * Requirements: 2.3, 19.1, 19.2, 19.3
 */

require_once __DIR__ . '/../email.php';

class NotificationManager {
    private $db;
    private $emailSender;
    private $settings;
    
    public function __construct($database = null) {
        global $db;
        $this->db = $database ?: $db;
        $this->emailSender = new EmailSender();
        $this->settings = include(__DIR__ . '/../../config/settings.php');
    }
    
    /**
     * Send project assignment notification to client
     * 
     * @param int $clientUserId Client user ID
     * @param array $projects Array of project data
     * @param int $adminUserId Admin who made the assignment
     * @return bool Success status
     */
    public function sendProjectAssignmentNotification($clientUserId, $projects, $adminUserId) {
        try {
            // Get client user details
            $clientData = $this->getClientUserData($clientUserId);
            if (!$clientData) {
                error_log("NotificationManager: Client user not found: $clientUserId");
                return false;
            }
            
            // Get admin user details
            $adminData = $this->getAdminUserData($adminUserId);
            if (!$adminData) {
                error_log("NotificationManager: Admin user not found: $adminUserId");
                return false;
            }
            
            // Generate email content
            $subject = $this->generateAssignmentSubject($projects);
            $body = $this->generateAssignmentEmailBody($clientData, $projects, $adminData);
            
            // Send email
            $success = $this->emailSender->send(
                $clientData['email'],
                $subject,
                $body,
                true // HTML format
            );
            
            // Log notification attempt
            $this->logNotification(
                $clientUserId,
                'project_assignment',
                $success,
                [
                    'project_count' => count($projects),
                    'project_ids' => array_column($projects, 'id'),
                    'admin_id' => $adminUserId
                ]
            );
            
            return $success;
            
        } catch (Exception $e) {
            error_log('NotificationManager sendProjectAssignmentNotification error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send project revocation notification to client
     * 
     * @param int $clientUserId Client user ID
     * @param string $projectTitle Project title being revoked
     * @param int $adminUserId Admin who made the revocation
     * @param string $reason Optional reason for revocation
     * @return bool Success status
     */
    public function sendProjectRevocationNotification($clientUserId, $projectTitle, $adminUserId, $reason = null) {
        try {
            // Get client user details
            $clientData = $this->getClientUserData($clientUserId);
            if (!$clientData) {
                error_log("NotificationManager: Client user not found: $clientUserId");
                return false;
            }
            
            // Get admin user details
            $adminData = $this->getAdminUserData($adminUserId);
            if (!$adminData) {
                error_log("NotificationManager: Admin user not found: $adminUserId");
                return false;
            }
            
            // Generate email content
            $subject = "Project Access Revoked: " . $projectTitle;
            $body = $this->generateRevocationEmailBody($clientData, $projectTitle, $adminData, $reason);
            
            // Send email
            $success = $this->emailSender->send(
                $clientData['email'],
                $subject,
                $body,
                true // HTML format
            );
            
            // Log notification attempt
            $this->logNotification(
                $clientUserId,
                'project_revocation',
                $success,
                [
                    'project_title' => $projectTitle,
                    'admin_id' => $adminUserId,
                    'reason' => $reason
                ]
            );
            
            return $success;
            
        } catch (Exception $e) {
            error_log('NotificationManager sendProjectRevocationNotification error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send periodic summary email with key accessibility metrics
     * 
     * @param int $clientUserId Client user ID
     * @param array $summaryData Analytics summary data
     * @return bool Success status
     */
    public function sendPeriodicSummaryEmail($clientUserId, $summaryData) {
        try {
            // Get client user details
            $clientData = $this->getClientUserData($clientUserId);
            if (!$clientData) {
                error_log("NotificationManager: Client user not found: $clientUserId");
                return false;
            }
            
            // Check if client has opted out of summary emails
            if (!$this->isClientOptedInForSummaries($clientUserId)) {
                return true; // Not an error, just skipped
            }
            
            // Generate email content
            $subject = "Weekly Accessibility Report Summary";
            $body = $this->generateSummaryEmailBody($clientData, $summaryData);
            
            // Send email
            $success = $this->emailSender->send(
                $clientData['email'],
                $subject,
                $body,
                true // HTML format
            );
            
            // Log notification attempt
            $this->logNotification(
                $clientUserId,
                'periodic_summary',
                $success,
                [
                    'summary_period' => $summaryData['period'] ?? 'weekly',
                    'project_count' => count($summaryData['projects'] ?? [])
                ]
            );
            
            return $success;
            
        } catch (Exception $e) {
            error_log('NotificationManager sendPeriodicSummaryEmail error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get client user data for notifications
     * 
     * @param int $clientUserId Client user ID
     * @return array|null Client data or null if not found
     */
    private function getClientUserData($clientUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, email, full_name, is_active
                FROM users 
                WHERE id = ? AND role = 'client' AND is_active = 1
            ");
            $stmt->execute([$clientUserId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('NotificationManager getClientUserData error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get admin user data for notifications
     * 
     * @param int $adminUserId Admin user ID
     * @return array|null Admin data or null if not found
     */
    private function getAdminUserData($adminUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, email, full_name
                FROM users 
                WHERE id = ? AND role IN ('admin') AND is_active = 1
            ");
            $stmt->execute([$adminUserId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('NotificationManager getAdminUserData error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate subject line for assignment notification
     * 
     * @param array $projects Array of project data
     * @return string Email subject
     */
    private function generateAssignmentSubject($projects) {
        $count = count($projects);
        if ($count === 1) {
            return "New Project Access Granted: " . $projects[0]['title'];
        } else {
            return "New Project Access Granted: $count Projects";
        }
    }
    
    /**
     * Generate HTML email body for project assignment notification
     * 
     * @param array $clientData Client user data
     * @param array $projects Array of project data
     * @param array $adminData Admin user data
     * @return string HTML email body
     */
    private function generateAssignmentEmailBody($clientData, $projects, $adminData) {
        // Use the professional template file
        $templatePath = __DIR__ . '/../templates/email/assignment_notification.php';
        
        if (file_exists($templatePath)) {
            // Extract variables for template
            $clientName = $clientData['full_name'] ?: $clientData['username'];
            $adminName = $adminData['full_name'] ?: $adminData['username'];
            $appUrl = $this->settings['app_url'] ?? '#';
            $companyName = $this->settings['company_name'] ?? 'Athenaeum Transformation';
            $unsubscribeUrl = ($this->settings['app_url'] ?? '') . '/preferences?client_id=' . $clientData['id'];
            
            // Start output buffering
            ob_start();
            include $templatePath;
            $emailBody = ob_get_clean();
            
            return $emailBody;
        }
        
        // Fallback to inline template if file doesn't exist
        $clientName = htmlspecialchars($clientData['full_name'] ?: $clientData['username']);
        $adminName = htmlspecialchars($adminData['full_name'] ?: $adminData['username']);
        $appUrl = $this->settings['app_url'] ?? '#';
        $companyName = $this->settings['company_name'] ?? 'Athenaeum Transformation';
        $currentYear = date('Y');
        
        // Build project list
        $projectList = '';
        foreach ($projects as $project) {
            $projectTitle = htmlspecialchars($project['title']);
            $projectCode = htmlspecialchars($project['project_code'] ?? '');
            $projectDescription = htmlspecialchars($project['description'] ?? '');
            
            $projectList .= "
                <div style='background-color: white; padding: 15px; margin: 10px 0; border-radius: 8px; border-left: 4px solid #007bff;'>
                    <h3 style='margin: 0 0 10px 0; color: #007bff;'>$projectTitle</h3>";
            
            if ($projectCode) {
                $projectList .= "<p style='margin: 5px 0; color: #6c757d;'><strong>Project Code:</strong> $projectCode</p>";
            }
            
            if ($projectDescription) {
                $projectList .= "<p style='margin: 5px 0; color: #495057;'>$projectDescription</p>";
            }
            
            $projectList .= "</div>";
        }
        
        $projectCount = count($projects);
        $projectWord = $projectCount === 1 ? 'project' : 'projects';
        
        return "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Project Access Granted</title>
                <style>
                    body { 
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                        line-height: 1.6; 
                        margin: 0; 
                        padding: 0; 
                        background-color: #f8f9fa; 
                    }
                    .container { 
                        max-width: 600px; 
                        margin: 20px auto; 
                        background-color: white; 
                        border-radius: 10px; 
                        overflow: hidden; 
                        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
                    }
                    .header { 
                        background: linear-gradient(135deg, #007bff, #0056b3); 
                        color: white; 
                        padding: 30px 20px; 
                        text-align: center; 
                    }
                    .header h1 { 
                        margin: 0; 
                        font-size: 24px; 
                        font-weight: 600; 
                    }
                    .content { 
                        padding: 30px 20px; 
                        background-color: #f8f9fa; 
                    }
                    .welcome { 
                        background-color: white; 
                        padding: 20px; 
                        border-radius: 8px; 
                        margin-bottom: 20px; 
                    }
                    .button { 
                        display: inline-block; 
                        padding: 12px 30px; 
                        background: linear-gradient(135deg, #28a745, #20c997); 
                        color: white; 
                        text-decoration: none; 
                        border-radius: 25px; 
                        font-weight: 600; 
                        text-align: center; 
                        transition: transform 0.2s; 
                    }
                    .button:hover { 
                        transform: translateY(-2px); 
                    }
                    .footer { 
                        background-color: #343a40; 
                        color: #adb5bd; 
                        text-align: center; 
                        padding: 20px; 
                        font-size: 14px; 
                    }
                    .footer a { 
                        color: #007bff; 
                        text-decoration: none; 
                    }
                    .highlight { 
                        background-color: #e3f2fd; 
                        padding: 15px; 
                        border-radius: 8px; 
                        margin: 15px 0; 
                        border-left: 4px solid #2196f3; 
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>🎉 Project Access Granted</h1>
                        <p style='margin: 10px 0 0 0; opacity: 0.9;'>You now have access to new accessibility reporting</p>
                    </div>
                    <div class='content'>
                        <div class='welcome'>
                            <h2 style='color: #007bff; margin-top: 0;'>Hello $clientName,</h2>
                            <p>Great news! You have been granted access to <strong>$projectCount $projectWord</strong> in our accessibility reporting system.</p>
                            <p>You can now view detailed analytics, compliance reports, and export data for your assigned projects.</p>
                        </div>
                        
                        <div class='highlight'>
                            <h3 style='margin-top: 0; color: #495057;'>📋 Your New Project Access:</h3>
                            $projectList
                        </div>
                        
                        <div style='background-color: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                            <h3 style='color: #28a745; margin-top: 0;'>🚀 What You Can Do:</h3>
                            <ul style='color: #495057; padding-left: 20px;'>
                                <li><strong>View Analytics:</strong> Access 8 comprehensive accessibility reports</li>
                                <li><strong>Export Data:</strong> Download reports in PDF or Excel format</li>
                                <li><strong>Track Progress:</strong> Monitor compliance trends over time</li>
                                <li><strong>Individual & Unified Views:</strong> See project-specific or combined analytics</li>
                            </ul>
                        </div>
                        
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='$appUrl' class='button'>🔗 Access Your Dashboard</a>
                        </div>
                        
                        <div style='background-color: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                            <p style='margin: 0; color: #856404;'><strong>📧 Need Help?</strong> If you have any questions about accessing your reports or using the system, please contact your project administrator: <strong>$adminName</strong></p>
                        </div>
                    </div>
                    <div class='footer'>
                        <p>&copy; $currentYear $companyName. All rights reserved.</p>
                        <p>This is an automated notification from the Project Management System.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
    }
    
    /**
     * Generate HTML email body for project revocation notification
     * 
     * @param array $clientData Client user data
     * @param string $projectTitle Project title being revoked
     * @param array $adminData Admin user data
     * @param string|null $reason Optional reason for revocation
     * @return string HTML email body
     */
    private function generateRevocationEmailBody($clientData, $projectTitle, $adminData, $reason = null) {
        // Use the professional template file
        $templatePath = __DIR__ . '/../templates/email/revocation_notification.php';
        
        if (file_exists($templatePath)) {
            // Extract variables for template
            $clientName = $clientData['full_name'] ?: $clientData['username'];
            $adminName = $adminData['full_name'] ?: $adminData['username'];
            $appUrl = $this->settings['app_url'] ?? '#';
            $companyName = $this->settings['company_name'] ?? 'Athenaeum Transformation';
            $unsubscribeUrl = ($this->settings['app_url'] ?? '') . '/preferences?client_id=' . $clientData['id'];
            
            // Start output buffering
            ob_start();
            include $templatePath;
            $emailBody = ob_get_clean();
            
            return $emailBody;
        }
        
        // Fallback to inline template if file doesn't exist
        $clientName = htmlspecialchars($clientData['full_name'] ?: $clientData['username']);
        $adminName = htmlspecialchars($adminData['full_name'] ?: $adminData['username']);
        $projectTitle = htmlspecialchars($projectTitle);
        $appUrl = $this->settings['app_url'] ?? '#';
        $companyName = $this->settings['company_name'] ?? 'Athenaeum Transformation';
        $currentYear = date('Y');
        $effectiveDate = date('F j, Y');
        
        $reasonSection = '';
        if ($reason) {
            $reason = htmlspecialchars($reason);
            $reasonSection = "
                <div style='background-color: #e3f2fd; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #2196f3;'>
                    <h4 style='margin-top: 0; color: #1976d2;'>📝 Reason for Change:</h4>
                    <p style='margin-bottom: 0; color: #495057;'>$reason</p>
                </div>
            ";
        }
        
        return "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Project Access Updated</title>
                <style>
                    body { 
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                        line-height: 1.6; 
                        margin: 0; 
                        padding: 0; 
                        background-color: #f8f9fa; 
                    }
                    .container { 
                        max-width: 600px; 
                        margin: 20px auto; 
                        background-color: white; 
                        border-radius: 10px; 
                        overflow: hidden; 
                        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
                    }
                    .header { 
                        background: linear-gradient(135deg, #dc3545, #c82333); 
                        color: white; 
                        padding: 30px 20px; 
                        text-align: center; 
                    }
                    .header h1 { 
                        margin: 0; 
                        font-size: 24px; 
                        font-weight: 600; 
                    }
                    .content { 
                        padding: 30px 20px; 
                        background-color: #f8f9fa; 
                    }
                    .notice { 
                        background-color: white; 
                        padding: 20px; 
                        border-radius: 8px; 
                        margin-bottom: 20px; 
                    }
                    .button { 
                        display: inline-block; 
                        padding: 12px 30px; 
                        background: linear-gradient(135deg, #007bff, #0056b3); 
                        color: white; 
                        text-decoration: none; 
                        border-radius: 25px; 
                        font-weight: 600; 
                        text-align: center; 
                        transition: transform 0.2s; 
                    }
                    .button:hover { 
                        transform: translateY(-2px); 
                    }
                    .footer { 
                        background-color: #343a40; 
                        color: #adb5bd; 
                        text-align: center; 
                        padding: 20px; 
                        font-size: 14px; 
                    }
                    .footer a { 
                        color: #007bff; 
                        text-decoration: none; 
                    }
                    .project-info { 
                        background-color: #fff3cd; 
                        padding: 15px; 
                        border-radius: 8px; 
                        margin: 15px 0; 
                        border-left: 4px solid #ffc107; 
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>📋 Project Access Updated</h1>
                        <p style='margin: 10px 0 0 0; opacity: 0.9;'>Your project access has been modified</p>
                    </div>
                    <div class='content'>
                        <div class='notice'>
                            <h2 style='color: #dc3545; margin-top: 0;'>Hello $clientName,</h2>
                            <p>We're writing to inform you that your access to the following project has been updated:</p>
                        </div>
                        
                        <div class='project-info'>
                            <h3 style='margin-top: 0; color: #856404;'>📁 Project: $projectTitle</h3>
                            <p style='margin: 5px 0; color: #856404;'><strong>Effective Date:</strong> $effectiveDate</p>
                            <p style='margin: 5px 0; color: #856404;'><strong>Updated By:</strong> $adminName</p>
                        </div>
                        
                        $reasonSection
                        
                        <div style='background-color: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                            <h3 style='color: #495057; margin-top: 0;'>ℹ️ What This Means:</h3>
                            <ul style='color: #495057; padding-left: 20px;'>
                                <li>You no longer have access to analytics reports for this project</li>
                                <li>Any existing exported reports remain valid</li>
                                <li>Your access to other assigned projects is unchanged</li>
                                <li>You can still access your remaining projects through the dashboard</li>
                            </ul>
                        </div>
                        
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='$appUrl' class='button'>🔗 View Your Dashboard</a>
                        </div>
                        
                        <div style='background-color: #d1ecf1; padding: 15px; border-radius: 8px; border-left: 4px solid #17a2b8; margin: 20px 0;'>
                            <p style='margin: 0; color: #0c5460;'><strong>📞 Questions?</strong> If you have any questions about this change or need access to different projects, please contact your project administrator: <strong>$adminName</strong></p>
                        </div>
                    </div>
                    <div class='footer'>
                        <p>&copy; $currentYear $companyName. All rights reserved.</p>
                        <p>This is an automated notification from the Project Management System.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
    }
    
    /**
     * Generate HTML email body for periodic summary notification
     * 
     * @param array $clientData Client user data
     * @param array $summaryData Analytics summary data
     * @return string HTML email body
     */
    private function generateSummaryEmailBody($clientData, $summaryData) {
        // Use the professional template file
        $templatePath = __DIR__ . '/../templates/email/periodic_summary.php';
        
        if (file_exists($templatePath)) {
            // Extract variables for template
            $clientName = $clientData['full_name'] ?: $clientData['username'];
            $appUrl = $this->settings['app_url'] ?? '#';
            $companyName = $this->settings['company_name'] ?? 'Athenaeum Transformation';
            $unsubscribeUrl = ($this->settings['app_url'] ?? '') . '/preferences?client_id=' . $clientData['id'];
            
            // Start output buffering
            ob_start();
            include $templatePath;
            $emailBody = ob_get_clean();
            
            return $emailBody;
        }
        
        // Fallback to inline template if file doesn't exist
        $clientName = htmlspecialchars($clientData['full_name'] ?: $clientData['username']);
        $appUrl = $this->settings['app_url'] ?? '#';
        $companyName = $this->settings['company_name'] ?? 'Athenaeum Transformation';
        $currentYear = date('Y');
        $period = $summaryData['period'] ?? 'weekly';
        $periodTitle = ucfirst($period);
        
        // Build summary metrics
        $totalIssues = $summaryData['total_issues'] ?? 0;
        $resolvedIssues = $summaryData['resolved_issues'] ?? 0;
        $criticalIssues = $summaryData['critical_issues'] ?? 0;
        $projectCount = count($summaryData['projects'] ?? []);
        
        $resolutionRate = $totalIssues > 0 ? round(($resolvedIssues / $totalIssues) * 100, 1) : 0;
        
        // Build project summaries
        $projectSummaries = '';
        if (!empty($summaryData['projects'])) {
            foreach ($summaryData['projects'] as $project) {
                $projectTitle = htmlspecialchars($project['title']);
                $projectIssues = $project['issues'] ?? 0;
                $projectResolved = $project['resolved'] ?? 0;
                $projectRate = $projectIssues > 0 ? round(($projectResolved / $projectIssues) * 100, 1) : 0;
                
                $projectSummaries .= "
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #dee2e6;'>$projectTitle</td>
                        <td style='padding: 10px; border-bottom: 1px solid #dee2e6; text-align: center;'>$projectIssues</td>
                        <td style='padding: 10px; border-bottom: 1px solid #dee2e6; text-align: center;'>$projectResolved</td>
                        <td style='padding: 10px; border-bottom: 1px solid #dee2e6; text-align: center;'>$projectRate%</td>
                    </tr>
                ";
            }
        }
        
        return "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>$periodTitle Accessibility Report Summary</title>
                <style>
                    body { 
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                        line-height: 1.6; 
                        margin: 0; 
                        padding: 0; 
                        background-color: #f8f9fa; 
                    }
                    .container { 
                        max-width: 600px; 
                        margin: 20px auto; 
                        background-color: white; 
                        border-radius: 10px; 
                        overflow: hidden; 
                        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
                    }
                    .header { 
                        background: linear-gradient(135deg, #6f42c1, #5a32a3); 
                        color: white; 
                        padding: 30px 20px; 
                        text-align: center; 
                    }
                    .header h1 { 
                        margin: 0; 
                        font-size: 24px; 
                        font-weight: 600; 
                    }
                    .content { 
                        padding: 30px 20px; 
                        background-color: #f8f9fa; 
                    }
                    .metrics { 
                        display: flex; 
                        justify-content: space-around; 
                        background-color: white; 
                        padding: 20px; 
                        border-radius: 8px; 
                        margin: 20px 0; 
                    }
                    .metric { 
                        text-align: center; 
                    }
                    .metric-value { 
                        font-size: 24px; 
                        font-weight: bold; 
                        color: #007bff; 
                    }
                    .metric-label { 
                        font-size: 12px; 
                        color: #6c757d; 
                        text-transform: uppercase; 
                    }
                    table { 
                        width: 100%; 
                        border-collapse: collapse; 
                        background-color: white; 
                        border-radius: 8px; 
                        overflow: hidden; 
                    }
                    th { 
                        background-color: #007bff; 
                        color: white; 
                        padding: 12px; 
                        text-align: left; 
                    }
                    .button { 
                        display: inline-block; 
                        padding: 12px 30px; 
                        background: linear-gradient(135deg, #28a745, #20c997); 
                        color: white; 
                        text-decoration: none; 
                        border-radius: 25px; 
                        font-weight: 600; 
                        text-align: center; 
                        transition: transform 0.2s; 
                    }
                    .footer { 
                        background-color: #343a40; 
                        color: #adb5bd; 
                        text-align: center; 
                        padding: 20px; 
                        font-size: 14px; 
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>📊 $periodTitle Summary Report</h1>
                        <p style='margin: 10px 0 0 0; opacity: 0.9;'>Your accessibility progress overview</p>
                    </div>
                    <div class='content'>
                        <div style='background-color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;'>
                            <h2 style='color: #6f42c1; margin-top: 0;'>Hello $clientName,</h2>
                            <p>Here's your $period accessibility report summary for your $projectCount assigned projects.</p>
                        </div>
                        
                        <div class='metrics'>
                            <div class='metric'>
                                <div class='metric-value'>$totalIssues</div>
                                <div class='metric-label'>Total Issues</div>
                            </div>
                            <div class='metric'>
                                <div class='metric-value'>$resolvedIssues</div>
                                <div class='metric-label'>Resolved</div>
                            </div>
                            <div class='metric'>
                                <div class='metric-value'>$criticalIssues</div>
                                <div class='metric-label'>Critical</div>
                            </div>
                            <div class='metric'>
                                <div class='metric-value'>$resolutionRate%</div>
                                <div class='metric-label'>Resolution Rate</div>
                            </div>
                        </div>
                        
                        <div style='margin: 20px 0;'>
                            <h3 style='color: #495057; margin-bottom: 15px;'>📋 Project Breakdown:</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th style='text-align: center;'>Issues</th>
                                        <th style='text-align: center;'>Resolved</th>
                                        <th style='text-align: center;'>Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    $projectSummaries
                                </tbody>
                            </table>
                        </div>
                        
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='$appUrl' class='button'>📈 View Full Dashboard</a>
                        </div>
                        
                        <div style='background-color: #d4edda; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745; margin: 20px 0; font-size: 14px;'>
                            <p style='margin: 0; color: #155724;'><strong>💡 Tip:</strong> You can adjust your email preferences or opt out of these summaries in your dashboard settings.</p>
                        </div>
                    </div>
                    <div class='footer'>
                        <p>&copy; $currentYear $companyName. All rights reserved.</p>
                        <p>This is an automated $period summary from the Project Management System.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
    }
    
    /**
     * Check if client is opted in for summary emails
     * 
     * @param int $clientUserId Client user ID
     * @return bool True if opted in, false otherwise
     */
    private function isClientOptedInForSummaries($clientUserId) {
        try {
            // Check for user preference in database
            // For now, default to true (opted in) unless explicitly opted out
            $stmt = $this->db->prepare("
                SELECT meta_value 
                FROM user_meta 
                WHERE user_id = ? AND meta_key = 'email_summary_opt_out'
            ");
            $stmt->execute([$clientUserId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If no preference set or not opted out, default to opted in
            return !($result && $result['meta_value'] === '1');
            
        } catch (Exception $e) {
            // If table doesn't exist or error occurs, default to opted in
            return true;
        }
    }
    
    /**
     * Log notification attempt for audit trail
     * 
     * @param int $clientUserId Client user ID
     * @param string $notificationType Type of notification
     * @param bool $success Whether notification was successful
     * @param array $details Additional details
     */
    private function logNotification($clientUserId, $notificationType, $success, $details = []) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log (
                    client_user_id, 
                    action_type, 
                    resource_type, 
                    action_details, 
                    success, 
                    error_message,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $actionDetails = json_encode(array_merge($details, [
                'notification_type' => $notificationType,
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            $errorMessage = $success ? null : 'Email delivery failed';
            
            $stmt->execute([
                $clientUserId,
                'email_notification',
                'notification',
                $actionDetails,
                $success ? 1 : 0,
                $errorMessage
            ]);
            
        } catch (Exception $e) {
            // Don't fail the main operation if logging fails
            error_log('NotificationManager logNotification error: ' . $e->getMessage());
        }
    }
    
    /**
     * Update client communication preferences
     * 
     * @param int $clientUserId Client user ID
     * @param array $preferences Preference settings
     * @return bool Success status
     */
    public function updateCommunicationPreferences($clientUserId, $preferences) {
        try {
            $this->db->beginTransaction();
            
            foreach ($preferences as $key => $value) {
                $metaKey = 'email_' . $key;
                
                // Check if preference already exists
                $stmt = $this->db->prepare("
                    SELECT id FROM user_meta 
                    WHERE user_id = ? AND meta_key = ?
                ");
                $stmt->execute([$clientUserId, $metaKey]);
                
                if ($stmt->fetch()) {
                    // Update existing preference
                    $updateStmt = $this->db->prepare("
                        UPDATE user_meta 
                        SET meta_value = ?, updated_at = NOW() 
                        WHERE user_id = ? AND meta_key = ?
                    ");
                    $updateStmt->execute([$value, $clientUserId, $metaKey]);
                } else {
                    // Insert new preference
                    $insertStmt = $this->db->prepare("
                        INSERT INTO user_meta (user_id, meta_key, meta_value, created_at, updated_at) 
                        VALUES (?, ?, ?, NOW(), NOW())
                    ");
                    $insertStmt->execute([$clientUserId, $metaKey, $value]);
                }
            }
            
            $this->db->commit();
            
            // Log preference update
            $this->logNotification(
                $clientUserId,
                'preference_update',
                true,
                ['preferences' => $preferences]
            );
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('NotificationManager updateCommunicationPreferences error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get client communication preferences
     * 
     * @param int $clientUserId Client user ID
     * @return array Preference settings
     */
    public function getCommunicationPreferences($clientUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT meta_key, meta_value 
                FROM user_meta 
                WHERE user_id = ? AND meta_key LIKE 'email_%'
            ");
            $stmt->execute([$clientUserId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $preferences = [
                'summary_opt_out' => false,
                'assignment_notifications' => true,
                'revocation_notifications' => true
            ];
            
            foreach ($results as $row) {
                $key = str_replace('email_', '', $row['meta_key']);
                $preferences[$key] = $row['meta_value'] === '1';
            }
            
            return $preferences;
            
        } catch (Exception $e) {
            error_log('NotificationManager getCommunicationPreferences error: ' . $e->getMessage());
            // Return default preferences on error
            return [
                'summary_opt_out' => false,
                'assignment_notifications' => true,
                'revocation_notifications' => true
            ];
        }
    }
    
    /**
     * Test email configuration and connectivity
     * 
     * @return array Test results
     */
    public function testEmailConfiguration() {
        try {
            $testEmail = $this->settings['mail_from'] ?? 'test@example.com';
            $testSubject = 'Email Configuration Test - ' . date('Y-m-d H:i:s');
            $testBody = 'This is a test email to verify the notification system configuration.';
            
            $success = $this->emailSender->send($testEmail, $testSubject, $testBody, false);
            
            return [
                'success' => $success,
                'message' => $success ? 'Email configuration test successful' : 'Email configuration test failed',
                'smtp_configured' => $this->emailSender->isSmtpConfigured ?? false,
                'test_timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Email configuration test failed: ' . $e->getMessage(),
                'smtp_configured' => false,
                'test_timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
}
