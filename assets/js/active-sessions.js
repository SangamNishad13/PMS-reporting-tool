/**
 * active-sessions.js
 * Extracted from modules/admin/active_sessions.php inline script
 * Requires window._activeSessionsConfig.baseDir
 */
(function () {
    var baseDir = (window._activeSessionsConfig && window._activeSessionsConfig.baseDir) ? window._activeSessionsConfig.baseDir : '';
    var csrfToken = (window._activeSessionsConfig && window._activeSessionsConfig.csrfToken) ? window._activeSessionsConfig.csrfToken : (window._csrfToken || '');

    function updateSessionRowState(row, data) {
        if (!row) {
            return;
        }

        var logoutAtCell = row.querySelector('.session-logout-at');
        var logoutTypeCell = row.querySelector('.session-logout-type');
        var statusCell = row.querySelector('.session-status');
        var actionCell = row.querySelector('.session-action');

        if (logoutAtCell) {
            logoutAtCell.textContent = (data && data.logout_at) ? data.logout_at : 'Just now';
        }
        if (logoutTypeCell) {
            logoutTypeCell.textContent = (data && data.logout_type) ? data.logout_type : 'forced_by_admin';
        }
        if (statusCell) {
            statusCell.innerHTML = '<span class="badge bg-secondary">Logged Out</span>';
        }
        if (actionCell) {
            actionCell.innerHTML = '<span class="text-muted">-</span>';
        }

        row.classList.remove('table-warning');
        row.classList.add('table-light');
    }

    document.querySelectorAll('.force-logout').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var self = this;
            var sid = self.dataset.session;
            confirmModal('Force logout session ' + sid + ' ?', function () {
                self.disabled = true;
                fetch(baseDir + '/api/force_logout_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ session_id: sid, csrf_token: csrfToken })
                }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j && j.success) {
                        var row = document.getElementById('sess-' + sid);
                        updateSessionRowState(row, j);
                        showToast('Session terminated successfully.', 'success');
                    } else {
                        self.disabled = false;
                        showToast('Failed: ' + (j && j.error ? j.error : 'unknown'), 'danger');
                    }
                }).catch(function (e) {
                    self.disabled = false;
                    console.error('Force logout error:', e);
                    showToast('Request failed', 'danger');
                });
            });
        });
    });

    // Read more toggle for long user-agent strings
    function isOverflowing(el) { return el && el.scrollWidth > el.clientWidth; }

    function ensureUaButtons() {
        document.querySelectorAll('.ua-snippet').forEach(function (snippet) {
            var cell = snippet.closest('td');
            if (!cell) return;
            var btnToggle = cell.querySelector('.ua-toggle');
            var btnCopy = cell.querySelector('.ua-copy');
            var overflowing = isOverflowing(snippet);
            if (overflowing) {
                if (!btnToggle) {
                    btnToggle = document.createElement('button');
                    btnToggle.type = 'button';
                    btnToggle.className = 'btn btn-link btn-sm ua-toggle';
                    btnToggle.textContent = 'Read more';
                }
                if (!btnCopy) {
                    btnCopy = document.createElement('button');
                    btnCopy.type = 'button';
                    btnCopy.className = 'btn btn-outline-secondary btn-sm ua-copy ms-2';
                    btnCopy.title = 'Copy user-agent';
                    btnCopy.textContent = 'Copy';
                }
                var container = cell.querySelector('.ua-actions');
                if (!container) {
                    container = document.createElement('div');
                    container.className = 'mt-1 ua-actions';
                    snippet.after(container);
                }
                if (!container.contains(btnToggle)) container.appendChild(btnToggle);
                if (!container.contains(btnCopy)) container.appendChild(btnCopy);
                btnToggle.classList.remove('d-none');
                btnCopy.classList.remove('d-none');
            } else {
                if (btnToggle) btnToggle.classList.add('d-none');
                if (btnCopy) btnCopy.classList.add('d-none');
            }
        });
    }

    document.addEventListener('click', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('ua-toggle')) {
            var btn = e.target;
            var cell = btn.closest('td');
            if (!cell) return;
            var full = cell.querySelector('.ua-full');
            var snippet = cell.querySelector('.ua-snippet');
            if (full) {
                if (full.classList.contains('d-none')) {
                    full.classList.remove('d-none');
                    if (snippet) snippet.classList.add('d-none');
                    btn.textContent = 'Read less';
                } else {
                    full.classList.add('d-none');
                    if (snippet) snippet.classList.remove('d-none');
                    btn.textContent = 'Read more';
                }
            } else if (snippet) {
                var fullText = snippet.getAttribute('title') || snippet.textContent;
                if (btn.dataset.expanded === '1') {
                    snippet.textContent = fullText.substring(0, 120) + (fullText.length > 120 ? '...' : '');
                    btn.textContent = 'Read more';
                    btn.dataset.expanded = '0';
                } else {
                    snippet.textContent = fullText;
                    btn.textContent = 'Read less';
                    btn.dataset.expanded = '1';
                }
            }
        }
    });

    // Copy UA to clipboard
    document.addEventListener('click', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('ua-copy')) {
            var btn = e.target;
            var cell = btn.closest('td');
            if (!cell) return;
            var full = cell.querySelector('.ua-full');
            var text = full
                ? full.textContent.trim()
                : (cell.querySelector('.ua-snippet')
                    ? (cell.querySelector('.ua-snippet').getAttribute('title') || cell.querySelector('.ua-snippet').textContent)
                    : '');
            if (!text) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    var old = btn.innerHTML;
                    btn.innerHTML = 'Copied';
                    setTimeout(function () { btn.innerHTML = old; }, 1500);
                }).catch(function () { showToast('Copy failed', 'danger'); });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand('copy');
                    btn.innerHTML = 'Copied';
                    setTimeout(function () { btn.innerHTML = 'Copy'; }, 1500);
                } catch (err) { showToast('Copy failed', 'danger'); }
                document.body.removeChild(ta);
            }
        }
    });

    window.addEventListener('load', ensureUaButtons);
    window.addEventListener('resize', function () { setTimeout(ensureUaButtons, 150); });

    var selectAllSessions = document.getElementById('selectAllSessions');
    if (selectAllSessions) {
        selectAllSessions.addEventListener('change', function () {
            var checked = !!this.checked;
            document.querySelectorAll('input[name="session_ids[]"]').forEach(function (cb) { cb.checked = checked; });
        });
    }

    (function () {
        var scopeType = document.getElementById('sessionScopeType');
        var userWrap = document.getElementById('sessionUserTargetWrap');
        var projectWrap = document.getElementById('sessionProjectTargetWrap');
        var userSelect = document.getElementById('sessionUserTarget');
        var projectSelect = document.getElementById('sessionProjectTarget');
        if (!scopeType || !userWrap || !projectWrap || !userSelect || !projectSelect) return;

        function syncScope() {
            if (scopeType.value === 'project') {
                userWrap.classList.add('d-none');
                projectWrap.classList.remove('d-none');
                userSelect.name = '';
                projectSelect.name = 'target_id';
            } else {
                projectWrap.classList.add('d-none');
                userWrap.classList.remove('d-none');
                projectSelect.name = '';
                userSelect.name = 'target_id';
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
})();
