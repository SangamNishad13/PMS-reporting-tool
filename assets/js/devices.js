/**
 * devices.js - Device management page logic
 */
(function () {
    var devices = [];
    var myRequests = [];
    var incomingRequests = [];
    var currentUserId = window.DevicesConfig ? window.DevicesConfig.currentUserId : 0;

    $(document).ready(function () {
        loadDevices();
        loadMyRequests();
        loadIncomingRequests();

        $('#searchDevice').on('keyup', function () {
            filterDevices($(this).val());
        });
    });

    function loadIncomingRequests() {
        $.get('../api/devices.php?action=get_incoming_requests')
            .done(function (response) {
                if (response.success) {
                    incomingRequests = response.requests;
                    renderIncomingRequests();
                } else {
                    showToast(response.message || 'Failed to load incoming requests', 'danger');
                }
            })
            .fail(function (xhr) {
                var msg = 'Failed to load incoming requests';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                showToast(msg, 'danger');
            });
    }

    function loadDevices() {
        $.get('../api/devices.php?action=get_all_devices')
            .done(function (response) {
                if (response.success) {
                    devices = response.devices;
                    renderMyDevices();
                    renderAllDevices();
                } else {
                    showToast(response.message || 'Failed to load devices', 'danger');
                }
            })
            .fail(function (xhr) {
                var msg = 'Failed to load devices';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                showToast(msg, 'danger');
            });
    }

    function loadMyRequests() {
        $.get('../api/devices.php?action=get_switch_requests')
            .done(function (response) {
                if (response.success) {
                    myRequests = response.requests;
                    renderMyRequests();
                } else {
                    showToast(response.message || 'Failed to load requests', 'danger');
                }
            })
            .fail(function (xhr) {
                var msg = 'Failed to load requests';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                showToast(msg, 'danger');
            });
    }

    function renderMyDevices() {
        var container = $('#myDevices');
        container.empty();
        var myDevices = devices.filter(function (d) { return d.assigned_user_id == currentUserId; });
        if (myDevices.length === 0) {
            container.html('<div class="col-12"><p class="text-muted">No devices assigned to you</p></div>');
            return;
        }
        myDevices.forEach(function (device) {
            container.append(
                '<div class="col-md-4">' +
                '<div class="device-card position-relative">' +
                '<div class="d-flex align-items-center">' +
                '<div class="device-icon me-3"><i class="fas fa-' + getDeviceIcon(device.device_type) + '"></i></div>' +
                '<div class="device-info">' +
                '<h5 class="mb-1">' + escapeHtml(device.device_name) + '</h5>' +
                '<p class="mb-0 text-muted">' + escapeHtml(device.device_type) + '</p>' +
                (device.model ? '<small class="text-muted">' + escapeHtml(device.model) + '</small>' : '') +
                (device.version ? '<br><small class="text-muted">Version: ' + escapeHtml(device.version) + '</small>' : '') +
                '</div></div>' +
                '<div class="device-status"><span class="badge bg-success">Assigned to You</span></div>' +
                (device.notes ? '<div class="mt-2"><small class="text-muted"><i class="fas fa-info-circle"></i> ' + escapeHtml(device.notes) + '</small></div>' : '') +
                '<div class="mt-3 d-flex gap-2">' +
                '<button class="btn btn-sm btn-outline-success" onclick="submitDevice(' + device.id + ')">' +
                '<i class="fas fa-box-open"></i> Submit to Office</button>' +
                '</div></div></div>'
            );
        });
    }

    function renderAllDevices() {
        var tbody = $('#devicesTable tbody');
        tbody.empty();
        devices.forEach(function (device) {
            var statusBadge = getStatusBadge(device.status);
            var assignedTo = device.assigned_to_name || '-';
            var isMyDevice = device.assigned_user_id == currentUserId;
            var canRequest = device.status === 'Assigned' && !isMyDevice;
            var canRequestAvailable = device.status === 'Available';
            var actionBtn;
            if (canRequest) {
                actionBtn = '<button class="btn btn-sm btn-primary" onclick="showRequestModal(' + device.id + ')"><i class="fas fa-exchange-alt"></i> Request</button>';
            } else if (canRequestAvailable) {
                actionBtn = '<button class="btn btn-sm btn-outline-primary" onclick="showRequestModal(' + device.id + ')"><i class="fas fa-paper-plane"></i> Request</button>';
            } else if (isMyDevice) {
                actionBtn = '<span class="badge bg-success">Your Device</span>';
            } else {
                actionBtn = '-';
            }
            tbody.append(
                '<tr>' +
                '<td><strong>' + escapeHtml(device.device_name) + '</strong></td>' +
                '<td><i class="fas fa-' + getDeviceIcon(device.device_type) + '"></i> ' + escapeHtml(device.device_type) + '</td>' +
                '<td>' + escapeHtml(device.model || '-') + '</td>' +
                '<td>' + escapeHtml(device.version || '-') + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + escapeHtml(assignedTo) + '</td>' +
                '<td>' + actionBtn + '</td>' +
                '</tr>'
            );
        });
    }

    function renderMyRequests() {
        var tbody = $('#requestsTable tbody');
        tbody.empty();
        if (myRequests.length === 0) {
            tbody.html('<tr><td colspan="7" class="text-center text-muted">No requests found. You can request devices from the "All Devices" section above.</td></tr>');
            return;
        }
        myRequests.forEach(function (request) {
            var statusBadge = getRequestStatusBadge(request.status);
            var response = request.response_notes || '-';
            var canCancel = request.status === 'Pending';
            var statusIcon = '';
            if (request.status === 'Approved') statusIcon = '<i class="fas fa-check-circle text-success"></i> ';
            else if (request.status === 'Rejected') statusIcon = '<i class="fas fa-times-circle text-danger"></i> ';
            else if (request.status === 'Pending') statusIcon = '<i class="fas fa-clock text-warning"></i> ';
            var rowClass = request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '');
            tbody.append(
                '<tr class="' + rowClass + '">' +
                '<td><strong>' + escapeHtml(request.device_name) + '</strong><br><small class="text-muted">' + escapeHtml(request.device_type) + '</small></td>' +
                '<td>' + escapeHtml(request.holder_full_name || request.holder_name || 'Office') + '</td>' +
                '<td><small>' + escapeHtml(request.reason || '-') + '</small></td>' +
                '<td><small>' + new Date(request.requested_at).toLocaleString() + '</small></td>' +
                '<td>' + statusIcon + statusBadge + '</td>' +
                '<td><small>' + escapeHtml(response) + '</small></td>' +
                '<td>' + (canCancel ? '<button class="btn btn-sm btn-danger" onclick="cancelRequest(' + request.id + ')"><i class="fas fa-times"></i> Cancel</button>' : '-') + '</td>' +
                '</tr>'
            );
        });
    }

    function renderIncomingRequests() {
        var tbody = $('#incomingRequestsTable tbody');
        tbody.empty();
        if (incomingRequests.length === 0) {
            tbody.html('<tr><td colspan="6" class="text-center text-muted">No incoming requests</td></tr>');
            return;
        }
        incomingRequests.forEach(function (request) {
            var statusBadge = getRequestStatusBadge(request.status);
            var canRespond = request.status === 'Pending';
            var rowClass = request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '');
            tbody.append(
                '<tr class="' + rowClass + '">' +
                '<td><strong>' + escapeHtml(request.device_name) + '</strong><br><small class="text-muted">' + escapeHtml(request.device_type) + '</small></td>' +
                '<td>' + escapeHtml(request.requester_name) + '</td>' +
                '<td><small>' + escapeHtml(request.reason || '-') + '</small></td>' +
                '<td><small>' + new Date(request.requested_at).toLocaleString() + '</small></td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + (canRespond ?
                    '<button class="btn btn-sm btn-success me-1" onclick="respondToRequest(' + request.id + ', \'approve\')"><i class="fas fa-check"></i> Accept</button>' +
                    '<button class="btn btn-sm btn-danger" onclick="respondToRequest(' + request.id + ', \'reject\')"><i class="fas fa-times"></i> Reject</button>'
                    : '<span class="text-muted">' + escapeHtml(request.status) + '</span>') + '</td>' +
                '</tr>'
            );
        });
    }

    window.cancelRequest = function (requestId) {
        confirmAction('Cancel this request?', function () {
            $.post('../api/devices.php', { action: 'cancel_request', request_id: requestId }, function (response) {
                if (response.success) {
                    showToast('Request cancelled', 'success');
                    loadMyRequests();
                } else {
                    showToast(response.message, 'danger');
                }
            });
        });
    };

    window.respondToRequest = function (requestId, action) {
        var actionText = action === 'approve' ? 'accept' : 'reject';
        var notes = action === 'reject' ? prompt('Reason for rejection (optional):') : '';
        if (action === 'reject' && notes === null) return;
        confirmAction('Are you sure you want to ' + actionText + ' this request?', function () {
            $.post('../api/devices.php', {
                action: 'respond_to_request',
                request_id: requestId,
                response_action: action,
                response_notes: notes || ''
            }, function (response) {
                if (response.success) {
                    showToast('Request ' + (action === 'approve' ? 'accepted' : 'rejected') + ' successfully', 'success');
                    loadIncomingRequests();
                    loadDevices();
                } else {
                    showToast(response.message, 'danger');
                }
            });
        });
    };

    window.showRequestModal = function (deviceId) {
        var device = devices.find(function (d) { return d.id == deviceId; });
        if (!device) return;
        $('#requestDeviceId').val(device.id);
        $('#requestDeviceName').text(device.device_name + ' (' + device.device_type + ')');
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
        $('#requestSubmitBtn').prop('disabled', false).html('Submit Request');
        $('#requestModal').modal('show');
    };

    window.submitRequest = function () {
        var reason = $('#requestReason').val().trim();
        if (!reason) { notify('Please provide a reason for your request', 'warning'); return; }
        var formData = new FormData($('#requestForm')[0]);
        var action = $('#requestAction').val() || 'request_switch';
        var $submitBtn = $('#requestSubmitBtn');
        formData.append('action', action);
        $submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Submitting...');
        try {
            var modalEl = document.getElementById('requestModal');
            if (modalEl && window.bootstrap && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            } else { $('#requestModal').modal('hide'); }
        } catch (e) { $('#requestModal').modal('hide'); }
        $.ajax({
            url: '../api/devices.php', method: 'POST', dataType: 'json',
            data: formData, processData: false, contentType: false,
            success: function (response) {
                if (response.success) {
                    notify(response.message || 'Request submitted successfully', 'success');
                    loadMyRequests();
                } else {
                    notify('Error: ' + (response.message || 'Request failed'), 'danger');
                    $('#requestModal').modal('show');
                }
            },
            error: function (xhr) {
                var msg = 'Request failed. Please try again.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                notify(msg, 'danger');
                $('#requestModal').modal('show');
            },
            complete: function () { $submitBtn.prop('disabled', false).html('Submit Request'); }
        });
    };

    window.submitDevice = function (deviceId) {
        confirmAction('Submit this device back to office? It will become Available.', function () {
            $.post('../api/devices.php', { action: 'submit_device', device_id: deviceId }, function (response) {
                if (response.success) {
                    notify(response.message || 'Device submitted successfully', 'success');
                    loadDevices();
                } else {
                    notify('Error: ' + (response.message || 'Action failed'), 'danger');
                }
            });
        });
    };

    function filterDevices(searchTerm) {
        var rows = $('#devicesTable tbody tr');
        if (!searchTerm) { rows.show(); return; }
        searchTerm = searchTerm.toLowerCase();
        rows.each(function () { $(this).toggle($(this).text().toLowerCase().includes(searchTerm)); });
    }

    function getStatusBadge(status) {
        var badges = { 'Available': 'success', 'Assigned': 'warning', 'Maintenance': 'info', 'Retired': 'secondary' };
        return '<span class="badge bg-' + (badges[status] || 'secondary') + '">' + escapeHtml(status) + '</span>';
    }

    function getRequestStatusBadge(status) {
        var badges = { 'Pending': 'warning', 'Approved': 'success', 'Rejected': 'danger', 'Cancelled': 'secondary' };
        return '<span class="badge bg-' + (badges[status] || 'secondary') + '">' + escapeHtml(status) + '</span>';
    }

    function getDeviceIcon(type) {
        var icons = { 'Android': 'mobile-alt', 'iOS': 'mobile-alt', 'Mac': 'laptop', 'Windows': 'laptop', 'BT Keyboard': 'keyboard', 'Mouse': 'mouse', 'Tablet': 'tablet-alt', 'Other': 'desktop' };
        return icons[type] || 'desktop';
    }

    function notify(message, variant) {
        if (typeof showToast === 'function') showToast(message, variant || 'info');
    }

    function confirmAction(message, onConfirm) {
        if (typeof confirmModal === 'function') { confirmModal(message, onConfirm); return; }
        if (confirm(message)) onConfirm();
    }
})();
