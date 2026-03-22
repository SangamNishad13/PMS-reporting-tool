/**
 * chat-widget.js
 * Floating project chat widget logic.
 * Requires window.ProjectConfig.baseDir and window.ProjectConfig.projectId
 * OR window._chatConfig.fullSrc for custom full-screen URL
 */
document.addEventListener('DOMContentLoaded', function () {
    var launcher      = document.getElementById('chatLauncher');
    var widget        = document.getElementById('projectChatWidget');
    var closeBtn      = document.getElementById('chatWidgetClose');
    var fullscreenBtn = document.getElementById('chatWidgetFullscreen');
    if (!launcher || !widget || !closeBtn || !fullscreenBtn) return;

    var baseDir   = window.ProjectConfig ? window.ProjectConfig.baseDir   : '';
    var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;
    var fullSrc   = (window._chatConfig && window._chatConfig.fullSrc)
        ? window._chatConfig.fullSrc
        : (baseDir + '/modules/chat/project_chat.php?project_id=' + projectId);

    launcher.addEventListener('click', function () {
        widget.classList.add('open');
        launcher.style.display = 'none';
        setTimeout(function () { try { closeBtn.focus(); } catch (e) {} }, 0);
    });
    closeBtn.addEventListener('click', function () {
        widget.classList.remove('open');
        launcher.style.display = 'inline-flex';
        setTimeout(function () { try { launcher.focus(); } catch (e) {} }, 0);
    });
    fullscreenBtn.addEventListener('click', function () {
        window.location.href = fullSrc;
    });
    window.addEventListener('message', function (event) {
        if (!event || !event.data || event.data.type !== 'pms-chat-close') return;
        widget.classList.remove('open');
        launcher.style.display = 'inline-flex';
        setTimeout(function () { try { launcher.focus(); } catch (e) {} }, 0);
    });

    function syncModalState() {
        // Only consider modals that are actually visible (display != none), not just those with .show class
        var hasOpenModal = document.querySelector('.modal.show[style*="display: block"], .modal.show[style*="display:block"]') !== null
            || document.querySelector('.modal.show:not([style*="display: none"]):not([style*="display:none"])') !== null;
        // Extra guard: if body has modal-open class, a modal is truly open
        hasOpenModal = hasOpenModal && document.body.classList.contains('modal-open');
        document.body.classList.toggle('chat-modal-open', hasOpenModal);
        if (hasOpenModal) {
            widget.classList.remove('open');
            launcher.style.display = 'none';
        } else {
            launcher.style.display = 'inline-flex';
        }
    }

    document.addEventListener('show.bs.modal',   syncModalState, true);
    document.addEventListener('shown.bs.modal',  syncModalState, true);
    document.addEventListener('hidden.bs.modal', syncModalState, true);
    syncModalState();
});
