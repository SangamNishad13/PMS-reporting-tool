<?php
require_once '../../includes/auth.php';
requireAdmin();

$page_title = 'Hours Compliance Report';
include '../../includes/header.php';
?>

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
                        <input type="number" step="0.5" class="form-control" id="minimumHours" name="minimum_hours" required>
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
        nonCompliantTbody.html('<tr><td colspan="7" class="text-center text-success">All users are compliant! 🎉</td></tr>');
    } else {
        currentReport.non_compliant.forEach(user => {
            const hoursShort = (currentReport.minimum_hours - user.total_hours).toFixed(2);
            const reminderStatus = user.reminder_sent ? 
                `<span class="badge bg-success">Sent at ${new Date(user.reminder_sent_at).toLocaleTimeString()}</span>` : 
                '<span class="badge bg-secondary">Not Sent</span>';
            
            nonCompliantTbody.append(`
                <tr>
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
        compliantTbody.html('<tr><td colspan="5" class="text-center text-muted">No compliant users found</td></tr>');
    } else {
        currentReport.compliant.forEach(user => {
            compliantTbody.append(`
                <tr>
                    <td><strong>${user.full_name || user.username}</strong></td>
                    <td><span class="badge bg-secondary">${user.role}</span></td>
                    <td>${user.email}</td>
                    <td><span class="badge bg-success">${user.total_hours} hrs</span></td>
                    <td><span class="badge bg-success"><i class="fas fa-check"></i> Compliant</span></td>
                </tr>
            `);
        });
    }
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

// Auto-refresh every 5 minutes
setInterval(loadReport, 300000);
</script>

<?php include '../../includes/footer.php'; ?>
