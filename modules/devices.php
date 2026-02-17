<?php
require_once '../includes/auth.php';
requireLogin();

$page_title = 'Devices';
include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <h2><i class="fas fa-laptop"></i> Device Inventory</h2>
            <p class="text-muted">View all devices and their current assignments</p>
        </div>
        <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true)): ?>
        <div class="col-auto d-flex align-items-start gap-2">
            <a href="../modules/admin/devices.php" class="btn btn-outline-primary">
                <i class="fas fa-cogs"></i> Manage Devices
            </a>
            <a href="../modules/admin/device_permissions.php" class="btn btn-outline-secondary">
                <i class="fas fa-user-shield"></i> Device Permissions
            </a>
            <a href="../modules/admin/uploads_manager.php" class="btn btn-outline-danger">
                <i class="fas fa-folder-open"></i> Uploads Manager
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- My Devices Section -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-user"></i> My Devices</h5>
        </div>
        <div class="card-body">
            <div id="myDevices" class="row"></div>
        </div>
    </div>

    <!-- All Devices Section -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list"></i> All Devices</h5>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <input type="text" class="form-control" id="searchDevice" placeholder="Search devices...">
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="devicesTable">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Type</th>
                            <th>Model</th>
                            <th>Version</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Incoming Requests Section (Requests for my devices) -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="fas fa-inbox"></i> Incoming Device Requests</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> <strong>Requests for your devices:</strong> Other users have requested devices currently assigned to you. You can accept or reject these requests directly.
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="incomingRequestsTable">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Requested By</th>
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

    <!-- My Requests Section -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-exchange-alt"></i> My Device Switch Requests</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> <strong>How it works:</strong>
                <ol class="mb-0 mt-2">
                    <li>Find a device you need that's assigned to someone else</li>
                    <li>Click "Request" button and provide a reason</li>
                    <li>The device holder or an admin can approve your request</li>
                    <li>If approved, the device will be automatically assigned to you</li>
                </ol>
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="requestsTable">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Current Holder</th>
                            <th>Your Reason</th>
                            <th>Requested At</th>
                            <th>Status</th>
                            <th>Admin Response</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Request Device Modal -->
<div class="modal fade" id="requestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="requestModalTitle">Request Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="requestForm">
                    <input type="hidden" id="requestDeviceId" name="device_id">
                    <input type="hidden" id="requestAction" value="request_switch">
                    
                    <div class="alert alert-info">
                        <strong>Device:</strong> <span id="requestDeviceName"></span><br>
                        <strong>Current Holder:</strong> <span id="requestCurrentHolder"></span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Request *</label>
                        <textarea class="form-control" id="requestReason" name="reason" rows="4" 
                                  placeholder="Please explain why you need this device..." required></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <span id="requestHelpText">Your request will be sent to the device holder. They can accept your request, or an admin can approve it.</span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitRequest()">Submit Request</button>
            </div>
        </div>
    </div>
</div>

<style>
.device-card {
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    transition: all 0.3s;
}

.device-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.device-icon {
    font-size: 3rem;
    color: #6c757d;
}

.device-info {
    flex-grow: 1;
}

.device-status {
    position: absolute;
    top: 10px;
    right: 10px;
}
</style>

<script>
let devices = [];
let myRequests = [];
let incomingRequests = [];
const currentUserId = <?php echo $_SESSION['user_id']; ?>;

$(document).ready(function() {
    loadDevices();
    loadMyRequests();
    loadIncomingRequests();
    
    $('#searchDevice').on('keyup', function() {
        filterDevices($(this).val());
    });
});

function loadIncomingRequests() {
    $.get('../api/devices.php?action=get_incoming_requests', function(response) {
        if (response.success) {
            incomingRequests = response.requests;
            renderIncomingRequests();
        }
    });
}

function loadDevices() {
    $.get('../api/devices.php?action=get_all_devices', function(response) {
        if (response.success) {
            devices = response.devices;
            renderMyDevices();
            renderAllDevices();
        }
    });
}

function loadMyRequests() {
    $.get('../api/devices.php?action=get_switch_requests', function(response) {
        if (response.success) {
            myRequests = response.requests;
            renderMyRequests();
        }
    });
}

function renderMyDevices() {
    const container = $('#myDevices');
    container.empty();
    
    const myDevices = devices.filter(d => d.assigned_user_id == currentUserId);
    
    if (myDevices.length === 0) {
        container.html('<div class="col-12"><p class="text-muted">No devices assigned to you</p></div>');
        return;
    }
    
    myDevices.forEach(device => {
        container.append(`
            <div class="col-md-4">
                <div class="device-card position-relative">
                    <div class="d-flex align-items-center">
                        <div class="device-icon me-3">
                            <i class="fas fa-${getDeviceIcon(device.device_type)}"></i>
                        </div>
                        <div class="device-info">
                            <h5 class="mb-1">${device.device_name}</h5>
                            <p class="mb-0 text-muted">${device.device_type}</p>
                            ${device.model ? `<small class="text-muted">${device.model}</small>` : ''}
                            ${device.version ? `<br><small class="text-muted">Version: ${device.version}</small>` : ''}
                        </div>
                    </div>
                    <div class="device-status">
                        <span class="badge bg-success">Assigned to You</span>
                    </div>
                    ${device.notes ? `<div class="mt-2"><small class="text-muted"><i class="fas fa-info-circle"></i> ${device.notes}</small></div>` : ''}
                    <div class="mt-3 d-flex gap-2">
                        <button class="btn btn-sm btn-outline-success" onclick="submitDevice(${device.id})">
                            <i class="fas fa-box-open"></i> Submit to Office
                        </button>
                    </div>
                </div>
            </div>
        `);
    });
}

function renderAllDevices() {
    const tbody = $('#devicesTable tbody');
    tbody.empty();
    
    devices.forEach(device => {
        const statusBadge = getStatusBadge(device.status);
        const assignedTo = device.assigned_to_name || '-';
        const isMyDevice = device.assigned_user_id == currentUserId;
        const canRequest = device.status === 'Assigned' && !isMyDevice;
        const canRequestAvailable = device.status === 'Available';
        
        const actionBtn = canRequest ? 
            `<button class="btn btn-sm btn-primary" onclick="showRequestModal(${device.id})">
                <i class="fas fa-exchange-alt"></i> Request
            </button>` : 
            (canRequestAvailable ? 
                `<button class="btn btn-sm btn-outline-primary" onclick="showRequestModal(${device.id})">
                    <i class="fas fa-paper-plane"></i> Request
                </button>` : 
                (isMyDevice ? '<span class="badge bg-success">Your Device</span>' : '-'));
        
        tbody.append(`
            <tr>
                <td><strong>${device.device_name}</strong></td>
                <td><i class="fas fa-${getDeviceIcon(device.device_type)}"></i> ${device.device_type}</td>
                <td>${device.model || '-'}</td>
                <td>${device.version || '-'}</td>
                <td>${statusBadge}</td>
                <td>${assignedTo}</td>
                <td>${actionBtn}</td>
            </tr>
        `);
    });
}

function renderMyRequests() {
    const tbody = $('#requestsTable tbody');
    tbody.empty();
    
    if (myRequests.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted">No requests found. You can request devices from the "All Devices" section above.</td></tr>');
        return;
    }
    
    myRequests.forEach(request => {
        const statusBadge = getRequestStatusBadge(request.status);
        const response = request.response_notes || '-';
        const canCancel = request.status === 'Pending';
        
        let statusIcon = '';
        if (request.status === 'Approved') {
            statusIcon = '<i class="fas fa-check-circle text-success"></i> ';
        } else if (request.status === 'Rejected') {
            statusIcon = '<i class="fas fa-times-circle text-danger"></i> ';
        } else if (request.status === 'Pending') {
            statusIcon = '<i class="fas fa-clock text-warning"></i> ';
        }
        
        tbody.append(`
            <tr class="${request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '')}">
                <td><strong>${request.device_name}</strong><br><small class="text-muted">${request.device_type}</small></td>
                <td>${request.holder_full_name || request.holder_name || 'Office'}</td>
                <td><small>${request.reason || '-'}</small></td>
                <td><small>${new Date(request.requested_at).toLocaleString()}</small></td>
                <td>${statusIcon}${statusBadge}</td>
                <td><small>${response}</small></td>
                <td>
                    ${canCancel ? `
                        <button class="btn btn-sm btn-danger" onclick="cancelRequest(${request.id})">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    ` : '-'}
                </td>
            </tr>
        `);
    });
}

function cancelRequest(requestId) {
    if (!confirm('Cancel this request?')) return;
    
    $.post('../api/devices.php', {
        action: 'cancel_request',
        request_id: requestId
    }, function(response) {
        if (response.success) {
            showToast('Request cancelled', 'success');
            loadMyRequests();
        } else {
            showToast(response.message, 'danger');
        }
    });
}

function renderIncomingRequests() {
    const tbody = $('#incomingRequestsTable tbody');
    tbody.empty();
    
    if (incomingRequests.length === 0) {
        tbody.html('<tr><td colspan="6" class="text-center text-muted">No incoming requests</td></tr>');
        return;
    }
    
    incomingRequests.forEach(request => {
        const statusBadge = getRequestStatusBadge(request.status);
        const canRespond = request.status === 'Pending';
        
        tbody.append(`
            <tr class="${request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '')}">
                <td><strong>${request.device_name}</strong><br><small class="text-muted">${request.device_type}</small></td>
                <td>${request.requester_name}</td>
                <td><small>${request.reason || '-'}</small></td>
                <td><small>${new Date(request.requested_at).toLocaleString()}</small></td>
                <td>${statusBadge}</td>
                <td>
                    ${canRespond ? `
                        <button class="btn btn-sm btn-success me-1" onclick="respondToRequest(${request.id}, 'approve')">
                            <i class="fas fa-check"></i> Accept
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="respondToRequest(${request.id}, 'reject')">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    ` : `<span class="text-muted">${request.status}</span>`}
                </td>
            </tr>
        `);
    });
}

function respondToRequest(requestId, action) {
    const actionText = action === 'approve' ? 'accept' : 'reject';
    const notes = action === 'reject' ? prompt('Reason for rejection (optional):') : '';
    
    if (action === 'reject' && notes === null) return; // User cancelled

    confirmAction(`Are you sure you want to ${actionText} this request?`, function() {
        $.post('../api/devices.php', {
            action: 'respond_to_request',
            request_id: requestId,
            response_action: action,
            response_notes: notes || ''
        }, function(response) {
            if (response.success) {
                showToast(`Request ${action === 'approve' ? 'accepted' : 'rejected'} successfully`, 'success');
                loadIncomingRequests();
                loadDevices(); // Refresh device list
            } else {
                showToast(response.message, 'danger');
            }
        });
    });
}

function filterDevices(searchTerm) {
    const rows = $('#devicesTable tbody tr');
    
    if (!searchTerm) {
        rows.show();
        return;
    }
    
    searchTerm = searchTerm.toLowerCase();
    
    rows.each(function() {
        const text = $(this).text().toLowerCase();
        $(this).toggle(text.includes(searchTerm));
    });
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

function showRequestModal(deviceId) {
    const device = devices.find(d => d.id == deviceId);
    if (!device) return;
    
    $('#requestDeviceId').val(device.id);
    $('#requestDeviceName').text(`${device.device_name} (${device.device_type})`);
    if (device.status === 'Available') {
        $('#requestModalTitle').text('Request Available Device');
        $('#requestAction').val('request_available');
        $('#requestCurrentHolder').text('Office');
        $('#requestHelpText').text('Your request will be sent to admin for approval.');
    } else {
        $('#requestModalTitle').text('Request Device Switch');
        $('#requestAction').val('request_switch');
        $('#requestCurrentHolder').text(device.assigned_to_name || 'Unknown');
        $('#requestHelpText').text('Your request will be sent to the device holder. They can accept your request, or an admin can approve it.');
    }
    $('#requestReason').val('');
    $('#requestModal').modal('show');
}

function submitRequest() {
    const reason = $('#requestReason').val().trim();
    if (!reason) {
        alert('Please provide a reason for your request');
        return;
    }
    
    const formData = new FormData($('#requestForm')[0]);
    const action = $('#requestAction').val() || 'request_switch';
    formData.append('action', action);
    
    $.ajax({
        url: '../api/devices.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                alert(response.message);
                $('#requestModal').modal('hide');
                loadMyRequests();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Request failed. Please try again.');
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

function submitDevice(deviceId) {
    confirmAction('Submit this device back to office? It will become Available.', function() {
        $.post('../api/devices.php', {
            action: 'submit_device',
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
</script>

<?php include '../includes/footer.php'; ?>
