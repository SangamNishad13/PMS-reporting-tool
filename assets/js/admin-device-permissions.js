/* Admin Device Permissions JS - extracted from modules/admin/device_permissions.php */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[data-confirm="device-perm"]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var onConfirm = function() { form.submit(); };
            if (typeof confirmModal === 'function') {
                confirmModal('Save device permission changes for this user?', onConfirm);
            } else if (confirm('Save device permission changes for this user?')) {
                onConfirm();
            }
        });
    });
});
