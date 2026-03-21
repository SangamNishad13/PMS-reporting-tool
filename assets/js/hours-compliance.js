/**
 * hours-compliance.js - Hours compliance report page
 */
(function () {
    var currentReport = null;
    var settings = null;
    var apiUrl = (window.HoursComplianceConfig || {}).apiUrl || '../../api/hours_reminder.php';

    $(document).ready(function () {
        loadSettings();
        loadReport();
    });

    function initComplianceTables() {
        if (!$.fn.DataTable) return;
        var commonOptions = {
            pageLength: 10, lengthMenu: [[10, 25, 50], [10, 25, 50]],
            paging: true, searching: true, info: true, autoWidth: false,
            language: { search: 'Filter:', lengthMenu: 'Show _MENU_ entries', info: 'Showing _START_ to _END_ of _TOTAL_ entries' }
        };
        if ($.fn.DataTable.isDataTable('#nonCompliantTable')) $('#nonCompliantTable').DataTable().destroy();
        if ($.fn.DataTable.isDataTable('#compliantTable')) $('#compliantTable').DataTable().destroy();
        $('#nonCompliantTable').DataTable($.extend(true, {}, commonOptions, { order: [[1, 'asc']], columnDefs: [{ targets: [0], orderable: false, searchable: false }, { targets: [7], orderable: false, searchable: false }] }));
        $('#compliantTable').DataTable($.extend(true, {}, commonOptions, { order: [[1, 'asc']], columnDefs: [{ targets: [0], orderable: false, searchable: false }] }));
    }

    function loadSettings() {
        $.get(apiUrl + '?action=get_settings', function (response) {
            if (response.success) { settings = response.settings; $('#minHoursDisplay').text(settings.minimum_hours); }
        });
    }

    function loadReport() {
        var date = $('#reportDate').val();
        $.get(apiUrl, { action: 'get_compliance_report', date: date }, function (response) {
            if (response.success) { currentReport = response; renderReport(); }
            else showToast('Error: ' + response.message, 'danger');
        });
    }

    function renderReport() {
        $('#totalUsers').text(currentReport.summary.total_users);
        $('#compliantUsers').text(currentReport.summary.compliant_count);
        $('#nonCompliantUsers').text(currentReport.summary.non_compliant_count);
        $('#complianceRate').text(currentReport.summary.compliance_rate + '%');
        $('#minHoursDisplay').text(currentReport.minimum_hours);

        var nonCompliantTbody = $('#nonCompliantTable tbody');
        nonCompliantTbody.empty();
        if (currentReport.non_compliant.length === 0) {
            nonCompliantTbody.html('<tr><td colspan="8" class="text-center text-success">All users are compliant!</td></tr>');
        } else {
            currentReport.non_compliant.forEach(function (user) {
                var hoursShort = (currentReport.minimum_hours - user.total_hours).toFixed(2);
                var reminderStatus = user.reminder_sent ?
                    '<span class="badge bg-success">Sent at ' + new Date(user.reminder_sent_at).toLocaleTimeString() + '</span>' :
                    '<span class="badge bg-secondary">Not Sent</span>';
                nonCompliantTbody.append(
                    '<tr id="nc-row-' + user.id + '" data-user-id="' + user.id + '">' +
                    '<td><i class="fas fa-chevron-right expand-btn" onclick="toggleUserDetails(' + user.id + ', \'nc\')"></i></td>' +
                    '<td><strong>' + escapeHtml(user.full_name || user.username) + '</strong></td>' +
                    '<td><span class="badge bg-secondary">' + escapeHtml(user.role) + '</span></td>' +
                    '<td>' + escapeHtml(user.email) + '</td>' +
                    '<td><span class="badge bg-warning">' + user.total_hours + ' hrs</span></td>' +
                    '<td><span class="badge bg-danger">-' + hoursShort + ' hrs</span></td>' +
                    '<td>' + reminderStatus + '</td>' +
                    '<td><a href="mailto:' + escapeHtml(user.email) + '" class="btn btn-sm btn-primary" title="Send Email"><i class="fas fa-envelope"></i></a></td>' +
                    '</tr>'
                );
            });
        }

        var compliantTbody = $('#compliantTable tbody');
        compliantTbody.empty();
        if (currentReport.compliant.length === 0) {
            compliantTbody.html('<tr><td colspan="6" class="text-center text-muted">No compliant users found</td></tr>');
        } else {
            currentReport.compliant.forEach(function (user) {
                compliantTbody.append(
                    '<tr id="c-row-' + user.id + '" data-user-id="' + user.id + '">' +
                    '<td><i class="fas fa-chevron-right expand-btn" onclick="toggleUserDetails(' + user.id + ', \'c\')"></i></td>' +
                    '<td><strong>' + escapeHtml(user.full_name || user.username) + '</strong></td>' +
                    '<td><span class="badge bg-secondary">' + escapeHtml(user.role) + '</span></td>' +
                    '<td>' + escapeHtml(user.email) + '</td>' +
                    '<td><span class="badge bg-success">' + user.total_hours + ' hrs</span></td>' +
                    '<td><span class="badge bg-success"><i class="fas fa-check"></i> Compliant</span></td>' +
                    '</tr>'
                );
            });
        }
        initComplianceTables();
    }

    window.showSettingsModal = function () {
        $.get(apiUrl + '?action=get_settings', function (response) {
            if (response.success) {
                var s = response.settings;
                $('#reminderTime').val(s.reminder_time);
                $('#minimumHours').val(s.minimum_hours);
                $('#notificationMessage').val(s.notification_message);
                $('#enabled').prop('checked', s.enabled == 1);
                $('#settingsModal').modal('show');
            }
        });
    };

    window.saveSettings = function () {
        var formData = new FormData($('#settingsForm')[0]);
        formData.append('action', 'update_settings');
        $.post(apiUrl, formData, function (response) {
            if (response.success) {
                showToast('Settings updated successfully', 'success');
                $('#settingsModal').modal('hide');
                loadSettings();
                loadReport();
            } else {
                showToast('Error: ' + response.message, 'danger');
            }
        });
    };

    window.loadReport = loadReport;

    window.toggleUserDetails = function (userId, tablePrefix) {
        var rowId = tablePrefix + '-row-' + userId;
        var detailsRowId = tablePrefix + '-details-' + userId;
        var expandBtn = $('#' + rowId + ' .expand-btn');
        var existingDetailsRow = $('#' + detailsRowId);
        if (existingDetailsRow.length > 0) {
            existingDetailsRow.remove();
            expandBtn.removeClass('expanded');
        } else {
            expandBtn.addClass('expanded');
            var date = $('#reportDate').val();
            var colCount = tablePrefix === 'nc' ? 8 : 6;
            $('#' + rowId).after(
                '<tr id="' + detailsRowId + '" class="details-row"><td colspan="' + colCount + '">' +
                '<div class="details-content"><div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading time logs...</div></div></td></tr>'
            );
            $.get(apiUrl, { action: 'get_user_time_logs', user_id: userId, date: date }, function (response) {
                if (response.success) {
                    renderTimeLogDetails(detailsRowId, response.logs, response.total_hours);
                } else {
                    $('#' + detailsRowId + ' .details-content').html('<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-triangle"></i> Error: ' + escapeHtml(response.message || 'Unknown error') + '</div>');
                }
            }).fail(function (xhr) {
                var errorMsg = 'Failed to load time logs';
                try { var r = JSON.parse(xhr.responseText); errorMsg = r.message || errorMsg; } catch (e) {}
                $('#' + detailsRowId + ' .details-content').html('<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(errorMsg) + '</div>');
            });
        }
    };

    function renderTimeLogDetails(detailsRowId, logs, totalHours) {
        var detailsContent = $('#' + detailsRowId + ' .details-content');
        if (logs.length === 0) {
            detailsContent.html('<div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i> No time logs found for this date</div>');
            return;
        }
        var html = '<div class="mb-2"><strong>Total Hours: ' + totalHours + '</strong></div>';
        logs.forEach(function (log) {
            var taskInfo = '';
            if (log.task_type === 'page_testing' || log.task_type === 'page_qa' || log.task_type === 'regression') {
                taskInfo = '<div class="time-log-details"><span class="badge bg-info">' + escapeHtml(log.task_type.replace('_', ' ').toUpperCase()) + '</span>' +
                    (log.page_name ? ' <strong>Page:</strong> ' + escapeHtml((log.page_number ? log.page_number + ' - ' : '') + log.page_name) : '') +
                    (log.env_name ? ' | <strong>Environment:</strong> ' + escapeHtml(log.env_name) : '') + '</div>';
            } else if (log.task_type === 'project_phase') {
                taskInfo = '<div class="time-log-details"><span class="badge bg-info">PROJECT PHASE</span>' + (log.phase_name ? ' <strong>Phase:</strong> ' + escapeHtml(log.phase_name) : '') + '</div>';
            } else if (log.task_type === 'generic_task') {
                taskInfo = '<div class="time-log-details"><span class="badge bg-info">GENERIC TASK</span>' + (log.task_category ? ' <strong>Category:</strong> ' + escapeHtml(log.task_category) : '') + '</div>';
            }
            html += '<div class="time-log-entry">' +
                '<div class="time-log-header"><span class="time-log-project">' + escapeHtml(log.project_name || 'No Project') + '</span><span class="time-log-hours">' + log.hours_spent + ' hrs</span></div>' +
                taskInfo +
                (log.description ? '<div class="time-log-details mt-2"><strong>Description:</strong> ' + escapeHtml(log.description) + '</div>' : '') +
                '<div class="time-log-details mt-1"><small class="text-muted"><i class="fas fa-clock"></i> Logged at ' + new Date(log.created_at).toLocaleString() + '</small></div>' +
                '</div>';
        });
        detailsContent.html(html);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({ '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;' })[s];
        });
    }

    setInterval(loadReport, 300000);
})();
