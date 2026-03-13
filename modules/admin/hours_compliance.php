<?php
require_once '../../includes/auth.php';
requireAdmin();

$page_title = 'Hours Compliance Report';
include '../../includes/header.php';
?>
<style>
#nonCompliantTable_wrapper .dataTables_length select,
#compliantTable_wrapper .dataTables_length select {
    min-width: 86px;
    padding-right: 2rem !important;
    background-position: right 0.6rem center;
    text-overflow: clip;
}

.expand-btn {
    cursor: pointer;
    transition: transform 0.2s;
}

.expand-btn.expanded {
    transform: rotate(90deg);
}

.details-row {
    background-color: #f8f9fa;
}

.details-content {
    padding: 15px;
}

.time-log-entry {
    border-left: 3px solid #007bff;
    padding: 10px;
    margin-bottom: 10px;
    background: white;
    border-radius: 4px;
}

.time-log-entry:last-child {
    margin-bottom: 0;
}

.time-log-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
}

.time-log-hours {
    font-weight: bold;
    color: #007bff;
}

.time-log-project {
    font-weight: 500;
    color: #333;
}

.time-log-details {
    font-size: 0.9em;
    color: #666;
}
</style>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <h2><i class="fas fa-clock"></i> Daily Hours Compliance Report</h2>
            <p class="text-muted">Track which users have not met the minimum daily hours requirement</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" onclick="showSettingsModal()">
                <i class="fas fa-cog"></i> Settings
            </button>
        </div>
    </div>

    <!-- Date Selector -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <label class="form-label">Select Date</label>
                    <input type="date" class="form-control" id="reportDate" value="<?php echo date('Y-m-d', strtotime('-1 day')); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-primary d-block w-100" onclick="loadReport()">
                        <i class="fas fa-search"></i> Load Report
                    </button>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle"></i> <strong>Minimum Hours:</strong> <span id="minHoursDisplay">8</span> hours per day
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Users</h5>
                    <h2 id="totalUsers">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Compliant</h5>
                    <h2 id="compliantUsers">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Non-Compliant</h5>
                    <h2 id="nonCompliantUsers">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Compliance Rate</h5>
                    <h2 id="complianceRate">0%</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Non-Compliant Users -->
    <div class="card mb-4">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Non-Compliant Users</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="nonCompliantTable">
                    <thead>
                        <tr>
                            <th style="width: 30px;"></th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Hours Logged</th>
                            <th>Hours Short</th>
                            <th>Reminder Sent</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Compliant Users -->
    <div class="card">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="fas fa-check-circle"></i> Compliant Users</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="compliantTable">
                    <thead>
                        <tr>
                            <th style="width: 30px;"></th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Hours Logged</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Hours Reminder Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="settingsForm">
                    <div class="mb-3">
                        <label class="form-label">Reminder Time</label>
                        <input type="time" class="form-control" id="reminderTime" name="reminder_time" required>
                        <small class="text-muted">Time when daily reminder will be sent to users</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Minimum Hours Required</label>
                        <input type="number" step="0.01" class="form-control" id="minimumHours" name="minimum_hours" required>
                        <small class="text-muted">Minimum hours users must log per day</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notification Message</label>
                        <textarea class="form-control" id="notificationMessage" name="notification_message" rows="3" required></textarea>
                        <small class="text-muted">Message shown to users in reminder notification</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enabled" name="enabled">
                            <label class="form-check-label" for="enabled">
                                Enable Daily Reminders
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveSettings()">Save Settings</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentReport = null;
let settings = null;

$(document).ready(function() {
    loadSettings();
    loadReport();
});

function initComplianceTables() {
    if (!$.fn.DataTable) return;

    const commonOptions = {
        pageLength: 10,
        lengthMenu: [[10, 25, 50], [10, 25, 50]],
        paging: true,
        searching: true,
        info: true,
        autoWidth: false,
        language: {
            search: 'Filter:',
            lengthMenu: 'Show _MENU_ entries',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries'
        }
    };

    if ($.fn.DataTable.isDataTable('#nonCompliantTable')) {
        $('#nonCompliantTable').DataTable().destroy();
    }
    if ($.fn.DataTable.isDataTable('#compliantTable')) {
        $('#compliantTable').DataTable().destroy();
    }

    $('#nonCompliantTable').DataTable($.extend(true, {}, commonOptions, {
        order: [[1, 'asc']],
        columnDefs: [
            { targets: [0], orderable: false, searchable: false },
            { targets: [7], orderable: false, searchable: false }
        ]
    }));
    $('#compliantTable').DataTable($.extend(true, {}, commonOptions, {
        order: [[1, 'asc']],
        columnDefs: [
            { targets: [0], orderable: false, searchable: false }
        ]
    }));
}

function loadSettings() {
    $.get('../../api/hours_reminder.php?action=get_settings', function(response) {
        if (response.success) {
            settings = response.settings;
            $('#minHoursDisplay').text(settings.minimum_hours);
        }
    });
}

function loadReport() {
    const date = $('#reportDate').val();
    
    $.get('../../api/hours_reminder.php', {
        action: 'get_compliance_report',
        date: date
    }, function(response) {
        if (response.success) {
            currentReport = response;
            renderReport();
        } else {
            alert('Error: ' + response.message);
        }
    });
}

function renderReport() {
    // Update summary
    $('#totalUsers').text(currentReport.summary.total_users);
    $('#compliantUsers').text(currentReport.summary.compliant_count);
    $('#nonCompliantUsers').text(currentReport.summary.non_compliant_count);
    $('#complianceRate').text(currentReport.summary.compliance_rate + '%');
    $('#minHoursDisplay').text(currentReport.minimum_hours);
    
    // Render non-compliant users
    const nonCompliantTbody = $('#nonCompliantTable tbody');
    nonCompliantTbody.empty();
    
    if (currentReport.non_compliant.length === 0) {
        nonCompliantTbody.html('<tr><td colspan="8" class="text-center text-success">All users are compliant! 🎉</td></tr>');
    } else {
        currentReport.non_compliant.forEach(user => {
            const hoursShort = (currentReport.minimum_hours - user.total_hours).toFixed(2);
            const reminderStatus = user.reminder_sent ? 
                `<span class="badge bg-success">Sent at ${new Date(user.reminder_sent_at).toLocaleTimeString()}</span>` : 
                '<span class="badge bg-secondary">Not Sent</span>';
            
            const rowId = `nc-row-${user.id}`;
            nonCompliantTbody.append(`
                <tr id="${rowId}" data-user-id="${user.id}">
                    <td>
                        <i class="fas fa-chevron-right expand-btn" onclick="toggleUserDetails(${user.id}, 'nc')"></i>
                    </td>
                    <td><strong>${user.full_name || user.username}</strong></td>
                    <td><span class="badge bg-secondary">${user.role}</span></td>
                    <td>${user.email}</td>
                    <td><span class="badge bg-warning">${user.total_hours} hrs</span></td>
                    <td><span class="badge bg-danger">-${hoursShort} hrs</span></td>
                    <td>${reminderStatus}</td>
                    <td>
                        <a href="mailto:${user.email}" class="btn btn-sm btn-primary" title="Send Email">
                            <i class="fas fa-envelope"></i>
                        </a>
                    </td>
                </tr>
            `);
        });
    }
    
    // Render compliant users
    const compliantTbody = $('#compliantTable tbody');
    compliantTbody.empty();
    
    if (currentReport.compliant.length === 0) {
        compliantTbody.html('<tr><td colspan="6" class="text-center text-muted">No compliant users found</td></tr>');
    } else {
        currentReport.compliant.forEach(user => {
            const rowId = `c-row-${user.id}`;
            compliantTbody.append(`
                <tr id="${rowId}" data-user-id="${user.id}">
                    <td>
                        <i class="fas fa-chevron-right expand-btn" onclick="toggleUserDetails(${user.id}, 'c')"></i>
                    </td>
                    <td><strong>${user.full_name || user.username}</strong></td>
                    <td><span class="badge bg-secondary">${user.role}</span></td>
                    <td>${user.email}</td>
                    <td><span class="badge bg-success">${user.total_hours} hrs</span></td>
                    <td><span class="badge bg-success"><i class="fas fa-check"></i> Compliant</span></td>
                </tr>
            `);
        });
    }

    initComplianceTables();
}

function showSettingsModal() {
    $.get('../../api/hours_reminder.php?action=get_settings', function(response) {
        if (response.success) {
            const s = response.settings;
            $('#reminderTime').val(s.reminder_time);
            $('#minimumHours').val(s.minimum_hours);
            $('#notificationMessage').val(s.notification_message);
            $('#enabled').prop('checked', s.enabled == 1);
            $('#settingsModal').modal('show');
        }
    });
}

function saveSettings() {
    const formData = new FormData($('#settingsForm')[0]);
    formData.append('action', 'update_settings');
    
    $.post('../../api/hours_reminder.php', formData, function(response) {
        if (response.success) {
            alert('Settings updated successfully');
            $('#settingsModal').modal('hide');
            loadSettings();
            loadReport();
        } else {
            alert('Error: ' + response.message);
        }
    });
}

function toggleUserDetails(userId, tablePrefix) {
    const rowId = `${tablePrefix}-row-${userId}`;
    const detailsRowId = `${tablePrefix}-details-${userId}`;
    const expandBtn = $(`#${rowId} .expand-btn`);
    
    // Check if details row already exists
    const existingDetailsRow = $(`#${detailsRowId}`);
    
    if (existingDetailsRow.length > 0) {
        // Collapse
        existingDetailsRow.remove();
        expandBtn.removeClass('expanded');
    } else {
        // Expand - fetch and show details
        expandBtn.addClass('expanded');
        
        const date = $('#reportDate').val();
        const colCount = tablePrefix === 'nc' ? 8 : 6;
        
        // Insert loading row
        $(`#${rowId}`).after(`
            <tr id="${detailsRowId}" class="details-row">
                <td colspan="${colCount}">
                    <div class="details-content">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin"></i> Loading time logs...
                        </div>
                    </div>
                </td>
            </tr>
        `);
        
        // Fetch time logs
        $.get('../../api/hours_reminder.php', {
            action: 'get_user_time_logs',
            user_id: userId,
            date: date
        }, function(response) {
            if (response.success) {
                renderTimeLogDetails(detailsRowId, response.logs, response.total_hours);
            } else {
                console.error('API Error:', response);
                $(`#${detailsRowId} .details-content`).html(`
                    <div class="alert alert-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i> Error: ${response.message || 'Unknown error'}
                    </div>
                `);
            }
        }).fail(function(xhr, status, error) {
            console.error('Request failed:', xhr.responseText);
            let errorMsg = 'Failed to load time logs';
            try {
                const response = JSON.parse(xhr.responseText);
                errorMsg = response.message || errorMsg;
            } catch(e) {}
            
            $(`#${detailsRowId} .details-content`).html(`
                <div class="alert alert-danger mb-0">
                    <i class="fas fa-exclamation-triangle"></i> ${errorMsg}
                </div>
            `);
        });
    }
}

function renderTimeLogDetails(detailsRowId, logs, totalHours) {
    const detailsContent = $(`#${detailsRowId} .details-content`);
    
    if (logs.length === 0) {
        detailsContent.html(`
            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle"></i> No time logs found for this date
            </div>
        `);
        return;
    }
    
    let html = `<div class="mb-2"><strong>Total Hours: ${totalHours}</strong></div>`;
    
    logs.forEach(log => {
        let taskInfo = '';
        
        if (log.task_type === 'page_testing' || log.task_type === 'page_qa' || log.task_type === 'regression') {
            taskInfo = `
                <div class="time-log-details">
                    <span class="badge bg-info">${log.task_type.replace('_', ' ').toUpperCase()}</span>
                    ${log.page_name ? `<strong>Page:</strong> ${log.page_number ? log.page_number + ' - ' : ''}${log.page_name}` : ''}
                    ${log.env_name ? ` | <strong>Environment:</strong> ${log.env_name}` : ''}
                </div>
            `;
        } else if (log.task_type === 'project_phase') {
            taskInfo = `
                <div class="time-log-details">
                    <span class="badge bg-info">PROJECT PHASE</span>
                    ${log.phase_name ? `<strong>Phase:</strong> ${log.phase_name}` : ''}
                </div>
            `;
        } else if (log.task_type === 'generic_task') {
            taskInfo = `
                <div class="time-log-details">
                    <span class="badge bg-info">GENERIC TASK</span>
                    ${log.task_category ? `<strong>Category:</strong> ${log.task_category}` : ''}
                </div>
            `;
        }
        
        html += `
            <div class="time-log-entry">
                <div class="time-log-header">
                    <span class="time-log-project">${log.project_name || 'No Project'}</span>
                    <span class="time-log-hours">${log.hours_spent} hrs</span>
                </div>
                ${taskInfo}
                ${log.description ? `<div class="time-log-details mt-2"><strong>Description:</strong> ${log.description}</div>` : ''}
                <div class="time-log-details mt-1">
                    <small class="text-muted"><i class="fas fa-clock"></i> Logged at ${new Date(log.created_at).toLocaleString()}</small>
                </div>
            </div>
        `;
    });
    
    detailsContent.html(html);
}

// Auto-refresh every 5 minutes
setInterval(loadReport, 300000);
</script>

<?php include '../../includes/footer.php'; ?>
