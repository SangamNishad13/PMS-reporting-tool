<?php
/**
 * Debug v5 - download actual sheet4.xml from export
 * DELETE THIS FILE AFTER DEBUGGING
 */
ob_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/client_issue_snapshots.php';
require_once __DIR__ . '/includes/models/SecurityValidator.php';
ob_end_clean();

$auth = new Auth();
if (!$auth->isLoggedIn()) { exit('Not logged in'); }

$projectId = (int)($_GET['project_id'] ?? 34);
$action = $_GET['action'] ?? 'info';

// Include all functions from export file by extracting them
// We'll just re-implement the key parts here

$db = Database::getInstance();

if ($action === 'download_sh4') {
    // Run the actual export and capture sheet4.xml
    // We do this by temporarily saving it
    
    // Replicate the export logic minimally
    ini_set('memory_limit', '256M');
    
    require_once __DIR__ . '/includes/project_permissions.php';
    
    // Get issues
    $issueStmt = $db->prepare("SELECT i.id, i.title, i.description, i.severity, i.issue_key, s.name as status_name FROM issues i LEFT JOIN issue_statuses s ON s.id = i.status_id WHERE i.project_id = ? ORDER BY i.id ASC");
    $issueStmt->execute([$projectId]);
    $allIssues = $issueStmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/plain');
    echo "Issues count: " . count($allIssues) . "\n";
    echo "filteredIssues would be: " . count($allIssues) . " (approx)\n\n";
    
    // Check what the actual export produces
    // Load the export file and check key variables
    $exportContent = file_get_contents(__DIR__ . '/api/export_client_report.php');
    
    // Check for key markers
    echo "=== export_client_report.php analysis ===\n";
    echo "File size: " . strlen($exportContent) . " bytes\n";
    echo "Has 'built from scratch': " . (strpos($exportContent, 'built from scratch') !== false ? 'YES' : 'NO') . "\n";
    echo "Has 'bypass corrupt': " . (strpos($exportContent, 'bypass corrupt') !== false ? 'YES' : 'NO') . "\n";
    echo "Has 'sortRows(\$sh4)' (uncommented): ";
    // Check if sortRows($sh4) is called (not commented)
    preg_match_all('/^[^\/\n]*sortRows\(\$sh4\)/m', $exportContent, $matches);
    echo count($matches[0]) . " times\n";
    echo "sortRows calls: " . implode(' | ', $matches[0]) . "\n\n";
    
    // Check injectRows call
    preg_match_all('/^[^\/\n]*injectRows\(\$sh4/m', $exportContent, $m2);
    echo "injectRows(\$sh4) calls: " . count($m2[0]) . "\n";
    echo implode("\n", $m2[0]) . "\n\n";
    
    // Show the sh4 building section
    $sh4Start = strpos($exportContent, 'SHEET 4');
    $sh4End = strpos($exportContent, 'SHEET 5', $sh4Start);
    if ($sh4End === false) $sh4End = strpos($exportContent, '// ── stream', $sh4Start);
    if ($sh4Start !== false) {
        echo "=== Sheet4 section (first 3000 chars) ===\n";
        echo substr($exportContent, $sh4Start, min(3000, ($sh4End ?: strlen($exportContent)) - $sh4Start));
    }
    exit;
}

// Default: show info
header('Content-Type: text/html');
echo "<h2>Debug v5 - Project $projectId</h2>";
echo "<p><a href='?project_id=$projectId&action=download_sh4'>View export analysis</a></p>";
echo "<hr><p style='color:gray'>Delete: debug_export_sheet4.php</p>";
