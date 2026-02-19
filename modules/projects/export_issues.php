<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'super_admin']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

// Get project details
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

// Get project pages for filtering
$pagesStmt = $db->prepare("SELECT id, page_name FROM project_pages WHERE project_id = ? ORDER BY page_name");
$pagesStmt->execute([$projectId]);
$projectPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get metadata fields for this project type
$metadataFields = [];
try {
    $projectType = $project['project_type'] ?? $project['type'] ?? 'web';
    $metaStmt = $db->prepare("
        SELECT field_key, field_label, options_json, sort_order
        FROM issue_metadata_fields
        WHERE project_type = ?
        AND is_active = 1
        ORDER BY sort_order, field_label
    ");
    $metaStmt->execute([$projectType]);
    $metadataFields = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $metadataFields = [];
}

// Fetch issue template sections for this project
$templateSections = [];
try {
    $projectType = $project['project_type'] ?? $project['type'] ?? 'web';
    $templateStmt = $db->prepare("
        SELECT DISTINCT section_name 
        FROM issue_templates 
        WHERE project_type = ? AND is_active = 1
        ORDER BY section_name
    ");
    $templateStmt->execute([$projectType]);
    $templateSections = $templateStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $templateSections = [];
}

// If no template sections found, use common default sections
if (empty($templateSections)) {
    $templateSections = [
        'Actual Result',
        'Incorrect Code',
        'Screenshot',
        'Recommendation',
        'Correct Code'
    ];
}

$pageTitle = 'Export Issues - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<style>
.export-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
.column-checkbox {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 8px 12px;
    padding-right: 88px;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    background: white;
    transition: all 0.2s;
    cursor: move;
    position: relative;
}
.column-checkbox:hover {
    background: #f8f9fa;
    border-color: #0d6efd;
}
.column-checkbox input[type="checkbox"]:checked + label {
    color: #0d6efd;
    font-weight: 500;
}
.column-checkbox.dragging {
    opacity: 0.5;
    background: #e9ecef;
}
.column-checkbox.drag-over {
    border-top: 3px solid #0d6efd;
}
.drag-handle {
    position: static;
    transform: none;
    margin-top: 4px;
    color: #6c757d;
    cursor: move;
    font-size: 14px;
    flex: 0 0 auto;
}
.columns-container {
    position: relative;
    padding-left: 0;
}
.column-checkbox .form-check-input {
    margin-top: 4px;
    flex: 0 0 auto;
}
.column-checkbox .form-check-label {
    flex: 1 1 auto;
    min-width: 0;
}
.order-number {
    min-width: 30px;
    text-align: center;
    font-weight: 600;
    margin-top: 1px;
    flex: 0 0 auto;
}
.order-info {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    border-radius: 4px;
    padding: 10px;
    margin-bottom: 15px;
    font-size: 13px;
    color: #0c5460;
}
.reorder-actions {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    gap: 4px;
}
.reorder-actions .btn {
    width: 28px;
    height: 28px;
    padding: 0;
    line-height: 1;
}
.column-checkbox:focus-within {
    outline: 2px solid #0d6efd;
    outline-offset: 2px;
}
</style>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>">
                        <?php echo htmlspecialchars($project['title']); ?>
                    </a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>">Accessibility Report</a></li>
                    <li class="breadcrumb-item active">Export Issues</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1">
                        <i class="fas fa-file-export text-primary me-2"></i>
                        Export Issues
                    </h2>
                    <p class="text-muted mb-0">Configure and export issues to Excel or PDF</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues.php?project_id=<?php echo $projectId; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Issues
                    </a>
                </div>
            </div>
        </div>
    </div>

    <form id="exportForm" method="POST" action="<?php echo $baseDir; ?>/api/export_issues.php">
        <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
        
        <div class="row">
            <div class="col-lg-8">
                <!-- Export Format -->
                <div class="export-section">
                    <h5 class="mb-3"><i class="fas fa-file-alt me-2"></i>Export Format</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="format" id="formatExcel" value="excel" checked>
                                <label class="form-check-label" for="formatExcel">
                                    <i class="fas fa-file-excel text-success me-1"></i> Excel (.xlsx)
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="format" id="formatPDF" value="pdf">
                                <label class="form-check-label" for="formatPDF">
                                    <i class="fas fa-file-pdf text-danger me-1"></i> PDF (.pdf)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Image Handling Options -->
                <div class="export-section">
                    <h5 class="mb-3"><i class="fas fa-image me-2"></i>Image Handling</h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="image_handling" id="imageLinks" value="links" checked>
                                <label class="form-check-label" for="imageLinks">
                                    <strong>Image Links Only</strong>
                                    <div class="small text-muted">Export image URLs as clickable links (recommended for Excel)</div>
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="image_handling" id="imageEmbed" value="embed">
                                <label class="form-check-label" for="imageEmbed">
                                    <strong>Embed Images</strong>
                                    <div class="small text-muted">Include actual images in export (best for PDF, may increase file size)</div>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="image_handling" id="imageNone" value="none">
                                <label class="form-check-label" for="imageNone">
                                    <strong>No Images</strong>
                                    <div class="small text-muted">Remove all images from details field</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Options -->
                <div class="export-section">
                    <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Options</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Pages</label>
                            <select name="pages[]" class="form-select" multiple size="5">
                                <option value="all" selected>All Pages</option>
                                <?php foreach ($projectPages as $page): ?>
                                    <option value="<?php echo $page['id']; ?>"><?php echo htmlspecialchars($page['page_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Issue Status</label>
                            <select name="status[]" class="form-select" multiple size="5">
                                <option value="all" selected>All Statuses</option>
                                <option value="open">Open</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Column Selection -->
                <div class="export-section">
                    <h5 class="mb-3"><i class="fas fa-columns me-2"></i>Select Columns to Export</h5>
                    
                    <div class="order-info">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Tip:</strong> Reorder columns by drag-and-drop, by buttons (mobile), or by keyboard using Alt + Up/Down Arrow.
                    </div>
                    
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary me-2" id="selectAllColumns">
                            <i class="fas fa-check-square me-1"></i> Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllColumns">
                            <i class="fas fa-square me-1"></i> Deselect All
                        </button>
                    </div>

                    <div class="columns-container">
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="issue_key" id="col_issue_key" checked>
                            <label class="form-check-label" for="col_issue_key">Issue Key</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="title" id="col_title" checked>
                            <label class="form-check-label" for="col_title">Title</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="common_title" id="col_common_title" checked>
                            <label class="form-check-label" for="col_common_title">Common Issue Title</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="description" id="col_description" checked>
                            <label class="form-check-label" for="col_description">Details/Description</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="status" id="col_status" checked>
                            <label class="form-check-label" for="col_status">Status</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="qa_status" id="col_qa_status" checked>
                            <label class="form-check-label" for="col_qa_status">QA Status</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="pages" id="col_pages" checked>
                            <label class="form-check-label" for="col_pages">Page Names</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="page_numbers" id="col_page_numbers" checked>
                            <label class="form-check-label" for="col_page_numbers">Page Numbers</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="reporter_name" id="col_reporter_name" checked>
                            <label class="form-check-label" for="col_reporter_name">Reporter (QA Name)</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="grouped_urls" id="col_grouped_urls" checked>
                            <label class="form-check-label" for="col_grouped_urls">Grouped URLs</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="created_at" id="col_created_at">
                            <label class="form-check-label" for="col_created_at">Created Date</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="updated_at" id="col_updated_at">
                            <label class="form-check-label" for="col_updated_at">Updated Date</label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="regression_comments" id="col_regression_comments">
                            <label class="form-check-label" for="col_regression_comments">
                                Regression Comments
                                <small class="text-muted d-block">All regression comments from issue chat</small>
                            </label>
                        </div>
                        <div class="column-checkbox mb-2" draggable="true">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <input type="checkbox" class="form-check-input me-2" name="columns[]" value="image_alt_texts" id="col_image_alt_texts">
                            <label class="form-check-label" for="col_image_alt_texts">
                                Image Alt Texts
                                <small class="text-muted d-block">Alt text of all images in issue details</small>
                            </label>
                        </div>

                        <?php if (!empty($metadataFields)): ?>
                            <?php foreach ($metadataFields as $field): ?>
                                <div class="column-checkbox mb-2" draggable="true">
                                    <i class="fas fa-grip-vertical drag-handle"></i>
                                    <input type="checkbox" class="form-check-input me-2" name="columns[]" value="<?php echo htmlspecialchars($field['field_key']); ?>" id="col_<?php echo htmlspecialchars($field['field_key']); ?>" checked>
                                    <label class="form-check-label" for="col_<?php echo htmlspecialchars($field['field_key']); ?>">
                                        <?php echo htmlspecialchars($field['field_label']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted small mb-2">No metadata fields configured</div>
                        <?php endif; ?>

                        <?php if (!empty($templateSections)): ?>
                            <div class="alert alert-info small mb-2">
                                <i class="fas fa-info-circle me-1"></i>
                                Template sections are extracted from the Details field and exported as separate columns.
                            </div>
                            <?php foreach ($templateSections as $section): ?>
                                <div class="column-checkbox mb-2" draggable="true">
                                    <i class="fas fa-grip-vertical drag-handle"></i>
                                    <input type="checkbox" class="form-check-input me-2" name="columns[]" value="section_<?php echo htmlspecialchars(strtolower(str_replace(' ', '_', $section))); ?>" id="col_section_<?php echo htmlspecialchars(strtolower(str_replace(' ', '_', $section))); ?>">
                                    <label class="form-check-label" for="col_section_<?php echo htmlspecialchars(strtolower(str_replace(' ', '_', $section))); ?>">
                                        [<?php echo htmlspecialchars($section); ?>]
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Export Button -->
                <div class="text-end">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-download me-2"></i> Export Issues
                    </button>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- Export Preview Info -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Export Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <strong>Project:</strong><br>
                            <span class="text-muted"><?php echo htmlspecialchars($project['title']); ?></span>
                        </div>
                        <div class="mb-3">
                            <strong>Total Pages:</strong><br>
                            <span class="text-muted"><?php echo count($projectPages); ?> pages</span>
                        </div>
                        <div class="mb-3">
                            <strong>Available Metadata:</strong><br>
                            <span class="text-muted"><?php echo count($metadataFields); ?> fields</span>
                        </div>
                        <div class="mb-3">
                            <strong>Template Sections:</strong><br>
                            <span class="text-muted"><?php echo count($templateSections); ?> sections</span>
                        </div>
                        <hr>
                        <h6 class="mb-2">Available Columns:</h6>
                        <ul class="small text-muted mb-3">
                            <li>Issue Key, Title, Details</li>
                            <li>Status & QA Status</li>
                            <li>Page Names & Numbers</li>
                            <li>Reporter (QA Name)</li>
                            <li>Grouped URLs</li>
                            <li>All Metadata Fields</li>
                            <li>Template Sections</li>
                            <li>Created & Updated Dates</li>
                        </ul>
                        <hr>
                        <h6 class="mb-2">Export Tips:</h6>
                        <ul class="small text-muted mb-0">
                            <li>Excel format is best for data analysis</li>
                            <li>PDF format is best for reports</li>
                            <li>Select specific pages to filter issues</li>
                            <li>Choose only needed columns for cleaner exports</li>
                            <li><strong>Image Links:</strong> Best for Excel, shows URLs</li>
                            <li><strong>Embed Images:</strong> Best for PDF, includes actual images</li>
                            <li><strong>No Images:</strong> Smallest file size</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
document.getElementById('selectAllColumns').addEventListener('click', function() {
    document.querySelectorAll('input[name="columns[]"]').forEach(function(cb) {
        cb.checked = true;
    });
});

document.getElementById('deselectAllColumns').addEventListener('click', function() {
    document.querySelectorAll('input[name="columns[]"]').forEach(function(cb) {
        cb.checked = false;
    });
});

function updateColumnOrderIndicators() {
    var items = Array.from(document.querySelectorAll('.column-checkbox'));
    items.forEach(function(item, index) {
        var badge = item.querySelector('.order-number');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'order-number badge rounded-pill bg-light text-dark border';
            badge.setAttribute('aria-hidden', 'true');
            var handle = item.querySelector('.drag-handle');
            if (handle && handle.parentNode === item) {
                item.insertBefore(badge, handle.nextSibling);
            } else {
                item.insertBefore(badge, item.firstChild);
            }
        }
        badge.textContent = String(index + 1);
        item.setAttribute('data-order', String(index + 1));
    });
}

document.getElementById('exportForm').addEventListener('submit', function(e) {
    var checkedColumns = document.querySelectorAll('input[name="columns[]"]:checked');
    if (checkedColumns.length === 0) {
        e.preventDefault();
        alert('Please select at least one column to export.');
        return false;
    }

    // Preserve exact UI order in payload (dragged order).
    this.querySelectorAll('input.generated-column-order').forEach(function(el) { el.remove(); });
    var orderedChecked = Array.from(document.querySelectorAll('.column-checkbox input[name="columns[]"]')).filter(function(cb) {
        return cb.checked;
    });

    orderedChecked.forEach(function(cb) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'columns[]';
        hidden.value = cb.value;
        hidden.className = 'generated-column-order';
        document.getElementById('exportForm').appendChild(hidden);
    });

    // Disable original checkboxes to avoid duplicate columns[] values.
    document.querySelectorAll('.column-checkbox input[name="columns[]"]').forEach(function(cb) {
        cb.disabled = true;
    });
});

// Drag and Drop functionality for column ordering
let draggedElement = null;

document.querySelectorAll('.column-checkbox').forEach(function(item) {
    item.addEventListener('dragstart', function(e) {
        draggedElement = this;
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    
    item.addEventListener('dragend', function(e) {
        this.classList.remove('dragging');
        document.querySelectorAll('.column-checkbox').forEach(function(el) {
            el.classList.remove('drag-over');
        });
        updateColumnOrderIndicators();
    });
    
    item.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        
        if (draggedElement !== this) {
            this.classList.add('drag-over');
        }
    });
    
    item.addEventListener('dragleave', function(e) {
        this.classList.remove('drag-over');
    });
    
    item.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        
        if (draggedElement !== this) {
            // Get parent containers
            const draggedParent = draggedElement.parentNode;
            const targetParent = this.parentNode;
            
            // If both are in the same column, just swap
            if (draggedParent === targetParent) {
                const allItems = Array.from(targetParent.querySelectorAll('.column-checkbox'));
                const draggedIndex = allItems.indexOf(draggedElement);
                const targetIndex = allItems.indexOf(this);
                
                if (draggedIndex < targetIndex) {
                    targetParent.insertBefore(draggedElement, this.nextSibling);
                } else {
                    targetParent.insertBefore(draggedElement, this);
                }
            } else {
                // Moving between columns - insert before target
                targetParent.insertBefore(draggedElement, this);
            }
            updateColumnOrderIndicators();
        }
    });
});
updateColumnOrderIndicators();
</script>

<!-- Floating Project Chat (bottom-right) -->
<style>
.chat-launcher { position: fixed; bottom: 20px; right: 20px; z-index: 1060; border-radius: 999px; box-shadow: 0 10px 24px rgba(0,0,0,0.18); padding: 12px 18px; display: flex; align-items: center; gap: 8px; }
.chat-launcher i { font-size: 1.1rem; }
.chat-widget { position: fixed; bottom: 86px; right: 20px; width: 360px; max-width: 92vw; height: 520px; max-height: 78vh; background: #fff; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,0.25); border: 1px solid #e5e7eb; overflow: hidden; z-index: 1060; display: none; }
.chat-widget.open { display: block; }
.chat-widget iframe { width: 100%; height: calc(100% - 48px); border: 0; }
.chat-widget .chat-widget-header { height: 48px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #0d6efd, #4dabf7); color: #fff; }
.chat-widget .chat-widget-header .btn { color: #fff; border-color: rgba(255,255,255,0.3); }
.chat-widget .chat-widget-header .btn:hover { background: rgba(255,255,255,0.12); }
@media (max-width: 576px) {
    .chat-widget { width: 94vw; height: 70vh; bottom: 76px; right: 3vw; }
    .chat-launcher { bottom: 14px; right: 14px; }
}
</style>

<div class="chat-widget" id="projectChatWidget" aria-label="Project Chat">
    <div class="chat-widget-header">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-comments"></i>
            <strong>Project Chat</strong>
        </div>
        <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetClose" aria-label="Close chat">
                <i class="fas fa-times"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetFullscreen" aria-label="Open full chat">
                <i class="fas fa-up-right-and-down-left-from-center"></i>
            </button>
        </div>
    </div>
    <iframe src="<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo (int)$projectId; ?>&embed=1" title="Project Chat"></iframe>
</div>

<button type="button" class="btn btn-primary chat-launcher" id="chatLauncher">
    <i class="fas fa-comments"></i>
    <span>Project Chat</span>
</button>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var launcher = document.getElementById('chatLauncher');
    var widget = document.getElementById('projectChatWidget');
    var closeBtn = document.getElementById('chatWidgetClose');
    var fullscreenBtn = document.getElementById('chatWidgetFullscreen');
    if (!launcher || !widget || !closeBtn || !fullscreenBtn) return;

    function openChatWidget() {
        widget.classList.add('open');
        launcher.style.display = 'none';
        setTimeout(function () { try { closeBtn.focus(); } catch (e) {} }, 0);
    }
    function closeChatWidget() {
        widget.classList.remove('open');
        launcher.style.display = 'inline-flex';
        setTimeout(function () { try { launcher.focus(); } catch (e) {} }, 0);
    }

    launcher.addEventListener('click', openChatWidget);
    closeBtn.addEventListener('click', closeChatWidget);
    fullscreenBtn.addEventListener('click', function () {
        window.location.href = '<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo (int)$projectId; ?>';
    });
    window.addEventListener('message', function (event) {
        if (!event || !event.data || event.data.type !== 'pms-chat-close') return;
        closeChatWidget();
    });
});

// Accessible, mobile-friendly column reordering controls
(function initAccessibleColumnOrdering() {
    const columnItems = document.querySelectorAll('.column-checkbox');
    if (!columnItems.length) return;

    let liveRegion = document.getElementById('columnOrderLiveRegion');
    if (!liveRegion) {
        liveRegion = document.createElement('div');
        liveRegion.id = 'columnOrderLiveRegion';
        liveRegion.className = 'visually-hidden';
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.setAttribute('aria-atomic', 'true');
        document.body.appendChild(liveRegion);
    }

    function getAllItems() {
        return Array.from(document.querySelectorAll('.column-checkbox'));
    }

    function getItemLabel(item) {
        const label = item.querySelector('label');
        return (label ? label.textContent : 'Column').trim();
    }

    function announce(message) {
        liveRegion.textContent = '';
        window.setTimeout(function () { liveRegion.textContent = message; }, 20);
    }

    function moveItemUp(item) {
        const all = getAllItems();
        const idx = all.indexOf(item);
        if (idx <= 0) return false;
        const prev = all[idx - 1];
        const prevParent = prev.parentNode;
        const itemParent = item.parentNode;

        if (prevParent === itemParent) {
            prevParent.insertBefore(item, prev);
        } else {
            const prevNext = prev.nextSibling;
            itemParent.insertBefore(prev, item);
            if (prevNext) prevParent.insertBefore(item, prevNext);
            else prevParent.appendChild(item);
        }
        return true;
    }

    function moveItemDown(item) {
        const all = getAllItems();
        const idx = all.indexOf(item);
        if (idx < 0 || idx >= all.length - 1) return false;
        const next = all[idx + 1];
        const nextParent = next.parentNode;
        const itemParent = item.parentNode;

        if (nextParent === itemParent) {
            itemParent.insertBefore(next, item);
        } else {
            const itemNext = item.nextSibling;
            nextParent.insertBefore(item, next);
            if (itemNext) itemParent.insertBefore(next, itemNext);
            else itemParent.appendChild(next);
        }
        return true;
    }

    function setButtonState(item) {
        const all = getAllItems();
        const idx = all.indexOf(item);
        const upBtn = item.querySelector('.column-move-up');
        const downBtn = item.querySelector('.column-move-down');
        if (upBtn) upBtn.disabled = idx <= 0;
        if (downBtn) downBtn.disabled = idx === -1 || idx >= all.length - 1;
    }

    function refreshAllStates() {
        getAllItems().forEach(setButtonState);
        updateColumnOrderIndicators();
    }

    columnItems.forEach(function (item) {
        const label = getItemLabel(item);
        item.setAttribute('tabindex', '0');
        item.setAttribute('role', 'group');
        item.setAttribute('aria-label', label + ' column');

        if (!item.querySelector('.reorder-actions')) {
            const controls = document.createElement('div');
            controls.className = 'reorder-actions';

            const upBtn = document.createElement('button');
            upBtn.type = 'button';
            upBtn.className = 'btn btn-sm btn-outline-secondary column-move-up';
            upBtn.setAttribute('aria-label', 'Move ' + label + ' up');
            upBtn.innerHTML = '<i class="fas fa-chevron-up" aria-hidden="true"></i>';

            const downBtn = document.createElement('button');
            downBtn.type = 'button';
            downBtn.className = 'btn btn-sm btn-outline-secondary column-move-down';
            downBtn.setAttribute('aria-label', 'Move ' + label + ' down');
            downBtn.innerHTML = '<i class="fas fa-chevron-down" aria-hidden="true"></i>';

            upBtn.addEventListener('click', function () {
                if (moveItemUp(item)) {
                    refreshAllStates();
                    item.focus();
                    announce('Moved ' + label + ' up');
                }
            });

            downBtn.addEventListener('click', function () {
                if (moveItemDown(item)) {
                    refreshAllStates();
                    item.focus();
                    announce('Moved ' + label + ' down');
                }
            });

            controls.appendChild(upBtn);
            controls.appendChild(downBtn);
            item.appendChild(controls);
        }

        item.addEventListener('keydown', function (e) {
            if (e.altKey && e.key === 'ArrowUp') {
                e.preventDefault();
                if (moveItemUp(item)) {
                    refreshAllStates();
                    item.focus();
                    announce('Moved ' + label + ' up');
                }
                return;
            }
            if (e.altKey && e.key === 'ArrowDown') {
                e.preventDefault();
                if (moveItemDown(item)) {
                    refreshAllStates();
                    item.focus();
                    announce('Moved ' + label + ' down');
                }
            }
        });
    });

    refreshAllStates();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
