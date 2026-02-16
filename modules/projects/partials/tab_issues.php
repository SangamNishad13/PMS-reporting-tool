        <!-- Issues Tab -->
        <div class="tab-pane fade" id="issues" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <h5 class="mb-0">Issues</h5>
                    <div class="small text-muted">Pages-wise final issues and automated review findings.</div>
                </div>
            </div>

            <ul class="nav nav-tabs mb-3" id="issuesSubTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="issues-pages-tab" data-bs-toggle="tab" data-bs-target="#issues_pages" type="button">Pages</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="issues-common-tab" data-bs-toggle="tab" data-bs-target="#issues_common" type="button">Common Issues</button>
                </li>
            </ul>

            <div class="tab-content" id="issuesSubTabContent">
                <div class="tab-pane fade show active" id="issues_pages" role="tabpanel">
                    <div class="row g-3" id="issuesPagesRow">
                        <div class="col-lg-12" id="issuesPagesCol">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold">Pages</span>
                                    <span class="text-muted small"><?php echo count($uniqueIssuePages); ?> total</span>
                                </div>
                                <div class="card-body border-bottom">
                                    <div class="d-flex flex-wrap gap-3">
                                        <div>
                                            <div class="text-muted small">Total Pages</div>
                                            <div class="fw-semibold"><?php echo (int)$issuesPagesCount; ?></div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Total Issues</div>
                                            <div class="fw-semibold"><?php echo (int)$issuesTotalCount; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive" id="issuesPageList">
                                    <table class="table table-hover table-sm align-middle mb-0 resizable-table">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 40px;">#<div class="col-resizer"></div></th>
                                                <th>Page Name<div class="col-resizer"></div></th>
                                                <th style="width: 100px;">Page No<div class="col-resizer"></div></th>
                                                <th style="width: 100px;">Issues<div class="col-resizer"></div></th>
                                                <th style="width: 150px;">Tester<div class="col-resizer"></div></th>
                                                <th style="width: 120px;">Environment<div class="col-resizer"></div></th>
                                                <th style="width: 120px;">Prod Hours<div class="col-resizer"></div></th>
                                                <th style="width: 120px;">Grouped URLs</th>
                                            </tr>
                                        </thead>
                                        <tbody>
    <?php if (!empty($uniqueIssuePages)): 
        $rowNum = 1;
        foreach ($uniqueIssuePages as $u):
        $mappedPageId = (int)($u['mapped_page_id'] ?? 0);
        $sum = $mappedPageId ? ($issuePageSummaries[$mappedPageId] ?? []) : [];
        $tester = trim($sum['testers'] ?? "");
        $envs = trim($sum['envs'] ?? "");
        $count = isset($sum['issues_count']) ? (int)$sum['issues_count'] : 0;
        $prodHours = isset($sum['production_hours']) ? (float)$sum['production_hours'] : 0;
        $uniqueLabel = $u['canonical_url'] ?: ($u['unique_name'] ?? "");
        $pageNoLabel = $u['mapped_page_number'] ?? "";
        $displayName = $u['mapped_page_name'] ?? "";
        if (!$displayName) { $displayName = $u['unique_name'] ?? $uniqueLabel; }
        $pageUrls = $urlsByUniqueId[$u['unique_id']] ?? [];
        $hasUrls = !empty($pageUrls);
        $urlCount = count($pageUrls);
    ?>
                                            <tr class="issues-page-row" 
                                                data-unique-id="<?php echo (int)$u['unique_id']; ?>"
                                                data-page-id="<?php echo (int)$mappedPageId; ?>"
                                                data-page-name="<?php echo htmlspecialchars($displayName); ?>"
                                                data-page-tester="<?php echo htmlspecialchars($tester ?: '-'); ?>"
                                                data-page-env="<?php echo htmlspecialchars($envs ?: '-'); ?>"
                                                data-page-issues="<?php echo $count; ?>"
                                                style="cursor: pointer;">
                                                <td class="text-muted"><?php echo $rowNum++; ?></td>
                                                <td>
                                                    <div class="fw-semibold text-primary"><?php echo htmlspecialchars($displayName); ?></div>
                                                    <div class="small text-muted text-truncate" style="max-width: 300px;" title="<?php echo htmlspecialchars($uniqueLabel); ?>">
                                                        <?php echo htmlspecialchars($uniqueLabel ?: '-'); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary-subtle text-primary">
                                                        <?php echo htmlspecialchars($pageNoLabel ?: '-'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $count > 0 ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary'; ?>">
                                                        <?php echo $count; ?>
                                                    </span>
                                                </td>
                                                <td class="small"><?php echo htmlspecialchars($tester ?: '-'); ?></td>
                                                <td class="small"><?php echo htmlspecialchars($envs ?: '-'); ?></td>
                                                <td class="small"><?php echo number_format($prodHours, 2); ?> hrs</td>
                                                <td>
                                                    <?php if ($hasUrls): ?>
                                                    <button class="btn btn-xs btn-outline-secondary" 
                                                            type="button" 
                                                            data-bs-toggle="collapse" 
                                                            data-bs-target="#urls-<?php echo (int)$u['unique_id']; ?>" 
                                                            aria-expanded="false"
                                                            onclick="event.stopPropagation();">
                                                        <i class="fas fa-link me-1"></i> <?php echo $urlCount; ?>
                                                    </button>
                                                    <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($hasUrls): ?>
                                            <tr class="collapse" id="urls-<?php echo (int)$u['unique_id']; ?>">
                                                <td colspan="8" class="p-0 border-0">
                                                    <div class="bg-light p-3 border-top">
                                                        <div class="small fw-bold text-muted mb-2">
                                                            <i class="fas fa-link me-1"></i> Grouped URLs (<?php echo $urlCount; ?>)
                                                        </div>
                                                        <ul class="list-unstyled mb-0 small">
                                                            <?php foreach ($pageUrls as $pUrl): ?>
                                                            <li class="mb-1 text-break">
                                                                <i class="fas fa-angle-right text-muted me-2"></i>
                                                                <?php echo htmlspecialchars($pUrl['url']); ?>
                                                            </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
    <?php endforeach; else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-5">
                                                    <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                                                    <div>No unique pages added yet.</div>
                                                </td>
                                            </tr>
    <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
</div>
</div>

<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js"></script>
<div class="col-lg-12 d-none" id="issuesDetailCol">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary d-none mb-1" id="issuesBackBtn"><i class="fas fa-arrow-left"></i> Back</button>
                                        <div class="fw-semibold" id="issueSelectedPageName">Select a page</div>
                                        <div class="small text-muted" id="issueSelectedPageMeta">Tester: - | Env: - | Issues: -</div>
                                    </div>
                                    <div class="d-flex gap-2 issues-actions">
                                        <button class="btn btn-sm btn-outline-primary" id="issueAddFinalBtn" disabled>
                                            <i class="fas fa-plus"></i> Add Issue
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Page URLs Card -->
                                <div class="card mb-3 mx-3 mt-3" id="pageUrlsCard" style="display: none; border-left: 3px solid #0d6efd;">
                                    <div class="card-header bg-light" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#pageUrlsList">
                                        <i class="fas fa-chevron-right" id="urlsToggleIcon" style="transition: transform 0.3s ease;"></i>
                                        <i class="fas fa-link ms-2"></i>
                                        <strong>Page URLs</strong>
                                        <span class="badge bg-secondary ms-2" id="urlsCount">0</span>
                                    </div>
                                    <div class="collapse" id="pageUrlsList">
                                        <div class="card-body">
                                            <ul class="list-unstyled mb-0" id="pageUrlsListContent">
                                                <!-- URLs will be populated here -->
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="card-body">
                                    <ul class="nav nav-tabs mb-3" id="pageIssueTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" id="final-issues-tab" data-bs-toggle="tab" data-bs-target="#final_issues_tab" type="button">Final Issues <span class="badge bg-secondary ms-1" id="finalIssuesCountBadge">0</span></button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="review-issues-tab" data-bs-toggle="tab" data-bs-target="#review_issues_tab" type="button">Needs Review <span class="badge bg-secondary ms-1" id="reviewIssuesCountBadge">0</span></button>
                                        </li>
                                    </ul>

            <div class="tab-content">
                                        <div class="tab-pane fade show active" id="final_issues_tab" role="tabpanel">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div class="small text-muted">Issues added by users for the final report.</div>
                                                <div class="d-flex gap-2 issue-expand-actions">
                                                    <button class="btn btn-sm btn-outline-secondary" id="finalDeleteSelected" disabled>Delete Selected</button>
                                                </div>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width:30px;"><input type="checkbox" id="finalSelectAll"></th>
                                                            <th style="width:80px;">Issue Key</th>
                                                            <th>Issue Title</th>
                                                            <th style="width:100px;">Severity</th>
                                                            <th style="width:100px;">Priority</th>
                                                            <th style="width:120px;">Status</th>
                                                            <th style="width:120px;">QA Status</th>
                                                            <th style="width:120px;">Reporter</th>
                                                            <th style="width:120px;">QA Name</th>
                                                            <th style="width:150px;">Pages</th>
                                                            <th style="width:120px;">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="finalIssuesBody">
                                                        <tr><td colspan="11" class="text-muted text-center">Select a page to view issues.</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                        </div>

                                        <div class="tab-pane fade" id="review_issues_tab" role="tabpanel">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div class="small text-muted">Automated tool findings to review.</div>
                                                <div class="d-flex gap-2">
                                                    <button class="btn btn-sm btn-primary" id="reviewRunScanBtn" disabled>Run Auto Scan</button>
                                                    <span class="small text-muted align-self-center" id="reviewScanProgress" aria-live="polite"></span>
                                                    <button class="btn btn-sm btn-outline-primary" id="reviewMoveSelected" disabled>Move to Final Report</button>
                                                    <button class="btn btn-sm btn-outline-secondary" id="reviewDeleteSelected" disabled>Delete Selected</button>
                                                    <button class="btn btn-sm btn-outline-secondary" id="reviewAddBtn" disabled>Add Tool Issue</button>
                                                </div>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead>
                                                        <tr>
                                                            <th style="width:30px;"><input type="checkbox" id="reviewSelectAll"></th>
                                                            <th>Title</th>
                                                            <th>Source URL</th>
                                                            <th>Instance</th>
                                                            <th>Rule</th>
                                                            <th>Impact</th>
                                                            <th>WCAG</th>
                                                            <th>Severity</th>
                                                            <th>Recommendation</th>
                                                            <th style="width:110px;">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="reviewIssuesBody">
                                                        <tr><td colspan="10" class="text-muted text-center">Select a page to view tool findings.</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div id="reviewPagination" class="px-1 py-2"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="issues_common" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="mb-0">Common Issues</h6>
                            <div class="small text-muted">Manage issues that apply to multiple pages.</div>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" id="commonAddBtn"><i class="fas fa-plus"></i> Add Common Issue</button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th style="width:30px;"><input type="checkbox" id="commonSelectAll"></th>
                                    <th>Common Issue Title</th>
                                    <th>Pages</th>
                                    <th style="width:110px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="commonIssuesBody">
                                <tr><td colspan="4" class="text-muted text-center">No common issues added yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info small mt-3 mb-0">
                        Tip: If a final issue applies to more than one page, fill the "Common Issue Title" field while adding it.
                    </div>
                </div>
            </div>

            <div class="modal fade" id="reviewScanConfigModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Run Automated Scan</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="small text-muted mb-2" id="reviewScanPageInfo"></div>
                            <div class="row g-2 align-items-end mb-2">
                                <div class="col-lg-8">
                                    <label class="form-label">Grouped / Unique URLs</label>
                                    <div class="d-flex gap-2 mb-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="reviewScanSelectAllBtn">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="reviewScanSelectNoneBtn">Clear</button>
                                    </div>
                                    <div id="reviewScanUrlChecklist" class="border rounded p-2" style="max-height: 220px; overflow:auto;"></div>
                                    <div class="input-group mt-2">
                                        <input type="url" id="reviewScanCustomUrl" class="form-control" placeholder="https://example.com/path">
                                        <button type="button" class="btn btn-outline-secondary" id="reviewScanAddCustomBtn">Add URL</button>
                                    </div>
                                    <div class="form-text">Choose one or more URLs. You can run selected URLs one-by-one or all at once.</div>
                                </div>
                                <div class="col-lg-4 d-grid">
                                    <button type="button" class="btn btn-outline-secondary" id="reviewScanOpenIframeBtn">Open In Iframe For Login</button>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label d-block">Execution Mode</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="reviewScanRunMode" id="reviewScanModeSequential" value="sequential" checked>
                                    <label class="form-check-label" for="reviewScanModeSequential">One by one</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="reviewScanRunMode" id="reviewScanModeParallel" value="parallel">
                                    <label class="form-check-label" for="reviewScanModeParallel">All at once</label>
                                </div>
                            </div>
                            <div id="reviewScanIframeWrap" class="border rounded p-2 d-none">
                                <div class="small text-muted mb-2">If login is required, login here first and then click Start Scan.</div>
                                <iframe id="reviewScanIframe" title="Scan URL Login Frame" style="width:100%; height:420px; border:1px solid #dee2e6; border-radius:6px;" src="about:blank"></iframe>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="reviewScanStartBtn">Start Scan</button>
                        </div>
                    </div>
                </div>
            </div>

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

                                    <!-- Chat and History Tabs (Moved here) -->
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
                                            <option value="<?php echo htmlspecialchars($status['id']); ?>" style="color: <?php echo htmlspecialchars($status['color']); ?>;">
                                                <?php echo htmlspecialchars($status['name']); ?>
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

            <!-- Review Issue Modal -->
            <div class="modal fade" id="reviewIssueModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="reviewEditorTitle">New Review Issue</h5>
                                <div class="small text-muted">Automated findings with instance + WCAG failure.</div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="reviewIssueEditId" value="">
                            <input type="hidden" id="reviewIssueRuleId" value="">
                            <input type="hidden" id="reviewIssueImpact" value="">
                            <input type="hidden" id="reviewIssuePrimarySourceUrl" value="">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Issue Title</label>
                                    <input type="text" class="form-control" id="reviewIssueTitle" placeholder="Issue title">
                                </div>
                                <div class="col-lg-8">
                                    <label class="form-label">Issue Details</label>
                                    <textarea id="reviewIssueDetails" class="issue-summernote"></textarea>
                                </div>
                                <div class="col-lg-4">
                                    <div class="row g-3">
                                        <div class="col-12">
                                    <label class="form-label">Instance Name</label>
                                    <textarea class="form-control" id="reviewIssueInstance" rows="4" placeholder="Instance paths"></textarea>
                                        </div>
                                        <div class="col-12">
                                    <label class="form-label">Source URLs</label>
                                    <textarea class="form-control" id="reviewIssueSourceUrls" rows="4" placeholder="Source URLs" readonly></textarea>
                                        </div>
                                        <div class="col-12">
                                    <label class="form-label">WCAG Failure</label>
                                    <input type="text" class="form-control" id="reviewIssueWcag" placeholder="WCAG failure">
                                        </div>
                                        <div class="col-12">
                                    <label class="form-label">Severity</label>
                                    <select id="reviewIssueSeverity" class="form-select">
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-outline-primary d-none" id="reviewIssueMoveToFinalBtn" type="button">Move to Final Issue</button>
                            <button class="btn btn-primary" id="reviewIssueSaveBtn">Save</button>
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
        </div>
 <!-- end #issues tab-pane -->
