/* Client Dashboard Widgets JS - extracted from modules/client/partials/dashboard_widgets.php */
document.addEventListener('DOMContentLoaded', function() {
    var widgets = document.querySelectorAll('.widget-container .dashboard-widget');
    widgets.forEach(function(widget) {
        var drillDownLink = widget.querySelector('.widget-actions a');
        if (drillDownLink) {
            widget.addEventListener('click', function(e) {
                if (!e.target.closest('.widget-actions')) {
                    window.location.href = drillDownLink.href;
                }
            });
            widget.style.cursor = 'pointer';
        }
    });
});
