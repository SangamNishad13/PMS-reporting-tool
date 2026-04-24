<?php
/**
 * Debug v3 - simulate full sheet4 XML generation and find exact error
 * DELETE THIS FILE AFTER DEBUGGING
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/client_issue_snapshots.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) { exit('Not logged in'); }

$db = Database::getInstance();
$projectId = (int)($_GET['project_id'] ?? 34);

echo "<h2>Debug v3b: Full sheet4 XML simulation - Project $projectId</h2>";
echo "<style>body{font-family:monospace;padding:20px;font-size:12px} pre{background:#f5f5f5;padding:8px;overflow:auto;max-height:300px;font-size:11px}</style>";

// Copy all helper functions inline
function xstr2(string $v): string {
    $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    $v = preg_replace('/\xED[\xA0-\xBF][\x80-\xBF]/s', '', $v) ?? '';
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = htmlspecialchars($v, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    $v = str_replace("\n", '&#10;', $v);
    return $v;
}
function xcell2(string $col, int $row, string $val, string $style = ''): string {
    $s = $style ? ' s="' . $style . '"' : '';
    return '<c r="' . $col . $row . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . xstr2($val) . '</t></is></c>';
}
function numToCol2(int $n): string {
    $result = '';
    while ($n >= 0) { $result = chr($n % 26 + 65) . $result; $n = intdiv($n, 26) - 1; }
    return $result;
}
function stripHtml2(string $html): string {
    if (!$html) return '';
    $text = preg_replace_callback('/<(?:pre|code)\b[^>]*>(.*?)<\/\s*(?:pre|code)>/is', function ($m) {
        return '###CODE###' . htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '###ENDCODE###';
    }, $html);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<li\b[^>]*>/i', '• ', $text);
    $text = preg_replace(['/<\/li>/i','/<\/p>/i','/<\/h[1-6]>/i','/<\/div>/i','/<\/ul>/i','/<\/ol>/i'], "\n", $text);
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
function metaFirst2(array $meta, string $key): string {
    if (empty($meta[$key])) return '';
    $v = $meta[$key][0];
    if (is_string($v) && strlen($v) > 0 && $v[0] === '[') {
        $d = json_decode($v, true);
        if (is_array($d) && !empty($d)) return (string)$d[0];
    }
    return (string)$v;
}
function metaArray2(array $meta, string $key): array {
    if (empty($meta[$key])) return [];
    $out = [];
    foreach ($meta[$key] as $v) {
        $v = (string)$v;
        if (strlen($v) > 0 && $v[0] === '[') {
            $d = json_decode($v, true);
            if (is_array($d)) { foreach ($d as $item) { $item = trim((string)$item); if ($item !== '') $out[] = $item; } continue; }
        }
        $v = trim($v); if ($v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
}
function extractSections2(string $html): array {
    $keys = ['actual_result','incorrect_code','screenshot','recommendation','correct_code'];
    $empty = array_fill_keys($keys, '');
    if (!$html) return $empty;
    $labels = ['actual_result'=>'Actual Result','incorrect_code'=>'Incorrect Code','screenshot'=>'Screenshot','recommendation'=>'Recommendation','correct_code'=>'Correct Code'];
    $found = false;
    foreach ($labels as $key => $label) { if (stripos($html, '[' . $label . ']') !== false) { $found = true; break; } }
    if (!$found) { $plain = strip_tags($html); foreach ($labels as $key => $label) { if (stripos($plain, '[' . $label . ']') !== false) { $found = true; $html = $plain; break; } } }
    if (!$found) return $empty;
    $patterns = [
        'actual_result'  => '/\[Actual Result\](.*?)(?=\[(?:Incorrect Code|Screenshot|Recommendation|Correct Code)\]|\z)/is',
        'incorrect_code' => '/\[Incorrect Code\](.*?)(?=\[(?:Actual Result|Screenshot|Recommendation|Correct Code)\]|\z)/is',
        'screenshot'     => '/\[Screenshot\](.*?)(?=\[(?:Actual Result|Incorrect Code|Recommendation|Correct Code)\]|\z)/is',
        'recommendation' => '/\[Recommendation\](.*?)(?=\[(?:Actual Result|Incorrect Code|Screenshot|Correct Code)\]|\z)/is',
        'correct_code'   => '/\[Correct Code\](.*?)(?=\[(?:Actual Result|Incorrect Code|Screenshot|Recommendation)\]|\z)/is',
    ];
    $out = $empty;
    foreach ($patterns as $key => $pat) {
        if (preg_match($pat, $html, $m)) {
            $content = trim(preg_replace('/^(?:\s|&nbsp;|<br\s*\/?>|<\/?(?:p|div)[^>]*>)+|(?:\s|&nbsp;|<br\s*\/?>|<\/?(?:p|div)[^>]*>)+$/i', '', $m[1] ?? ''));
            $out[$key] = $content;
        }
    }
    return $out;
}

// Fetch issues + metadata
$issueStmt = $db->prepare("SELECT i.id, i.title, i.description, i.severity, i.issue_key, s.name as status_name FROM issues i LEFT JOIN issue_statuses s ON s.id = i.status_id WHERE i.project_id = ? ORDER BY i.id ASC");
$issueStmt->execute([$projectId]);
$allIssues = $issueStmt->fetchAll(PDO::FETCH_ASSOC);
$issueIds = array_column($allIssues, 'id');
$ph = implode(',', array_fill(0, count($issueIds), '?'));

$metaMap = [];
$ms = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($ph) ORDER BY id ASC");
$ms->execute($issueIds);
while ($m = $ms->fetch(PDO::FETCH_ASSOC)) { $metaMap[(int)$m['issue_id']][$m['meta_key']][] = $m['meta_value']; }

$pagesByIssue = [];
$ps = $db->prepare("SELECT ip.issue_id, pp.id AS page_id, pp.page_number, pp.page_name, pp.url FROM issue_pages ip JOIN project_pages pp ON ip.page_id = pp.id WHERE ip.issue_id IN ($ph)");
$ps->execute($issueIds);
while ($p = $ps->fetch(PDO::FETCH_ASSOC)) { $pagesByIssue[(int)$p['issue_id']][] = $p; }

$guPerPageStmt = $db->prepare("SELECT url FROM grouped_urls WHERE project_id = ? AND unique_page_id = ? ORDER BY id ASC");

$baseHeaders = ['Sr.No','Issue Key','Page No','Page Name','Page URL','Issue Title','Actual Result','Incorrect Code','Screenshots','Recommendation','Correct Code','Severity','Priority','Responsibility','User Affected','WCAG SC Number','WCAG SC Name','WCAG Level','GIGW 3.0','IS 17802','Environments',"Developer's Status","Developer's Comment"];
$totalCols = count($baseHeaders);

// Build rows4
$rows4 = '';
$srNo = 1;
foreach ($allIssues as $iss) {
    $iid = (int)$iss['id'];
    $rn = $srNo + 1;
    $meta = $metaMap[$iid] ?? [];
    $pages = $pagesByIssue[$iid] ?? [];
    $pageNums = implode(', ', array_column($pages, 'page_number'));
    $pageNames = implode(', ', array_column($pages, 'page_name'));
    $pageUrlValues = [];
    foreach ($pages as $page) {
        $pageId = (int)($page['page_id'] ?? $page['id'] ?? 0);
        if ($pageId > 0) { $guPerPageStmt->execute([$projectId, $pageId]); $grouped = $guPerPageStmt->fetchAll(PDO::FETCH_COLUMN); }
        else $grouped = [];
        if (!empty($grouped)) { foreach ($grouped as $g) { $g = trim((string)$g); if ($g !== '') $pageUrlValues[] = $g; } continue; }
        $fb = trim((string)($page['url'] ?? '')); if ($fb !== '') $pageUrlValues[] = $fb;
    }
    $pageUrls = implode(', ', array_values(array_unique($pageUrlValues)));
    $wcagNums = implode(', ', metaArray2($meta, 'wcagsuccesscriteria'));
    $wcagNames = implode(', ', metaArray2($meta, 'wcagsuccesscriterianame'));
    $wcagLevel = implode(', ', metaArray2($meta, 'wcagsuccesscriterialevel'));
    $gigw = implode(', ', metaArray2($meta, 'gigw30'));
    $is17802 = implode(', ', metaArray2($meta, 'is17802'));
    $severity = ucfirst(strtolower(metaFirst2($meta, 'severity') ?: ($iss['severity'] ?? 'minor')));
    $priority = ucfirst(strtolower(metaFirst2($meta, 'priority') ?: 'medium'));
    $responsibility = implode(', ', metaArray2($meta, 'responsibility'));
    $envs = implode(', ', metaArray2($meta, 'environments'));
    $issueKey = $iss['issue_key'] ?? metaFirst2($meta, 'issue_key');
    $usersArr = [];
    foreach ($meta['usersaffected'] ?? [] as $rawVal) { foreach (array_filter(array_map('trim', explode(',', $rawVal))) as $u) { $usersArr[] = $u; } }
    $usersAff = implode(', ', array_unique($usersArr));
    $sections = extractSections2($iss['description'] ?? '');
    $actualResult = stripHtml2($sections['actual_result']);
    $incorrectCode = stripHtml2($sections['incorrect_code']);
    $recommendation = stripHtml2($sections['recommendation']);
    $correctCode = stripHtml2($sections['correct_code']);
    if ($actualResult === '' && $incorrectCode === '' && $recommendation === '' && $correctCode === '') {
        $actualResult = stripHtml2($iss['description'] ?? '');
    }
    $screenshotUrls = '';

    $rowXml = '<row r="' . $rn . '" spans="1:' . $totalCols . '">'
        . '<c r="A' . $rn . '"><v>' . $srNo . '</v></c>'
        . xcell2('B', $rn, $issueKey)
        . xcell2('C', $rn, $pageNums)
        . xcell2('D', $rn, $pageNames)
        . xcell2('E', $rn, $pageUrls)
        . xcell2('F', $rn, $iss['title'] ?? '')
        . xcell2('G', $rn, $actualResult)
        . xcell2('H', $rn, $incorrectCode)
        . xcell2('I', $rn, $screenshotUrls)
        . xcell2('J', $rn, $recommendation)
        . xcell2('K', $rn, $correctCode)
        . xcell2('L', $rn, $severity)
        . xcell2('M', $rn, $priority)
        . xcell2('N', $rn, $responsibility)
        . xcell2('O', $rn, $usersAff)
        . xcell2('P', $rn, $wcagNums)
        . xcell2('Q', $rn, $wcagNames)
        . xcell2('R', $rn, $wcagLevel)
        . xcell2('S', $rn, $gigw)
        . xcell2('T', $rn, $is17802)
        . xcell2('U', $rn, $envs)
        . xcell2('V', $rn, '')
        . xcell2('W', $rn, '')
        . '</row>';

    // Validate this individual row
    libxml_use_internal_errors(true);
    simplexml_load_string('<?xml version="1.0" encoding="UTF-8"?><root>' . $rowXml . '</root>');
    $errs = libxml_get_errors(); libxml_clear_errors();
    if (!empty($errs)) {
        echo "<div style='background:#ffe0e0;padding:8px;margin:4px'>";
        echo "<strong>❌ Issue {$iid} ({$issueKey}): " . htmlspecialchars($iss['title']) . "</strong><br>";
        echo "XML error: " . htmlspecialchars(trim($errs[0]->message)) . " at col " . $errs[0]->column . "<br>";
        $col = (int)$errs[0]->column;
        echo "Snippet: <code>" . htmlspecialchars(substr($rowXml, max(0, $col - 60), 120)) . "</code><br>";
        echo "</div>";
    }

    $rows4 .= $rowXml;
    $srNo++;
}

// Now validate the full rows4
echo "<p>rows4 total length: " . strlen($rows4) . " bytes</p>";

$fullXml = '<?xml version="1.0" encoding="UTF-8"?><root>' . $rows4 . '</root>';
libxml_use_internal_errors(true);
simplexml_load_string($fullXml);
$errs = libxml_get_errors(); libxml_clear_errors();

if (empty($errs)) {
    echo "<p style='color:green'><strong>✅ Full rows4 XML is VALID!</strong></p>";
    echo "<p>The issue must be in the template XML manipulation (injectRows/sortRows). Checking...</p>";
    
    // Now simulate full sheet4 generation
    $templatePath = __DIR__ . '/assets/templates/report_template.xlsx';
    $zip = new ZipArchive();
    $tmpFile = tempnam(sys_get_temp_dir(), 'pms_dbg_') . '.xlsx';
    copy($templatePath, $tmpFile);
    $zip->open($tmpFile);
    $sh4 = $zip->getFromName('xl/worksheets/sheet4.xml');
    $zip->close();
    @unlink($tmpFile);
    
    // Inject header
    $pos = strpos($sh4, '<sheetData>');
    $endPos = strpos($sh4, '</sheetData>');
    $before = substr($sh4, 0, $pos + 11);
    $after = substr($sh4, $endPos);
    $headerRow = '<row r="1" spans="1:' . $totalCols . '">';
    foreach ($baseHeaders as $i => $hText) {
        $col = numToCol2($i);
        $headerRow .= xcell2($col, 1, $hText, '2');
    }
    $headerRow .= '</row>';
    $sh4 = $before . $headerRow . $after;
    
    // Inject rows4
    $sh4 = preg_replace('/<\/sheetData>/', $rows4 . '</sheetData>', $sh4, 1);
    
    echo "<p>sh4 length after injection: " . strlen($sh4) . " bytes</p>";
    
    // Validate
    libxml_use_internal_errors(true);
    simplexml_load_string($sh4);
    $errs2 = libxml_get_errors(); libxml_clear_errors();
    if (empty($errs2)) {
        echo "<p style='color:green'><strong>✅ Full sh4 XML is VALID after injection!</strong></p>";
        echo "<p style='color:orange'>Issue might be in sortRows or something else. Try exporting again after git pull.</p>";
    } else {
        echo "<p style='color:red'><strong>❌ sh4 XML error after injection: " . htmlspecialchars(trim($errs2[0]->message)) . " at col " . $errs2[0]->column . "</strong></p>";
        $col = (int)$errs2[0]->column;
        $line = (int)$errs2[0]->line;
        $lines = explode("\n", $sh4);
        $lineContent = $lines[$line - 1] ?? $sh4;
        echo "<p>Snippet at error: <code>" . htmlspecialchars(substr($lineContent, max(0, $col - 80), 160)) . "</code></p>";
    }
} else {
    echo "<p style='color:red'><strong>❌ rows4 XML error: " . htmlspecialchars(trim($errs[0]->message)) . " at col " . $errs[0]->column . "</strong></p>";
    $col = (int)$errs[0]->column;
    echo "<p>Snippet: <code>" . htmlspecialchars(substr($rows4, max(0, $col - 80), 160)) . "</code></p>";
}

echo "<hr><p style='color:gray'>Delete: debug_export_sheet4.php</p>";
