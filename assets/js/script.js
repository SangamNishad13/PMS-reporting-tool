// Global JavaScript Functions

// Confirm before delete
function confirmDelete(message = "Are you sure you want to delete this? This action cannot be undone.", callback) {
    if (typeof confirmModal === 'function') {
        confirmModal(message, callback, {
            title: 'Confirm Delete',
            icon: '<i class="fas fa-exclamation-triangle text-danger me-2"></i>',
            confirmText: 'Delete',
            confirmClass: 'btn-danger'
        });
    } else {
        if (confirm(message)) {
            if (typeof callback === 'function') callback();
        }
    }
}

// Confirm form submission
function confirmForm(formId, message = "Are you sure?") {
    confirmDelete(message, function () {
        const form = document.getElementById(formId);
        if (form) {
            // Use a temporary hidden input to ensure the button name/value is sent if needed
            // But usually, we can just call submit(). 
            // If the button was used for a specific action, we might need to handle it.
            form.submit();
        }
    });
    return false;
}

// Initialize tooltips
$(function () {
    $('[data-bs-toggle="tooltip"]').tooltip();
});

// Auto-hide only alerts that explicitly opt-in with `alert-auto` class
// Persistent informational alerts (e.g. "No projects") should NOT include this class.
$(document).ready(function () {
    setTimeout(function () {
        $('.alert.alert-auto').fadeOut('slow');
    }, 5000);
});

// File upload preview
function previewFile(input, previewId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) {
            $('#' + previewId).attr('src', e.target.result);
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Form validation
function validateForm(formId) {
    var form = document.getElementById(formId);
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return false;
    }
    return true;
}

// AJAX status update
function updateStatus(elementId, url, data) {
    $.ajax({
        url: url,
        type: 'POST',
        data: data,
        success: function (response) {
            $('#' + elementId).html(response);
            showToast('Status updated successfully!', 'success');
        },
        error: function () {
            showToast('Error updating status!', 'error');
        }
    });
}

// Toast notifications
function showToast(message, type = 'info') {
    var toast = $('<div class="toast align-items-center text-white bg-' +
        (type === 'success' ? 'success' :
            type === 'error' ? 'danger' : 'info') +
        ' border-0" role="alert">' +
        '<div class="d-flex">' +
        '<div class="toast-body">' + message + '</div>' +
        '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
        '</div></div>');

    $('#toastContainer').append(toast);
    var bsToast = new bootstrap.Toast(toast[0]);
    bsToast.show();

    setTimeout(function () {
        toast.remove();
    }, 3000);
}

// Initialize DataTables with common settings
function initDataTable(tableId) {
    return $(tableId).DataTable({
        "pageLength": 25,
        "order": [[0, "desc"]],
        "language": {
            "search": "Filter:",
            "lengthMenu": "Show _MENU_ entries",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries"
        }
    });
}

// Real-time updates (for chat, notifications)
function startRealTimeUpdates() {
    setInterval(function () {
        // Check for new messages/updates
        $.get('/api/check-updates', function (data) {
            if (data.new_messages > 0) {
                updateNotificationBadge(data.new_messages);
            }
        });
    }, 30000); // Every 30 seconds
}

function updateNotificationBadge(count) {
    $('#notificationBadge').text(count).show();
}

// Date picker initialization
function initDatePickers() {
    $('.datepicker').datepicker({
        format: 'yyyy-mm-dd',
        autoclose: true,
        todayHighlight: true
    });
}

// Multi-select enhancements
function initMultiSelect() {
    $('.multi-select').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });
}

// Initialize everything when document is ready
$(document).ready(function () {
    // Initialize DataTables
    $('.dataTable').each(function () {
        if (!$.fn.DataTable.isDataTable(this)) {
            initDataTable(this);
        }
    });

    // Initialize date pickers
    if ($('.datepicker').length) {
        initDatePickers();
    }

    // Initialize multi-select
    if ($('.multi-select').length) {
        initMultiSelect();
    }

    // Start real-time updates if user is logged in
    if (typeof userId !== 'undefined') {
        startRealTimeUpdates();
    }

    // Add toast container if not present
    if (!$('#toastContainer').length) {
        $('body').append('<div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index: 11"></div>');
    }
});