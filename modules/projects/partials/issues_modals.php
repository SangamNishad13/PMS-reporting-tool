<!-- Final Issue Modal -->
<div class="modal fade" id="finalIssueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header d-block pb-2">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h5 class="modal-title mb-0" id="finalEditorTitle">Final Issue Editor</h5>
                        <div class="small text-muted">Manage issue title, details, and metadata</div>
                        <div class="small mt-1" id="finalIssuePresenceIndicator" aria-live="polite"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <!-- Compact Title Row -->
                <div class="row g-2">
                    <!-- Issue Title Container -->
                    <div class="col-lg-8">
                        <div class="issue-title-compact" id="customIssueTitleWrap">
                            <!-- Issue title input injected by JS -->
                        </div>
                    </div>
                    
                    <!-- Common Issue Title (shows when multiple pages selected) -->
                    <div class="col-lg-4">
                        <div id="finalIssueCommonTitleWrap" class="d-none">
                            <label class="form-label mb-1 small text-muted fw-bold">Common Title</label>
                            <input type="text" class="form-control form-control-sm" id="finalIssueCommonTitle" placeholder="Common title for multi-page issue">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-body pt-2">
                <input type="hidden" id="finalIssueEditId" value="">
                <input type="hidden" id="finalIssueExpectedUpdatedAt" value="">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <label class="form-label mb-1 fw-bold">Issue Details</label>
                        <div class="d-flex justify-content-end mb-1">
                            <button class="btn btn-xs btn-outline-info" id="btnResetToTemplate">
                                <i class="fas fa-undo"></i> Reset to Template
                            </button>
                        </div>
                        <textarea id="finalIssueDetails" class="issue-summernote"></textarea>

                        <!-- Chat and History Tabs -->
                        <div class="mt-4 pt-3 border-top">
                            <ul class="nav nav-tabs" id="finalIssueTabs" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link active py-2 fw-bold" id="btnShowChat" data-bs-toggle="tab" data-bs-target="#tabChat">Chat / Comments</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link py-2 fw-bold" id="btnShowHistory" data-bs-toggle="tab" data-bs-target="#tabHistory">Edit History</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link py-2 fw-bold" id="btnShowVisitHistory" data-bs-toggle="tab" data-bs-target="#tabVisitHistory">Visit History</button>
                                </li>
                            </ul>
                            <div class="tab-content mt-3">
                                <div class="tab-pane fade show active" id="tabChat">
                                    <div class="issue-chat-container">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold">Comment Type</label>
                                            <select id="finalIssueCommentType" class="form-select form-select-sm mb-2" style="max-width: 200px;">
                                                <option value="normal">Normal Comment</option>
                                                <option value="regression">Regression Comment</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <textarea id="finalIssueCommentEditor" class="issue-summernote"></textarea>
                                            <div class="small text-muted mt-1">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Type @ to mention users
                                            </div>
                                        </div>
                                        <div class="text-end mb-3">
                                            <button class="btn btn-sm btn-primary" id="finalIssueAddCommentBtn">
                                                <i class="fas fa-paper-plane me-1"></i> Add Comment
                                            </button>
                                        </div>
                                        <div id="finalIssueCommentsList" class="small text-muted border rounded p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                                            <div class="text-center py-5">
                                                <i class="fas fa-comments fa-3x mb-3 opacity-25"></i>
                                                <p>No comments yet.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="tabHistory">
                                    <div id="historyEntries" class="small border rounded p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                                        <div class="text-center py-5 text-muted">Loading history...</div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="tabVisitHistory">
                                    <div id="visitHistoryEntries" class="small border rounded p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                                        <div class="text-center py-5 text-muted">Loading visit history...</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4 issue-metadata">
                        <label class="form-label">Issue Status</label>
                        <select id="finalIssueStatus" class="form-select form-select-sm">
                            <?php foreach ($issueStatuses as $status): ?>
                                <option value="<?php echo htmlspecialchars($status['id']); ?>" style="color: <?php echo htmlspecialchars($status['color'] ?? '#6c757d'); ?>;">
                                    <?php echo htmlspecialchars($status['name'] ?? 'Unknown'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-label mt-2">QA Status (Multi-select)</label>
                        <select id="finalIssueQaStatus" class="form-select form-select-sm issue-select2-tags" multiple>
                            <?php foreach ($qaStatuses as $qs): ?>
                                <option value="<?php echo htmlspecialchars($qs['status_key']); ?>"><?php echo htmlspecialchars($qs['status_label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-label mt-2">Page Name(s)</label>
                        <select id="finalIssuePages" class="form-select form-select-sm issue-select2" multiple>
                            <?php foreach ($projectPages as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['page_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="d-grid gap-1 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnOpenUrlSelectionModal">
                                <i class="fas fa-link me-1"></i> Manage Grouped URLs
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#finalIssueGroupedUrlsPreview" aria-expanded="false">
                                <i class="fas fa-chevron-down me-1"></i> View Grouped URLs (<span id="groupedUrlsPreviewCount">0</span>)
                            </button>
                            <div class="collapse" id="finalIssueGroupedUrlsPreview">
                                <div class="border rounded p-2 bg-light small">
                                    <ul class="mb-0 ps-3" id="finalIssueGroupedUrlsPreviewList"></ul>
                                </div>
                            </div>
                            <div class="small text-muted" id="urlSelectionSummary">Pages: 0 | Grouped URLs: 0 selected</div>
                        </div>
                        <div class="d-none" aria-hidden="true">
                            <select id="finalIssueGroupedUrls" class="form-select form-select-sm issue-select2" multiple></select>
                        </div>
                        <label class="form-label mt-2">Reporter Name(s)</label>
                        <select id="finalIssueReporters" class="form-select form-select-sm issue-select2" multiple>
                            <?php foreach ($projectUsers as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <!-- Dynamic Metadata Container -->
                        <div id="finalIssueMetadataContainer"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="finalIssueSaveBtn">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- URLs Selection Modal -->
<div class="modal fade" id="urlSelectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage Page Name(s) & Grouped URLs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-bold">Page Name(s)</label>
                <select id="urlModalPages" class="form-select issue-select2" multiple></select>
                <div class="form-text mb-3">Select one or multiple pages for this issue.</div>

                <label class="form-label fw-bold">Grouped URLs</label>
                <select id="urlModalGroupedUrls" class="form-select issue-select2-tags" multiple></select>
                <div class="form-text">Search, select, or type custom URL and press Enter to add it.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="btnCopyGroupedUrls">
                    <i class="fas fa-copy me-1"></i> Copy Selected URLs
                </button>
                <button type="button" class="btn btn-primary" id="btnApplyUrlSelection" data-bs-dismiss="modal">
                    Apply
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Common Issue Modal -->
<div class="modal fade" id="commonIssueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="commonEditorTitle">New Common Issue</h5>
                    <div class="small text-muted">Title + pages + details.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="commonIssueEditId" value="">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label">Common Issue Title</label>
                        <input type="text" class="form-control" id="commonIssueTitle" placeholder="Common issue title">
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label">Page Name(s)</label>
                        <select id="commonIssuePages" class="form-select issue-select2" multiple>
                            <?php foreach ($projectPages as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['page_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Details</label>
                        <textarea id="commonIssueDetails" class="issue-summernote"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="commonIssueSaveBtn">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Issue Image Modal -->
<div class="modal fade issue-image-modal" id="issueImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Image Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center">
                    <img id="issueImagePreview" src="" alt="" class="img-fluid" style="max-height: 70vh;">
                </div>
                <div id="issueImageAltText" class="mt-3 p-3 bg-light rounded" style="display: none;">
                    <strong>Alt Text:</strong> <span id="issueImageAltTextContent"></span>
                </div>
            </div>
        </div>
    </div>
</div>
