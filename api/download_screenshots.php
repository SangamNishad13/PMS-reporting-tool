<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'client']);

$db = Database::getInstance();
$projectId = (int)($_GET['project_id'] ?? 0);
$pageId = (int)($_GET['page_id'] ?? 0);
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

if (!$projectId) {
    die("Project ID is required");
}

// IDOR fix: verify user has access to this project
if (!hasProjectAccess($db, $userId, $projectId)) {
    die("Access denied");
}

// Get project details for filename
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    die("Project not found");
}

// Fetch issues
$query = "SELECT i.id, i.issue_key, i.description FROM issues i WHERE i.project_id = ?";
$params = [$projectId];

if ($pageId) {
    // Check if page belongs to project
    $pageStmt = $db->prepare("SELECT id FROM project_pages WHERE id = ? AND project_id = ?");
    $pageStmt->execute([$pageId, $projectId]);
    if (!$pageStmt->fetch()) {
        die("Invalid Page ID");
    }
    
    // Use issue_pages as authoritative source (always wiped+re-inserted on save).
    // Fall back to i.page_id for legacy issues that predate the issue_pages table.
    $query .= " AND (
        EXISTS (SELECT 1 FROM issue_pages ip WHERE ip.issue_id = i.id AND ip.page_id = ?)
        OR (
            i.page_id = ?
            AND NOT EXISTS (SELECT 1 FROM issue_pages ip2 WHERE ip2.issue_id = i.id)
        )
    )";
    $params = array_merge($params, [$pageId, $pageId]);
}

// Filter for clients
if ($userRole === 'client') {
    $query .= " AND i.client_ready = 1";
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($issues)) {
    die("No issues found with screenshots for this selection.");
}

// Prepare ZIP
$zip = new ZipArchive();
$tempFile = tempnam(sys_get_temp_dir(), 'screenshots_zip');
if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Cannot create ZIP file");
}

$addedFiles = 0;
$baseUrl = getBaseDir(); // e.g. /PMS
$docRoot = realpath(__DIR__ . '/..');

foreach ($issues as $issue) {
    $issueKey = $issue['issue_key'] ?: 'ISSUE-' . $issue['id'];
    $htmlContent = $issue['description'];
    
    // Fetch comments for internal screenshots too
    $commentStmt = $db->prepare("SELECT comment_html FROM issue_comments WHERE issue_id = ?");
    $commentStmt->execute([$issue['id']]);
    while ($comment = $commentStmt->fetch(PDO::FETCH_ASSOC)) {
        $htmlContent .= ' ' . $comment['comment_html'];
    }

    if (empty($htmlContent)) continue;

    // Extract images
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $images = $dom->getElementsByTagName('img');
    
    $counter = 1;
    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        if (empty($src)) continue;

        // Strip base URL if present to get relative path
        if ($baseUrl && strpos($src, $baseUrl) === 0) {
            $relPath = substr($src, strlen($baseUrl));
        } else {
            $relPath = $src;
        }
        
        // Clean leading slash
        $relPath = ltrim($relPath, '/');
        
        // Check if it's an internal upload
        if (strpos($relPath, 'uploads/issues/') === 0 || strpos($relPath, 'uploads/chat/') === 0) {
            $absolutePath = $docRoot . '/' . $relPath;
            
            if (file_exists($absolutePath) && is_file($absolutePath)) {
                $ext = pathinfo($absolutePath, PATHINFO_EXTENSION);
                $newName = $issueKey . '-' . $counter . '.' . $ext;
                
                // Add to ZIP
                if ($zip->addFile($absolutePath, $newName)) {
                    $counter++;
                    $addedFiles++;
                }
            }
        }
    }
}

$zip->close();

if ($addedFiles === 0) {
    unlink($tempFile);
    ob_end_clean();
    die("No screenshots found to download.");
}

// Serve ZIP
$zipName = 'Screenshots_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['title']) . ($pageId ? '_Page_' . $pageId : '') . '_' . date('Ymd_His') . '.zip';

// Discard any buffered output (warnings/notices) that would corrupt the ZIP
ob_end_clean();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tempFile));
header('Pragma: no-cache');
header('Expires: 0');

readfile($tempFile);
unlink($tempFile);
exit;
