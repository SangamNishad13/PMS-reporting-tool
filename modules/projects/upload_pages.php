<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

header('Content-Type: application/json; charset=utf-8');
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$db = Database::getInstance();

$projectId = (int)($_POST['project_id'] ?? 0);
if (!$projectId) {
    echo json_encode(['error' => 'project_id required']);
    exit;
}

if (!hasProjectAccess($db, $userId, $projectId)) {
    echo json_encode(['error' => 'Permission denied for this project']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

$tmp = $_FILES['file']['tmp_name'];
if (!is_uploaded_file($tmp)) {
    echo json_encode(['error' => 'Invalid upload']);
    exit;
}

$uniqueCols = [];
$allCols = [];
$pageNumberCol = null;
$pageNameCol = null;
$screenNameCol = null;
$notesCol = null;
$groupedUrlsCol = null;

// New simplified column mapping
if (isset($_POST['unique_url_col']) && $_POST['unique_url_col'] !== '') {
    $uniqueCols = [(int)$_POST['unique_url_col']];
}
if (isset($_POST['page_number_col']) && $_POST['page_number_col'] !== '') {
    $pageNumberCol = (int)$_POST['page_number_col'];
}
if (isset($_POST['page_name_col']) && $_POST['page_name_col'] !== '') {
    $pageNameCol = (int)$_POST['page_name_col'];
}
if (isset($_POST['screen_name_col']) && $_POST['screen_name_col'] !== '') {
    $screenNameCol = (int)$_POST['screen_name_col'];
}
if (isset($_POST['notes_col']) && $_POST['notes_col'] !== '') {
    $notesCol = (int)$_POST['notes_col'];
}
if (isset($_POST['grouped_urls_col']) && $_POST['grouped_urls_col'] !== '') {
    $groupedUrlsCol = (int)$_POST['grouped_urls_col'];
}

// Backward compatibility: support old format
if (empty($uniqueCols)) {
    if (!empty($_POST['unique_cols']) && is_array($_POST['unique_cols'])) {
        $uniqueCols = array_map('intval', $_POST['unique_cols']);
    } elseif (isset($_POST['unique_col']) && $_POST['unique_col'] !== '') {
        $uniqueCols = [(int)$_POST['unique_col']];
    }
}
if (!empty($_POST['all_cols']) && is_array($_POST['all_cols'])) {
    $allCols = array_map('intval', $_POST['all_cols']);
} elseif (isset($_POST['all_col']) && $_POST['all_col'] !== '') {
    $allCols = [(int)$_POST['all_col']];
}

// Require at least one unique URL column
if (empty($uniqueCols) && empty($allCols)) {
    echo json_encode(['error' => 'At least one column mapping required (unique_url_col or unique_cols)']);
    exit;
}

$addedUnique = 0; $addedGrouped = 0;

if (($fp = fopen($tmp, 'r')) !== false) {
    // Read header
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); echo json_encode(['error'=>'Empty CSV']); exit; }

    // Prepare statements
    $findUnique = $db->prepare('SELECT id FROM unique_pages WHERE project_id = ? AND (canonical_url = ? OR name = ?) LIMIT 1');
    $insertUnique = $db->prepare('INSERT INTO unique_pages (project_id, name, canonical_url, page_number, screen_name, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $findGrouped = $db->prepare('SELECT id FROM grouped_urls WHERE project_id = ? AND url = ? LIMIT 1');
    $insertGrouped = $db->prepare('INSERT INTO grouped_urls (project_id, unique_page_id, url, normalized_url, created_at) VALUES (?, ?, ?, ?, NOW())');

    // Get current count for unique naming and page numbering
    $cntStmt = $db->prepare('SELECT COUNT(*) FROM unique_pages WHERE project_id = ?');
    $cntStmt->execute([$projectId]);
    $nextIndex = (int)$cntStmt->fetchColumn() + 1;
    
    // Get the next page number using the same logic as the API
    $maxStmt = $db->prepare("SELECT MAX(CAST(REPLACE(page_number, 'Page ', '') AS UNSIGNED)) as maxn FROM unique_pages WHERE project_id = ? AND page_number LIKE 'Page %'");
    $maxStmt->execute([$projectId]);
    $maxRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
    $nextPageNumber = (int)($maxRow['maxn'] ?? 0) + 1;

    while (($row = fgetcsv($fp)) !== false) {
        // Extract values from CSV columns
        $uniqueParts = [];
        foreach ($uniqueCols as $uc) { if (isset($row[$uc])) $uniqueParts[] = trim($row[$uc]); }
        $uniqueVal = trim(implode(' ', array_filter($uniqueParts, function($v){ return $v !== ''; })));
        
        $pageNumberVal = ($pageNumberCol !== null && isset($row[$pageNumberCol])) ? trim($row[$pageNumberCol]) : '';
        $pageNameVal = ($pageNameCol !== null && isset($row[$pageNameCol])) ? trim($row[$pageNameCol]) : '';
        $screenNameVal = ($screenNameCol !== null && isset($row[$screenNameCol])) ? trim($row[$screenNameCol]) : '';
        $notesVal = ($notesCol !== null && isset($row[$notesCol])) ? trim($row[$notesCol]) : '';
        $groupedUrlsVal = ($groupedUrlsCol !== null && isset($row[$groupedUrlsCol])) ? trim($row[$groupedUrlsCol]) : '';
        
        // Build all URLs values by collecting selected columns (backward compatibility)
        $allParts = [];
        foreach ($allCols as $ac) { if (isset($row[$ac])) $allParts[] = trim($row[$ac]); }
        $allVal = trim(implode(' ', array_filter($allParts, function($v){ return $v !== ''; })));
        
        // If grouped URLs column is provided, use it; otherwise fall back to allVal or uniqueVal
        if ($groupedUrlsVal !== '') {
            $allVal = $groupedUrlsVal;
        } elseif ($allVal === '' && !empty($uniqueCols)) {
            // take the first unique col as the URL source
            $uc = (int)$uniqueCols[0];
            $allVal = isset($row[$uc]) ? trim($row[$uc]) : '';
        }

        $uniqueId = null;
        if ($uniqueVal !== '') {
            $findUnique->execute([$projectId, $uniqueVal, $uniqueVal]);
            $u = $findUnique->fetch();
            if ($u) {
                $uniqueId = (int)$u['id'];
            } else {
                // Generate page number if not provided
                $pageNumberToUse = $pageNumberVal !== '' ? $pageNumberVal : ('Page ' . $nextPageNumber++);
                // Generate name if not provided
                $nameToUse = $pageNameVal !== '' ? $pageNameVal : $pageNumberToUse;
                
                $insertUnique->execute([
                    $projectId, 
                    $nameToUse, 
                    $uniqueVal, 
                    $pageNumberToUse,
                    $screenNameVal ?: null,
                    $notesVal ?: null,
                    $userId
                ]);
                $uniqueId = (int)$db->lastInsertId();
                $addedUnique++;
            }
        }

        if ($allVal !== '') {
            // split multiple urls in cell by semicolon, pipe or newline
            $parts = preg_split('/[;|\n\r]+/', $allVal);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $findGrouped->execute([$projectId, $p]);
                if ($findGrouped->fetch()) continue;
                $norm = preg_replace('#[?].*$#', '', rtrim($p, '/'));
                $norm = mb_strtolower($norm);
                $insertGrouped->execute([$projectId, $uniqueId ?: null, $p, $norm]);
                $addedGrouped++;
            }
        }
    }
    fclose($fp);
    echo json_encode(['success' => true, 'added_unique' => $addedUnique, 'added_grouped' => $addedGrouped]);
    exit;
} else {
    echo json_encode(['error' => 'Unable to open uploaded file']);
    exit;
}

?>
