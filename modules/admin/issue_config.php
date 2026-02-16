<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
// Allow if admin, super_admin OR has explicit permission
if (!$auth->checkRole(['admin', 'super_admin']) && empty($_SESSION['can_manage_issue_config'])) {
    $auth->requireRole(['admin', 'super_admin']); // Fallback to standard redirect
}

$baseDir = getBaseDir();
$db = Database::getInstance();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Issue Configuration</h4>
            <div class="text-muted small">Manage presets, metadata fields, and default templates for different project types.</div>
        </div>
        <div>
             <a href="<?php echo $baseDir; ?>/modules/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Dashboard
             </a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-auto">
                    <label class="fw-bold me-2">Configuring for:</label>
                </div>
                <div class="col-auto">
                    <div class="btn-group" role="group">
                        <input type="radio" class="btn-check project-type-toggle" name="project_type" id="type_web" value="web" checked>
                        <label class="btn btn-outline-primary" for="type_web"><i class="fas fa-globe me-1"></i> Web</label>

                        <input type="radio" class="btn-check project-type-toggle" name="project_type" id="type_app" value="app">
                        <label class="btn btn-outline-primary" for="type_app"><i class="fas fa-mobile-alt me-1"></i> App</label>

                        <input type="radio" class="btn-check project-type-toggle" name="project_type" id="type_pdf" value="pdf">
                        <label class="btn btn-outline-primary" for="type_pdf"><i class="fas fa-file-pdf me-1"></i> PDF</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs" id="configTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" id="presets-tab" data-bs-toggle="tab" data-bs-target="#presets" type="button">Issue Presets</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="metadata-tab" data-bs-toggle="tab" data-bs-target="#metadata" type="button">Metadata Fields</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="defaults-tab" data-bs-toggle="tab" data-bs-target="#defaults" type="button">Default Template</button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 p-4 bg-white mb-5 rounded-bottom shadow-sm">
        
        <!-- Presets Tab -->
        <div class="tab-pane fade show active" id="presets" role="tabpanel">
            <div class="row">
                <div class="col-md-4 border-end">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Saved Presets</h6>
                        <button class="btn btn-sm btn-success" id="btnNewPreset"><i class="fas fa-plus"></i> New</button>
                    </div>
                    
                    <div class="input-group input-group-sm mb-3">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="searchPresets" placeholder="Search presets...">
                    </div>

                    <div class="list-group" id="presetList" style="max-height: 500px; overflow-y: auto;">
                        <!-- Presets loaded via JS -->
                        <div class="text-center p-3 text-muted">Loading...</div>
                    </div>

                    <div class="mt-3 pt-3 border-top">
                        <h6 class="small fw-bold">Import from CSV</h6>
                        <input type="file" id="csvFile" class="form-control form-control-sm mb-2" accept=".csv">
                        <button class="btn btn-sm btn-outline-primary w-100" id="btnImportCsv">Import CSV</button>
                        <div class="form-text small">
                            Required headers: <code>Title</code>. Optional: <code>Description</code> (HTML), metadata columns (e.g. <code>Severity</code>, <code>Priority</code>).
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8 ps-md-4">
                    <form id="presetForm" style="display:none;">
                        <input type="hidden" id="presetId">
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0" id="formTitle">New Preset</h5>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnDeletePreset" style="display:none;"><i class="fas fa-trash"></i> Delete</button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label required">Preset Title</label>
                            <input type="text" class="form-control" id="presetTitle" required placeholder="e.g. Image missing alt text">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Default Metadata</label>
                            <div class="row g-2" id="presetMetadataContainer">
                                <!-- Dynamic Metadata Fields loaded via JS -->
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Issue Description Template</label>
                            <textarea id="presetDescription" class="summernote"></textarea>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Preset</button>
                        </div>
                    </form>
                    
                    <div id="noPresetSelected" class="text-center py-5 text-muted">
                        <i class="fas fa-hand-pointer fa-3x mb-3 opacity-25"></i>
                        <p>Select a preset from the list or create a new one.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metadata Tab -->
        <div class="tab-pane fade" id="metadata" role="tabpanel">
            <div class="alert alert-info py-2 small">
                <i class="fas fa-info-circle"></i> Define dynamic dropdown fields for your issues. System fields (Severity, Priority, Status) can be modified but not deleted completely using this UI (use rigorous DB admin if really needed).
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">Sort</th>
                            <th style="width: 200px;">Field Label</th>
                            <th style="width: 200px;">Key (Database)</th>
                            <th>Options (Comma separated)</th>
                            <th style="width: 100px;">Active</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="metadataList">
                        <!-- Metadata rows loaded via JS -->
                    </tbody>
                </table>
            </div>

            <button class="btn btn-outline-primary btn-sm mt-2" id="btnAddField">
                <i class="fas fa-plus"></i> Add New Field
            </button>
        </div>

        <!-- Default Template Tab -->
        <div class="tab-pane fade" id="defaults" role="tabpanel">
             <div class="row justify-content-center">
                 <div class="col-md-8">
                     <p class="text-muted">
                         Configure the default sections that appear in the "Create Issue" modal when <b>no preset</b> is selected.
                     </p>
                     
                     <div class="card bg-light mb-3">
                         <div class="card-body">
                             <label class="form-label fw-bold">Default Sections</label>
                             <div id="defaultSectionsList" class="mb-2">
                                 <!-- Pill tags for sections -->
                             </div>
                             
                             <div class="input-group">
                                 <input type="text" class="form-control" id="newSectionInput" placeholder="Add section (e.g. Actual Result)">
                                 <button class="btn btn-secondary" type="button" id="btnAddSection">Add</button>
                             </div>
                             <small class="text-muted">These sections will be generated as empty blocks in the editor (e.g. <code>[Actual Result]</code>)</small>
                         </div>
                     </div>

                     <div class="d-grid">
                         <button class="btn btn-primary" id="btnSaveDefaults"><i class="fas fa-save"></i> Save Configuration</button>
                     </div>
                 </div>
             </div>
        </div>
    </div>
</div>

<!-- Metadata Edit Modal -->
<div class="modal fade" id="metadataModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Metadata Field</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="metaId">
                <div class="mb-3">
                    <label class="form-label">Field Label</label>
                    <input type="text" class="form-control" id="metaLabel" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Field Key</label>
                    <input type="text" class="form-control" id="metaKey" required placeholder="system_variable_name">
                    <div class="form-text">Unique key for database (letters, numbers, underscores only).</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Options</label>
                    <textarea class="form-control" id="metaOptions" rows="5" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                    <div class="form-text">One option per line. This allows values to contain commas.</div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="metaActive" checked>
                    <label class="form-check-label" for="metaActive">Field Active</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveMeta">Save Field</button>
            </div>
        </div>
    </div>
</div>

<!-- CSV Mapping Modal -->
<div class="modal fade" id="csvMappingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Map CSV Columns</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">Map your CSV columns to the appropriate preset fields. "Preset Title" is required.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Preset Field</th>
                                <th>CSV Column</th>
                            </tr>
                        </thead>
                        <tbody id="mappingTableBody">
                            <!-- Mapping rows generated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnConfirmImport">Start Import</button>
            </div>
        </div>
    </div>
</div>

<!-- Summernote & Scripts -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
$(document).ready(function() {
    const API_URL = '<?php echo $baseDir; ?>/api/issue_config.php';
    let currentType = 'web';
    let metadataFields = [];
    let defaultSections = [];

    // --- Image Upload Handling ---
    const IMAGE_UPLOAD_URL = '<?php echo $baseDir; ?>/api/chat_upload_image.php';

    function uploadIssueImage(file, $el) {
        if (!file || !file.type || !file.type.startsWith('image/')) return;
        var altText = window.prompt('Enter alt text for this image (optional):', '');
        if (altText === null) return; // Cancelled
        
        var fd = new FormData();
        fd.append('image', file);
        fetch(IMAGE_UPLOAD_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(res){ return res.json(); })
            .then(function(res){
                if (res && res.success && res.url) {
                    var safeAlt = (altText.trim() || 'Issue Screenshot').replace(/"/g, '&quot;');
                    $el.summernote('pasteHTML', '<p><img src="' + res.url + '" alt="' + safeAlt + '" style="max-width:100%; height:auto;" /></p>');
                } else if (res && res.error) {
                    alert(res.error);
                }
            })
            .catch(function(){ alert('Image upload failed'); });
    }

    // Initialize Summernote
    if (!document.getElementById('preset-codeblock-btn-style')) {
        var presetStyle = document.createElement('style');
        presetStyle.id = 'preset-codeblock-btn-style';
        presetStyle.textContent = '.note-btn-codeblock.active{background-color:#0d6efd!important;color:#fff!important;border-color:#0a58ca!important;}';
        document.head.appendChild(presetStyle);
    }

    function setPresetCodeBlockButtonState() {
        var $editor = $('#presetDescription');
        var $btn = $editor.next('.note-editor').find('.note-btn-codeblock');
        if (!$btn.length) return;
        var inCode = false;
        try {
            var range = $editor.summernote('createRange');
            var sc = range && range.sc ? range.sc : null;
            var node = sc && sc.nodeType === 3 ? sc.parentNode : sc;
            var editable = $editor.next('.note-editor').find('.note-editable')[0];
            inCode = !!(node && editable && $(node).closest('code', editable).length);
        } catch (e) { inCode = false; }
        $btn
            .toggleClass('active', inCode)
            .attr('aria-pressed', inCode ? 'true' : 'false')
            .attr('title', 'Code Block')
            .attr('aria-label', 'Code Block');
    }

    function enablePresetToolbarKeyboardA11y() {
        var $editor = $('#presetDescription');
        var $toolbar = $editor.next('.note-editor').find('.note-toolbar').first();
        if (!$toolbar.length || $toolbar.data('kbdA11yBound')) return;
        function getItems() {
            return $toolbar.find('.note-btn-group button').filter(function() {
                var $b = $(this);
                if ($b.is(':hidden')) return false;
                if ($b.prop('disabled')) return false;
                if ($b.closest('.dropdown-menu').length) return false;
                if ($b.attr('aria-hidden') === 'true') return false;
                return true;
            });
        }

        function setActiveIndex(idx) {
            var $items = getItems();
            if (!$items.length) return;
            var next = Math.max(0, Math.min(idx, $items.length - 1));
            $items.attr('tabindex', '-1');
            $items.eq(next).attr('tabindex', '0');
            $toolbar.data('kbdIndex', next);
        }

        function ensureOneTabStop() {
            var $items = getItems();
            if (!$items.length) return;
            if (!$items.filter('[tabindex="0"]').length) {
                $items.attr('tabindex', '-1');
                $items.eq(0).attr('tabindex', '0');
            }
        }

        $toolbar.attr('role', 'toolbar');
        if (!$toolbar.attr('aria-label')) {
            $toolbar.attr('aria-label', 'Editor toolbar');
        }
        setActiveIndex(0);

        $toolbar.on('focusin', 'button', function() {
            var $items = getItems();
            var idx = $items.index(this);
            if (idx >= 0) setActiveIndex(idx);
        });
        $toolbar.on('click', 'button', function() {
            var $items = getItems();
            var idx = $items.index(this);
            if (idx >= 0) setActiveIndex(idx);
        });

        function handleToolbarArrowNav(e) {
            var key = e.key || (e.originalEvent && e.originalEvent.key);
            if (key !== 'ArrowRight' && key !== 'ArrowLeft' && key !== 'Home' && key !== 'End') return;

            var $items = getItems();
            if (!$items.length) return;
            var activeEl = document.activeElement;
            var idx = $items.index(activeEl);
            if (idx < 0 && activeEl && activeEl.closest) {
                var parentBtn = activeEl.closest('button');
                if (parentBtn) idx = $items.index(parentBtn);
            }
            if (idx < 0) {
                var savedIdx = parseInt($toolbar.data('kbdIndex'), 10);
                if (!isNaN(savedIdx) && savedIdx >= 0 && savedIdx < $items.length) idx = savedIdx;
            }
            if (idx < 0) idx = $items.index($items.filter('[tabindex="0"]').first());
            if (idx < 0) idx = 0;

            e.preventDefault();
            if (e.stopPropagation) e.stopPropagation();
            if (key === 'Home') idx = 0;
            else if (key === 'End') idx = $items.length - 1;
            else if (key === 'ArrowRight') idx = (idx + 1) % $items.length;
            else if (key === 'ArrowLeft') idx = (idx - 1 + $items.length) % $items.length;

            setActiveIndex(idx);
            var $target = $items.eq(idx);
            $target.focus();
            if (document.activeElement !== $target.get(0)) {
                setTimeout(function() { $target.focus(); }, 0);
            }
        }

        $toolbar.on('keydown', handleToolbarArrowNav);
        if (!$toolbar.data('kbdA11yNativeKeyBound')) {
            $toolbar.get(0).addEventListener('keydown', handleToolbarArrowNav, true);
            $toolbar.data('kbdA11yNativeKeyBound', true);
        }

        var observer = new MutationObserver(function() { ensureOneTabStop(); });
        observer.observe($toolbar[0], { subtree: true, attributes: true, attributeFilter: ['tabindex', 'class', 'disabled'] });
        $toolbar.data('kbdA11yObserver', observer);
        var fixTimer = setInterval(ensureOneTabStop, 1000);
        $toolbar.data('kbdA11yTimer', fixTimer);
        ensureOneTabStop();

        $toolbar.data('kbdA11yBound', true);
    }

    function toggleCodeBlockInPresetEditor(context) {
        context.invoke('editor.focus');
        context.invoke('editor.saveRange');
        var range = context.invoke('editor.createRange');
        var sc = range && range.sc ? range.sc : null;
        var node = sc && sc.nodeType === 3 ? sc.parentNode : sc;
        var editable = context.layoutInfo && context.layoutInfo.editable ? context.layoutInfo.editable[0] : null;
        var inCode = false;
        if (node && editable) {
            inCode = $(node).closest('code', editable).length > 0;
        }
        if (inCode) {
            var $code = $(node).closest('code', editable).first();
            if ($code.length) {
                var txt = document.createTextNode($code.text());
                var codeNode = $code.get(0);
                codeNode.parentNode.replaceChild(txt, codeNode);
                try {
                    var sel = window.getSelection();
                    if (sel) {
                        var r = document.createRange();
                        r.setStart(txt, txt.textContent.length);
                        r.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(r);
                    }
                } catch (e) {}
            }
        } else {
            var sel = window.getSelection();
            if (sel && sel.rangeCount) {
                var nativeRange = sel.getRangeAt(0);
                var selectedText = nativeRange.toString();
                var code = document.createElement('code');
                if (selectedText) {
                    code.textContent = selectedText;
                    nativeRange.deleteContents();
                    nativeRange.insertNode(code);
                    var after = document.createRange();
                    after.setStartAfter(code);
                    after.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(after);
                } else {
                    code.textContent = '\u200B';
                    nativeRange.insertNode(code);
                    var inside = document.createRange();
                    inside.setStart(code.firstChild, 1);
                    inside.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(inside);
                }
            }
        }
        setTimeout(setPresetCodeBlockButtonState, 0);
    }

    $('#presetDescription').summernote({
        height: 250,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear']],
            ['fontname', ['fontname']],
            ['fontsize', ['fontsize']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph', 'height']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'video', 'hr', 'codeBlockToggle']],
            ['view', ['fullscreen', 'help']]
        ],
        buttons: {
            codeBlockToggle: function(context) {
                var ui = $.summernote.ui;
                var $btn = ui.button({
                    contents: '&lt;/&gt;',
                    className: 'note-btn-codeblock',
                    click: function() { toggleCodeBlockInPresetEditor(context); }
                }).render();
                try {
                    $btn.attr('title', 'Code Block');
                    $btn.attr('aria-label', 'Code Block');
                } catch (e) {}
                return $btn;
            }
        },
        styleTags: [
            'p',
            { title: 'Blockquote', tag: 'blockquote', className: 'blockquote', value: 'blockquote' },
            'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'
        ],
        callbacks: {
            onInit: function() {
                setTimeout(setPresetCodeBlockButtonState, 0);
                setTimeout(enablePresetToolbarKeyboardA11y, 0);
                setTimeout(enablePresetToolbarKeyboardA11y, 200);
            },
            onFocus: function() { setPresetCodeBlockButtonState(); },
            onKeyup: function() { setPresetCodeBlockButtonState(); },
            onMouseup: function() { setPresetCodeBlockButtonState(); },
            onChange: function() { setPresetCodeBlockButtonState(); },
            onImageUpload: function(files) {
                const $el = $('#presetDescription');
                (files || []).forEach(function(f){ uploadIssueImage(f, $el); });
            },
            onPaste: function(e) {
                const $el = $('#presetDescription');
                var clipboard = e.originalEvent && e.originalEvent.clipboardData;
                if (clipboard && clipboard.items) {
                    for (var i = 0; i < clipboard.items.length; i++) {
                        var item = clipboard.items[i];
                        if (item.type && item.type.indexOf('image') === 0) {
                            e.preventDefault();
                            uploadIssueImage(item.getAsFile(), $el);
                            break;
                        }
                    }
                }
            }
        }
    });
    setTimeout(enablePresetToolbarKeyboardA11y, 0);
    setTimeout(enablePresetToolbarKeyboardA11y, 200);

    // --- Type Switching ---
    $('.project-type-toggle').on('change', function() {
        currentType = $(this).val();
        loadAllData();
    });

    function loadAllData() {
        // Parallel load
        loadPresets();
        loadMetadata();
        loadDefaults();
    }

    // --- PRESETS HANDLING ---
    function loadPresets() {
        $('#presetList').html('<div class="text-center p-3 text-muted">Loading...</div>');
        $.get(API_URL, { action: 'get_presets', project_type: currentType }, function(res) {
            if (res.success) {
                renderPresets(res.data);
            }
        });
    }

    function renderPresets(presets) {
        const $list = $('#presetList');
        $list.empty();
        
        // Add Bulk Select UI header
        const $bulkWrap = $('<div class="px-2 mb-2 d-flex justify-content-between align-items-center">');
        const $selectAll = $('<div class="form-check"><input class="form-check-input" type="checkbox" id="selectAllPresets"><label class="form-check-label small" for="selectAllPresets">Select All</label></div>');
        const $bulkDelBtn = $('<button class="btn btn-sm btn-outline-danger py-0" id="btnBulkDelete" style="display:none;font-size:0.7rem;"><i class="fas fa-trash"></i> Delete Selected</button>');
        
        $bulkWrap.append($selectAll).append($bulkDelBtn);
        $list.append($bulkWrap);

        // Filter by search
        const term = $('#searchPresets').val().toLowerCase();
        const filtered = presets.filter(p => p.title.toLowerCase().includes(term));

        if (filtered.length === 0) {
            $list.append('<div class="p-3 text-muted text-center">No presets found.</div>');
            return;
        }

        filtered.forEach(p => {
             const $item = $('<div class="list-group-item list-group-item-action d-flex align-items-center p-2">');
             const $cbWrap = $('<div class="me-2">').append($('<input type="checkbox" class="form-check-input preset-select">').val(p.id));
             const $titleWrap = $('<div class="flex-grow-1 cursor-pointer fw-bold" style="font-size:0.9rem;">').text(p.title);
             
             $item.append($cbWrap).append($titleWrap);
             $item.data('preset', p);
             
             $titleWrap.on('click', function(e) {
                 e.preventDefault();
                 selectPreset($item);
             });
             
             $item.appendTo($list);
        });

        $('#selectAllPresets').on('change', function() {
            $('.preset-select').prop('checked', this.checked);
            updateBulkDeleteBtnStatus();
        });
        
        $list.on('change', '.preset-select', function() {
            updateBulkDeleteBtnStatus();
        });

        function updateBulkDeleteBtnStatus() {
            const count = $('.preset-select:checked').length;
            if (count > 0) {
                $('#btnBulkDelete').show().text('Delete (' + count + ')');
            } else {
                $('#btnBulkDelete').hide();
            }
        }
    }

    $('body').on('click', '#btnBulkDelete', function() {
        const ids = $('.preset-select:checked').map(function() { return $(this).val(); }).get();
        if (ids.length === 0) return;
        confirmModal('Are you sure you want to delete ' + ids.length + ' presets?', function() {
            $.post(API_URL, { action: 'bulk_delete_presets', ids: JSON.stringify(ids) }, function(res) {
                if (res.success) {
                    showToast('Presets deleted successfully', 'success');
                    loadPresets();
                    $('#btnNewPreset').click();
                } else {
                    alert(res.error);
                }
            });
        });
    });

    $('#searchPresets').on('input', loadPresets); // Simple re-fetch or client-side filter could be optimized

    function selectPreset($el) {
        $('#presetList .active').removeClass('active');
        $el.addClass('active');

        const p = $el.data('preset');
        $('#noPresetSelected').hide();
        $('#presetForm').show();
        
        $('#presetId').val(p.id);
        $('#presetTitle').val(p.title);
        $('#formTitle').text('Edit Preset: ' + p.title);
        $('#presetDescription').summernote('code', p.description_html);
        $('#btnDeletePreset').show();

        // Populate Metadata
        renderPresetMetadataForm(p.metadata_json);
    }

    $('#btnNewPreset').click(function() {
        $('#presetList .active').removeClass('active');
        $('#noPresetSelected').hide();
        $('#presetForm').show();
        
        $('#presetId').val('');
        $('#presetTitle').val('');
        $('#formTitle').text('New Preset');
        
        // Auto-load default template sections if available
        let defaultHtml = '';
        if (defaultSections && defaultSections.length > 0) {
            defaultSections.forEach(sec => {
                defaultHtml += `<p><strong>[${sec}]</strong></p><p><br></p><p><br></p>`;
            });
        }
        $('#presetDescription').summernote('code', defaultHtml);
        
        $('#btnDeletePreset').hide();

        renderPresetMetadataForm({});
    });

    function renderPresetMetadataForm(values = {}) {
        const $container = $('#presetMetadataContainer');
        $container.empty();
        
        if (metadataFields.length === 0) {
            $container.html('<div class="text-muted small">No metadata fields defined. Go to Metadata tab to add some.</div>');
            return;
        }

        metadataFields.forEach(field => {
            const val = values[field.field_key] || '';
            const $col = $('<div class="col-md-6">');
            const $group = $('<div class="form-group mb-1">');
            
            let labelText = field.field_label;
            if (!field.is_active) {
                labelText += ' <span class="badge bg-secondary badge-sm" style="font-size:0.6rem;">Inactive</span>';
            }
            
            $group.append($('<label class="small text-muted d-block">').html(labelText));
            
            if (field.options && field.options.length > 0) {
                const $sel = $('<select class="form-select form-select-sm preset-meta-field">').attr('data-key', field.field_key);
                $sel.append('<option value="">(Default/Empty)</option>');
                field.options.forEach(opt => {
                     $sel.append($('<option>').val(opt).text(opt).prop('selected', opt == val));
                });
                $group.append($sel);
            } else {
                 const $inp = $('<input type="text" class="form-control form-control-sm preset-meta-field">')
                    .attr('data-key', field.field_key)
                    .val(val);
                 $group.append($inp);
            }
            $col.append($group);
            $container.append($col);
        });
    }

    $('#presetForm').submit(function(e) {
        e.preventDefault();
        const id = $('#presetId').val();
        
        // Collect Metadata
        const meta = {};
        $('.preset-meta-field').each(function() {
            const key = $(this).data('key');
            const val = $(this).val();
            if (val) meta[key] = val;
        });

        const data = {
            action: 'save_preset',
            project_type: currentType,
            id: id,
            title: $('#presetTitle').val(),
            description_html: $('#presetDescription').summernote('code'),
            metadata_json: JSON.stringify(meta)
        };

        $.post(API_URL, data, function(res) {
            if (res.success) {
                showToast('Preset saved successfully', 'success');
                loadPresets();
                if (!id) $('#btnNewPreset').click(); // Clear form if new
            } else {
                showToast('Error: ' + res.error, 'danger');
            }
        });
    });

    $('#btnDeletePreset').click(function() {
        confirmModal('Delete this preset?', function() {
            const id = $('#presetId').val();
            $.post(API_URL, { action: 'delete_preset', id: id }, function(res) {
                if (res.success) {
                    showToast('Preset deleted', 'success');
                    $('#btnNewPreset').click();
                    loadPresets();
                }
            });
        });
    });

    // --- METADATA HANDLING ---
    function loadMetadata() {
        $('#metadataList').html('<tr><td colspan="6" class="text-center">Loading...</td></tr>');
        $.get(API_URL, { action: 'get_metadata', project_type: currentType }, function(res) {
            if (res.success) {
                metadataFields = res.data;
                renderMetadataTable();
            }
        });
    }

    function renderMetadataTable() {
        const $tbody = $('#metadataList');
        $tbody.empty();
        
        metadataFields.forEach((f, idx) => {
            const $tr = $('<tr>');
            $tr.attr('data-id', f.id);
            $tr.attr('data-sort', f.sort_order);
            $tr.append(`<td><i class="fas fa-grip-vertical text-muted me-2" style="cursor:move;"></i>${idx + 1}</td>`);
            $tr.append(`<td>${f.field_label}</td>`);
            $tr.append(`<td><code>${f.field_key}</code></td>`);
            $tr.append(`<td><small class="text-muted text-truncate d-block" style="max-width: 200px;">${(f.options || []).join(' | ')}</small></td>`);
            
            const activeBadge = f.is_active 
                ? '<span class="badge bg-success">Yes</span>' 
                : '<span class="badge bg-secondary">No</span>';
            $tr.append(`<td>${activeBadge}</td>`);
            
            const $actions = $('<td>');
            const $editBtn = $('<button class="btn btn-sm btn-light border"><i class="fas fa-edit"></i></button>');
            $editBtn.click(() => openMetaModal(f));
            $actions.append($editBtn);

            // "System" fields might be protected from deletion if needed, but for now allow all
            const $delBtn = $('<button class="btn btn-sm btn-light border text-danger ms-1"><i class="fas fa-trash"></i></button>');
            $delBtn.click(() => deleteMeta(f.id));
            $actions.append($delBtn);
            
            $tr.append($actions);
            $tbody.append($tr);
        });
        
        // Initialize SortableJS for drag-and-drop
        if (window.Sortable && !$tbody.data('sortable-initialized')) {
            Sortable.create($tbody[0], {
                animation: 150,
                handle: '.fa-grip-vertical',
                onEnd: function(evt) {
                    updateMetadataSortOrder();
                }
            });
            $tbody.data('sortable-initialized', true);
        }
    }
    
    function updateMetadataSortOrder() {
        const $rows = $('#metadataList tr');
        const newOrder = [];
        
        $rows.each(function(idx) {
            const id = $(this).attr('data-id');
            const newSortOrder = idx + 1;
            newOrder.push({ id: id, sort_order: newSortOrder });
            
            // Update display
            $(this).find('td:first').html(`<i class="fas fa-grip-vertical text-muted me-2" style="cursor:move;"></i>${newSortOrder}`);
            
            // Update local data
            const field = metadataFields.find(f => f.id == id);
            if (field) field.sort_order = newSortOrder;
        });
        
        // Save to backend
        $.post(API_URL, {
            action: 'update_metadata_sort',
            project_type: currentType,
            order: JSON.stringify(newOrder)
        }, function(res) {
            if (!res.success) {
                alert('Failed to update sort order');
                loadMetadata(); // Reload on failure
            }
        });
    }

    function openMetaModal(field = null) {
        if (field) {
            $('#metaId').val(field.id);
            $('#metaLabel').val(field.field_label);
            $('#metaKey').val(field.field_key).prop('readonly', true); // Key cannot change
            $('#metaOptions').val((field.options || []).join('\n'));
            $('#metaActive').prop('checked', field.is_active == 1);
        } else {
            $('#metaId').val('');
            $('#metaLabel').val('');
            $('#metaKey').val('').prop('readonly', false);
            $('#metaOptions').val('');
            $('#metaActive').prop('checked', true);
        }
        new bootstrap.Modal('#metadataModal').show();
    }

    $('#btnAddField').click(() => openMetaModal());

    $('#btnSaveMeta').click(function() {
        const data = {
            action: 'save_metadata',
            project_type: currentType,
            id: $('#metaId').val(),
            field_label: $('#metaLabel').val(),
            field_key: $('#metaKey').val(),
            options: $('#metaOptions').val(),
            is_active: $('#metaActive').is(':checked') ? 1 : 0
        };

        $.post(API_URL, data, function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance('#metadataModal').hide();
                loadMetadata();
                // Reload presets metadata form if open
                if ($('#presetForm').is(':visible')) {
                    const currentValues = {}; // Should ideally preserve current edit values
                    renderPresetMetadataForm(currentValues);
                }
            } else {
                alert(res.error);
            }
        });
    });

    function deleteMeta(id) {
        confirmModal('Are you sure? This will remove this field from all new issues matching this project type.', function() {
            $.post(API_URL, { action: 'delete_metadata', id: id }, function(res) {
                 if(res.success) loadMetadata();
                 else alert(res.error);
            });
        });
    }

    // --- DEFAULTS HANDLING ---
    function loadDefaults() {
        $.get(API_URL, { action: 'get_defaults', project_type: currentType }, function(res) {
            if (res.success && res.data) {
                defaultSections = res.data.sections || [];
                renderDefaults();
            } else {
                defaultSections = [];
                renderDefaults();
            }
        });
    }

    function renderDefaults() {
        const $con = $('#defaultSectionsList');
        $con.empty();
        defaultSections.forEach((sec, idx) => {
            const $tag = $('<span class="badge bg-light text-dark border me-2 mb-2 p-2" style="cursor:move;">');
            $tag.attr('data-section', sec);
            $tag.html('<i class="fas fa-grip-vertical text-muted me-2"></i>[' + sec + ']');
            const $remove = $('<i class="fas fa-times ms-2 text-danger cursor-pointer"></i>');
            $remove.click(() => {
                defaultSections.splice(idx, 1);
                renderDefaults();
            });
            $tag.append($remove);
            $con.append($tag);
        });
        
        // Initialize SortableJS for sections
        if (window.Sortable && !$con.data('sortable-initialized')) {
            Sortable.create($con[0], {
                animation: 150,
                handle: '.fa-grip-vertical',
                onEnd: function(evt) {
                    updateSectionsOrder();
                }
            });
            $con.data('sortable-initialized', true);
        }
    }
    
    function updateSectionsOrder() {
        const $badges = $('#defaultSectionsList .badge');
        const newSections = [];
        $badges.each(function() {
            const section = $(this).attr('data-section');
            if (section) newSections.push(section);
        });
        defaultSections = newSections;
    }

    $('#btnAddSection').click(() => {
        const val = $('#newSectionInput').val().trim();
        if (val) {
            // Remove brackets if user added them
            const clean = val.replace(/^\[|\]$/g, '');
            if (!defaultSections.includes(clean)) {
                defaultSections.push(clean);
                renderDefaults();
                $('#newSectionInput').val('');
            }
        }
    });

    $('#btnSaveDefaults').click(() => {
        $.post(API_URL, { 
            action: 'save_defaults', 
            project_type: currentType,
            sections_json: JSON.stringify(defaultSections) 
        }, function(res) {
            if(res.success) showToast('Defaults saved', 'success');
            else showToast('Error saving defaults', 'danger');
        });
    });
    
    // --- CSV IMPORT ---
    let csvHeaders = [];
    let csvFile = null;

    $('#btnImportCsv').click(function() {
        const file = $('#csvFile')[0].files[0];
        if (!file) {
            alert('Please select a CSV file first.');
            return;
        }
        
        csvFile = file;
        const reader = new FileReader();
        reader.onload = function(e) {
            const text = e.target.result;
            const firstLine = text.split('\n')[0];
            // Simple comma split for headers (handles some quotes)
            csvHeaders = firstLine.split(',').map(h => h.trim().replace(/^"|"$/g, ''));
            
            if (csvHeaders.length === 0 || !csvHeaders[0]) {
                alert('Could not read headers from CSV.');
                return;
            }
            
            showMappingModal();
        };
        reader.readAsText(file);
    });

    function showMappingModal() {
        const $tbody = $('#mappingTableBody');
        $tbody.empty();
        
        const fieldsToMap = [
            { label: 'Preset Title', key: 'title', required: true },
            { label: 'Description (HTML)', key: 'desc', required: false },
            { label: 'Sections', key: 'sections', required: false }
        ];
        
        // Add metadata fields
        metadataFields.forEach(f => {
            fieldsToMap.push({ label: f.field_label, key: 'meta:' + f.field_key, isMeta: true });
        });
        
        // Base common sections if not in defaultSections (for better mapping options)
        const commonSecs = ['Actual Result', 'Incorrect Code', 'Screenshot', 'Recommendation', 'Correct Code'];
        const uniqueSections = [...new Set([...defaultSections, ...commonSecs])];
        
        uniqueSections.forEach(s => {
            fieldsToMap.push({ label: 'Section: [' + s + ']', key: 'section:' + s, sectionName: s });
        });
        
        csvHeaders.forEach((h, idx) => {
            const $tr = $('<tr>');
            $tr.append(`<td><strong>${h}</strong></td>`);
            
            const $sel = $('<select class="form-select form-select-sm mapping-select">')
                .attr('data-column-index', idx);
            
            $sel.append('<option value="">-- Ignore --</option>');
            fieldsToMap.forEach(f => {
                const hLower = h.toLowerCase().trim();
                const labelLower = f.label.toLowerCase();
                const keyLower = f.key.toLowerCase();
                
                // Smart auto-match
                let selected = (hLower === labelLower || hLower === keyLower);
                
                // Match "Actual Result" to "Section: [Actual Result]"
                if (!selected && f.sectionName) {
                    const sLower = f.sectionName.toLowerCase();
                    if (hLower === sLower || hLower === '[' + sLower + ']' || hLower.includes(sLower)) {
                        selected = true;
                    }
                }
                
                // Generic sections match
                if (!selected && f.key === 'sections' && (hLower.includes('section') || hLower === 'sections')) {
                    selected = true;
                }
                
                const $opt = $('<option>').val(f.key).text(f.label).prop('selected', selected);
                $sel.append($opt);
            });
            
            $tr.append($('<td>').append($sel));
            $tbody.append($tr);
        });
        
        new bootstrap.Modal('#csvMappingModal').show();
    }

    $('#btnConfirmImport').click(function() {
        const mapping = {};
        let titleMapped = false;
        
        $('.mapping-select').each(function() {
            const fieldKey = $(this).val();
            const colIdx = $(this).data('column-index');
            
            if (fieldKey !== "") {
                if (fieldKey === 'sections' || fieldKey.startsWith('section:')) {
                    if (!mapping['sections']) mapping['sections'] = {};
                    // If it's a specific section, map it to that title
                    if (fieldKey.startsWith('section:')) {
                        const sectionTitle = fieldKey.replace('section:', '');
                        mapping['sections'][sectionTitle] = colIdx;
                    } else {
                        // Generic sections mapping (fallback/multi-select)
                        if (!mapping['sections']['_generic']) mapping['sections']['_generic'] = [];
                        mapping['sections']['_generic'].push(colIdx);
                    }
                } else {
                    mapping[fieldKey] = colIdx;
                }
                if (fieldKey === 'title') titleMapped = true;
            }
        });
        
        if (!titleMapped) {
            alert('Please map the "Preset Title" field.');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'import_csv');
        fd.append('project_type', currentType);
        fd.append('csv', csvFile);
        fd.append('mapping', JSON.stringify(mapping));
        
        const $btn = $(this);
        $btn.prop('disabled', true).text('Importing...');
        
        $.ajax({
            url: API_URL,
            type: 'POST',
            data: fd,
            contentType: false,
            processData: false,
            success: function(res) {
                $btn.prop('disabled', false).text('Start Import');
                if (res.success) {
                    alert('Imported ' + res.imported + ' presets.');
                    bootstrap.Modal.getInstance('#csvMappingModal').hide();
                    loadPresets();
                    $('#csvFile').val('');
                } else {
                    alert('Import failed: ' + res.error);
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Start Import');
                alert('Request failed');
            }
        });
    });

    // Validations to prevent 'system_X' keys unless handled
    $('#metaKey').on('input', function() {
        this.value = this.value.replace(/[^a-zA-Z0-9_]/g, '').toLowerCase();
    });

    loadAllData();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
