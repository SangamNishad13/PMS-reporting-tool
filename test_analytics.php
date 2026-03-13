<?php
require_once 'includes/models/CommentedIssuesAnalytics.php';

try {
    $analytics = new CommentedIssuesAnalytics();
    $report = $analytics->generateReport(null, null);
    echo "Report generated successfully.\n";
    echo "Total commented issues: " . $report->getData()['summary']['total_commented_issues'] . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
