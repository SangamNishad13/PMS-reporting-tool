/**
 * view_issues.js
 * Logic for the Issues tab: issue management, drafting, rendering, and interaction.
 */

(function () {
    try {
        var list = document.getElementById('issuesPageList');
        if (!list) {
            // Element not found - likely on detail page
        } else {
            var rows = list.querySelectorAll('.issues-page-row');
        }
    } catch (e) { alert('view_issues.js error: ' + e); }
    
    // Check if we're on a page that needs issues functionality
    // Allow execution on detail pages even without #issues or #issuesSubTabs
    var hasIssuesTab = document.getElementById('issues') || document.getElementById('issuesSubTabs');
    var hasIssueModal = document.getElementById('finalIssueModal');
    var hasAddIssueBtn = document.getElementById('issueAddFinalBtn');
    var hasCommonIssues = document.getElementById('commonIssuesBody') || document.getElementById('commonAddBtn');
    
    if (!hasIssuesTab && !hasIssueModal && !hasAddIssueBtn && !hasCommonIssues) {
        return; // Exit early if no issues-related elements found
    }

    // Config from global object
    var pages = ProjectConfig.projectPages || [];
    var groupedUrls = ProjectConfig.groupedUrls || [];
    var projectId = ProjectConfig.projectId;
    var projectType = ProjectConfig.projectType || 'web';
    var apiBase = ProjectConfig.baseDir + '/api/automated_findings.php';
    var issuesApiBase = ProjectConfig.baseDir + '/api/issues.php';
    var issueImageUploadUrl = ProjectConfig.baseDir + '/api/chat_upload_image.php';
    var issueTemplatesApi = ProjectConfig.baseDir + '/api/issue_templates.php';
    var issueCommentsApi = ProjectConfig.baseDir + '/api/issue_comments.php';
    var issueDraftsApi = ProjectConfig.baseDir + '/api/issue_drafts.php';

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
            isEditing: false,
            editingImg: null,
            savedRange: null
        }
    };
    
    // Expose issueData globally for external access
    window.issueData = issueData;
    var issueTemplates = [];
    var defaultSections = [];
    var issuePresets = [];
    var issueMetadataFields = [];

    // Expose issueData for debug if needed, or keep private? 
    // view_core.js might need it? No, view_core is generic.
    // We might need to expose some functions to window if view.php calls them inline (unlikely, we are extracting everything).

    function ensurePageStore(store, pageId) {
        if (!store[pageId]) store[pageId] = { final: [], review: [] };
        if (!store[pageId].final) store[pageId].final = [];
        if (!store[pageId].review) store[pageId].review = [];
    }

    function canEdit() {
        return true;
    }

    function updateEditingState() {
        var editable = canEdit() && !!issueData.selectedPageId;
        var addBtn = document.getElementById('issueAddFinalBtn');
        var reviewAddBtn = document.getElementById('reviewAddBtn');
        if (addBtn) addBtn.disabled = !editable;
        if (reviewAddBtn) reviewAddBtn.disabled = !editable;

        if (!canEdit()) {
            hideEditors();
        }
    }

    async function loadReviewFindings(pageId) {
        if (!pageId) return;
        var tbody = document.getElementById('reviewIssuesBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-muted text-center">Loading automated findings...</td></tr>';
        var store = issueData.pages;
        ensurePageStore(store, pageId);
        try {
            var url = apiBase + '?action=list&project_id=' + encodeURIComponent(projectId) + '&page_id=' + encodeURIComponent(pageId);
            var res = await fetch(url, { credentials: 'same-origin' });
            var json = await res.json();
            var items = (json && json.findings) ? json.findings : [];
            store[pageId].review = items.map(function (it) {
                return {
                    id: String(it.id),
                    title: it.title || 'Automated Issue',
                    instance: it.instance_name || '',
                    wcag: it.wcag_failure || '',
                    severity: (it.severity || 'medium'),
                    details: it.details || '',
                    page_id: it.page_id || pageId
                };
            });
            renderReviewIssues();
        } catch (e) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-muted text-center">Unable to load automated findings.</td></tr>';
        }
    }

    async function loadFinalIssues(pageId) {
        if (!pageId) return;
        var tbody = document.getElementById('finalIssuesBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="11" class="text-muted text-center">Loading final issues...</td></tr>';
        var store = issueData.pages;
        ensurePageStore(store, pageId);
        try {
            var url = issuesApiBase + '?action=list&project_id=' + encodeURIComponent(projectId) + '&page_id=' + encodeURIComponent(pageId);
            var res = await fetch(url, { credentials: 'same-origin' });
            var json = await res.json();
            var items = (json && json.issues) ? json.issues : [];
            store[pageId].final = items.map(function (it) {
                return {
                    id: String(it.id),
                    issue_key: it.issue_key || '',
                    title: it.title || 'Issue',
                    details: it.description || '',
                    status: it.status || 'open',
                    status_id: it.status_id || null,
                    qa_status: Array.isArray(it.qa_status) ? it.qa_status : (it.qa_status ? [it.qa_status] : []),
                    severity: it.severity || 'medium',
                    priority: it.priority || 'medium',
                    pages: it.pages || [],
                    grouped_urls: it.grouped_urls || [],
                    reporter_name: it.reporter_name || null,
                    qa_name: it.qa_name || null,
                    page_id: it.page_id || pageId,
                    // Metadata fields - use correct field names from API
                    usersaffected: it.usersaffected || [],
                    wcagsuccesscriteria: it.wcagsuccesscriteria || [],
                    wcagsuccesscriterianame: it.wcagsuccesscriterianame || [],
                    wcagsuccesscriterialevel: it.wcagsuccesscriterialevel || [],
                    gigw30: it.gigw30 || [],
                    is17802: it.is17802 || [],
                    common_title: it.common_title || '',
                    reporters: it.reporters || []
                };
            });
            renderFinalIssues();
        } catch (e) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="11" class="text-muted text-center">Unable to load final issues.</td></tr>';
        }
    }

    async function loadCommonIssues() {
        var tbody = document.getElementById('commonIssuesBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center">Loading common issues...</td></tr>';
        try {
            var url = issuesApiBase + '?action=common_list&project_id=' + encodeURIComponent(projectId);
            var res = await fetch(url, { credentials: 'same-origin' });
            var json = await res.json();
            var items = (json && json.common) ? json.common : [];
            issueData.common = items.map(function (it) {
                return {
                    id: String(it.id),
                    issue_id: it.issue_id,
                    title: it.title || 'Common Issue',
                    description: it.description || '',
                    pages: it.pages || []
                };
            });
            renderCommonIssues();
        } catch (e) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center">Unable to load common issues.</td></tr>';
        }
    }

    function initSelect2() {
        if (!window.jQuery || !jQuery.fn.select2) return;
        jQuery('.issue-select2').each(function () {
            var $el = jQuery(this);
            var $parent = $el.closest('.modal');
            $el.select2({
                width: '100%',
                dropdownParent: $parent.length ? $parent : null
            });
        });
        jQuery('.issue-select2-tags').each(function () {
            var $el = jQuery(this);
            var $parent = $el.closest('.modal');
            $el.select2({
                width: '100%',
                tags: true,
                tokenSeparators: [','],
                dropdownParent: $parent.length ? $parent : null
            });
        });
        
        // Add event listener for pages select to auto-populate grouped URLs
        jQuery('#finalIssuePages').on('change', function() {
            // Use the existing updateGroupedUrls function which properly handles URLs
            updateGroupedUrls();
        });
    }

    function uploadIssueImage(file, $el) {
        if (!file || !file.type || !file.type.startsWith('image/')) return;
        var now = Date.now();
        if (now - issueData.imageUpload.lastPasteTime < 500) return;
        issueData.imageUpload.lastPasteTime = now;
        issueData.imageUpload.savedRange = $el.summernote('createRange');
        issueData.imageUpload.pendingFile = file;
        issueData.imageUpload.pendingEditor = $el;
        issueData.imageUpload.isEditing = false;
        showImageAltModal('');
    }

    function showImageAltModal(currentAlt) {
        var $modal = jQuery('#imageAltTextModal');
        if (!$modal.length) {
            var modalHtml = `
                <div class="modal fade" id="imageAltTextModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Image Alt-Text</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <label class="form-label">Enter descriptive alt-text for this image:</label>
                                <input type="text" class="form-control" id="imageAltTextInput" placeholder="e.g., Screenshot showing login error">
                                <div class="form-text">Alt-text helps with accessibility and SEO. You can edit this later by clicking the image.</div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="btnConfirmAltText">Upload Image</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            jQuery('body').append(modalHtml);
            $modal = jQuery('#imageAltTextModal');
            jQuery('#btnConfirmAltText').on('click', confirmImageAltText);
            jQuery('#imageAltTextInput').on('keypress', function (e) {
                if (e.which === 13) { e.preventDefault(); confirmImageAltText(); }
            });
        }
        jQuery('#imageAltTextInput').val(currentAlt);
        var modal = new bootstrap.Modal($modal[0]);
        modal.show();
        $modal.one('shown.bs.modal', function () { jQuery('#imageAltTextInput').focus(); });
    }

    function confirmImageAltText() {
        var altText = jQuery('#imageAltTextInput').val().trim();
        if (issueData.imageUpload.isEditing && issueData.imageUpload.editingImg) {
            issueData.imageUpload.editingImg.attr('alt', altText || 'Issue Screenshot');
            bootstrap.Modal.getInstance(jQuery('#imageAltTextModal')[0]).hide();
            issueData.imageUpload.isEditing = false;
            issueData.imageUpload.editingImg = null;
        } else if (issueData.imageUpload.pendingFile && issueData.imageUpload.pendingEditor) {
            var file = issueData.imageUpload.pendingFile;
            var $el = issueData.imageUpload.pendingEditor;
            var fd = new FormData();
            fd.append('image', file);
            fetch(issueImageUploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json()).then(function (res) {
                    if (res && res.success && res.url) {
                        var safeAlt = (altText || 'Issue Screenshot').replace(/"/g, '&quot;');
                        var imgHtml = '<img src="' + res.url + '" alt="' + safeAlt + '" style="max-width:100%; height:auto; cursor:pointer;" class="editable-issue-image" />';
                        if (issueData.imageUpload.savedRange) {
                            $el.summernote('restoreRange');
                            issueData.imageUpload.savedRange.pasteHTML(imgHtml);
                            issueData.imageUpload.savedRange = null;
                        } else {
                            $el.summernote('insertNode', $('<img>').attr({ src: res.url, alt: safeAlt, style: 'max-width:100%; height:auto; cursor:pointer;', class: 'editable-issue-image' })[0]);
                        }
                        bootstrap.Modal.getInstance(jQuery('#imageAltTextModal')[0]).hide();
                    } else if (res && res.error) { alert(res.error); }
                }).catch(function () { alert('Image upload failed'); })
                .finally(function () {
                    issueData.imageUpload.pendingFile = null;
                    issueData.imageUpload.pendingEditor = null;
                });
        }
    }

    function initSummernote(el) {
        if (!window.jQuery || !jQuery.fn.summernote) return;
        var $el = jQuery(el);
        if ($el.data('summernote')) return;
        $el.summernote({
            height: 180,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear']],
                ['fontname', ['fontname']],
                ['fontsize', ['fontsize']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph', 'height']],
                ['table', ['table']],
                ['insert', ['link', 'picture', 'video', 'hr']],
                ['view', ['fullscreen', 'codeview', 'help']]
            ],
            styleTags: ['p', { title: 'Blockquote', tag: 'blockquote', className: 'blockquote', value: 'blockquote' }, 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            popover: { image: [['image', ['resizeFull', 'resizeHalf', 'resizeQuarter', 'resizeNone']], ['float', ['floatLeft', 'floatRight', 'floatNone']], ['remove', ['removeMedia']], ['custom', ['imageAltText']]] },
            buttons: {
                imageAltText: function (context) {
                    var ui = jQuery.summernote.ui;
                    return ui.button({
                        contents: '<i class="fas fa-tag"/> <span style="font-size:0.75em;">Alt Text</span>',
                        tooltip: 'Edit alt text',
                        click: function () {
                            var $img = jQuery(context.invoke('restoreTarget'));
                            if ($img && $img.length) {
                                issueData.imageUpload.isEditing = true;
                                issueData.imageUpload.editingImg = $img;
                                showImageAltModal($img.attr('alt') || '');
                            }
                        }
                    }).render();
                }
            },
            callbacks: {
                onImageUpload: function (files) { (files || []).forEach(function (f) { uploadIssueImage(f, $el); }); },
                onPaste: function (e) {
                    var clipboard = e.originalEvent && e.originalEvent.clipboardData;
                    if (clipboard && clipboard.items) {
                        for (var i = 0; i < clipboard.items.length; i++) {
                            var item = clipboard.items[i];
                            if (item.type && item.type.indexOf('image') === 0) {
                                e.preventDefault(); uploadIssueImage(item.getAsFile(), $el); break;
                            }
                        }
                    }
                }
            }
        });
    }

    function initEditors() {
        document.querySelectorAll('.issue-summernote').forEach(function (el) { initSummernote(el); });
        jQuery(document).on('click', '.note-editable img', function (e) {
            e.preventDefault();
            var $img = jQuery(this);
            issueData.imageUpload.isEditing = true;
            issueData.imageUpload.editingImg = $img;
            showImageAltModal($img.attr('alt') || '');
        });
        
        // Initialize @ mention for comment editor
        initMentionSupport();
    }
    
    function initMentionSupport() {
        var $editor = jQuery('#finalIssueCommentEditor');
        if (!$editor.length) return;
        
        var mentionDropdown = null;
        var mentionIndex = -1;
        var mentionList = [];
        var lastAtPosition = null;
        
        // Create mention dropdown
        var dropdownHtml = '<div id="issueMentionDropdown" class="dropdown-menu" style="display:none; position:fixed; z-index:99999; max-height:200px; overflow-y:auto;"></div>';
        if (!document.getElementById('issueMentionDropdown')) {
            jQuery('body').append(dropdownHtml);
        }
        mentionDropdown = document.getElementById('issueMentionDropdown');
        
        // Handle keydown in Summernote (for preventing default behavior)
        $editor.on('summernote.keydown', function(we, e) {
            // Check if dropdown is visible
            var dropdownVisible = mentionDropdown && mentionDropdown.style.display === 'block';
            
            if (dropdownVisible) {
                if (e.keyCode === 40) { // Arrow down
                    e.preventDefault();
                    e.stopPropagation();
                    moveMentionHighlight(1);
                    return false;
                } else if (e.keyCode === 38) { // Arrow up
                    e.preventDefault();
                    e.stopPropagation();
                    moveMentionHighlight(-1);
                    return false;
                } else if (e.keyCode === 13) { // Enter
                    e.preventDefault();
                    e.stopPropagation();
                    var active = mentionDropdown.querySelector('.mention-item.active');
                    if (active) {
                        insertMention(active.getAttribute('data-username'));
                    }
                    return false;
                } else if (e.keyCode === 9) { // Tab
                    e.preventDefault();
                    e.stopPropagation();
                    var active = mentionDropdown.querySelector('.mention-item.active');
                    if (active) {
                        insertMention(active.getAttribute('data-username'));
                    }
                    return false;
                } else if (e.keyCode === 27) { // Escape
                    e.preventDefault();
                    e.stopPropagation(); // CRITICAL: Stop event from reaching modal
                    e.stopImmediatePropagation(); // Also stop other handlers
                    hideMentionDropdown();
                    return false;
                }
            }
        });
        
        // Handle keyup in Summernote (for showing/hiding dropdown)
        $editor.on('summernote.keyup', function(we, e) {
            // Don't process if we just handled navigation keys
            if (mentionDropdown && mentionDropdown.style.display === 'block') {
                if ([9, 13, 27, 38, 40].indexOf(e.keyCode) !== -1) {
                    return;
                }
            }
            
            // Don't show dropdown if it was just closed by Escape
            if (e.keyCode === 27) {
                return;
            }
            
            // Get the editable div content
            var $editable = $editor.next('.note-editor').find('.note-editable');
            if (!$editable.length) return;
            
            var text = $editable.text();
            var lastAtPos = text.lastIndexOf('@');
            
            // Check if @ was just typed or we're typing after @
            if (lastAtPos >= 0) {
                var afterAt = text.substring(lastAtPos + 1);
                // Check if we're still in a mention (no space after @)
                var spacePos = afterAt.indexOf(' ');
                var query = spacePos >= 0 ? afterAt.substring(0, spacePos) : afterAt;
                
                // Only show dropdown if query is reasonable (no special chars, reasonable length)
                if (query.length <= 50 && /^[\w]*$/.test(query)) {
                    showMentionDropdown(query, $editable);
                } else if (query.length === 0) {
                    showMentionDropdown('', $editable);
                } else {
                    hideMentionDropdown();
                }
            } else {
                hideMentionDropdown();
            }
        });
        
        function showMentionDropdown(query, $editable) {
            var users = ProjectConfig.projectUsers || [];
            mentionList = users.filter(function(u) {
                return u.full_name.toLowerCase().indexOf(query.toLowerCase()) >= 0;
            });
            
            if (mentionList.length === 0) {
                hideMentionDropdown();
                return;
            }
            
            var html = mentionList.map(function(u, idx) {
                var username = u.full_name.replace(/\s+/g, '');
                return '<a href="#" class="dropdown-item mention-item' + (idx === 0 ? ' active' : '') + '" data-username="' + 
                       escapeHtml(username) + '" data-id="' + u.id + '">' + 
                       escapeHtml(u.full_name) + '</a>';
            }).join('');
            
            mentionDropdown.innerHTML = html;
            mentionDropdown.style.display = 'block';
            mentionIndex = 0;
            
            // Position dropdown near @ symbol using cursor position
            if ($editable && $editable.length) {
                try {
                    // Get cursor position from Summernote
                    var range = $editor.summernote('createRange');
                    if (range && range.getClientRects) {
                        var rects = range.getClientRects();
                        if (rects && rects.length > 0) {
                            var rect = rects[0];
                            // Position dropdown just below cursor
                            mentionDropdown.style.left = rect.left + 'px';
                            mentionDropdown.style.top = (rect.bottom + 5) + 'px';
                        } else {
                            // Fallback to editor position
                            var offset = $editable.offset();
                            mentionDropdown.style.left = offset.left + 'px';
                            mentionDropdown.style.top = (offset.top + 30) + 'px';
                        }
                    } else {
                        // Fallback to editor position
                        var offset = $editable.offset();
                        mentionDropdown.style.left = offset.left + 'px';
                        mentionDropdown.style.top = (offset.top + 30) + 'px';
                    }
                } catch (e) {
                    // Fallback to editor position
                    var offset = $editable.offset();
                    mentionDropdown.style.left = offset.left + 'px';
                    mentionDropdown.style.top = (offset.top + 30) + 'px';
                }
            }
            
            // Click handler
            jQuery(mentionDropdown).find('.mention-item').off('click').on('click', function(e) {
                e.preventDefault();
                insertMention(jQuery(this).attr('data-username'));
            });
        }
        
        function hideMentionDropdown() {
            if (mentionDropdown) {
                mentionDropdown.style.display = 'none';
            }
        }
        
        function moveMentionHighlight(direction) {
            var items = mentionDropdown.querySelectorAll('.mention-item');
            if (items.length === 0) return;
            
            items[mentionIndex].classList.remove('active');
            mentionIndex += direction;
            if (mentionIndex < 0) mentionIndex = items.length - 1;
            if (mentionIndex >= items.length) mentionIndex = 0;
            items[mentionIndex].classList.add('active');
            items[mentionIndex].scrollIntoView({ block: 'nearest' });
        }
        
        function insertMention(username) {
            var $editable = $editor.next('.note-editor').find('.note-editable');
            var text = $editable.text();
            var lastAtPos = text.lastIndexOf('@');
            
            if (lastAtPos >= 0) {
                // Get current HTML
                var currentHtml = $editor.summernote('code');
                
                // Find position of @ in text
                var beforeAtText = text.substring(0, lastAtPos);
                var afterAtText = text.substring(lastAtPos);
                
                // Find where the query ends (space or end of string)
                var queryEndPos = afterAtText.indexOf(' ');
                if (queryEndPos === -1) queryEndPos = afterAtText.length;
                
                // Build new text with mention
                var newText = beforeAtText + '@' + username + ' ' + afterAtText.substring(queryEndPos);
                
                // For HTML, we need to be more careful
                // Find the last @ in HTML
                var lastAtHtmlPos = currentHtml.lastIndexOf('@');
                if (lastAtHtmlPos >= 0) {
                    var beforeAtHtml = currentHtml.substring(0, lastAtHtmlPos);
                    var afterAtHtml = currentHtml.substring(lastAtHtmlPos + 1);
                    
                    // Remove the partial query after @
                    // Find the next space, tag, or end
                    var endMatch = afterAtHtml.match(/^[\w]*/);
                    var queryLength = endMatch ? endMatch[0].length : 0;
                    afterAtHtml = afterAtHtml.substring(queryLength);
                    
                    // Insert the mention with space
                    var newHtml = beforeAtHtml + '@' + username + ' ' + afterAtHtml;
                    
                    // Set the new HTML
                    $editor.summernote('code', newHtml);
                    
                    // Move cursor to end
                    $editor.summernote('editor.saveRange');
                    $editor.summernote('editor.restoreRange');
                    
                    // Focus at the end
                    var range = document.createRange();
                    var sel = window.getSelection();
                    var editableEl = $editable[0];
                    
                    // Move to end of content
                    range.selectNodeContents(editableEl);
                    range.collapse(false);
                    sel.removeAllRanges();
                    sel.addRange(range);
                }
            }
            hideMentionDropdown();
        }
    }
    
    function showFinalIssuesTab() {
        var tabBtn = document.getElementById('final-issues-tab');
        if (!tabBtn) return;
        try { new bootstrap.Tab(tabBtn).show(); } catch (e) { }
    }

    function setSelectedPage(btn) {
        if (!btn) return;
        var pid = btn.getAttribute('data-page-id');
        if (!pid || pid === '0') return;
        document.querySelectorAll('#issuesPageList .issues-page-row').forEach(function (b) { b.classList.remove('table-active'); });
        btn.classList.add('table-active');
        issueData.selectedPageId = pid;
        ensurePageStore(issueData.pages, issueData.selectedPageId);

        var name = btn.getAttribute('data-page-name') || 'Page';
        var tester = btn.getAttribute('data-page-tester') || '-';
        var env = btn.getAttribute('data-page-env') || '-';
        var issues = btn.getAttribute('data-page-issues') || '0';
        var nameEl = document.getElementById('issueSelectedPageName');
        var metaEl = document.getElementById('issueSelectedPageMeta');
        if (nameEl) nameEl.textContent = name;
        if (metaEl) metaEl.textContent = 'Tester: ' + tester + ' | Env: ' + env + ' | Issues: ' + issues;
        showFinalIssuesTab();

        showIssuesDetail();
        updateEditingState();
        populatePageUrls(issueData.selectedPageId);
        renderAll();
        loadReviewFindings(issueData.selectedPageId);
        loadFinalIssues(issueData.selectedPageId);
    }

    function attachPageClickListeners() {
        var pageRows = document.querySelectorAll('#issuesPageList .issues-page-row');
        document.querySelectorAll('#issuesPageList .issues-page-row').forEach(function (btn) {
            // Remove existing listeners to avoid duplicates
            if (btn._pageClickHandler) {
                btn.removeEventListener('click', btn._pageClickHandler);
            }
            // Create and attach new handler
            btn._pageClickHandler = function (e) {
                // Don't trigger if clicking on the collapse button
                if (e.target.closest('button[data-bs-toggle="collapse"]')) return;
                console.debug('issues: row clicked (direct) on', btn.getAttribute('data-page-id') || btn.getAttribute('data-unique-id'));
                var pageId = btn.getAttribute('data-page-id');
                if (pageId && pageId !== '0') {
                    setSelectedPage(btn);
                    return;
                }
                var uniqueId = btn.getAttribute('data-unique-id');
                if (!uniqueId) return;
                setSelectedUniquePage(btn, uniqueId);
            };
            btn.addEventListener('click', btn._pageClickHandler);
        });

        // Delegated click handler as a fallback for dynamic row updates
        try {
            var container = document.getElementById('issuesPageList');
            if (container && !container._issuesDelegateAttached) {
                container._issuesDelegateAttached = true;
                try { } catch (e) {}
                container.addEventListener('click', function (e) {
                    var row = e.target.closest && e.target.closest('.issues-page-row');
                    if (!row) return;
                    // Ignore clicks on collapse toggle buttons
                    if (e.target.closest && e.target.closest('button[data-bs-toggle="collapse"]')) return;
                    console.debug('issues: delegated row click on', row.getAttribute('data-page-id') || row.getAttribute('data-unique-id'));
                    var pageId = row.getAttribute('data-page-id');
                    if (pageId && pageId !== '0') { setSelectedPage(row); return; }
                    var uniqueId = row.getAttribute('data-unique-id');
                    if (!uniqueId) return;
                    setSelectedUniquePage(row, uniqueId);
                });
            }
        } catch (e) { /* ignore */ }
    }

    function populatePageUrls(pageId) {
        var card = document.getElementById('pageUrlsCard');
        var content = document.getElementById('pageUrlsListContent');
        var count = document.getElementById('urlsCount');
        
        if (!pageId || !card || !content) return;
        
        // Get URLs for this page from groupedUrls array
        var urls = groupedUrls.filter(function(u) { 
            return u.mapped_page_id == pageId; 
        });
        
        if (urls.length === 0) {
            card.style.display = 'none';
            return;
        }
        
        // Show card and populate URLs
        card.style.display = 'block';
        count.textContent = urls.length;
        
        content.innerHTML = urls.map(function(u) {
            return '<li class="mb-1"><i class="fas fa-angle-right text-muted me-2"></i>' + 
                   escapeHtml(u.url) + '</li>';
        }).join('');
    }

    function showIssuesDetail() {
        var pagesCol = document.getElementById('issuesPagesCol');
        var detailCol = document.getElementById('issuesDetailCol');
        var backBtn = document.getElementById('issuesBackBtn');
        if (pagesCol) pagesCol.classList.add('d-none');
        if (detailCol) {
            detailCol.classList.remove('d-none');
            detailCol.classList.remove('col-lg-8');
            detailCol.classList.add('col-lg-12');
        }
        if (backBtn) backBtn.classList.remove('d-none');
    }

    function showIssuesPages() {
        var pagesCol = document.getElementById('issuesPagesCol');
        var detailCol = document.getElementById('issuesDetailCol');
        var backBtn = document.getElementById('issuesBackBtn');
        if (pagesCol) {
            pagesCol.classList.remove('d-none');
            pagesCol.classList.remove('col-lg-4');
            pagesCol.classList.add('col-lg-12');
        }
        if (detailCol) {
            detailCol.classList.add('d-none');
            detailCol.classList.remove('col-lg-12');
            detailCol.classList.add('col-lg-12');
        }
        if (backBtn) backBtn.classList.add('d-none');
    }

    function captureFormState() {
        var titleInput = document.getElementById('customIssueTitle');
        var titleVal = titleInput ? titleInput.value.trim() : '';
        var detailsVal = jQuery('#finalIssueDetails').summernote('code') || '';
        var statusVal = document.getElementById('finalIssueStatus').value;
        var qaStatusVal = jQuery('#finalIssueQaStatus').val() || [];
        var pagesVal = jQuery('#finalIssuePages').val() || [];
        var groupedUrlsVal = jQuery('#finalIssueGroupedUrls').val() || [];
        var reportersVal = jQuery('#finalIssueReporters').val() || [];
        var commonTitleVal = document.getElementById('finalIssueCommonTitle').value;
        var dynamicFields = {};
        if (typeof issueMetadataFields !== 'undefined') {
            issueMetadataFields.forEach(function (f) {
                var el = document.getElementById('finalIssueField_' + f.field_key);
                if (el) dynamicFields[f.field_key] = jQuery(el).val();
            });
        }
        return {
            title: titleVal, details: detailsVal, status: statusVal, qa_status: qaStatusVal,
            pages: pagesVal, grouped_urls: groupedUrlsVal, reporters: reportersVal,
            common_title: commonTitleVal, dynamic_fields: dynamicFields
        };
    }

    function hasFormChanges() {
        if (!issueData.initialFormState) return false;
        return JSON.stringify(captureFormState()) !== JSON.stringify(issueData.initialFormState);
    }

    async function saveDraft() {
        if (!projectId) return;
        var formState = captureFormState();
        var plainText = String(formState.details || '').replace(/<[^>]*>/g, '').trim();
        if (!formState.title && !plainText) return;
        try {
            var fd = new FormData();
            fd.append('action', 'save'); fd.append('project_id', projectId);
            fd.append('issue_params', JSON.stringify(formState));
            await fetch(issueDraftsApi, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e) { }
    }

    async function loadDraft() {
        if (!projectId) return null;
        try {
            var res = await fetch(issueDraftsApi + '?action=get&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' });
            var json = await res.json();
            if (json && json.success && json.draft) return { data: json.draft, updated_at: json.updated_at };
        } catch (e) { }
        return null;
    }

    async function deleteDraft() {
        if (!projectId) return;
        try {
            var fd = new FormData(); fd.append('action', 'delete'); fd.append('project_id', projectId);
            await fetch(issueDraftsApi, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e) { }
    }

    function startDraftAutosave() {
        if (issueData.draftTimer) clearInterval(issueData.draftTimer);
        issueData.draftTimer = setInterval(function () { if (hasFormChanges()) saveDraft(); }, 8000);
    }

    function stopDraftAutosave() {
        if (issueData.draftTimer) { clearInterval(issueData.draftTimer); issueData.draftTimer = null; }
    }

    function hideEditors() {
        ['finalIssueModal', 'reviewIssueModal', 'commonIssueModal'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            var inst = bootstrap.Modal.getInstance(el);
            if (inst) inst.hide();
        });
    }

    function toggleFinalIssueFields(enable) {
        var form = document.getElementById('finalIssueModal');
        if (!form) return;
        form.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (el.type === 'hidden') return;
            if (el.closest('#finalIssueComments')) return;
            el.disabled = !enable;
        });
        if (window.jQuery && jQuery.fn.summernote) {
            jQuery('#finalIssueDetails').summernote(enable ? 'enable' : 'disable');
            jQuery('#finalIssueCommentEditor').summernote('enable');
        }
        if (window.jQuery && jQuery.fn.select2) {
            jQuery('.issue-select2, .issue-select2-tags').prop('disabled', !enable);
        }
    }

    function openFinalViewer(issue) {
        if (!issue) return;
        document.getElementById('finalIssueEditId').value = issue.id;
        
        // Inject/update custom title field with issue title
        if (window.injectIssueTitleField) {
            window.injectIssueTitleField(issue.title || '');
        }
        
        document.getElementById('finalIssueStatus').value = issue.status || 'Open';
        jQuery('#finalIssueQaStatus').val(issue.qa_status || []).trigger('change');
        jQuery('#finalIssuePages').val(issue.pages || [issueData.selectedPageId]).trigger('change');
        jQuery('#finalIssueGroupedUrls').val(issue.grouped_urls || []).trigger('change');
        if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueDetails').summernote('code', issue.description || '');
        else document.getElementById('finalIssueDetails').value = issue.description || '';
        document.getElementById('finalIssueCommonTitle').value = issue.common_title || '';

        Object.keys(issue).forEach(function (k) {
            if (k.startsWith('meta:')) {
                var fieldKey = k.substring(5);
                var el = document.getElementById('finalIssueField_' + fieldKey);
                if (el) {
                    var val = issue[k];
                    if (val && typeof val === 'string' && val.startsWith('[')) { try { val = JSON.parse(val); } catch (e) { } }
                    jQuery(el).val(val).trigger('change');
                }
            } else if (k === 'reporters') { jQuery('#finalIssueReporters').val(issue.reporters || []).trigger('change'); }
        });

        renderIssueComments(issue.id);
        loadIssueComments(issue.id);

        var modalTitle = document.getElementById('finalIssueModalLabel');
        if (modalTitle) modalTitle.textContent = 'View Issue';
        document.getElementById('finalIssueSaveBtn').classList.add('d-none');

        var footer = document.querySelector('#finalIssueModal .modal-footer');
        var editBtn = document.getElementById('finalIssueEditBtn');
        if (!editBtn && footer) {
            editBtn = document.createElement('button');
            editBtn.type = 'button'; editBtn.id = 'finalIssueEditBtn'; editBtn.className = 'btn btn-primary';
            editBtn.textContent = 'Edit Issue';
            editBtn.addEventListener('click', function () {
                toggleFinalIssueFields(true);
                this.classList.add('d-none');
                document.getElementById('finalIssueSaveBtn').classList.remove('d-none');
                if (modalTitle) modalTitle.textContent = 'Edit Issue';
            });
            var saveBtn = document.getElementById('finalIssueSaveBtn');
            if (saveBtn) footer.insertBefore(editBtn, saveBtn); else footer.appendChild(editBtn);
        }
        if (editBtn) editBtn.classList.remove('d-none');
        toggleFinalIssueFields(false);
        var chatDiv = document.getElementById('finalIssueComments');
        if (chatDiv) {
            chatDiv.querySelectorAll('input, select, textarea, button').forEach(function (el) { el.disabled = false; el.classList.remove('disabled'); });
            if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueCommentEditor').summernote('enable');
        }
        var modal = new bootstrap.Modal(document.getElementById('finalIssueModal'));
        modal.show();
        setTimeout(function () {
            var activeTab = document.querySelector('#finalIssueModal .nav-link.active');
            if (activeTab) activeTab.dispatchEvent(new Event('shown.bs.tab', { bubbles: true }));
        }, 200);
    }

    async function openFinalEditor(issue) {
        var modalEl = document.getElementById('finalIssueModal');
        if (!modalEl) return;
        
        toggleFinalIssueFields(true);
        document.getElementById('finalEditorTitle').textContent = issue ? 'Edit Final Issue' : 'New Final Issue';
        document.getElementById('finalIssueEditId').value = issue ? issue.id : '';

        // Ensure save button is visible and edit button is hidden
        var saveBtn = document.getElementById('finalIssueSaveBtn');
        if (saveBtn) saveBtn.classList.remove('d-none');
        var editBtn = document.getElementById('finalIssueEditBtn');
        if (editBtn) editBtn.classList.add('d-none');

        var draftData = null;
        if (!issue) {
            var draft = await loadDraft();
            if (draft && draft.data) {
                draftData = draft.data;
                issueData.isDraftRestored = true;
                if (window.showToast) showToast('Draft restored from ' + new Date(draft.updated_at).toLocaleString(), 'info');
            }
        }

        // Inject title field with value (won't re-inject if exists, just updates value)
        var titleVal = issue ? (issue.title || '') : (draftData ? draftData.title : '');
        if (window.injectIssueTitleField) {
            window.injectIssueTitleField(titleVal);
        }
        
        // Verify field was created/updated
        setTimeout(function() {
            var titleInput = document.getElementById('customIssueTitle');
            var applyBtn = document.getElementById('applyPresetBtn');
        }, 100);

        var detailsVal = issue ? (issue.details || '') : (draftData ? draftData.details : '');
        jQuery('#finalIssueDetails').summernote('code', detailsVal);
        
        // Note: Issue status options are already populated by PHP in the modal HTML
        // We only need to set the selected value
        
        // Set the selected value - convert status name to ID if needed
        var statusValue = '1'; // Default to Open
        if (issue && issue.status_id) {
            // Ensure it's a string for proper comparison with option values
            statusValue = String(issue.status_id);
        } else if (issue && issue.status && ProjectConfig.issueStatuses) {
            // Try to find the ID by name or label
            var statusOption = ProjectConfig.issueStatuses.find(function(s) { 
                var label = s.status_label || s.name || '';
                return label && issue.status && label.toLowerCase() === issue.status.toLowerCase(); 
            });
            if (statusOption) statusValue = String(statusOption.id);
        } else if (draftData && draftData.status && ProjectConfig.issueStatuses) {
            var statusOption = ProjectConfig.issueStatuses.find(function(s) { 
                var label = s.status_label || s.name || '';
                return label && draftData.status && label.toLowerCase() === draftData.status.toLowerCase(); 
            });
            if (statusOption) statusValue = String(statusOption.id);
        }
        document.getElementById('finalIssueStatus').value = statusValue;
        
        // Store values to set after modal is shown
        var qaStatusValue = issue ? (issue.qa_status || []) : (draftData ? draftData.qa_status : []);
        var reportersValue = issue ? (issue.reporters || []) : (draftData ? draftData.reporters : []);
        var pageIds = (issue && issue.pages) ? issue.pages : ((draftData && draftData.pages) ? draftData.pages : [issueData.selectedPageId]);
        
        // Set pages immediately (this usually works)
        jQuery('#finalIssuePages').val(pageIds).trigger('change');
        
        // Wait for modal to be fully shown before setting Select2 values
        var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        
        // Remove any existing event listeners to avoid duplicates
        modalEl.removeEventListener('shown.bs.modal', modalEl._select2SetterHandler);
        
        // Create new handler
        modalEl._select2SetterHandler = function() {
            // Set QA Status
            setTimeout(function() {
                jQuery('#finalIssueQaStatus').val(qaStatusValue).trigger('change');
            }, 100);
            
            // Set Reporters
            setTimeout(function() {
                jQuery('#finalIssueReporters').val(reportersValue).trigger('change');
            }, 100);
        };
        
        // Attach the handler
        modalEl.addEventListener('shown.bs.modal', modalEl._select2SetterHandler, { once: true });

        // Populate metadata fields with a slight delay to ensure Select2 is initialized
        setTimeout(function() {
            if (typeof issueMetadataFields !== 'undefined') {
                issueMetadataFields.forEach(function (f) {
                    var elId = 'finalIssueField_' + f.field_key;
                    var val = null;
                    
                    // Get value from issue data
                    if (issue && issue[f.field_key] !== undefined) {
                        val = issue[f.field_key];
                    } else if (draftData && draftData.dynamic_fields && draftData.dynamic_fields[f.field_key] !== undefined) {
                        val = draftData.dynamic_fields[f.field_key];
                    } else if (!issue && (f.field_key === 'severity' || f.field_key === 'priority')) {
                        val = 'medium';
                    }
                    
                    var $el = jQuery('#' + elId);
                    if ($el.length) {
                        // For select2 multi-select, ensure value is an array
                        if ($el.prop('multiple') && val && !Array.isArray(val)) {
                            val = [val];
                        }
                        // For single select, ensure value is a string
                        if (!$el.prop('multiple') && Array.isArray(val)) {
                            val = val[0] || null;
                        }
                        $el.val(val).trigger('change');
                    }
                });
            }
        }, 100);

        var commonTitleVal = issue ? (issue.common_title || '') : (draftData ? draftData.common_title : '');
        document.getElementById('finalIssueCommonTitle').value = commonTitleVal;
        if (issue && issue.grouped_urls) setGroupedUrls(issue.grouped_urls);
        else if (draftData && draftData.grouped_urls) setGroupedUrls(draftData.grouped_urls);
        else updateGroupedUrls();
        toggleCommonTitle();
        if (!issue) ensureDefaultSections();
        renderIssueComments(issue ? String(issue.id) : 'new');
        if (issue && issue.id) loadIssueComments(String(issue.id));

        setTimeout(function () {
            issueData.initialFormState = captureFormState();
            if (!issue) startDraftAutosave();
        }, 500);

        var modal = new bootstrap.Modal(modalEl);
        modal.show();
        // Removed the condition - always ensure metadata fields are properly initialized
        setTimeout(function () { var at = modalEl.querySelector('.nav-link.active'); if (at) at.dispatchEvent(new Event('shown.bs.tab', { bubbles: true })); }, 200);
    }

    function openReviewEditor(issue) {
        if (!canEdit()) return;
        var modalEl = document.getElementById('reviewIssueModal');
        if (!modalEl) return;
        document.getElementById('reviewEditorTitle').textContent = issue ? 'Edit Review Issue' : 'New Review Issue';
        document.getElementById('reviewIssueEditId').value = issue ? issue.id : '';
        document.getElementById('reviewIssueTitle').value = issue ? issue.title : '';
        document.getElementById('reviewIssueInstance').value = issue ? issue.instance : '';
        document.getElementById('reviewIssueWcag').value = issue ? issue.wcag : '';
        document.getElementById('reviewIssueSeverity').value = issue ? (issue.severity || 'medium') : 'medium';
        jQuery('#reviewIssueDetails').summernote('code', issue ? (issue.details || '') : '');
        new bootstrap.Modal(modalEl).show();
    }

    function openCommonEditor(issue) {
        var modalEl = document.getElementById('commonIssueModal');
        if (!modalEl) return;
        document.getElementById('commonEditorTitle').textContent = issue ? 'Edit Common Issue' : 'New Common Issue';
        document.getElementById('commonIssueEditId').value = issue ? issue.id : '';
        document.getElementById('commonIssueTitle').value = issue ? issue.title : '';
        jQuery('#commonIssuePages').val(issue ? issue.pages : []).trigger('change');
        jQuery('#commonIssueDetails').summernote('code', issue ? issue.details : '');
        new bootstrap.Modal(modalEl).show();
    }

    function toggleCommonTitle() {
        var sel = jQuery('#finalIssuePages').val() || [];
        var wrap = document.getElementById('finalIssueCommonTitleWrap');
        if (!wrap) return;
        if (sel.length > 1) wrap.classList.remove('d-none'); else wrap.classList.add('d-none');
    }

    function groupedUrlsByPages(pageIds) {
        var urls = [];
        
        // For each selected page
        pageIds.forEach(function(pageId) {
            // First, add all grouped URLs for this page
            var hasGroupedUrls = false;
            groupedUrls.forEach(function (row) {
                var rowPageId = row.unique_page_id || row.mapped_page_id;
                if (String(rowPageId) === String(pageId)) {
                    hasGroupedUrls = true;
                    var val = row.url || row.normalized_url || row.canonical_url;
                    if (val && urls.indexOf(val) === -1) {
                        urls.push(val);
                    }
                }
            });
            
            // If no grouped URLs found, add the main page URL
            if (!hasGroupedUrls) {
                var page = pages.find(function(p) { 
                    return String(p.id) === String(pageId); 
                });
                
                if (page && page.url && urls.indexOf(page.url) === -1) {
                    urls.push(page.url);
                }
            }
        });
        
        return urls;
    }

    function updateGroupedUrls() {
        var pageIds = jQuery('#finalIssuePages').val() || [];
        var urls = groupedUrlsByPages(pageIds);
        setGroupedUrls(urls);
    }

    function setGroupedUrls(values) {
        var $sel = jQuery('#finalIssueGroupedUrls');
        $sel.empty();
        values.forEach(function (u) { $sel.append('<option value="' + u.replace(/"/g, '&quot;') + '">' + u + '</option>'); });
        $sel.val(values).trigger('change');
    }

    function renderFinalIssues() {
        var tbody = document.getElementById('finalIssuesBody');
        if (!tbody) return;
        if (!issueData.selectedPageId) { 
            tbody.innerHTML = '<tr><td colspan="11" class="text-center py-5"><div class="text-muted mb-2"><i class="fas fa-arrow-left fa-2x opacity-25"></i></div><div class="text-muted fw-medium">Select a page from the list to view issues.</div></td></tr>'; 
            return; 
        }
        var issues = issueData.pages[issueData.selectedPageId].final || [];
        if (!issues.length) { 
            tbody.innerHTML = '<tr><td colspan="11" class="text-center py-5"><div class="text-muted mb-2"><i class="fas fa-check-circle fa-2x opacity-25"></i></div><div class="text-muted fw-medium">No final issues recorded yet.</div></td></tr>'; 
            return; 
        }

        // Helper functions for badges
        var getSeverityBadge = function(s) {
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
            return '<span class="badge bg-' + color + '">' + escapeHtml(s.toUpperCase()) + '</span>';
        };
        
        var getPriorityBadge = function(p) {
            if (!p || p === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
            p = String(p).toLowerCase();
            var colors = {
                'urgent': 'danger',
                'critical': 'danger',
                'high': 'warning',
                'medium': 'info',
                'low': 'success'
            };
            var color = colors[p] || 'secondary';
            return '<span class="badge bg-' + color + '">' + escapeHtml(p.toUpperCase()) + '</span>';
        };

        var getStatusBadge = function (statusId) {
            if (!statusId) return '<span class="badge bg-secondary">N/A</span>';
            // statusId can be either numeric ID or status key string
            if (ProjectConfig.issueStatuses) {
                var found = ProjectConfig.issueStatuses.find(function(s) { 
                    // Try matching by ID first (numeric comparison)
                    if (s.id == statusId) return true;
                    // Fallback to matching by name (case-insensitive)
                    if (s.name && String(s.name).toLowerCase() === String(statusId).toLowerCase()) return true;
                    return false;
                });
                if (found) {
                    var color = found.color || '#6c757d';
                    var name = found.name || 'Unknown';
                    // If color is a hex code, use inline style; otherwise use Bootstrap class
                    if (color.startsWith('#')) {
                        return '<span class="badge" style="background-color: ' + color + '; color: white;">' + escapeHtml(name) + '</span>';
                    } else {
                        return '<span class="badge bg-' + color + '">' + escapeHtml(name) + '</span>';
                    }
                }
            }
            return '<span class="badge bg-secondary">' + escapeHtml(String(statusId)) + '</span>';
        };
        
        var getQaBadge = function (q) {
            if (!q || q === 'pending' || q === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
            q = String(q).toLowerCase();
            if (q === 'pass' || q === 'passed') return '<span class="badge bg-success">PASS</span>';
            if (q === 'fail' || q === 'failed') return '<span class="badge bg-danger">FAIL</span>';
            return '<span class="badge bg-warning">' + escapeHtml(q.toUpperCase()) + '</span>';
        };
        
        var stripHtml = function(html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html || '';
            return tmp.textContent || tmp.innerText || '';
        };

        tbody.innerHTML = issues.map(function (issue) {
            // Extract values and handle arrays OR stringified arrays
            var severity = issue.severity || 'N/A';
            
            // Check if it's a stringified array like '["Low"]'
            if (typeof severity === 'string' && severity.startsWith('[')) {
                try {
                    var parsed = JSON.parse(severity);
                    if (Array.isArray(parsed)) {
                        severity = parsed[0] || 'N/A';
                    }
                } catch (e) {}
            } else if (Array.isArray(severity)) {
                severity = severity[0] || 'N/A';
            }
            
            var priority = issue.priority || 'N/A';
            
            // Check if it's a stringified array like '["Low"]'
            if (typeof priority === 'string' && priority.startsWith('[')) {
                try {
                    var parsed = JSON.parse(priority);
                    if (Array.isArray(parsed)) {
                        priority = parsed[0] || 'N/A';
                    }
                } catch (e) {}
            } else if (Array.isArray(priority)) {
                priority = priority[0] || 'N/A';
            }
            
            var status = issue.status || 'open';
            var statusId = issue.status_id || null;
            // QA Status is now an array - display as badges with proper labels
            var qaStatusArray = Array.isArray(issue.qa_status) ? issue.qa_status : (issue.qa_status ? [issue.qa_status] : []);
            var qaStatusHtml = '';
            if (qaStatusArray.length > 0) {
                qaStatusHtml = qaStatusArray.map(function(qs) {
                    // Get label from qaStatuses mapping or format the key
                    var label = qs;
                    if (ProjectConfig.qaStatuses) {
                        var found = ProjectConfig.qaStatuses.find(function(s) { 
                            return s.status_key === qs; 
                        });
                        if (found) {
                            label = found.status_label;
                        } else {
                            // Format key: TYPO_GRAMMAR → Typo Grammar
                            label = qs.split('_').map(function(word) {
                                return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
                            }).join(' ');
                        }
                    }
                    return '<span class="badge bg-info me-1">' + escapeHtml(label) + '</span>';
                }).join('');
            } else {
                qaStatusHtml = '<span class="text-muted">N/A</span>';
            }
            
            // Handle multiple reporters
            var reportersArray = Array.isArray(issue.reporters) && issue.reporters.length > 0 
                ? issue.reporters 
                : (issue.reporter_name ? [issue.reporter_name] : []);
            
            var reporterHtml = '';
            if (reportersArray.length > 0) {
                reporterHtml = reportersArray.map(function(reporterId) {
                    // Get reporter name from project users
                    var reporterName = 'Unknown';
                    if (ProjectConfig.projectUsers) {
                        var found = ProjectConfig.projectUsers.find(function(u) { 
                            return u.id == reporterId; 
                        });
                        if (found) {
                            reporterName = found.full_name;
                        }
                    }
                    return '<span class="badge bg-info me-1">' + escapeHtml(reporterName) + '</span>';
                }).join('');
            } else {
                reporterHtml = '<span class="text-muted">N/A</span>';
            }
            
            var qaName = issue.qa_name || 'N/A';
            var issueKey = issue.issue_key || 'N/A';
            var pageCount = (issue.pages && issue.pages.length) || 1;
            var titlePreview = stripHtml(issue.details).substring(0, 100);
            if (titlePreview && stripHtml(issue.details).length > 100) titlePreview += '...';
            var uniqueId = 'issue-details-' + issue.id;
            
            // Main row - NOT directly clickable, only chevron button is
            var mainRow = '<tr class="align-middle issue-expandable-row" data-collapse-target="#' + uniqueId + '">' +
                '<td class="checkbox-cell"><input type="checkbox" class="final-select" value="' + issue.id + '"></td>' +
                '<td><span class="badge bg-primary">' + escapeHtml(issueKey) + '</span></td>' +
                '<td style="min-width: 250px; max-width: 400px;">' +
                    '<div class="d-flex align-items-center">' +
                        '<button class="btn btn-link p-0 me-2 text-muted chevron-toggle-btn" ' +
                            'data-collapse-target="#' + uniqueId + '" ' +
                            'aria-label="Expand details for ' + escapeHtml(issueKey) + ': ' + escapeHtml(issue.title) + '" ' +
                            'style="border: none; background: none; font-size: 1rem;">' +
                            '<i class="fas fa-chevron-right chevron-icon"></i>' +
                        '</button>' +
                        '<div style="cursor: pointer;" class="issue-title-click" data-issue-id="' + issue.id + '">' +
                            (issue.common_title ? 
                                '<div class="fw-bold">' + escapeHtml(issue.common_title) + '</div>' +
                                '<div class="small text-muted">' + escapeHtml(issue.title) + '</div>' 
                                : 
                                '<div class="fw-bold">' + escapeHtml(issue.title) + '</div>' +
                                (titlePreview ? '<div class="small text-muted">' + escapeHtml(titlePreview) + '</div>' : '')
                            ) +
                        '</div>' +
                    '</div>' +
                '</td>' +
                '<td>' + getSeverityBadge(severity) + '</td>' +
                '<td>' + getPriorityBadge(priority) + '</td>' +
                '<td>' + getStatusBadge(statusId) + '</td>' +
                '<td>' + qaStatusHtml + '</td>' +
                '<td>' + reporterHtml + '</td>' +
                '<td>' + 
                    (qaName !== 'N/A' ? 
                        '<span class="badge bg-success">' + escapeHtml(qaName) + '</span>' : 
                        '<span class="text-muted">N/A</span>') +
                '</td>' +
                '<td>' +
                    '<span class="badge bg-secondary">' + pageCount + ' page(s)</span>' +
                '</td>' +
                '<td class="action-buttons-cell">' +
                    '<button class="btn btn-sm btn-outline-primary me-1 final-edit" data-id="' + issue.id + '" type="button" title="Edit Issue">' +
                        '<i class="fas fa-edit"></i>' +
                    '</button>' +
                    '<button class="btn btn-sm btn-outline-danger final-delete" data-id="' + issue.id + '" type="button" title="Delete Issue">' +
                        '<i class="fas fa-trash"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
            
            // Expandable details row
            var detailsRow = '<tr class="collapse" id="' + uniqueId + '">' +
                '<td colspan="11" class="p-0 border-0">' +
                    '<div class="bg-light p-4 border-top">' +
                        '<div class="row g-3">' +
                            '<div class="col-md-8">' +
                                '<h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Issue Details</h6>' +
                                '<div class="card">' +
                                    '<div class="card-body">' +
                                        (issue.details || '<p class="text-muted">No details provided.</p>') +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="col-md-4">' +
                                '<h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Metadata</h6>' +
                                '<div class="card">' +
                                    '<div class="card-body">' +
                                        '<div class="mb-2"><strong>Issue Key:</strong> ' + escapeHtml(issueKey) + '</div>' +
                                        '<div class="mb-2"><strong>Status:</strong> ' + getStatusBadge(status) + '</div>' +
                                        '<div class="mb-2"><strong>QA Status:</strong> ' + qaStatusHtml + '</div>' +
                                        '<div class="mb-2"><strong>Severity:</strong> ' + getSeverityBadge(severity) + '</div>' +
                                        '<div class="mb-2"><strong>Priority:</strong> ' + getPriorityBadge(priority) + '</div>' +
                                        '<div class="mb-2"><strong>Reporter(s):</strong> ' + reporterHtml + '</div>' +
                                        '<div class="mb-2"><strong>QA Name:</strong> ' + escapeHtml(qaName) + '</div>' +
                                        (function() {
                                            // Pages section with names
                                            var pagesHtml = '<div class="mb-2"><strong>Pages:</strong> ';
                                            if (issue.pages && issue.pages.length > 0) {
                                                var pageNames = issue.pages.map(function(pageId) {
                                                    return getPageName(pageId);
                                                });
                                                pagesHtml += '<span class="badge bg-secondary">' + pageNames.length + '</span><br>';
                                                pagesHtml += '<small class="text-muted">' + pageNames.join(', ') + '</small>';
                                            } else {
                                                pagesHtml += '<span class="text-muted">N/A</span>';
                                            }
                                            pagesHtml += '</div>';
                                            
                                            // Grouped URLs section with expand/collapse
                                            var urlsHtml = '';
                                            if (issue.grouped_urls && issue.grouped_urls.length > 0) {
                                                var urlsId = 'urls-' + issue.id;
                                                urlsHtml += '<div class="mb-2">';
                                                urlsHtml += '<strong>Grouped URLs:</strong> ';
                                                urlsHtml += '<span class="badge bg-info">' + issue.grouped_urls.length + '</span> ';
                                                urlsHtml += '<button class="btn btn-xs btn-link p-0 grouped-urls-toggle" data-bs-toggle="collapse" data-bs-target="#' + urlsId + '" aria-expanded="false">';
                                                urlsHtml += '<i class="fas fa-chevron-down transition-transform"></i>';
                                                urlsHtml += '</button>';
                                                urlsHtml += '<div class="mt-2" id="' + urlsId + '" style="display: none;">';
                                                urlsHtml += '<div class="small p-2 border rounded bg-light" style="max-height: 150px; overflow-y: auto;">';
                                                
                                                var urlsFound = 0;
                                                issue.grouped_urls.forEach(function(urlString) {
                                                    // The issue stores actual URL strings, not IDs
                                                    // Find matching URL data from ProjectConfig.groupedUrls
                                                    var urlData = (ProjectConfig.groupedUrls || []).find(function(u) {
                                                        return u.url === urlString || u.normalized_url === urlString;
                                                    });
                                                    
                                                    // If not found in groupedUrls, just display the URL string directly
                                                    var displayUrl = urlData ? urlData.url : urlString;
                                                    
                                                    if (displayUrl) {
                                                        urlsFound++;
                                                        urlsHtml += '<div class="mb-1">';
                                                        urlsHtml += '<a href="' + escapeHtml(displayUrl) + '" target="_blank" class="text-decoration-none">';
                                                        urlsHtml += '<i class="fas fa-external-link-alt me-1 text-primary"></i>';
                                                        urlsHtml += '<span class="text-primary">' + escapeHtml(displayUrl) + '</span>';
                                                        urlsHtml += '</a>';
                                                        urlsHtml += '</div>';
                                                    }
                                                });
                                                
                                                // If no URLs found, show message
                                                if (urlsFound === 0) {
                                                    urlsHtml += '<div class="text-muted">No URL data available</div>';
                                                }
                                                
                                                urlsHtml += '</div></div></div>';
                                            }
                                            
                                            return pagesHtml + urlsHtml;
                                        })() +
                                        (function() {
                                            var metaHtml = '';
                                            if (typeof issueMetadataFields !== 'undefined') {
                                                issueMetadataFields.forEach(function(f) {
                                                    // Skip severity and priority as they're already shown above
                                                    if (f.field_key === 'severity' || f.field_key === 'priority') return;
                                                    
                                                    var value = issue[f.field_key];
                                                    if (value && value.length > 0) {
                                                        var displayValue = Array.isArray(value) ? value.join(', ') : value;
                                                        metaHtml += '<div class="mb-2"><strong>' + escapeHtml(f.field_label) + ':</strong> ' + escapeHtml(displayValue) + '</div>';
                                                    }
                                                });
                                            }
                                            return metaHtml;
                                        })() +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</td>' +
            '</tr>';
            
            return mainRow + detailsRow;
        }).join('');
        
        // Add click handlers for chevron toggle buttons
        document.querySelectorAll('#finalIssuesBody .chevron-toggle-btn').forEach(function(btn) {
            // Click handler for chevron button
            btn.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent event bubbling
                toggleIssueRow(this);
            });
            
            // Keyboard handler (Enter or Space)
            btn.addEventListener('keydown', function(e) {
                // Only handle Enter (13) or Space (32)
                if (e.keyCode === 13 || e.keyCode === 32) {
                    e.preventDefault(); // Prevent page scroll on Space
                    e.stopPropagation();
                    toggleIssueRow(this);
                }
            });
        });
        
        // Add click handler for issue title to open edit modal
        document.querySelectorAll('#finalIssuesBody .issue-title-click').forEach(function(titleEl) {
            titleEl.addEventListener('click', function(e) {
                e.stopPropagation();
                var issueId = this.getAttribute('data-issue-id');
                if (issueId && issueData.selectedPageId) {
                    var issue = issueData.pages[issueData.selectedPageId].final.find(function(i) { 
                        return String(i.id) === String(issueId); 
                    });
                    if (issue) openFinalEditor(issue);
                }
            });
        });
        
        // Add click handler for entire row (for mouse users)
        document.querySelectorAll('#finalIssuesBody .issue-expandable-row').forEach(function(row) {
            row.style.cursor = 'pointer';
            
            row.addEventListener('click', function(e) {
                // Don't expand if clicking on buttons, checkbox, inputs, or action buttons
                if (e.target.closest('button') || 
                    e.target.closest('input') ||
                    e.target.closest('select') ||
                    e.target.closest('.action-buttons-cell') ||
                    e.target.closest('.checkbox-cell') ||
                    e.target.closest('.issue-title-click')) {
                    return;
                }
                
                // Find the chevron button in this row and trigger it
                var chevronBtn = this.querySelector('.chevron-toggle-btn');
                if (chevronBtn) {
                    toggleIssueRow(chevronBtn);
                }
            });
        });
        
        // Helper function to toggle issue row expansion
        function toggleIssueRow(btn) {
            var targetId = btn.getAttribute('data-collapse-target');
            if (targetId) {
                var collapseEl = document.querySelector(targetId);
                var chevronIcon = btn.querySelector('.chevron-icon');
                
                if (collapseEl) {
                    // Check current state and toggle
                    var isExpanded = collapseEl.classList.contains('show');
                    
                    if (isExpanded) {
                        // Collapse
                        collapseEl.classList.remove('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-right chevron-icon';
                    } else {
                        // Expand
                        collapseEl.classList.add('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
                    }
                }
            }
        }
        
        // Add click handlers for images in expanded details
        setTimeout(function() {
            document.querySelectorAll('#finalIssuesBody img').forEach(function(img) {
                img.style.cursor = 'pointer';
                img.addEventListener('click', function(e) {
                    e.stopPropagation(); // Prevent row collapse
                    var imgSrc = this.src;
                    var imgAlt = this.alt || '';
                    
                    var modal = document.getElementById('issueImageModal');
                    var previewImg = document.getElementById('issueImagePreview');
                    var altTextDiv = document.getElementById('issueImageAltText');
                    var altTextContent = document.getElementById('issueImageAltTextContent');
                    
                    if (modal && previewImg) {
                        previewImg.src = imgSrc;
                        previewImg.alt = imgAlt;
                        
                        if (imgAlt && altTextDiv && altTextContent) {
                            altTextContent.textContent = imgAlt;
                            altTextDiv.style.display = 'block';
                        } else if (altTextDiv) {
                            altTextDiv.style.display = 'none';
                        }
                        
                        var bsModal = new bootstrap.Modal(modal);
                        bsModal.show();
                    }
                });
            });
            
            // Add event listeners for grouped URLs collapse with manual toggle
            document.querySelectorAll('#finalIssuesBody .grouped-urls-toggle').forEach(function(toggleBtn) {
                toggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var targetId = this.getAttribute('data-bs-target');
                    if (targetId) {
                        var collapseEl = document.querySelector(targetId);
                        var chevron = this.querySelector('i');
                        
                        if (collapseEl) {
                            // Toggle using inline style
                            var isHidden = collapseEl.style.display === 'none';
                            
                            if (isHidden) {
                                // Expand
                                collapseEl.style.display = 'block';
                                if (chevron) {
                                    chevron.classList.remove('fa-chevron-down');
                                    chevron.classList.add('fa-chevron-up');
                                }
                                this.setAttribute('aria-expanded', 'true');
                            } else {
                                // Collapse
                                collapseEl.style.display = 'none';
                                if (chevron) {
                                    chevron.classList.remove('fa-chevron-up');
                                    chevron.classList.add('fa-chevron-down');
                                }
                                this.setAttribute('aria-expanded', 'false');
                            }
                        }
                    }
                });
            });
        }, 100);
    }

    function renderReviewIssues() {
        var tbody = document.getElementById('reviewIssuesBody');
        if (!tbody) return;
        if (!issueData.selectedPageId || !issueData.pages[issueData.selectedPageId].review.length) { tbody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">No review findings logged.</td></tr>'; return; }
        tbody.innerHTML = issueData.pages[issueData.selectedPageId].review.map(function (it) {
            return '<tr class="align-middle"><td class="text-center"><input type="checkbox" class="form-check-input review-select" data-id="' + it.id + '"></td><td class="fw-medium text-dark">' + escapeHtml(it.title) + '</td><td class="font-monospace small text-primary">' + escapeHtml(it.instance || '-') + '</td><td><span class="badge bg-light text-dark border">' + escapeHtml(it.wcag || 'N/A') + '</span></td><td><span class="badge bg-warning-subtle text-warning text-uppercase">' + escapeHtml(it.severity || '-') + '</span></td><td class="text-end"><div class="btn-group"><button class="btn btn-sm btn-outline-primary review-edit bg-white" data-id="' + it.id + '"><i class="fas fa-pencil-alt"></i></button><button class="btn btn-sm btn-outline-danger review-delete bg-white" data-id="' + it.id + '"><i class="far fa-trash-alt"></i></button></div></td></tr>';
        }).join('');
    }

    function renderCommonIssues() {
        var tbody = document.getElementById('commonIssuesBody');
        if (!tbody) return;
        if (!issueData.common.length) { 
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-5 text-muted">No common issues found.</td></tr>'; 
            return; 
        }
        
        tbody.innerHTML = issueData.common.map(function (it) {
            var pagesStr = (it.pages || []).map(getPageName).slice(0, 5).join(', ') + ((it.pages && it.pages.length > 5) ? '...' : '');
            var uniqueId = 'common-issue-details-' + it.id;
            var pageCount = (it.pages || []).length;
            var descriptionPreview = stripHtml(it.description || '').substring(0, 100);
            if (descriptionPreview && stripHtml(it.description || '').length > 100) descriptionPreview += '...';
            
            // Try to find the actual issue data from loaded pages
            var actualIssue = null;
            if (it.issue_id && it.pages && it.pages.length > 0) {
                var firstPageId = it.pages[0];
                if (issueData.pages[firstPageId] && issueData.pages[firstPageId].final) {
                    actualIssue = issueData.pages[firstPageId].final.find(function(i) {
                        return String(i.id) === String(it.issue_id);
                    });
                }
            }
            
            // Main row with expand button
            var mainRow = '<tr class="align-middle issue-expandable-row" data-collapse-target="#' + uniqueId + '">' +
                '<td class="text-center checkbox-cell">' +
                    '<input type="checkbox" class="form-check-input common-select" data-id="' + it.id + '">' +
                '</td>' +
                '<td style="min-width: 250px; max-width: 400px;">' +
                    '<div class="d-flex align-items-center">' +
                        '<button class="btn btn-link p-0 me-2 text-muted chevron-toggle-btn" ' +
                            'data-collapse-target="#' + uniqueId + '" ' +
                            'aria-label="Expand details for ' + escapeHtml(it.title) + '" ' +
                            'style="border: none; background: none; font-size: 1rem;">' +
                            '<i class="fas fa-chevron-right chevron-icon"></i>' +
                        '</button>' +
                        '<div>' +
                            '<div class="fw-bold text-dark">' + escapeHtml(it.title) + '</div>' +
                            (descriptionPreview ? '<div class="small text-muted">' + escapeHtml(descriptionPreview) + '</div>' : '') +
                        '</div>' +
                    '</div>' +
                '</td>' +
                '<td class="small text-muted">' +
                    '<span class="badge bg-secondary">' + pageCount + ' page(s)</span>' +
                '</td>' +
                '<td class="text-end action-buttons-cell">' +
                    '<div class="btn-group">' +
                        '<button class="btn btn-sm btn-outline-primary common-edit bg-white" data-id="' + it.id + '" title="Edit Common Issue">' +
                            '<i class="fas fa-pencil-alt"></i>' +
                        '</button>' +
                        '<button class="btn btn-sm btn-outline-danger common-delete bg-white" data-id="' + it.id + '" title="Delete Common Issue">' +
                            '<i class="far fa-trash-alt"></i>' +
                        '</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
            
            // Build metadata section if actual issue is found
            var metadataHtml = '';
            if (actualIssue) {
                var severity = actualIssue.severity || 'N/A';
                var priority = actualIssue.priority || 'N/A';
                var status = actualIssue.status || 'open';
                var statusId = actualIssue.status_id || null;
                
                // QA Status
                var qaStatusArray = Array.isArray(actualIssue.qa_status) ? actualIssue.qa_status : (actualIssue.qa_status ? [actualIssue.qa_status] : []);
                var qaStatusHtml = '';
                if (qaStatusArray.length > 0) {
                    qaStatusHtml = qaStatusArray.map(function(qs) {
                        var label = qs;
                        if (ProjectConfig.qaStatuses) {
                            var found = ProjectConfig.qaStatuses.find(function(s) { return s.status_key === qs; });
                            if (found) {
                                label = found.status_label;
                            } else {
                                label = qs.split('_').map(function(word) {
                                    return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
                                }).join(' ');
                            }
                        }
                        return '<span class="badge bg-info me-1">' + escapeHtml(label) + '</span>';
                    }).join('');
                } else {
                    qaStatusHtml = '<span class="text-muted">N/A</span>';
                }
                
                // Reporters - handle both IDs and names
                var reportersArray = [];
                
                // First, add reporters from reporters array (these are IDs)
                if (Array.isArray(actualIssue.reporters) && actualIssue.reporters.length > 0) {
                    reportersArray = actualIssue.reporters;
                }
                
                // If no reporters array, use reporter_name (this is already a name string)
                var reporterHtml = '';
                if (reportersArray.length > 0) {
                    // These are IDs, convert to names
                    reporterHtml = reportersArray.map(function(reporterId) {
                        var reporterName = 'Unknown';
                        if (ProjectConfig.projectUsers) {
                            var found = ProjectConfig.projectUsers.find(function(u) { return u.id == reporterId; });
                            if (found) reporterName = found.full_name;
                        }
                        return '<span class="badge bg-info me-1">' + escapeHtml(reporterName) + '</span>';
                    }).join('');
                } else if (actualIssue.reporter_name) {
                    // This is already a name string
                    reporterHtml = '<span class="badge bg-info me-1">' + escapeHtml(actualIssue.reporter_name) + '</span>';
                } else {
                    reporterHtml = '<span class="text-muted">N/A</span>';
                }
                
                // Grouped URLs
                var urlsHtml = '';
                if (actualIssue.grouped_urls && actualIssue.grouped_urls.length > 0) {
                    var urlsId = 'common-urls-' + it.id;
                    urlsHtml = '<div class="mb-2">';
                    urlsHtml += '<strong>Grouped URLs:</strong> ';
                    urlsHtml += '<span class="badge bg-info">' + actualIssue.grouped_urls.length + '</span> ';
                    urlsHtml += '<button class="btn btn-xs btn-link p-0 grouped-urls-toggle" data-bs-toggle="collapse" data-bs-target="#' + urlsId + '" aria-expanded="false">';
                    urlsHtml += '<i class="fas fa-chevron-down transition-transform"></i>';
                    urlsHtml += '</button>';
                    urlsHtml += '<div class="mt-2" id="' + urlsId + '" style="display: none;">';
                    urlsHtml += '<div class="small p-2 border rounded bg-light" style="max-height: 150px; overflow-y: auto;">';
                    
                    actualIssue.grouped_urls.forEach(function(urlString) {
                        var urlData = (ProjectConfig.groupedUrls || []).find(function(u) {
                            return u.url === urlString || u.normalized_url === urlString;
                        });
                        var displayUrl = urlData ? urlData.url : urlString;
                        if (displayUrl) {
                            urlsHtml += '<div class="mb-1">';
                            urlsHtml += '<a href="' + escapeHtml(displayUrl) + '" target="_blank" class="text-decoration-none">';
                            urlsHtml += '<i class="fas fa-external-link-alt me-1 text-primary"></i>';
                            urlsHtml += '<span class="text-primary">' + escapeHtml(displayUrl) + '</span>';
                            urlsHtml += '</a></div>';
                        }
                    });
                    urlsHtml += '</div></div></div>';
                }
                
                metadataHtml = '<div class="mb-2"><strong>Issue Key:</strong> ' + escapeHtml(actualIssue.issue_key || 'N/A') + '</div>' +
                    '<div class="mb-2"><strong>Status:</strong> ' + getStatusBadge(statusId) + '</div>' +
                    '<div class="mb-2"><strong>QA Status:</strong> ' + qaStatusHtml + '</div>' +
                    '<div class="mb-2"><strong>Severity:</strong> ' + getSeverityBadge(severity) + '</div>' +
                    '<div class="mb-2"><strong>Priority:</strong> ' + getPriorityBadge(priority) + '</div>' +
                    '<div class="mb-2"><strong>Reporter(s):</strong> ' + reporterHtml + '</div>' +
                    '<div class="mb-2"><strong>Pages:</strong> ' +
                        '<span class="badge bg-secondary">' + pageCount + '</span><br>' +
                        '<small class="text-muted">' + escapeHtml(pagesStr) + '</small>' +
                    '</div>' +
                    urlsHtml;
                
                // Add metadata fields
                if (typeof issueMetadataFields !== 'undefined') {
                    issueMetadataFields.forEach(function(f) {
                        if (f.field_key === 'severity' || f.field_key === 'priority') return;
                        var value = actualIssue[f.field_key];
                        if (value && value.length > 0) {
                            var displayValue = Array.isArray(value) ? value.join(', ') : value;
                            metadataHtml += '<div class="mb-2"><strong>' + escapeHtml(f.field_label) + ':</strong> ' + escapeHtml(displayValue) + '</div>';
                        }
                    });
                }
            } else {
                metadataHtml = '<div class="mb-2"><strong>Title:</strong> ' + escapeHtml(it.title) + '</div>' +
                    '<div class="mb-2"><strong>Pages:</strong> ' +
                        '<span class="badge bg-secondary">' + pageCount + '</span><br>' +
                        '<small class="text-muted">' + escapeHtml(pagesStr) + '</small>' +
                    '</div>' +
                    (it.issue_id ? '<div class="mb-2"><strong>Issue ID:</strong> ' + escapeHtml(it.issue_id) + '</div>' : '') +
                    '<div class="alert alert-info small mt-2"><i class="fas fa-info-circle me-1"></i>Load the page to see full issue details</div>';
            }
            
            // Expandable details row
            var detailsRow = '<tr class="collapse" id="' + uniqueId + '">' +
                '<td colspan="4" class="p-0 border-0">' +
                    '<div class="bg-light p-4 border-top">' +
                        '<div class="row g-3">' +
                            '<div class="col-md-8">' +
                                '<h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Description</h6>' +
                                '<div class="card">' +
                                    '<div class="card-body">' +
                                        (it.description || actualIssue && actualIssue.details || '<p class="text-muted">No description provided.</p>') +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="col-md-4">' +
                                '<h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Details</h6>' +
                                '<div class="card">' +
                                    '<div class="card-body">' +
                                        metadataHtml +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</td>' +
            '</tr>';
            
            return mainRow + detailsRow;
        }).join('');
        
        // Add click handlers for chevron toggle buttons in common issues
        document.querySelectorAll('#commonIssuesBody .chevron-toggle-btn').forEach(function(btn) {
            // Click handler for chevron button
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleCommonIssueRow(this);
            });
            
            // Keyboard handler (Enter or Space)
            btn.addEventListener('keydown', function(e) {
                if (e.keyCode === 13 || e.keyCode === 32) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleCommonIssueRow(this);
                }
            });
        });
        
        // Add click handler for entire row
        document.querySelectorAll('#commonIssuesBody .issue-expandable-row').forEach(function(row) {
            row.style.cursor = 'pointer';
            
            row.addEventListener('click', function(e) {
                // Don't expand if clicking on buttons, checkbox, inputs, or action buttons
                if (e.target.closest('button') || 
                    e.target.closest('input') ||
                    e.target.closest('select') ||
                    e.target.closest('.action-buttons-cell') ||
                    e.target.closest('.checkbox-cell')) {
                    return;
                }
                
                // Find the chevron button in this row and trigger it
                var chevronBtn = this.querySelector('.chevron-toggle-btn');
                if (chevronBtn) {
                    toggleCommonIssueRow(chevronBtn);
                }
            });
        });
        
        // Helper function to toggle common issue row expansion
        function toggleCommonIssueRow(btn) {
            var targetId = btn.getAttribute('data-collapse-target');
            if (targetId) {
                var collapseEl = document.querySelector(targetId);
                var chevronIcon = btn.querySelector('.chevron-icon');
                
                if (collapseEl) {
                    var isExpanded = collapseEl.classList.contains('show');
                    
                    if (isExpanded) {
                        collapseEl.classList.remove('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-right chevron-icon';
                    } else {
                        // Before expanding, check if we need to load issue details
                        var commonIssueId = targetId.replace('#common-issue-details-', '');
                        var commonIssue = issueData.common.find(function(ci) { return String(ci.id) === String(commonIssueId); });
                        
                        if (commonIssue && commonIssue.issue_id && commonIssue.pages && commonIssue.pages.length > 0) {
                            var firstPageId = commonIssue.pages[0];
                            
                            // Check if page data is already loaded
                            if (!issueData.pages[firstPageId] || !issueData.pages[firstPageId].final) {
                                // Load the page data first
                                loadFinalIssues(firstPageId).then(function() {
                                    // Re-render common issues with updated data
                                    renderCommonIssues();
                                    // Now expand the row
                                    var newCollapseEl = document.querySelector(targetId);
                                    if (newCollapseEl) {
                                        newCollapseEl.classList.add('show');
                                        var newChevronIcon = document.querySelector('[data-collapse-target="' + targetId + '"] .chevron-icon');
                                        if (newChevronIcon) newChevronIcon.className = 'fas fa-chevron-down chevron-icon';
                                    }
                                }).catch(function(err) {
                                    console.error('Failed to load issue data:', err);
                                    // Expand anyway with basic info
                                    collapseEl.classList.add('show');
                                    if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
                                });
                                return; // Don't expand yet, wait for data to load
                            }
                        }
                        
                        // Expand normally if data is already loaded
                        collapseEl.classList.add('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
                    }
                }
            }
        }
    }

    function renderAll() { renderFinalIssues(); renderReviewIssues(); renderCommonIssues(); updateSelectionButtons(); }

    function updateSelectionButtons() {
        var finalChecked = document.querySelectorAll('.final-select:checked').length;
        var reviewChecked = document.querySelectorAll('.review-select:checked').length;
        var finalDelete = document.getElementById('finalDeleteSelected');
        var reviewDelete = document.getElementById('reviewDeleteSelected');
        var reviewMove = document.getElementById('reviewMoveSelected');
        if (finalDelete) finalDelete.disabled = !finalChecked || !canEdit();
        if (reviewDelete) reviewDelete.disabled = !reviewChecked || !canEdit();
        if (reviewMove) reviewMove.disabled = !reviewChecked || !canEdit();
    }

    function getPageName(id) { var p = (pages || []).find(function (x) { return String(x.id) === String(id); }); return p ? p.page_name : id; }
    function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/[&<>"']/g, function (m) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[m]); }); }
    function stripHtml(html) { if (!html) return ''; var tmp = document.createElement('div'); tmp.innerHTML = html; return tmp.textContent || tmp.innerText || ''; }
    
    function getSeverityBadge(s) {
        if (!s || s === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
        s = String(s).toLowerCase();
        var colors = { 'critical': 'danger', 'high': 'warning', 'medium': 'info', 'low': 'success', 'major': 'warning', 'minor': 'info' };
        var color = colors[s] || 'secondary';
        return '<span class="badge bg-' + color + '">' + escapeHtml(s.toUpperCase()) + '</span>';
    }
    
    function getPriorityBadge(p) {
        if (!p || p === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
        p = String(p).toLowerCase();
        var colors = { 'urgent': 'danger', 'critical': 'danger', 'high': 'warning', 'medium': 'info', 'low': 'success' };
        var color = colors[p] || 'secondary';
        return '<span class="badge bg-' + color + '">' + escapeHtml(p.toUpperCase()) + '</span>';
    }
    
    function getStatusBadge(statusId) {
        if (!statusId) return '<span class="badge bg-secondary">N/A</span>';
        if (ProjectConfig.issueStatuses) {
            var found = ProjectConfig.issueStatuses.find(function(s) { 
                if (s.id == statusId) return true;
                if (s.name && String(s.name).toLowerCase() === String(statusId).toLowerCase()) return true;
                return false;
            });
            if (found) {
                var color = found.color || '#6c757d';
                var name = found.name || 'Unknown';
                if (color.startsWith('#')) {
                    return '<span class="badge" style="background-color: ' + color + '; color: white;">' + escapeHtml(name) + '</span>';
                } else {
                    return '<span class="badge bg-' + color + '">' + escapeHtml(name) + '</span>';
                }
            }
        }
        return '<span class="badge bg-secondary">' + escapeHtml(String(statusId)) + '</span>';
    }
    
    function extractAltText(html) { if (!html) return ''; var matches = []; var re = /<img[^>]*alt=['"]([^'"]*)['"][^>]*>/gi; var m; while ((m = re.exec(html)) !== null) { if (m[1] && matches.indexOf(m[1]) === -1) matches.push(m[1]); } return matches.join(' | '); }
    function decorateIssueImages(html) { if (!html) return ''; return String(html).replace(/<img\b([^>]*)>/gi, function (_, attrs) { if (/class\s*=/.test(attrs)) { return '<img' + attrs.replace(/class\s*=(["\'])([^"\']*)\1/, 'class="$2 issue-image-thumb"') + '>'; } return '<img class="issue-image-thumb"' + attrs + '>'; }); }
    function openIssueImageModal(src) { var m = document.getElementById('issueImageModal'); var i = document.getElementById('issueModalImg'); if (!m || !i) return; i.src = src || ''; new bootstrap.Modal(m).show(); }

    function renderIssueComments(issueId) {
        var listEl = document.getElementById('finalIssueCommentsList');
        if (!listEl) return;
        var items = issueData.comments[issueId || 'new'] || [];
        
        if (!items.length) { 
            listEl.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-comments fa-3x mb-3 opacity-25"></i><p>No comments yet. Start the conversation!</p></div>'; 
            return; 
        }
        
        var currentUserId = ProjectConfig.userId;
        
        listEl.innerHTML = items.map(function (c, idx) {
            var isOwn = (c.user_id === currentUserId);
            var isRegression = (c.comment_type === 'regression');
            
            var commentText = decorateIssueImages(c.text || '');
            // Highlight @ mentions
            commentText = commentText.replace(/@(\w+)/g, '<span class="badge bg-warning text-dark">@$1</span>');
            
            // Reply preview if exists
            var replyPreview = '';
            if (c.reply_to && c.reply_preview) {
                var rp = c.reply_preview;
                var replyText = (rp.text || '').replace(/<[^>]*>/g, '').substring(0, 80);
                if (rp.text && rp.text.length > 80) replyText += '...';
                
                replyPreview = '<div class="reply-preview mb-2 p-2 rounded" style="background: #f8f9fa; border-left: 3px solid #0d6efd;">' +
                    '<div class="d-flex align-items-center mb-1">' +
                        '<i class="fas fa-reply text-primary me-2" style="font-size: 0.75rem;"></i>' +
                        '<small class="text-muted fw-bold">Replying to ' + escapeHtml(rp.user_name || 'User') + '</small>' +
                    '</div>' +
                    '<small class="text-muted" style="font-style: italic;">' + escapeHtml(replyText) + '</small>' +
                    '</div>';
            }
            
            // Determine background color based on comment type
            var bgClass = '';
            var borderStyle = '';
            var regressionHeading = '';
            
            if (isRegression) {
                // Regression comment: always very light blue with border
                bgClass = ''; // No class to avoid conflicts
                borderStyle = 'background: #e7f3ff !important; border-left: 3px solid #0d6efd;';
                regressionHeading = '<div class="mb-2 pb-2 border-bottom" style="border-color: #b6d4fe !important;">' +
                    '<span class="badge" style="background: #0d6efd; font-size: 0.75rem;">' +
                        '<i class="fas fa-retweet me-1"></i>Regression Comment' +
                    '</span>' +
                '</div>';
            } else if (isOwn) {
                bgClass = 'bg-primary-subtle';
            } else {
                bgClass = 'bg-light';
            }
            
            // Add regression badge next to name (smaller, for header)
            var regressionBadge = isRegression ? '<span class="badge bg-info ms-2" style="font-size: 0.65rem;"><i class="fas fa-retweet me-1"></i>Regression</span>' : '';
            
            return '<div class="message ' + (isOwn ? 'own-message' : 'other-message') + ' mb-3" data-comment-id="' + (c.id || idx) + '">' +
                '<div class="d-flex justify-content-between align-items-start mb-1">' +
                    '<div>' +
                        '<span class="fw-semibold text-primary">' + escapeHtml(c.user_name || 'User') + '</span>' +
                        regressionBadge +
                    '</div>' +
                    '<div class="d-flex align-items-center gap-2">' +
                        '<small class="text-muted">' + escapeHtml(c.time || '') + '</small>' +
                        '<button class="btn btn-xs btn-link p-0 text-decoration-none issue-comment-reply" ' +
                            'data-comment-id="' + (c.id || idx) + '" ' +
                            'data-user-name="' + escapeHtml(c.user_name || 'User') + '" ' +
                            'data-comment-text="' + escapeHtml((c.text || '').replace(/<[^>]*>/g, '').substring(0, 100)) + '">' +
                            '<i class="fas fa-reply"></i> Reply' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                replyPreview +
                '<div class="message-content p-2 rounded ' + bgClass + '" style="' + borderStyle + '">' +
                    regressionHeading +
                    commentText +
                '</div>' +
            '</div>';
        }).join('');
        
        // Add reply click handlers
        document.querySelectorAll('.issue-comment-reply').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var commentId = this.getAttribute('data-comment-id');
                var userName = this.getAttribute('data-user-name');
                var commentText = this.getAttribute('data-comment-text');
                showReplyPreview(commentId, userName, commentText);
            });
        });
    }
    
    function showReplyPreview(commentId, userName, commentText) {
        // Create or update reply preview
        var previewEl = document.getElementById('issueCommentReplyPreview');
        if (!previewEl) {
            var editorWrap = document.querySelector('#finalIssueCommentEditor').closest('.mb-3');
            if (!editorWrap) return;
            
            var previewHtml = '<div id="issueCommentReplyPreview" class="alert alert-info mb-3" style="display:none; background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%); border: 1px solid #b6d4fe; border-left: 4px solid #0d6efd;">' +
                '<div class="d-flex align-items-start">' +
                    '<div class="flex-shrink-0">' +
                        '<i class="fas fa-reply text-primary me-2" style="font-size: 1.1rem; margin-top: 2px;"></i>' +
                    '</div>' +
                    '<div class="flex-grow-1">' +
                        '<div class="fw-bold text-primary mb-1">' +
                            'Replying to <span id="replyUserName" class="text-decoration-underline"></span>' +
                        '</div>' +
                        '<div class="small text-muted" id="replyCommentText" style="font-style: italic; padding-left: 0.25rem; border-left: 2px solid #dee2e6;"></div>' +
                    '</div>' +
                    '<button type="button" class="btn-close ms-2" id="cancelReply" aria-label="Cancel" style="font-size: 0.75rem;"></button>' +
                '</div>' +
                '<input type="hidden" id="replyToCommentId" value="">' +
            '</div>';
            
            editorWrap.insertAdjacentHTML('afterbegin', previewHtml);
            previewEl = document.getElementById('issueCommentReplyPreview');
            
            // Add cancel handler
            document.getElementById('cancelReply').addEventListener('click', function() {
                previewEl.style.display = 'none';
                document.getElementById('replyToCommentId').value = '';
            });
        }
        
        // Update preview content
        document.getElementById('replyUserName').textContent = userName;
        document.getElementById('replyCommentText').textContent = commentText;
        document.getElementById('replyToCommentId').value = commentId;
        previewEl.style.display = 'block';
        
        // Smooth scroll to editor
        previewEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        
        // Focus editor after a short delay
        setTimeout(function() {
            if (window.jQuery && jQuery.fn.summernote) {
                jQuery('#finalIssueCommentEditor').summernote('focus');
            }
        }, 300);
    }

    function addIssueComment(issueId) {
        var key = issueId || 'new';
        if (key === 'new') { alert('Please save the issue before adding chat.'); return; }
        var html = (window.jQuery && jQuery.fn.summernote) ? jQuery('#finalIssueCommentEditor').summernote('code') : document.getElementById('finalIssueCommentEditor').value;
        if (!String(html || '').replace(/<[^>]*>/g, '').trim()) return;
        
        // Get comment type
        var commentTypeEl = document.getElementById('finalIssueCommentType');
        var commentType = commentTypeEl ? commentTypeEl.value : 'normal';
        
        // Get reply_to if exists
        var replyToEl = document.getElementById('replyToCommentId');
        var replyTo = replyToEl ? replyToEl.value : '';
        
        // Extract mentions from comment
        var mentions = [];
        var mentionRegex = /@(\w+)/g;
        var match;
        while ((match = mentionRegex.exec(html)) !== null) {
            var username = match[1];
            // Find user ID by username (assuming username is full_name for now)
            var users = ProjectConfig.projectUsers || [];
            var user = users.find(function(u) { 
                return u.full_name.toLowerCase().replace(/\s+/g, '') === username.toLowerCase();
            });
            if (user && mentions.indexOf(user.id) === -1) {
                mentions.push(user.id);
            }
        }
        
        var fd = new FormData();
        fd.append('action', 'create'); 
        fd.append('project_id', projectId); 
        fd.append('issue_id', key); 
        fd.append('comment_html', html);
        fd.append('comment_type', commentType);
        fd.append('mentions', JSON.stringify(mentions));
        if (replyTo) {
            fd.append('reply_to', replyTo);
        }
        
        fetch(issueCommentsApi, { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(function (res) {
            if (!res || res.error) return;
            if (!issueData.comments[key]) issueData.comments[key] = [];
            issueData.comments[key].unshift({ 
                user_id: ProjectConfig.userId, 
                user_name: 'You',
                text: html, 
                time: new Date().toLocaleString(),
                reply_to: replyTo || null,
                comment_type: commentType
            });
            if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueCommentEditor').summernote('code', '');
            
            // Reset comment type to normal
            if (commentTypeEl) {
                commentTypeEl.value = 'normal';
            }
            
            // Hide reply preview
            var previewEl = document.getElementById('issueCommentReplyPreview');
            if (previewEl) {
                previewEl.style.display = 'none';
            }
            if (replyToEl) {
                replyToEl.value = '';
            }
            
            renderIssueComments(key);
        });
    }

    function loadIssueComments(issueId) {
        if (!issueId) return;
        fetch(issueCommentsApi + '?action=list&project_id=' + encodeURIComponent(projectId) + '&issue_id=' + encodeURIComponent(issueId), { credentials: 'same-origin' }).then(r => r.json()).then(function (res) {
            if (res && res.comments) {
                issueData.comments[String(issueId)] = res.comments.map(function (c) { 
                    return { 
                        id: c.id,
                        user_id: c.user_id, 
                        user_name: c.user_name, 
                        qa_status: c.qa_status_name || '', 
                        text: c.comment_html, 
                        time: c.created_at,
                        reply_to: c.reply_to || null,
                        reply_preview: c.reply_preview || null,
                        comment_type: c.comment_type || 'normal'
                    }; 
                });
                renderIssueComments(String(issueId));
            }
        });
    }

    function applyPreset(preset) {
        if (!preset) return;
        jQuery('#finalIssueTitle').val(preset.name).trigger('change');
        if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueDetails').summernote('code', preset.description);
        var sev = preset.severity || 'medium';
        var pri = preset.priority || 'medium';
        toggleFinalIssueFields(true);
        var $s = jQuery('#finalIssueField_severity'); if ($s.length) $s.val(sev.toLowerCase()).trigger('change');
        var $p = jQuery('#finalIssueField_priority'); if ($p.length) $p.val(pri.toLowerCase()).trigger('change');

        var meta = preset.meta_json ? JSON.parse(preset.meta_json) : {};
        Object.keys(meta).forEach(function (k) {
            if (['status', 'qa_status', 'pages', 'reporters', 'grouped_urls', 'common_title'].indexOf(k) !== -1) return;
            var dynId = 'finalIssueField_' + k;
            if (document.getElementById(dynId)) {
                jQuery('#' + dynId).val(meta[k]).trigger('change');
            }
        });
    }

    function renderSectionButtons(sections) {
        var wrap = document.getElementById('finalIssueSectionButtons');
        if (!wrap) return;
        wrap.innerHTML = '';
        (sections || []).forEach(function (s) {
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'btn btn-sm btn-outline-secondary'; btn.textContent = s;
            btn.addEventListener('click', function () {
                if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueDetails').summernote('pasteHTML', '<p style="margin-bottom:0;"><strong>[' + escapeHtml(s) + ']</strong></p><p><br></p>');
            });
            wrap.appendChild(btn);
        });
    }

    function ensureDefaultSections() {
        if (!defaultSections.length) return;
        if (window.jQuery && jQuery.fn.summernote) {
            var cur = jQuery('#finalIssueDetails').summernote('code');
            var plain = String(cur || '').replace(/<[^>]*>/g, '').trim();
            if (plain) return;
            var html = defaultSections.map(function (s) { return '<p style="margin-bottom:0;"><strong>[' + escapeHtml(s) + ']</strong></p><p><br></p>'; }).join('');
            jQuery('#finalIssueDetails').summernote('code', html);
        }
    }

    function loadTemplates() {
        if (!issueTemplatesApi) return;
        fetch(issueTemplatesApi + '?action=list&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                issuePresets = res.templates || [];
                defaultSections = res.default_sections || [];
                var sel = document.getElementById('finalIssueTitle');
                if (sel) {
                    // Professional Select2 setup with custom template and fallback
                    if (window.jQuery && jQuery.fn.select2) {
                        // Pehle destroy karo, fir options inject karo, fir select2 init karo
                        try {
                            if (jQuery(sel).data('select2')) {
                                jQuery(sel).select2('destroy');
                            }
                            jQuery(sel).empty();
                            jQuery(sel).append('<option value="">Select preset or type title...</option>');
                            (issuePresets || []).forEach(function (t) {
                                jQuery(sel).append('<option value="PRESET:' + t.id + '">' + t.name + '</option>');
                            });
                        } catch (e) {}
                        jQuery(sel).select2({
                            tags: true,
                            theme: 'bootstrap-5',
                            placeholder: 'Select preset or type title...',
                            dropdownParent: jQuery('#finalIssueModal'),
                            width: '100%',
                            templateResult: function (data) {
                                if (data.loading) return data.text;
                                if (data.id && String(data.id).startsWith('PRESET:')) {
                                    return '<span class="text-primary fw-bold"><i class="fas fa-star me-1"></i>' + data.text + '</span>';
                                }
                                return '<span>' + data.text + '</span>';
                            },
                            templateSelection: function (data) {
                                return data.text;
                            },
                            escapeMarkup: function (m) { return m; }
                        }).on('change', function () {
                            var val = jQuery(this).val();
                            if (val && typeof val === 'string' && val.indexOf('PRESET:') === 0) {
                                var pid = val.split(':')[1];
                                var preset = issuePresets.find(function (p) { return String(p.id) === String(pid); });
                                if (preset) applyPreset(preset);
                            }
                        });
                        // Trigger change on modal open (no auto-focus)
                        jQuery('#finalIssueModal').on('shown.bs.modal', function () {
                            setTimeout(function () {
                                jQuery(sel).trigger('change.select2');
                            }, 300);
                        });
                    } else {
                        // Fallback: datalist input
                        try {
                            sel.innerHTML = '';
                            var container = sel.parentElement;
                            var input = document.createElement('input');
                            input.type = 'text'; input.id = 'finalIssueTitleInput'; input.className = 'form-control form-control-lg';
                            input.placeholder = 'Type issue title...';
                            var dl = document.createElement('datalist'); dl.id = 'finalIssueTitleList';
                            issuePresets.forEach(function (t) { var o = document.createElement('option'); o.value = t.name; dl.appendChild(o); });
                            container.replaceChild(input, sel);
                            container.appendChild(dl);
                            input.setAttribute('list', dl.id);
                        } catch (e) { }
                    }
                }
                renderSectionButtons(defaultSections);
            });
    }

    function applyMetadataOptions(fields) {
        if (!fields || !fields.length) return;
        var container = document.getElementById('finalIssueMetadataContainer');
        if (!container) return;
        container.innerHTML = '';
        fields.forEach(function (f) {
            var label = document.createElement('label'); label.className = 'form-label mt-2'; label.textContent = f.field_label; container.appendChild(label);
            var select = document.createElement('select'); select.className = 'form-select form-select-sm issue-dynamic-field issue-select2-tags';
            select.id = 'finalIssueField_' + f.field_key; select.multiple = true;
            (f.options || []).forEach(function (o) { select.appendChild(new Option(o.option_label, o.option_value)); });
            container.appendChild(select);
        });
        if (window.jQuery && jQuery.fn.select2) {
            jQuery('.issue-dynamic-field.issue-select2-tags').select2({ width: '100%', tags: true, tokenSeparators: [','], dropdownParent: jQuery('#finalIssueModal') });
        }
    }

    function loadMetadataOptions() {
        if (!issueTemplatesApi) return;
        fetch(issueTemplatesApi + '?action=metadata_options&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res && res.fields) { issueMetadataFields = res.fields; applyMetadataOptions(res.fields); }
            });
    }

    async function addOrUpdateFinalIssue() {
        if (!issueData.selectedPageId) return;
        var editId = document.getElementById('finalIssueEditId').value;
        var titleVal = '';
        var titleInput = document.getElementById('customIssueTitle');
        if (titleInput) {
            titleVal = titleInput.value.trim();
        }
        var data = {
            title: titleVal,
            details: jQuery('#finalIssueDetails').summernote('code'),
            status: document.getElementById('finalIssueStatus').value,
            qa_status: jQuery('#finalIssueQaStatus').val() || [],
            priority: document.getElementById('finalIssueField_priority') ? document.getElementById('finalIssueField_priority').value : 'medium',
            pages: jQuery('#finalIssuePages').val() || [],
            grouped_urls: jQuery('#finalIssueGroupedUrls').val() || [],
            reporters: jQuery('#finalIssueReporters').val() || [],
            common_title: document.getElementById('finalIssueCommonTitle').value.trim()
        };

        if (typeof issueMetadataFields !== 'undefined') {
            issueMetadataFields.forEach(function (f) {
                var el = document.getElementById('finalIssueField_' + f.field_key);
                if (el) {
                    var value = jQuery(el).val();
                    data[f.field_key] = value;
                }
            });
        }

        // Separate metadata fields
        var metadata = {};
        if (typeof issueMetadataFields !== 'undefined') {
            issueMetadataFields.forEach(function (f) {
                if (data.hasOwnProperty(f.field_key)) {
                    metadata[f.field_key] = data[f.field_key];
                    delete data[f.field_key];
                }
            });
        }

        if (!data.title) { alert('Issue title is required.'); return; }

        try {
            var fd = new FormData();
            fd.append('action', editId ? 'update' : 'create');
            fd.append('project_id', projectId);
            if (editId) fd.append('id', editId);
            fd.append('page_id', issueData.selectedPageId);
            fd.append('metadata', JSON.stringify(metadata));

            Object.keys(data).forEach(function (k) {
                var v = data[k];
                if (Array.isArray(v)) { fd.append(k, JSON.stringify(v)); }
                else {
                    if (k === 'status') fd.append('issue_status', v);
                    else if (k === 'details') fd.append('description', v);
                    else fd.append(k, v);
                }
            });

            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            
            if (!json || json.error) {
                throw new Error(json && json.error ? json.error : 'Save failed');
            }
            
            if (!json.success) {
                throw new Error('Save failed - server returned unsuccessful response');
            }

            var store = issueData.pages;
            ensurePageStore(store, issueData.selectedPageId);
            var pagesArr = (data.pages && data.pages.length) ? data.pages : [issueData.selectedPageId];

            var payload = Object.assign({ id: String(editId || json.id || ''), issue_key: String(json.issue_key || '') }, data);
            var list = store[issueData.selectedPageId].final || [];
            var idx = list.findIndex(function (it) { return String(it.id) === String(payload.id); });
            if (idx >= 0) list[idx] = payload; else list.unshift(payload);
            store[issueData.selectedPageId].final = list;

            renderFinalIssues();
            updateSelectionButtons();
            showFinalIssuesTab();

            if (!editId && issueData.comments['new']) {
                issueData.comments[String(json.id)] = issueData.comments['new'];
                delete issueData.comments['new'];
            }
            if (!editId) await deleteDraft();
            stopDraftAutosave();
            issueData.initialFormState = null;
            hideEditors();
            await loadFinalIssues(issueData.selectedPageId);
            await loadCommonIssues();
        } catch (e) { 
            alert('Unable to save issue: ' + e.message); 
        }
    }

    async function addOrUpdateReviewIssue() {
        if (!issueData.selectedPageId) return;
        var editId = document.getElementById('reviewIssueEditId').value;
        var data = {
            title: document.getElementById('reviewIssueTitle').value.trim(),
            instance: document.getElementById('reviewIssueInstance').value.trim(),
            wcag: document.getElementById('reviewIssueWcag').value.trim(),
            severity: document.getElementById('reviewIssueSeverity').value,
            details: jQuery('#reviewIssueDetails').summernote('code')
        };
        if (!data.title) { alert('Issue title is required.'); return; }
        if (!data.details || data.details.trim() === '') data.details = data.title;
        try {
            var fd = new FormData();
            fd.append('project_id', projectId);
            fd.append('page_id', issueData.selectedPageId);
            fd.append('title', data.title);
            fd.append('instance_name', data.instance);
            fd.append('wcag_failure', data.wcag);
            fd.append('details', data.details);
            fd.append('summary', '');
            fd.append('snippet', '');
            if (editId) { fd.append('action', 'update'); fd.append('id', editId); } else { fd.append('action', 'create'); }
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Save failed');
            hideEditors();
            await loadReviewFindings(issueData.selectedPageId);
        } catch (e) { alert('Unable to save automated finding.'); }
    }

    async function addOrUpdateCommonIssue() {
        var editId = document.getElementById('commonIssueEditId').value;
        var data = {
            title: document.getElementById('commonIssueTitle').value.trim(),
            pages: jQuery('#commonIssuePages').val() || [],
            details: jQuery('#commonIssueDetails').summernote('code')
        };
        if (!data.title) { alert('Common issue title is required.'); return; }
        try {
            var fd = new FormData();
            fd.append('action', editId ? 'common_update' : 'common_create');
            fd.append('project_id', projectId);
            if (editId) fd.append('id', editId);
            fd.append('title', data.title);
            fd.append('description', data.details);
            fd.append('pages', JSON.stringify(data.pages || []));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Save failed');
            hideEditors();
            await loadCommonIssues();
        } catch (e) { alert('Unable to save common issue.'); }
    }

    async function moveReviewToFinal() {
        if (!issueData.selectedPageId) return;
        var selected = Array.from(document.querySelectorAll('.review-select:checked')).map(function (el) { return el.getAttribute('data-id'); });
        if (!selected.length) return;
        try {
            var fd = new FormData();
            fd.append('action', 'move_to_issue'); fd.append('project_id', projectId); fd.append('ids', selected.join(','));
            var res = await fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Move failed');
            await loadReviewFindings(issueData.selectedPageId);
            await loadFinalIssues(issueData.selectedPageId);
        } catch (e) { alert('Unable to move findings to final report.'); }
    }

    async function deleteReviewIds(ids) {
        if (!ids.length) return;
        try {
            var fd = new FormData(); fd.append('action', 'delete'); fd.append('project_id', projectId); fd.append('ids', ids.join(','));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Delete failed');
            await loadReviewFindings(issueData.selectedPageId);
        } catch (e) { alert('Unable to delete automated findings.'); }
    }

    async function deleteFinalIds(ids) {
        if (!ids.length) return;
        try {
            var fd = new FormData(); fd.append('action', 'delete'); fd.append('project_id', projectId); fd.append('ids', ids.join(','));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Delete failed');
            await loadFinalIssues(issueData.selectedPageId);
            await loadCommonIssues();
        } catch (e) { alert('Unable to delete issues.'); }
    }

    async function deleteCommonIds(ids) {
        if (!ids.length) return;
        try {
            var fd = new FormData(); fd.append('action', 'common_delete'); fd.append('project_id', projectId); fd.append('ids', ids.join(','));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Delete failed');
            await loadCommonIssues();
        } catch (e) { alert('Unable to delete common issues.'); }
    }

    async function deleteSelected(type) {
        if (!issueData.selectedPageId && type !== 'common') return;
        if (type === 'final' || type === 'review') {
            var sel = Array.from(document.querySelectorAll('.' + type + '-select:checked')).map(function (el) { return el.getAttribute('data-id'); });
            if (!sel.length) return;
            if (type === 'review') await deleteReviewIds(sel); else await deleteFinalIds(sel);
        } else if (type === 'common') {
            var selC = Array.from(document.querySelectorAll('.common-select:checked')).map(function (el) { return el.getAttribute('data-id'); });
            if (!selC.length) return;
            await deleteCommonIds(selC);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        try { } catch (e) {}
        initSelect2();
        initEditors();
        loadTemplates();
        loadMetadataOptions();

        // Only attach page click listeners if issues tab is active
        var issuesTab = document.querySelector('#issues');
        if (issuesTab && issuesTab.classList.contains('active')) {
            attachPageClickListeners();
        }

        // Auto-select first page if issues tab is active
        var firstPageBtn = document.querySelector('#issuesPageList .issues-page-row');
        if (firstPageBtn && issuesTab && issuesTab.classList.contains('active')) {
            var pageId = firstPageBtn.getAttribute('data-page-id');
            if (pageId && pageId !== '0') {
                setSelectedPage(firstPageBtn);
            } else {
                var uniqueId = firstPageBtn.getAttribute('data-unique-id');
                if (uniqueId) setSelectedUniquePage(firstPageBtn, uniqueId);
            }
        }
    });

    // Prevent page reload/navigation when there are unsaved changes
    window.addEventListener('beforeunload', function (e) {
        if (hasFormChanges()) {
            e.preventDefault();
            e.returnValue = ''; // Chrome requires returnValue to be set
            return ''; // For older browsers
        }
    });

    // New function for unique page selection
    window.setSelectedUniquePage = function(btn, uniqueId) {
            document.querySelectorAll('#issuesPageList .issues-page-row').forEach(function (b) { b.classList.remove('table-active'); });
            btn.classList.add('table-active');
            // Show details section
            var name = btn.getAttribute('data-page-name') || 'Page';
            var tester = btn.getAttribute('data-page-tester') || '-';
            var env = btn.getAttribute('data-page-env') || '-';
            var issues = btn.getAttribute('data-page-issues') || '0';
            var nameEl = document.getElementById('issueSelectedPageName');
            var metaEl = document.getElementById('issueSelectedPageMeta');
            if (nameEl) nameEl.textContent = name;
            if (metaEl) metaEl.textContent = 'Tester: ' + tester + ' | Env: ' + env + ' | Issues: ' + issues;
            // Show/hide columns
            var pagesCol = document.getElementById('issuesPagesCol');
            var detailCol = document.getElementById('issuesDetailCol');
            var backBtn = document.getElementById('issuesBackBtn');
            if (pagesCol) pagesCol.classList.add('d-none');
            if (detailCol) {
                detailCol.classList.remove('d-none');
                detailCol.classList.remove('col-lg-8');
                detailCol.classList.add('col-lg-12');
            }
            if (backBtn) backBtn.classList.remove('d-none');
            // If we have a mapped page id, load issues for it
            var pageId = btn.getAttribute('data-page-id');
            if (pageId && pageId !== '0') {
                issueData.selectedPageId = pageId;
                ensurePageStore(issueData.pages, issueData.selectedPageId);
                updateEditingState();
                populatePageUrls(issueData.selectedPageId);
                renderAll();
                loadReviewFindings(issueData.selectedPageId);
                loadFinalIssues(issueData.selectedPageId);
            }
        };

        var issuesTabBtn = document.querySelector('button[data-bs-target="#issues"]');
        if (issuesTabBtn) {
            issuesTabBtn.addEventListener('shown.bs.tab', function () {
                attachPageClickListeners();

                if (!issueData.selectedPageId) {
                    var fp = document.querySelector('#issuesPageList .issues-page-row');
                    if (fp) {
                        var pageId = fp.getAttribute('data-page-id');
                        if (pageId && pageId !== '0') {
                            setSelectedPage(fp);
                        } else {
                            var uniqueId = fp.getAttribute('data-unique-id');
                            if (uniqueId) setSelectedUniquePage(fp, uniqueId);
                        }
                    }
                } else {
                    updateModeUI();
                    renderAll();
                    showFinalIssuesTab();
                }
            });
        }

        var addF = document.getElementById('issueAddFinalBtn'); if (addF) addF.addEventListener('click', function () { openFinalEditor(null); });

        var finalIssueModalEl = document.getElementById('finalIssueModal');
        if (finalIssueModalEl) {
            finalIssueModalEl.addEventListener('hide.bs.modal', function (e) {
                var editId = document.getElementById('finalIssueEditId').value;
                // Check for changes in both NEW and EDIT modes
                if (hasFormChanges()) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Show custom confirmation modal
                    showDraftConfirmation(function(action) {
                        if (action === 'save') {
                            // For new issues, save as draft; for edit, save the issue
                            if (!editId) {
                                saveDraft().then(function () {
                                    stopDraftAutosave();
                                    issueData.initialFormState = null;
                                    var modal = bootstrap.Modal.getInstance(finalIssueModalEl);
                                    if (modal) modal.hide();
                                });
                            } else {
                                // For edit mode, trigger save button click
                                document.getElementById('finalIssueSaveBtn').click();
                                // Modal will close after successful save
                            }
                        } else if (action === 'discard') {
                            if (!editId) {
                                deleteDraft().then(function () {
                                    stopDraftAutosave();
                                    issueData.initialFormState = null;
                                    var modal = bootstrap.Modal.getInstance(finalIssueModalEl);
                                    if (modal) modal.hide();
                                });
                            } else {
                                // For edit mode, just close without saving
                                stopDraftAutosave();
                                issueData.initialFormState = null;
                                var modal = bootstrap.Modal.getInstance(finalIssueModalEl);
                                if (modal) modal.hide();
                            }
                        }
                        // If action === 'keep', do nothing (modal stays open)
                    }, editId);
                } else {
                    stopDraftAutosave();
                    issueData.initialFormState = null;
                }
            });
        }
        
        // Draft confirmation modal function
        function showDraftConfirmation(callback, editId) {
            var isEditMode = !!editId;
            var saveButtonText = isEditMode ? 'Save Changes' : 'Save Draft';
            var saveButtonIcon = isEditMode ? 'save' : 'file-alt';
            
            var modalHtml = `
                <div class="modal fade" id="draftConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-warning-subtle">
                                <h5 class="modal-title">
                                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                    Unsaved Changes
                                </h5>
                            </div>
                            <div class="modal-body">
                                <p class="mb-0">You have unsaved changes in this issue. What would you like to do?</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" id="draftKeepEditing">
                                    <i class="fas fa-edit me-1"></i> Keep Editing
                                </button>
                                <button type="button" class="btn btn-outline-danger" id="draftDiscard">
                                    <i class="fas fa-trash me-1"></i> Discard
                                </button>
                                <button type="button" class="btn btn-primary" id="draftSave">
                                    <i class="fas fa-${saveButtonIcon} me-1"></i> ${saveButtonText}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            var existing = document.getElementById('draftConfirmModal');
            if (existing) existing.remove();
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            var confirmModal = document.getElementById('draftConfirmModal');
            var bsModal = new bootstrap.Modal(confirmModal);
            
            // Event listeners
            document.getElementById('draftSave').addEventListener('click', function() {
                bsModal.hide();
                callback('save');
            });
            
            document.getElementById('draftDiscard').addEventListener('click', function() {
                bsModal.hide();
                callback('discard');
            });
            
            document.getElementById('draftKeepEditing').addEventListener('click', function() {
                bsModal.hide();
                callback('keep');
            });
            
            // Cleanup after modal is hidden
            confirmModal.addEventListener('hidden.bs.modal', function() {
                confirmModal.remove();
            });
            
            bsModal.show();
        }

        var addR = document.getElementById('reviewAddBtn'); if (addR) addR.addEventListener('click', function () { openReviewEditor(null); });
        var saveF = document.getElementById('finalIssueSaveBtn'); if (saveF) { 
            saveF.addEventListener('click', addOrUpdateFinalIssue);
        }
        var pageSel = jQuery('#finalIssuePages');
        if (pageSel && pageSel.length) {
            pageSel.on('change', function () { updateGroupedUrls(); toggleCommonTitle(); });
        }

        var tplApply = document.getElementById('finalIssueApplyTemplateBtn');
        if (tplApply) {
            tplApply.addEventListener('click', function (e) {
                e.preventDefault();
                var sel = document.getElementById('finalIssueTemplate');
                var id = sel ? sel.value : '';
                if (!id) return;
                var tpl = itemTemplates.find(function (t) { return String(t.id) === String(id); });
                if (tpl) applyPreset(tpl);
            });
        }

        var addCBtn = document.getElementById('finalIssueAddCommentBtn');
        if (addCBtn) {
            addCBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var id = document.getElementById('finalIssueEditId').value || 'new';
                addIssueComment(String(id));
            });
        }

        var resetBtn = document.getElementById('btnResetToTemplate');
        if (resetBtn) {
            resetBtn.addEventListener('click', function (e) {
                e.preventDefault();
                ensureDefaultSections();
            });
        }

        var historyBtn = document.getElementById('btnShowHistory');
        if (historyBtn) {
            historyBtn.addEventListener('shown.bs.tab', function () {
                var id = document.getElementById('finalIssueEditId').value;
                if (!id) {
                    document.getElementById('historyEntries').innerHTML = '<div class="text-center py-4 text-muted">No history for new issues.</div>';
                    return;
                }
                fetch(ProjectConfig.baseDir + '/api/issue_history.php?issue_id=' + id, { credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (res) {
                        var wrap = document.getElementById('historyEntries');
                        if (!wrap || !res || !res.history) return;
                        if (!res.history.length) { wrap.innerHTML = '<div class="text-center py-4 text-muted">No edits recorded yet.</div>'; return; }
                        
                        // Helper function to strip HTML tags and preserve spacing
                        var stripHtml = function(html) {
                            if (!html) return '';
                            var tmp = document.createElement('div');
                            // Replace block-level tags with spaces to prevent word merging
                            html = html.replace(/<\/(p|div|h[1-6]|li|tr|td|th|br)>/gi, ' ');
                            html = html.replace(/<br\s*\/?>/gi, ' ');
                            tmp.innerHTML = html;
                            var text = tmp.textContent || tmp.innerText || '';
                            // Clean up multiple spaces
                            text = text.replace(/\s+/g, ' ').trim();
                            return text;
                        };
                        
                        wrap.innerHTML = res.history.map(function (h, idx) {
                            var oldVal = h.old_value || '';
                            var newVal = h.new_value || '';
                            var fieldName = h.field_name || 'field';
                            var uniqueId = 'history-' + idx;
                            
                            // Format field name: remove "meta:" prefix and format nicely
                            var displayFieldName = fieldName;
                            if (fieldName.startsWith('meta:')) {
                                displayFieldName = fieldName.substring(5); // Remove "meta:"
                            }
                            // Format: qa_status → QA Status, severity → Severity
                            displayFieldName = displayFieldName.split('_').map(function(word) {
                                return word.charAt(0).toUpperCase() + word.slice(1);
                            }).join(' ');
                            
                            // Format QA status values if it's qa_status field
                            if (fieldName === 'meta:qa_status' || fieldName === 'qa_status') {
                                // Format old value
                                if (oldVal) {
                                    try {
                                        var parsed = JSON.parse(oldVal);
                                        if (Array.isArray(parsed)) {
                                            oldVal = parsed.map(function(v) {
                                                return v.split('_').map(function(w) {
                                                    return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                                                }).join(' ');
                                            }).join(', ');
                                        }
                                    } catch(e) {
                                        // If not JSON, format the string
                                        oldVal = oldVal.split('_').map(function(w) {
                                            return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                                        }).join(' ');
                                    }
                                }
                                // Format new value
                                if (newVal) {
                                    try {
                                        var parsed = JSON.parse(newVal);
                                        if (Array.isArray(parsed)) {
                                            newVal = parsed.map(function(v) {
                                                return v.split('_').map(function(w) {
                                                    return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                                                }).join(' ');
                                            }).join(', ');
                                        }
                                    } catch(e) {
                                        // If not JSON, format the string
                                        newVal = newVal.split('_').map(function(w) {
                                            return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                                        }).join(' ');
                                    }
                                }
                            }
                            
                            // For description field, create inline diff view
                            if (fieldName === 'description') {
                                var oldText = stripHtml(oldVal);
                                var newText = stripHtml(newVal);
                                
                                // If texts are identical, show a message
                                if (oldText.trim() === newText.trim()) {
                                    return '<div class="issue-history-entry border rounded p-3 mb-3" style="background-color:#f8f9fa;">' +
                                        '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                                            '<small class="text-muted text-uppercase" style="font-weight:600;letter-spacing:0.5px;">' +
                                                '<i class="fas fa-edit me-1"></i>' + escapeHtml(displayFieldName) +
                                            '</small>' +
                                            '<small class="text-muted">' +
                                                '<i class="fas fa-user me-1"></i><strong>' + escapeHtml(h.user_name) + '</strong> • ' +
                                                '<i class="fas fa-clock me-1"></i>' + h.created_at +
                                            '</small>' +
                                        '</div>' +
                                        '<div class="alert alert-info mb-0">' +
                                            '<i class="fas fa-info-circle me-2"></i>No visible changes detected (possibly formatting or whitespace changes)' +
                                        '</div>' +
                                    '</div>';
                                }
                                
                                // Split by words and spaces, keeping delimiters
                                var oldWords = oldText.split(/(\s+)/);
                                var newWords = newText.split(/(\s+)/);
                                
                                // LCS-based diff algorithm
                                var lcs = function(arr1, arr2) {
                                    var m = arr1.length;
                                    var n = arr2.length;
                                    var dp = [];
                                    
                                    // Initialize DP table
                                    for (var i = 0; i <= m; i++) {
                                        dp[i] = [];
                                        for (var j = 0; j <= n; j++) {
                                            dp[i][j] = 0;
                                        }
                                    }
                                    
                                    // Fill DP table
                                    for (var i = 1; i <= m; i++) {
                                        for (var j = 1; j <= n; j++) {
                                            if (arr1[i-1] === arr2[j-1]) {
                                                dp[i][j] = dp[i-1][j-1] + 1;
                                            } else {
                                                dp[i][j] = Math.max(dp[i-1][j], dp[i][j-1]);
                                            }
                                        }
                                    }
                                    
                                    // Backtrack to find LCS
                                    var result = [];
                                    var i = m, j = n;
                                    while (i > 0 && j > 0) {
                                        if (arr1[i-1] === arr2[j-1]) {
                                            result.unshift({type: 'common', value: arr1[i-1], oldIdx: i-1, newIdx: j-1});
                                            i--;
                                            j--;
                                        } else if (dp[i-1][j] > dp[i][j-1]) {
                                            i--;
                                        } else {
                                            j--;
                                        }
                                    }
                                    
                                    return result;
                                };
                                
                                // Get LCS
                                var common = lcs(oldWords, newWords);
                                
                                // Build diff HTML
                                var diffHtml = '';
                                var oldIdx = 0;
                                var newIdx = 0;
                                
                                for (var k = 0; k < common.length; k++) {
                                    var item = common[k];
                                    
                                    // Add removed words before this common word
                                    if (oldIdx < item.oldIdx) {
                                        var removedText = '';
                                        while (oldIdx < item.oldIdx) {
                                            removedText += escapeHtml(oldWords[oldIdx]);
                                            oldIdx++;
                                        }
                                        diffHtml += '<span style="background-color:#ffd7d5;color:#d73a49;text-decoration:line-through;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + removedText + '</span>';
                                    }
                                    
                                    // Add added words before this common word
                                    if (newIdx < item.newIdx) {
                                        var addedText = '';
                                        while (newIdx < item.newIdx) {
                                            addedText += escapeHtml(newWords[newIdx]);
                                            newIdx++;
                                        }
                                        diffHtml += '<span style="background-color:#d4edda;color:#28a745;font-weight:600;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + addedText + '</span>';
                                    }
                                    
                                    // Add the common word
                                    diffHtml += escapeHtml(item.value);
                                    oldIdx++;
                                    newIdx++;
                                }
                                
                                // Add remaining removed words
                                if (oldIdx < oldWords.length) {
                                    var removedText = '';
                                    while (oldIdx < oldWords.length) {
                                        removedText += escapeHtml(oldWords[oldIdx]);
                                        oldIdx++;
                                    }
                                    diffHtml += '<span style="background-color:#ffd7d5;color:#d73a49;text-decoration:line-through;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + removedText + '</span>';
                                }
                                
                                // Add remaining added words
                                if (newIdx < newWords.length) {
                                    var addedText = '';
                                    while (newIdx < newWords.length) {
                                        addedText += escapeHtml(newWords[newIdx]);
                                        newIdx++;
                                    }
                                    diffHtml += '<span style="background-color:#d4edda;color:#28a745;font-weight:600;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + addedText + '</span>';
                                }
                                
                                // Ensure diffHtml is valid
                                if (!diffHtml || diffHtml.trim() === '') {
                                    diffHtml = escapeHtml(newText); // Fallback
                                }
                                
                                var preview = diffHtml;
                                var needsExpand = false;
                                if (diffHtml.length > 300) {
                                    needsExpand = true;
                                    // Try to truncate at the end of a span to keep HTML valid
                                    var truncated = diffHtml.substring(0, 300);
                                    var lastSpanEnd = truncated.lastIndexOf('</span>');
                                    if (lastSpanEnd > 0 && lastSpanEnd < 300) {
                                        preview = truncated.substring(0, lastSpanEnd + 7) + '...';
                                    } else {
                                        // Fallback to plain text truncation
                                        var plainText = stripHtml(diffHtml);
                                        preview = escapeHtml(plainText.substring(0, 200) + '...');
                                    }
                                }
                                
                                return '<div class="issue-history-entry border rounded p-3 mb-3" style="background-color:#f8f9fa;">' +
                                    '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                                        '<small class="text-muted text-uppercase" style="font-weight:600;letter-spacing:0.5px;">' +
                                            '<i class="fas fa-edit me-1"></i>' + escapeHtml(displayFieldName) +
                                        '</small>' +
                                        '<small class="text-muted">' +
                                            '<i class="fas fa-user me-1"></i><strong>' + escapeHtml(h.user_name) + '</strong> • ' +
                                            '<i class="fas fa-clock me-1"></i>' + h.created_at +
                                        '</small>' +
                                    '</div>' +
                                    '<div class="diff-container bg-white p-3 rounded border" style="line-height: 1.8;">' +
                                        '<div class="diff-preview" id="preview-' + uniqueId + '" style="white-space: pre-wrap; word-wrap: break-word;">' +
                                            preview +
                                        '</div>' +
                                        (needsExpand ? 
                                            '<div class="diff-full" id="full-' + uniqueId + '" style="display:none;white-space:pre-wrap;word-wrap:break-word;line-height:1.8;">' +
                                                diffHtml +
                                            '</div>' +
                                            '<button class="btn btn-sm btn-outline-primary mt-2" onclick="toggleHistoryDiff(\'' + uniqueId + '\', event)">' +
                                                '<i class="fas fa-chevron-down me-1"></i>' +
                                                '<span class="toggle-text">Read More</span>' +
                                            '</button>'
                                        : '') +
                                    '</div>' +
                                    '<div class="mt-2 small">' +
                                        '<span class="badge bg-danger-subtle text-danger me-2">' +
                                            '<i class="fas fa-minus me-1"></i>Removed' +
                                        '</span>' +
                                        '<span class="badge bg-success-subtle text-success">' +
                                            '<i class="fas fa-plus me-1"></i>Added' +
                                        '</span>' +
                                    '</div>' +
                                '</div>';
                            } else {
                                // For other fields, simple before/after
                                return '<div class="issue-history-entry border rounded p-3 mb-3" style="background-color:#f8f9fa;">' +
                                    '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                                        '<small class="text-muted text-uppercase" style="font-weight:600;letter-spacing:0.5px;">' +
                                            '<i class="fas fa-edit me-1"></i>' + escapeHtml(displayFieldName) +
                                        '</small>' +
                                        '<small class="text-muted">' +
                                            '<i class="fas fa-user me-1"></i><strong>' + escapeHtml(h.user_name) + '</strong> • ' +
                                            '<i class="fas fa-clock me-1"></i>' + h.created_at +
                                        '</small>' +
                                    '</div>' +
                                    '<div class="row g-2 bg-white p-3 rounded border">' +
                                        '<div class="col-md-5">' +
                                            '<div class="small text-muted mb-1 fw-bold">Before:</div>' +
                                            '<div class="p-2 bg-danger-subtle text-danger rounded border border-danger">' +
                                                '<small>' + escapeHtml(oldVal || 'N/A') + '</small>' +
                                            '</div>' +
                                        '</div>' +
                                        '<div class="col-md-2 d-flex align-items-center justify-content-center">' +
                                            '<i class="fas fa-arrow-right text-primary fs-4"></i>' +
                                        '</div>' +
                                        '<div class="col-md-5">' +
                                            '<div class="small text-muted mb-1 fw-bold">After:</div>' +
                                            '<div class="p-2 bg-success-subtle text-success rounded border border-success">' +
                                                '<small>' + escapeHtml(newVal || 'N/A') + '</small>' +
                                            '</div>' +
                                        '</div>' +
                                    '</div>' +
                                '</div>';
                            }
                        }).join('');
                        
                        // Add toggle function to window scope
                        window.toggleHistoryDiff = function(id, event) {
                            var preview = document.getElementById('preview-' + id);
                            var full = document.getElementById('full-' + id);
                            var btn = event.target.closest('button');
                            var icon = btn.querySelector('i');
                            var text = btn.querySelector('.toggle-text');
                            
                            if (full.style.display === 'none' || full.style.display === '') {
                                preview.style.display = 'none';
                                full.style.display = 'block';
                                icon.className = 'fas fa-chevron-up me-1';
                                text.textContent = 'Read Less';
                            } else {
                                preview.style.display = 'block';
                                full.style.display = 'none';
                                icon.className = 'fas fa-chevron-down me-1';
                                text.textContent = 'Read More';
                            }
                        };
                    });
            });
        }

        var chatBtn = document.getElementById('btnShowChat');
        if (chatBtn) {
            chatBtn.addEventListener('shown.bs.tab', function () {
                var id = document.getElementById('finalIssueEditId').value || 'new';
                renderIssueComments(String(id));
                if (window.jQuery && jQuery.fn.summernote) { jQuery('#finalIssueCommentEditor').summernote('code', jQuery('#finalIssueCommentEditor').summernote('code')); }
            });
        }

        var finalSubTabBtn = document.getElementById('final-issues-tab');
        if (finalSubTabBtn) { finalSubTabBtn.addEventListener('shown.bs.tab', function () { renderFinalIssues(); }); }

        document.addEventListener('click', function (e) {
            var target = e.target;
            if (target && target.classList && target.classList.contains('issue-image-thumb')) {
                e.preventDefault();
                var src = target.getAttribute('src');
                if (src) openIssueImageModal(src);
            }
        });

        document.addEventListener('shown.bs.collapse', function (e) {
            var id = e.target && e.target.id ? e.target.id : '';
            if (!id) return;
            var btn = document.querySelector('[data-bs-target="#' + id + '"]');
            if (btn && btn.classList.contains('issue-url-toggle')) { btn.innerHTML = '<i class="fas fa-eye-slash"></i>'; }
        });
        document.addEventListener('hidden.bs.collapse', function (e) {
            var id = e.target && e.target.id ? e.target.id : '';
            if (!id) return;
            var btn = document.querySelector('[data-bs-target="#' + id + '"]');
            if (btn && btn.classList.contains('issue-url-toggle')) { btn.innerHTML = '<i class="fas fa-globe"></i>'; }
        });

        var saveR = document.getElementById('reviewIssueSaveBtn'); if (saveR) saveR.addEventListener('click', addOrUpdateReviewIssue);
        
        // Use event delegation for commonAddBtn to handle dynamic loading
        document.addEventListener('click', function(e) {
            var target = e.target;
            var commonBtn = target.closest('#commonAddBtn');
            
            if (commonBtn || (target && target.id === 'commonAddBtn')) {
                e.preventDefault();
                e.stopPropagation();
                openCommonEditor(null);
            }
        });
        
        var saveCom = document.getElementById('commonIssueSaveBtn'); if (saveCom) saveCom.addEventListener('click', addOrUpdateCommonIssue);
        var backBtn = document.getElementById('issuesBackBtn'); if (backBtn) backBtn.addEventListener('click', showIssuesPages);

        var delF = document.getElementById('finalDeleteSelected'); if (delF) delF.addEventListener('click', function () { 
            if (typeof confirmModal === 'function') {
                confirmModal('Delete selected issues? This action cannot be undone.', function() { deleteSelected('final'); });
            } else {
                if (confirm('Delete selected issues?')) deleteSelected('final');
            }
        });
        var delR = document.getElementById('reviewDeleteSelected'); if (delR) delR.addEventListener('click', function () { 
            if (typeof confirmModal === 'function') {
                confirmModal('Delete selected findings? This action cannot be undone.', function() { deleteSelected('review'); });
            } else {
                if (confirm('Delete selected findings?')) deleteSelected('review');
            }
        });
        var movR = document.getElementById('reviewMoveSelected'); if (movR) movR.addEventListener('click', moveReviewToFinal);

        ['common', 'final', 'review'].forEach(function (t) {
            var c = document.getElementById(t + 'SelectAll');
            if (c) c.addEventListener('change', function (e) {
                document.querySelectorAll('.' + t + '-select').forEach(function (cb) { cb.checked = e.target.checked; });
                updateSelectionButtons();
            });
            var body = document.getElementById(t + 'IssuesBody');
            if (body) {
                body.addEventListener('change', updateSelectionButtons);
                body.addEventListener('click', function (e) {
                    var target = e.target.closest('.' + t + '-edit, .' + t + '-delete, .issue-open');
                    if (!target) return;
                    
                    var id = target.getAttribute('data-id');
                    
                    if (target.classList.contains(t + '-edit') || target.classList.contains('issue-open')) {
                        if (t === 'final') { 
                            var i = issueData.pages[issueData.selectedPageId].final.find(function (x) { return String(x.id) === id; }); 
                            openFinalEditor(i);
                        }
                        if (t === 'review') { var i = issueData.pages[issueData.selectedPageId].review.find(function (x) { return String(x.id) === id; }); openReviewEditor(i); }
                        if (t === 'common') { 
                            var i = issueData.common.find(function (x) { return String(x.id) === id; }); 
                            
                            if (i) {
                                var actualIssueId = i.issue_id;
                                
                                if (i.pages && i.pages.length > 0) {
                                    var firstPageId = i.pages[0];
                                    
                                    ensurePageStore(issueData.pages, firstPageId);
                                    issueData.selectedPageId = firstPageId;
                                    
                                    if (!issueData.pages[firstPageId].final || issueData.pages[firstPageId].final.length === 0) {
                                        loadFinalIssues(firstPageId).then(function() {
                                            var finalIssue = issueData.pages[firstPageId].final.find(function(x) {
                                                return String(x.id) === String(actualIssueId);
                                            });
                                            
                                            if (finalIssue) {
                                                openFinalEditor(finalIssue);
                                            }
                                        }).catch(function(error) {
                                            console.error('Error loading issues:', error);
                                        });
                                    } else {
                                        var finalIssue = issueData.pages[firstPageId].final.find(function(x) {
                                            return String(x.id) === String(actualIssueId);
                                        });
                                        
                                        if (finalIssue) {
                                            openFinalEditor(finalIssue);
                                        }
                                    }
                                }
                            }
                        }
                    } else if (target.classList.contains(t + '-delete')) {
                        if (typeof confirmModal === 'function') {
                            confirmModal('Delete this item? This action cannot be undone.', function() {
                                if (t === 'final') deleteFinalIds([id]);
                                if (t === 'review') deleteReviewIds([id]);
                                if (t === 'common') deleteCommonIds([id]);
                            });
                        } else {
                            if (confirm('Delete this item?')) {
                                if (t === 'final') deleteFinalIds([id]);
                                if (t === 'review') deleteReviewIds([id]);
                                if (t === 'common') deleteCommonIds([id]);
                            }
                        }
                    }
                });
            }
        });
        
        if (finalIssueModalEl) {
            finalIssueModalEl.addEventListener('shown.bs.modal', function () {
                // No auto-focus - let modal container handle focus
                
                // Legacy code for old select field (if it exists)
                var sel = document.getElementById('finalIssueTitle');
                if (sel) {
                    sel.disabled = false;
                    if (window.jQuery && jQuery.fn.select2) {
                        jQuery('#finalIssueTitle').prop('disabled', false).trigger('change.select2');
                    }
                }
            });
        }

        initSelect2();
        initSummernote();
        loadCommonIssues();

    // Define editFinalIssue for table edit buttons
    window.editFinalIssue = function(id) {
		var issue = issueData.pages[issueData.selectedPageId].final.find(function(i) { return String(i.id) === String(id); });
		if (issue) openFinalEditor(issue);
    };
    
    // Expose necessary functions globally for external pages
    window.loadFinalIssues = loadFinalIssues;
    window.loadReviewFindings = loadReviewFindings;
    window.updateEditingState = updateEditingState;
    window.loadCommonIssues = loadCommonIssues;
    window.openFinalEditor = openFinalEditor;
})(); // IIFE invocation - this actually executes the function














