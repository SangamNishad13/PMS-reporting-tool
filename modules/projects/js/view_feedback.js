/**
 * view_feedback.js
 * Handles project feedback form and submission.
 */

$(document).ready(function () {
    var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;
    var baseDir = window.ProjectConfig ? window.ProjectConfig.baseDir : '';

    // Initialize Summernote for feedback editor with lazy init + fallback
    window.initFeedbackEditor = function () {
        try {
            if (window.jQuery && typeof jQuery.fn.summernote === 'function') {
                var $el = $('#pf_editor');
                if (!$el.length) return false;
                if ($el.data('summernote')) return true;
                $el.summernote({
                    height: 180,
                    toolbar: [
                        ['style', ['bold', 'italic', 'underline', 'clear']],
                        ['para', ['ul', 'ol', 'paragraph']],
                        ['insert', ['link', 'picture']],
                        ['view', ['fullscreen']]
                    ]
                });
                return true;
            }
        } catch (e) { }
        return false;
    };

    function ensureSummernoteLoaded(cb) {
        if (window.jQuery && typeof jQuery.fn.summernote === 'function') {
            cb();
            return;
        }
        $.getScript('https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js')
            .done(function () { cb(); })
            .fail(function () { });
    }

    function refreshFeedbackEditor() {
        if (!window.jQuery || !$('#pf_editor').length) return;
        ensureSummernoteLoaded(function () {
            if (!initFeedbackEditor()) return;
            try {
                var $el = $('#pf_editor');
                var $editor = $el.next('.note-editor');
                if (!$editor.length || $editor.height() === 0) {
                    try { $el.summernote('destroy'); } catch (e) { }
                    initFeedbackEditor();
                }
            } catch (e) { }
            // Always (re)bind mention handlers after editor init/re-init.
            setTimeout(bindFeedbackMentionEvents, 0);
        });
    }

    // @mention support for feedback editor
    var feedbackMentionDropdown = null;
    var feedbackMentionList = [];
    var feedbackMentionIndex = -1;

    function ensureFeedbackMentionDropdown() {
        if (feedbackMentionDropdown && document.body.contains(feedbackMentionDropdown)) return feedbackMentionDropdown;
        feedbackMentionDropdown = document.getElementById('feedbackMentionDropdown');
        if (!feedbackMentionDropdown) {
            feedbackMentionDropdown = document.createElement('div');
            feedbackMentionDropdown.id = 'feedbackMentionDropdown';
            feedbackMentionDropdown.className = 'dropdown-menu';
            feedbackMentionDropdown.style.position = 'fixed';
            feedbackMentionDropdown.style.zIndex = '2000';
            feedbackMentionDropdown.style.maxHeight = '220px';
            feedbackMentionDropdown.style.overflowY = 'auto';
            feedbackMentionDropdown.style.display = 'none';
            document.body.appendChild(feedbackMentionDropdown);
        }
        return feedbackMentionDropdown;
    }

    function hideFeedbackMentionDropdown() {
        var dd = ensureFeedbackMentionDropdown();
        dd.style.display = 'none';
        dd.innerHTML = '';
        feedbackMentionList = [];
        feedbackMentionIndex = -1;
    }

    function moveFeedbackMentionSelection(direction) {
        var dd = ensureFeedbackMentionDropdown();
        var items = dd.querySelectorAll('.feedback-mention-item');
        if (!items.length) return;
        if (feedbackMentionIndex >= 0 && items[feedbackMentionIndex]) items[feedbackMentionIndex].classList.remove('active');
        feedbackMentionIndex += direction;
        if (feedbackMentionIndex < 0) feedbackMentionIndex = items.length - 1;
        if (feedbackMentionIndex >= items.length) feedbackMentionIndex = 0;
        items[feedbackMentionIndex].classList.add('active');
        items[feedbackMentionIndex].scrollIntoView({ block: 'nearest' });
    }

    function insertFeedbackMention(username) {
        var $editor = $('#pf_editor');
        if (!$editor.length || !$editor.data('summernote')) return;
        var $editable = $editor.next('.note-editor').find('.note-editable');
        if (!$editable.length) return;
        var text = $editable.text();
        var atPos = text.lastIndexOf('@');
        if (atPos < 0) {
            hideFeedbackMentionDropdown();
            return;
        }
        var editorHtml = $editor.summernote('code');
        var lastAtHtmlPos = editorHtml.lastIndexOf('@');
        if (lastAtHtmlPos < 0) {
            hideFeedbackMentionDropdown();
            return;
        }
        var beforeAtHtml = editorHtml.substring(0, lastAtHtmlPos);
        var afterAtHtml = editorHtml.substring(lastAtHtmlPos + 1);
        var endMatch = afterAtHtml.match(/^[\w]*/);
        var queryLength = endMatch ? endMatch[0].length : 0;
        afterAtHtml = afterAtHtml.substring(queryLength);
        var newHtml = beforeAtHtml + '@' + username + ' ' + afterAtHtml;
        $editor.summernote('code', newHtml);
        try {
            var range = document.createRange();
            var sel = window.getSelection();
            var editableEl = $editable[0];
            range.selectNodeContents(editableEl);
            range.collapse(false);
            sel.removeAllRanges();
            sel.addRange(range);
            $editor.summernote('editor.focus');
        } catch (e) {
            try {
                $editor.summernote('editor.focus');
            } catch (ex) {}
        }
        hideFeedbackMentionDropdown();
    }

    function sanitizeHtmlText(s) {
        return String(s || '').replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[m]);
        });
    }

    function showFeedbackMentionDropdown(query) {
        var users = (window.ProjectConfig && window.ProjectConfig.projectUsers) ? window.ProjectConfig.projectUsers.slice() : [];
        if (!users.length) {
            $('#pf_recipients option').each(function () {
                var username = String($(this).data('username') || '').trim();
                var fullName = String($(this).text() || '').trim();
                users.push({ username: username, full_name: fullName });
            });
        }
        if (!users.length) {
            hideFeedbackMentionDropdown();
            return;
        }
        var q = String(query || '').toLowerCase().trim();
        feedbackMentionList = users.filter(function (u) {
            if (!u) return false;
            var un = String(u.username || '').toLowerCase();
            var fn = String(u.full_name || '').toLowerCase();
            return !q || un.indexOf(q) !== -1 || fn.indexOf(q) !== -1;
        }).map(function (u) {
            if (!u.username) {
                // fallback so suggestion still works when username missing
                u.username = String(u.full_name || '').replace(/\s+/g, '');
            }
            return u;
        }).slice(0, 8);

        var dd = ensureFeedbackMentionDropdown();
        if (!feedbackMentionList.length) {
            hideFeedbackMentionDropdown();
            return;
        }
        dd.innerHTML = feedbackMentionList.map(function (u, i) {
            var name = String(u.full_name || 'User');
            var uname = String(u.username || '');
            return '<a href="#" class="dropdown-item feedback-mention-item' + (i === 0 ? ' active' : '') + '" data-username="' + uname + '">' +
                sanitizeHtmlText(name) +
                (uname ? ' <small class="text-muted">@' + sanitizeHtmlText(uname) + '</small>' : '') +
                '</a>';
        }).join('');
        feedbackMentionIndex = 0;
        dd.style.display = 'block';

        var $editable = $editorEditable();
        if ($editable && $editable.length) {
            try {
                var range = $('#pf_editor').summernote('createRange');
                if (range && range.getClientRects && range.getClientRects().length > 0) {
                    var r = range.getClientRects()[0];
                    dd.style.left = r.left + 'px';
                    dd.style.top = (r.bottom + 5) + 'px';
                } else {
                    var rect = $editable[0].getBoundingClientRect();
                    dd.style.left = (rect.left + 12) + 'px';
                    dd.style.top = (rect.top + 42) + 'px';
                }
            } catch (e) {
                var rect2 = $editable[0].getBoundingClientRect();
                dd.style.left = (rect2.left + 12) + 'px';
                dd.style.top = (rect2.top + 42) + 'px';
            }
        }

        $(dd).find('.feedback-mention-item').off('click').on('click', function (e) {
            e.preventDefault();
            insertFeedbackMention($(this).data('username'));
        });
    }

    function $editorEditable() {
        var $el = $('#pf_editor');
        if (!$el.length) return null;
        return $el.next('.note-editor').find('.note-editable');
    }

    function bindFeedbackMentionEvents() {
        var $el = $('#pf_editor');
        if (!$el.length) return;
        if (!$el.data('summernote') && !$el.next('.note-editor').length) return;
        $el.off('.feedbackMention');
        var $editable = $editorEditable();
        if ($editable && $editable.length) {
            $editable.off('.feedbackMention');
        }

        function handleMentionKeydown(e) {
            var dd = ensureFeedbackMentionDropdown();
            var visible = dd.style.display === 'block';
            if (!visible) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); moveFeedbackMentionSelection(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); moveFeedbackMentionSelection(-1); }
            else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                var active = dd.querySelector('.feedback-mention-item.active');
                if (active) insertFeedbackMention(active.getAttribute('data-username'));
            } else if (e.key === 'Escape') {
                e.preventDefault();
                hideFeedbackMentionDropdown();
            }
        }

        function handleMentionKeyup(e) {
            if (['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].indexOf(e.key) !== -1) return;
            var $editableNow = $el.next('.note-editor').find('.note-editable');
            if (!$editableNow.length) return;
            var text = $editableNow.text();
            var atPos = text.lastIndexOf('@');
            if (atPos < 0) { hideFeedbackMentionDropdown(); return; }
            var query = text.substring(atPos + 1);
            if (/\s/.test(query) || query.length > 50 || !/^[\w]*$/.test(query)) { hideFeedbackMentionDropdown(); return; }
            showFeedbackMentionDropdown(query);
        }

        // Bind Summernote event hooks.
        $el.on('summernote.keydown.feedbackMention', function (we, e) { handleMentionKeydown(e); });
        $el.on('summernote.keyup.feedbackMention', function (we, e) { handleMentionKeyup(e); });

        // Bind directly on editable area as fallback (more reliable across Summernote builds).
        $editable = $editorEditable();
        if ($editable && $editable.length) {
            $editable.on('keydown.feedbackMention', handleMentionKeydown);
            $editable.on('keyup.feedbackMention input.feedbackMention', function (e) { handleMentionKeyup(e); });
        }

        $(document).off('click.feedbackMention').on('click.feedbackMention', function (e) {
            var dd = ensureFeedbackMentionDropdown();
            if (!dd.contains(e.target)) hideFeedbackMentionDropdown();
        });
    }

    // Initialize editor only when visible to avoid hidden-height issues
    setTimeout(function () {
        var pane = document.getElementById('feedback');
        if (pane && (pane.classList.contains('active') || pane.offsetParent !== null)) {
            refreshFeedbackEditor();
            setTimeout(bindFeedbackMentionEvents, 120);
        }
    }, 200);

    // Initialize feedback editor when Feedback tab is shown
    (function () {
        const fbtn = document.getElementById('feedback-tab') || document.querySelector('button[data-bs-target="#feedback"]');
        if (!fbtn) return;
        fbtn.addEventListener('shown.bs.tab', function () {
            setTimeout(refreshFeedbackEditor, 50);
            setTimeout(bindFeedbackMentionEvents, 150);
        });
        // fallback if bootstrap tab event doesn't fire
        fbtn.addEventListener('click', function () {
            setTimeout(refreshFeedbackEditor, 150);
            setTimeout(bindFeedbackMentionEvents, 200);
        });
    })();

    // Bind feedback form submit
    $('#projectFeedbackForm').off('submit').on('submit', function (e) {
        e.preventDefault();
        var recipients = $('#pf_recipients').val() || [];
        var adminResource = $('#pf_admin_resource').length ? $('#pf_admin_resource').val() : '';
        if (adminResource) {
            if (!Array.isArray(recipients)) recipients = [recipients];
            if (!recipients.includes(adminResource)) recipients.push(adminResource);
        }
        var isGeneric = $('#pf_is_generic').is(':checked') ? 1 : 0;
        var sendToAdmin = $('#pf_send_to_admin').is(':checked') ? 1 : 0;
        var sendToLead = $('#pf_send_to_lead').is(':checked') ? 1 : 0;

        // collect editor content safely
        var content = '';
        try {
            if (window.jQuery && typeof jQuery.fn.summernote === 'function' && $('#pf_editor').length) {
                content = $('#pf_editor').summernote('code') || '';
            } else if (document.getElementById('pf_editor')) {
                var el = document.getElementById('pf_editor');
                content = el.value || el.innerHTML || '';
            }
        } catch (e) { content = ''; }

        var data = {
            action: 'submit_feedback',
            project_id: projectId,
            content: content,
            recipient_ids: recipients,
            is_generic: isGeneric,
            send_to_admin: sendToAdmin,
            send_to_lead: sendToLead
        };

        $.ajax({
            url: baseDir + '/api/feedback.php',
            type: 'POST',
            data: data,
            success: function (response) {
                if (response.success) {
                    if (typeof showToast === 'function') showToast('Feedback submitted successfully', 'success');
                    try { $('#pf_editor').summernote('code', ''); } catch (e) { }
                    $('#pf_recipients').val([]);
                    if ($('#pf_admin_resource').length) $('#pf_admin_resource').val('');
                    $('#pf_is_generic').prop('checked', false);
                    $('#pf_send_to_admin').prop('checked', false);
                    $('#pf_send_to_lead').prop('checked', false);
                    setTimeout(function () { location.reload(); }, 1000);
                } else {
                    if (typeof showToast === 'function') showToast('Failed to submit feedback: ' + (response.message || 'Unknown error'), 'danger');
                }
            },
            error: function (xhr, status, error) {
                if (typeof showToast === 'function') showToast('Error submitting feedback: ' + error, 'danger');
            }
        });
    });
});
