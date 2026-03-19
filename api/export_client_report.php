<?php
/**
 * Server-side Excel report generator using ZipArchive.
 * Opens template xlsx, injects data into XML, streams result.
 * Preserves ALL formatting, charts, images, and formulas.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) { http_response_code(401); exit('Unauthorized'); }

$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) { http_response_code(400); exit('project_id required'); }
if (!class_exists('ZipArchive')) { http_response_code(500); exit('ZipArchive not available'); }

$templatePath = __DIR__ . '/../assets/templates/report_template.xlsx';
if (!file_exists($templatePath)) { http_response_code(404); exit('Template not found'); }

$db = Database::getInstance();

// ── helpers ───────────────────────────────────────────────────────────────────

/**
 * Get first value for a meta key. Handles JSON-encoded arrays stored as strings.
 */
function metaFirst(array $meta, string $key): string {
    if (empty($meta[$key])) return '';
    $v = $meta[$key][0]; // always array of raw DB rows
    if (is_string($v) && strlen($v) > 0 && $v[0] === '[') {
        $d = json_decode($v, true);
        if (is_array($d) && !empty($d)) return (string)$d[0];
    }
    return (string)$v;
}

/**
 * Get all values for a meta key as a flat array.
 * Each DB row is one value (may be plain string or JSON array).
 * Does NOT split on comma — each row is treated as one atomic value.
 */
function metaArray(array $meta, string $key): array {
    if (empty($meta[$key])) return [];
    $out = [];
    foreach ($meta[$key] as $v) {
        $v = (string)$v;
        if (strlen($v) > 0 && $v[0] === '[') {
            $d = json_decode($v, true);
            if (is_array($d)) {
                foreach ($d as $item) {
                    $item = trim((string)$item);
                    if ($item !== '') $out[] = $item;
                }
                continue;
            }
        }
        $v = trim($v);
        if ($v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
}

function xstr(string $v): string {
    // Fix invalid UTF-8 sequences first
    $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    // Strip illegal XML 1.0 characters (control chars except tab/LF/CR, and UTF-8 surrogates)
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    // Strip UTF-8 encoded surrogates (U+D800–U+DFFF)
    $v = preg_replace('/\xED[\xA0-\xBF][\x80-\xBF]/s', '', $v);
    // Strip emoji and supplementary plane characters that can cause issues (U+10000+)
    // These are encoded as 4-byte UTF-8 sequences: F0-F4 ...
    // Keep them but ensure they're valid; mb_convert_encoding above handles this
    // Normalize CR+LF and bare CR to LF
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    return htmlspecialchars($v, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an xlsx inlineStr cell — supports newlines and all text content */
function xcell(string $col, int $row, string $val, string $style = ''): string {
    $s = $style ? ' s="' . $style . '"' : '';
    return '<c r="' . $col . $row . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . xstr($val) . '</t></is></c>';
}

/**
 * Replace a cell in worksheet XML with a new value.
 * Handles both self-closing <c .../> and normal <c ...>...</c> forms.
 * Preserves the style (s="N") attribute.
 */
function setCell(string &$xml, string $ref, string $value, bool $isNum = false): void {
    $rq = preg_quote($ref, '/');
    $style = '';

    // Try self-closing form first: <c r="REF" s="N"/>
    // Use strict pattern: [^/]* to avoid matching past the />
    $selfPat = '/<c\s+r="' . $rq . '"([^\/]*)\s*\/>/';
    if (preg_match($selfPat, $xml, $m)) {
        if (preg_match('/\bs="(\d+)"/', $m[1], $sm)) $style = ' s="' . $sm[1] . '"';
        $repl = $isNum
            ? '<c r="' . $ref . '"' . $style . '><v>' . (int)$value . '</v></c>'
            : '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . xstr($value) . '</t></is></c>';
        $xml = preg_replace($selfPat, $repl, $xml, 1);
        return;
    }

    // Normal form: find opening <c r="REF" ...> then scan to matching </c>
    // Use strpos-based approach to handle multiline formula content safely
    $openPat = '/<c\s+r="' . $rq . '"([^>\/][^>]*)>/';
    if (!preg_match($openPat, $xml, $m, PREG_OFFSET_CAPTURE)) return;
    if (preg_match('/\bs="(\d+)"/', $m[1][0], $sm)) $style = ' s="' . $sm[1] . '"';
    $start    = $m[0][1];
    $closePos = strpos($xml, '</c>', $start + strlen($m[0][0]));
    if ($closePos === false) return;
    $end = $closePos + 4;

    $repl = $isNum
        ? '<c r="' . $ref . '"' . $style . '><v>' . (int)$value . '</v></c>'
        : '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . xstr($value) . '</t></is></c>';
    $xml = substr($xml, 0, $start) . $repl . substr($xml, $end);
}

/**
 * Inject or replace a cell in a specific row. Creates the row if needed.
 */
function injectCell(string &$xml, int $rowNum, string $ref, string $value, string $styleAttr = '', bool $isNum = false): void {
    $rq = preg_quote($ref, '/');

    // Check if cell already exists (self-closing or normal)
    $cellExists = preg_match('/<c\s+r="' . $rq . '"\s*\/>/', $xml)
               || preg_match('/<c\s+r="' . $rq . '"[^\/][^>]*>/', $xml);
    if ($cellExists) {
        setCell($xml, $ref, $value, $isNum);
        return;
    }

    $newCell = $isNum
        ? '<c r="' . $ref . '"' . $styleAttr . '><v>' . (int)$value . '</v></c>'
        : '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">' . xstr($value) . '</t></is></c>';

    // Find existing row using strpos-based approach (avoids regex backtracking on large XML)
    $rowTag = '<row r="' . $rowNum . '"';
    $rowPos = strpos($xml, $rowTag);
    if ($rowPos !== false) {
        // Find end of opening row tag
        $rowOpenEnd = strpos($xml, '>', $rowPos) + 1;
        // Find closing </row>
        $rowClosePos = strpos($xml, '</row>', $rowOpenEnd);
        if ($rowClosePos !== false) {
            // Insert cell before </row>
            $xml = substr($xml, 0, $rowClosePos) . $newCell . substr($xml, $rowClosePos);
            return;
        }
    }

    // Row doesn't exist — create it before </sheetData>
    if (strpos($xml, '</sheetData>') !== false) {
        $xml = preg_replace('/<\/sheetData>/', '<row r="' . $rowNum . '" spans="1:18">' . $newCell . '</row></sheetData>', $xml, 1);
    } else {
        $xml = preg_replace('/<sheetData\s*\/>/s', '<sheetData><row r="' . $rowNum . '" spans="1:18">' . $newCell . '</row></sheetData>', $xml, 1);
    }
}

/**
 * Inject rows into a sheet's sheetData. Replaces existing sheetData content
 * (after clearDataRows) with the new rows. Handles both </sheetData> and
 * self-closing <sheetData/> forms. Safe to call even if rows string is empty.
 * Also updates the <dimension> element to cover all rows.
 */
function injectRows(string &$xml, string $rows): void {
    if (strpos($xml, '</sheetData>') !== false) {
        // Replace only the FIRST occurrence to avoid double-injection
        $xml = preg_replace('/<\/sheetData>/', $rows . '</sheetData>', $xml, 1);
    } else {
        $xml = preg_replace('/<sheetData\s*\/>/s', '<sheetData>' . $rows . '</sheetData>', $xml, 1);
    }
    // Remove dimension element — Excel will recalculate it, avoids "repair" dialog
    $xml = preg_replace('/<dimension\s+ref="[^"]*"\s*\/>/s', '', $xml);
}

/** Remove all data rows (row 2 onwards), keep header row 1.
 *  Handles both <row ...>...</row> and self-closing <row .../> forms.
 */
function clearDataRows(string &$xml): void {
    // Self-closing rows: <row r="N" ... />
    $xml = preg_replace('/<row\s+r="[1-9]\d+"[^>]*\/>/s', '', $xml);  // 10+
    $xml = preg_replace('/<row\s+r="[2-9]"[^>]*\/>/s', '', $xml);     // 2-9
    // Normal rows: <row r="N" ...>...</row>
    $xml = preg_replace('/<row\s+r="[1-9]\d+"[^>]*>.*?<\/row>/s', '', $xml); // 10+
    $xml = preg_replace('/<row\s+r="[2-9]"[^>]*>.*?<\/row>/s', '', $xml);    // 2-9
}

function stripHtml(string $html): string {
    if (!$html) return '';
    // Replace block-level tags with newlines/bullets BEFORE stripping
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/p>/i', "\n", $text);
    $text = preg_replace('/<li\b[^>]*>/i', '• ', $text);
    // Strip all HTML tags (leaves entity-encoded content like &lt;header&gt; intact)
    $text = strip_tags($text);
    // NOW decode entities so &lt;header&gt; → <header> shows as literal text
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Collapse whitespace
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/** Build absolute URL from a possibly-relative src path */
function absoluteUrl(string $src): string {
    if ($src === '') return '';
    if (preg_match('#^https?://#i', $src)) return $src;
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host . '/' . ltrim($src, '/');
}

/**
 * Extract named sections from issue description HTML.
 * Sections are delimited by [Section Name] markers embedded in the rich text.
 * Works on both raw HTML and plain text descriptions.
 * Returns array: ['actual_result' => '...html...', 'incorrect_code' => '...', ...]
 */
function extractSections(string $html): array {
    $keys = ['actual_result', 'incorrect_code', 'screenshot', 'recommendation', 'correct_code'];
    $empty = array_fill_keys($keys, '');

    if (!$html) return $empty;

    // Marker labels (case-insensitive)
    $labels = [
        'actual_result'  => 'Actual Result',
        'incorrect_code' => 'Incorrect Code',
        'screenshot'     => 'Screenshot',
        'recommendation' => 'Recommendation',
        'correct_code'   => 'Correct Code',
    ];

    // Strategy 1: markers exist directly in HTML (most common)
    // Try matching on raw HTML first
    $found = false;
    foreach ($labels as $key => $label) {
        if (stripos($html, '[' . $label . ']') !== false) { $found = true; break; }
    }

    // Strategy 2: markers may be inside HTML tags or encoded — strip tags first
    // then re-match on plain text, but keep original HTML for section content
    if (!$found) {
        // Try after stripping tags (markers might be wrapped in <p>, <strong> etc.)
        $plain = strip_tags($html);
        foreach ($labels as $key => $label) {
            if (stripos($plain, '[' . $label . ']') !== false) { $found = true; break; }
        }
        if ($found) {
            // Work on plain text for section splitting, return plain text sections
            $html = $plain;
        }
    }

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
            $content = trim(preg_replace(
                '/^(?:\s|&nbsp;|<br\s*\/?>|<\/?(?:p|div)[^>]*>)+|(?:\s|&nbsp;|<br\s*\/?>|<\/?(?:p|div)[^>]*>)+$/i',
                '', $m[1] ?? ''
            ));
            $out[$key] = $content;
        }
    }
    return $out;
}

/**
 * Extract all <img src="..."> URLs from an HTML string.
 */
function extractImageUrls(string $html): array {
    if (!$html) return [];
    preg_match_all('/<img\b[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $html, $m);
    return array_values(array_filter($m[1] ?? []));
}

// ── fetch project ─────────────────────────────────────────────────────────────
$projStmt = $db->prepare("SELECT title, project_type FROM projects WHERE id = ?");
$projStmt->execute([$projectId]);
$project = $projStmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { http_response_code(404); exit('Project not found'); }

$typeMap = ['web' => 'Website', 'app' => 'Mobile App', 'pdf' => 'PDF'];
$projectTypeLabel = $typeMap[strtolower($project['project_type'] ?? '')] ?? ($project['project_type'] ?? '');

// ── team members ──────────────────────────────────────────────────────────────
$teamStmt = $db->prepare("
    SELECT DISTINCT u.full_name, ua.role
    FROM user_assignments ua JOIN users u ON u.id = ua.user_id
    WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
    ORDER BY ua.role, u.full_name
");
$teamStmt->execute([$projectId]);
$teamMembers = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
$roleLabels = ['admin'=>'Admin','super_admin'=>'Super Admin','project_lead'=>'Project Lead',
               'qa'=>'QA','at_tester'=>'AT Tester','ft_tester'=>'FT Tester'];

// ── issues + metadata ─────────────────────────────────────────────────────────
$issueStmt = $db->prepare("
    SELECT i.id, i.title, i.description, i.severity, i.issue_key,
           s.name as status_name
    FROM issues i
    LEFT JOIN issue_statuses s ON s.id = i.status_id
    WHERE i.project_id = ?
    ORDER BY i.id ASC
");
$issueStmt->execute([$projectId]);
$allIssues = $issueStmt->fetchAll(PDO::FETCH_ASSOC);
$issueIds  = array_column($allIssues, 'id');

$metaMap       = [];
$pagesByIssue  = [];
$qaStatusByIssue = [];

if (!empty($issueIds)) {
    $ph = implode(',', array_fill(0, count($issueIds), '?'));

    // All metadata — each key stores array of raw DB values
    $ms = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($ph) ORDER BY id ASC");
    $ms->execute($issueIds);
    while ($m = $ms->fetch(PDO::FETCH_ASSOC)) {
        $metaMap[(int)$m['issue_id']][$m['meta_key']][] = $m['meta_value'];
    }

    // Pages per issue
    $ps = $db->prepare("SELECT ip.issue_id, pp.page_number, pp.page_name, pp.url FROM issue_pages ip JOIN project_pages pp ON ip.page_id = pp.id WHERE ip.issue_id IN ($ph)");
    $ps->execute($issueIds);
    while ($p = $ps->fetch(PDO::FETCH_ASSOC)) {
        $pagesByIssue[(int)$p['issue_id']][] = $p;
    }

    // QA statuses per issue
    foreach ($issueIds as $iid) {
        $meta = $metaMap[$iid] ?? [];
        $keys = [];
        if (!empty($meta['reporter_qa_status_map'])) {
            foreach ($meta['reporter_qa_status_map'] as $v) {
                $d = json_decode($v, true);
                if (is_array($d)) {
                    foreach ($d as $statuses) {
                        foreach ((array)$statuses as $sk) {
                            $sk = strtolower(trim((string)$sk));
                            if ($sk !== '') $keys[] = $sk;
                        }
                    }
                }
            }
        }
        if (empty($keys) && !empty($meta['qa_status'])) {
            foreach ($meta['qa_status'] as $v) {
                $d = json_decode($v, true);
                if (is_array($d)) { foreach ($d as $sk) $keys[] = strtolower(trim((string)$sk)); }
                else { $keys[] = strtolower(trim($v)); }
            }
        }
        $qaStatusByIssue[$iid] = array_values(array_unique(array_filter($keys)));
    }
}

// QA status labels
$qaStatusLabels = [];
try {
    $qsm = $db->query("SELECT status_key, status_label FROM qa_status_master WHERE is_active = 1");
    while ($qs = $qsm->fetch(PDO::FETCH_ASSOC)) {
        $qaStatusLabels[strtolower($qs['status_key'])] = strtolower($qs['status_label']);
    }
} catch (Exception $e) {}

// ── filter deleted/duplicate issues ──────────────────────────────────────────
$filteredIssues = [];
foreach ($allIssues as $iss) {
    $iid  = (int)$iss['id'];
    $skip = false;
    foreach ($qaStatusByIssue[$iid] ?? [] as $qk) {
        $normalized = strtolower(str_replace([' ', '-'], '_', $qk));
        $label = $qaStatusLabels[$qk] ?? $normalized;
        if (strpos($normalized, 'delete') !== false || strpos($normalized, 'duplicate') !== false
            || strpos($label, 'delete') !== false || strpos($label, 'duplicate') !== false) {
            $skip = true; break;
        }
    }
    if (!$skip) $filteredIssues[] = $iss;
}

// ── overview calculations ─────────────────────────────────────────────────────
$wcagLevelMap = [];
try {
    $wc = $db->query("SELECT criterion_number, level FROM wcag_criteria");
    while ($row = $wc->fetch(PDO::FETCH_ASSOC)) {
        $wcagLevelMap[trim($row['criterion_number'])] = strtoupper(trim($row['level']));
    }
} catch (Exception $e) {}

$failingSCsA = []; $failingSCsAA = [];
$userAffectedCounts = [];
$severityCounts = [];
$severityOrder = ['blocker'=>0,'critical'=>1,'major'=>2,'minor'=>3,'low'=>4];
$issuesWithMeta = [];

foreach ($filteredIssues as $iss) {
    $iid  = (int)$iss['id'];
    $meta = $metaMap[$iid] ?? [];

    // WCAG levels
    $scNums   = metaArray($meta, 'wcagsuccesscriteria');
    $scLevels = metaArray($meta, 'wcagsuccesscriterialevel');
    foreach ($scNums as $idx => $sc) {
        $sc = trim($sc); if ($sc === '') continue;
        $level = strtoupper(trim($scLevels[$idx] ?? ''));
        if ($level === '' && isset($wcagLevelMap[$sc])) $level = $wcagLevelMap[$sc];
        if ($level === 'A') $failingSCsA[$sc] = true;
        elseif ($level === 'AA') $failingSCsAA[$sc] = true;
    }

    // Users affected — each DB row = one user (may be comma-separated if multiple selected)
    foreach ($meta['usersaffected'] ?? [] as $rawVal) {
        // Split on comma in case multiple users stored in one row
        foreach (array_filter(array_map('trim', explode(',', $rawVal))) as $u) {
            if ($u !== '') $userAffectedCounts[$u] = ($userAffectedCounts[$u] ?? 0) + 1;
        }
    }

    // Severity
    $sev = ucfirst(strtolower(trim(metaFirst($meta, 'severity') ?: ($iss['severity'] ?? 'minor'))));
    $severityCounts[$sev] = ($severityCounts[$sev] ?? 0) + 1;

    $issuesWithMeta[] = [
        'title'    => $iss['title'],
        'sc_nums'  => $scNums,
        'sev_rank' => $severityOrder[strtolower(trim(metaFirst($meta, 'severity') ?: 'minor'))] ?? 99,
    ];
}

// Top 5 issues by severity (unique titles)
usort($issuesWithMeta, fn($a, $b) => $a['sev_rank'] - $b['sev_rank']);
$topIssues = []; $seenTitles = [];
foreach ($issuesWithMeta as $iss) {
    $tk = strtolower(trim($iss['title']));
    if (isset($seenTitles[$tk])) continue;
    $seenTitles[$tk] = true;
    $topIssues[] = ['title' => $iss['title'], 'sc_nums' => implode(', ', $iss['sc_nums'])];
    if (count($topIssues) >= 5) break;
}

// Severity counts sorted
arsort($userAffectedCounts);
$sevOrderKeys = ['Blocker','Critical','Major','Minor','Low'];
$severityCountsSorted = [];
foreach ($sevOrderKeys as $k) {
    if (isset($severityCounts[$k])) $severityCountsSorted[] = ['severity' => $k, 'count' => $severityCounts[$k]];
}
foreach ($severityCounts as $k => $v) {
    if (!in_array($k, $sevOrderKeys)) $severityCountsSorted[] = ['severity' => $k, 'count' => $v];
}

// ── project pages ─────────────────────────────────────────────────────────────
$pagesStmt = $db->prepare("
    SELECT id, page_number, page_name, url FROM project_pages WHERE project_id = ?
    ORDER BY CASE WHEN page_number LIKE 'Global%' THEN 0 WHEN page_number LIKE 'Page%' THEN 1 ELSE 2 END,
             CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(page_number,' ',-1),' ',1) AS UNSIGNED), page_number, id ASC
");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

// All grouped URLs (for All URLs sheet)
$guStmt = $db->prepare("SELECT url FROM grouped_urls WHERE project_id = ? ORDER BY id ASC");
$guStmt->execute([$projectId]);
$allGroupedUrls = $guStmt->fetchAll(PDO::FETCH_COLUMN);

// Grouped URLs per page (for URL Details sheet)
$guPerPageStmt = $db->prepare("SELECT url FROM grouped_urls WHERE project_id = ? AND unique_page_id = ? ORDER BY id ASC");

// ── open template ─────────────────────────────────────────────────────────────
$tmpFile = tempnam(sys_get_temp_dir(), 'pms_rpt_') . '.xlsx';
copy($templatePath, $tmpFile);
$zip = new ZipArchive();
if ($zip->open($tmpFile) !== true) { http_response_code(500); exit('Cannot open template'); }

// Fix sharedStrings count attributes to prevent Excel repair dialog
$ss = $zip->getFromName('xl/sharedStrings.xml');
if ($ss !== false) {
    // Remove count/uniqueCount so Excel recalculates — avoids mismatch after cell replacements
    $ss = preg_replace('/\s+count="\d+"/', '', $ss);
    $ss = preg_replace('/\s+uniqueCount="\d+"/', '', $ss);
    $zip->addFromString('xl/sharedStrings.xml', $ss);
}

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 1: Overview
// ══════════════════════════════════════════════════════════════════════════════
$sh1 = $zip->getFromName('xl/worksheets/sheet1.xml');

// Basic project info
setCell($sh1, 'F2', $project['title'] ?? '');
setCell($sh1, 'F3', $projectTypeLabel);
setCell($sh1, 'F4', 'Sakshi Infotech Solutions LLP');
setCell($sh1, 'F5', 'Sangam Nishad');
// F6: replace TODAY() formula with plain date string
setCell($sh1, 'F6', date('d-M'));

// F12/G12: WCAG failing SC counts
setCell($sh1, 'F12', (string)count($failingSCsA), true);
setCell($sh1, 'G12', (string)count($failingSCsAA), true);

// K12:K16 = top 5 issue titles (K:L merged), M12:M16 = SC numbers
for ($ti = 0; $ti < 5; $ti++) {
    $rn   = 12 + $ti;
    $tiss = $topIssues[$ti] ?? ['title' => '', 'sc_nums' => ''];
    setCell($sh1, 'K' . $rn, $tiss['title']);
    setCell($sh1, 'M' . $rn, $tiss['sc_nums']);
}

// B15: "Users Affected" header, C15: "Issue counts" header
setCell($sh1, 'B15', 'Users Affected');
setCell($sh1, 'C15', 'Issue counts');

// B16 onwards: users affected + counts
$ui = 0;
foreach ($userAffectedCounts as $user => $count) {
    $rn = 16 + $ui;
    injectCell($sh1, $rn, 'B' . $rn, (string)$user, ' s="26"');
    injectCell($sh1, $rn, 'C' . $rn, (string)$count, ' s="27"', true);
    $ui++;
}

// O23: "Severity" header, P23: "Issue Count" header
setCell($sh1, 'O23', 'Severity');
setCell($sh1, 'P23', 'Issue Count');

// O24 onwards: severity name + count
for ($si = 0; $si < count($severityCountsSorted); $si++) {
    $rn = 24 + $si;
    injectCell($sh1, $rn, 'O' . $rn, $severityCountsSorted[$si]['severity'], ' s="108"');
    injectCell($sh1, $rn, 'P' . $rn, (string)$severityCountsSorted[$si]['count'], ' s="109"', true);
}

// B29 onwards: team members (row 28 = header "Resource Name"/"Resource Type" — leave as-is)
for ($mi = 0; $mi < count($teamMembers); $mi++) {
    $rn   = 29 + $mi;
    $role = $roleLabels[$teamMembers[$mi]['role']] ?? ucfirst(str_replace('_', ' ', $teamMembers[$mi]['role']));
    injectCell($sh1, $rn, 'B' . $rn, $teamMembers[$mi]['full_name'], ' s="101"');
    injectCell($sh1, $rn, 'C' . $rn, $role, ' s="102"');
}

// Remove dimension element from sheet1 to prevent Excel repair dialog
$sh1 = preg_replace('/<dimension\s+ref="[^"]*"\s*\/>/s', '', $sh1);

$zip->addFromString('xl/worksheets/sheet1.xml', $sh1);
// Delete calcChain — stale chain prevents recalculation
$zip->deleteName('xl/calcChain.xml');

// Force full recalculation on open — Excel will recalculate ALL formulas
// across all sheets (including Conformance Score) when the file is opened
$wb = $zip->getFromName('xl/workbook.xml');
if ($wb !== false) {
    $wb = preg_replace('/<calcPr\b[^\/]*\/>/i', '<calcPr calcId="191029" calcMode="auto" fullCalcOnLoad="1"/>', $wb);
    if (strpos($wb, 'fullCalcOnLoad') === false) {
        $wb = str_replace('</workbook>', '<calcPr calcId="191029" calcMode="auto" fullCalcOnLoad="1"/></workbook>', $wb);
    }
    $zip->addFromString('xl/workbook.xml', $wb);
}

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 2: URL Details — A=Page No, B=Page Name, C=Unique URL, D=Grouped URLs
// ══════════════════════════════════════════════════════════════════════════════
$sh2 = $zip->getFromName('xl/worksheets/sheet2.xml');
clearDataRows($sh2);

$rows2 = '';
foreach ($projectPages as $idx => $page) {
    $rn = $idx + 2;
    $guPerPageStmt->execute([$projectId, $page['id']]);
    $grouped = $guPerPageStmt->fetchAll(PDO::FETCH_COLUMN);
    $rows2 .= '<row r="' . $rn . '" spans="1:4">'
        . xcell('A', $rn, $page['page_number'] ?? '')
        . xcell('B', $rn, $page['page_name'] ?? '')
        . xcell('C', $rn, $page['url'] ?? '')
        . xcell('D', $rn, implode(', ', $grouped))
        . '</row>';
}
injectRows($sh2, $rows2);
$zip->addFromString('xl/worksheets/sheet2.xml', $sh2);

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 3: All URLs — A=URL
// ══════════════════════════════════════════════════════════════════════════════
$sh3 = $zip->getFromName('xl/worksheets/sheet3.xml');
clearDataRows($sh3);

$rows3 = '';
foreach ($allGroupedUrls as $idx => $url) {
    $rn = $idx + 2;
    $rows3 .= '<row r="' . $rn . '" spans="1:1">' . xcell('A', $rn, (string)$url) . '</row>';
}
injectRows($sh3, $rows3);
$zip->addFromString('xl/worksheets/sheet3.xml', $sh3);

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 4: Final Report
// A=Sr.No  B=Issue Key  C=Page No  D=Page Name  E=Page URL
// F=Issue Title  G=Actual Result  H=Incorrect Code  I=Screenshots
// J=Recommendation  K=Correct Code  L=Severity  M=Priority
// N=User Affected  O=WCAG SC Number  P=WCAG SC Name  Q=WCAG Level
// R=GIGW 3.0  S=IS 17802  T=Environments  U=Developer's Status  V=Developer's Comment
// ══════════════════════════════════════════════════════════════════════════════
$sh4 = $zip->getFromName('xl/worksheets/sheet4.xml');
clearDataRows($sh4);

$rows4 = '';
$srNo  = 1;
foreach ($filteredIssues as $iss) {
    $iid   = (int)$iss['id'];
    $rn    = $srNo + 1;
    $meta  = $metaMap[$iid] ?? [];
    $pages = $pagesByIssue[$iid] ?? [];

    $pageNums  = implode(', ', array_column($pages, 'page_number'));
    $pageNames = implode(', ', array_column($pages, 'page_name'));
    $pageUrls  = implode(', ', array_filter(array_column($pages, 'url')));

    $wcagNums  = implode(', ', metaArray($meta, 'wcagsuccesscriteria'));
    $wcagNames = implode(', ', metaArray($meta, 'wcagsuccesscriterianame'));
    $wcagLevel = implode(', ', metaArray($meta, 'wcagsuccesscriterialevel'));
    $gigw      = implode(', ', metaArray($meta, 'gigw30'));
    $is17802   = implode(', ', metaArray($meta, 'is17802'));
    $severity  = ucfirst(strtolower(metaFirst($meta, 'severity') ?: ($iss['severity'] ?? 'minor')));
    $priority  = ucfirst(strtolower(metaFirst($meta, 'priority') ?: 'medium'));
    $envs      = implode(', ', metaArray($meta, 'environments'));
    $issueKey  = $iss['issue_key'] ?? metaFirst($meta, 'issue_key');

    // Users affected: split on comma since multiple users may be in one row
    $usersArr = [];
    foreach ($meta['usersaffected'] ?? [] as $rawVal) {
        foreach (array_filter(array_map('trim', explode(',', $rawVal))) as $u) {
            $usersArr[] = $u;
        }
    }
    $usersAff = implode(', ', array_unique($usersArr));

    // Extract named sections from description
    $sections       = extractSections($iss['description'] ?? '');
    $actualResult   = stripHtml($sections['actual_result']);
    $incorrectCode  = stripHtml($sections['incorrect_code']);
    $recommendation = stripHtml($sections['recommendation']);
    $correctCode    = stripHtml($sections['correct_code']);
    // Fallback: if no sections found, put full description in Actual Result
    if ($actualResult === '' && $incorrectCode === '' && $recommendation === '' && $correctCode === '') {
        $actualResult = stripHtml($iss['description'] ?? '');
    }
    $screenshotUrls = implode("\n", array_map('absoluteUrl', extractImageUrls($sections['screenshot'])));

    $rows4 .= '<row r="' . $rn . '" spans="1:22">'
        . '<c r="A' . $rn . '"><v>' . $srNo . '</v></c>'
        . xcell('B', $rn, $issueKey)
        . xcell('C', $rn, $pageNums)
        . xcell('D', $rn, $pageNames)
        . xcell('E', $rn, $pageUrls)
        . xcell('F', $rn, $iss['title'] ?? '')
        . xcell('G', $rn, $actualResult)
        . xcell('H', $rn, $incorrectCode)
        . xcell('I', $rn, $screenshotUrls)
        . xcell('J', $rn, $recommendation)
        . xcell('K', $rn, $correctCode)
        . xcell('L', $rn, $severity)
        . xcell('M', $rn, $priority)
        . xcell('N', $rn, $usersAff)
        . xcell('O', $rn, $wcagNums)
        . xcell('P', $rn, $wcagNames)
        . xcell('Q', $rn, $wcagLevel)
        . xcell('R', $rn, $gigw)
        . xcell('S', $rn, $is17802)
        . xcell('T', $rn, $envs)
        . xcell('U', $rn, '')
        . xcell('V', $rn, '')
        . '</row>';
    $srNo++;
}
injectRows($sh4, $rows4);
$zip->addFromString('xl/worksheets/sheet4.xml', $sh4);

// ── stream ────────────────────────────────────────────────────────────────────
$zip->close();

// Validate all modified sheets before streaming
$validateZip = new ZipArchive();
$validateZip->open($tmpFile);
$sheetNames = ['xl/worksheets/sheet1.xml','xl/worksheets/sheet2.xml','xl/worksheets/sheet3.xml','xl/worksheets/sheet4.xml'];
foreach ($sheetNames as $sn) {
    $content = $validateZip->getFromName($sn);
    if ($content === false) continue;
    libxml_use_internal_errors(true);
    simplexml_load_string($content);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();
    if (!empty($xmlErrors)) {
        // Log first error to PHP error log for debugging
        error_log("export_client_report: XML error in $sn: " . trim($xmlErrors[0]->message) . " at line " . $xmlErrors[0]->line);
    }
}
$validateZip->close();
$safeTitle = trim(preg_replace('/[^a-zA-Z0-9_\- ]/', '', $project['title'] ?? 'Project')) ?: 'Project';
$filename  = $safeTitle . ' - Accessibility Audit Report.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: private, no-cache');
readfile($tmpFile);
unlink($tmpFile);
exit;
