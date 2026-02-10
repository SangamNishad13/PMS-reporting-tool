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
        });
    }

    // Initialize editor only when visible to avoid hidden-height issues
    setTimeout(function () {
        var pane = document.getElementById('feedback');
        if (pane && (pane.classList.contains('active') || pane.offsetParent !== null)) {
            refreshFeedbackEditor();
        }
    }, 200);

    // Initialize feedback editor when Feedback tab is shown
    (function () {
        const fbtn = document.getElementById('feedback-tab') || document.querySelector('button[data-bs-target="#feedback"]');
        if (!fbtn) return;
        fbtn.addEventListener('shown.bs.tab', function () {
            setTimeout(refreshFeedbackEditor, 50);
        });
        // fallback if bootstrap tab event doesn't fire
        fbtn.addEventListener('click', function () {
            setTimeout(refreshFeedbackEditor, 150);
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
