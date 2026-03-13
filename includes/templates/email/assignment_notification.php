<?php
/**
 * Project Assignment Email Template
 * 
 * Professional template for notifying clients about new project assignments
 * with branding, responsive design, and accessibility compliance.
 * 
 * Variables available:
 * - $clientName: Client's display name
 * - $projects: Array of assigned projects
 * - $adminName: Admin who made the assignment
 * - $appUrl: Application URL
 * - $companyName: Company name from settings
 * - $unsubscribeUrl: URL for managing preferences
 */

$currentYear = date('Y');
$projectCount = count($projects);
$projectWord = $projectCount === 1 ? 'project' : 'projects';

// Build project list HTML
$projectList = '';
foreach ($projects as $project) {
    $projectTitle = htmlspecialchars($project['title']);
    $projectCode = htmlspecialchars($project['project_code'] ?? '');
    $projectDescription = htmlspecialchars($project['description'] ?? '');
    
    $projectList .= "
        <div class='project-card'>
            <h3 class='project-title'>$projectTitle</h3>";
    
    if ($projectCode) {
        $projectList .= "<p class='project-meta'><strong>Project Code:</strong> $projectCode</p>";
    }
    
    if ($projectDescription) {
        $projectList .= "<p class='project-description'>$projectDescription</p>";
    }
    
    $projectList .= "</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Project Access Granted</title>
    <style>
        /* Reset and base styles */
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
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        
        /* Container and layout */
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            letter-spacing: -0.5px;
        }
        
        .header .subtitle {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 400;
        }
        
        .content {
            padding: 40px 30px;
            background-color: #f8f9fa;
        }
        
        .welcome-section {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        
        .welcome-section h2 {
            color: #007bff;
            font-size: 24px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        
        .welcome-section p {
            color: #495057;
            font-size: 16px;
            margin-bottom: 16px;
        }
        
        /* Project cards */
        .projects-section {
            background-color: #e3f2fd;
            padding: 30px;
            border-radius: 10px;
            margin: 30px 0;
            border-left: 5px solid #2196f3;
        }
        
        .projects-section h3 {
            color: #1976d2;
            font-size: 20px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .project-card {
            background-color: #ffffff;
            padding: 20px;
            margin: 15px 0;
            border-radius: 8px;
            border-left: 4px solid #007bff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .project-title {
            color: #007bff;
            font-size: 18px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .project-meta {
            color: #6c757d;
            font-size: 14px;
            margin: 8px 0;
        }
        
        .project-description {
            color: #495057;
            font-size: 15px;
            margin: 10px 0 0 0;
        }
        
        /* Features section */
        .features-section {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 10px;
            margin: 30px 0;
            border: 1px solid #e9ecef;
        }
        
        .features-section h3 {
            color: #28a745;
            font-size: 20px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .features-list {
            list-style: none;
            padding: 0;
        }
        
        .features-list li {
            color: #495057;
            font-size: 15px;
            margin: 12px 0;
            padding-left: 30px;
            position: relative;
        }
        
        .features-list li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
            font-size: 16px;
        }
        
        /* Call to action button */
        .cta-section {
            text-align: center;
            margin: 40px 0;
        }
        
        .cta-button {
            display: inline-block;
            padding: 16px 32px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: #ffffff;
            text-decoration: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 16px;
            transition: transform 0.2s ease;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .cta-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(40, 167, 69, 0.4);
        }
        
        /* Help section */
        .help-section {
            background-color: #fff3cd;
            padding: 25px;
            border-radius: 10px;
            margin: 30px 0;
            border-left: 5px solid #ffc107;
        }
        
        .help-section p {
            color: #856404;
            font-size: 15px;
            margin: 0;
        }
        
        /* Footer */
        .footer {
            background-color: #343a40;
            color: #adb5bd;
            text-align: center;
            padding: 30px;
            font-size: 14px;
        }
        
        .footer p {
            margin: 8px 0;
        }
        
        .footer a {
            color: #007bff;
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        .unsubscribe-link {
            font-size: 12px;
            color: #6c757d;
            margin-top: 15px;
        }
        
        /* Responsive design */
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 10px;
                border-radius: 8px;
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
            
            .welcome-section,
            .projects-section,
            .features-section {
                padding: 20px;
            }
            
            .project-card {
                padding: 15px;
            }
            
            .cta-button {
                padding: 14px 28px;
                font-size: 15px;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .email-container {
                background-color: #1a1a1a;
            }
            
            .welcome-section,
            .features-section {
                background-color: #2d2d2d;
                border-color: #404040;
            }
            
            .project-card {
                background-color: #2d2d2d;
            }
            
            .content {
                background-color: #1a1a1a;
            }
            
            .welcome-section h2 {
                color: #4dabf7;
            }
            
            .welcome-section p,
            .features-list li,
            .project-description {
                color: #e9ecef;
            }
            
            .project-meta {
                color: #adb5bd;
            }
        }
        
        /* Accessibility improvements */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
    </style>
</head>
<body>
    <div class="email-container" role="main">
        <header class="header">
            <h1>🎉 Project Access Granted</h1>
            <p class="subtitle">You now have access to new accessibility reporting</p>
        </header>
        
        <div class="content">
            <section class="welcome-section">
                <h2>Hello <?php echo htmlspecialchars($clientName); ?>,</h2>
                <p>Great news! You have been granted access to <strong><?php echo $projectCount; ?> <?php echo $projectWord; ?></strong> in our accessibility reporting system.</p>
                <p>You can now view detailed analytics, compliance reports, and export data for your assigned projects.</p>
            </section>
            
            <section class="projects-section">
                <h3>📋 Your New Project Access:</h3>
                <?php echo $projectList; ?>
            </section>
            
            <section class="features-section">
                <h3>🚀 What You Can Do:</h3>
                <ul class="features-list">
                    <li><strong>View Analytics:</strong> Access 8 comprehensive accessibility reports</li>
                    <li><strong>Export Data:</strong> Download reports in PDF or Excel format</li>
                    <li><strong>Track Progress:</strong> Monitor compliance trends over time</li>
                    <li><strong>Individual & Unified Views:</strong> See project-specific or combined analytics</li>
                </ul>
            </section>
            
            <div class="cta-section">
                <a href="<?php echo htmlspecialchars($appUrl); ?>" class="cta-button">
                    🔗 Access Your Dashboard
                </a>
            </div>
            
            <section class="help-section">
                <p><strong>📧 Need Help?</strong> If you have any questions about accessing your reports or using the system, please contact your project administrator: <strong><?php echo htmlspecialchars($adminName); ?></strong></p>
            </section>
        </div>
        
        <footer class="footer">
            <p>&copy; <?php echo $currentYear; ?> <?php echo htmlspecialchars($companyName); ?>. All rights reserved.</p>
            <p>This is an automated notification from the Project Management System.</p>
            <div class="unsubscribe-link">
                <p>
                    <a href="<?php echo htmlspecialchars($unsubscribeUrl); ?>">Manage email preferences</a> | 
                    <a href="<?php echo htmlspecialchars($unsubscribeUrl); ?>&action=unsubscribe">Unsubscribe</a>
                </p>
            </div>
        </footer>
    </div>
</body>
</html>