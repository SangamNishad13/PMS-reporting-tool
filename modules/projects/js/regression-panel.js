/**
 * regression-panel.js
 * Client-side logic for the Regression Testing Panel partial.
 * Loaded on issues_pages, issues_page_detail, issues_common, issues_all.
 */
(function () {
    'use strict';

    var cfg = window.ProjectConfig || {};
    var projectId = cfg.projectId || 0;
    var baseDir = cfg.baseDir || '';
    var userRole = cfg.userRole || 'client';
    var regressionApi = baseDir + '/api/regression_actions.php';

    var canManageRounds = (userRole === 'admin' || userRole === 'project_lead' || userRole === 'qa');
    var csrfToken = resolveCsrfToken();
    var newRoundDefaultHtml = '<i class="fas fa-plus me-1"></i>New Round';
    var pendingConfirmAction = null;

    // -------------------------------------------------------
    // Stats
    // -------------------------------------------------------
    function loadRegressionStats() {
        var container = document.getElementById('regressionStatsContainer');
        if (!container || !projectId) return;

        fetch(regressionApi + '?action=get_stats&project_id=' + encodeURIComponent(projectId), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    container.innerHTML = '<span class="text-muted small">Could not load regression stats.</span>';
                    return;
                }
                setNewRoundButtonState(data.active_round || null);
                var total = parseInt(data.issues_total || 0, 10);
                var attempted = parseInt(data.regression_issues_total || data.attempted_issues_total || 0, 10);
                var newInRound = parseInt(data.new_issues_in_round_total || 0, 10);
                var pct = total > 0 ? Math.round((attempted / total) * 100) : 0;
                var statusCounts = data.attempted_status_counts || {};

                var statusKeys = Object.keys(statusCounts);
                var statusHtml = '';
                if (statusKeys.length) {
                    statusHtml = statusKeys.map(function (s) {
                        return '<span class="badge bg-secondary me-1 mb-1">' +
                            escHtml(s) + ': ' + parseInt(statusCounts[s] || 0, 10) +
                            '</span>';
                    }).join('');
                } else {
                    statusHtml = '<span class="text-muted small">No regression activity yet.</span>';
                }

                var progressBarColor = pct >= 100 ? 'bg-success' :
                                       pct >= 60  ? 'bg-info'    :
                                       pct >= 30  ? 'bg-warning'  : 'bg-danger';

                container.innerHTML =
                    '<div class="row g-3 align-items-center">' +
                    '<div class="col-6 col-md-2">' +
                    '<div class="text-muted small">Total Issues</div>' +
                    '<div class="fw-semibold fs-5">' + total + '</div>' +
                    '</div>' +
                    '<div class="col-6 col-md-2">' +
                    '<div class="text-muted small">Regression Issues</div>' +
                    '<div class="fw-semibold fs-5 text-success">' + attempted + '</div>' +
                    (newInRound > 0 ? '<div class="small text-muted">New in round: ' + newInRound + '</div>' : '') +
                    '</div>' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="text-muted small mb-1">Coverage</div>' +
                    '<div class="d-flex align-items-center gap-2">' +
                    '<span class="fw-semibold">' + pct + '%</span>' +
                    '<div class="progress flex-grow-1" style="height:8px;" role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100">' +
                    '<div class="progress-bar ' + progressBarColor + '" style="width:' + pct + '%"></div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-12 col-md-5">' +
                    '<div class="text-muted small mb-1">Status Breakdown</div>' +
                    '<div>' + statusHtml + '</div>' +
                    '</div>' +
                    '</div>';
            })
            .catch(function () {
                var c = document.getElementById('regressionStatsContainer');
                if (c) c.innerHTML = '<span class="text-muted small">Could not load regression stats.</span>';
            });
    }

    // -------------------------------------------------------
    // Rounds
    // -------------------------------------------------------
    function loadRegressionRounds() {
        var container = document.getElementById('regressionRoundsList');
        if (!container || !projectId) return;

        container.innerHTML = '<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Loading…</div>';

        fetch(regressionApi + '?action=list_rounds&project_id=' + encodeURIComponent(projectId), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var badgeEl = document.getElementById('regressionActiveRoundBadge');

                if (!data || !data.success || !data.rounds || !data.rounds.length) {
                    container.innerHTML = '<div class="text-muted small">No regression rounds created yet.</div>';
                    if (badgeEl) badgeEl.innerHTML = '';
                    return;
                }

                // Active round badge
                var activeRound = data.rounds.find(function (r) {
                    return r.status === 'in_progress' && r.is_active == 1;
                });
                setNewRoundButtonState(activeRound || null);
                if (badgeEl) {
                    badgeEl.innerHTML = activeRound
                        ? '<span class="badge bg-warning text-dark"><i class="fas fa-circle-notch fa-spin me-1"></i>Round ' + escHtml(String(activeRound.round_number)) + ' in progress</span>'
                        : '<span class="badge bg-secondary"><i class="fas fa-check me-1"></i>No active round</span>';
                }

                var html = '<div class="table-responsive">' +
                    '<table class="table table-sm table-hover mb-0 align-middle">' +
                    '<thead class="table-light">' +
                    '<tr>' +
                    '<th>Round</th>' +
                    '<th>Started By</th>' +
                    '<th>Start Date</th>' +
                    '<th>End Date</th>' +
                    '<th>Status</th>' +
                    (canManageRounds ? '<th></th>' : '') +
                    '</tr>' +
                    '</thead>' +
                    '<tbody>';

                data.rounds.forEach(function (r) {
                    var statusBadge = r.status === 'completed'
                        ? '<span class="badge bg-success">Completed</span>'
                        : '<span class="badge bg-warning text-dark"><i class="fas fa-circle-notch fa-spin me-1"></i>In Progress</span>';

                    var actionCell = '';
                    if (canManageRounds && r.status === 'in_progress') {
                        actionCell = '<button type="button" class="btn btn-xs btn-outline-danger py-0 regression-complete-round" data-round-id="' +
                            escAttr(String(r.id)) + '" data-round-number="' + escAttr(String(r.round_number)) + '">Complete</button>';
                    }

                    html += '<tr>' +
                        '<td><span class="badge bg-primary">Round ' + escHtml(String(r.round_number)) + '</span></td>' +
                        '<td class="small">' + escHtml(r.started_by_name || '—') + '</td>' +
                        '<td class="small">' + escHtml(r.start_date || '—') + '</td>' +
                        '<td class="small">' + escHtml(r.end_date || '—') + '</td>' +
                        '<td>' + statusBadge + '</td>' +
                        (canManageRounds ? '<td>' + actionCell + '</td>' : '') +
                        '</tr>';
                });

                html += '</tbody></table></div>';
                container.innerHTML = html;

                container.querySelectorAll('.regression-complete-round').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var roundId     = this.getAttribute('data-round-id');
                        var roundNumber = this.getAttribute('data-round-number');
                        openConfirmModal({
                            title: 'Complete Regression Round',
                            body: 'Mark RR ' + roundNumber + ' as completed? This cannot be undone.',
                            confirmLabel: 'Complete',
                            confirmClass: 'btn-danger',
                            onConfirm: function () {
                                completeRound(roundId);
                            }
                        });
                    });
                });
            })
            .catch(function () {
                var c = document.getElementById('regressionRoundsList');
                if (c) c.innerHTML = '<div class="text-muted small">Could not load rounds.</div>';
            });
    }

    function createNewRound() {
        if (!projectId) return;
        var btn = document.getElementById('btnNewRegressionRound');
        if (btn) { btn.disabled = true; }
        var createdSuccessfully = false;

        var fd = new FormData();
        fd.append('action', 'create_round');
        fd.append('project_id', String(projectId));
        if (csrfToken) {
            fd.append('csrf_token', csrfToken);
        }

        fetch(regressionApi, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
        })
            .then(readJsonResponse)
            .then(function (data) {
                if (data && data.success) {
                    createdSuccessfully = true;
                    loadRegressionStats();
                    loadRegressionRounds();
                    if (typeof window.showToast === 'function') {
                        showToast('Regression Round ' + data.round_number + ' created', 'success');
                    }
                    // Auto-open rounds collapse
                    var collapseEl = document.getElementById('regressionRoundsCollapse');
                    if (collapseEl && window.bootstrap) {
                        var bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseEl);
                        bsCollapse.show();
                    }
                } else {
                    var msg = (data && data.error) ? data.error : 'Failed to create round';
                    if (typeof window.showToast === 'function') showToast(msg, 'danger');
                    else alert(msg);
                }
            })
            .catch(function (err) {
                var message = (err && err.message) ? err.message : 'Request failed';
                if (typeof window.showToast === 'function') showToast(message, 'danger');
                else alert(message);
            })
            .finally(function () {
                if (btn && !createdSuccessfully) {
                    btn.disabled = false;
                }
                loadRegressionStats();
            });
    }

    function completeRound(roundId) {
        var fd = new FormData();
        fd.append('action', 'complete_round');
        fd.append('project_id', String(projectId));
        fd.append('round_id', String(roundId));
        if (csrfToken) {
            fd.append('csrf_token', csrfToken);
        }

        fetch(regressionApi, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
        })
            .then(readJsonResponse)
            .then(function (data) {
                if (data && data.success) {
                    loadRegressionStats();
                    loadRegressionRounds();
                    if (typeof window.showToast === 'function') showToast('Round completed', 'success');
                } else {
                    var msg = (data && data.error) ? data.error : 'Failed to complete round';
                    if (typeof window.showToast === 'function') showToast(msg, 'danger');
                    else alert(msg);
                }
            })
            .catch(function (err) {
                var message = (err && err.message) ? err.message : 'Request failed';
                if (typeof window.showToast === 'function') showToast(message, 'danger');
                else alert(message);
            });
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------
    function escHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function escAttr(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function setNewRoundButtonState(activeRound) {
        var btn = document.getElementById('btnNewRegressionRound');
        if (!btn) return;

        var roundNumber = activeRound ? parseInt(activeRound.round_number || 0, 10) : 0;
        if (roundNumber > 0) {
            btn.disabled = true;
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-warning');
            btn.innerHTML = '<i class="fas fa-circle-notch me-1"></i>RR ' + roundNumber + ' in progress';
            btn.title = 'Complete active round to create a new round';
            return;
        }

        btn.disabled = false;
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-primary');
        btn.innerHTML = newRoundDefaultHtml;
        btn.removeAttribute('title');
    }

    function resolveCsrfToken() {
        if (window._csrfToken) {
            return String(window._csrfToken);
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return String(meta.getAttribute('content'));
        }
        return '';
    }

    function readJsonResponse(response) {
        return response.text().then(function (text) {
            var data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                throw new Error('Invalid JSON response');
            }

            if (!response.ok && data && data.error) {
                throw new Error(String(data.error));
            }
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return data;
        });
    }

    // -------------------------------------------------------
    // Init
    // -------------------------------------------------------
    function init() {
        loadRegressionStats();

        wireConfirmModal();

        var newRoundBtn = document.getElementById('btnNewRegressionRound');
        if (newRoundBtn) {
            newRoundBtn.addEventListener('click', function () {
                openConfirmModal({
                    title: 'Start New Regression Round',
                    body: 'Start a new regression round for this project?',
                    confirmLabel: 'Start Round',
                    confirmClass: 'btn-primary',
                    onConfirm: function () {
                        createNewRound();
                    }
                });
            });
        }

        // Load rounds when the collapse panel is first opened
        var roundsCollapse = document.getElementById('regressionRoundsCollapse');
        if (roundsCollapse) {
            roundsCollapse.addEventListener('show.bs.collapse', function onFirstShow() {
                roundsCollapse.removeEventListener('show.bs.collapse', onFirstShow);
                loadRegressionRounds();
                // Re-attach for subsequent opens so it refreshes
                roundsCollapse.addEventListener('show.bs.collapse', loadRegressionRounds);
            });
        }
    }

    function wireConfirmModal() {
        var confirmBtn = document.getElementById('regressionConfirmModalYes');
        if (!confirmBtn) return;

        confirmBtn.addEventListener('click', function () {
            if (typeof pendingConfirmAction === 'function') {
                var action = pendingConfirmAction;
                pendingConfirmAction = null;
                action();
            }

            var modalEl = document.getElementById('regressionConfirmModal');
            if (modalEl && window.bootstrap) {
                var modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) modalInstance.hide();
            }
        });
    }

    function openConfirmModal(options) {
        if (!options || typeof options.onConfirm !== 'function') {
            return;
        }

        if (!window.bootstrap) {
            if (confirm(options.body || 'Are you sure?')) {
                options.onConfirm();
            }
            return;
        }

        var modalEl = document.getElementById('regressionConfirmModal');
        var titleEl = document.getElementById('regressionConfirmModalLabel');
        var bodyEl = document.getElementById('regressionConfirmModalBody');
        var confirmBtn = document.getElementById('regressionConfirmModalYes');

        if (!modalEl || !titleEl || !bodyEl || !confirmBtn) {
            if (confirm(options.body || 'Are you sure?')) {
                options.onConfirm();
            }
            return;
        }

        titleEl.textContent = options.title || 'Confirm Action';
        bodyEl.textContent = options.body || 'Are you sure?';
        confirmBtn.textContent = options.confirmLabel || 'Confirm';
        confirmBtn.className = 'btn ' + (options.confirmClass || 'btn-primary');

        pendingConfirmAction = options.onConfirm;

        var modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        modalInstance.show();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
