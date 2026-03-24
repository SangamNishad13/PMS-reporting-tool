/**
 * view_issues-init.js
 * Initialization, setup, and global function exposure
 */

window.IssuesInit = (function() {
    'use strict';

    // Initialize all modules
    function initializeModules() {
        // Initialize core first
        if (!IssuesCore.init()) {
            return false; // No issues elements found
        }

        // Initialize utilities (no dependencies)
        // IssuesUtilities is self-contained

        // Initialize modals
        // IssuesModals depends on IssuesCore and IssuesUtilities

        // Initialize interactions
        IssuesInteractions.init();

        return true;
    }

    // Setup Select2 components
    function setupSelect2() {
        if (!window.jQuery || !jQuery.fn.select2) return;

        // Issue title select
        var titleSelect = document.getElementById('finalIssueTitle');
        if (titleSelect) {
            jQuery(titleSelect).select2({
                ajax: {
                    url: IssuesCore.config.issueTemplatesApi,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term,
                            page: params.page || 1
                        };
                    },
                    processResults: function (data, params) {
                        return {
                            results: data.items || [],
                            pagination: {
                                more: (params.page || 1) * 30 < (data.total_count || 0)
                            }
                        };
                    },
                    cache: true
                },
                placeholder: 'Select or type issue title...',
                allowClear: true,
                tags: true,
                createTag: function(params) {
                    return {
                        id: params.term,
                        text: params.term,
                        newOption: true
                    };
                },
                templateResult: function(data) {
                    if (data.loading) return data.text;
                    if (data.newOption) {
                        return '<span class="text-success"><i class="fas fa-plus me-1"></i>' + 
                               jQuery('<div>').text(data.text).html() + '</span>';
                    }
                    return data.text;
                }
            });
        }

        // Multi-select fields
        var multiSelects = ['#finalIssuePages', '#finalIssueReporters', '#finalIssueGroupedUrls'];
        multiSelects.forEach(function(selector) {
            var element = document.querySelector(selector);
            if (element) {
                jQuery(element).select2({
                    placeholder: 'Select options...',
                    allowClear: true,
                    width: '100%'
                });
            }
        });

        // QA Status select
        var qaStatusSelect = document.getElementById('finalIssueQaStatus');
        if (qaStatusSelect) {
            jQuery(qaStatusSelect).select2({
                placeholder: 'Select QA status...',
                allowClear: true,
                width: '100%'
            });
        }
    }

    // Setup Summernote editor
    function setupSummernote() {
        if (!window.jQuery || !jQuery.fn.summernote) return;

        var detailsField = document.getElementById('finalIssueDetails');
        if (detailsField) {
            jQuery(detailsField).summernote({
                height: 200,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ],
                callbacks: {
                    onImageUpload: function(files) {
                        IssuesInteractions.eventHandlers.uploadImage(files[0]);
                    }
                }
            });
        }
    }

    // Setup URL selection modal
    function setupUrlSelectionModal() {
        var urlModal = document.getElementById('urlSelectionModal');
        if (!urlModal) return;

        // Add event listeners for URL selection
        var urlCheckboxes = urlModal.querySelectorAll('.url-checkbox');
        urlCheckboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateUrlSelectionSummary();
            });
        });
    }

    // Update URL selection summary
    function updateUrlSelectionSummary() {
        var summaryElement = document.getElementById('urlSelectionSummary');
        if (!summaryElement) return;

        var checkedBoxes = document.querySelectorAll('#urlSelectionModal .url-checkbox:checked');
        var count = checkedBoxes.length;
        
        summaryElement.textContent = count + ' URL(s) selected';
    }

    // Update grouped URLs preview
    function updateGroupedUrlsPreview() {
        var previewElement = document.getElementById('groupedUrlsPreview');
        if (!previewElement) return;

        var groupedUrlsSelect = document.getElementById('finalIssueGroupedUrls');
        if (!groupedUrlsSelect) return;

        var selectedOptions = Array.from(groupedUrlsSelect.selectedOptions);
        var urls = selectedOptions.map(function(option) {
            return option.textContent;
        });

        previewElement.innerHTML = urls.length > 0 ? 
            urls.map(function(url) { return '<div class="small">' + IssuesCore.utils.escapeHtml(url) + '</div>'; }).join('') :
            '<div class="text-muted small">No URLs selected</div>';
    }

    // Load common issues
    function loadCommonIssues() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', IssuesCore.config.issuesApiBase + '?action=list&type=common');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        IssuesCore.data.common = response.issues || [];
                        renderCommonIssues();
                    }
                } catch (e) {
                    console.error('Failed to load common issues:', e);
                }
            }
        };
        xhr.send();
    }

    // Render common issues
    function renderCommonIssues() {
        var container = document.getElementById('commonIssuesBody');
        if (!container) return;

        var issues = IssuesCore.data.common;
        var html = issues.map(function(issue) {
            return '<div class="common-issue-item" data-issue-id="' + issue.id + '">' +
                '<h6>' + IssuesCore.utils.escapeHtml(issue.title) + '</h6>' +
                '<p class="small text-muted">' + IssuesCore.utils.escapeHtml(issue.description || '') + '</p>' +
                '<button type="button" class="btn btn-sm btn-outline-primary" onclick="IssuesModals.openFinalEditor(window.issueData.common.find(i => i.id == ' + issue.id + '))">' +
                'Use This Issue' +
                '</button>' +
                '</div>';
        }).join('');

        container.innerHTML = html || '<p class="text-muted">No common issues available.</p>';
    }

    // Update issue tab counts
    function updateIssueTabCounts() {
        var pageId = IssuesCore.data.selectedPageId;
        if (!pageId) return;

        var pageData = IssuesCore.data.pages[pageId];
        if (!pageData) return;

        var finalCount = (pageData.final || []).length;
        var reviewCount = (pageData.review || []).length;

        var finalTab = document.querySelector('a[href="#final"][data-bs-toggle="tab"]');
        var reviewTab = document.querySelector('a[href="#review"][data-bs-toggle="tab"]');

        if (finalTab) {
            var finalBadge = finalTab.querySelector('.badge');
            if (finalBadge) {
                finalBadge.textContent = finalCount;
            } else {
                finalTab.innerHTML += ' <span class="badge bg-secondary">' + finalCount + '</span>';
            }
        }

        if (reviewTab) {
            var reviewBadge = reviewTab.querySelector('.badge');
            if (reviewBadge) {
                reviewBadge.textContent = reviewCount;
            } else {
                reviewTab.innerHTML += ' <span class="badge bg-secondary">' + reviewCount + '</span>';
            }
        }
    }

    // Handle issues changed events
    function handleIssuesChanged(event) {
        var detail = event.detail || {};
        var type = detail.type;

        switch (type) {
            case 'page_selected':
                IssuesInteractions.loadFinalIssues(detail.pageId);
                break;
            case 'issue_created':
            case 'issue_updated':
                IssuesInteractions.loadFinalIssues(IssuesCore.data.selectedPageId);
                updateIssueTabCounts();
                break;
        }
    }

    // Expose global functions for backward compatibility
    function exposeGlobalFunctions() {
        // Core functions
        window.loadFinalIssues = IssuesInteractions.loadFinalIssues;
        window.updateEditingState = IssuesCore.permissions.updateEditingState;
        window.loadCommonIssues = loadCommonIssues;
        window.updateIssueTabCounts = updateIssueTabCounts;

        // Modal functions
        window.openFinalEditor = IssuesModals.openFinalEditor;
        window.editFinalIssue = function(id) {
            var issue = IssuesCore.data.pages[IssuesCore.data.selectedPageId].final.find(function (i) { 
                return String(i.id) === String(id); 
            });
            if (issue) IssuesModals.openFinalEditor(issue);
        };

        // Utility functions
        window.escapeHtml = IssuesCore.utils.escapeHtml;
        window.issueNotify = IssuesCore.utils.issueNotify;
    }

    // Main initialization
    function init() {
        try {
            // Initialize all modules
            if (!initializeModules()) {
                return; // No issues functionality needed
            }

            // Setup UI components
            setupSelect2();
            setupSummernote();
            setupUrlSelectionModal();
            updateUrlSelectionSummary();
            updateGroupedUrlsPreview();

            // Load initial data
            loadCommonIssues();

            // Update initial state
            IssuesCore.permissions.updateEditingState();
            IssuesCore.permissions.applyIssueQaPermissionState();

            // Setup event listeners
            document.addEventListener('pms:issues-changed', handleIssuesChanged);

            // Expose global functions
            exposeGlobalFunctions();

            console.log('Issues modules initialized successfully');

        } catch (e) {
            console.error('Failed to initialize issues modules:', e);
            if (typeof window.showToast === 'function') {
                showToast('Issues system failed to initialize: ' + e.message, 'danger');
            }
        }
    }

    // Public API
    return {
        init: init,
        setupSelect2: setupSelect2,
        setupSummernote: setupSummernote,
        loadCommonIssues: loadCommonIssues,
        updateIssueTabCounts: updateIssueTabCounts
    };
})();

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', IssuesInit.init);
} else {
    IssuesInit.init();
}
