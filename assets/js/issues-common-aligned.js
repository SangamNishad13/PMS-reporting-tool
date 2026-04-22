/**
 * issues-common-aligned.js
 * Modeled after issues-all.js but for Common cached/all list.
 */

var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;
var baseDir   = window.ProjectConfig ? window.ProjectConfig.baseDir   : '';
var allIssues = [];
var filteredIssues = [];
var loadIssuesDebounceTimer = null;
var currentPage = 1;
var perPage = 50;

function getPagedIssues() {
    var start = (currentPage - 1) * perPage;
    return filteredIssues.slice(start, start + perPage);
}

function getTotalPages() {
    return Math.max(1, Math.ceil(filteredIssues.length / perPage));
}

function renderPagination() {
    var total = filteredIssues.length;
    var totalPages = getTotalPages();
    var start = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
    var end = Math.min(currentPage * perPage, total);
    var infoText = total === 0 ? 'No issues' : 'Showing ' + start + '–' + end + ' of ' + total;

    ['Top', ''].forEach(function(suffix) {
        var info = document.getElementById('paginationInfo' + suffix);
        var controls = document.getElementById('paginationControls' + suffix);
        var bar = document.getElementById('paginationBar' + suffix);

        if (info) info.textContent = infoText;
        if (bar) bar.style.display = totalPages <= 1 ? 'none' : '';
        if (!controls) return;
        if (totalPages <= 1) { controls.innerHTML = ''; return; }

        var html = '';
        html += '<li class="page-item' + (currentPage === 1 ? ' disabled' : '') + '">' +
            '<a class="page-link" href="#" data-page="' + (currentPage - 1) + '" aria-label="Previous">&laquo;</a></li>';

        var pages = [];
        if (totalPages <= 5) {
            for (var i = 1; i <= totalPages; i++) pages.push(i);
        } else {
            pages = [1];
            if (currentPage > 3) pages.push('...');
            var rangeStart = Math.max(2, currentPage - 1);
            var rangeEnd = Math.min(totalPages - 1, currentPage + 1);
            if (currentPage <= 3) rangeEnd = Math.min(totalPages - 1, 4);
            if (currentPage >= totalPages - 2) rangeStart = Math.max(2, totalPages - 3);
            for (var p = rangeStart; p <= rangeEnd; p++) pages.push(p);
            if (currentPage < totalPages - 2) pages.push('...');
            pages.push(totalPages);
        }

        pages.forEach(function (p) {
            if (p === '...') {
                html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
            } else {
                html += '<li class="page-item' + (p === currentPage ? ' active' : '') + '">' +
                    '<a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
            }
        });

        html += '<li class="page-item' + (currentPage === totalPages ? ' disabled' : '') + '">' +
            '<a class="page-link" href="#" data-page="' + (currentPage + 1) + '" aria-label="Next">&raquo;</a></li>';

        controls.innerHTML = html;

        controls.querySelectorAll('a.page-link[data-page]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var pg = parseInt(this.getAttribute('data-page'));
                if (pg >= 1 && pg <= getTotalPages() && pg !== currentPage) {
                    currentPage = pg;
                    renderIssues();
                    var table = document.getElementById('commonIssuesTable');
                    if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    });
}

function getCommonSelectedIds() {
    return Array.from(document.querySelectorAll('.common-select:checked')).map(function (checkbox) {
        return String(checkbox.getAttribute('data-id') || '');
    }).filter(Boolean);
}

function updateCommonSelectionState() {
    var markBtn = document.getElementById('allIssuesMarkClientReadyBtn');
    var selectAll = document.getElementById('commonSelectAll');
    var checkboxes = Array.from(document.querySelectorAll('#commonIssuesBody .common-select'));
    var checked = checkboxes.filter(function (checkbox) { return checkbox.checked; });

    if (markBtn) {
        markBtn.disabled = checked.length === 0;
    }

    if (selectAll) {
        selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
    }
}

function escapeAttr(text) {
    return String(text == null ? '' : text)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function normalizeIssueImageSrc(src) {
    var rawSrc = String(src || '').trim();
    if (!rawSrc) return rawSrc;
    try {
        var parsed = new URL(rawSrc, window.location.origin);
        var pathname = parsed.pathname || '';
        var prefixMatch = pathname.match(/^\/(?:(PMS(?:-UAT)?)\/)?((?:uploads\/|assets\/uploads\/|api\/public_image\.php|api\/secure_file\.php).*)$/i);
        if (!prefixMatch) return rawSrc;
        var normalizedBaseDir = String(baseDir || '').replace(/\/+$/, '');
        var normalizedTarget = '/' + String(prefixMatch[2] || '').replace(/^\/+/, '');
        if (/^\/(?:assets\/uploads|uploads)\/(issues|chat)\//i.test(normalizedTarget)) {
            var relativePath = normalizedTarget.replace(/^\//, '');
            return (normalizedBaseDir ? normalizedBaseDir : '') + '/api/secure_file.php?path=' + encodeURIComponent(relativePath) + (parsed.hash || '');
        }
        var normalizedPath = (normalizedBaseDir ? normalizedBaseDir : '') + normalizedTarget;
        if (!normalizedPath) normalizedPath = normalizedTarget;
        if (normalizedPath.charAt(0) !== '/') normalizedPath = '/' + normalizedPath;
        if (/^(?:https?:)?\/\//i.test(rawSrc)) {
            parsed.pathname = normalizedPath;
            return parsed.toString();
        }
        return normalizedPath + (parsed.search || '') + (parsed.hash || '');
    } catch (e) { return rawSrc; }
}

function decorateIssueImages(html) {
    if (!html) return '';
    return String(html).replace(/<img\b([^>]*)>/gi, function (_, attrs) {
        var newAttrs = attrs;
        newAttrs = newAttrs.replace(/\bsrc\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i, function (match, dq, sq, bare) {
            var currentSrc = dq || sq || bare || '';
            return 'src="' + escapeAttr(normalizeIssueImageSrc(currentSrc)) + '"';
        });
        if (/class\s*=/.test(attrs)) {
            newAttrs = attrs.replace(/class\s*=(["\'])([^"\']*)\1/, 'class="$2 issue-image-thumb"');
        } else {
            newAttrs = 'class="issue-image-thumb" ' + attrs;
        }
        if (!/loading\s*=/.test(newAttrs)) newAttrs += ' loading="lazy"';
        if (!/style\s*=/.test(newAttrs)) newAttrs += ' style="max-width: 100%; height: auto; cursor: pointer;"';
        return '<img ' + newAttrs + '>';
    });
}

function loadIssues(options) {
    var opts = options || {};
    var preserveFilters = !!opts.preserveFilters;
    var silentErrors    = !!opts.silentErrors;
    var immediate       = !!opts.immediate;

    if (loadIssuesDebounceTimer) { clearTimeout(loadIssuesDebounceTimer); loadIssuesDebounceTimer = null; }
    if (immediate) return performLoadIssues(preserveFilters, silentErrors);

    return new Promise(function (resolve) {
        loadIssuesDebounceTimer = setTimeout(function () {
            performLoadIssues(preserveFilters, silentErrors).then(resolve);
        }, 300);
    });
}

function performLoadIssues(preserveFilters, silentErrors, retryCount) {
    retryCount = retryCount || 0;
    var maxRetries = 3;
    var controller = new AbortController();
    var timeoutId  = setTimeout(function () { controller.abort(); }, 10000);

    return fetch(baseDir + '/api/issues.php?action=common_get_all&project_id=' + projectId, {
        signal: controller.signal,
        headers: { 'Cache-Control': 'no-cache' }
    })
    .then(function (response) {
        clearTimeout(timeoutId);
        if (!response.ok) throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        return response.text().then(function(text) {
            return JSON.parse(text.replace(/^\uFEFF/, ''));
        });
    })
    .then(function (data) {
        if (data.success) {
            allIssues = (data.issues || []).sort(function(a, b) {
                var ka = String(a.issue_key || '');
                var kb = String(b.issue_key || '');
                var ma = ka.match(/^(.*?)(\d+)$/);
                var mb = kb.match(/^(.*?)(\d+)$/);
                if (ma && mb) {
                    if (ma[1] !== mb[1]) return ma[1].localeCompare(mb[1]);
                    return parseInt(ma[2], 10) - parseInt(mb[2], 10);
                }
                return ka.localeCompare(kb);
            });
            if (preserveFilters) { applyFilters(); } else { filteredIssues = allIssues; updateCounts(); renderIssues(); }
        } else {
            throw new Error(data.message || 'Failed to load issues');
        }
    })
    .catch(function (error) {
        clearTimeout(timeoutId);
        if (retryCount < maxRetries && (error.name === 'AbortError' || error.message.includes('Failed to fetch'))) {
            return new Promise(function (resolve) {
                setTimeout(function () { performLoadIssues(preserveFilters, true, retryCount + 1).then(resolve); }, Math.pow(2, retryCount) * 1000);
            });
        }
        if (!silentErrors && window.showError) window.showError('Failed to load issues: ' + error.message);
        throw error;
    });
}

function renderIssues() {
    var tbody    = document.getElementById('commonIssuesBody');
    var userRole = window.ProjectConfig ? window.ProjectConfig.userRole : '';
    var isClient = (userRole === 'client');
    var colspan  = isClient ? 3 : 5;

    if (!tbody) return;

    if (filteredIssues.length === 0) {
        tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center py-5"><i class="fas fa-inbox fa-3x text-muted mb-3"></i><p class="text-muted">No common issues found</p></td></tr>';
        renderPagination();
        return;
    }

    var pagedIssues = getPagedIssues();

    tbody.innerHTML = pagedIssues.map(function (issue) {
        var uniqueId = 'common-issue-details-' + issue.id;
        var mainRow = '<tr class="align-middle issue-expandable-row" data-collapse-target="#' + uniqueId + '" style="cursor: pointer;">';

        if (!isClient) {
            mainRow += '<td class="text-center checkbox-cell"><input type="checkbox" class="form-check-input common-select" data-id="' + issue.id + '"></td>';
        }

        // Issue Key
        mainRow += '<td><span class="badge bg-primary">' + escapeHtml(issue.issue_key) + '</span></td>';

        // Title + Description
        var descriptionPreview = issue.description ? (stripHtml(issue.description).slice(0, 80) + (issue.description.length > 80 ? '...' : '')) : '';
        mainRow += '<td style="min-width: 250px;">' +
            '<div class="d-flex align-items-center">' +
            '<button class="btn btn-link p-0 me-2 text-muted chevron-toggle-btn" data-collapse-target="#' + uniqueId + '" style="border: none; background: none; font-size: 1rem;">' +
            '<i class="fas fa-chevron-right chevron-icon" id="chevron-' + issue.id + '"></i>' +
            '</button>' +
            '<div>' +
            '<div class="fw-bold text-dark text-truncate-cell" title="' + escapeAttr(issue.title) + '">' + escapeHtml(issue.title) + '</div>' +
            (descriptionPreview ? '<div class="small text-muted text-truncate-cell" title="' + escapeAttr(stripHtml(issue.description)) + '">' + escapeHtml(descriptionPreview) + '</div>' : '') +
            '</div>' +
            '</div>' +
            '</td>';

        // Pages
        var pageCount = (issue.page_ids || []).length;
        mainRow += '<td class="small text-muted"><span class="badge bg-secondary">' + pageCount + ' page(s)</span></td>';

        if (!isClient) {
            mainRow += '<td class="text-end action-buttons-cell">' +
                '<div class="btn-group">' +
                '<button class="btn btn-sm btn-outline-primary common-edit bg-white" data-id="' + issue.id + '" title="Edit Common Issue"><i class="fas fa-pencil-alt"></i></button>' +
                '<button class="btn btn-sm btn-outline-danger common-delete bg-white" data-id="' + issue.id + '" title="Delete Common Issue"><i class="fas fa-trash"></i></button>' +
                '</div>' +
                '</td>';
        }

        mainRow += '</tr>';

        // Details Row (Expanded)
        var detailsRow = '<tr id="' + uniqueId + '" style="display:none;"><td colspan="' + colspan + '" class="p-0">' +
            '<div class="bg-light p-4 border-top"><div class="row">' +
            '<div class="col-md-8"><h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Issue Details</h6>' +
            '<div class="card"><div class="card-body issue-content">' + (decorateIssueImages(issue.description) || '<p class="text-muted">No details provided.</p>') + '</div></div></div>' +
            '<div class="col-md-4"><h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Metadata</h6><div class="card"><div class="card-body">' +
            '<div class="mb-2"><strong>Issue Key:</strong><br><span class="badge bg-primary">' + escapeHtml(issue.issue_key) + '</span></div>' +
            '<div class="mb-2"><strong>Status:</strong><br><span class="badge" style="background-color:' + (issue.status_color || '#6c757d') + ';color:white;">' + escapeHtml(issue.status_name || 'Open') + '</span></div>' +
            '<div class="mb-2"><strong>Severity:</strong><br><span class="badge bg-warning text-dark">' + escapeHtml((issue.severity || 'N/A').toUpperCase()) + '</span></div>' +
            '<div class="mb-2"><strong>Priority:</strong><br><span class="badge bg-info text-dark">' + escapeHtml((issue.priority || 'N/A').toUpperCase()) + '</span></div>';

        if (!isClient) {
            var qaMetaHtml = (issue.qa_statuses && issue.qa_statuses.length > 0)
                ? issue.qa_statuses.map(function (qs) { return '<span class="badge me-1" style="background-color:' + (qs.color || '#6c757d') + ';color:white;">' + escapeHtml(qs.label) + '</span>'; }).join('')
                : '<span class="text-muted">N/A</span>';
            detailsRow += '<div class="mb-2"><strong>QA Status:</strong><br>' + qaMetaHtml + '</div>' +
                '<div class="mb-2"><strong>Reporter(s):</strong><br>' + (issue.reporters ? escapeHtml(issue.reporters) : '<span class="text-muted">N/A</span>') + '</div>';
        }

        if (issue.pages) {
            var pagesList = issue.pages.split(', ');
            detailsRow += '<div class="mb-2"><strong>Page(s):</strong> <span class="badge bg-secondary ms-1">' + pagesList.length + '</span>' +
                '<div class="mt-1 border rounded bg-white p-2" style="max-height:120px;overflow-y:auto;">' +
                '<ul class="list-unstyled mb-0 small">' +
                pagesList.map(function(p) { return '<li><i class="fas fa-file-alt text-muted me-1"></i>' + escapeHtml(p.trim()) + '</li>'; }).join('') +
                '</ul></div></div>';
        }

        if (window.ProjectConfig && window.issueMetadataFields) {
            window.issueMetadataFields.forEach(function (field) {
                if (field.field_key === 'severity' || field.field_key === 'priority') return;
                var value = (issue.metadata && issue.metadata[field.field_key]) ? issue.metadata[field.field_key] : issue[field.field_key];
                if (value && value.length > 0) {
                    var displayValue = Array.isArray(value) ? value.join(', ') : value;
                    detailsRow += '<div class="mb-2"><strong>' + escapeHtml(field.field_label) + ':</strong><br>' + escapeHtml(displayValue) + '</div>';
                }
            });
        }
        
        detailsRow += '</div></div></div></div></div></td></tr>';
        return mainRow + detailsRow;
    }).join('');

    attachEventListeners();
    updateCommonSelectionState();
    renderPagination();
    document.dispatchEvent(new CustomEvent('pms:issueTableUpdated'));
}

function updateCounts() {}

function applyFilters() {
    var searchTerm    = document.getElementById('searchInput').value.toLowerCase();
    var statusFilter  = ($('#filterStatus').val() || []);
    var qaStatusFilterEl  = document.getElementById('filterQAStatus');
    var qaStatusFilter    = qaStatusFilterEl ? ($(qaStatusFilterEl).val() || []) : [];
    var reporterFilterEl  = document.getElementById('filterReporter');
    var reporterFilter    = reporterFilterEl ? ($(reporterFilterEl).val() || []) : [];
    
    filteredIssues = allIssues.filter(function (issue) {
        if (searchTerm) {
            var stripHtml = function (h) { return h ? String(h).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : ''; };
            var qaLabels  = Array.isArray(issue.qa_statuses) ? issue.qa_statuses.map(function (qs) { return String(qs.label || ''); }).join(' ') : String(issue.qa_statuses || '');
            var text = [issue.issue_key, issue.title, issue.pages, issue.status_name, qaLabels, issue.reporters, stripHtml(issue.description)].filter(Boolean).join(' ').toLowerCase();
            if (!text.includes(searchTerm)) return false;
        }
        if (statusFilter.length > 0 && !statusFilter.includes('')) {
            if (!statusFilter.includes(String(issue.status_id))) return false;
        }
        if (qaStatusFilter.length > 0 && !qaStatusFilter.includes('')) {
            if (!issue.qa_status_keys || !qaStatusFilter.some(function (qas) { return issue.qa_status_keys.includes(qas); })) return false;
        }
        if (reporterFilter.length > 0 && !reporterFilter.includes('')) {
            if (!issue.reporter_ids || !reporterFilter.some(function (rid) { return issue.reporter_ids.includes(parseInt(rid)); })) return false;
        }
        return true;
    });
    currentPage = 1;
    renderIssues();
}

function attachEventListeners() {
    document.querySelectorAll('.common-select').forEach(function (checkbox) {
        checkbox.addEventListener('click', function (e) { e.stopPropagation(); });
        checkbox.addEventListener('change', updateCommonSelectionState);
    });
    document.querySelectorAll('.common-edit').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); editCommonIssue(this.dataset.id); });
    });
    document.querySelectorAll('.common-delete').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); deleteCommonIssue(this.dataset.id); });
    });
    document.querySelectorAll('.issue-expandable-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('button') || e.target.closest('input')) return;
            var targetId = this.getAttribute('data-collapse-target');
            var detailsRow = document.querySelector(targetId);
            var chevron = this.querySelector('.chevron-icon');
            if (!detailsRow) return;
            if (detailsRow.style.display === 'none') {
                detailsRow.style.display = 'table-row';
                if (chevron) { chevron.classList.remove('fa-chevron-right'); chevron.classList.add('fa-chevron-down'); }
            } else {
                detailsRow.style.display = 'none';
                if (chevron) { chevron.classList.remove('fa-chevron-down'); chevron.classList.add('fa-chevron-right'); }
            }
        });
    });
}

function editCommonIssue(id) {
    var issueData = allIssues.find(function (i) { return i.id == id; });
    if (!issueData) return;
    
    // Normalize for openFinalEditor
    var issue = {
        id: issueData.id,
        issue_key: issueData.issue_key,
        title: issueData.title,
        details: issueData.description,
        common_title: issueData.common_title || '',
        status_id: issueData.status_id,
        status: issueData.status_name,
        pages: issueData.page_ids || [],
        reporters: issueData.reporter_ids || [],
        qa_status: issueData.qa_status_keys || [],
        severity: issueData.severity || 'medium',
        priority: issueData.priority || 'medium',
        metadata: issueData.metadata || {}
    };

    if (typeof openFinalEditor === 'function') openFinalEditor(issue);
}

function deleteCommonIssue(id) {
    if (!confirm('Are you sure you want to delete this common issue?')) return;
    var fd = new FormData();
    fd.append('action', 'delete'); fd.append('ids', String(id)); fd.append('project_id', projectId);
    fetch(baseDir + '/api/issues.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) loadIssues({ preserveFilters: true });
        });
}

function stripHtml(html) { var d = document.createElement('div'); d.innerHTML = html; return d.textContent || d.innerText || ''; }
function escapeHtml(text) { var d = document.createElement('div'); d.textContent = text; return d.innerHTML; }

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Select2 dropdowns
    $('#filterStatus').select2({ placeholder: 'All Statuses', allowClear: true, width: '100%' });
    $('#filterQAStatus').select2({ placeholder: 'All QA Statuses', allowClear: true, width: '100%' });
    $('#filterReporter').select2({ placeholder: 'All Reporters', allowClear: true, width: '100%' });

    var searchInput = document.getElementById('searchInput');
    if (searchInput) searchInput.addEventListener('input', applyFilters);
    
    var filterStatus = document.getElementById('filterStatus');
    if (filterStatus) $(filterStatus).on('change', applyFilters);
    
    var filterQAStatus = document.getElementById('filterQAStatus');
    if (filterQAStatus) $(filterQAStatus).on('change', applyFilters);

    var filterReporter = document.getElementById('filterReporter');
    if (filterReporter) $(filterReporter).on('change', applyFilters);
    
    var clearFilters = document.getElementById('clearFilters');
    if (clearFilters) clearFilters.addEventListener('click', function() {
        if (searchInput) searchInput.value = '';
        if (filterStatus) $(filterStatus).val([]).trigger('change');
        if (filterQAStatus) $(filterQAStatus).val([]).trigger('change');
        if (filterReporter) $(filterReporter).val([]).trigger('change');
        applyFilters();
    });
    
    var refreshBtn = document.getElementById('commonIssuesRefreshBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', function() { loadIssues({ preserveFilters: true }); });
    
    var commonSelectAll = document.getElementById('commonSelectAll');
    if (commonSelectAll) {
        commonSelectAll.addEventListener('click', function(e) { e.stopPropagation(); });
        commonSelectAll.addEventListener('change', function() {
            var isChecked = !!this.checked;
            document.querySelectorAll('#commonIssuesBody .common-select').forEach(function(c) { c.checked = isChecked; });
            updateCommonSelectionState();
        });
    }

    var perPageSelect = document.getElementById('perPageSelect');
    if (perPageSelect) perPageSelect.addEventListener('change', function() {
        perPage = parseInt(this.value) || 50;
        currentPage = 1;
        renderIssues();
    });

    var addIssueBtn = document.getElementById('addIssueBtn');
    if (addIssueBtn) {
        addIssueBtn.addEventListener('click', function() {
            if (typeof openFinalEditor === 'function') {
                // Initialize with project-level defaults
                var newIssue = {
                    id: '',
                    project_id: projectId,
                    pages: [],
                    status: 'open',
                    severity: 'medium',
                    priority: 'medium',
                    is_new: true
                };
                openFinalEditor(newIssue);
            }
        });
    }

    var markClientReadyBtn = document.getElementById('allIssuesMarkClientReadyBtn');
    if (markClientReadyBtn) {
        markClientReadyBtn.addEventListener('click', function() {
            var selectedIds = getCommonSelectedIds();
            if (!selectedIds.length) return;

            var proceed = function() {
                fetch(baseDir + '/api/issues.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=bulk_client_ready&issue_ids=' + encodeURIComponent(selectedIds.join(',')) + '&client_ready=1&project_id=' + encodeURIComponent(projectId)
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        if (window.showSuccess) window.showSuccess(selectedIds.length + ' issues marked client ready');
                        loadIssues({ preserveFilters: true });
                    }
                });
            };

            if (confirm('Mark ' + selectedIds.length + ' issues as Client Ready?')) {
                proceed();
            }
        });
    }

    loadIssues({ immediate: true });
});
