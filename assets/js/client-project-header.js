/* Client Project Header JS - extracted from modules/client/partials/project_header.php */

function exportProject(format) {
    var cfg = window._projectHeaderConfig || {};
    var projectId = cfg.projectId || 0;
    var baseDir = cfg.baseDir || '';
    var exportUrl = baseDir + '/modules/client/export.php?type=project&format=' + encodeURIComponent(format) + '&project_id=' + projectId;

    var button = event.target.closest('button');
    var originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    button.disabled = true;

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = exportUrl;
    form.style.display = 'none';
    document.body.appendChild(form);
    form.submit();

    setTimeout(function() {
        button.innerHTML = originalText;
        button.disabled = false;
        document.body.removeChild(form);
    }, 3000);
}

function refreshProject() {
    var button = event.target.closest('button');
    var originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;

    var url = new URL(window.location);
    url.searchParams.set('refresh', '1');
    window.location.href = url.toString();
}
