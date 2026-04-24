<?php
/**
 * Debug v4 - verify which code version is running on live
 * DELETE THIS FILE AFTER DEBUGGING
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) { exit('Not logged in'); }

echo "<h2>Debug v4: Code version check</h2>";
echo "<style>body{font-family:monospace;padding:20px;font-size:13px}</style>";

// 1. Check what version of export_client_report.php is running
$exportFile = __DIR__ . '/api/export_client_report.php';
$content = file_get_contents($exportFile);

echo "<h3>1. export_client_report.php version check</h3>";
echo "<p>File size: " . filesize($exportFile) . " bytes</p>";
echo "<p>Last modified: " . date('Y-m-d H:i:s', filemtime($exportFile)) . "</p>";

// Check for our latest fix
if (strpos($content, 'built from scratch') !== false) {
    echo "<p style='color:green'><strong>✅ Latest fix IS present (build from scratch)</strong></p>";
} else {
    echo "<p style='color:red'><strong>❌ Latest fix NOT present - git pull not done or failed</strong></p>";
}

if (strpos($content, 'bypass corrupt live template') !== false) {
    echo "<p style='color:green'>✅ Bypass corrupt template fix present</p>";
}

if (strpos($content, 'memory_limit') !== false) {
    echo "<p style='color:green'>✅ Memory limit fix present</p>";
} else {
    echo "<p style='color:red'>❌ Memory limit fix NOT present</p>";
}

// 2. Check git log
echo "<h3>2. Git status</h3>";
$gitLog = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git log --oneline -5 2>&1');
echo "<pre>" . htmlspecialchars($gitLog) . "</pre>";

$gitStatus = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git status 2>&1');
echo "<pre>" . htmlspecialchars($gitStatus) . "</pre>";

// 3. Quick simulation - does sh4 get built correctly?
echo "<h3>3. Sheet4 build simulation</h3>";

$templatePath = __DIR__ . '/assets/templates/report_template.xlsx';
if (!file_exists($templatePath)) { echo "<p style='color:red'>Template not found!</p>"; exit; }

$zip = new ZipArchive();
$tmpFile = tempnam(sys_get_temp_dir(), 'pms_dbg_') . '.xlsx';
copy($templatePath, $tmpFile);
$zip->open($tmpFile);
$sh4template = $zip->getFromName('xl/worksheets/sheet4.xml');
$zip->close();
@unlink($tmpFile);

echo "<p>Template sheet4.xml size: " . strlen($sh4template) . " bytes</p>";

// Simulate fresh build
$colsXml = '';
$sheetViewsXml = '';
if ($sh4template !== false) {
    if (preg_match('/<cols>(.*?)<\/cols>/s', $sh4template, $cm)) {
        $colsXml = '<cols>' . $cm[1] . '</cols>';
        echo "<p style='color:green'>✅ cols extracted: " . strlen($colsXml) . " bytes</p>";
    } else {
        echo "<p style='color:orange'>⚠️ No cols in template</p>";
    }
    if (preg_match('/<sheetViews>(.*?)<\/sheetViews>/s', $sh4template, $svm)) {
        $sheetViewsXml = '<sheetViews>' . $svm[1] . '</sheetViews>';
        echo "<p style='color:green'>✅ sheetViews extracted: " . strlen($sheetViewsXml) . " bytes</p>";
    }
}

$sh4fresh = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . ($sheetViewsXml ?: '<sheetViews><sheetView workbookViewId="0"/></sheetViews>')
    . ($colsXml ?: '')
    . '<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Test</t></is></c></row></sheetData>'
    . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0" footer="0"/>'
    . '</worksheet>';

libxml_use_internal_errors(true);
simplexml_load_string($sh4fresh);
$errs = libxml_get_errors();
libxml_clear_errors();

if (empty($errs)) {
    echo "<p style='color:green'><strong>✅ Fresh sheet4 XML is VALID</strong></p>";
} else {
    echo "<p style='color:red'><strong>❌ Fresh sheet4 XML error: " . htmlspecialchars(trim($errs[0]->message)) . "</strong></p>";
    // The cols or sheetViews from template is corrupt!
    echo "<p>cols XML snippet: <code>" . htmlspecialchars(substr($colsXml, 0, 200)) . "</code></p>";
    echo "<p>sheetViews XML snippet: <code>" . htmlspecialchars(substr($sheetViewsXml, 0, 200)) . "</code></p>";
}

echo "<hr><p style='color:gray'>Delete: debug_export_sheet4.php</p>";
