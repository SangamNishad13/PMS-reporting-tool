<?php
require_once '../../includes/auth.php';
requireDeviceManager();

$page_title = 'Device Management';
include '../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <h2><i class="fas fa-laptop"></i> Device Management</h2>
        </div>
        <div class="col-auto">
            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true)): ?>
            <a href="../admin/device_permissions.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-user-shield"></i> Device Permissions
            </a>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="showAddDeviceModal()">
                <i class="fas fa-plus"></i> Add Device
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Devices</h5>
                    <h2 id="totalDevices">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Available</h5>
                    <h2 id="availableDevices">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Assigned</h5>
                    <h2 id="assignedDevices">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Pending Requests</h5>
                    <h2 id="pendingRequests">0</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#devicesTab">Devices</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#requestsTab">Switch Requests</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#historyTab">Rotation History</a>
        </li>
    </ul>

    <div class="tab-content mt-3">
        <!-- Devices Tab -->
        <div id="devicesTab" class="tab-pane fade show active">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="devicesTable">
                            <thead>
                                <tr>
                                    <th>Device Name</th>
                                    <th>Type</th>
                                    <th>Model</th>
                                    <th>Version</th>
                                    <th>Status</th>
                                    <th>Assigned To</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Requests Tab -->
        <div id="requestsTab" class="tab-pane fade">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="requestsTable">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>Requested By</th>
                                    <th>Current Holder</th>
                                    <th>Reason</th>
                                    <th>Requested At</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rotation History Tab -->
        <div id="historyTab" class="tab-pane fade">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Device Rotation History</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="searchHistory" placeholder="Search history..." onkeyup="filterHistory()">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped" id="rotationHistoryTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Device</th>
                                    <th>From User</th>
                                    <th>To User</th>
                                    <th>Rotated By</th>
                                    <th>Reason</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Device Modal -->
<div class="modal fade" id="deviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deviceModalTitle">Add Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="deviceForm">
                    <input type="hidden" id="deviceId" name="device_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Device Name *</label>
                        <input type="text" class="form-control" id="deviceName" name="device_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Device Type *</label>
                        <select class="form-select" id="deviceType" name="device_type" required>
                            <option value="Android">Android</option>
                            <option value="iOS">iOS</option>
                            <option value="Mac">Mac</option>
                            <option value="Windows">Windows</option>
                            <option value="BT Keyboard">BT Keyboard</option>
                            <option value="Mouse">Mouse</option>
                            <option value="Tablet">Tablet</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Model</label>
                        <input type="text" class="form-control" id="model" name="model">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Version</label>
                        <input type="text" class="form-control" id="version" name="version">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Serial Number</label>
                        <input type="text" class="form-control" id="serialNumber" name="serial_number">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" class="form-control" id="purchaseDate" name="purchase_date">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="Available">Available</option>
                            <option value="Assigned">Assigned</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Retired">Retired</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="editAssignWrap">
                        <label class="form-label">Assign To</label>
                        <select class="form-select" id="editAssignUserId" name="assigned_user_id">
                            <option value="">-- Keep Current Assignment --</option>
                        </select>
                        <small class="text-muted">Edit mode में यहाँ user select करके device reassign कर सकते हैं.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveDevice()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Device Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="assignForm">
                    <input type="hidden" id="assignDeviceId" name="device_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Assign To *</label>
                        <select class="form-select" id="assignUserId" name="user_id" required></select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="assignNotes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="assignDeviceBtn">Assign</button>
            </div>
        </div>
    </div>
</div>

<script>
// Assign device button handler
$(document).on('click', '#assignDeviceBtn', function() {
    assignDevice();
});
</script>

<!-- Respond to Request Modal -->
<div class="modal fade" id="respondModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Respond to Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="respondForm">
                    <input type="hidden" id="requestId" name="request_id">
                    <input type="hidden" id="responseAction" name="response">
                    
                    <div class="mb-3">
                        <label class="form-label">Response Notes</label>
                        <textarea class="form-control" id="responseNotes" name="response_notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="respondToRequest('Rejected')">Reject</button>
                <button type="button" class="btn btn-success" onclick="respondToRequest('Approved')">Approve</button>
            </div>
        </div>
    </div>
</div>

<script>
let devices = [];
let users = [];
let requests = [];

$(document).ready(function() {
    loadUsers();
    loadDevices();
    loadRequests();
    loadRotationHistory();
});

function loadUsers() {
    $.get('../../api/devices.php?action=get_users', function(response) {
        if (response.success) {
            users = response.users;
        }
    });
}

function loadDevices() {
    $.get('../../api/devices.php?action=get_all_devices', function(response) {
        if (response.success) {
            devices = response.devices;
            renderDevices();
            updateStats();
        }
    });
}

function loadRequests() {
    $.get('../../api/devices.php?action=get_switch_requests', function(response) {
        if (response.success) {
            requests = response.requests;
            renderRequests();
            updateStats();
        }
    });
}

function loadRotationHistory() {
    $.get('../../api/admin_vault.php?action=get_device_rotation_history', function(response) {
        if (response.success) {
            renderRotationHistory(response.history);
        }
    });
}

function renderRotationHistory(history) {
    const tbody = $('#rotationHistoryTable tbody');
    tbody.empty();
    
    if (history.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No rotation history yet</td></tr>');
        return;
    }
    
    history.forEach(h => {
        tbody.append(`
            <tr>
                <td><small>${new Date(h.rotation_date).toLocaleString()}</small></td>
                <td>
                    <strong>${h.device_name}</strong><br>
                    <small class="text-muted">${h.device_type}</small>
                </td>
                <td>${h.from_user_name ? h.from_user_name : '<span class="text-muted">New Assignment</span>'}</td>
                <td><strong>${h.to_user_name}</strong></td>
                <td>${h.rotated_by_name}</td>
                <td>${h.reason || '-'}</td>
                <td>${h.notes ? `<small>${h.notes}</small>` : '-'}</td>
            </tr>
        `);
    });
}

function filterHistory() {
    const search = $('#searchHistory').val().toLowerCase();
    $('#rotationHistoryTable tbody tr').each(function() {
        const text = $(this).text().toLowerCase();
        $(this).toggle(text.includes(search));
    });
}

function renderDevices() {
    const tbody = $('#devicesTable tbody');
    tbody.empty();
    
    devices.forEach(device => {
        const statusBadge = getStatusBadge(device.status);
        const assignedTo = device.assigned_to_name || '-';
        
        tbody.append(`
            <tr>
                <td><strong>${device.device_name}</strong></td>
                <td><i class="fas fa-${getDeviceIcon(device.device_type)}"></i> ${device.device_type}</td>
                <td>${device.model || '-'}</td>
                <td>${device.version || '-'}</td>
                <td>${statusBadge}</td>
                <td>${assignedTo}</td>
                <td>
                    <button class="btn btn-sm btn-info" onclick="viewDeviceHistory(${device.id})" title="History">
                        <i class="fas fa-history"></i>
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="showEditDeviceModal(${device.id})" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    ${device.status === 'Available' ? 
                        `<button class="btn btn-sm btn-success" onclick="showAssignModal(${device.id})" title="Assign">
                            <i class="fas fa-user-plus"></i>
                        </button>` : 
                        `<button class="btn btn-sm btn-warning" onclick="returnDevice(${device.id})" title="Return">
                            <i class="fas fa-undo"></i>
                        </button>`
                    }
                    <button class="btn btn-sm btn-danger" onclick="deleteDevice(${device.id})" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `);
    });
}

function renderRequests() {
    const tbody = $('#requestsTable tbody');
    tbody.empty();
    
    if (requests.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No switch requests yet</td></tr>');
        return;
    }
    
    requests.forEach(request => {
        const statusBadge = getRequestStatusBadge(request.status);
        const isPending = request.status === 'Pending';
        const rowClass = request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '');
        
        const actions = isPending ? 
            `<button class="btn btn-sm btn-success me-1" onclick="quickApprove(${request.id})" title="Quick Approve">
                <i class="fas fa-check"></i> Approve
            </button>
            <button class="btn btn-sm btn-danger" onclick="quickReject(${request.id})" title="Quick Reject">
                <i class="fas fa-times"></i> Reject
            </button>
            <button class="btn btn-sm btn-primary mt-1" onclick="showRespondModal(${request.id})" title="Respond with Notes">
                <i class="fas fa-reply"></i> Respond
            </button>` : 
            `<small class="text-muted">Responded</small>`;
        
        const holderName = request.holder_full_name || request.holder_name || 'Office';
        tbody.append(`
            <tr class="${rowClass}">
                <td>
                    <strong>${request.device_name}</strong><br>
                    <small class="text-muted">${request.device_type}</small>
                </td>
                <td>
                    <strong>${request.requester_full_name || request.requester_name}</strong>
                    ${isPending ? '<br><span class="badge bg-warning">Waiting</span>' : ''}
                </td>
                <td>
                    <strong>${holderName}</strong>
                    ${isPending ? '<br><span class="badge bg-info">Current</span>' : ''}
                </td>
                <td><small>${request.reason || '<em class="text-muted">No reason provided</em>'}</small></td>
                <td><small>${new Date(request.requested_at).toLocaleString()}</small></td>
                <td>${statusBadge}</td>
                <td>${actions}</td>
            </tr>
        `);
    });
}

function quickApprove(requestId) {
    confirmAction('Approve this device switch request? The device will be automatically reassigned.', function() {
        $.post('../../api/devices.php', {
            action: 'respond_to_request',
            request_id: requestId,
            response: 'Approved',
            response_notes: 'Quick approved by admin'
        }, function(response) {
            if (response.success) {
                alert(response.message);
                loadRequests();
                loadDevices();
                loadRotationHistory();
            } else {
                alert('Error: ' + response.message);
            }
        });
    });
}

function quickReject(requestId) {
    const reason = prompt('Reason for rejection (optional):');
    if (reason === null) return; // User cancelled
    
    $.post('../../api/devices.php', {
        action: 'respond_to_request',
        request_id: requestId,
        response: 'Rejected',
        response_notes: reason || 'Rejected by admin'
    }, function(response) {
        if (response.success) {
            alert(response.message);
            loadRequests();
        } else {
            alert('Error: ' + response.message);
        }
    });
}

function updateStats() {
    $('#totalDevices').text(devices.length);
    $('#availableDevices').text(devices.filter(d => d.status === 'Available').length);
    $('#assignedDevices').text(devices.filter(d => d.status === 'Assigned').length);
    $('#pendingRequests').text(requests.filter(r => r.status === 'Pending').length);
}

function getStatusBadge(status) {
    const badges = {
        'Available': 'success',
        'Assigned': 'warning',
        'Maintenance': 'info',
        'Retired': 'secondary'
    };
    return `<span class="badge bg-${badges[status]}">${status}</span>`;
}

function getRequestStatusBadge(status) {
    const badges = {
        'Pending': 'warning',
        'Approved': 'success',
        'Rejected': 'danger',
        'Cancelled': 'secondary'
    };
    return `<span class="badge bg-${badges[status]}">${status}</span>`;
}

function getDeviceIcon(type) {
    const icons = {
        'Android': 'mobile-alt',
        'iOS': 'mobile-alt',
        'Mac': 'laptop',
        'Windows': 'laptop',
        'BT Keyboard': 'keyboard',
        'Mouse': 'mouse',
        'Tablet': 'tablet-alt',
        'Other': 'desktop'
    };
    return icons[type] || 'desktop';
}

function showAddDeviceModal() {
    $('#deviceModalTitle').text('Add Device');
    $('#deviceForm')[0].reset();
    $('#deviceId').val('');
    $('#editAssignWrap').addClass('d-none');
    $('#editAssignUserId').empty().append('<option value="">-- Keep Current Assignment --</option>').attr('data-current-assigned', '');
    $('#deviceModal').modal('show');
}

function populateEditAssignUsers(currentAssignedUserId) {
    const select = $('#editAssignUserId');
    select.empty();
    select.append('<option value="">-- Keep Current Assignment --</option>');

    users.forEach(user => {
        const selected = String(user.id) === String(currentAssignedUserId) ? ' selected' : '';
        select.append(`<option value="${user.id}"${selected}>${user.full_name || user.username}</option>`);
    });
    select.attr('data-current-assigned', currentAssignedUserId || '');
}

function showEditDeviceModal(deviceId) {
    const device = devices.find(d => d.id == deviceId);
    if (!device) return;
    
    $('#deviceModalTitle').text('Edit Device');
    $('#deviceId').val(device.id);
    $('#deviceName').val(device.device_name);
    $('#deviceType').val(device.device_type);
    $('#model').val(device.model);
    $('#version').val(device.version);
    $('#serialNumber').val(device.serial_number);
    $('#purchaseDate').val(device.purchase_date);
    $('#status').val(device.status);
    $('#notes').val(device.notes);
    $('#editAssignWrap').removeClass('d-none');
    populateEditAssignUsers(device.assigned_user_id || '');
    $('#deviceModal').modal('show');
}

function saveDevice() {
    const formData = new FormData($('#deviceForm')[0]);
    const isEdit = !!$('#deviceId').val();
    const action = isEdit ? 'update_device' : 'add_device';
    formData.append('action', action);
    
    $.ajax({
        url: '../../api/devices.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                const deviceId = $('#deviceId').val() || response.device_id;
                const selectedAssignUserId = $('#editAssignUserId').val();
                const currentAssignedUserId = $('#editAssignUserId').attr('data-current-assigned');

                if (isEdit && selectedAssignUserId && String(selectedAssignUserId) !== String(currentAssignedUserId)) {
                    $.post('../../api/devices.php', {
                        action: 'assign_device',
                        device_id: deviceId,
                        user_id: selectedAssignUserId,
                        notes: 'Assigned via Edit Device'
                    }, function(assignResp) {
                        if (assignResp.success) {
                            alert(response.message + ' Device reassigned successfully.');
                            $('#deviceModal').modal('hide');
                            loadDevices();
                            loadRotationHistory();
                        } else {
                            alert(response.message + ' But reassignment failed: ' + assignResp.message);
                            $('#deviceModal').modal('hide');
                            loadDevices();
                        }
                    });
                    return;
                }

                alert(response.message);
                $('#deviceModal').modal('hide');
                loadDevices();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            console.error('Response:', xhr.responseText);
            alert('Request failed: ' + error);
        }
    });
}

function confirmAction(message, onConfirm) {
    if (typeof confirmModal === 'function') {
        confirmModal(message, onConfirm);
        return;
    }
    if (confirm(message)) onConfirm();
}

function deleteDevice(deviceId) {
    confirmAction('Are you sure you want to delete this device?', function() {
        $.post('../../api/devices.php', {
            action: 'delete_device',
            device_id: deviceId
        }, function(response) {
            if (response.success) {
                alert(response.message);
                loadDevices();
            } else {
                alert('Error: ' + response.message);
            }
        });
    });
}

function showAssignModal(deviceId) {
    $('#assignDeviceId').val(deviceId);
    $('#assignNotes').val('');
    
    // Populate users dropdown
    const select = $('#assignUserId');
    select.empty();
    select.append('<option value="">Select User...</option>');
    
    // Load users via AJAX
    $.get('../../api/devices.php?action=get_users', function(response) {
        if (response.success) {
            response.users.forEach(user => {
                select.append(`<option value="${user.id}">${user.full_name || user.username}</option>`);
            });
        }
    });
    
    $('#assignModal').modal('show');
}

function assignDevice() {
    // Validate form
    const deviceId = $('#assignDeviceId').val();
    const userId = $('#assignUserId').val();
    
    if (!deviceId) {
        alert('Device ID is missing');
        return;
    }
    
    if (!userId) {
        alert('Please select a user');
        return;
    }
    
    const formData = new FormData($('#assignForm')[0]);
    formData.append('action', 'assign_device');
    
    $.ajax({
        url: '../../api/devices.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                alert(response.message);
                $('#assignModal').modal('hide');
                loadDevices();
                loadRotationHistory();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            console.error('Response:', xhr.responseText);
            alert('Request failed: ' + error);
        }
    });
}

function returnDevice(deviceId) {
    confirmAction('Mark this device as returned?', function() {
        $.post('../../api/devices.php', {
            action: 'return_device',
            device_id: deviceId
        }, function(response) {
            if (response.success) {
                alert(response.message);
                loadDevices();
            } else {
                alert('Error: ' + response.message);
            }
        });
    });
}

function showRespondModal(requestId) {
    $('#requestId').val(requestId);
    $('#responseNotes').val('');
    $('#respondModal').modal('show');
}

function respondToRequest(action) {
    confirmAction(`Are you sure you want to ${action === 'Approved' ? 'approve' : 'reject'} this request?`, function() {
        $.post('../../api/devices.php', {
            action: 'respond_to_request',
            request_id: $('#requestId').val(),
            response: action,
            response_notes: $('#responseNotes').val()
        }, function(response) {
            if (response.success) {
                alert(response.message);
                $('#respondModal').modal('hide');
                loadRequests();
                loadDevices();
                loadRotationHistory(); // Reload history after approval
            } else {
                alert('Error: ' + response.message);
            }
        });
    });
}

function viewDeviceHistory(deviceId) {
    $.get('../../api/devices.php?action=get_assignment_history&device_id=' + deviceId, function(response) {
        if (response.success) {
            let html = '<div class="table-responsive"><table class="table table-sm">';
            html += '<thead><tr><th>User</th><th>Assigned</th><th>Returned</th><th>Status</th></tr></thead><tbody>';
            
            response.history.forEach(h => {
                html += `<tr>
                    <td>${h.full_name || h.username}</td>
                    <td>${new Date(h.assigned_at).toLocaleString()}</td>
                    <td>${h.returned_at ? new Date(h.returned_at).toLocaleString() : '-'}</td>
                    <td><span class="badge bg-${h.status === 'Active' ? 'success' : 'secondary'}">${h.status}</span></td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';
            
            const modal = $('<div class="modal fade" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">' +
                '<div class="modal-header"><h5 class="modal-title">Assignment History</h5>' +
                '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                '<div class="modal-body">' + html + '</div></div></div></div>');
            
            modal.modal('show');
            modal.on('hidden.bs.modal', function() {
                modal.remove();
            });
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
