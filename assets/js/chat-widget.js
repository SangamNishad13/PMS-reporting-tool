/**
 * chat-widget.js
 * Floating project chat widget logic.
 * Requires window.ProjectConfig.baseDir and window.ProjectConfig.projectId
 */
document.addEventListener('DOMContentLoaded', function () {
    var launcher      = document.getElementById('chatLauncher');
    var widget        = document.getElementById('projectChatWidget');
    var closeBtn      = document.getElementById('chatWidgetClose');
    var fullscreenBtn = document.getElementById('chatWidgetFullscreen');
    if (!launcher || !widget || !closeBtn || !fullscreenBtn) return;

    var baseDir   = window.ProjectConfig ? window.ProjectConfig.baseDir   : '';
    var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;

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
        window.location.href = baseDir + '/modules/chat/project_chat.php?project_id=' + projectId;
    });
    window.addEventListener('message', function (event) {
        if (!event || !event.data || event.data.type !== 'pms-chat-close') return;
        widget.classList.remove('open');
        launcher.style.display = 'inline-flex';
        setTimeout(function () { try { launcher.focus(); } catch (e) {} }, 0);
    });

    function syncModalState() {
        var hasOpenModal = document.querySelector('.modal.show') !== null;
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
