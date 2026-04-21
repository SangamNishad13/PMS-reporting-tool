<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$db = Database::getInstance();
$projectId = (int)($_GET['project_id'] ?? 0);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head><title>Grouped URLs Diagnostic</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<h4>Grouped URLs Diagnostic</h4>

<?php if (!$projectId): ?>
<form method="get">
    <div class="mb-3">
        <label>Project ID</label>
        <input type="number" name="project_id" class="form-control" style="max-width:200px">
    </div>
    <button class="btn btn-primary">Check</button>
</form>
<?php else: ?>

<h5>Project ID: <?php echo $projectId; ?></h5>

<h6 class="mt-4">project_pages (<?php
$pp = $db->prepare("SELECT id, page_name, page_number, url FROM project_pages WHERE project_id = ? ORDER BY page_number");
$pp->execute([$projectId]);
$pages = $pp->fetchAll(PDO::FETCH_ASSOC);
echo count($pages);
?> pages)</h6>
<table class="table table-sm table-bordered">
<thead><tr><th>ID</th><th>Page Number</th><th>Page Name</th><th>URL</th></tr></thead>
<tbody>
<?php foreach ($pages as $p): ?>
<tr>
    <td><?php echo $p['id']; ?></td>
    <td><?php echo htmlspecialchars($p['page_number'] ?? ''); ?></td>
    <td><?php echo htmlspecialchars($p['page_name']); ?></td>
    <td><small><?php echo htmlspecialchars($p['url'] ?? ''); ?></small></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h6 class="mt-4">grouped_urls (<?php
$gu = $db->prepare("SELECT gu.*, pp.page_name FROM grouped_urls gu LEFT JOIN project_pages pp ON gu.unique_page_id = pp.id WHERE gu.project_id = ? ORDER BY gu.url");
$gu->execute([$projectId]);
$gurls = $gu->fetchAll(PDO::FETCH_ASSOC);
echo count($gurls);
?> rows)</h6>
<table class="table table-sm table-bordered">
<thead><tr><th>ID</th><th>URL</th><th>unique_page_id</th><th>Linked Page Name</th><th>URL Match?</th></tr></thead>
<tbody>
<?php foreach ($gurls as $g): 
    // Check if URL matches any page
    $matchedPage = null;
    foreach ($pages as $p) {
        if ($p['url'] && (rtrim($p['url'],'/') === rtrim($g['url'],'/') || rtrim($p['url'],'/') === rtrim($g['normalized_url'] ?? '','/') )) {
            $matchedPage = $p;
            break;
        }
    }
?>
<tr class="<?php echo $g['unique_page_id'] ? '' : 'table-warning'; ?>">
    <td><?php echo $g['id']; ?></td>
    <td><small><?php echo htmlspecialchars($g['url']); ?></small></td>
    <td><?php echo $g['unique_page_id'] ?? '<span class="text-danger">NULL</span>'; ?></td>
    <td><?php echo htmlspecialchars($g['page_name'] ?? '-'); ?></td>
    <td><?php echo $matchedPage ? '<span class="text-success">Yes - Page '.$matchedPage['id'].' ('.$matchedPage['page_name'].')</span>' : '<span class="text-muted">No</span>'; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php
// Count NULL unique_page_id rows
$nullCount = count(array_filter($gurls, fn($g) => !$g['unique_page_id']));
if ($nullCount > 0):
?>
<div class="alert alert-warning">
    <strong><?php echo $nullCount; ?> rows have NULL unique_page_id</strong> - these won't match pages in the issue modal.
</div>

<form method="post">
    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
    <input type="hidden" name="action" value="fix_null_unique_page_ids">
    <button class="btn btn-warning">Fix: Auto-assign unique_page_id by URL match</button>
</form>

<?php endif; ?>

<?php
// Handle fix action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fix_null_unique_page_ids') {
    $fixed = 0;
    $upd = $db->prepare("UPDATE grouped_urls SET unique_page_id = ? WHERE id = ? AND unique_page_id IS NULL");
    
    // Re-fetch null rows
    $nullStmt = $db->prepare("SELECT id, url, normalized_url FROM grouped_urls WHERE project_id = ? AND unique_page_id IS NULL");
    $nullStmt->execute([$projectId]);
    $nullRows = $nullStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($nullRows as $row) {
        foreach ($pages as $p) {
            if (!$p['url']) continue;
            $pu = rtrim($p['url'], '/');
            $ru = rtrim($row['url'], '/');
            $rn = rtrim($row['normalized_url'] ?? '', '/');
            if ($pu === $ru || $pu === $rn) {
                $upd->execute([$p['id'], $row['id']]);
                $fixed++;
                break;
            }
        }
    }
    echo '<div class="alert alert-success mt-3">Fixed <strong>'.$fixed.'</strong> rows. <a href="?project_id='.$projectId.'">Refresh</a></div>';
}
?>

<?php endif; ?>
</body>
</html>
