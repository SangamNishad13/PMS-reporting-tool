<?php
/**
 * Debug script for Final Report sheet blank issue - Project 34
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

echo "<h2>Debug: Export Final Report - Project $projectId</h2>";
echo "<style>body{font-family:monospace;padding:20px} table{border-collapse:collapse} td,th{border:1px solid #ccc;padding:4px 8px;font-size:12px}</style>";

// 1. Check issues count
$issueStmt = $db->prepare("SELECT COUNT(*) FROM issues WHERE project_id = ?");
$issueStmt->execute([$projectId]);
$totalIssues = $issueStmt->fetchColumn();
echo "<p><strong>Total issues in DB:</strong> $totalIssues</p>";

// 2. Fetch all issues
$issueStmt = $db->prepare("SELECT i.id, i.title, i.severity, i.issue_key, s.name as status_name FROM issues i LEFT JOIN issue_statuses s ON s.id = i.status_id WHERE i.project_id = ? ORDER BY i.id ASC");
$issueStmt->execute([$projectId]);
$allIssues = $issueStmt->fetchAll(PDO::FETCH_ASSOC);
echo "<p><strong>Fetched issues:</strong> " . count($allIssues) . "</p>";

if (empty($allIssues)) {
    echo "<p style='color:red'>NO ISSUES FOUND for project $projectId</p>";
    exit;
}

$issueIds = array_column($allIssues, 'id');
$ph = implode(',', array_fill(0, count($issueIds), '?'));

// 3. Fetch metadata
$metaMap = [];
$ms = $db->prepare("SELECT issue_id, meta_key, meta_value FROM issue_metadata WHERE issue_id IN ($ph) ORDER BY id ASC");
$ms->execute($issueIds);
while ($m = $ms->fetch(PDO::FETCH_ASSOC)) {
    $metaMap[(int)$m['issue_id']][$m['meta_key']][] = $m['meta_value'];
}

// 4. Fetch QA statuses
$qaStatusByIssue = [];
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

// 5. QA status labels
$qaStatusLabels = [];
try {
    $qsm = $db->query("SELECT status_key, status_label FROM qa_status_master WHERE is_active = 1");
    while ($qs = $qsm->fetch(PDO::FETCH_ASSOC)) {
        $qaStatusLabels[strtolower($qs['status_key'])] = strtolower($qs['status_label']);
    }
    echo "<p><strong>QA Status Labels:</strong> " . json_encode($qaStatusLabels) . "</p>";
} catch (Exception $e) {
    echo "<p style='color:orange'>qa_status_master table error: " . $e->getMessage() . "</p>";
}

// 6. shouldSkipIssueForExport logic
function shouldSkipDebug(array $qaStatusKeys, array $qaStatusLabels): array {
    if (empty($qaStatusKeys)) return ['skip' => false, 'reason' => 'no qa status keys'];
    $deleteOrDuplicateCount = 0;
    $meaningfulStatusCount = 0;
    $details = [];
    foreach ($qaStatusKeys as $qk) {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim((string) $qk)));
        $label = strtolower(trim((string) ($qaStatusLabels[strtolower(trim((string) $qk))] ?? $normalized)));
        if ($normalized === '' && $label === '') continue;
        $meaningfulStatusCount++;
        $isDelDup = strpos($normalized, 'delete') !== false || strpos($normalized, 'duplicate') !== false
            || strpos($label, 'delete') !== false || strpos($label, 'duplicate') !== false;
        if ($isDelDup) $deleteOrDuplicateCount++;
        $details[] = "key=$qk normalized=$normalized label=$label isDelDup=" . ($isDelDup ? 'YES' : 'no');
    }
    $skip = $meaningfulStatusCount > 0 && $deleteOrDuplicateCount === $meaningfulStatusCount;
    return ['skip' => $skip, 'meaningful' => $meaningfulStatusCount, 'deldup' => $deleteOrDuplicateCount, 'details' => $details];
}

// 7. Show per-issue analysis
$skippedCount = 0;
$includedCount = 0;

echo "<table><tr><th>ID</th><th>Title</th><th>QA Status Keys</th><th>Skip?</th><th>Reason</th></tr>";
foreach ($allIssues as $iss) {
    $iid = (int)$iss['id'];
    $qaKeys = $qaStatusByIssue[$iid] ?? [];
    $result = shouldSkipDebug($qaKeys, $qaStatusLabels);
    if ($result['skip']) $skippedCount++; else $includedCount++;
    $color = $result['skip'] ? 'background:#ffe0e0' : '';
    echo "<tr style='$color'>";
    echo "<td>{$iid}</td>";
    echo "<td>" . htmlspecialchars(substr($iss['title'] ?? '', 0, 50)) . "</td>";
    echo "<td>" . htmlspecialchars(implode(', ', $qaKeys)) . "</td>";
    echo "<td>" . ($result['skip'] ? '<strong style="color:red">SKIP</strong>' : 'include') . "</td>";
    echo "<td style='font-size:11px'>" . htmlspecialchars(implode(' | ', $result['details'] ?? [$result['reason'] ?? ''])) . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<br><p><strong>Summary:</strong> Included: $includedCount | Skipped: $skippedCount</p>";

// 8. Check filteredIssues fallback
if ($includedCount === 0 && count($allIssues) > 0) {
    echo "<p style='color:orange'><strong>All issues skipped! Fallback will use allIssues (" . count($allIssues) . " issues)</strong></p>";
    echo "<p>So filteredIssues = allIssues = " . count($allIssues) . " issues - data SHOULD appear in sheet.</p>";
}

// 9. Check if issue_metadata table has data
$metaCount = $db->prepare("SELECT COUNT(*) FROM issue_metadata WHERE issue_id IN ($ph)");
$metaCount->execute($issueIds);
echo "<p><strong>Total metadata rows for these issues:</strong> " . $metaCount->fetchColumn() . "</p>";

// 10. Check regression_rounds table
try {
    $rrCheck = $db->prepare("SELECT COUNT(*) FROM regression_rounds WHERE project_id = ?");
    $rrCheck->execute([$projectId]);
    echo "<p><strong>Regression rounds:</strong> " . $rrCheck->fetchColumn() . "</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>regression_rounds table error: " . $e->getMessage() . "</p>";
}

// 11. Check regression_round_issue_versions table
try {
    $rrivCheck = $db->query("SELECT COUNT(*) FROM regression_round_issue_versions LIMIT 1");
    echo "<p><strong>regression_round_issue_versions table:</strong> exists</p>";
} catch (Exception $e) {
    echo "<p style='color:red'><strong>regression_round_issue_versions table MISSING:</strong> " . $e->getMessage() . "</p>";
}

echo "<hr><p style='color:gray'>Delete this file after debugging: debug_export_sheet4.php</p>";
