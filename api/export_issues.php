<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'super_admin']);

$projectId = (int)($_POST['project_id'] ?? 0);
$format = $_POST['format'] ?? 'excel';
$selectedColumns = $_POST['columns'] ?? [];
$selectedPages = $_POST['pages'] ?? ['all'];
$selectedStatuses = $_POST['status'] ?? ['all'];
$imageHandling = $_POST['image_handling'] ?? 'links'; // links, embed, none

if (!$projectId || empty($selectedColumns)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$db = Database::getInstance();

// Get project details
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Project not found']);
    exit;
}

// Build query to fetch issues
$query = "
    SELECT 
        i.id,
        i.issue_key,
        i.title,
        i.description,
        i.status_id,
        ist.name as status,
        i.created_at,
        i.updated_at,
        i.reporter_id,
        reporter.full_name as reporter_name,
        GROUP_CONCAT(DISTINCT pp.page_name ORDER BY pp.page_name SEPARATOR ', ') as pages,
        GROUP_CONCAT(DISTINCT pp.page_number ORDER BY pp.page_number SEPARATOR ', ') as page_numbers
    FROM issues i
    LEFT JOIN issue_statuses ist ON i.status_id = ist.id
    LEFT JOIN issue_pages ip ON i.id = ip.issue_id
    LEFT JOIN project_pages pp ON ip.page_id = pp.id
    LEFT JOIN users reporter ON i.reporter_id = reporter.id
    WHERE i.project_id = ?
";

$params = [$projectId];

// Add page filter
if (!in_array('all', $selectedPages)) {
    $placeholders = str_repeat('?,', count($selectedPages) - 1) . '?';
    $query .= " AND ip.page_id IN ($placeholders)";
    $params = array_merge($params, $selectedPages);
}

// Add status filter
if (!in_array('all', $selectedStatuses)) {
    $statusMap = [
        'open' => 1,
        'in_progress' => 2,
        'resolved' => 3,
        'closed' => 4
    ];
    $statusIds = array_map(function($s) use ($statusMap) {
        return $statusMap[$s] ?? 1;
    }, $selectedStatuses);
    $placeholders = str_repeat('?,', count($statusIds) - 1) . '?';
    $query .= " AND i.status_id IN ($placeholders)";
    $params = array_merge($params, $statusIds);
}

$query .= " GROUP BY i.id ORDER BY i.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch metadata for each issue
foreach ($issues as &$issue) {
    $metaStmt = $db->prepare("SELECT meta_key, meta_value FROM issue_metadata WHERE issue_id = ?");
    $metaStmt->execute([$issue['id']]);
    $metadata = $metaStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $issue['metadata'] = $metadata;
    
    // If pages are empty from query, try to get from metadata
    if (empty($issue['pages']) && isset($metadata['page_ids'])) {
        $pageIds = json_decode($metadata['page_ids'], true);
        if (!is_array($pageIds)) {
            $pageIds = array_filter(array_map('trim', explode(',', $metadata['page_ids'])));
        }
        $pageIds = array_values(array_filter(array_map('intval', $pageIds)));
        
        if (!empty($pageIds)) {
            try {
                $placeholders = str_repeat('?,', count($pageIds) - 1) . '?';
                $pageStmt = $db->prepare("SELECT page_name, page_number FROM project_pages WHERE id IN ($placeholders)");
                $pageStmt->execute($pageIds);
                $pageData = $pageStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $pageNames = array_column($pageData, 'page_name');
                $pageNumbers = array_column($pageData, 'page_number');
                
                $issue['pages'] = implode(', ', $pageNames);
                $issue['page_numbers'] = implode(', ', $pageNumbers);
            } catch (Exception $e) {
                // Silently handle error
            }
        }
    }
    
    // Fetch grouped URLs from metadata
    $groupedUrlsList = [];
    if (isset($metadata['grouped_urls'])) {
        $urlData = $metadata['grouped_urls'];
        
        // Check if it's already a URL string (not IDs)
        if (filter_var($urlData, FILTER_VALIDATE_URL) || strpos($urlData, 'http') === 0) {
            // It's a direct URL or comma-separated URLs
            $groupedUrlsList = array_filter(array_map('trim', explode(',', $urlData)));
        } else {
            // Try to decode as JSON (IDs)
            $urlIds = json_decode($urlData, true);
            
            // If not valid JSON, try comma-separated
            if (!is_array($urlIds)) {
                $urlIds = array_filter(array_map('trim', explode(',', $urlData)));
            }
            
            // Convert string IDs to integers and filter out empty values
            $urlIds = array_values(array_filter(array_map('intval', $urlIds)));
            
            if (!empty($urlIds)) {
                try {
                    $placeholders = str_repeat('?,', count($urlIds) - 1) . '?';
                    $urlStmt = $db->prepare("SELECT url FROM grouped_urls WHERE id IN ($placeholders)");
                    $urlStmt->execute($urlIds);
                    $groupedUrlsList = $urlStmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {
                    // Silently handle error
                }
            }
        }
    }
    $issue['grouped_urls'] = !empty($groupedUrlsList) ? implode(', ', $groupedUrlsList) : '';
    
    // Get common issue title from metadata
    $issue['common_title'] = $metadata['common_title'] ?? '';
    
    // Fetch all reporters (primary + additional from metadata)
    $allReporters = [];
    
    // Add primary reporter if exists
    if (!empty($issue['reporter_name'])) {
        $allReporters[] = $issue['reporter_name'];
    }
    
    // Add additional reporters from metadata if exists
    if (isset($metadata['reporter_ids'])) {
        $reporterIds = json_decode($metadata['reporter_ids'], true);
        if (!is_array($reporterIds)) {
            // Try comma-separated format
            $reporterIds = array_filter(array_map('trim', explode(',', $metadata['reporter_ids'])));
        }
        
        if (is_array($reporterIds) && !empty($reporterIds)) {
            $placeholders = str_repeat('?,', count($reporterIds) - 1) . '?';
            $reporterStmt = $db->prepare("SELECT full_name FROM users WHERE id IN ($placeholders)");
            $reporterStmt->execute($reporterIds);
            $additionalReporters = $reporterStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($additionalReporters as $reporter) {
                if (!in_array($reporter, $allReporters)) {
                    $allReporters[] = $reporter;
                }
            }
        }
    }
    
    // Combine all reporters
    $issue['all_reporters'] = implode(', ', array_filter($allReporters));
    
    // Fetch QA status from metadata
    $qaStatusLabels = [];
    if (isset($metadata['qa_status'])) {
        $qaStatusKeys = json_decode($metadata['qa_status'], true);
        if (!is_array($qaStatusKeys)) {
            // Try comma-separated format
            $qaStatusKeys = array_filter(array_map('trim', explode(',', $metadata['qa_status'])));
        }
        
        if (is_array($qaStatusKeys) && !empty($qaStatusKeys)) {
            $placeholders = str_repeat('?,', count($qaStatusKeys) - 1) . '?';
            $qaStmt = $db->prepare("SELECT status_label FROM qa_status_master WHERE status_key IN ($placeholders)");
            $qaStmt->execute($qaStatusKeys);
            $qaStatusLabels = $qaStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    $issue['qa_status'] = implode(', ', $qaStatusLabels);
    
    // Extract template sections from description field
    $issue['sections'] = extractTemplateSections($issue['description'] ?? '');
}

if ($format === 'excel') {
    exportToExcel($issues, $selectedColumns, $project, $imageHandling);
} else {
    exportToPDF($issues, $selectedColumns, $project, $imageHandling);
}

function extractTemplateSections($html) {
    $sections = [];
    
    // Common section patterns to look for
    $sectionPatterns = [
        'actual_result' => '/\[Actual Result\](.*?)(?=\[|$)/is',
        'incorrect_code' => '/\[Incorrect Code\](.*?)(?=\[|$)/is',
        'screenshot' => '/\[Screenshot\](.*?)(?=\[|$)/is',
        'recommendation' => '/\[Recommendation\](.*?)(?=\[|$)/is',
        'correct_code' => '/\[Correct Code\](.*?)(?=\[|$)/is',
        'expected_result' => '/\[Expected Result\](.*?)(?=\[|$)/is',
        'steps_to_reproduce' => '/\[Steps to Reproduce\](.*?)(?=\[|$)/is',
        'impact' => '/\[Impact\](.*?)(?=\[|$)/is',
        'notes' => '/\[Notes\](.*?)(?=\[|$)/is',
    ];
    
    foreach ($sectionPatterns as $key => $pattern) {
        if (preg_match($pattern, $html, $matches)) {
            // Extract content - keep HTML for now (will be processed later)
            $content = trim($matches[1]);
            $sections[$key] = $content;
        } else {
            $sections[$key] = '';
        }
    }
    
    return $sections;
}

function exportToExcel($issues, $columns, $project, $imageHandling) {
    // Set headers for CSV download (Excel can open CSV files)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="issues_' . sanitizeFilename($project['title']) . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    // Write UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header row
    $headers = [];
    foreach ($columns as $col) {
        if (strpos($col, 'section_') === 0) {
            // Convert section_actual_result to "Actual Result"
            $sectionName = str_replace('section_', '', $col);
            $sectionName = str_replace('_', ' ', $sectionName);
            $headers[] = '[' . ucwords($sectionName) . ']';
        } else {
            $headers[] = ucwords(str_replace('_', ' ', $col));
        }
    }
    fputcsv($output, $headers);
    
    // Write data rows
    foreach ($issues as $issue) {
        $row = [];
        foreach ($columns as $col) {
            if ($col === 'description') {
                // Handle description with formatting preserved
                $description = $issue[$col] ?? '';
                // First process images based on handling option
                $description = processImagesInContent($description, $imageHandling);
                // Then convert HTML to formatted text
                $description = convertHtmlToFormattedText($description);
                $row[] = $description;
            } elseif ($col === 'common_title') {
                // Handle common issue title from metadata
                $row[] = $issue['common_title'] ?? '';
            } elseif ($col === 'reporter_name') {
                // Use all_reporters which includes primary + additional reporters
                $row[] = $issue['all_reporters'] ?? '';
            } elseif (strpos($col, 'section_') === 0) {
                // Handle template sections
                $sectionKey = str_replace('section_', '', $col);
                $sectionContent = $issue['sections'][$sectionKey] ?? '';
                // Process images in section content
                $sectionContent = processImagesInContent($sectionContent, $imageHandling);
                // Convert HTML to formatted text
                $sectionContent = convertHtmlToFormattedText($sectionContent);
                $row[] = $sectionContent;
            } elseif (isset($issue['metadata'][$col])) {
                $value = $issue['metadata'][$col];
                $row[] = is_array($value) ? implode(', ', $value) : $value;
            } else {
                $row[] = $issue[$col] ?? '';
            }
        }
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

function exportToPDF($issues, $columns, $project, $imageHandling) {
    // Create a formatted document-style PDF (HTML for print)
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Issues Export - ' . htmlspecialchars($project['title']) . '</title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: Arial, sans-serif; 
            font-size: 13px; 
            margin: 15px; 
            line-height: 1.6;
            max-width: 100%;
        }
        .header { 
            border-bottom: 3px solid #0d6efd; 
            padding-bottom: 15px; 
            margin-bottom: 30px;
            max-width: 100%;
        }
        .header h1 { 
            color: #333; 
            margin: 0; 
            font-size: 26px;
            word-wrap: break-word;
        }
        .meta-info { 
            color: #666; 
            font-size: 12px; 
            margin-top: 10px;
        }
        
        .issue-container { 
            margin-bottom: 40px; 
            page-break-inside: avoid;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            background: #f8f9fa;
            max-width: 100%;
            overflow: hidden;
        }
        
        .issue-title { 
            font-size: 20px; 
            font-weight: bold; 
            color: #0d6efd; 
            margin-bottom: 15px;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 8px;
            word-wrap: break-word;
        }
        
        .fields-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px 20px;
            margin-bottom: 20px;
        }
        
        .issue-field { 
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .field-label { 
            font-weight: bold; 
            color: #495057;
            display: block;
            margin-bottom: 4px;
            font-size: 12px;
        }
        
        .field-value { 
            color: #212529;
            display: block;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-size: 13px;
        }
        
        .field-full-width {
            grid-column: 1 / -1;
        }
        
        .section-container {
            margin-top: 20px;
            padding: 15px;
            background: white;
            border-left: 4px solid #0d6efd;
            margin-bottom: 15px;
            max-width: 100%;
            overflow: hidden;
        }
        
        .section-title {
            font-weight: bold;
            color: #0d6efd;
            font-size: 15px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        
        .section-content {
            color: #212529;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
            font-size: 13px;
            line-height: 1.7;
        }
        
        img { 
            max-width: 100%; 
            height: auto;
            margin: 10px 0;
            border: 1px solid #ddd;
            padding: 5px;
            background: white;
        }
        
        .url-list {
            list-style: none;
            padding-left: 0;
            margin: 5px 0;
        }
        
        .url-list li {
            padding: 4px 0;
            color: #0d6efd;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        @media print {
            body { margin: 10mm; }
            button { display: none; }
            .issue-container { page-break-inside: avoid; }
        }
        
        @page {
            margin: 15mm;
            size: A4;
        }
    </style>
</head>
<body>
    <button onclick="window.print()" style="padding: 10px 20px; background: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer; margin-bottom: 20px;">
        Print / Save as PDF
    </button>
    
    <div class="header">
        <h1>Issues Export - ' . htmlspecialchars($project['title']) . '</h1>
        <div class="meta-info">
            <strong>Export Date:</strong> ' . date('Y-m-d H:i:s') . ' | 
            <strong>Total Issues:</strong> ' . count($issues) . '
        </div>
    </div>';
    
    $issueNumber = 1;
    foreach ($issues as $issue) {
        echo '<div class="issue-container">';
        echo '<div class="issue-title">Issue #' . $issueNumber . ': ' . htmlspecialchars($issue['title'] ?? 'Untitled') . '</div>';
        
        // Display description first if selected
        if (in_array('description', $columns) && !empty($issue['description'])) {
            echo '<div class="section-container">';
            echo '<div class="section-title">ISSUE DESCRIPTION</div>';
            echo '<div class="section-content">';
            if ($imageHandling === 'embed') {
                echo processImagesForPDF($issue['description']);
            } else {
                echo nl2br(htmlspecialchars(convertHtmlToFormattedText($issue['description'])));
            }
            echo '</div>';
            echo '</div>';
        }
        
        // Display basic fields in 2-column grid
        echo '<div class="fields-grid">';
        
        foreach ($columns as $col) {
            // Skip sections, description and title - handled separately
            if (strpos($col, 'section_') === 0 || $col === 'description' || $col === 'title') {
                continue;
            }
            
            $label = ucwords(str_replace('_', ' ', $col));
            $value = '';
            $isFullWidth = false;
            
            if ($col === 'common_title') {
                $value = $issue['common_title'] ?? '';
            } elseif ($col === 'reporter_name') {
                $value = $issue['all_reporters'] ?? '';
            } elseif ($col === 'grouped_urls') {
                // Special handling for URLs - full width
                $urlsString = $issue['grouped_urls'] ?? $issue[$col] ?? '';
                $urls = array_filter(explode(', ', $urlsString));
                
                if (!empty($urls)) {
                    echo '<div class="issue-field field-full-width">';
                    echo '<span class="field-label">' . htmlspecialchars($label) . '</span>';
                    echo '<ul class="url-list">';
                    foreach ($urls as $url) {
                        $url = trim($url);
                        if (!empty($url)) {
                            echo '<li>' . htmlspecialchars($url) . '</li>';
                        }
                    }
                    echo '</ul>';
                    echo '</div>';
                }
                continue;
            } elseif (isset($issue['metadata'][$col])) {
                $metaValue = $issue['metadata'][$col];
                $value = is_array($metaValue) ? implode(', ', $metaValue) : $metaValue;
                
                // Check if value is long - make it full width
                if (strlen($value) > 80) {
                    $isFullWidth = true;
                }
            } else {
                $value = $issue[$col] ?? '';
            }
            
            if (!empty($value)) {
                $fieldClass = $isFullWidth ? 'issue-field field-full-width' : 'issue-field';
                echo '<div class="' . $fieldClass . '">';
                echo '<span class="field-label">' . htmlspecialchars($label) . '</span>';
                echo '<span class="field-value">' . htmlspecialchars($value) . '</span>';
                echo '</div>';
            }
        }
        
        echo '</div>'; // End fields-grid
        
        // Display template sections
        foreach ($columns as $col) {
            if (strpos($col, 'section_') === 0) {
                $sectionKey = str_replace('section_', '', $col);
                $sectionName = str_replace('_', ' ', $sectionKey);
                $sectionContent = $issue['sections'][$sectionKey] ?? '';
                
                if (!empty($sectionContent)) {
                    echo '<div class="section-container">';
                    echo '<div class="section-title">[' . htmlspecialchars(ucwords($sectionName)) . ']</div>';
                    echo '<div class="section-content">';
                    
                    if ($imageHandling === 'embed') {
                        // Keep images in HTML
                        echo processImagesForPDF($sectionContent);
                    } elseif ($imageHandling === 'links') {
                        // Convert images to links
                        echo nl2br(htmlspecialchars(processImagesInContent($sectionContent, 'links')));
                    } else {
                        // Remove images
                        echo nl2br(htmlspecialchars(strip_tags($sectionContent)));
                    }
                    
                    echo '</div>';
                    echo '</div>';
                }
            }
        }
        
        echo '</div>'; // End issue-container
        $issueNumber++;
    }
    
    echo '</body>
</html>';
    exit;
}

function sanitizeFilename($filename) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
}

function convertHtmlToFormattedText($html) {
    // Convert HTML to formatted plain text while preserving structure
    
    // First, normalize line breaks
    $text = str_replace(["\r\n", "\r"], "\n", $html);
    
    // Convert <br> tags to newlines
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    
    // Convert closing paragraph tags to double newlines
    $text = preg_replace('/<\/p>/i', "\n\n", $text);
    
    // Remove opening paragraph tags
    $text = preg_replace('/<p[^>]*>/i', '', $text);
    
    // Convert div closing tags to newlines
    $text = preg_replace('/<\/div>/i', "\n", $text);
    $text = preg_replace('/<div[^>]*>/i', '', $text);
    
    // Convert headings to text with extra spacing
    $text = preg_replace('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', "\n\n$1\n\n", $text);
    
    // Convert lists - add newline before and after list items
    $text = preg_replace('/<li[^>]*>/i', "\n• ", $text);
    $text = preg_replace('/<\/li>/i', "", $text);
    $text = preg_replace('/<\/(ul|ol)>/i', "\n", $text);
    $text = preg_replace('/<(ul|ol)[^>]*>/i', "\n", $text);
    
    // Convert bold/strong - keep as is or add markers
    $text = preg_replace('/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/is', '**$2**', $text);
    
    // Convert italic/em
    $text = preg_replace('/<(em|i)[^>]*>(.*?)<\/(em|i)>/is', '*$2*', $text);
    
    // Remove all remaining HTML tags
    $text = strip_tags($text);
    
    // Decode HTML entities
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Clean up excessive whitespace on same line
    $text = preg_replace('/[ \t]+/', ' ', $text);
    
    // Remove spaces at start/end of lines
    $text = preg_replace('/^[ \t]+/m', '', $text);
    $text = preg_replace('/[ \t]+$/m', '', $text);
    
    // Reduce multiple consecutive newlines to maximum 2
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    
    // Trim overall
    return trim($text);
}

function processImagesInContent($html, $imageHandling) {
    if ($imageHandling === 'none') {
        // Remove all images
        return strip_tags($html);
    } elseif ($imageHandling === 'links') {
        // Convert images to just URLs (one per line)
        $html = preg_replace_callback('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', function($matches) {
            $src = $matches[1];
            
            // If relative path, convert to full URL
            if (strpos($src, 'http') !== 0) {
                // Get base URL from server
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                
                // Remove leading slash if present in src
                $src = ltrim($src, '/');
                $fullUrl = $protocol . '://' . $host . '/' . $src;
            } else {
                $fullUrl = $src;
            }
            
            return $fullUrl . "\n";
        }, $html);
        return strip_tags($html);
    }
    // For 'embed', return as is (will be handled differently in PDF)
    return strip_tags($html);
}

function processImagesForPDF($html) {
    // For PDF, keep images but ensure they have proper attributes
    $html = preg_replace_callback('/<img([^>]+)>/i', function($matches) {
        $attrs = $matches[1];
        // Ensure images have max-width for PDF
        if (strpos($attrs, 'style=') === false) {
            $attrs .= ' style="max-width: 200px; max-height: 150px;"';
        }
        return '<img' . $attrs . '>';
    }, $html);
    return $html;
}
