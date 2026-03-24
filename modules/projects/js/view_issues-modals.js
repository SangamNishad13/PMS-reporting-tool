/**
 * view_issues-modals.js
 * Modal handling, form management, and issue editor functionality
 */

window.IssuesModals = (function() {
    'use strict';

    // Modal state management
    var modalState = {
        isEditorOpen: false,
        currentEditId: null,
        isViewMode: false
    };

    // Badge rendering functions
    var badges = {
        getSeverityBadge: function(s) {
            if (!s || s === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
            s = String(s).toLowerCase();
            var colors = {
                'critical': 'danger',
                'high': 'warning',
                'medium': 'info',
                'low': 'success',
                'major': 'warning',
                'minor': 'info'
            };
            var color = colors[s] || 'secondary';
            return '<span class="badge bg-' + color + '">' + IssuesCore.utils.escapeHtml(s.toUpperCase()) + '</span>';
        },

        getPriorityBadge: function(p) {
            if (!p || p === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
            p = String(p).toLowerCase();
            var colors = {
                'critical': 'danger',
                'high': 'warning',
                'medium': 'info',
                'low': 'success'
            };
            var color = colors[p] || 'secondary';
            return '<span class="badge bg-' + color + '">' + IssuesCore.utils.escapeHtml(p.toUpperCase()) + '</span>';
        },

        getStatusBadge: function(statusId, statusLabel) {
            if (!statusId) return '<span class="badge bg-secondary">N/A</span>';
            
            if (ProjectConfig.issueStatuses) {
                var found = ProjectConfig.issueStatuses.find(function (s) {
                    if (s.id == statusId) return true;
                    if (s.name && String(s.name).toLowerCase() === String(statusId).toLowerCase()) return true;
                    return false;
                });
                if (found) {
                    var color = found.color || '#6c757d';
                    var name = statusLabel || found.name || 'Unknown';
                    if (color.startsWith('#')) {
                        return '<span class="badge" style="background-color: ' + color + '; color: white;">' + IssuesCore.utils.escapeHtml(name) + '</span>';
                    } else {
                        return '<span class="badge bg-' + color + '">' + IssuesCore.utils.escapeHtml(name) + '</span>';
                    }
                }
            }
            return '<span class="badge bg-secondary">' + IssuesCore.utils.escapeHtml(String(statusId)) + '</span>';
        },

        getQaBadge: function(q) {
            if (!q || q === 'pending' || q === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
            q = String(q).toLowerCase();
            if (q === 'pass' || q === 'passed') return '<span class="badge bg-success">PASS</span>';
            if (q === 'fail' || q === 'failed') return '<span class="badge bg-danger">FAIL</span>';
            return '<span class="badge bg-warning">' + IssuesCore.utils.escapeHtml(q.toUpperCase()) + '</span>';
        },

        getClientReadyBadge: function(clientReady) {
            if (clientReady == 1) return '<span class="badge bg-success">Yes</span>';
            return '<span class="badge bg-secondary">No</span>';
        }
    };

    // Issue content rendering
    var contentRenderer = {
        generateIssueDetailsContent: function(issue) {
            var details = this.decorateIssueImages(issue.details || '');
            if (!details) {
                details = '<p class="text-muted">No details provided.</p>';
            }
            
            return '<div class="issue-details-content">' +
                '<div class="mb-3">' + details + '</div>' +
                '<div class="row g-2 small text-muted">' +
                '<div class="col-md-6"><strong>URL:</strong> ' + IssuesCore.utils.escapeHtml(issue.url || 'N/A') + '</div>' +
                '<div class="col-md-6"><strong>Environment:</strong> ' + IssuesCore.utils.escapeHtml(issue.environment || 'N/A') + '</div>' +
                '</div>' +
                '</div>';
        },

        decorateIssueImages: function(html) {
            // This would contain image decoration logic
            return html || '';
        },

        stripHtml: function(html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html || '';
            return tmp.textContent || tmp.innerText || '';
        }
    };

    // Modal management
    var modalManager = {
        openFinalViewer: function(issue) {
            modalState.isViewMode = true;
            modalState.currentEditId = issue.id;

            // Populate view fields
            document.getElementById('finalIssueViewTitle').textContent = issue.title || 'Untitled Issue';
            document.getElementById('finalIssueViewDetails').innerHTML = this.generateIssueDetailsContent(issue);
            document.getElementById('finalIssueViewSeverity').innerHTML = badges.getSeverityBadge(issue.severity);
            document.getElementById('finalIssueViewPriority').innerHTML = badges.getPriorityBadge(issue.priority);
            document.getElementById('finalIssueViewStatus').innerHTML = badges.getStatusBadge(issue.status_id, issue.status_name);
            document.getElementById('finalIssueViewQaStatus').innerHTML = badges.getQaBadge(issue.qa_status);
            document.getElementById('finalIssueViewClientReady').innerHTML = badges.getClientReadyBadge(issue.client_ready);

            // Show/hide edit button based on permissions
            var editBtn = document.getElementById('finalIssueViewEditBtn');
            if (editBtn) {
                editBtn.style.display = IssuesCore.permissions.canEdit() ? '' : 'none';
            }

            // Load comments
            this.renderIssueComments(issue.id);
            this.loadIssueComments(issue.id);

            var modalTitle = document.getElementById('finalIssueModalLabel');
            if (modalTitle) modalTitle.textContent = 'View Issue';
            document.getElementById('finalIssueSaveBtn').classList.add('d-none');

            var footer = document.querySelector('#finalIssueModal .modal-footer');
            var editBtn = document.getElementById('finalIssueEditBtn');
            if (!editBtn && footer) {
                editBtn = document.createElement('button');
                editBtn.id = 'finalIssueEditBtn';
                editBtn.className = 'btn btn-primary';
                editBtn.innerHTML = '<i class="fas fa-edit me-1"></i>Edit';
                editBtn.addEventListener('click', function() {
                    modalManager.closeFinalViewer();
                    modalManager.openFinalEditor(issue);
                });
                footer.insertBefore(editBtn, footer.firstChild);
            } else if (editBtn) {
                editBtn.classList.remove('d-none');
            }

            // Enable chat if present
            var chatDiv = document.getElementById('issueChatContainer');
            if (chatDiv) {
                chatDiv.querySelectorAll('input, select, textarea, button').forEach(function (el) { 
                    el.disabled = false; 
                    el.classList.remove('disabled'); 
                });
                if (window.jQuery && jQuery.fn.summernote) {
                    jQuery('#finalIssueCommentEditor').summernote('enable');
                }
            }

            var modal = new bootstrap.Modal(document.getElementById('finalIssueModal'));
            modal.show();
            
            document.getElementById('finalIssueModal').addEventListener('shown.bs.modal', function onViewerShown() {
                document.getElementById('finalIssueModal').removeEventListener('shown.bs.modal', onViewerShown);
                var activeTab = document.querySelector('#finalIssueModal .nav-link.active');
                if (activeTab) activeTab.dispatchEvent(new Event('shown.bs.tab', { bubbles: true }));
            });
        },

        closeFinalViewer: function() {
            modalState.isViewMode = false;
            modalState.currentEditId = null;
            
            var modal = document.getElementById('finalIssueModal');
            if (modal) {
                var modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
        },

        openFinalEditor: async function(issue, options) {
            var opts = options || {};
            var modalEl = document.getElementById('finalIssueModal');
            if (!modalEl) return;

            IssuesUtilities.modalUtils.clearIssueConflictNotice();
            modalState.isEditorOpen = true;
            modalState.isViewMode = false;
            modalState.currentEditId = issue ? issue.id : null;

            // Setup modal for editing
            this.setupModalForEditing(issue, opts);
            
            if (!opts.skipShow) {
                var modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        },

        closeFinalEditor: function() {
            modalState.isEditorOpen = false;
            modalState.currentEditId = null;
            
            var modal = document.getElementById('finalIssueModal');
            if (modal) {
                var modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
        },

        setupModalForEditing: function(issue, options) {
            var opts = options || {};
            var isEdit = !!issue;
            var editId = isEdit ? issue.id : null;

            // Set modal title
            var modalTitle = document.getElementById('finalIssueModalLabel');
            if (modalTitle) {
                modalTitle.textContent = isEdit ? 'Edit Issue' : 'Add New Issue';
            }

            // Show/hide save button
            var saveBtn = document.getElementById('finalIssueSaveBtn');
            if (saveBtn) {
                saveBtn.classList.toggle('d-none', false);
                saveBtn.textContent = isEdit ? 'Update Issue' : 'Save Issue';
            }

            // Hide edit button in edit mode
            var editBtn = document.getElementById('finalIssueEditBtn');
            if (editBtn) {
                editBtn.classList.add('d-none');
            }

            // Populate form fields
            if (isEdit) {
                this.populateFormFromIssue(issue);
            } else {
                this.clearForm();
            }

            // Apply permissions
            IssuesCore.permissions.applyIssueQaPermissionState();
        },

        populateFormFromIssue: function(issue) {
            // Set edit ID
            var editIdField = document.getElementById('finalIssueEditId');
            if (editIdField) editIdField.value = issue.id;

            // Populate basic fields
            var titleField = document.getElementById('finalIssueTitle');
            if (titleField && titleField.tagName === 'SELECT') {
                // Handle Select2
                if (window.jQuery && jQuery.fn.select2) {
                    jQuery(titleField).val(issue.title).trigger('change');
                } else {
                    titleField.value = issue.title;
                }
            } else if (titleField) {
                titleField.value = issue.title || '';
            }

            var detailsField = document.getElementById('finalIssueDetails');
            if (detailsField && window.jQuery && jQuery.fn.summernote) {
                jQuery(detailsField).summernote('code', issue.details || '');
            } else if (detailsField) {
                detailsField.value = issue.details || '';
            }

            // Set other fields
            this.setFieldValue('finalIssueSeverity', issue.severity);
            this.setFieldValue('finalIssuePriority', issue.priority);
            this.setFieldValue('finalIssueStatus', issue.status_id);
            this.setFieldValue('finalIssueQaStatus', issue.qa_status);
            this.setFieldValue('finalIssueClientReady', issue.client_ready ? '1' : '0');
        },

        clearForm: function() {
            // Clear edit ID
            var editIdField = document.getElementById('finalIssueEditId');
            if (editIdField) editIdField.value = '';

            // Clear basic fields
            this.clearField('finalIssueTitle');
            this.clearField('finalIssueDetails');
            this.clearField('finalIssueSeverity');
            this.clearField('finalIssuePriority');
            this.clearField('finalIssueStatus');
            this.clearField('finalIssueQaStatus');
            this.clearField('finalIssueClientReady');
        },

        setFieldValue: function(fieldId, value) {
            var field = document.getElementById(fieldId);
            if (!field) return;

            if (field.tagName === 'SELECT') {
                if (window.jQuery && jQuery.fn.select2) {
                    jQuery(field).val(value).trigger('change');
                } else {
                    field.value = value || '';
                }
            } else if (field.type === 'checkbox') {
                field.checked = !!value;
            } else {
                field.value = value || '';
            }
        },

        clearField: function(fieldId) {
            this.setFieldValue(fieldId, '');
        },

        toggleFinalIssueFields: function(enable) {
            var form = document.getElementById('finalIssueModal');
            if (!form) return;
            form.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.type === 'hidden') return;
                el.disabled = !enable;
            });
        },

        isIssueEditorOpen: function() {
            var modal = document.getElementById('finalIssueModal');
            return !!(modal && modal.classList.contains('show'));
        },

        renderIssueComments: function(issueId) {
            // Comments rendering logic
            var commentsContainer = document.getElementById('issueCommentsContainer');
            if (!commentsContainer) return;

            var comments = IssuesCore.data.comments[issueId] || [];
            var html = comments.map(function(comment) {
                return '<div class="comment-item">' +
                    '<div class="comment-header">' +
                    '<strong>' + IssuesCore.utils.escapeHtml(comment.author_name) + '</strong>' +
                    '<small class="text-muted ms-2">' + comment.created_at + '</small>' +
                    '</div>' +
                    '<div class="comment-body">' + comment.comment + '</div>' +
                    '</div>';
            }).join('');

            commentsContainer.innerHTML = html || '<p class="text-muted">No comments yet.</p>';
        },

        loadIssueComments: function(issueId) {
            // Load comments from API
            var xhr = new XMLHttpRequest();
            xhr.open('GET', IssuesCore.config.issueCommentsApi + '?issue_id=' + issueId);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        IssuesCore.data.comments[issueId] = response.comments || [];
                        modalManager.renderIssueComments(issueId);
                    } catch (e) {
                        console.error('Failed to parse comments response:', e);
                    }
                }
            };
            xhr.send();
        }
    };

    // Public API
    return {
        modalState: modalState,
        badges: badges,
        contentRenderer: contentRenderer,
        modalManager: modalManager,
        
        // Expose main functions for backward compatibility
        openFinalEditor: modalManager.openFinalEditor.bind(modalManager),
        closeFinalEditor: modalManager.closeFinalEditor.bind(modalManager),
        openFinalViewer: modalManager.openFinalViewer.bind(modalManager),
        closeFinalViewer: modalManager.closeFinalViewer.bind(modalManager),
        isIssueEditorOpen: modalManager.isIssueEditorOpen.bind(modalManager)
    };
})();
