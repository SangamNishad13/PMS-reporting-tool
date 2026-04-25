/**
 * uploads-manager.js
 */
(function () {

    // ── Scope toggle (Project / User) ────────────────────────────────────────
    var scopeType   = document.getElementById('uploadScopeType');
    var projectWrap = document.getElementById('uploadScopeProjectWrap');
    var userWrap    = document.getElementById('uploadScopeUserWrap');

    function syncScope() {
        if (!scopeType) return;
        if (scopeType.value === 'user') {
            projectWrap && projectWrap.classList.add('d-none');
            userWrap    && userWrap.classList.remove('d-none');
        } else {
            userWrap    && userWrap.classList.add('d-none');
            projectWrap && projectWrap.classList.remove('d-none');
        }
    }
    if (scopeType) {
        scopeType.addEventListener('change', syncScope);
        syncScope();
    }

    // ── Helper: show a Bootstrap confirmation modal ──────────────────────────
    function showConfirmModal(title, bodyHtml, onConfirm, confirmBtnClass, confirmBtnText) {
        confirmBtnClass = confirmBtnClass || 'btn-danger';
        confirmBtnText  = confirmBtnText  || 'Confirm';

        // Remove any existing instance
        var old = document.getElementById('umConfirmModal');
        if (old) old.remove();

        var modal = document.createElement('div');
        modal.id = 'umConfirmModal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.innerHTML = [
            '<div class="modal-dialog modal-dialog-centered">',
              '<div class="modal-content">',
                '<div class="modal-header">',
                  '<h5 class="modal-title">' + title + '</h5>',
                  '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>',
                '</div>',
                '<div class="modal-body">' + bodyHtml + '</div>',
                '<div class="modal-footer">',
                  '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>',
                  '<button type="button" class="btn ' + confirmBtnClass + '" id="umConfirmBtn">' + confirmBtnText + '</button>',
                '</div>',
              '</div>',
            '</div>'
        ].join('');

        document.body.appendChild(modal);

        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();

        document.getElementById('umConfirmBtn').addEventListener('click', function () {
            bsModal.hide();
            onConfirm();
        });

        modal.addEventListener('hidden.bs.modal', function () {
            modal.remove();
        });
    }

    // ── Cleanup form — preview before delete ─────────────────────────────────
    var cleanupForm = document.getElementById('cleanupForm');
    if (cleanupForm) {
        cleanupForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var scope     = document.getElementById('uploadScopeType').value;
            var projectId = (document.querySelector('[name="project_id"]') || {}).value || '';
            var userId    = (document.querySelector('[name="user_id"]') || {}).value || '';

            if (scope === 'project' && !projectId) {
                alert('Please select a project first.');
                return;
            }
            if (scope === 'user' && !userId) {
                alert('Please select a user first.');
                return;
            }

            // Fetch preview count via AJAX
            var params = new URLSearchParams({
                action:     'preview_cleanup',
                scope_type: scope,
                project_id: projectId,
                user_id:    userId
            });

            var btn = cleanupForm.querySelector('button[type="submit"]') ||
                      cleanupForm.querySelector('button');
            var origText = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking...'; }

            fetch(window.location.pathname + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (btn) { btn.disabled = false; btn.innerHTML = origText; }

                if (!data.success) {
                    alert(data.message || 'Could not fetch preview.');
                    return;
                }

                if (data.count === 0) {
                    showConfirmModal(
                        '<i class="fas fa-info-circle text-info me-2"></i>No Files Found',
                        '<p class="mb-0">No matching upload records found for <strong>' + escHtml(data.label) + '</strong>. Nothing will be deleted.</p>',
                        function () {},
                        'btn-secondary',
                        'OK'
                    );
                    return;
                }

                var bodyHtml = [
                    '<div class="alert alert-warning mb-3">',
                      '<i class="fas fa-exclamation-triangle me-2"></i>',
                      '<strong>This action is irreversible.</strong> Physical files will be permanently deleted from the server.',
                    '</div>',
                    '<table class="table table-sm mb-0">',
                      '<tr><th>Scope</th><td>' + escHtml(data.scope_type === 'project' ? 'Project' : 'User') + '</td></tr>',
                      '<tr><th>Target</th><td>' + escHtml(data.label) + '</td></tr>',
                      '<tr><th>Files to delete</th><td><span class="badge bg-danger fs-6">' + data.count + '</span></td></tr>',
                    '</table>'
                ].join('');

                showConfirmModal(
                    '<i class="fas fa-trash-alt text-danger me-2"></i>Confirm Bulk Delete',
                    bodyHtml,
                    function () { cleanupForm.submit(); },
                    'btn-danger',
                    'Yes, Delete ' + data.count + ' File' + (data.count !== 1 ? 's' : '')
                );
            })
            .catch(function () {
                if (btn) { btn.disabled = false; btn.innerHTML = origText; }
                alert('Failed to fetch preview. Please try again.');
            });
        });
    }

    // ── Individual file delete — confirmation with filename ──────────────────
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList.contains('um-delete-form')) return;
        e.preventDefault();

        var filename = form.getAttribute('data-filename') || 'this file';

        var bodyHtml = [
            '<div class="alert alert-warning mb-3">',
              '<i class="fas fa-exclamation-triangle me-2"></i>',
              '<strong>This action cannot be undone.</strong>',
            '</div>',
            '<p>Are you sure you want to permanently delete:</p>',
            '<p class="fw-semibold text-danger mb-0"><i class="fas fa-file me-1"></i>' + escHtml(filename) + '</p>'
        ].join('');

        showConfirmModal(
            '<i class="fas fa-trash-alt text-danger me-2"></i>Delete File',
            bodyHtml,
            function () { form.submit(); },
            'btn-danger',
            'Yes, Delete File'
        );
    });

    // ── HTML escape helper ───────────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})();
