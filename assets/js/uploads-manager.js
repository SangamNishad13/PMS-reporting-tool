/**
 * uploads-manager.js
 * Extracted from modules/admin/uploads_manager.php inline script
 */
(function () {
    var scopeType = document.getElementById('uploadScopeType');
    var projectWrap = document.getElementById('uploadScopeProjectWrap');
    var userWrap = document.getElementById('uploadScopeUserWrap');
    if (!scopeType || !projectWrap || !userWrap) return;

    function syncScope() {
        if (scopeType.value === 'user') {
            projectWrap.classList.add('d-none');
            userWrap.classList.remove('d-none');
        } else {
            userWrap.classList.add('d-none');
            projectWrap.classList.remove('d-none');
        }
    }
    scopeType.addEventListener('change', syncScope);
    syncScope();
})();

document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var msg = form.getAttribute('data-confirm') || 'Are you sure?';
        e.preventDefault();
        if (typeof window.confirmModal === 'function') {
            window.confirmModal(msg, function () { form.submit(); });
            return;
        }
        var confirmFn = (typeof window._origConfirm === 'function') ? window._origConfirm : window.confirm;
        if (confirmFn(msg)) form.submit();
    });
});
