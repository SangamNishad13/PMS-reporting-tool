<?php
/**
 * Debug v3 - find which issue has bad XML characters
 * DELETE THIS FILE AFTER DEBUGGING
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) { exit('Not logged in'); }

$db = Database::getInstance();
$projectId = (int)($_GET['project_id'] ?? 34);

echo "<h2>Debug v3: Find bad XML chars - Project $projectId</h2>";
echo "<style>body{font-family:monospace;padding:20px;font-size:13px} .bad{background:#ffe0e0} .ok{background:#e0ffe0}</style>";

function xstr_test(string $v): string {
    $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    $v = preg_replace('/\xED[\xA0-\xBF][\x80-\xBF]/s', '', $v);
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = htmlspecialchars($v, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    $v = str_replace("\n", '&#10;', $v);
    return $v;
}

function xcell_test(string $col, int $row, string $val, string $style = ''): string {
    $s = $style ? ' s="' . $style . '"' : '';
    return '<c r="' . $col . $row . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . xstr_test($val) . '</t></is></c>';
}

function stripHtml_test(string $html): string {
    if (!$html) return '';
    $text = preg_replace_callback('/<(?:pre|code)\b[^>]*>(.*?)<\/\s*(?:pre|code)>/is', function ($m) {
        return '###CODE###' . htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '###ENDCODE###';
    }, $html);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<li\b[^>]*>/i', '• ', $text);
    $text = preg_replace('/<\/li>/i', "\n", $text);
    $text = preg_replace('/<\/p>/i', "\n", $text);
    $text = preg_replace('/<\/h[1-6]>/i', "\n", $text);
    $text = preg_replace('/<\/div>/i', "\n", $text);
    $text = preg_replace('/<\/?(?:div|p|h[1-6]|ul|ol)[^>]*>/i', '', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace_callback('/###CODE###(.*?)###ENDCODE###/s', function ($m) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }, $text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n[ \t]*/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

// Fetch all issues with descriptions
$stmt = $db->prepare("SELECT i.id, i.title, i.issue_key, i.description FROM issues i WHERE i.project_id = ? ORDER BY i.id ASC");
$stmt->execute([$projectId]);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Checking " . count($issues) . " issues for XML validity...</p>";

$badIssues = [];

foreach ($issues as $iss) {
    $iid = (int)$iss['id'];
    $desc = $iss['description'] ?? '';
    
    // Simulate what export does
    $stripped = stripHtml_test($desc);
    $cellXml = xcell_test('G', 2, $stripped);
    
    // Check if valid XML
    libxml_use_internal_errors(true);
    $testXml = '<?xml version="1.0" encoding="UTF-8"?><root>' . $cellXml . '</root>';
    simplexml_load_string($testXml);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    
    if (!empty($errors)) {
        $badIssues[] = [
            'id' => $iid,
            'title' => $iss['title'],
            'key' => $iss['issue_key'],
            'error' => trim($errors[0]->message),
            'col' => $errors[0]->column,
            'snippet' => substr($cellXml, max(0, (int)$errors[0]->column - 50), 100),
            'raw_snippet' => bin2hex(substr($stripped, max(0, (int)$errors[0]->column - 20), 40)),
        ];
    }
    
    // Also check raw description for control chars
    $hasControlChars = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $desc);
    $hasInvalidUtf8 = !mb_check_encoding($desc, 'UTF-8');
    
    if ($hasControlChars || $hasInvalidUtf8 || !empty($errors)) {
        echo "<div class='bad'>";
        echo "<strong>Issue {$iid} ({$iss['issue_key']}): {$iss['title']}</strong><br>";
        if ($hasControlChars) echo "⚠️ Has control characters in description<br>";
        if ($hasInvalidUtf8) echo "⚠️ Has invalid UTF-8 in description<br>";
        if (!empty($errors)) echo "❌ XML error: " . htmlspecialchars(trim($errors[0]->message)) . " at col " . $errors[0]->column . "<br>";
        if (!empty($errors)) echo "Snippet: <code>" . htmlspecialchars($badIssues[count($badIssues)-1]['snippet']) . "</code><br>";
        if (!empty($errors)) echo "Raw hex: <code>" . $badIssues[count($badIssues)-1]['raw_snippet'] . "</code><br>";
        echo "</div><br>";
    }
}

if (empty($badIssues)) {
    echo "<p style='color:green'><strong>No XML issues found in issue descriptions!</strong></p>";
    echo "<p>The issue might be in metadata fields. Checking metadata...</p>";
    
    // Check metadata
    $issueIds = array_column($issues, 'id');
    $ph = implode(',', array_fill(0, count($issueIds), '?'));
    $metaStmt = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($ph) AND meta_key IN ('wcagsuccesscriteria','wcagsuccesscriterianame','wcagsuccesscriterialevel','gigw30','is17802','severity','priority','responsibility','environments','usersaffected') ORDER BY issue_id, meta_key");
    $metaStmt->execute($issueIds);
    $metaRows = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($metaRows as $m) {
        $val = (string)$m['meta_value'];
        $cellXml = xcell_test('A', 2, $val);
        libxml_use_internal_errors(true);
        simplexml_load_string('<?xml version="1.0" encoding="UTF-8"?><root>' . $cellXml . '</root>');
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if (!empty($errors)) {
            echo "<div class='bad'>";
            echo "<strong>Issue {$m['issue_id']} meta_key={$m['meta_key']}</strong><br>";
            echo "Value: <code>" . htmlspecialchars(substr($val, 0, 100)) . "</code><br>";
            echo "XML error: " . htmlspecialchars(trim($errors[0]->message)) . "<br>";
            echo "Raw hex: <code>" . bin2hex(substr($val, 0, 40)) . "</code><br>";
            echo "</div><br>";
        }
    }
}

echo "<p>Done. Bad issues: " . count($badIssues) . "</p>";
echo "<hr><p style='color:gray'>Delete: debug_export_sheet4.php</p>";
