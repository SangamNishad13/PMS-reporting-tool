/**
 * admin-projects.js - Admin projects page: sub-project mode toggle + table filtering
 */
(function () {
    // Sub-project mode toggle
    var cfg = window.AdminProjectsConfig || {};
    var projectLeads = cfg.projectLeads || [];

    var modeRadios = document.querySelectorAll('input[name="project_mode"]');
    var subContainer = document.getElementById('subprojectsContainer');
    var subList = document.getElementById('subprojectList');
    var addBtn = document.getElementById('addSubprojectBtn');
    var singleFields = document.querySelectorAll('.single-project-fields');
    var singleFieldInputs = document.querySelectorAll('.single-project-fields input, .single-project-fields select, .single-project-fields textarea');

    singleFieldInputs.forEach(function (el) {
        if (el.required) el.dataset.wasRequired = '1';
    });

    function toggleMode() {
        var showSubs = Array.from(modeRadios).some(function (r) { return r.checked && r.value === 'parent'; });
        if (subContainer) subContainer.classList.toggle('d-none', !showSubs);
        singleFields.forEach(function (el) {
            var wrapper = el.closest('.mb-3') || el;
            wrapper.classList.toggle('d-none', showSubs);
        });
        singleFieldInputs.forEach(function (el) {
            el.required = showSubs ? false : (el.dataset.wasRequired === '1');
        });
    }

    function buildLeadOptions() {
        var opts = '<option value="">Select Project Lead</option>';
        projectLeads.forEach(function (lead) {
            opts += '<option value="' + lead.id + '">' + escapeHtml(lead.full_name) + '</option>';
        });
        return opts;
    }

    function addSubRow() {
        var row = document.createElement('div');
        row.className = 'border rounded p-3 position-relative';
        row.innerHTML =
            '<button type="button" class="btn-close position-absolute top-0 end-0 mt-2 me-2" aria-label="Remove"></button>' +
            '<div class="row g-3">' +
            '<div class="col-md-6"><label class="form-label">Sub-Project Title *</label><input type="text" name="child_title[]" class="form-control" required></div>' +
            '<div class="col-md-6"><label class="form-label">Project Type *</label><select name="child_type[]" class="form-select" required>' +
            '<option value="web">Web Project</option><option value="app">App Project</option><option value="pdf">PDF Remediation</option>' +
            '</select></div>' +
            '<div class="col-md-4"><label class="form-label">Priority</label><select name="child_priority[]" class="form-select">' +
            '<option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option>' +
            '</select></div>' +
            '<div class="col-md-4"><label class="form-label">Project Lead</label><select name="child_lead_id[]" class="form-select">' + buildLeadOptions() + '</select></div>' +
            '<div class="col-md-4"><label class="form-label">Total Hours (optional)</label><input type="number" name="child_total_hours[]" class="form-control" step="0.01" min="0"></div>' +
            '</div>';
        row.querySelector('.btn-close').addEventListener('click', function () { row.remove(); });
        if (subList) subList.appendChild(row);
    }

    if (modeRadios.length) {
        modeRadios.forEach(function (radio) { radio.addEventListener('change', toggleMode); });
        toggleMode();
    }
    if (addBtn) addBtn.addEventListener('click', addSubRow);

    // Project table filtering
    $(document).ready(function () {
        function filterProjects() {
            var statusFilter = $('#statusFilter').val().toLowerCase();
            var typeFilter = $('#typeFilter').val().toLowerCase();
            var priorityFilter = $('#priorityFilter').val().toLowerCase();
            var searchText = $('#searchProject').val().toLowerCase();

            $('#projectsTable tbody > tr').each(function () {
                var row = $(this);
                if (row.hasClass('collapse')) return;
                var status = row.data('status');
                var type = row.data('type');
                var priority = row.data('priority');
                var title = row.data('title');
                var code = row.data('code');
                var showRow = true;
                if (statusFilter && status !== statusFilter) showRow = false;
                if (typeFilter && type !== typeFilter) showRow = false;
                if (priorityFilter && priority !== priorityFilter) showRow = false;
                if (searchText && String(title).indexOf(searchText) === -1 && String(code).indexOf(searchText) === -1) showRow = false;
                row.toggle(showRow);
                var collapseRow = row.next('.collapse');
                if (collapseRow.length) collapseRow.toggle(showRow);
            });
        }
        $('#statusFilter, #typeFilter, #priorityFilter').on('change', filterProjects);
        $('#searchProject').on('keyup', filterProjects);
    });

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({ '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;' })[s];
        });
    }
})();
