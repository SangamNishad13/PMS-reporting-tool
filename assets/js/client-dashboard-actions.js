/* Client Dashboard Actions JS - extracted from modules/client/partials/dashboard_actions.php */
function refreshDashboard() {
    var button = event.target.closest('button');
    var originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    button.disabled = true;
    var url = new URL(window.location);
    url.searchParams.set('refresh', '1');
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    var projectFilter = document.getElementById('projectFilter');
    if (projectFilter) {
        projectFilter.addEventListener('change', function() {
            var url = new URL(window.location);
            if (this.value) {
                url.searchParams.set('project_id', this.value);
            } else {
                url.searchParams.delete('project_id');
            }
            window.location.href = url.toString();
        });
    }
});
