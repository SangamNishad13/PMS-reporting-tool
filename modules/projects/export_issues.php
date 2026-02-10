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
    $metaStmt = $db->prepare("
        SELECT field_key, field_label, field_type
        FROM issue_metadata_fields
        WHERE (project_type = ? OR project_type IS NULL)
        AND is_active = 1
        ORDER BY display_order, field_label
    ");
    $metaStmt->execute([$project['type'] ?? 'web']);
    $metadataFields = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $metadataFields = [];
}

// Fetch issue template sections for this project
$templateSections = [];
try {
    $templateStmt = $db->prepare("
        SELECT DISTINCT section_name 
        FROM issue_templates 
        WHERE project_type = ? AND is_active = 1
        ORDER BY section_name
    ");
    $templateStmt->execute([$project['type'] ?? 'web']);
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
    padding: 8px 12px;
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
    position: absolute;
    left: -20px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    cursor: move;
    font-size: 14px;
}
.columns-container {
    position: relative;
    padding-left: 25px;
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
                        <strong>Tip:</strong> Drag and drop columns to reorder them. The order here will be the order in your export.
                    </div>
                    
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary me-2" id="selectAllColumns">
                            <i class="fas fa-check-square me-1"></i> Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllColumns">
                            <i class="fas fa-square me-1"></i> Deselect All
                        </button>
                    </div>

                    <div class="row g-2 columns-container">
                        <!-- Basic Columns -->
                        <div class="col-md-6">
                            <h6 class="text-muted small mb-2">Basic Information</h6>
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
                        </div>

                        <!-- Metadata Columns -->
                        <div class="col-md-6">
                            <h6 class="text-muted small mb-2">Metadata Fields (Admin Created)</h6>
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
                                <div class="text-muted small">No metadata fields configured</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($templateSections)): ?>
                    <div class="row g-2 mt-3">
                        <div class="col-12">
                            <h6 class="text-muted small mb-2">Template Sections (from Details field)</h6>
                            <div class="alert alert-info small mb-2">
                                <i class="fas fa-info-circle me-1"></i>
                                These sections will be extracted from the Details field and exported as separate columns
                            </div>
                        </div>
                        <?php 
                        $halfCount = ceil(count($templateSections) / 2);
                        $firstHalf = array_slice($templateSections, 0, $halfCount);
                        $secondHalf = array_slice($templateSections, $halfCount);
                        ?>
                        <div class="col-md-6">
                            <?php foreach ($firstHalf as $section): ?>
                                <div class="column-checkbox mb-2" draggable="true">
                                    <i class="fas fa-grip-vertical drag-handle"></i>
                                    <input type="checkbox" class="form-check-input me-2" name="columns[]" value="section_<?php echo htmlspecialchars(strtolower(str_replace(' ', '_', $section))); ?>" id="col_section_<?php echo htmlspecialchars(strtolower(str_replace(' ', '_', $section))); ?>">
                                    <label class="form-check-label" for="col_section_<?php echo htmlspecialchars(strtolower(str_replace(' ', '_', $section))); ?>">
                                        [<?php echo htmlspecialchars($section); ?>]
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-md-6">
                            <?php foreach ($secondHalf as $section): ?>
                                <div class="column-checkbox mb-2" draggable="true">
                                    <i class="fas fa-grip-vertical drag-handle"></i>
                                    <input type="checkbox" class="form-check-input me-2" name="columns[]" value="section_<?php echo htmlspecialchars(strtolower(str_replace(' ', '_', $section))); ?>" id="col_section_<?php echo htmlspecialchars(strtolower(str_replace(' ', '_', $section))); ?>">
                                    <label class="form-check-label" for="col_section_<?php echo htmlspecialchars(strtolower(str_replace(' ', '_', $section))); ?>">
                                        [<?php echo htmlspecialchars($section); ?>]
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
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

document.getElementById('exportForm').addEventListener('submit', function(e) {
    var checkedColumns = document.querySelectorAll('input[name="columns[]"]:checked');
    if (checkedColumns.length === 0) {
        e.preventDefault();
        alert('Please select at least one column to export.');
        return false;
    }
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
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
