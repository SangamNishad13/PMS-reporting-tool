/* Client Dashboard Widgets JS - extracted from modules/client/partials/dashboard_widgets.php */
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var activeReport = params.get('report');
    if (!activeReport) {
        return;
    }

    var focusedWidget = document.querySelector('.dashboard-widget[data-report-type="' + activeReport + '"]');
    if (!focusedWidget) {
        return;
    }

    focusedWidget.classList.add('is-active');
    var container = focusedWidget.closest('.widget-container, .project-analytics-widget');
    if (container) {
        container.classList.add('is-active');
    }

    setTimeout(function() {
        focusedWidget.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 120);
});
