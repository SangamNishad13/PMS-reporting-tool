
// Regression tab JS
document.addEventListener('DOMContentLoaded', function () {
    const projectId = <? php echo (int)$projectId; ?>;

    // Toast helper
    function showToast(message, variant = 'info', ttl = 4000) {
        try {
            const containerId = 'pmsToastContainer';
            let container = document.getElementById(containerId);
            if (!container) {
                container = document.createElement('div');
                container.id = containerId;
                container.className = 'position-fixed bottom-0 end-0 p-3';
                container.style.zIndex = 10800;
                document.body.appendChild(container);
            }
            const toastId = 'toast_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
            const bg = variant === 'success' ? 'bg-success text-white' : (variant === 'danger' ? 'bg-danger text-white' : (variant === 'warning' ? 'bg-warning text-dark' : 'bg-secondary text-white'));
            const toastEl = document.createElement('div');
            toastEl.id = toastId;
            toastEl.className = 'toast align-items-center ' + bg + ' border-0 show';
            toastEl.role = 'alert';
            toastEl.ariaLive = 'assertive';
            toastEl.ariaAtomic = 'true';
            toastEl.style.minWidth = '220px';
            toastEl.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">${escapeHtml(message)}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" aria-label="Close"></button>
                </div>`;
            container.appendChild(toastEl);
            const closeBtn = toastEl.querySelector('.btn-close');
            closeBtn.addEventListener('click', () => { toastEl.remove(); });
            setTimeout(() => { toastEl.remove(); }, ttl);
        } catch (e) {
            // fallback to original alert (if preserved) or console
            try { window._origAlert ? window._origAlert(message) : null; } catch (_) { /* nothing */ }
        }
    }

    // simple HTML escaper for toast body
    function escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return String(unsafe)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    async function fetchRegression() {
        const res = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php?action=get_stats&project_id=' + projectId);
        const json = await res.json();
        if (!json.success) return;
        document.getElementById('reg_total_issues').textContent = json.issues_total;

        // status counts
        const sc = json.status_counts || {};
        const scEl = document.getElementById('reg_status_counts');
        scEl.innerHTML = Object.keys(sc).map(k => `<div>${k}: ${sc[k]}</div>`).join('');

        // phase counts
        const pcEl = document.getElementById('reg_phase_counts');
        pcEl.innerHTML = (json.phase_counts || []).map(p => `<div>${p.phase}: ${p.cnt}</div>`).join('');

        // expose session/readonly flag for tasks rendering
        window.regressionSession = json.session || null;
        window.regressionReadOnly = !!(json.session && json.session.status === 'ended');

        // show latest round info (if any)
        try {
            const round = json.round || null;
            const roundNumEl = document.getElementById('reg_round_number');
            if (round && round.round_number) {
                roundNumEl.textContent = round.round_number;
                // if admin and round not confirmed, show confirm/edit
                <? php if (in_array($userRole, ['admin', 'super_admin'])): ?>
                    const confirmBtn = document.getElementById('regConfirmBtn');
                const editBtn = document.getElementById('regEditBtn');
                if (round.admin_confirmed && round.admin_confirmed != 0) {
                    confirmBtn.classList.add('d-none');
                    editBtn.classList.remove('d-none');
                } else {
                    confirmBtn.classList.remove('d-none');
                    editBtn.classList.remove('d-none');
                }
                // attach handlers (idempotent)
                confirmBtn.onclick = async function () {
                    const fd = new FormData(); fd.append('action', 'confirm_round'); fd.append('round_id', round.id);
                    const r = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php', { method: 'POST', body: fd });
                    const j = await r.json();
                    if (j.success) { showToast('Round confirmed', 'success'); fetchRegression(); } else showToast('Failed to confirm', 'danger');
                };
                editBtn.onclick = function () {
                    (async function () {
                        let newNum = null;
                        // Use preserved native prompt if available
                        if (typeof window._origPrompt === 'function') {
                            try { newNum = window._origPrompt('Enter new round number', round.round_number || ''); } catch (e) { newNum = null; }
                            if (!newNum) return;
                            newNum = parseInt(newNum, 10);
                            if (!newNum) return;
                        } else {
                            // build modal fallback if prompt disabled
                            const mid = 'regRoundEditModal';
                            let modalEl = document.getElementById(mid);
                            if (!modalEl) {
                                modalEl = document.createElement('div');
                                modalEl.className = 'modal fade';
                                modalEl.id = mid;
                                modalEl.innerHTML = '<div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Edit Round Number</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="number" id="reg_round_edit_input" class="form-control" /></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" id="reg_round_edit_save" class="btn btn-primary">Save</button></div></div></div>';
                                document.body.appendChild(modalEl);
                            }
                            // set current value
                            const inp = modalEl.querySelector('#reg_round_edit_input');
                            if (inp) inp.value = round.round_number || '';
                            const bm = new bootstrap.Modal(modalEl);
                            bm.show();
                            await new Promise((resolve) => {
                                const saveBtn = modalEl.querySelector('#reg_round_edit_save');
                                const cancelBtns = modalEl.querySelectorAll('[data-bs-dismiss]');
                                const onClose = function () { resolve(); };
                                cancelBtns.forEach(b => b.addEventListener('click', onClose, { once: true }));
                                if (saveBtn) {
                                    saveBtn.addEventListener('click', function () {
                                        newNum = parseInt(modalEl.querySelector('#reg_round_edit_input').value, 10);
                                        bm.hide();
                                        resolve();
                                    }, { once: true });
                                }
                            });
                            if (!newNum) return;
                        }
                        // call API to update
                        try {
                            const fd = new FormData(); fd.append('action', 'edit_round'); fd.append('round_id', round.id); fd.append('round_number', parseInt(newNum, 10));
                            const r = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php', { method: 'POST', body: fd });
                            const j = await r.json();
                            if (j && j.success) { showToast('Round number updated', 'success'); fetchRegression(); }
                            else showToast('Failed to update', 'danger');
                        } catch (e) { showToast('Failed to update', 'danger'); }
                    })();
                };
                <? php endif; ?>
            } else {
                if (roundNumEl) roundNumEl.textContent = '—';
                <? php if (in_array($userRole, ['admin', 'super_admin'])): ?>
                    document.getElementById('regConfirmBtn').classList.add('d-none');
                document.getElementById('regEditBtn').classList.add('d-none');
                <? php endif; ?>
            }
        } catch (e) { /* ignore */ }

        // user counts and per-user attempted issue lists
        const ucEl = document.getElementById('reg_user_counts');
        const users = json.user_counts || [];
        const attemptsByUser = json.attempts_by_user || {};
        // build HTML: each user row shows counts and a toggle to expand issue list
        let out = '';
        users.forEach(u => {
            const uid = u.id || 0;
            const assigned = u.assigned_count || 0;
            const completed = u.completed_count || 0;
            const changes = u.issue_changes_count || 0;
            const total = u.total_activity || (assigned + completed + changes);
            const issueList = attemptsByUser[uid] || [];
            const listId = 'reg_user_issues_' + uid;
            out += `<div class="mb-2">`;
            out += `<div class="d-flex justify-content-between align-items-center">`;
            out += `<div><strong>${escapeHtml(u.full_name)}</strong> — assigned: ${assigned} — completed: ${completed} — changes: ${changes} — total: ${total}</div>`;
            if (issueList.length) {
                out += `<div><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#${listId}" aria-expanded="false">Show Issues (${issueList.length})</button></div>`;
            }
            out += `</div>`;
            if (issueList.length) {
                out += `<div class="collapse mt-2" id="${listId}"><div class="card card-body p-2">`;
                issueList.forEach(it => {
                    out += `<div class="mb-1"><strong>${escapeHtml(it.issue_key || ('#' + it.issue_id))}</strong> — ${escapeHtml(it.last_status || '')} <small class="text-muted">${escapeHtml(it.last_changed_at || '')}</small></div>`;
                });
                out += `</div></div>`;
            }
            out += `</div>`;
        });
        ucEl.innerHTML = out || '<div class="text-muted">No activity</div>';
    }

    document.getElementById('startRegression')?.addEventListener('click', async function () {
        if (!confirm('Start regression for this project?')) return;
        const res = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php?action=start&project_id=' + projectId);
        const j = await res.json();
        if (j.success) {
            // update UI immediately
            document.getElementById('startRegression')?.classList.add('d-none');
            document.getElementById('endRegression')?.classList.remove('d-none');
            window.regressionSession = j.session || { status: 'active' };
            window.regressionReadOnly = false;
            showToast('Regression started', 'success');
            // reflect round info immediately if returned
            try {
                if (j.round && j.round.round_number) {
                    const rnd = document.getElementById('reg_round_number'); if (rnd) rnd.textContent = j.round.round_number;
                    <? php if (in_array($userRole, ['admin', 'super_admin'])): ?>
                        document.getElementById('regConfirmBtn').classList.remove('d-none');
                    document.getElementById('regEditBtn').classList.remove('d-none');
                    <? php endif; ?>
                }
            } catch (e) { }
            await fetchRegression();
            await fetchTasks();
        } else showToast('Error: ' + (j.message || 'unknown'), 'danger');
    });

    document.getElementById('endRegression')?.addEventListener('click', async function () {
        if (!confirm('End regression for this project? This will make regression data read-only.')) return;
        const res = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php?action=end&project_id=' + projectId);
        const j = await res.json();
        if (j.success) {
            // update UI immediately
            document.getElementById('endRegression')?.classList.add('d-none');
            document.getElementById('startRegression')?.classList.remove('d-none');
            window.regressionSession = j.session || { status: 'ended' };
            window.regressionReadOnly = true;
            showToast('Regression ended', 'success');
            // keep round visible; admin may confirm/edit
            try {
                <? php if (in_array($userRole, ['admin', 'super_admin'])): ?>
                    document.getElementById('regConfirmBtn').classList.remove('d-none');
                document.getElementById('regEditBtn').classList.remove('d-none');
                <? php endif; ?>
            } catch (e) { }
            await fetchRegression();
            await fetchTasks();
        } else showToast('Error: ' + (j.message || 'unknown'), 'danger');
    });

    // Refresh when Regression tab is shown and poll while visible
    let _regPollId = null;
    document.getElementById('regression-tab-btn').addEventListener('shown.bs.tab', function () {
        fetchRegression();
        if (_regPollId) clearInterval(_regPollId);
        _regPollId = setInterval(fetchRegression, 20000);
    });
    document.getElementById('regression-tab-btn').addEventListener('hidden.bs.tab', function () {
        if (_regPollId) { clearInterval(_regPollId); _regPollId = null; }
    });
    // Load if tab active on page load
    if (document.querySelector('#regression').classList.contains('active')) fetchRegression();

    // Tasks
    async function fetchTasks() {
        const res = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php?action=get_tasks&project_id=' + projectId);
        const json = await res.json();
        if (!json.success) { document.getElementById('reg_tasks_list').textContent = 'Error loading tasks'; return; }
        const list = json.tasks || [];
        if (!list.length) { document.getElementById('reg_tasks_list').innerHTML = '<div class="text-muted p-2">No tasks</div>'; return; }

        // group by phase
        const phases = {};
        list.forEach(t => {
            const p = t.phase || 'Unassigned';
            if (!phases[p]) phases[p] = [];
            phases[p].push(t);
        });

        // build accordion
        let html = '<div class="accordion" id="regPhaseAccordion">';
        let idx = 0;
        for (const phaseName of Object.keys(phases)) {
            const tasks = phases[phaseName];
            const count = tasks.length;
            const safeId = 'phase_' + idx++;
            html += `<div class="accordion-item">
                <h2 class="accordion-header" id="heading_${safeId}">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_${safeId}">
                        ${escapeHtml(phaseName)} <span class="badge bg-secondary ms-2">${count}</span>
                    </button>
                </h2>
                <div id="collapse_${safeId}" class="accordion-collapse collapse" data-bs-parent="#regPhaseAccordion">
                    <div class="accordion-body p-0">
                                <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Title</th><th>Page</th><th>Environment</th><th>Assigned</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                                <tbody>`;
            tasks.forEach(t => {
                const assigned = t.assigned_to_name || '—';
                const status = t.status || 'open';
                const created = t.created_at || '';
                const canComplete = !window.regressionReadOnly && status !== 'completed' && status !== 'done';
                html += `<tr>
                    <td>${escapeHtml(t.title)}</td>
                    <td>${escapeHtml(t.page_name || '—')}</td>
                    <td>${escapeHtml(t.environment_name || '—')}</td>
                    <td>${escapeHtml(assigned)}</td>
                    <td>${escapeHtml(status)}</td>
                    <td>${escapeHtml(created)}</td>
                    <td>`;
                if (canComplete) html += `<button class="btn btn-sm btn-success me-1" data-task-id="${t.id}" data-action="complete">Complete</button>`;
                // Edit button
                html += `<button class="btn btn-sm btn-secondary me-1" data-task-id="${t.id}" data-action="edit" data-page-id="${t.page_id || ''}" data-environment-id="${t.environment_id || ''}">Edit</button>`;
                html += `</td></tr>`;
            });
            html += `</tbody></table></div></div></div></div>`;
        }
        html += '</div>';

        document.getElementById('reg_tasks_list').innerHTML = html;

        // attach action handlers
        document.querySelectorAll('#reg_tasks_list [data-action="complete"]').forEach(btn => {
            btn.addEventListener('click', async function () {
                const taskId = this.getAttribute('data-task-id');
                if (!confirm('Mark task as completed?')) return;
                const fd = new FormData(); fd.append('action', 'update_task'); fd.append('task_id', taskId); fd.append('status', 'completed');
                const r = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.success) { fetchTasks(); fetchRegression(); } else showToast('Error: ' + (j.message || 'unknown'), 'danger');
            });
        });
        // Edit buttons
        document.querySelectorAll('#reg_tasks_list [data-action="edit"]').forEach(btn => {
            btn.addEventListener('click', function () {
                const taskId = this.getAttribute('data-task-id');
                const task = list.find(t => String(t.id) === String(taskId));
                if (!task) { showToast('Task data not found', 'warning'); return; }
                // populate modal
                document.getElementById('reg_task_id').value = task.id;
                document.getElementById('reg_title').value = task.title || '';
                document.getElementById('reg_description').value = task.description || '';
                document.getElementById('reg_phase').value = task.phase || '';
                document.getElementById('reg_status').value = task.status || 'open';
                document.getElementById('reg_assigned_user_id').value = task.assigned_user_id || '';
                var modal = new bootstrap.Modal(document.getElementById('regEditModal'));
                modal.show();
            });
        });
    }

    // fetch eligible assignees for create/edit forms based on selected page/env
    async function fetchAssignees(pageId, envId, targetSelectId, preselectId) {
        const url = new URL('<?php echo $baseDir; ?>/api/regression_actions.php', window.location.origin);
        url.searchParams.set('action', 'get_assignees');
        url.searchParams.set('project_id', projectId);
        if (pageId) url.searchParams.set('page_id', pageId);
        if (envId) url.searchParams.set('environment_id', envId);
        const res = await fetch(url.toString());
        const j = await res.json();
        if (!j.success) return;
        const sel = document.getElementById(targetSelectId);
        if (!sel) return;
        const curVal = sel.value;
        sel.innerHTML = '<option value="">Assign to user (optional)</option>';
        j.users.forEach(u => {
            const opt = document.createElement('option');
            opt.value = u.id; opt.text = u.full_name;
            sel.appendChild(opt);
        });
        // try to preserve selection
        if (preselectId) sel.value = preselectId;
        else if (curVal) sel.value = curVal;
    }

    // wire create form selects
    document.getElementById('reg_create_page_id')?.addEventListener('change', function () {
        const pid = this.value || '';
        const eid = document.getElementById('reg_create_environment_id')?.value || '';
        fetchAssignees(pid, eid, 'reg_create_assigned_user');
    });
    document.getElementById('reg_create_environment_id')?.addEventListener('change', function () {
        const eid = this.value || '';
        const pid = document.getElementById('reg_create_page_id')?.value || '';
        fetchAssignees(pid, eid, 'reg_create_assigned_user');
    });

    // when edit modal is shown, fetch assignees for that task's page/env
    var regEditModalEl = document.getElementById('regEditModal');
    if (regEditModalEl) {
        regEditModalEl.addEventListener('show.bs.modal', function (event) {
            // when modal opens, ensure assigned-user list matches task context
            const taskId = document.getElementById('reg_task_id').value || null;
            // attempt to find task row in loaded list
            // if task object exists in window (from fetchTasks), try to locate it
            // fallback: fetch task via API (not implemented) — we'll attempt to preserve currently selected value
            const pageId = document.getElementById('reg_page_id_for_modal')?.value || '';
            const envId = document.getElementById('reg_env_id_for_modal')?.value || '';
            const pre = document.getElementById('reg_assigned_user_id')?.value || '';
            fetchAssignees(pageId, envId, 'reg_assigned_user_id', pre);
        });
    }

    function escapeHtml(s) { if (!s) return ''; return String(s).replace(/[&"'<>]/g, function (m) { return ({ '&': '&amp;', '"': '&quot;', '\'': '&#39;', '<': '&lt;', '>': '&gt;' }[m]); }); }

    document.getElementById('createRegressionTask')?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action', 'create_task');
        fd.append('project_id', projectId);
        const res = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php', { method: 'POST', body: fd });
        const j = await res.json();
        if (j.success) { showToast('Task created', 'success'); fetchTasks(); fetchRegression(); e.target.reset(); }
        else showToast('Error: ' + (j.message || 'unknown'), 'danger');
    });

    // Regression assignments: list and add
    async function fetchAssignments() {
        const res = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php?action=get_assignments&project_id=' + projectId);
        const j = await res.json();
        if (!j.success) { document.getElementById('reg_assignments_list').textContent = 'Error loading assignments'; return; }
        const rows = j.assignments || [];
        if (!rows.length) { document.getElementById('reg_assignments_list').innerHTML = '<div class="text-muted p-2">No assignments</div>'; return; }
        let html = '<ul class="list-group">';
        rows.forEach(r => {
            html += '<li class="list-group-item d-flex justify-content-between align-items-center">'
                + '<div>' + escapeHtml(r.full_name || 'Unknown') + '</div>'
                + '<div class="d-flex align-items-center gap-2">'
                + '<small class="text-muted">' + (r.created_at ? r.created_at : '') + '</small>';
            // show remove button for privileged users
            html += <? php echo in_array($userRole, ['admin', 'super_admin', 'project_lead']) ? '"<button class=\"btn btn-sm btn-outline-danger ms-2 reg-assign-remove\" data-id=\"" + r.id + "\">Remove</button>"' : '""'; ?>;
            html += '</div></li>';
        });
        html += '</ul>';
        document.getElementById('reg_assignments_list').innerHTML = html;
        // attach remove handlers (delegated)
        document.querySelectorAll('.reg-assign-remove').forEach(function (btn) {
            btn.addEventListener('click', async function (e) {
                e.preventDefault();
                if (typeof confirmModal === 'function') {
                    confirmModal('Remove this assignment? This action cannot be undone.', async function() {
                        const aid = this.getAttribute('data-id');
                        const fd = new FormData(); fd.append('action', 'delete_assignment'); fd.append('project_id', projectId); fd.append('assignment_id', aid);
                        const r = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php', { method: 'POST', body: fd });
                        const jj = await r.json();
                        if (jj.success) { fetchAssignments(); } else showToast('Error: ' + (jj.message || 'unknown'), 'danger');
                    }.bind(this));
                } else {
                    if (!confirm('Remove this assignment?')) return;
                    const aid = this.getAttribute('data-id');
                    const fd = new FormData(); fd.append('action', 'delete_assignment'); fd.append('project_id', projectId); fd.append('assignment_id', aid);
                    const r = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php', { method: 'POST', body: fd });
                    const jj = await r.json();
                    if (jj.success) { fetchAssignments(); } else showToast('Error: ' + (jj.message || 'unknown'), 'danger');
                }
            });
        });
    }

    document.getElementById('reg_assign_add_btn')?.addEventListener('click', async function (e) {
        e.preventDefault();
        const sel = document.getElementById('reg_assign_user_select');
        if (!sel) return;
        const uid = sel.value;
        if (!uid) { showToast('Select a user to assign', 'warning'); return; }
        const fd = new FormData(); fd.append('action', 'add_assignment'); fd.append('project_id', projectId); fd.append('assigned_user_id', uid);
        const res = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php', { method: 'POST', body: fd });
        const j = await res.json();
        if (j.success) { showToast('Assigned', 'success'); sel.value = ''; fetchAssignments(); } else showToast('Error: ' + (j.message || 'unknown'), 'danger');
    });

    // load assignments when regression tab shown
    document.getElementById('regression-tab-btn')?.addEventListener('shown.bs.tab', function () { fetchAssignments(); });
    // if regression tab active on load, fetch assignments
    if (document.querySelector('#regression').classList.contains('active')) fetchAssignments();

    // Load tasks when tab shown
    document.getElementById('regression-tab-btn').addEventListener('shown.bs.tab', function () { fetchTasks(); });
    if (document.querySelector('#regression').classList.contains('active')) fetchTasks();

    // If a link opened this page with a regression task/assignment to open, handle it.
    (async function () {
        try {
            const params = new URLSearchParams(window.location.search);
            const openTask = params.get('open_reg_task');
            const openAssign = params.get('open_reg_assignment');
            if (!openTask && !openAssign) return;
            // ensure regression tab is active
            const regTabBtn = document.getElementById('regression-tab-btn');
            if (regTabBtn) regTabBtn.click();

            if (openTask) {
                await fetchTasks();
                // allow DOM to render handlers
                setTimeout(function () {
                    const editBtn = document.querySelector('[data-action="edit"][data-task-id="' + openTask + '"]');
                    if (editBtn) editBtn.click();
                }, 200);
            }

            if (openAssign) {
                await fetchAssignments();
                setTimeout(function () {
                    // scroll to assignment list item (look for remove button which has data-id)
                    const listEl = document.querySelector('#reg_assignments_list');
                    if (!listEl) return;
                    const removeBtn = listEl.querySelector('.reg-assign-remove[data-id="' + openAssign + '"]');
                    const li = removeBtn ? removeBtn.closest('li') : listEl.querySelector('li');
                    if (li) li.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 200);
            }
        } catch (e) {
        }
    })();
});

// Handle edit form submission
document.getElementById('regEditForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'update_task');
    const res = await fetch('<?php echo $baseDir; ?>/api/regression_actions.php', { method: 'POST', body: fd });
    const j = await res.json();
    if (j.success) { showToast('Saved', 'success'); fetchTasks(); fetchRegression(); new bootstrap.Modal(document.getElementById('regEditModal')).hide(); }
    else showToast('Error: ' + (j.message || 'unknown'), 'danger');
});

$(document).ready(function () {
    // Initialize Summernote for feedback editor
    // Initialize Summernote for feedback editor with fallback loader
    function initFeedbackEditor() {
        try {
            if (window.jQuery && typeof jQuery.fn.summernote === 'function') {
                $('#pf_editor').summernote({
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
        } catch (e) {
            // Summernote init error suppressed
        }
        return false;
    }

    if (!initFeedbackEditor()) {
        // Try loading script dynamically if summernote not available
        $.getScript('https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js')
            .then(function (j) {
                if (j && j.success) {
                    // replace cell content with new text and re-add edit button
                    var display = $('<span>').text(newName);
                    var editBtn = $('<button class="btn btn-sm btn-link edit-page-name ms-2">Edit</button>');
                    editBtn.attr('data-field', field);
                    editBtn.attr('data-unique-id', uniqueId);
                    if (pageId) editBtn.attr('data-page-id', pageId); else editBtn.attr('data-page-id', j.created_page_id ? j.created_page_id : 0);
                    editBtn.attr('data-current-name', newName);
                    td.html(display);
                    td.append(editBtn);
                    // if API created a project page and returned a page_number, update the Page No cell (previous sibling)
                    if (j.page_number) {
                        var pageNoCell = td.closest('tr').find('td').eq(1); // first td is checkbox, second is Page No
                        if (pageNoCell.length) pageNoCell.text(j.page_number);
                    }
                    showToast('Updated', 'success');
                } else {
                    alert('Update failed: ' + (j.error || j.message || 'Unknown'));
                    td.html(originalHtml);
                }
            }).catch(function (err) { alert('Request error'); td.html(originalHtml); });
    }

    var recipients = $('#pf_recipients').val() || [];
    var isGeneric = $('#pf_is_generic').is(':checked') ? 1 : 0;

    var data = {
        action: 'submit_feedback',
        project_id: <? php echo $projectId; ?>,
        content: content,
        recipient_ids: recipients,
        is_generic: isGeneric
};



$.ajax({
    url: '<?php echo $baseDir; ?>/api/feedback.php',
    type: 'POST',
    data: data,
    success: function (response) {

        if (response.success) {
            showToast('Feedback submitted successfully', 'success');
            $('#pf_editor').summernote('code', '');
            $('#pf_recipients').val([]);
            $('#pf_is_generic').prop('checked', false);

            // Reload the page to show new feedback
            setTimeout(function () {
                location.reload();
            }, 1000);
        } else {
            showToast('Failed to submit feedback: ' + (response.message || 'Unknown error'), 'danger');
        }
    },
    error: function (xhr, status, error) {
        showToast('Error submitting feedback: ' + error, 'danger');
    }
});
    });

// Production Hours quick-form: load pages/environments/issues and submit
function initProductionHours() {
    var $form = $('#logProductionHoursForm');
    var projectId = '<?php echo $projectId; ?>';
    var $pageSel = $('#productionPageSelect');
    var $envSel = $('#productionEnvSelect');
    var $issueCont = $('#productionIssueSelect');
    var $issueSel = $('#productionIssueSelect');
    var $taskType = $('#taskTypeSelect');
    var $pageTestingCont = $('#pageTestingContainer');
    var $phaseCont = $('#projectPhaseContainer');
    var $genericCont = $('#genericTaskContainer');
    var $genericCat = $('#genericCategorySelect');
    var $phaseSelect = $('#projectPhaseSelect');
    var $regressionCont = $('#regressionContainer');
    var $regressionSummary = $('#regressionSummary');
    var $dateInput = $form.find('[name="log_date"]');

    // Restrict allowed log dates to today and the previous business day
    function setAllowedLogDates() {
        var today = new Date();
        var dow = today.getDay(); // 0 = Sun, 1 = Mon
        var prev = new Date(today);
        if (dow === 1) { // Monday -> allow Saturday as previous business day
            prev.setDate(prev.getDate() - 2);
        } else {
            prev.setDate(prev.getDate() - 1);
        }
        function fmt(d) {
            var y = d.getFullYear();
            var m = ('0' + (d.getMonth() + 1)).slice(-2);
            var dd = ('0' + d.getDate()).slice(-2);
            return y + '-' + m + '-' + dd;
        }
        var min = fmt(prev), max = fmt(today);
        $dateInput.attr('min', min).attr('max', max);
        var cur = $dateInput.val();
        if (!cur || cur < min || cur > max) $dateInput.val(max);
        var note = 'Only ' + min + ' and ' + max + ' are editable. For older dates, send an edit request to admin.';
        var info = document.getElementById('proj_log_date_info');
        if (info) {
            info.setAttribute('title', note);
            try {
                var existing = bootstrap.Tooltip.getInstance(info);
                if (existing) existing.dispose();
            } catch (e) { }
            try { new bootstrap.Tooltip(info, { trigger: 'hover focus' }); } catch (e) { }
        }
    }
    setAllowedLogDates();

    function loadProjectPages() {
        $pageSel.html('<option value="">Loading pages...</option>');
        fetch('<?php echo $baseDir; ?>/api/tasks.php?project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (pages) {
                $pageSel.empty();
                $pageSel.append('<option value="">(none)</option>');
                if (Array.isArray(pages)) pages.forEach(function (pg) {
                    $pageSel.append('<option value="' + pg.id + '">' + (pg.page_name || pg.title || pg.url || ('Page ' + pg.id)) + '</option>');
                });
            }).catch(function () {
                $pageSel.html('<option value="">Error loading pages</option>');
            });
    }

    function loadProjectPhases() {
        $phaseSelect.html('<option value="">Loading phases...</option>');
        fetch('<?php echo $baseDir; ?>/api/projects.php?action=get_phases&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
            .then(r => r.text())
            .then(function (txt) {
                try { var phases = JSON.parse(txt); } catch (e) { throw new Error(txt); }
                $phaseSelect.empty();
                $phaseSelect.append('<option value="">Select project phase</option>');
                if (Array.isArray(phases)) {
                    phases.forEach(function (phase) {
                        var opt = '<option value="' + phase.id + '">' + (phase.phase_name || phase.name || phase.id) + '</option>';
                        $phaseSelect.append(opt);
                    });
                }
            }).catch(function () { $phaseSelect.html('<option value="">Error loading phases</option>'); });
    }

    function loadGenericCategories() {
        $genericCat.html('<option value="">Loading categories...</option>');
        fetch('<?php echo $baseDir; ?>/api/generic_tasks.php?action=get_categories', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (categories) {
                $genericCat.empty(); $genericCat.append('<option value="">Select category</option>');
                if (Array.isArray(categories)) categories.forEach(function (c) { $genericCat.append('<option value="' + c.id + '">' + (c.name || c.title || c.id) + '</option>'); });
            }).catch(function () { $genericCat.html('<option value="">Error loading categories</option>'); });
    }

    function loadRegressionSummary() {
        $regressionSummary.text('Loading...');
        fetch('<?php echo $baseDir; ?>/api/regression_actions.php?action=get_stats&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (json) {
                if (!json || !json.success) { $regressionSummary.text('Error'); return; }
                var html = '<div><strong>Total issues:</strong> ' + (json.issues_total || 0) + '</div>';
                html += '<div><strong>Attempted:</strong> ' + (json.attempted_issues_total || 0) + '</div>';
                html += '<div class="mt-1"><strong>By status:</strong><br/>';
                var asc = json.attempted_status_counts || {};
                for (var k in asc) html += '<div>' + k + ': ' + asc[k] + '</div>';
                html += '</div>';
                $regressionSummary.html(html);
            }).catch(function () { $regressionSummary.text('Error'); });
    }

    function loadPageEnvironments(pageId) {
        $envSel.html('<option value="">Loading environments...</option>');
        if (!pageId) { $envSel.html('<option value="">Select page first</option>'); return; }
        fetch('<?php echo $baseDir; ?>/api/tasks.php?page_id=' + encodeURIComponent(pageId), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) return res.text().then(t => { throw new Error('HTTP ' + res.status + ': ' + t); });
                return res.json();
            })
            .then(function (page) {
                $envSel.empty();
                if (page && page.environments && page.environments.length) {
                    $envSel.append('<option value="">(none)</option>');
                    page.environments.forEach(function (env) {
                        // defensive: ensure id exists
                        var id = env.id || env.environment_id || env.environmentId || '';
                        var name = env.name || env.env_name || env.environment_name || ('Env ' + id);
                        if (!id) return;
                        $envSel.append('<option value="' + id + '">' + (name) + '</option>');
                    });
                } else {
                    $envSel.append('<option value="">No environments</option>');
                }
            }).catch(function (err) {
                // Error loading environments suppressed
                $envSel.html('<option value="">Error loading environments</option>');
            });
    }

    function loadProjectIssues(pageId) {
        $issueSel.html('<option value="">Loading issues...</option>');
        fetch('<?php echo $baseDir; ?>/api/regression_actions.php?action=get_project_issues&project_id=' + encodeURIComponent(projectId) + (pageId ? '&page_id=' + encodeURIComponent(pageId) : ''), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (data) {
                $issueSel.empty();
                $issueSel.append('<option value="">(none)</option>');
                if (data && Array.isArray(data.issues)) {
                    data.issues.forEach(function (it) {
                        $issueSel.append('<option value="' + it.id + '">' + ((it.issue_key ? it.issue_key + ' - ' : '') + (it.title || ('Issue ' + it.id))) + '</option>');
                    });
                }
            }).catch(function () { $issueSel.html('<option value="">Error loading issues</option>'); });
    }

    // init
    loadProjectPages();

    $pageSel.off('change').on('change', function () {
        var val = $(this).val();
        var pid = Array.isArray(val) ? (val[0] || '') : (val || '');
        loadPageEnvironments(pid);
        // If the current task type is regression_testing, load issues for the page (use first selected)
        if ($taskType.val() === 'regression_testing') loadProjectIssues(pid);
    });

    $taskType.off('change').on('change', function () {
        var t = $(this).val();
        // hide all containers by default
        $pageTestingCont.hide(); $phaseCont.hide(); $genericCont.hide(); $regressionCont.hide(); $issueCont.hide();
        if (!t) return;
        if (t === 'page_testing' || t === 'page_qa') {
            $pageTestingCont.show(); loadProjectPages();
        } else if (t === 'regression_testing') {
            $regressionCont.show(); loadRegressionSummary();
            $issueCont.show();
            var firstPage = $pageSel.val() || null;
            loadProjectIssues(firstPage);
        } else if (t === 'project_phase') {
            $phaseCont.show(); loadProjectPhases();
        } else if (t === 'generic_task') {
            $genericCont.show(); loadGenericCategories();
        }
    });

    $form.off('submit').on('submit', function (e) {
        e.preventDefault();
        var fd = {};
        fd.action = 'log';
        fd.user_id = $form.find('[name="user_id"]').val() || <? php echo $userId; ?>;
        fd.project_id = $form.find('[name="project_id"]').val();
        var pageVals = $form.find('[name="page_ids[]"]').val() || $form.find('[name="page_id"]').val() || '';
        fd.page_id = Array.isArray(pageVals) ? (pageVals[0] || '') : (pageVals || '');
        var envVals = $form.find('[name="environment_ids[]"]').val() || $form.find('[name="environment_id"]').val() || '';
        fd.environment_id = Array.isArray(envVals) ? (envVals[0] || '') : (envVals || '');
        fd.issue_id = $form.find('[name="issue_id"]').val() || '';
        fd.testing_type = $form.find('[name="testing_type"]').val() || '';
        fd.log_date = $form.find('[name="log_date"]').val() || '';
        fd.hours = $form.find('[name="hours"]').val();
        fd.description = $form.find('[name="description"]').val();
        fd.is_utilized = 1;

        if (!fd.hours || parseFloat(fd.hours) <= 0) { showToast('Please enter valid hours', 'warning'); return; }

        $.ajax({
            url: '<?php echo $baseDir; ?>/api/project_hours.php',
            method: 'POST',
            data: fd,
            dataType: 'json',
            success: function (resp) {
                if (resp.success) {
                    // fetch updated production-hours panel and replace
                    fetch(window.location.href, { credentials: 'same-origin' }).then(r => r.text()).then(function (html) {
                        try {
                            var parser = new DOMParser();
                            var doc = parser.parseFromString(html, 'text/html');
                            var newPanel = doc.querySelector('#production-hours');
                            var curPanel = document.querySelector('#production-hours');
                            if (newPanel && curPanel) {
                                curPanel.innerHTML = newPanel.innerHTML;
                                // re-run init to bind handlers
                                initProductionHours();
                            } else {
                                // fallback to reload
                                location.reload();
                            }
                        } catch (e) { location.reload(); }
                    }).catch(function () { location.reload(); });
                } else {
                    showToast('Failed to log hours: ' + (resp.message || resp.error || 'Unknown'), 'danger');
                }
            },
            error: function (xhr) { showToast('Error logging hours', 'danger'); }
        });
    });
}

// call init on load
initProductionHours();

// Asset Modal Toggle
$('input[name="asset_type"]').on('change', function () {

    var assetType = $(this).val();

    // Hide all field groups first
    $('#link_fields').hide();
    $('#file_fields').hide();
    $('#text_fields').hide();

    // Reset required states
    $('#main_url').prop('required', false);
    $('#asset_file').prop('required', false);

    if (assetType === 'link') {
        $('#link_fields').show();
        $('#main_url').prop('required', true);
    } else if (assetType === 'file') {
        $('#file_fields').show();
        $('#asset_file').prop('required', true);
    } else if (assetType === 'text') {
        $('#text_fields').show();
        // Initialize Summernote for text content if not already initialized
        if (!$('#text_content_editor').hasClass('note-editor')) {
            $('#text_content_editor').summernote({
                height: 200,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['fontname', ['fontname']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ]
            });

        }
    }
});

// Set initial required state
$('#main_url').prop('required', true);

// Auto-switch to assets or pages tab if hash exists
if (window.location.hash === '#assets') {
    $('#assets-tab').tab('show');
}
if (window.location.hash === '#pages' || window.location.hash.startsWith('#page-details-')) {
    $('#pages-tab').tab('show');
    // If a specific page detail anchor is present, expand that collapse and scroll to it
    if (window.location.hash.startsWith('#page-details-')) {
        var target = window.location.hash.substring(1); // remove '#'
        var $el = $('#' + target);
        if ($el.length) {
            $el.collapse('show');
            // scroll slightly above the element for visibility
            setTimeout(function () {
                var top = $el.offset().top - 80;
                $('html,body').animate({ scrollTop: top }, 300);
            }, 200);
        }
    }
}

// Handle View Text Content modal
$('#viewTextModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget);
    var title = button.data('title');
    var content = button.data('content');

    var modal = $(this);
    modal.find('#viewTextModalTitle').text(title);
    modal.find('#viewTextModalContent').html(content);
});

// Handle phase status updates
$('.phase-status-update').on('change', function () {
    var phaseId = $(this).data('phase-id');
    var projectId = $(this).data('project-id');
    var newStatus = $(this).val();
    var $select = $(this);



    $.ajax({
        url: '<?php echo $baseDir; ?>/api/update_phase.php',
        type: 'POST',
        data: {
            phase_id: phaseId,
            project_id: projectId,
            field: 'status',
            value: newStatus
        },
        success: function (response) {

            if (response.success) {
                // Show success message briefly
                var $row = $select.closest('tr');
                $row.addClass('table-success');
                setTimeout(function () {
                    $row.removeClass('table-success');
                }, 2000);
            } else {
                showToast('Failed to update phase status: ' + (response.message || 'Unknown error'), 'danger');
                // Revert the select to previous value
                $select.val($select.data('original-value') || 'not_started');
            }
        },
        error: function (xhr, status, error) {
            showToast('Error updating phase status: ' + error, 'danger');
            $select.val($select.data('original-value') || 'not_started');
        }
    });
});

// Store original values for phase status selects
$('.phase-status-update').each(function () {
    $(this).data('original-value', $(this).val());
});

// Handle page status updates
$('.page-status-update').on('change', function () {
    var pageId = $(this).data('page-id');
    var projectId = $(this).data('project-id');
    var newStatus = $(this).val();
    var $select = $(this);



    $.ajax({
        url: '<?php echo $baseDir; ?>/api/update_page_status.php',
        type: 'POST',
        data: {
            page_id: pageId,
            project_id: projectId,
            status: newStatus
        },
        success: function (response) {

            if (response.success) {
                // Show success message briefly
                var $row = $select.closest('tr');
                $row.addClass('table-success');
                setTimeout(function () {
                    $row.removeClass('table-success');
                }, 2000);
            } else {
                showToast('Failed to update page status: ' + (response.message || 'Unknown error'), 'danger');
                // Revert the select to previous value
                $select.val($select.data('original-value') || 'not_started');
            }
        },
        error: function (xhr, status, error) {
            showToast('Error updating page status: ' + error, 'danger');
            $select.val($select.data('original-value') || 'not_started');
        }
    });
});

// Store original values for page status selects
$('.page-status-update').each(function () {
    $(this).data('original-value', $(this).val());
});

// Handle environment status updates
$('.env-status-update').on('change', function () {
    var pageId = $(this).data('page-id');
    var envId = $(this).data('env-id');
    var testerType = $(this).data('tester-type');
    var newStatus = $(this).val();
    var $select = $(this);



    $.ajax({
        url: '<?php echo $baseDir; ?>/api/update_env_status.php',
        type: 'POST',
        data: {
            page_id: pageId,
            env_id: envId,
            tester_type: testerType,
            status: newStatus
        },
        success: function (response) {

            if (response.success) {
                // Show success message briefly
                var $row = $select.closest('tr');
                $row.addClass('table-success');
                setTimeout(function () {
                    $row.removeClass('table-success');
                }, 2000);
            } else {
                showToast('Failed to update environment status: ' + (response.message || 'Unknown error'), 'danger');
                // Revert the select to previous value
                $select.val($select.data('original-value') || $select.find('option:first').val());
            }
        },
        error: function (xhr, status, error) {
            showToast('Error updating environment status: ' + error, 'danger');
            $select.val($select.data('original-value') || $select.find('option:first').val());
        }
    });
});

// Store original values for environment status selects
$('.env-status-update').each(function () {
    $(this).data('original-value', $(this).val());
});

// Handle page expand/collapse functionality
$('.page-toggle-btn').on('click', function () {
    var $button = $(this);
    var $icon = $button.find('.toggle-icon');
    var $collapse = $($(this).data('bs-target'));

    // Toggle icon rotation
    $collapse.on('show.bs.collapse', function () {
        $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
        $button.attr('title', 'Collapse Details');
    });

    $collapse.on('hide.bs.collapse', function () {
        $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
        $button.attr('title', 'Expand Details');
    });
});

// Auto-expand first page if there's only one page
var pageCards = $('.page-toggle-btn').length;
if (pageCards === 1) {
    $('.page-toggle-btn').first().click();
}



// Handle Edit Phase modal
$('.edit-phase-btn').on('click', function () {
    var phaseId = $(this).data('phase-id');
    var phaseName = $(this).data('phase-name');
    var startDate = $(this).data('start-date');
    var endDate = $(this).data('end-date');
    var plannedHours = $(this).data('planned-hours');
    var status = $(this).data('status');
    // Populate the modal fields
    $('#edit_phase_id').val(phaseId);
    $('#edit_phase_name').val(phaseName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
    $('#edit_start_date').val(startDate || '');
    $('#edit_end_date').val(endDate || '');
    $('#edit_planned_hours').val(plannedHours || '');
    $('#edit_status').val(status || 'not_started');
});

// Handle interactive status dropdowns (Page and Environment)
$(document).on('click', '.status-update-link', function (e) {
    e.preventDefault();
    var $link = $(this);
    var action = $link.data('action'); // update_page_status or update_env_status
    var pageId = $link.data('page-id');
    var status = $link.data('status');
    var envId = $link.data('environment-id');

    var data = {
        action: action,
        page_id: pageId,
        status: status
    };

    if (envId) {
        data.environment_id = envId;
    }

    $.ajax({
        url: '<?php echo $baseDir; ?>/api/status.php',
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function (response) {
            if (response.success) {
                // For now, reloading is the safest way to ensure all badges/parents update correctly
                // especially with the new automation logic in place
                location.reload();
            } else {
                showToast('Error: ' + (response.message || 'Unknown error'), 'danger');
            }
        },
        error: function (xhr, status, error) {
            showToast('Error updating status: ' + error, 'danger');
        }
    });
});

// Floating project chat widget
var $chatWidget = $('#projectChatWidget');
var $chatLauncher = $('#chatLauncher');
var $chatClose = $('#chatWidgetClose');
var $chatFullscreen = $('#chatWidgetFullscreen');

function openChatWidget() {
    $chatWidget.addClass('open');
    $chatLauncher.hide();
}

function closeChatWidget() {
    $chatWidget.removeClass('open');
    $chatLauncher.show();
}

$chatLauncher.on('click', function () { openChatWidget(); });
$chatClose.on('click', function () { closeChatWidget(); });
$chatFullscreen.on('click', function () {
    window.location.href = '<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo $projectId; ?>';
});

// Auto-open if hash targets chat
if (window.location.hash === '#chat') {
    openChatWidget();
}

}); // End of $(document).ready
