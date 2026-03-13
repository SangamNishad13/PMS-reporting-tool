<?php
/**
 * Periodic Summary Email Template
 * 
 * Professional template for sending periodic accessibility report summaries
 * with branding, responsive design, and accessibility compliance.
 * 
 * Variables available:
 * - $clientName: Client's display name
 * - $summaryData: Array containing analytics summary data
 * - $period: Summary period (weekly, monthly, quarterly)
 * - $appUrl: Application URL
 * - $companyName: Company name from settings
 * - $unsubscribeUrl: URL for managing preferences
 */

$currentYear = date('Y');
$period = $summaryData['period'] ?? 'weekly';
$periodTitle = ucfirst($period);

// Extract summary metrics
$totalIssues = $summaryData['total_issues'] ?? 0;
$resolvedIssues = $summaryData['resolved_issues'] ?? 0;
$criticalIssues = $summaryData['critical_issues'] ?? 0;
$newIssues = $summaryData['new_issues'] ?? 0;
$projectCount = count($summaryData['projects'] ?? []);

$resolutionRate = $totalIssues > 0 ? round(($resolvedIssues / $totalIssues) * 100, 1) : 0;
$criticalRate = $totalIssues > 0 ? round(($criticalIssues / $totalIssues) * 100, 1) : 0;

// Build project summaries table
$projectSummaries = '';
if (!empty($summaryData['projects'])) {
    foreach ($summaryData['projects'] as $project) {
        $projectTitle = htmlspecialchars($project['title']);
        $projectIssues = $project['issues'] ?? 0;
        $projectResolved = $project['resolved'] ?? 0;
        $projectCritical = $project['critical'] ?? 0;
        $projectRate = $projectIssues > 0 ? round(($projectResolved / $projectIssues) * 100, 1) : 0;
        
        // Status indicator based on resolution rate
        $statusIcon = '🔴'; // Red for poor performance
        if ($projectRate >= 80) {
            $statusIcon = '🟢'; // Green for good performance
        } elseif ($projectRate >= 60) {
            $statusIcon = '🟡'; // Yellow for moderate performance
        }
        
        $projectSummaries .= "
            <tr>
                <td class='project-cell'>
                    <div class='project-name'>$statusIcon $projectTitle</div>
                </td>
                <td class='metric-cell'>$projectIssues</td>
                <td class='metric-cell'>$projectResolved</td>
                <td class='metric-cell critical-cell'>$projectCritical</td>
                <td class='metric-cell rate-cell'>$projectRate%</td>
            </tr>
        ";
    }
} else {
    $projectSummaries = "
        <tr>
            <td colspan='5' class='no-data-cell'>
                <div class='no-data-message'>
                    <span class='no-data-icon'>📊</span>
                    <p>No project data available for this period</p>
                </div>
            </td>
        </tr>
    ";
}

// Build trend indicators
$trendData = $summaryData['trends'] ?? [];
$resolutionTrend = $trendData['resolution_trend'] ?? 0;
$criticalTrend = $trendData['critical_trend'] ?? 0;

$resolutionTrendIcon = $resolutionTrend > 0 ? '📈' : ($resolutionTrend < 0 ? '📉' : '➡️');
$criticalTrendIcon = $criticalTrend > 0 ? '📈' : ($criticalTrend < 0 ? '📉' : '➡️');

$resolutionTrendText = $resolutionTrend > 0 ? 'improving' : ($resolutionTrend < 0 ? 'declining' : 'stable');
$criticalTrendText = $criticalTrend > 0 ? 'increasing' : ($criticalTrend < 0 ? 'decreasing' : 'stable');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title><?php echo $periodTitle; ?> Accessibility Report Summary</title>
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
            max-width: 650px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
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
            color: #6f42c1;
            font-size: 24px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        
        .welcome-section p {
            color: #495057;
            font-size: 16px;
            margin-bottom: 16px;
        }
        
        /* Metrics grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .metric-card {
            background-color: #ffffff;
            padding: 25px 20px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: transform 0.2s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .metric-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            display: block;
        }
        
        .metric-label {
            font-size: 13px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .metric-trend {
            font-size: 12px;
            margin-top: 5px;
            color: #495057;
        }
        
        /* Color coding for metrics */
        .metric-total .metric-value { color: #007bff; }
        .metric-resolved .metric-value { color: #28a745; }
        .metric-critical .metric-value { color: #dc3545; }
        .metric-new .metric-value { color: #fd7e14; }
        .metric-rate .metric-value { color: #6f42c1; }
        
        /* Project breakdown table */
        .projects-section {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 10px;
            margin: 30px 0;
            border: 1px solid #e9ecef;
        }
        
        .projects-section h3 {
            color: #495057;
            font-size: 20px;
            margin-bottom: 25px;
            font-weight: 600;
        }
        
        .projects-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .projects-table th {
            background-color: #f8f9fa;
            color: #495057;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .projects-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }
        
        .project-cell {
            font-weight: 500;
        }
        
        .project-name {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .metric-cell {
            text-align: center;
            font-weight: 600;
        }
        
        .critical-cell {
            color: #dc3545;
        }
        
        .rate-cell {
            color: #6f42c1;
        }
        
        .no-data-cell {
            text-align: center;
            padding: 40px 20px;
        }
        
        .no-data-message {
            color: #6c757d;
        }
        
        .no-data-icon {
            font-size: 24px;
            display: block;
            margin-bottom: 10px;
        }
        
        /* Trends section */
        .trends-section {
            background-color: #e8f4fd;
            padding: 30px;
            border-radius: 10px;
            margin: 30px 0;
            border-left: 5px solid #007bff;
        }
        
        .trends-section h3 {
            color: #0056b3;
            font-size: 20px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .trend-item {
            background-color: #ffffff;
            padding: 15px 20px;
            margin: 10px 0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .trend-icon {
            font-size: 20px;
        }
        
        .trend-text {
            color: #495057;
            font-size: 15px;
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
        
        /* Preferences section */
        .preferences-section {
            background-color: #d4edda;
            padding: 25px;
            border-radius: 10px;
            margin: 30px 0;
            border-left: 5px solid #28a745;
        }
        
        .preferences-section p {
            color: #155724;
            font-size: 14px;
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
            .trends-section {
                padding: 20px;
            }
            
            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .metric-card {
                padding: 20px 15px;
            }
            
            .metric-value {
                font-size: 24px;
            }
            
            .projects-table th,
            .projects-table td {
                padding: 10px 8px;
                font-size: 13px;
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
            .projects-section {
                background-color: #2d2d2d;
                border-color: #404040;
            }
            
            .metric-card,
            .trend-item {
                background-color: #2d2d2d;
            }
            
            .content {
                background-color: #1a1a1a;
            }
            
            .welcome-section h2 {
                color: #9d7bea;
            }
            
            .welcome-section p,
            .trend-text {
                color: #e9ecef;
            }
            
            .projects-table th {
                background-color: #404040;
                color: #e9ecef;
            }
            
            .projects-table td {
                border-color: #404040;
                color: #e9ecef;
            }
        }
    </style>
</head>
<body>
    <div class="email-container" role="main">
        <header class="header">
            <h1>📊 <?php echo $periodTitle; ?> Summary Report</h1>
            <p class="subtitle">Your accessibility progress overview</p>
        </header>
        
        <div class="content">
            <section class="welcome-section">
                <h2>Hello <?php echo htmlspecialchars($clientName); ?>,</h2>
                <p>Here's your <?php echo $period; ?> accessibility report summary for your <strong><?php echo $projectCount; ?> assigned projects</strong>.</p>
                <p>This summary provides key insights into your accessibility compliance progress and highlights areas that may need attention.</p>
            </section>
            
            <div class="metrics-grid">
                <div class="metric-card metric-total">
                    <span class="metric-value"><?php echo $totalIssues; ?></span>
                    <div class="metric-label">Total Issues</div>
                </div>
                <div class="metric-card metric-resolved">
                    <span class="metric-value"><?php echo $resolvedIssues; ?></span>
                    <div class="metric-label">Resolved</div>
                </div>
                <div class="metric-card metric-critical">
                    <span class="metric-value"><?php echo $criticalIssues; ?></span>
                    <div class="metric-label">Critical</div>
                </div>
                <div class="metric-card metric-new">
                    <span class="metric-value"><?php echo $newIssues; ?></span>
                    <div class="metric-label">New Issues</div>
                </div>
                <div class="metric-card metric-rate">
                    <span class="metric-value"><?php echo $resolutionRate; ?>%</span>
                    <div class="metric-label">Resolution Rate</div>
                </div>
            </div>
            
            <?php if (!empty($trendData)): ?>
            <section class="trends-section">
                <h3>📈 Trends This <?php echo $periodTitle; ?>:</h3>
                <div class="trend-item">
                    <span class="trend-icon"><?php echo $resolutionTrendIcon; ?></span>
                    <span class="trend-text">Resolution rate is <strong><?php echo $resolutionTrendText; ?></strong> compared to last period</span>
                </div>
                <div class="trend-item">
                    <span class="trend-icon"><?php echo $criticalTrendIcon; ?></span>
                    <span class="trend-text">Critical issues are <strong><?php echo $criticalTrendText; ?></strong> compared to last period</span>
                </div>
            </section>
            <?php endif; ?>
            
            <section class="projects-section">
                <h3>📋 Project Breakdown:</h3>
                <table class="projects-table">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th style="text-align: center;">Issues</th>
                            <th style="text-align: center;">Resolved</th>
                            <th style="text-align: center;">Critical</th>
                            <th style="text-align: center;">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $projectSummaries; ?>
                    </tbody>
                </table>
            </section>
            
            <div class="cta-section">
                <a href="<?php echo htmlspecialchars($appUrl); ?>" class="cta-button">
                    📈 View Full Dashboard
                </a>
            </div>
            
            <section class="preferences-section">
                <p><strong>💡 Tip:</strong> You can adjust your email preferences or opt out of these summaries in your dashboard settings. <a href="<?php echo htmlspecialchars($unsubscribeUrl); ?>" style="color: #155724; text-decoration: underline;">Manage preferences</a></p>
            </section>
        </div>
        
        <footer class="footer">
            <p>&copy; <?php echo $currentYear; ?> <?php echo htmlspecialchars($companyName); ?>. All rights reserved.</p>
            <p>This is an automated <?php echo $period; ?> summary from the Project Management System.</p>
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