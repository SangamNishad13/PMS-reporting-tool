/* Admin Client Permissions JS - extracted from modules/admin/client_permissions.php */
document.getElementById('clientFilter').addEventListener('change', function() {
    var clientId = this.value;
    var checkboxes = document.querySelectorAll('.form-check[data-client-id]');
    checkboxes.forEach(function(checkbox) {
        if (clientId === '' || checkbox.dataset.clientId === clientId) {
            checkbox.style.display = 'block';
        } else {
            checkbox.style.display = 'none';
            checkbox.querySelector('input').checked = false;
        }
    });
    updateSelectedCount();
});

document.querySelectorAll('.project-checkbox').forEach(function(checkbox) {
    checkbox.addEventListener('change', updateSelectedCount);
});

function updateSelectedCount() {
    var count = document.querySelectorAll('.project-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count;
}
