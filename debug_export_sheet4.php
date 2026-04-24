<?php
/**
 * Debug script v2 - check XML generation for sheet4
 * DELETE THIS FILE AFTER DEBUGGING
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) { exit('Not logged in'); }

$db = Database::getInstance();
$projectId = (int)($_GET['project_id'] ?? 34);

echo "<h2>Debug v2: Sheet4 XML Check - Project $projectId</h2>";
echo "<style>body{font-family:monospace;padding:20px;font-size:13px} pre{background:#f5f5f5;padding:10px;overflow:auto;max-height:400px}</style>";

// 1. Check issue_comments table structure
echo "<h3>1. issue_comments columns</h3>";
try {
    $cols = $db->query("SHOW COLUMNS FROM issue_comments")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    echo "<p>Columns: " . implode(', ', $colNames) . "</p>";
    echo "<p>Has comment_type: " . (in_array('comment_type', $colNames) ? '<strong style="color:green">YES</strong>' : '<strong style="color:red">NO</strong>') . "</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 2. Check regression_rounds table structure
echo "<h3>2. regression_rounds columns</h3>";
try {
    $cols = $db->query("SHOW COLUMNS FROM regression_rounds")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    echo "<p>Columns: " . implode(', ', $colNames) . "</p>";
    echo "<p>Has started_at: " . (in_array('started_at', $colNames) ? '<strong style="color:green">YES</strong>' : '<strong style="color:red">NO</strong>') . "</p>";
    echo "<p>Has ended_at: " . (in_array('ended_at', $colNames) ? '<strong style="color:green">YES</strong>' : '<strong style="color:red">NO</strong>') . "</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 3. Test commentStmt prepare
echo "<h3>3. commentStmt prepare test</h3>";
try {
    $commentStmt = $db->prepare("SELECT comment_html FROM issue_comments WHERE issue_id = ? AND comment_type = 'regression' AND created_at >= (SELECT started_at FROM regression_rounds WHERE id = ?) AND (created_at <= (SELECT ended_at FROM regression_rounds WHERE id = ?) OR (SELECT ended_at FROM regression_rounds WHERE id = ?) IS NULL) ORDER BY created_at DESC LIMIT 1");
    echo "<p style='color:green'>commentStmt prepare: OK</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>commentStmt prepare FAILED: " . $e->getMessage() . "</p>";
}

// 4. Test rrivStmt prepare
echo "<h3>4. rrivStmt prepare test</h3>";
try {
    $rrivStmt = $db->prepare("SELECT latest_payload FROM regression_round_issue_versions WHERE project_id = ? AND round_id = ? AND issue_id = ?");
    echo "<p style='color:green'>rrivStmt prepare: OK</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>rrivStmt prepare FAILED: " . $e->getMessage() . "</p>";
}

// 5. Simulate rows4 generation for first 3 issues
echo "<h3>5. rows4 XML generation test (first 3 issues)</h3>";
require_once __DIR__ . '/includes/functions.php';

function xstr_test(string $v): string {
    $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = htmlspecialchars($v, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    $v = str_replace("\n", '&#10;', $v);
    return $v;
}
function xcell_test(string $col, int $row, string $val): string {
    return '<c r="' . $col . $row . '" t="inlineStr"><is><t xml:space="preserve">' . xstr_test($val) . '</t></is></c>';
}

$issueStmt = $db->prepare("SELECT i.id, i.title, i.description, i.severity, i.issue_key, s.name as status_name FROM issues i LEFT JOIN issue_statuses s ON s.id = i.status_id WHERE i.project_id = ? ORDER BY i.id ASC LIMIT 3");
$issueStmt->execute([$projectId]);
$testIssues = $issueStmt->fetchAll(PDO::FETCH_ASSOC);

$rows4 = '';
$srNo = 1;
foreach ($testIssues as $iss) {
    $rn = $srNo + 1;
    $rows4 .= '<row r="' . $rn . '" spans="1:23">'
        . '<c r="A' . $rn . '"><v>' . $srNo . '</v></c>'
        . xcell_test('B', $rn, $iss['issue_key'] ?? '')
        . xcell_test('F', $rn, $iss['title'] ?? '')
        . '</row>';
    $srNo++;
}

echo "<p>rows4 length: " . strlen($rows4) . " chars</p>";
echo "<p>rows4 empty: " . (empty($rows4) ? '<strong style="color:red">YES - BUG!</strong>' : '<strong style="color:green">NO - has data</strong>') . "</p>";
echo "<pre>" . htmlspecialchars(substr($rows4, 0, 500)) . "</pre>";

// 6. Check ZipArchive and template
echo "<h3>6. Template & ZipArchive check</h3>";
$templatePath = __DIR__ . '/assets/templates/report_template.xlsx';
echo "<p>Template exists: " . (file_exists($templatePath) ? '<strong style="color:green">YES</strong>' : '<strong style="color:red">NO</strong>') . "</p>";
echo "<p>ZipArchive class: " . (class_exists('ZipArchive') ? '<strong style="color:green">YES</strong>' : '<strong style="color:red">NO</strong>') . "</p>";

if (file_exists($templatePath) && class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    $tmpFile = tempnam(sys_get_temp_dir(), 'pms_test_') . '.xlsx';
    copy($templatePath, $tmpFile);
    if ($zip->open($tmpFile) === true) {
        $sh4 = $zip->getFromName('xl/worksheets/sheet4.xml');
        echo "<p>sheet4.xml from template: " . ($sh4 !== false ? '<strong style="color:green">OK (' . strlen($sh4) . ' bytes)</strong>' : '<strong style="color:red">NOT FOUND</strong>') . "</p>";
        
        if ($sh4 !== false) {
            $hasSheetData = strpos($sh4, '<sheetData>') !== false;
            $hasSelfClose = strpos($sh4, '<sheetData/>') !== false;
            echo "<p>Has &lt;sheetData&gt;: " . ($hasSheetData ? 'YES' : 'NO') . "</p>";
            echo "<p>Has &lt;sheetData/&gt;: " . ($hasSelfClose ? 'YES' : 'NO') . "</p>";
            
            // Simulate the header injection
            $pos = strpos($sh4, '<sheetData>');
            $endPos = strpos($sh4, '</sheetData>');
            if ($pos !== false && $endPos !== false) {
                $before = substr($sh4, 0, $pos + 11);
                $after  = substr($sh4, $endPos);
                $headerRow = '<row r="1" spans="1:23"><c r="A1" t="inlineStr"><is><t>Sr.No</t></is></c></row>';
                $sh4mod = $before . $headerRow . $after;
                
                // Inject rows4
                $sh4mod = preg_replace('/<\/sheetData>/', $rows4 . '</sheetData>', $sh4mod, 1);
                
                // Check result
                $rowCount = substr_count($sh4mod, '<row ');
                echo "<p>After injection - row count in XML: <strong>$rowCount</strong> (expected: " . (count($testIssues) + 1) . ")</p>";
                
                if ($rowCount === count($testIssues) + 1) {
                    echo "<p style='color:green'><strong>XML injection working correctly!</strong></p>";
                } else {
                    echo "<p style='color:red'><strong>XML injection issue! Expected " . (count($testIssues) + 1) . " rows but got $rowCount</strong></p>";
                }
            }
        }
        $zip->close();
    } else {
        echo "<p style='color:red'>Cannot open template zip</p>";
    }
    @unlink($tmpFile);
}

// 7. PHP error log check
echo "<h3>7. PHP info</h3>";
echo "<p>PHP version: " . PHP_VERSION . "</p>";
echo "<p>Memory limit: " . ini_get('memory_limit') . "</p>";
echo "<p>Max execution time: " . ini_get('max_execution_time') . "</p>";

echo "<hr><p style='color:gray'>Delete this file: debug_export_sheet4.php</p>";
