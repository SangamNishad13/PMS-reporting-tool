<?php
/**
 * UnifiedDashboardController
 * 
 * Aggregates analytics from all assigned projects and renders summary widgets
 * for all 9 report types with drill-down capabilities.
 * 
 * Requirements: 12.1, 12.2, 12.4
 */

require_once __DIR__ . '/../models/ClientAccessControlManager.php';
require_once __DIR__ . '/../models/AnalyticsEngine.php';
require_once __DIR__ . '/../models/UserAffectedAnalytics.php';
require_once __DIR__ . '/../models/WCAGComplianceAnalytics.php';
require_once __DIR__ . '/../models/SeverityAnalytics.php';
require_once __DIR__ . '/../models/CommonIssuesAnalytics.php';
require_once __DIR__ . '/../models/BlockerIssuesAnalytics.php';
require_once __DIR__ . '/../models/PageIssuesAnalytics.php';
require_once __DIR__ . '/../models/CommentedIssuesAnalytics.php';
require_once __DIR__ . '/../models/ComplianceTrendAnalytics.php';
require_once __DIR__ . '/../models/ClientComplianceScoreResolver.php';
require_once __DIR__ . '/../models/VisualizationRenderer.php';

class UnifiedDashboardController {
    private $accessControl;
    public $visualization;
    private $analyticsEngines;
    private $complianceResolver;

    private function getClientBasePath(): string {
        if (function_exists('getBaseDir')) {
            return rtrim((string) getBaseDir(), '/');
        }

        return '/PMS';
    }

    private function getClientDashboardUrl(): string {
        return $this->getClientBasePath() . '/client/dashboard';
    }

    private function getClientProjectUrl(int $projectId): string {
        return $this->getClientBasePath() . '/client/project/' . $projectId;
    }

    private function getSelectedProjectId(array $assignedProjects): ?int {
        $selectedProjectId = (int) ($_GET['project_id'] ?? 0);

        if ($selectedProjectId <= 0) {
            return null;
        }

        $assignedProjectIds = array_map('intval', array_column($assignedProjects, 'id'));
        return in_array($selectedProjectId, $assignedProjectIds, true) ? $selectedProjectId : null;
    }

    private function getProjectReportUrl(int $projectId, string $reportType): string {
        return $this->getClientProjectUrl($projectId)
            . '?report=' . rawurlencode($reportType)
            . '#analytics-report-' . rawurlencode($reportType);
    }

    private function getDashboardReportUrl(string $reportType, array $projectIds): string {
        if (count($projectIds) === 1) {
            $selectedProjectId = (int) reset($projectIds);

            switch ($reportType) {
                case 'page_issues':
                    return $this->getClientBasePath() . '/modules/projects/issues_pages.php?project_id=' . $selectedProjectId;
                case 'common_issues':
                    return $this->getClientBasePath() . '/modules/projects/issues_common.php?project_id=' . $selectedProjectId;
                default:
                    return $this->getProjectReportUrl($selectedProjectId, $reportType);
            }
        }

        return $this->getClientDashboardUrl()
            . '?report=' . rawurlencode($reportType)
            . '#analytics-report-' . rawurlencode($reportType);
    }
    
    public function __construct() {
        $this->accessControl = new ClientAccessControlManager();
        $this->visualization = new VisualizationRenderer();
        $this->complianceResolver = new ClientComplianceScoreResolver($this->accessControl);
        
        // Initialize all 9 analytics engines
        $this->analyticsEngines = [
            'user_affected' => new UserAffectedAnalytics(),
            'wcag_compliance' => new WCAGComplianceAnalytics(),
            'severity_analysis' => new SeverityAnalytics(),
            'common_issues' => new CommonIssuesAnalytics(),
            'blocker_issues' => new BlockerIssuesAnalytics(),
            'page_issues' => new PageIssuesAnalytics(),
            'commented_issues' => new CommentedIssuesAnalytics(),
            'compliance_trend' => new ComplianceTrendAnalytics()
        ];
    }
    
    /**
     * Generate unified dashboard for client user
     * 
     * @param int $clientUserId Client user ID
     * @return array Dashboard data with all analytics widgets
     */
    public function generateUnifiedDashboard($clientUserId) {
        // Get assigned projects
        $assignedProjects = $this->accessControl->getAssignedProjects($clientUserId);
        
        if (empty($assignedProjects)) {
            return $this->generateOnboardingDashboard();
        }
        
        $selectedProjectId = $this->getSelectedProjectId($assignedProjects);
        $selectedProject = null;

        if ($selectedProjectId !== null) {
            $assignedProjects = array_values(array_filter($assignedProjects, function($project) use ($selectedProjectId) {
                return (int) ($project['id'] ?? 0) === $selectedProjectId;
            }));
            $selectedProject = $assignedProjects[0] ?? null;
        }

        $projectIds = array_map('intval', array_column($assignedProjects, 'id'));
        
        // Generate all analytics reports
        $analyticsReports = $this->generateAllAnalytics($projectIds, $clientUserId);
        
        // Create summary widgets
        $widgets = $this->createSummaryWidgets($analyticsReports, $projectIds);
        
        // Get project statistics
        $projectStats = $this->accessControl->getProjectStatistics($clientUserId, $selectedProjectId);
        
        // Calculate overall compliance percentage from WCAG compliance score
        $compliancePct = $this->complianceResolver->resolveForClientUser((int) $clientUserId, $projectIds);

        return [
            'success' => true,
            'client_user_id' => $clientUserId,
            'assigned_projects' => $assignedProjects,
            'selected_project_id' => $selectedProjectId,
            'selected_project' => $selectedProject,
            'project_statistics' => $projectStats,
            'compliance_percentage' => $compliancePct,
            'analytics_widgets' => $widgets,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Generate all analytics reports for assigned projects
     * 
     * @param array $projectIds Array of project IDs
     * @param int $clientUserId Client user ID
     * @return array All analytics reports
     */
    private function generateAllAnalytics($projectIds, $clientUserId) {
        $reports = [];
        $projectId = count($projectIds) === 1 ? (int) reset($projectIds) : null;
        
        foreach ($this->analyticsEngines as $type => $engine) {
            try {
                $report = $engine->generateReport($projectId, $clientUserId);
                $reports[$type] = $report;
            } catch (Exception $e) {
                error_log("Error generating {$type} analytics: " . $e->getMessage());
                $reports[$type] = null;
            }
        }
        
        return $reports;
    }
    
    /**
     * Create summary widgets for all analytics types
     * 
     * @param array $analyticsReports All analytics reports
     * @param array $projectIds Project IDs for drill-down links
     * @return array Widget configurations
     */
    private function createSummaryWidgets($analyticsReports, $projectIds) {
        $widgets = [];
        
        // User Affected Analytics Widget
        if ($analyticsReports['user_affected']) {
            $widgets['user_affected'] = $this->createUserAffectedWidget(
                $analyticsReports['user_affected'], 
                $projectIds
            );
        }
        
        // WCAG Compliance Analytics Widget
        if ($analyticsReports['wcag_compliance']) {
            $widgets['wcag_compliance'] = $this->createWCAGComplianceWidget(
                $analyticsReports['wcag_compliance'], 
                $projectIds
            );
        }
        
        // Severity Analysis Widget
        if ($analyticsReports['severity_analysis']) {
            $widgets['severity_analysis'] = $this->createSeverityAnalysisWidget(
                $analyticsReports['severity_analysis'], 
                $projectIds
            );
        }
        
        // Common Issues Widget
        if ($analyticsReports['common_issues']) {
            $widgets['common_issues'] = $this->createCommonIssuesWidget(
                $analyticsReports['common_issues'], 
                $projectIds
            );
        }
        
        // Blocker Issues Widget
        if ($analyticsReports['blocker_issues']) {
            $widgets['blocker_issues'] = $this->createBlockerIssuesWidget(
                $analyticsReports['blocker_issues'], 
                $projectIds
            );
        }
        
        // Page Issues Widget
        if ($analyticsReports['page_issues']) {
            $widgets['page_issues'] = $this->createPageIssuesWidget(
                $analyticsReports['page_issues'], 
                $projectIds
            );
        }
        
        // Commented Issues Widget
        if ($analyticsReports['commented_issues']) {
            $widgets['commented_issues'] = $this->createCommentedIssuesWidget(
                $analyticsReports['commented_issues'], 
                $projectIds
            );
        }
        
        // Compliance Trend Widget
        if ($analyticsReports['compliance_trend']) {
            $widgets['compliance_trend'] = $this->createComplianceTrendWidget(
                $analyticsReports['compliance_trend'], 
                $projectIds
            );
        }
        
        return $widgets;
    }
    
    /**
     * Create User Affected Analytics widget
     */
    private function createUserAffectedWidget($report, $projectIds) {
        $data = $report->getData();
        $summary = $data['summary'] ?? [];
        
        return [
            'type' => 'analytics',
            'title' => 'User Impact Analysis',
            'icon' => 'fas fa-users',
            'reportType' => 'user_affected',
            'drillDownUrl' => $this->getDashboardReportUrl('user_affected', $projectIds),
            'summary' => [
                [
                    'label' => 'Total Issues',
                    'value' => $summary['total_issues'] ?? 0
                ],
                [
                    'label' => 'Users Affected',
                    'value' => number_format($summary['total_users_affected'] ?? 0)
                ],
                [
                    'label' => 'High Impact Issues',
                    'value' => $summary['high_impact_issues'] ?? 0
                ],
                [
                    'label' => 'Avg Users/Issue',
                    'value' => $summary['average_users_per_issue'] ?? 0
                ]
            ],
            'quickChart' => [
                'labels' => array_keys($data['distribution'] ?? []),
                'datasets' => [[
                    'data' => array_column($data['distribution'] ?? [], 'count'),
                    'backgroundColor' => ['#28a745', '#ffc107', '#fd7e14', '#dc3545']
                ]]
            ]
        ];
    }
    
    /**
     * Create WCAG Compliance Analytics widget
     */
    private function createWCAGComplianceWidget($report, $projectIds) {
        $data = $report->getData();
        $summary = $data['summary'] ?? [];
        
        return [
            'type' => 'analytics',
            'title' => 'WCAG Compliance',
            'icon' => 'fas fa-shield-alt',
            'reportType' => 'wcag_compliance',
            'drillDownUrl' => $this->getDashboardReportUrl('wcag_compliance', $projectIds),
            'summary' => [
                [
                    'label' => 'Overall Score',
                    'value' => round($summary['overall_compliance_score'] ?? 0, 1) . '%'
                ],
                [
                    'label' => 'Level A',
                    'value' => round($summary['level_a_compliance'] ?? 0, 1) . '%'
                ],
                [
                    'label' => 'Level AA',
                    'value' => round($summary['level_aa_compliance'] ?? 0, 1) . '%'
                ]
            ],
            'quickChart' => [
                'labels' => ['Level A', 'Level AA'],
                'datasets' => [[
                    'data' => [
                        round($summary['level_a_compliance'] ?? 0, 1),
                        round($summary['level_aa_compliance'] ?? 0, 1)
                    ],
                    'backgroundColor' => ['#2563eb', '#16a34a']
                ]]
            ]
        ];
    }
    
    /**
     * Create Severity Analysis widget
     */
    private function createSeverityAnalysisWidget($report, $projectIds) {
        $data = $report->getData();
        $issueSeverityDistribution = $data['issue_severity_distribution'] ?? [];
        $issueSeverityColors = [
            'Blocker' => '#7f1d1d',
            'Critical' => '#dc2626',
            'Major' => '#ea580c',
            'Minor' => '#2563eb'
        ];
        
        return [
            'type' => 'analytics',
            'title' => 'Issue Severity',
            'icon' => 'fas fa-exclamation-triangle',
            'reportType' => 'severity_analysis',
            'drillDownUrl' => $this->getDashboardReportUrl('severity_analysis', $projectIds),
            'summary' => [
                [
                    'label' => 'Blocker',
                    'value' => $this->getIssueSeverityCount($issueSeverityDistribution, 'Blocker')
                ],
                [
                    'label' => 'Critical',
                    'value' => $this->getIssueSeverityCount($issueSeverityDistribution, 'Critical')
                ],
                [
                    'label' => 'Major',
                    'value' => $this->getIssueSeverityCount($issueSeverityDistribution, 'Major')
                ],
                [
                    'label' => 'Minor',
                    'value' => $this->getIssueSeverityCount($issueSeverityDistribution, 'Minor')
                ]
            ],
            'quickChart' => [
                'labels' => array_column($issueSeverityDistribution, 'severity'),
                'datasets' => [[
                    'data' => array_column($issueSeverityDistribution, 'count'),
                    'backgroundColor' => array_map(function ($item) use ($issueSeverityColors) {
                        $severity = $item['severity'] ?? '';
                        return $issueSeverityColors[$severity] ?? '#6c757d';
                    }, $issueSeverityDistribution)
                ]]
            ]
        ];
    }
    
    /**
     * Create Common Issues widget
     */
    private function createCommonIssuesWidget($report, $projectIds) {
        $data = $report->getData();
        $topIssues = array_slice($data['top_issues'] ?? [], 0, 3);
        
        return [
            'type' => 'analytics',
            'title' => 'Common Issues',
            'icon' => 'fas fa-list-ul',
            'reportType' => 'common_issues',
            'drillDownUrl' => $this->getDashboardReportUrl('common_issues', $projectIds),
            'summary' => array_map(function($issue, $index) {
                return [
                    'label' => '#' . ($index + 1) . ' Issue',
                    'value' => $issue['count'] ?? 0
                ];
            }, $topIssues, array_keys($topIssues))
        ];
    }
    
    /**
     * Create Blocker Issues widget
     */
    private function createBlockerIssuesWidget($report, $projectIds) {
        $data = $report->getData();
        $summary = $data['summary'] ?? [];
        
        return [
            'type' => 'analytics',
            'title' => 'Blocker Issues',
            'icon' => 'fas fa-ban',
            'reportType' => 'blocker_issues',
            'drillDownUrl' => $this->getDashboardReportUrl('blocker_issues', $projectIds),
            'summary' => [
                [
                    'label' => 'Active Blockers',
                    'value' => $summary['active_blockers'] ?? 0
                ],
                [
                    'label' => 'Resolved',
                    'value' => $summary['resolved_blockers'] ?? 0
                ],
                [
                    'label' => 'Avg Resolution Time',
                    'value' => ($summary['avg_resolution_days'] ?? 0) . ' days'
                ]
            ]
        ];
    }
    
    /**
     * Create Page Issues widget
     */
    private function createPageIssuesWidget($report, $projectIds) {
        $data = $report->getData();
        $summary = $data['summary'] ?? [];
        
        return [
            'type' => 'analytics',
            'title' => 'Page Analysis',
            'icon' => 'fas fa-file-alt',
            'reportType' => 'page_issues',
            'drillDownUrl' => $this->getDashboardReportUrl('page_issues', $projectIds),
            'summary' => [
                [
                    'label' => 'Pages Analyzed',
                    'value' => $summary['total_pages'] ?? 0
                ],
                [
                    'label' => 'Issues per Page',
                    'value' => round($summary['avg_issues_per_page'] ?? 0, 1)
                ],
                [
                    'label' => 'Most Affected Page',
                    'value' => $summary['most_affected_page_issues'] ?? 0
                ]
            ]
        ];
    }
    
    /**
     * Create Commented Issues widget
     */
    private function createCommentedIssuesWidget($report, $projectIds) {
        $data = $report->getData();
        $summary = $data['summary'] ?? [];
        
        return [
            'type' => 'analytics',
            'title' => 'Discussion Activity',
            'icon' => 'fas fa-comments',
            'reportType' => 'commented_issues',
            'drillDownUrl' => $this->getDashboardReportUrl('commented_issues', $projectIds),
            'summary' => [
                [
                    'label' => 'Issues with Comments',
                    'value' => $summary['commented_issues_count'] ?? 0
                ],
                [
                    'label' => 'Total Comments',
                    'value' => $summary['total_comments'] ?? 0
                ],
                [
                    'label' => 'Recent Activity',
                    'value' => $summary['recent_activity_count'] ?? 0
                ]
            ]
        ];
    }
    
    /**
     * Create Compliance Trend widget
     */
    private function createComplianceTrendWidget($report, $projectIds) {
        $data = $report->getData();
        $trendData = $data['daily_trends'] ?? ($data['trend_data'] ?? []);
        
        return [
            'type' => 'trend',
            'title' => 'Compliance Trends',
            'icon' => 'fas fa-chart-line',
            'reportType' => 'compliance_trend',
            'drillDownUrl' => $this->getDashboardReportUrl('compliance_trend', $projectIds),
            'period' => 'Last 30 days',
            'trendData' => [
                'labels' => array_column($trendData, 'date'),
                'datasets' => [[
                    'label' => 'Compliance Score',
                    'data' => array_column($trendData, 'compliance_score'),
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.1)'
                ]]
            ]
        ];
    }
    
    /**
     * Generate onboarding dashboard when no projects are assigned
     * 
     * @return array Onboarding dashboard data
     */
    private function generateOnboardingDashboard() {
        return [
            'success' => true,
            'onboarding' => true,
            'message' => 'Welcome to your accessibility analytics dashboard',
            'description' => 'You don\'t have any projects assigned yet. Please contact your administrator to get started.',
            'contact_info' => [
                'title' => 'Need Help Getting Started?',
                'message' => 'Contact your system administrator to assign projects to your account.',
                'email' => 'admin@example.com', // This should be configurable
                'phone' => '+1 (555) 123-4567' // This should be configurable
            ],
            'features' => [
                [
                    'icon' => 'fas fa-users',
                    'title' => 'User Impact Analysis',
                    'description' => 'Track how many users are affected by accessibility issues'
                ],
                [
                    'icon' => 'fas fa-shield-alt',
                    'title' => 'WCAG Compliance',
                    'description' => 'Monitor compliance with accessibility standards'
                ],
                [
                    'icon' => 'fas fa-chart-line',
                    'title' => 'Trend Analysis',
                    'description' => 'View progress over time with detailed trends'
                ],
                [
                    'icon' => 'fas fa-file-export',
                    'title' => 'Export Reports',
                    'description' => 'Download PDF and Excel reports for stakeholders'
                ]
            ],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Render unified dashboard HTML
     * 
     * @param int $clientUserId Client user ID
     * @return string Dashboard HTML
     */
    public function renderDashboard($clientUserId) {
        $dashboardData = $this->generateUnifiedDashboard($clientUserId);
        
        if ($dashboardData['onboarding'] ?? false) {
            return $this->renderOnboardingDashboard($dashboardData);
        }
        
        return $this->renderAnalyticsDashboard($dashboardData);
    }
    
    /**
     * Render onboarding dashboard HTML
     * 
     * @param array $data Onboarding data
     * @return string HTML
     */
    public function renderOnboardingDashboard($data) {
        $html = '<div class="onboarding-dashboard">';
        $html .= '<div class="container-fluid">';
        
        // Welcome section
        $html .= '<div class="row mb-4">';
        $html .= '<div class="col-12 text-center">';
        $html .= '<div class="welcome-section">';
        $html .= '<i class="fas fa-chart-bar fa-4x text-primary mb-3"></i>';
        $html .= '<h1 class="display-4">' . htmlspecialchars($data['message']) . '</h1>';
        $html .= '<p class="lead">' . htmlspecialchars($data['description']) . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Contact info section
        $contactInfo = $data['contact_info'];
        $html .= '<div class="row mb-5">';
        $html .= '<div class="col-md-6 offset-md-3">';
        $html .= '<div class="card border-primary">';
        $html .= '<div class="card-header bg-primary text-white">';
        $html .= '<h3 class="card-title mb-0"><i class="fas fa-phone"></i> ' . htmlspecialchars($contactInfo['title']) . '</h3>';
        $html .= '</div>';
        $html .= '<div class="card-body text-center">';
        $html .= '<p>' . htmlspecialchars($contactInfo['message']) . '</p>';
        $html .= '<div class="contact-details">';
        $html .= '<p><strong>Email:</strong> <a href="mailto:' . htmlspecialchars($contactInfo['email']) . '">' . htmlspecialchars($contactInfo['email']) . '</a></p>';
        $html .= '<p><strong>Phone:</strong> <a href="tel:' . htmlspecialchars($contactInfo['phone']) . '">' . htmlspecialchars($contactInfo['phone']) . '</a></p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Features preview
        $html .= '<div class="row">';
        $html .= '<div class="col-12">';
        $html .= '<h2 class="text-center mb-4">What You\'ll Get Access To</h2>';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div class="row">';
        foreach ($data['features'] as $feature) {
            $html .= '<div class="col-md-6 col-lg-3 mb-4">';
            $html .= '<div class="card h-100 text-center">';
            $html .= '<div class="card-body">';
            $html .= '<i class="' . htmlspecialchars($feature['icon']) . ' fa-3x text-primary mb-3"></i>';
            $html .= '<h5 class="card-title">' . htmlspecialchars($feature['title']) . '</h5>';
            $html .= '<p class="card-text">' . htmlspecialchars($feature['description']) . '</p>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render analytics dashboard HTML
     * 
     * @param array $data Dashboard data
     * @return string HTML
     */
    private function renderAnalyticsDashboard($data) {
        $html = '<div class="analytics-dashboard">';
        $html .= '<div class="container-fluid">';
        
        // Dashboard header
        $html .= '<div class="row mb-4">';
        $html .= '<div class="col-12">';
        $html .= '<div class="dashboard-header">';
        $html .= '<h1><i class="fas fa-tachometer-alt"></i> Analytics Dashboard</h1>';
        $html .= '<p class="text-muted">Comprehensive accessibility analytics across ' . count($data['assigned_projects']) . ' assigned projects</p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Project statistics overview
        $stats = $data['project_statistics'];
        $html .= '<div class="row mb-4">';
        $html .= '<div class="col-md-3">';
        $html .= $this->visualization->renderDashboardWidget('summary', [
            'title' => 'Total Projects',
            'value' => $stats['total_projects'],
            'icon' => 'fas fa-folder',
            'description' => 'Assigned projects'
        ]);
        $html .= '</div>';
        $html .= '<div class="col-md-3">';
        $html .= $this->visualization->renderDashboardWidget('summary', [
            'title' => 'Client-Ready Issues',
            'value' => number_format($stats['client_ready_issues']),
            'icon' => 'fas fa-check-circle',
            'description' => 'Issues ready for review'
        ]);
        $html .= '</div>';
        $html .= '<div class="col-md-3">';
        $html .= $this->visualization->renderDashboardWidget('summary', [
            'title' => 'Total Issues',
            'value' => number_format($stats['total_issues']),
            'icon' => 'fas fa-exclamation-triangle',
            'description' => 'All project issues'
        ]);
        $html .= '</div>';
        $html .= '<div class="col-md-3">';
        $readyPercentage = $stats['total_issues'] > 0 ? 
            round(($stats['client_ready_issues'] / $stats['total_issues']) * 100, 1) : 0;
        $html .= $this->visualization->renderDashboardWidget('summary', [
            'title' => 'Ready Percentage',
            'value' => $readyPercentage . '%',
            'icon' => 'fas fa-percentage',
            'description' => 'Issues marked client-ready'
        ]);
        $html .= '</div>';
        $html .= '</div>';
        
        // Analytics widgets grid
        $widgets = $data['analytics_widgets'];
        $widgetConfigs = [];
        
        foreach ($widgets as $type => $widgetData) {
            $widgetConfigs[] = [
                'size' => 'medium',
                'content' => $this->visualization->renderDashboardWidget($widgetData['type'], $widgetData)
            ];
        }
        
        $html .= $this->visualization->renderDashboardGrid($widgetConfigs, [
            'gridClass' => 'analytics-grid',
            'columns' => 'auto'
        ]);
        
        // Quick actions section
        $html .= '<div class="row mt-5">';
        $html .= '<div class="col-12">';
        $html .= '<div class="card">';
        $html .= '<div class="card-header">';
        $html .= '<h3><i class="fas fa-bolt"></i> Quick Actions</h3>';
        $html .= '</div>';
        $html .= '<div class="card-body">';
        $html .= '<div class="row">';
        
        $projectIdsList = implode(',', array_column($data['assigned_projects'], 'id'));
        
        $html .= '<div class="col-md-3 mb-3">';
        $html .= '<a href="' . htmlspecialchars($this->getClientDashboardUrl(), ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary btn-lg btn-block">';
        $html .= '<i class="fas fa-chart-line"></i><br>View All Analytics';
        $html .= '</a>';
        $html .= '</div>';
        
        $html .= '<div class="col-md-4 mb-3">';
        $html .= '<a href="/PMS/client/exports" class="btn btn-success btn-lg btn-block">';
        $html .= '<i class="fas fa-file-pdf"></i><br>Export Reports';
        $html .= '</a>';
        $html .= '</div>';
        
        $html .= '<div class="col-md-4 mb-3">';
        $html .= '<a href="/PMS/modules/client/projects.php" class="btn btn-secondary btn-lg btn-block">';
        $html .= '<i class="fas fa-folder-open"></i><br>View Projects';
        $html .= '</a>';
        $html .= '</div>';
        
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get dashboard data as JSON for AJAX requests
     * 
     * @param int $clientUserId Client user ID
     * @return string JSON response
     */
    public function getDashboardJSON($clientUserId) {
        header('Content-Type: application/json');
        
        try {
            $dashboardData = $this->generateUnifiedDashboard($clientUserId);
            echo json_encode($dashboardData, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to generate dashboard data',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        return true;
    }
    
    /**
     * Handle dashboard refresh requests
     * 
     * @param int $clientUserId Client user ID
     * @return array Refreshed dashboard data
     */
    public function refreshDashboard($clientUserId) {
        // Clear any cached data for this client
        $this->accessControl->invalidateCache($clientUserId);
        
        // Generate fresh dashboard data
        return $this->generateUnifiedDashboard($clientUserId);
    }
    
    /**
     * Generate analytics for a specific project
     * 
     * @param int $projectId Project ID
     * @param int $clientUserId Client user ID
     * @return array Project analytics data
     */
    public function generateProjectAnalytics($projectId, $clientUserId) {
        // Verify project access
        if (!$this->accessControl->hasProjectAccess($clientUserId, $projectId)) {
            throw new Exception('Access denied to project');
        }
        
        // Generate analytics for single project
        $analyticsReports = [];
        foreach ($this->analyticsEngines as $type => $engine) {
            try {
                $report = $engine->generateReport($projectId, $clientUserId);
                $analyticsReports[$type] = $report;
            } catch (Exception $e) {
                error_log("Error generating {$type} analytics for project {$projectId}: " . $e->getMessage());
                $analyticsReports[$type] = null;
            }
        }
        
        // Create project-specific widgets
        $widgets = $this->createProjectWidgets($analyticsReports, $projectId);
        
        // Get project statistics
        $projectStats = $this->accessControl->getProjectStatistics($clientUserId, $projectId);
        
        // Get project details for the template
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT title, description FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $projectDetails = $stmt->fetch(PDO::FETCH_ASSOC);

        // Safely extract resolved and total issues from severity analytics or set defaults
        $resolvedCount = 0;
        $totalClientIssues = $projectStats['client_ready_issues'] ?? 0;
        
        // Use ComplianceTrendAnalytics to safely get resolved count if possible
        if (isset($analyticsReports['compliance_trend']) && $analyticsReports['compliance_trend']) {
            $trendData = $analyticsReports['compliance_trend']->getData();
            $resolvedCount = $trendData['summary']['resolved_issues'] ?? 0;
        }

        $pendingCount = max(0, $totalClientIssues - $resolvedCount);
        $compliancePct = round((float) ($projectStats['compliance_score'] ?? $this->complianceResolver->resolveForClientUser((int) $clientUserId, [(int) $projectId])), 1);

        return [
            'success' => true,
            'project_id' => $projectId,
            'project_name' => $projectDetails['title'] ?? 'Project',
            'project_description' => $projectDetails['description'] ?? '',
            'client_user_id' => $clientUserId,
            'project_statistics' => $projectStats,
            'total_issues' => $projectStats['total_issues'] ?? 0,
            'client_ready_issues' => $totalClientIssues,
            'resolved_issues' => $resolvedCount,
            'pending_issues' => $pendingCount,
            'compliance_percentage' => $compliancePct,
            'analytics_widgets' => $widgets,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    // --- Bridge methods for ClientDashboardController AJAX requests ---

    public function getUserAffectedSummary($projectIds) {
        $report = $this->analyticsEngines['user_affected']->generateReport($projectIds);
        return $report->getData();
    }

    public function getWCAGComplianceSummary($projectIds) {
        $report = $this->analyticsEngines['wcag_compliance']->generateReport($projectIds);
        return $report->getData();
    }

    public function getSeverityDistribution($projectIds) {
        $report = $this->analyticsEngines['severity_analysis']->generateReport($projectIds);
        return $report->getData();
    }

    public function getTopCommonIssues($projectIds, $limit = 5) {
        $report = $this->analyticsEngines['common_issues']->generateReport($projectIds);
        $data = $report->getData();
        if (isset($data['top_issues'])) {
            $data['top_issues'] = array_slice($data['top_issues'], 0, $limit);
        }
        return $data;
    }

    public function getBlockerIssuesSummary($projectIds) {
        $report = $this->analyticsEngines['blocker_issues']->generateReport($projectIds);
        return $report->getData();
    }

    public function getTopPageIssues($projectIds, $limit = 5) {
        $report = $this->analyticsEngines['page_issues']->generateReport($projectIds);
        $data = $report->getData();
        if (isset($data['top_pages'])) {
            $data['top_pages'] = array_slice($data['top_pages'], 0, $limit);
        }
        return $data;
    }

    public function getRecentActivity($projectIds, $limit = 10) {
        // Mock recent activity since it's used as a widget
        return [
            'activities' => [],
            'count' => 0,
            'limit' => $limit
        ];
    }

    public function getComplianceTrend($projectIds, $days = 30) {
        $report = $this->analyticsEngines['compliance_trend']->generateReport($projectIds);
        return $report->getData();
    }


    
    /**
     * Create project-specific widgets
     * 
     * @param array $analyticsReports Analytics reports
     * @param int $projectId Project ID
     * @return array Widget configurations
     */
    private function createProjectWidgets($analyticsReports, $projectId) {
        $widgets = [];
        
        foreach ($analyticsReports as $type => $report) {
            if ($report) {
                $widgets[$type] = $this->createProjectWidget($type, $report, $projectId);
            }
        }
        
        return $widgets;
    }
    
    /**
     * Create individual project widget
     * 
     * @param string $type Widget type
     * @param object $report Analytics report
     * @param int $projectId Project ID
     * @return array Widget configuration
     */
    private function createProjectWidget($type, $report, $projectId) {
        $data = $report->getData();
        
        $widgetConfig = [
            'type' => 'analytics',
            'reportType' => $type,
            'drillDownUrl' => $this->getClientProjectUrl((int) $projectId),
            'summary' => []
        ];
        
        switch ($type) {
            case 'user_affected':
                $widgetConfig['title'] = 'User Impact Analysis';
                $widgetConfig['icon'] = 'fas fa-users';
                $summary = $data['summary'] ?? [];
                $widgetConfig['summary'] = [
                    ['label' => 'Total Issues', 'value' => $summary['total_issues'] ?? 0],
                    ['label' => 'Users Affected', 'value' => number_format($summary['total_users_affected'] ?? 0)],
                    ['label' => 'High Impact', 'value' => $summary['high_impact_issues'] ?? 0],
                    ['label' => 'Avg Users/Issue', 'value' => round($summary['average_users_per_issue'] ?? 0, 1)]
                ];
                $widgetConfig['quickChart'] = [
                    'labels' => array_keys($data['distribution'] ?? []),
                    'datasets' => [[
                        'data' => array_column($data['distribution'] ?? [], 'count'),
                        'backgroundColor' => ['#28a745', '#ffc107', '#fd7e14', '#dc3545']
                    ]]
                ];
                break;
                
            case 'wcag_compliance':
                $widgetConfig['title'] = 'WCAG Compliance';
                $widgetConfig['icon'] = 'fas fa-shield-alt';
                $summary = $data['summary'] ?? [];
                $levelDistribution = array_values(array_filter(
                    $data['level_distribution'] ?? [],
                    function ($item) {
                        return ($item['level'] ?? '') !== 'Level AAA';
                    }
                ));
                $levelColors = [
                    'Level A' => '#dc3545',
                    'Level AA' => '#fd7e14',
                    'Unknown' => '#6c757d'
                ];
                $widgetConfig['summary'] = [
                    ['label' => 'Overall Score', 'value' => round($summary['overall_compliance_score'] ?? 0, 1) . '%'],
                    ['label' => 'Level A', 'value' => round($summary['level_a_compliance'] ?? 0, 1) . '%'],
                    ['label' => 'Level AA', 'value' => round($summary['level_aa_compliance'] ?? 0, 1) . '%']
                ];
                $widgetConfig['quickChart'] = [
                    'labels' => array_column($levelDistribution, 'level'),
                    'datasets' => [[
                        'data' => array_column($levelDistribution, 'count'),
                        'backgroundColor' => array_map(function ($item) use ($levelColors) {
                            $level = $item['level'] ?? '';
                            return $levelColors[$level] ?? '#6c757d';
                        }, $levelDistribution)
                    ]]
                ];
                break;
                
            case 'severity_analysis':
                $widgetConfig['title'] = 'Issue Severity';
                $widgetConfig['icon'] = 'fas fa-exclamation-triangle';
                $summary = $data['summary'] ?? [];
                $issueSeverityDistribution = $data['issue_severity_distribution'] ?? [];
                $issueSeverityColors = [
                    'Blocker' => '#7f1d1d',
                    'Critical' => '#dc2626',
                    'Major' => '#ea580c',
                    'Minor' => '#2563eb'
                ];
                $widgetConfig['summary'] = [
                    ['label' => 'Blocker', 'value' => $this->getIssueSeverityCount($issueSeverityDistribution, 'Blocker')],
                    ['label' => 'Critical', 'value' => $this->getIssueSeverityCount($issueSeverityDistribution, 'Critical')],
                    ['label' => 'Major', 'value' => $this->getIssueSeverityCount($issueSeverityDistribution, 'Major')],
                    ['label' => 'Minor', 'value' => $this->getIssueSeverityCount($issueSeverityDistribution, 'Minor')]
                ];
                $widgetConfig['quickChart'] = [
                    'labels' => array_column($issueSeverityDistribution, 'severity'),
                    'datasets' => [[
                        'data' => array_column($issueSeverityDistribution, 'count'),
                        'backgroundColor' => array_map(function ($item) use ($issueSeverityColors) {
                            $severity = $item['severity'] ?? '';
                            return $issueSeverityColors[$severity] ?? '#6c757d';
                        }, $issueSeverityDistribution)
                    ]]
                ];
                break;
                
            case 'common_issues':
                $widgetConfig['title'] = 'Common Issues';
                $widgetConfig['icon'] = 'fas fa-list-ul';
                $topIssues = array_slice($data['top_issues'] ?? [], 0, 4);
                $widgetConfig['summary'] = array_map(function($issue, $index) {
                    return [
                        'label' => '#' . ($index + 1) . ' Issue',
                        'value' => $issue['count'] ?? 0
                    ];
                }, $topIssues, array_keys($topIssues));
                break;
                
            case 'blocker_issues':
                $widgetConfig['title'] = 'Blocker Issues';
                $widgetConfig['icon'] = 'fas fa-ban';
                $summary = $data['summary'] ?? [];
                $widgetConfig['summary'] = [
                    ['label' => 'Active Blockers', 'value' => $summary['active_blockers'] ?? 0],
                    ['label' => 'Resolved', 'value' => $summary['resolved_blockers'] ?? 0],
                    ['label' => 'Avg Resolution', 'value' => ($summary['avg_resolution_days'] ?? 0) . ' days']
                ];
                break;
                
            case 'page_issues':
                $widgetConfig['title'] = 'Page Analysis';
                $widgetConfig['icon'] = 'fas fa-file-alt';
                $summary = $data['summary'] ?? [];
                $widgetConfig['summary'] = [
                    ['label' => 'Pages Analyzed', 'value' => $summary['total_pages'] ?? 0],
                    ['label' => 'Issues per Page', 'value' => round($summary['avg_issues_per_page'] ?? 0, 1)],
                    ['label' => 'Most Affected', 'value' => $summary['most_affected_page_issues'] ?? 0]
                ];
                break;
                
            case 'commented_issues':
                $widgetConfig['title'] = 'Discussion Activity';
                $widgetConfig['icon'] = 'fas fa-comments';
                $summary = $data['summary'] ?? [];
                $widgetConfig['summary'] = [
                    ['label' => 'With Comments', 'value' => $summary['commented_issues_count'] ?? 0],
                    ['label' => 'Total Comments', 'value' => $summary['total_comments'] ?? 0],
                    ['label' => 'Recent Activity', 'value' => $summary['recent_activity_count'] ?? 0]
                ];
                break;
                
            case 'compliance_trend':
                $widgetConfig['type'] = 'trend';
                $widgetConfig['title'] = 'Compliance Trends';
                $widgetConfig['icon'] = 'fas fa-chart-line';
                $widgetConfig['period'] = 'Last 30 days';
                $trendData = $data['daily_trends'] ?? [];
                $widgetConfig['trendData'] = [
                    'labels' => array_column($trendData, 'date'),
                    'datasets' => [[
                        'label' => 'Compliance Score',
                        'data' => array_column($trendData, 'compliance_score'),
                        'borderColor' => '#2563eb',
                        'backgroundColor' => 'rgba(37, 99, 235, 0.1)'
                    ]]
                ];
                break;
        }
        
        return $widgetConfig;
    }

    private function getIssueSeverityCount(array $distribution, string $label): int {
        foreach ($distribution as $item) {
            if (($item['severity'] ?? '') === $label) {
                return (int) ($item['count'] ?? 0);
            }
        }

        return 0;
    }
}