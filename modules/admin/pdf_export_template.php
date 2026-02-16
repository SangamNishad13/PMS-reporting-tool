<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin', 'super_admin']);

$baseDir = getBaseDir();

function pdfTemplateConfigPath(): string {
    return __DIR__ . '/../../storage/pdf_export_template.json';
}

function pdfTemplateDefaults(): array {
    return [
        'enabled' => false,
        'header_html' => '',
        'footer_html' => '',
        'custom_css' => '',
        'logo_url' => '',
        'logo_alt' => '',
        'show_default_header' => true,
        'show_export_date' => true,
        'show_total_issues' => true,
        'header_title' => ''
    ];
}

function loadPdfTemplateConfigAdmin(): array {
    $path = pdfTemplateConfigPath();
    $defaults = pdfTemplateDefaults();
    if (!is_file($path)) {
        return $defaults;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }
    return array_merge($defaults, $data);
}

function savePdfTemplateConfigAdmin(array $data): bool {
    $path = pdfTemplateConfigPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    return (bool)@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$config = loadPdfTemplateConfigAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
        $newConfig = array_merge($config, ['logo_url' => '', 'logo_alt' => '']);
        if (!savePdfTemplateConfigAdmin($newConfig)) {
            $_SESSION['error'] = 'Failed to remove logo.';
        } else {
            $_SESSION['success'] = 'Logo removed from PDF template.';
        }
        header('Location: ' . $baseDir . '/modules/admin/pdf_export_template.php');
        exit;
    }

    $enabled = isset($_POST['enabled']) ? true : false;
    $headerHtml = trim((string)($_POST['header_html'] ?? ''));
    $footerHtml = trim((string)($_POST['footer_html'] ?? ''));
    $customCss = trim((string)($_POST['custom_css'] ?? ''));
    $logoUrl = (string)($config['logo_url'] ?? '');
    $logoAlt = trim((string)($_POST['logo_alt'] ?? ''));
    $showDefaultHeader = isset($_POST['show_default_header']);
    $showExportDate = isset($_POST['show_export_date']);
    $showTotalIssues = isset($_POST['show_total_issues']);
    $headerTitle = trim((string)($_POST['header_title'] ?? ''));

    if (!empty($_FILES['logo_file']['name'])) {
        $uploadDirFs = __DIR__ . '/../../uploads/pdf_export_templates';
        if (!is_dir($uploadDirFs) && !@mkdir($uploadDirFs, 0777, true) && !is_dir($uploadDirFs)) {
            $_SESSION['error'] = 'Unable to create upload directory.';
            header('Location: ' . $baseDir . '/modules/admin/pdf_export_template.php');
            exit;
        }

        $tmpPath = $_FILES['logo_file']['tmp_name'] ?? '';
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'] ?? '', PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            $_SESSION['error'] = 'Invalid logo format. Allowed: png, jpg, jpeg, svg, webp.';
            header('Location: ' . $baseDir . '/modules/admin/pdf_export_template.php');
            exit;
        }

        $targetName = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetFs = $uploadDirFs . '/' . $targetName;
        if (!@move_uploaded_file($tmpPath, $targetFs)) {
            $_SESSION['error'] = 'Failed to upload logo file.';
            header('Location: ' . $baseDir . '/modules/admin/pdf_export_template.php');
            exit;
        }
        $logoUrl = 'uploads/pdf_export_templates/' . $targetName;
    }


    $newConfig = [
        'enabled' => $enabled,
        'header_html' => $headerHtml,
        'footer_html' => $footerHtml,
        'custom_css' => $customCss,
        'logo_url' => $logoUrl,
        'logo_alt' => $logoAlt,
        'show_default_header' => $showDefaultHeader,
        'show_export_date' => $showExportDate,
        'show_total_issues' => $showTotalIssues,
        'header_title' => $headerTitle
    ];

    if (!savePdfTemplateConfigAdmin($newConfig)) {
        $_SESSION['error'] = 'Failed to save PDF export template settings.';
    } else {
        $_SESSION['success'] = 'PDF export template settings saved.';
    }

    header('Location: ' . $baseDir . '/modules/admin/pdf_export_template.php');
    exit;
}

$pageTitle = 'PDF Export Template';
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <h3 class="mb-1"><i class="fas fa-file-pdf text-danger me-2"></i>PDF Export Template</h3>
            <p class="text-muted mb-0">Configure PDF export look-and-feel for Issue export.</p>
        </div>
    </div>

    <div class="alert alert-info">
        <strong>Note:</strong> Direct PDF-to-PDF layout cloning is not supported in current engine.
        Use Header HTML, Footer HTML, CSS and logo to control exported PDF design.
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?php echo !empty($config['enabled']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="enabled">Enable custom PDF template</label>
                </div>

                <div class="mb-3">
                    <label for="logo_file" class="form-label">Logo (optional)</label>
                    <input type="file" class="form-control" id="logo_file" name="logo_file" accept=".png,.jpg,.jpeg,.svg,.webp">
                    <div class="mt-2">
                        <label for="logo_alt" class="form-label">Logo Alt Text</label>
                        <input type="text" class="form-control" id="logo_alt" name="logo_alt" value="<?php echo htmlspecialchars((string)($config['logo_alt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Company logo">
                    </div>
                    <?php if (!empty($config['logo_url'])): ?>
                        <div class="mt-2">
                            <small class="text-muted d-block">Current logo</small>
                            <img src="<?php echo htmlspecialchars($baseDir . '/' . ltrim($config['logo_url'], '/'), ENT_QUOTES, 'UTF-8'); ?>" alt="Current Logo" style="max-height:48px;">
                            <div class="mt-2">
                                <button type="submit" class="btn btn-sm btn-outline-danger" name="remove_logo" value="1" onclick="return confirm('Remove current logo from PDF template?');">
                                    <i class="fas fa-trash me-1"></i> Remove Logo
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>


                <div class="card mb-3">
                    <div class="card-header">Default Export Header Controls</div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="show_default_header" name="show_default_header" <?php echo !empty($config['show_default_header']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="show_default_header">Show default export header section</label>
                        </div>
                        <div class="mb-3">
                            <label for="header_title" class="form-label">Header Title</label>
                            <input type="text" class="form-control" id="header_title" name="header_title" value="<?php echo htmlspecialchars((string)($config['header_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Issues Export - Project Name">
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="show_export_date" name="show_export_date" <?php echo !empty($config['show_export_date']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="show_export_date">Show Export Date</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="show_total_issues" name="show_total_issues" <?php echo !empty($config['show_total_issues']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="show_total_issues">Show Total Issues Count</label>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="header_html" class="form-label">Header HTML</label>
                    <textarea class="form-control font-monospace" id="header_html" name="header_html" rows="5"><?php echo htmlspecialchars((string)($config['header_html'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="footer_html" class="form-label">Footer HTML</label>
                    <textarea class="form-control font-monospace" id="footer_html" name="footer_html" rows="4"><?php echo htmlspecialchars((string)($config['footer_html'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="custom_css" class="form-label">Custom CSS</label>
                    <textarea class="form-control font-monospace" id="custom_css" name="custom_css" rows="8"><?php echo htmlspecialchars((string)($config['custom_css'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Template
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
