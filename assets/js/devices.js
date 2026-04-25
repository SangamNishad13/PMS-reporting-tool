/* Devices JS - extracted from modules/admin/devices.php */
let devices = [];
let users = [];
let requests = [];
let filteredDevices = [];
let currentPage = 1;
const itemsPerPage = 10;

$(document).ready(function() {
    loadUsers();
    loadDevices();
    loadRequests();
    loadRotationHistory();
    
    // Search filter for devices table
    $('#searchDevice').on('keyup', function() {
        currentPage = 1;
        applyFilters();
    });
    
    // Filter dropdowns
    $('#filterType, #filterStatus, #filterOwnership').on('change', function() {
        currentPage = 1;
        applyFilters();
    });
});

// Assign device button handler
$(document).on('click', '#assignDeviceBtn', function() {
    assignDevice();
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
            filteredDevices = devices;
            applyFilters();
            renderMyDevices();
            updateStats();
        }
    });
}

function applyFilters() {
    const searchTerm = $('#searchDevice').val().toLowerCase();
    const filterType = $('#filterType').val();
    const filterStatus = $('#filterStatus').val();
    const filterOwnership = $('#filterOwnership').val();
    
    filteredDevices = devices.filter(device => {
        // Search filter
        const searchText = `${device.device_name} ${device.device_type} ${device.model || ''} ${device.version || ''} ${device.assigned_to_name || ''}`.toLowerCase();
        if (searchTerm && !searchText.includes(searchTerm)) {
            return false;
        }
        
        // Type filter
        if (filterType && device.device_type !== filterType) {
            return false;
        }
        
        // Status filter
        if (filterStatus && device.status !== filterStatus) {
            return false;
        }
        
        // Ownership filter
        if (filterOwnership && (device.ownership_type || 'Owned') !== filterOwnership) {
            return false;
        }
        
        return true;
    });
    
    renderDevices();
    renderPagination();
}

function renderPagination() {
    const totalPages = Math.ceil(filteredDevices.length / itemsPerPage);
    const paginationDiv = $('#devicesPagination');
    paginationDiv.empty();
    
    if (totalPages <= 1) {
        return;
    }
    
    let paginationHTML = '<nav><ul class="pagination justify-content-center">';
    
    // Previous button
    paginationHTML += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${currentPage - 1}); return false;">Previous</a>
    </li>`;
    
    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
            paginationHTML += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changePage(${i}); return false;">${i}</a>
            </li>`;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
            paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    // Next button
    paginationHTML += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${currentPage + 1}); return false;">Next</a>
    </li>`;
    
    paginationHTML += '</ul></nav>';
    paginationDiv.html(paginationHTML);
}

function changePage(page) {
    const totalPages = Math.ceil(filteredDevices.length / itemsPerPage);
    if (page < 1 || page > totalPages) {
        return;
    }
    currentPage = page;
    renderDevices();
    renderPagination();
}

function loadRequests() {
    $.get('../../api/devices.php?action=get_switch_requests', function(response) {
        if (response.success) {
            requests = response.requests;
            renderRequests();
            renderIncomingRequests();
            updateStats();
        }
    });
}

function loadRotationHistory() {
    // Only load rotation history for admin users
    // Check if user has admin role by checking if the admin vault link exists
    if (!document.querySelector('a[href*="admin_vault"]')) {
        return; // Skip loading rotation history for non-admin users
    }
    
    $.get('../../api/admin_vault.php?action=get_device_rotation_history', function(response) {
        if (response.success) {
            renderRotationHistory(response.history);
        }
    }).fail(function(xhr) {
        // Silently fail for non-admin users (403 Forbidden)
        if (xhr.status === 403) {
            console.log('Device rotation history is only available for admin users');
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
                <td><strong>${h.device_name}</strong><br><small class="text-muted">${h.device_type}</small></td>
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

function getOwnershipBadge(ownership) {
    if (ownership === 'Leased') {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-file-contract me-1"></i>Leased</span>';
    }
    return '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Owned</span>';
}

function getChargerBadge(charger) {
    if (!charger) return '-';
    let color = 'secondary';
    if (charger.toLowerCase().includes('yes')) color = 'success';
    if (charger.toLowerCase().includes('no')) color = 'danger';
    return `<span class="badge bg-${color}">${charger}</span>`;
}

function toggleLeaseOwner(value) {
    if (value === 'Leased') {
        $('#leaseOwnerWrap').removeClass('d-none');
        $('#leaseOwner').attr('required', true);
    } else {
        $('#leaseOwnerWrap').addClass('d-none');
        $('#leaseOwner').removeAttr('required').val('');
    }
}

function renderMyDevices() {
    const myDevicesDiv = $('#myDevices');
    myDevicesDiv.empty();
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Filter devices assigned to current user
    const myDevices = devices.filter(d => d.assigned_user_id == currentUserId && d.status === 'Assigned');
    
    if (myDevices.length === 0) {
        myDevicesDiv.html(`
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> You don't have any devices assigned to you currently.
                </div>
            </div>
        `);
        return;
    }
    
    myDevices.forEach(device => {
        const ownershipBadge = getOwnershipBadge(device.ownership_type || 'Owned');
        const storageBadge = device.storage_capacity ? `${device.storage_capacity} GB` : '-';
        const chargerBadge = getChargerBadge(device.charger_wire);
        
        myDevicesDiv.append(`
            <div class="col-md-6 col-lg-4">
                <div class="device-card position-relative">
                    <div class="device-status">
                        ${getStatusBadge(device.status)}
                    </div>
                    <div class="d-flex align-items-start">
                        <div class="device-icon me-3">
                            <i class="fas fa-${getDeviceIcon(device.device_type)}"></i>
                        </div>
                        <div class="device-info">
                            <h5 class="mb-1">${device.device_name}</h5>
                            <p class="text-muted mb-2">${device.device_type}</p>
                            <div class="mb-2">
                                <small><strong>Model:</strong> ${device.model || '-'}</small><br>
                                <small><strong>Version:</strong> ${device.version || '-'}</small><br>
                                <small><strong>Storage:</strong> ${storageBadge}</small><br>
                                <small><strong>Charger:</strong> ${chargerBadge}</small><br>
                                <small><strong>Ownership:</strong> ${ownershipBadge}</small>
                            </div>
                            ${device.notes ? `<p class="text-muted small mb-2"><i class="fas fa-sticky-note"></i> ${device.notes}</p>` : ''}
                            <div class="d-flex gap-2 mt-3">
                                <button class="btn btn-sm btn-warning" onclick="returnDevice(${device.id})" title="Return Device">
                                    <i class="fas fa-undo"></i> Return Device
                                </button>
                                <button class="btn btn-sm btn-info" onclick="viewDeviceHistory(${device.id})" title="History">
                                    <i class="fas fa-history"></i> History
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `);
    });
}

function renderDevices() {
    const tbody = $('#devicesTable tbody');
    tbody.empty();
    const canManage = window.DevicesConfig && window.DevicesConfig.canManageDevices;
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Calculate pagination
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const paginatedDevices = filteredDevices.slice(startIndex, endIndex);
    
    if (paginatedDevices.length === 0) {
        tbody.html('<tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No devices found</td></tr>');
        return;
    }
    
    paginatedDevices.forEach(device => {
        const statusBadge = getStatusBadge(device.status);
        const ownershipBadge = getOwnershipBadge(device.ownership_type || 'Owned');
        const storageBadge = device.storage_capacity ? `${device.storage_capacity} GB` : '-';
        const chargerBadge = getChargerBadge(device.charger_wire);
        const assignedTo = device.assigned_to_name || '-';
        
        // Determine which actions to show
        let actionButtons = '';
        
        // History button - available to all users
        actionButtons += `<button class="btn btn-sm btn-info" onclick="viewDeviceHistory(${device.id})" title="History"><i class="fas fa-history"></i></button>`;
        
        // Management buttons - only for users with device management permission
        if (canManage) {
            actionButtons += `<button class="btn btn-sm btn-primary" onclick="showEditDeviceModal(${device.id})" title="Edit"><i class="fas fa-edit"></i></button>`;
            
            if (device.status === 'Available') {
                actionButtons += `<button class="btn btn-sm btn-success" onclick="showAssignModal(${device.id})" title="Assign"><i class="fas fa-user-plus"></i></button>`;
            } else {
                actionButtons += `<button class="btn btn-sm btn-warning" onclick="returnDevice(${device.id})" title="Return"><i class="fas fa-undo"></i></button>`;
            }
            
            actionButtons += `<button class="btn btn-sm btn-danger" onclick="deleteDevice(${device.id})" title="Delete"><i class="fas fa-trash"></i></button>`;
        } else {
            // For non-managers, show return button if device is assigned to them
            if (device.status === 'Assigned' && device.assigned_user_id == currentUserId) {
                actionButtons += `<button class="btn btn-sm btn-warning" onclick="returnDevice(${device.id})" title="Return Device"><i class="fas fa-undo"></i> Return</button>`;
            }
            // Show request button if device is assigned to someone else
            else if (device.status === 'Assigned' && device.assigned_user_id != currentUserId) {
                actionButtons += `<button class="btn btn-sm btn-primary" onclick="showRequestModal(${device.id})" title="Request Device"><i class="fas fa-hand-paper"></i> Request</button>`;
            }
        }
        
        tbody.append(`
            <tr>
                <td><strong>${device.device_name}</strong></td>
                <td><i class="fas fa-${getDeviceIcon(device.device_type)}"></i> ${device.device_type}</td>
                <td>${device.model || '-'}</td>
                <td>${device.version || '-'}</td>
                <td>${ownershipBadge}</td>
                <td>${storageBadge}</td>
                <td>${chargerBadge}</td>
                <td>${statusBadge}</td>
                <td>${assignedTo}</td>
                <td>${actionButtons}</td>
            </tr>
        `);
    });
    
    // Update showing info
    const totalDevices = filteredDevices.length;
    const showingStart = totalDevices > 0 ? startIndex + 1 : 0;
    const showingEnd = Math.min(endIndex, totalDevices);
    $('#devicesShowingInfo').text(`Showing ${showingStart}-${showingEnd} of ${totalDevices} devices`);
}

function renderRequests() {
    const tbody = $('#requestsTable tbody');
    tbody.empty();
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Filter requests made by current user
    const myRequests = requests.filter(r => r.requester_id == currentUserId);
    
    if (myRequests.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> You haven\'t made any device switch requests yet</td></tr>');
        return;
    }
    
    myRequests.forEach(request => {
        const statusBadge = getRequestStatusBadge(request.status);
        const isPending = request.status === 'Pending';
        const rowClass = request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '');
        const holderName = request.holder_full_name || request.holder_name || 'Office';
        const responseNotes = request.response_notes ? `<small>${request.response_notes}</small>` : '-';
        
        // Actions for user's own requests
        const actions = isPending ? 
            `<button class="btn btn-sm btn-danger" onclick="cancelRequest(${request.id})" title="Cancel Request"><i class="fas fa-times"></i> Cancel</button>` :
            `<small class="text-muted">Completed</small>`;
        
        tbody.append(`
            <tr class="${rowClass}">
                <td><strong>${request.device_name}</strong><br><small class="text-muted">${request.device_type}</small></td>
                <td><strong>${holderName}</strong></td>
                <td><small>${request.reason || '<em class="text-muted">No reason provided</em>'}</small></td>
                <td><small>${new Date(request.requested_at).toLocaleString()}</small></td>
                <td>${statusBadge}</td>
                <td>${responseNotes}</td>
                <td>${actions}</td>
            </tr>
        `);
    });
}

function renderIncomingRequests() {
    const tbody = $('#incomingRequestsTable tbody');
    tbody.empty();
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Filter requests for devices assigned to current user
    const incomingRequests = requests.filter(r => {
        // Find the device for this request
        const device = devices.find(d => d.id == r.device_id);
        // Check if device is assigned to current user and request is not from current user
        return device && device.assigned_user_id == currentUserId && r.requester_id != currentUserId;
    });
    
    if (incomingRequests.length === 0) {
        tbody.html('<tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No incoming requests for your devices</td></tr>');
        return;
    }
    
    incomingRequests.forEach(request => {
        const statusBadge = getRequestStatusBadge(request.status);
        const isPending = request.status === 'Pending';
        const rowClass = request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '');
        
        const actions = isPending ?
            `<button class="btn btn-sm btn-success me-1" onclick="quickApprove(${request.id})" title="Approve"><i class="fas fa-check"></i> Approve</button>
            <button class="btn btn-sm btn-danger" onclick="quickReject(${request.id})" title="Reject"><i class="fas fa-times"></i> Reject</button>
            <button class="btn btn-sm btn-primary mt-1" onclick="showRespondModal(${request.id})" title="Respond with Notes"><i class="fas fa-reply"></i> Respond</button>` :
            `<small class="text-muted">Responded</small>`;
        
        tbody.append(`
            <tr class="${rowClass}">
                <td><strong>${request.device_name}</strong><br><small class="text-muted">${request.device_type}</small></td>
                <td><strong>${request.requester_full_name || request.requester_name}</strong></td>
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
    if (reason === null) return;
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

function cancelRequest(requestId) {
    confirmAction('Are you sure you want to cancel this request?', function() {
        $.post('../../api/devices.php', {
            action: 'cancel_request',
            request_id: requestId
        }, function(response) {
            if (response.success) {
                alert(response.message || 'Request cancelled successfully');
                loadRequests();
            } else {
                alert('Error: ' + (response.message || 'Failed to cancel request'));
            }
        }).fail(function() {
            alert('Failed to cancel request. Please try again.');
        });
    });
}

function updateStats() {
    $('#totalDevices').text(devices.length);
    $('#availableDevices').text(devices.filter(d => d.status === 'Available').length);
    $('#assignedDevices').text(devices.filter(d => d.status === 'Assigned').length);
    $('#pendingRequests').text(requests.filter(r => r.status === 'Pending').length);
}

function getStatusBadge(status) {
    const badges = { 'Available': 'success', 'Assigned': 'warning', 'Maintenance': 'info', 'Retired': 'secondary' };
    return `<span class="badge bg-${badges[status]}">${status}</span>`;
}

function getRequestStatusBadge(status) {
    const badges = { 'Pending': 'warning', 'Approved': 'success', 'Rejected': 'danger', 'Cancelled': 'secondary' };
    return `<span class="badge bg-${badges[status]}">${status}</span>`;
}

function getDeviceIcon(type) {
    const icons = { 'Android': 'mobile-alt', 'iOS': 'mobile-alt', 'Mac': 'laptop', 'Windows': 'laptop', 'BT Keyboard': 'keyboard', 'Mouse': 'mouse', 'Tablet': 'tablet-alt', 'Other': 'desktop' };
    return icons[type] || 'desktop';
}

function showAddDeviceModal() {
    $('#deviceModalTitle').text('Add Device');
    $('#deviceForm')[0].reset();
    $('#deviceId').val('');
    $('#editAssignWrap').addClass('d-none');
    $('#leaseOwnerWrap').addClass('d-none');
    $('#leaseOwner').removeAttr('required').val('');
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
    $('#storageCapacity').val(device.storage_capacity || '');
    $('#chargerWire').val(device.charger_wire || '');
    $('#version').val(device.version);
    $('#serialNumber').val(device.serial_number);
    $('#purchaseDate').val(device.purchase_date);
    $('#status').val(device.status);
    $('#ownershipType').val(device.ownership_type || 'Owned');
    toggleLeaseOwner(device.ownership_type || 'Owned');
    $('#leaseOwner').val(device.lease_owner || '');
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
                        } else {
                            alert(response.message + ' But reassignment failed: ' + assignResp.message);
                        }
                        $('#deviceModal').modal('hide');
                        loadDevices();
                        loadRotationHistory();
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
        $.post('../../api/devices.php', { action: 'delete_device', device_id: deviceId }, function(response) {
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
    const select = $('#assignUserId');
    select.empty();
    select.append('<option value="">Select User...</option>');
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
    const deviceId = $('#assignDeviceId').val();
    const userId = $('#assignUserId').val();
    if (!deviceId) { alert('Device ID is missing'); return; }
    if (!userId) { alert('Please select a user'); return; }
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
            alert('Request failed: ' + error);
        }
    });
}

function returnDevice(deviceId) {
    confirmAction('Mark this device as returned?', function() {
        $.post('../../api/devices.php', { action: 'return_device', device_id: deviceId }, function(response) {
            if (response.success) {
                alert(response.message);
                loadDevices();
                loadRotationHistory();
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
                loadRotationHistory();
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
            modal.on('hidden.bs.modal', function() { modal.remove(); });
        }
    });
}

function showRequestModal(deviceId) {
    const device = devices.find(d => d.id == deviceId);
    if (!device) return;
    
    $('#requestDeviceId').val(deviceId);
    $('#requestDeviceName').text(device.device_name);
    $('#requestCurrentHolder').text(device.assigned_to_name || 'Office');
    $('#requestReason').val('');
    $('#requestModal').modal('show');
}

function submitRequest() {
    const deviceId = $('#requestDeviceId').val();
    const reason = $('#requestReason').val().trim();
    
    if (!reason) {
        alert('Please provide a reason for your request');
        return;
    }
    
    $.post('../../api/devices.php', {
        action: 'request_switch',
        device_id: deviceId,
        reason: reason
    }, function(response) {
        if (response.success) {
            alert(response.message || 'Request submitted successfully');
            $('#requestModal').modal('hide');
            loadRequests();
        } else {
            alert('Error: ' + (response.message || 'Failed to submit request'));
        }
    }).fail(function() {
        alert('Failed to submit request. Please try again.');
    });
}
