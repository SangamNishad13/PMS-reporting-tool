/**
 * view_issues-interactions.js
 * User interactions, event handlers, and real-time updates
 */

window.IssuesInteractions = (function() {
    'use strict';

    // Event handlers setup
    var eventHandlers = {
        setupPageSelection: function() {
            var pageSelect = document.getElementById('finalIssuePages');
            if (!pageSelect) return;

            pageSelect.addEventListener('change', function() {
                var selectedPageId = this.value;
                if (selectedPageId) {
                    IssuesCore.data.selectedPageId = selectedPageId;
                    IssuesCore.permissions.updateEditingState();
                    IssuesCore.utils.dispatchIssuesChanged({ 
                        type: 'page_selected', 
                        pageId: selectedPageId 
                    });
                }
            });
        },

        setupFormValidation: function() {
            var form = document.getElementById('finalIssueForm');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                eventHandlers.handleFormSubmit();
            });

            // Real-time validation
            var requiredFields = form.querySelectorAll('[required]');
            requiredFields.forEach(function(field) {
                field.addEventListener('blur', function() {
                    eventHandlers.validateField(field);
                });
            });
        },

        setupModalEvents: function() {
            var finalIssueModalEl = document.getElementById('finalIssueModal');
            if (!finalIssueModalEl) return;

            finalIssueModalEl.addEventListener('shown.bs.modal', function () {
                IssuesCore.permissions.applyIssueQaPermissionState();
                var currentIssueId = (document.getElementById('finalIssueEditId') || {}).value;
                if (currentIssueId) {
                    eventHandlers.startIssuePresenceTracking(currentIssueId);
                }

                // Handle client_ready checkbox behavior for edits
                var editId = document.getElementById('finalIssueEditId').value;
                var clientReadyCheckbox = document.getElementById('finalIssueClientReady');
                if (editId && clientReadyCheckbox) {
                    eventHandlers.setupClientReadyWatcher(clientReadyCheckbox);
                }
            });

            finalIssueModalEl.addEventListener('hidden.bs.modal', function () {
                eventHandlers.stopIssuePresenceTracking();
                IssuesUtilities.modalUtils.cleanupModalOverlayState();
            });
        },

        setupClientReadyWatcher: function(clientReadyCheckbox) {
            var initialClientReady = clientReadyCheckbox.checked;
            
            var uncheckClientReady = function() {
                if (clientReadyCheckbox && initialClientReady) {
                    clientReadyCheckbox.checked = false;
                }
            };
            
            var fieldsToWatch = [
                'finalIssueTitle',
                'finalIssueDetails',
                'finalIssueStatus',
                'finalIssueSeverity',
                'finalIssuePriority',
                'finalIssueCommonTitle'
            ];
            
            fieldsToWatch.forEach(function(fieldId) {
                var field = document.getElementById(fieldId);
                if (field) {
                    field.removeEventListener('input', uncheckClientReady);
                    field.removeEventListener('change', uncheckClientReady);
                    field.addEventListener('input', uncheckClientReady);
                    field.addEventListener('change', uncheckClientReady);
                }
            });
            
            // Watch Summernote editor
            if (window.jQuery && jQuery.fn.summernote) {
                jQuery('#finalIssueDetails').off('summernote.change').on('summernote.change', uncheckClientReady);
            }
            
            // Watch Select2 fields
            if (window.jQuery && jQuery.fn.select2) {
                jQuery('#finalIssuePages, #finalIssueReporters, #finalIssueGroupedUrls, #finalIssueQaStatus')
                    .off('change.uncheckClientReady')
                    .on('change.uncheckClientReady', uncheckClientReady);
            }
        },

        handleFormSubmit: function() {
            var form = document.getElementById('finalIssueForm');
            if (!form) return;

            if (!this.validateForm()) {
                IssuesCore.utils.issueNotify('Please fill in all required fields.', 'warning');
                return;
            }

            var formData = this.collectFormData();
            var editId = document.getElementById('finalIssueEditId').value;
            var isEdit = !!editId;

            this.saveIssue(formData, isEdit);
        },

        validateField: function(field) {
            var isValid = field.checkValidity();
            field.classList.toggle('is-invalid', !isValid);
            
            var feedback = field.parentNode.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.textContent = isValid ? '' : field.validationMessage;
            }
            
            return isValid;
        },

        validateForm: function() {
            var form = document.getElementById('finalIssueForm');
            if (!form) return false;

            var isValid = true;
            var requiredFields = form.querySelectorAll('[required]');
            
            requiredFields.forEach(function(field) {
                if (!eventHandlers.validateField(field)) {
                    isValid = false;
                }
            });

            return isValid;
        },

        collectFormData: function() {
            var formData = new FormData();
            var editId = document.getElementById('finalIssueEditId').value;
            var selectedPageId = IssuesCore.data.selectedPageId;

            // Basic fields
            formData.append('title', document.getElementById('finalIssueTitle').value || '');
            formData.append('details', this.getDetailsValue());
            formData.append('severity', document.getElementById('finalIssueSeverity').value || '');
            formData.append('priority', document.getElementById('finalIssuePriority').value || '');
            formData.append('status', document.getElementById('finalIssueStatus').value || '');
            formData.append('qa_status', document.getElementById('finalIssueQaStatus').value || '');
            formData.append('client_ready', document.getElementById('finalIssueClientReady').checked ? '1' : '0');

            // Project and page info
            formData.append('project_id', IssuesCore.config.projectId);
            if (editId) formData.append('id', editId);
            formData.append('page_id', selectedPageId);

            // URLs and reporters
            var pagesSelect = document.getElementById('finalIssuePages');
            if (pagesSelect && pagesSelect.multiple) {
                var selectedPages = Array.from(pagesSelect.selectedOptions).map(opt => opt.value);
                formData.append('pages', selectedPages.join(','));
            }

            var reportersSelect = document.getElementById('finalIssueReporters');
            if (reportersSelect && reportersSelect.multiple) {
                var selectedReporters = Array.from(reportersSelect.selectedOptions).map(opt => opt.value);
                formData.append('reporters', selectedReporters.join(','));
            }

            // Metadata
            var metadata = this.collectMetadata();
            formData.append('metadata', JSON.stringify(metadata));

            return formData;
        },

        getDetailsValue: function() {
            var detailsField = document.getElementById('finalIssueDetails');
            if (window.jQuery && jQuery.fn.summernote && jQuery(detailsField).summernote('code')) {
                return jQuery(detailsField).summernote('code');
            }
            return detailsField.value || '';
        },

        collectMetadata: function() {
            var metadata = {};
            
            // Collect dynamic metadata fields
            var metaFields = document.querySelectorAll('.issue-dynamic-field');
            metaFields.forEach(function(field) {
                var key = field.getAttribute('data-meta-key');
                var value = field.value || '';
                if (key && value) {
                    metadata[key] = value;
                }
            });

            return metadata;
        },

        saveIssue: function(formData, isEdit) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', IssuesCore.config.issuesApiBase);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            IssuesCore.utils.issueNotify(
                                isEdit ? 'Issue updated successfully!' : 'Issue created successfully!', 
                                'success'
                            );
                            IssuesModals.modalManager.closeFinalEditor();
                            // Refresh issues list
                            IssuesCore.utils.dispatchIssuesChanged({ 
                                type: isEdit ? 'issue_updated' : 'issue_created',
                                issue: response.issue 
                            });
                        } else {
                            IssuesCore.utils.issueNotify(response.message || 'Save failed', 'error');
                        }
                    } catch (e) {
                        IssuesCore.utils.issueNotify('Invalid response from server', 'error');
                    }
                } else {
                    IssuesCore.utils.issueNotify('Server error occurred', 'error');
                }
            };
            xhr.onerror = function() {
                IssuesCore.utils.issueNotify('Network error occurred', 'error');
            };
            xhr.send(formData);
        }
    };

    // Real-time presence tracking
    var presenceTracking = {
        timer: null,
        issueId: null,
        sessionToken: null,

        startIssuePresenceTracking: function(issueId) {
            this.stopIssuePresenceTracking();
            this.issueId = issueId;
            this.sessionToken = this.generateSessionToken();
            
            this.timer = setInterval(function() {
                presenceTracking.sendPresencePing();
            }, IssuesCore.data.ISSUE_PRESENCE_PING_MS);
        },

        stopIssuePresenceTracking: function() {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
            this.issueId = null;
            this.sessionToken = null;
        },

        generateSessionToken: function() {
            return Math.random().toString(36).substr(2, 9);
        },

        sendPresencePing: function() {
            if (!this.issueId || !this.sessionToken) return;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', IssuesCore.config.issuesApiBase);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.conflict) {
                            presenceTracking.handleConflict(response);
                        }
                    } catch (e) {
                        // Ignore presence ping errors
                    }
                }
            };
            
            var params = 'action=ping_presence&issue_id=' + this.issueId + 
                        '&session_token=' + this.sessionToken;
            xhr.send(params);
        },

        handleConflict: function(response) {
            if (response.fresh_issue) {
                IssuesUtilities.modalUtils.showIssueConflictDialog(
                    response.message || 'This issue was modified by another user.',
                    function () {
                        IssuesModals.modalManager.openFinalEditor(response.fresh_issue, { 
                            skipShow: IssuesModals.modalState.isEditorOpen 
                        });
                    }
                );
            }
        }
    };

    // Issue loading and rendering
    var issueLoader = {
        loadFinalIssues: function(pageId) {
            if (!pageId) return;

            var xhr = new XMLHttpRequest();
            xhr.open('GET', IssuesCore.config.issuesApiBase + 
                     '?action=list&page_id=' + pageId + '&type=final');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            IssuesCore.data.pages[pageId] = IssuesCore.data.pages[pageId] || {};
                            IssuesCore.data.pages[pageId].final = response.issues || [];
                            issueLoader.renderFinalIssues();
                        }
                    } catch (e) {
                        console.error('Failed to load issues:', e);
                    }
                }
            };
            xhr.send();
        },

        renderFinalIssues: function() {
            var tbody = document.getElementById('finalIssuesBody');
            if (!tbody) return;

            var pageId = IssuesCore.data.selectedPageId;
            var issues = IssuesCore.data.pages[pageId] ? IssuesCore.data.pages[pageId].final : [];
            
            var html = issues.map(function(issue) {
                return '<tr data-issue-id="' + issue.id + '">' +
                    '<td>' + IssuesModals.badges.getSeverityBadge(issue.severity) + '</td>' +
                    '<td>' + IssuesCore.utils.escapeHtml(issue.title) + '</td>' +
                    '<td>' + IssuesModals.badges.getPriorityBadge(issue.priority) + '</td>' +
                    '<td>' + IssuesModals.badges.getStatusBadge(issue.status_id, issue.status_name) + '</td>' +
                    '<td>' + IssuesModals.badges.getQaBadge(issue.qa_status) + '</td>' +
                    '<td>' + IssuesModals.badges.getClientReadyBadge(issue.client_ready) + '</td>' +
                    '<td>' +
                    '<div class="btn-group btn-group-sm" role="group">' +
                    '<button type="button" class="btn btn-outline-primary btn-sm" onclick="IssuesModals.openFinalViewer(' + issue.id + ')">' +
                    '<i class="fas fa-eye"></i>' +
                    '</button>' +
                    '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="IssuesModals.openFinalEditor(window.issueData.pages[\'' + pageId + '\'].final.find(i => i.id == ' + issue.id + '))">' +
                    '<i class="fas fa-edit"></i>' +
                    '</button>' +
                    '</div>' +
                    '</td>' +
                    '</tr>';
            }).join('');

            tbody.innerHTML = html || '<tr><td colspan="7" class="text-center text-muted">No issues found</td></tr>';
        }
    };

    // Public API
    return {
        eventHandlers: eventHandlers,
        presenceTracking: presenceTracking,
        issueLoader: issueLoader,
        
        // Expose main functions
        init: function() {
            eventHandlers.setupPageSelection();
            eventHandlers.setupFormValidation();
            eventHandlers.setupModalEvents();
        },

        loadFinalIssues: issueLoader.loadFinalIssues.bind(issueLoader),
        renderFinalIssues: issueLoader.renderFinalIssues.bind(issueLoader),
        startIssuePresenceTracking: presenceTracking.startIssuePresenceTracking.bind(presenceTracking),
        stopIssuePresenceTracking: presenceTracking.stopIssuePresenceTracking.bind(presenceTracking)
    };
})();
