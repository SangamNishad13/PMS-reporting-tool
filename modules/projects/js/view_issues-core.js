/**
 * view_issues-core.js
 * Core functionality, data structures, and basic issue management
 */

// Global configuration and data
window.IssuesCore = (function() {
    'use strict';

    // Check if we're on a page that needs issues functionality
    function hasIssuesElements() {
        var hasIssuesTab = document.getElementById('issues') || document.getElementById('issuesSubTabs');
        var hasIssueModal = document.getElementById('finalIssueModal');
        var hasAddIssueBtn = document.getElementById('issueAddFinalBtn');
        var hasCommonIssues = document.getElementById('commonIssuesBody') || document.getElementById('commonAddBtn');
        return !!(hasIssuesTab || hasIssueModal || hasAddIssueBtn || hasCommonIssues);
    }

    // Initialize core configuration
    function initConfig() {
        // Config from global object
        var config = {
            pages: ProjectConfig.projectPages || [],
            groupedUrls: ProjectConfig.groupedUrls || [],
            projectId: ProjectConfig.projectId,
            projectType: ProjectConfig.projectType || 'web',
            issuesApiBase: ProjectConfig.baseDir + '/api/issues.php',
            issueImageUploadUrl: ProjectConfig.baseDir + '/api/issue_upload_image.php',
            issueTemplatesApi: ProjectConfig.baseDir + '/api/issue_templates.php',
            issueCommentsApi: ProjectConfig.baseDir + '/api/issue_comments.php',
            issueDraftsApi: ProjectConfig.baseDir + '/api/issue_drafts.php',
            uniqueIssuePages: ProjectConfig.uniqueIssuePages || [],
            userRole: String(ProjectConfig.userRole || '').toLowerCase(),
            canUpdateIssueQaStatus: !!ProjectConfig.canUpdateIssueQaStatus
        };

        var isTesterRole = config.userRole === 'at_tester' || config.userRole === 'ft_tester';
        if (isTesterRole) {
            config.canUpdateIssueQaStatus = false;
        }

        config.isAdminUser = config.userRole === 'admin' || config.userRole === 'superadmin';
        config.isTesterRole = isTesterRole;

        return config;
    }

    // Initialize core data structures
    function initData() {
        var config = IssuesCore.config;
        
        var issueData = {
            selectedPageId: null,
            pages: {},
            common: [],
            comments: {},
            counters: { final: 1, review: 1, common: 1 },
            draftTimer: null,
            initialFormState: null,
            isDraftRestored: false,
            imageUpload: {
                pendingFile: null,
                pendingEditor: null,
                lastPasteTime: 0,
                suppressUntil: 0,
                isEditing: false,
                editingImg: null,
                savedRange: null
            }
        };

        // Detail page fallback: pick page_id from URL if pre-selection bootstrap misses.
        try {
            var qp = new URLSearchParams(window.location.search || '');
            var pageIdFromQuery = qp.get('page_id');
            if (pageIdFromQuery && !issueData.selectedPageId) {
                issueData.selectedPageId = String(pageIdFromQuery);
            }
            
            // Handle expand parameter to auto-expand specific issue
            var expandIssueId = qp.get('expand');
            if (expandIssueId) {
                window.expandIssueId = expandIssueId;
            }
        } catch (e) { }

        // Additional global variables
        var additionalData = {
            issueTemplates: [],
            defaultSections: [],
            issuePresets: [],
            issueMetadataFields: [],
            isSyncingUrlModal: false,
            issuePresenceTimer: null,
            issuePresenceIssueId: null,
            issuePresenceSessionToken: null,
            issuePresenceRenderSignature: '',
            ISSUE_PRESENCE_PING_MS: 2000,
            reviewPageSize: 25,
            reviewCurrentPage: 1,
            reviewFeaturesEnabled: false,
            reviewStorageKey: 'pms_review_findings_v1_' + String(config.projectId || '0'),
            reviewIssueInitialFormState: null,
            reviewIssueBypassCloseConfirm: false,
            finalIssueBypassCloseConfirm: false
        };

        // Merge all data
        Object.assign(issueData, additionalData);
        
        // Expose globally for external access
        window.issueData = issueData;
        
        return issueData;
    }

    // Core utility functions
    var utils = {
        escapeHtml: function(str) { 
            if (str === null || str === undefined) return ''; 
            return String(str).replace(/[&<>"']/g, function (m) { 
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[m]); 
            }); 
        },

        escapeAttr: function(v) {
            return String(v == null ? '' : v)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        },

        decodeHtmlEntities: function(text) {
            var s = String(text || '');
            if (!s) return '';
            var el = document.createElement('textarea');
            el.innerHTML = s;
            return el.value;
        },

        issueNotify: function(message, type) {
            if (typeof window.showToast === 'function') {
                showToast(String(message || ''), type || 'info');
            }
        },

        dispatchIssuesChanged: function(detail) {
            try {
                var payload = Object.assign({ source: 'internal' }, detail || {});
                document.dispatchEvent(new CustomEvent('pms:issues-changed', { detail: payload }));
            } catch (e) {
                console.warn('Failed to dispatch issues changed event:', e);
            }
        }
    };

    // Page store management
    var pageStore = {
        ensurePageStore: function(store, pageId) {
            if (!store[pageId]) store[pageId] = { final: [], review: [] };
            if (!store[pageId].final) store[pageId].final = [];
            if (!store[pageId].review) store[pageId].review = [];
        },

        readReviewStore: function() {
            try {
                var raw = localStorage.getItem(IssuesCore.data.reviewStorageKey);
                var parsed = raw ? JSON.parse(raw) : {};
                return parsed;
            } catch (e) {
                return {};
            }
        },

        writeReviewStore: function(store) {
            try {
                localStorage.setItem(IssuesCore.data.reviewStorageKey, JSON.stringify(store || {}));
            } catch (e) { }
        },

        getLocalReviewItems: function(pageId) {
            var store = this.readReviewStore();
            var key = String(pageId || '');
            var arr = store[key];
            return Array.isArray(arr) ? arr : [];
        },

        setLocalReviewItems: function(pageId, items) {
            var store = this.readReviewStore();
            var key = String(pageId || '');
            store[key] = Array.isArray(items) ? items : [];
            this.writeReviewStore(store);
        }
    };

    // Permission and state management
    var permissions = {
        canEdit: function() {
            return true;
        },

        updateEditingState: function() {
            var editable = this.canEdit() && !!IssuesCore.data.selectedPageId;
            var addBtn = document.getElementById('issueAddFinalBtn');
            if (addBtn) addBtn.disabled = !editable;
            
            // Update other UI elements based on editing state
            var elements = document.querySelectorAll('.issues-edit-controls');
            elements.forEach(function(el) {
                el.style.display = editable ? '' : 'none';
            });
        },

        applyIssueQaPermissionState: function() {
            var $qa = jQuery('#finalIssueQaStatus');
            if ($qa.length) {
                $qa.prop('disabled', !IssuesCore.config.canUpdateIssueQaStatus).trigger('change.select2');
            }
            var reporterSelects = document.querySelectorAll('#reporterQaStatusRows .reporter-qa-status-select');
            reporterSelects.forEach(function (sel) {
                sel.disabled = !IssuesCore.config.canUpdateIssueQaStatus;
            });
            if (!IssuesCore.config.canUpdateIssueQaStatus) {
                if ($qa.length) $qa.attr('title', 'Only authorized users can update QA status.');
            } else {
                if ($qa.length) $qa.removeAttr('title');
            }
        }
    };

    // Public API
    return {
        init: function() {
            if (!hasIssuesElements()) {
                return false; // Exit early if no issues-related elements found
            }

            IssuesCore.config = initConfig();
            IssuesCore.data = initData();
            
            return true;
        },

        // Expose sub-modules
        utils: utils,
        pageStore: pageStore,
        permissions: permissions,

        // Expose data and config
        config: null,
        data: null
    };
})();
