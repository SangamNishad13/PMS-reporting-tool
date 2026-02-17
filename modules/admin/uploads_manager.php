<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'super_admin']);

$db = Database::getInstance();
$baseDir = getBaseDir();

function formatBytesAdminUpload(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, 2) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return number_format($value, 2) . ' TB';
}

function buildUploadRoots(): array {
    $roots = [];
    $uploadsPath = __DIR__ . '/../../uploads';
    $assetsUploadsPath = __DIR__ . '/../../assets/uploads';

    if (is_dir($uploadsPath)) {
        $resolved = realpath($uploadsPath);
        if ($resolved !== false) {
            $roots['uploads'] = $resolved;
        }
    }
    if (is_dir($assetsUploadsPath)) {
        $resolved = realpath($assetsUploadsPath);
        if ($resolved !== false) {
            $roots['assets_uploads'] = $resolved;
        }
    }
    return $roots;
}

function isWithinRoot(string $root, string $candidate): bool {
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $candidateNorm = str_replace('\\', '/', $candidate);
    return strpos($candidateNorm, $rootNorm) === 0;
}

$roots = buildUploadRoots();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cleanup_action']) && $_POST['cleanup_action'] === 'purge_project_assets_scope') {
        $scopeType = trim((string)($_POST['scope_type'] ?? ''));
        $projectId = (int)($_POST['project_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);

        $where = ["asset_type = 'file'", "file_path IS NOT NULL", "file_path LIKE 'assets/uploads/%'"];
        $params = [];
        if ($scopeType === 'project') {
            if ($projectId <= 0) {
                $_SESSION['error'] = 'Please select a valid project.';
                header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
                exit;
            }
            $where[] = 'project_id = ?';
            $params[] = $projectId;
        } elseif ($scopeType === 'user') {
            if ($userId <= 0) {
                $_SESSION['error'] = 'Please select a valid user.';
                header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
                exit;
            }
            $where[] = 'created_by = ?';
            $params[] = $userId;
        } else {
            $_SESSION['error'] = 'Invalid cleanup scope.';
            header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
            exit;
        }

        $selSql = 'SELECT id, file_path FROM project_assets WHERE ' . implode(' AND ', $where);
        $selStmt = $db->prepare($selSql);
        $selStmt->execute($params);
        $assets = $selStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($assets)) {
            $_SESSION['error'] = 'No matching project asset uploads found for selected scope.';
            header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
            exit;
        }

        $assetsRoot = $roots['assets_uploads'] ?? realpath(__DIR__ . '/../../assets/uploads');
        $removedFiles = 0;
        $missingFiles = 0;
        $assetIds = [];
        foreach ($assets as $asset) {
            $assetIds[] = (int)$asset['id'];
            $fp = str_replace('\\', '/', (string)($asset['file_path'] ?? ''));
            if (strpos($fp, 'assets/uploads/') !== 0 || !$assetsRoot) {
                continue;
            }
            $relative = substr($fp, strlen('assets/uploads/'));
            $full = $assetsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = realpath($full);
            if ($real !== false && is_file($real) && isWithinRoot($assetsRoot, $real)) {
                if (@unlink($real)) {
                    $removedFiles++;
                }
            } else {
                $missingFiles++;
            }
        }

        if (!empty($assetIds)) {
            $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
            $delStmt = $db->prepare("DELETE FROM project_assets WHERE id IN ($placeholders)");
            $delStmt->execute($assetIds);
            $deletedRows = (int)$delStmt->rowCount();
        } else {
            $deletedRows = 0;
        }

        $_SESSION['success'] = $deletedRows . ' project asset upload record(s) deleted. Physical files removed: ' . $removedFiles . '. Missing files skipped: ' . $missingFiles . '.';
        try {
            logActivity($db, (int)$_SESSION['user_id'], 'admin_purge_uploads_by_scope', 'project_assets', 0, [
                'scope_type' => $scopeType,
                'project_id' => $projectId > 0 ? $projectId : null,
                'user_id' => $userId > 0 ? $userId : null,
                'deleted_rows' => $deletedRows,
                'removed_files' => $removedFiles
            ]);
        } catch (Throwable $_) {
            // non-fatal
        }
        header('Location: ' . $baseDir . '/modules/admin/uploads_manager.php');
        exit;
    }

    if (isset($_POST['delete_upload']) && $_POST['delete_upload'] === '1') {
        $storageKey = trim((string)($_POST['storage_key'] ?? ''));
        $relativePath = trim((string)($_POST['relative_path'] ?? ''));
        $redirectQuery = (string)($_POST['redirect_query'] ?? '');

        if (!isset($roots[$storageKey])) {
            $_SESSION['error'] = 'Invalid storage location.';
        } elseif ($relativePath === '' || strpos($relativePath, "\0") !== false) {
            $_SESSION['error'] = 'Invalid file path.';
        } else {
            $rootPath = $roots[$storageKey];
            $targetPath = realpath($rootPath . DIRECTORY_SEPARATOR . $relativePath);

            if ($targetPath === false || !is_file($targetPath) || !isWithinRoot($rootPath, $targetPath)) {
                $_SESSION['error'] = 'File not found or path not allowed.';
            } else {
                $relativeUnix = ltrim(str_replace('\\', '/', $relativePath), '/');
                $deleted = @unlink($targetPath);
                if (!$deleted) {
                    $_SESSION['error'] = 'Unable to delete file.';
                } else {
                    $cleanupNotes = [];
                    if ($storageKey === 'assets_uploads') {
                        $dbPath = 'assets/uploads/' . $relativeUnix;
                        $stmt = $db->prepare('DELETE FROM project_assets WHERE file_path = ?');
                        $stmt->execute([$dbPath]);
                        if ($stmt->rowCount() > 0) {
                            $cleanupNotes[] = 'removed ' . $stmt->rowCount() . ' project asset record(s)';
                        }
                    }

                    if ($storageKey === 'uploads') {
                        $dbPath = 'uploads/' . $relativeUnix;
                        if (strpos($relativeUnix, 'pdf_export_templates/') === 0) {
                            $cfgPath = __DIR__ . '/../../storage/pdf_export_template.json';
                            if (is_file($cfgPath)) {
                                $raw = @file_get_contents($cfgPath);
                                $cfg = $raw ? json_decode($raw, true) : null;
                                if (is_array($cfg) && (string)($cfg['logo_url'] ?? '') === $dbPath) {
                                    $cfg['logo_url'] = '';
                                    $cfg['logo_alt'] = '';
                                    @file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                    $cleanupNotes[] = 'cleared PDF template logo reference';
                                }
                            }
                        }
                    }

                    $msg = 'File deleted successfully.';
                    if (!empty($cleanupNotes)) {
                        $msg .= ' Also ' . implode('; ', $cleanupNotes) . '.';
                    }
                    $_SESSION['success'] = $msg;
                    try {
                        logActivity($db, (int)$_SESSION['user_id'], 'admin_delete_upload', 'file', 0, [
                            'storage_key' => $storageKey,
                            'relative_path' => $relativeUnix
                        ]);
                    } catch (Throwable $_) {
                        // Ignore logging failures.
                    }
                }
            }
        }

        $target = $baseDir . '/modules/admin/uploads_manager.php';
        if ($redirectQuery !== '') {
            $target .= '?' . ltrim($redirectQuery, '?');
        }
        header('Location: ' . $target);
        exit;
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$locationFilter = trim((string)($_GET['location'] ?? 'all'));
$sort = trim((string)($_GET['sort'] ?? 'newest'));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 50);
if ($perPage <= 0 || $perPage > 500) {
    $perPage = 50;
}

$files = [];
$totalSize = 0;
foreach ($roots as $storageKey => $rootPath) {
    if ($locationFilter !== 'all' && $locationFilter !== $storageKey) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $name = $item->getFilename();
        // Skip hidden/system files like .htaccess from admin file listing.
        if ($name === '' || $name[0] === '.') {
            continue;
        }
        $fullPath = $item->getPathname();
        $relativePath = ltrim(str_replace('\\', '/', substr($fullPath, strlen($rootPath))), '/');
        $searchHaystack = strtolower($storageKey . '/' . $relativePath);
        if ($q !== '' && strpos($searchHaystack, strtolower($q)) === false) {
            continue;
        }
        $size = (int)$item->getSize();
        $mtime = (int)$item->getMTime();
        $rawPath = ($storageKey === 'uploads' ? 'uploads/' : 'assets/uploads/') . str_replace('\\', '/', $relativePath);
        $urlPath = $baseDir . '/api/secure_file.php?path=' . rawurlencode($rawPath);

        $files[] = [
            'storage_key' => $storageKey,
            'storage_label' => $storageKey === 'uploads' ? 'uploads/' : 'assets/uploads/',
            'relative_path' => $relativePath,
            'name' => $item->getFilename(),
            'extension' => strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION)),
            'size' => $size,
            'mtime' => $mtime,
            'mtime_display' => date('Y-m-d H:i:s', $mtime),
            'url' => $urlPath
        ];
        $totalSize += $size;
    }
}

usort($files, function (array $a, array $b) use ($sort): int {
    if ($sort === 'oldest') {
        return $a['mtime'] <=> $b['mtime'];
    }
    if ($sort === 'largest') {
        return $b['size'] <=> $a['size'];
    }
    if ($sort === 'smallest') {
        return $a['size'] <=> $b['size'];
    }
    if ($sort === 'name_asc') {
        return strcasecmp($a['name'], $b['name']);
    }
    if ($sort === 'name_desc') {
        return strcasecmp($b['name'], $a['name']);
    }
    return $b['mtime'] <=> $a['mtime'];
});

$totalFiles = count($files);
$totalPages = max(1, (int)ceil($totalFiles / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$rows = array_slice($files, $offset, $perPage);

$projects = $db->query("SELECT id, title FROM projects ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query("SELECT id, full_name, email FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$assetMetaMap = [];
$assetPaths = [];
foreach ($rows as $r) {
    if ($r['storage_key'] === 'assets_uploads') {
        $assetPaths[] = 'assets/uploads/' . $r['relative_path'];
    }
}
if (!empty($assetPaths)) {
    $assetPaths = array_values(array_unique($assetPaths));
    $ph = implode(',', array_fill(0, count($assetPaths), '?'));
    $metaSql = "
        SELECT pa.file_path, pa.project_id, pa.created_by, p.title AS project_title, u.full_name AS uploader_name
        FROM project_assets pa
        LEFT JOIN projects p ON p.id = pa.project_id
        LEFT JOIN users u ON u.id = pa.created_by
        WHERE pa.file_path IN ($ph)
    ";
    $metaStmt = $db->prepare($metaSql);
    $metaStmt->execute($assetPaths);
    foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $assetMetaMap[(string)$m['file_path']] = $m;
    }
}

$queryForForms = $_GET;
unset($queryForForms['page']);
$redirectQuery = http_build_query($queryForForms);

$pageTitle = 'Uploads Manager';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-1">Uploads Manager</h3>
            <p class="text-muted mb-0">Direct admin access to all uploaded files. You can review and delete files to reduce storage.</p>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2">
                    <div class="small text-muted">Total Files</div>
                    <div class="h5 mb-0"><?php echo (int)$totalFiles; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2">
                    <div class="small text-muted">Estimated Size</div>
                    <div class="h5 mb-0"><?php echo htmlspecialchars(formatBytesAdminUpload($totalSize)); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2">
                    <div class="small text-muted">Scanned Locations</div>
                    <div class="h6 mb-0"><?php echo htmlspecialchars(implode(', ', array_keys($roots)) ?: 'None'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3 border-warning">
        <div class="card-body py-3">
            <div class="fw-semibold mb-2">Project/User Wise Upload Cleanup</div>
            <div class="small text-muted mb-2">This cleanup targets mapped uploads from <code>project_assets.file_path</code> only.</div>
            <form method="post" class="row g-2 align-items-end" data-confirm="Delete uploads for the selected scope?">
                <input type="hidden" name="cleanup_action" value="purge_project_assets_scope">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Scope</label>
                    <select name="scope_type" id="uploadScopeType" class="form-select form-select-sm">
                        <option value="project">Project Wise</option>
                        <option value="user">User Wise</option>
                    </select>
                </div>
                <div class="col-md-4" id="uploadScopeProjectWrap">
                    <label class="form-label form-label-sm">Project</label>
                    <select name="project_id" class="form-select form-select-sm">
                        <option value="">Select project</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-none" id="uploadScopeUserWrap">
                    <label class="form-label form-label-sm">User</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">Select user</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)$u['full_name'] . ' (' . (string)$u['email'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-danger">Delete Matching Uploads</button>
                </div>
            </form>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="text" class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search by file name or path">
        </div>
        <div class="col-md-2">
            <select name="location" class="form-select form-select-sm">
                <option value="all"<?php if ($locationFilter === 'all') echo ' selected'; ?>>All Locations</option>
                <option value="uploads"<?php if ($locationFilter === 'uploads') echo ' selected'; ?>>uploads/</option>
                <option value="assets_uploads"<?php if ($locationFilter === 'assets_uploads') echo ' selected'; ?>>assets/uploads/</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="sort" class="form-select form-select-sm">
                <option value="newest"<?php if ($sort === 'newest') echo ' selected'; ?>>Newest</option>
                <option value="oldest"<?php if ($sort === 'oldest') echo ' selected'; ?>>Oldest</option>
                <option value="largest"<?php if ($sort === 'largest') echo ' selected'; ?>>Largest</option>
                <option value="smallest"<?php if ($sort === 'smallest') echo ' selected'; ?>>Smallest</option>
                <option value="name_asc"<?php if ($sort === 'name_asc') echo ' selected'; ?>>Name A-Z</option>
                <option value="name_desc"<?php if ($sort === 'name_desc') echo ' selected'; ?>>Name Z-A</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="per_page" class="form-select form-select-sm">
                <?php foreach ([25, 50, 100, 200] as $pp): ?>
                    <option value="<?php echo $pp; ?>"<?php if ($perPage === $pp) echo ' selected'; ?>><?php echo $pp; ?>/page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-primary w-100">Apply</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-striped table-sm align-middle">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Location</th>
                    <th>Project</th>
                    <th>Uploader</th>
                    <th>Relative Path</th>
                    <th>Size</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-muted">No files found for current filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $metaKey = $r['storage_key'] === 'assets_uploads' ? ('assets/uploads/' . $r['relative_path']) : '';
                        $meta = $metaKey !== '' && isset($assetMetaMap[$metaKey]) ? $assetMetaMap[$metaKey] : null;
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($r['extension'] ?: 'no-extension'); ?></div>
                            </td>
                            <td><code><?php echo htmlspecialchars($r['storage_label']); ?></code></td>
                            <td><?php echo htmlspecialchars((string)($meta['project_title'] ?? '-')); ?></td>
                            <td><?php echo htmlspecialchars((string)($meta['uploader_name'] ?? '-')); ?></td>
                            <td><code><?php echo htmlspecialchars($r['relative_path']); ?></code></td>
                            <td><?php echo htmlspecialchars(formatBytesAdminUpload((int)$r['size'])); ?></td>
                            <td><?php echo htmlspecialchars($r['mtime_display']); ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="<?php echo htmlspecialchars($r['url']); ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">Open</a>
                                    <form method="post" class="d-inline" data-confirm="Delete this file?">
                                        <input type="hidden" name="delete_upload" value="1">
                                        <input type="hidden" name="storage_key" value="<?php echo htmlspecialchars($r['storage_key']); ?>">
                                        <input type="hidden" name="relative_path" value="<?php echo htmlspecialchars($r['relative_path']); ?>">
                                        <input type="hidden" name="redirect_query" value="<?php echo htmlspecialchars($redirectQuery); ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <nav aria-label="Uploads pages">
        <ul class="pagination pagination-sm">
            <?php
            $qs = $_GET;
            unset($qs['page']);
            $baseQs = http_build_query($qs);
            $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
            for ($p = 1; $p <= $totalPages; $p++) {
                $activeClass = $p === $page ? ' active' : '';
                $href = $baseUrl . '?' . ($baseQs !== '' ? $baseQs . '&' : '') . 'page=' . $p;
                echo '<li class="page-item' . $activeClass . '"><a class="page-link" href="' . htmlspecialchars($href) . '">' . $p . '</a></li>';
            }
            ?>
        </ul>
    </nav>
</div>

<script>
(function() {
    var scopeType = document.getElementById('uploadScopeType');
    var projectWrap = document.getElementById('uploadScopeProjectWrap');
    var userWrap = document.getElementById('uploadScopeUserWrap');
    if (!scopeType || !projectWrap || !userWrap) return;
    function syncScope() {
        if (scopeType.value === 'user') {
            projectWrap.classList.add('d-none');
            userWrap.classList.remove('d-none');
        } else {
            userWrap.classList.add('d-none');
            projectWrap.classList.remove('d-none');
        }
    }
    scopeType.addEventListener('change', syncScope);
    syncScope();
})();

document.querySelectorAll('form[data-confirm]').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        var msg = form.getAttribute('data-confirm') || 'Are you sure?';
        e.preventDefault();
        if (typeof window.confirmModal === 'function') {
            window.confirmModal(msg, function() {
                form.submit();
            });
            return;
        }
        var confirmFn = (typeof window._origConfirm === 'function') ? window._origConfirm : window.confirm;
        if (confirmFn(msg)) {
            form.submit();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
