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
                    <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetFullscreen" aria-label="Open full chat">
                        <i class="fas fa-up-right-and-down-left-from-center"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetClose" aria-label="Close chat">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <iframe src="<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo $projectId; ?>&embed=1" title="Project Chat"></iframe>
        </div>

        <button type="button" class="btn btn-primary chat-launcher" id="chatLauncher">
            <i class="fas fa-comments"></i>
            <span>Project Chat</span>
        </button>

<!-- Add Phase Modal -->
<div class="modal fade" id="addPhaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/phases.php" id="addPhaseForm">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <input type="hidden" name="phase_name" id="phaseNameHidden">
                <div class="modal-header">
                    <h5 class="modal-title">Add Project Phase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Phase Name <span class="text-danger">*</span></label>
                        <select id="phaseNameSelect" class="form-select" required>
                            <option value="">-- Select Phase --</option>
                            <?php
                            // Fetch active phases from phase_master
                            $phaseMasterStmt = $db->query("SELECT id, phase_name, typical_duration_days FROM phase_master WHERE is_active = 1 ORDER BY display_order ASC, phase_name ASC");
                            while ($pm = $phaseMasterStmt->fetch()):
                            ?>
                                <option value="<?php echo htmlspecialchars($pm['phase_name']); ?>" 
                                        data-phase-id="<?php echo $pm['id']; ?>"
                                        data-duration="<?php echo $pm['typical_duration_days'] ?: ''; ?>">
                                    <?php echo htmlspecialchars($pm['phase_name']); ?>
                                </option>
                            <?php endwhile; ?>
                            <option value="custom">-- Custom Phase Name --</option>
                        </select>
                        <small class="text-muted">Select from standard phases or choose "Custom" to enter your own</small>
                    </div>
                    <div class="mb-3" id="customPhaseNameDiv" style="display: none;">
                        <label class="form-label">Custom Phase Name <span class="text-danger">*</span></label>
                        <input type="text" id="customPhaseName" class="form-control" placeholder="Enter custom phase name">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" id="phaseStartDate" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" id="phaseEndDate" class="form-control">
                                <small class="text-muted" id="durationHint"></small>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Planned Hours</label>
                        <input type="number" name="planned_hours" class="form-control" min="0" step="0.5" placeholder="e.g., 40">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="not_started">Not Started</option>
                            <option value="in_progress">In Progress</option>
                            <option value="on_hold">On Hold</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_phase" class="btn btn-success">Add Phase</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Handle custom phase name toggle and duration calculation
document.addEventListener('DOMContentLoaded', function() {
    const phaseSelect = document.getElementById('phaseNameSelect');
    const phaseHidden = document.getElementById('phaseNameHidden');
    const customDiv = document.getElementById('customPhaseNameDiv');
    const customInput = document.getElementById('customPhaseName');
    const startDateInput = document.getElementById('phaseStartDate');
    const endDateInput = document.getElementById('phaseEndDate');
    const durationHint = document.getElementById('durationHint');
    const addPhaseForm = document.getElementById('addPhaseForm');
    
    if (phaseSelect) {
        phaseSelect.addEventListener('change', function() {
            if (this.value === 'custom') {
                customDiv.style.display = 'block';
                customInput.required = true;
                durationHint.textContent = '';
            } else {
                customDiv.style.display = 'none';
                customInput.required = false;
                customInput.value = '';
                
                // Auto-calculate end date based on typical duration
                const selectedOption = this.options[this.selectedIndex];
                const duration = selectedOption.getAttribute('data-duration');
                
                if (duration && startDateInput.value) {
                    const startDate = new Date(startDateInput.value);
                    const endDate = new Date(startDate);
                    endDate.setDate(endDate.getDate() + parseInt(duration));
                    endDateInput.value = endDate.toISOString().split('T')[0];
                    durationHint.textContent = `Typical: ${duration} days`;
                } else if (duration) {
                    durationHint.textContent = `Typical: ${duration} days`;
                } else {
                    durationHint.textContent = '';
                }
            }
        });
        
        // Auto-calculate end date when start date changes
        startDateInput.addEventListener('change', function() {
            const selectedOption = phaseSelect.options[phaseSelect.selectedIndex];
            const duration = selectedOption.getAttribute('data-duration');
            
            if (duration && this.value && phaseSelect.value !== 'custom') {
                const startDate = new Date(this.value);
                const endDate = new Date(startDate);
                endDate.setDate(endDate.getDate() + parseInt(duration));
                endDateInput.value = endDate.toISOString().split('T')[0];
            }
            
            // Set min attribute on end date
            if (this.value) {
                endDateInput.setAttribute('min', this.value);
            } else {
                endDateInput.removeAttribute('min');
            }
        });
        
        // Validate end date is not before start date
        endDateInput.addEventListener('change', function() {
            const startDate = startDateInput.value;
            const endDate = this.value;
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                
                if (end < start) {
                    alert('End date cannot be before start date');
                    this.value = '';
                }
            }
        });
    }
    
    // Handle form submission
    if (addPhaseForm) {
        addPhaseForm.addEventListener('submit', function(e) {
            // Set the hidden input value based on selection
            if (phaseSelect.value === 'custom') {
                const customName = customInput.value.trim();
                if (!customName) {
                    e.preventDefault();
                    alert('Please enter a custom phase name');
                    customInput.focus();
                    return false;
                }
                phaseHidden.value = customName;
            } else {
                phaseHidden.value = phaseSelect.value;
            }
        });
    }
});
</script>

<!-- Edit Phase Modal -->
<div class="modal fade" id="editPhaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/phases.php">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <input type="hidden" name="phase_id" id="edit_phase_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Project Phase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Phase Name</label>
                        <input type="text" id="edit_phase_name" class="form-control" readonly>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" id="edit_start_date" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" id="edit_end_date" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Planned Hours</label>
                        <input type="number" name="planned_hours" id="edit_planned_hours" class="form-control" min="0" step="0.5" placeholder="e.g., 40">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="not_started">Not Started</option>
                            <option value="in_progress">In Progress</option>
                            <option value="on_hold">On Hold</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_phase" class="btn btn-primary">Update Phase</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Asset Modal -->
<div class="modal fade" id="addAssetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/handle_asset.php" enctype="multipart/form-data">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add Project Asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Asset Name *</label>
                        <input type="text" name="asset_name" class="form-control" required placeholder="e.g., Wireframes, Project Folder">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Asset Type *</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="asset_type" id="type_link" value="link" checked autocomplete="off">
                            <label class="btn btn-outline-primary" for="type_link"><i class="fas fa-link"></i> External Link</label>

                            <input type="radio" class="btn-check" name="asset_type" id="type_file" value="file" autocomplete="off">
                            <label class="btn btn-outline-primary" for="type_file"><i class="fas fa-file-upload"></i> Upload File</label>
                            
                            <input type="radio" class="btn-check" name="asset_type" id="type_text" value="text" autocomplete="off">
                            <label class="btn btn-outline-primary" for="type_text"><i class="fas fa-edit"></i> Text/Blog</label>
                        </div>
                    </div>

                    <!-- Link Fields -->
                    <div id="link_fields">
                        <div class="mb-3">
                            <label class="form-label">Link Type</label>
                            <input type="text" name="link_type" class="form-control" placeholder="e.g., Project Folder, Screenshot Link, etc.">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL *</label>
                            <input type="url" name="main_url" id="main_url" class="form-control" placeholder="https://...">
                        </div>
                    </div>

                    <!-- File Fields -->
                    <div id="file_fields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Select File *</label>
                            <input type="file" name="asset_file" id="asset_file" class="form-control">
                            <small class="text-muted">Allowed types: PDF, DOCX, ZIP, JPG, PNG etc.</small>
                        </div>
                    </div>
                    
                    <!-- Text/Blog Fields -->
                    <div id="text_fields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <input type="text" name="text_category" class="form-control" placeholder="e.g., Blog Post, Documentation, Notes">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Content *</label>
                            <textarea id="text_content_editor" name="text_content"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_asset" class="btn btn-primary">Add Asset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Text Content Modal -->
<div class="modal fade" id="viewTextModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTextModalTitle">Text Content</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="viewTextModalContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

            <!-- Import URLs CSV Modal -->
            <div class="modal fade" id="importUrlsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Import URLs CSV</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">CSV File</label>
                                <input type="file" accept=".csv,text/csv" id="importCsvFile" class="form-control">
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">After selecting a CSV we'll preview the first rows and let you choose which columns map to Unique Page and All URLs.</small>
                            </div>
                            <div id="csvPreviewArea" style="display:none;">
                                    <div class="mb-3">
                                    <label class="form-label fw-bold">Column Mapping</label>
                                    <p class="text-muted small mb-2">Map CSV columns to page fields. Select "-- None --" if a field is not in your CSV.</p>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Page No.</label>
                                            <select id="mapPageNumberCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Page Name</label>
                                            <select id="mapPageNameCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Unique URL <span class="text-danger">*</span></label>
                                            <select id="mapUniqueUrlCol" class="form-select form-select-sm"></select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Screen Name</label>
                                            <select id="mapScreenNameCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Notes</label>
                                            <select id="mapNotesCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Grouped URLs</label>
                                            <select id="mapGroupedUrlsCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                            <small class="text-muted">Additional URLs for this page</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive mt-3" style="max-height:300px; overflow:auto;">
                                    <table class="table table-sm table-bordered" id="csvPreviewTable">
                                        <thead></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" id="uploadCsvBtn">Upload</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Import All URLs CSV Modal -->
            <div class="modal fade" id="importAllUrlsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Import All URLs CSV</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">CSV File</label>
                                <input type="file" accept=".csv,text/csv" id="importAllCsvFile" class="form-control">
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Preview first rows and choose the column that contains URL(s). Multiple URLs in a cell can be separated by ; or |.</small>
                            </div>
                            <div id="csvAllPreviewArea" style="display:none;">
                                <div class="mb-2">
                                    <label class="form-label">Column mapping</label>
                                    <div class="row g-2">
                                        <div class="col-auto">
                                            <select id="mapAllOnlyCol" class="form-select form-select-sm" multiple size="3"></select>
                                        </div>
                                        <div class="col-auto align-self-center">→ All URLs (multiple URLs allowed)</div>
                                    </div>
                                </div>
                                <div class="table-responsive" style="max-height:300px; overflow:auto;">
                                    <table class="table table-sm table-bordered" id="csvAllPreviewTable">
                                        <thead></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" id="uploadAllCsvBtn">Upload All URLs</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Confirm Modal -->
            <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-sm">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="confirmModalTitle">Confirm</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="confirmModalBody">Are you sure?</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmModalConfirm">Yes, proceed</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assign Unique to Page Modal -->
            <div class="modal fade" id="assignUniqueModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Assign Unique Page to Project Page</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p id="assignUniqueTitle" class="fw-bold"></p>
                            <div class="mb-3">
                                <label class="form-label">Assign to page</label>
                                <select id="assignPageSelect" class="form-select">
                                    <option value="">-- Select page (or leave blank to unassign) --</option>
                                    <?php foreach ($projectPages as $pp): ?>
                                        <option value="<?php echo (int)$pp['id']; ?>"><?php echo htmlspecialchars($pp['page_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="assignUniqueConfirm">Assign</button>
                        </div>
                    </div>
                </div>
            </div>

<!-- Edit Regression Task Modal -->
<div class="modal fade" id="regEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="regEditForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Regression Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="task_id" id="reg_task_id">
                    <input type="hidden" id="reg_page_id_for_modal" name="page_id">
                    <input type="hidden" id="reg_env_id_for_modal" name="environment_id">
                    <div class="mb-2">
                        <label class="form-label">Title</label>
                        <input name="title" id="reg_title" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="reg_description" class="form-control"></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Assigned User</label>
                            <select name="assigned_user_id" id="reg_assigned_user_id" class="form-select">
                                <option value="">(unassigned)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phase</label>
                            <input name="phase" id="reg_phase" class="form-control">
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">Status</label>
                        <select name="status" id="reg_status" class="form-select">
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Unique Modal -->
<div class="modal fade" id="addUniqueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Unique Page</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">Name (optional)</label>
                    <input id="newUniqueName" class="form-control" placeholder="Leave empty to auto-generate (Page 11, Page 12, etc.)" />
                    <small class="form-text text-muted">If left empty, will automatically generate the next page number (e.g., Page 11)</small>
                </div>
                <div class="mb-2">
                    <label class="form-label">Canonical URL (optional)</label>
                    <input id="newUniqueCanonical" class="form-control" placeholder="https://example.com/page" />
                </div>
                <div id="addUniqueError" class="text-danger" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="createUniqueBtn">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Page Name / Notes Modal -->
<div class="modal fade" id="editPageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Page</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editPage_unique_id" value="0">
                <input type="hidden" id="editPage_page_id" value="0">
                <div class="mb-3">
                    <label class="form-label">Field</label>
                    <select id="editPage_field" class="form-select form-select-sm">
                        <option value="page_name">Page Name</option>
                        <option value="notes">Notes</option>
                    </select>
                </div>
                <div class="mb-3" id="editPage_input_wrap">
                    <label class="form-label" id="editPage_label">Page Name</label>
                    <input type="text" id="editPage_value" class="form-control">
                </div>
                <div class="mb-3 d-none" id="editPage_text_wrap">
                    <label class="form-label">Notes</label>
                    <textarea id="editPage_text" class="form-control" rows="4"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="editPageSaveBtn">Save</button>
            </div>
        </div>
    </div>
</div>
