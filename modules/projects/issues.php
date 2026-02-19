<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/project_permissions.php';

$auth = new Auth();
$auth->requireRole(['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'super_admin']);

$baseDir = getBaseDir();
$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

if (!hasProjectAccess($db, $userId, $projectId)) {
    $_SESSION['error'] = "You don't have access to this project.";
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

// Get project details
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    $_SESSION['error'] = 'Project not found.';
    header('Location: ' . $baseDir . '/index.php');
    exit;
}

$pageTitle = 'Accessibility Report - ' . htmlspecialchars($project['title']);
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>">
                        <?php echo htmlspecialchars($project['title']); ?>
                    </a></li>
                    <li class="breadcrumb-item active">Accessibility Report</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1">
                        <i class="fas fa-universal-access text-primary me-2"></i>
                        Accessibility Report
                    </h2>
                    <p class="text-muted mb-0">
                        Project: <strong><?php echo htmlspecialchars($project['title']); ?></strong>
                        <?php if ($project['client_name']): ?>
                            | Client: <strong><?php echo htmlspecialchars($project['client_name']); ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $projectId; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Project
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Pages Card -->
        <div class="col-md-6">
            <div class="card h-100 border-primary">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-file-alt fa-4x text-primary"></i>
                    </div>
                    <h4 class="card-title">Pages</h4>
                    <p class="card-text text-muted">
                        View page-wise final accessibility issues and reports
                    </p>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_pages.php?project_id=<?php echo $projectId; ?>" 
                       class="btn btn-primary btn-lg">
                        <i class="fas fa-arrow-right me-1"></i> View Pages
                    </a>
                </div>
            </div>
        </div>

        <!-- Common Issues Card -->
        <div class="col-md-6">
            <div class="card h-100 border-info">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-layer-group fa-4x text-info"></i>
                    </div>
                    <h4 class="card-title">Common Issues</h4>
                    <p class="card-text text-muted">
                        Manage issues that apply across multiple pages in the project
                    </p>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_common.php?project_id=<?php echo $projectId; ?>" 
                       class="btn btn-info btn-lg">
                        <i class="fas fa-arrow-right me-1"></i> View Common Issues
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Export Issues Card -->
        <div class="col-md-6">
            <div class="card h-100 border-success">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-file-export fa-4x text-success"></i>
                    </div>
                    <h4 class="card-title">Export Issues</h4>
                    <p class="card-text text-muted">
                        Export issues to Excel or PDF with customizable columns and filters
                    </p>
                    <a href="<?php echo $baseDir; ?>/modules/projects/export_issues.php?project_id=<?php echo $projectId; ?>" 
                       class="btn btn-success btn-lg">
                        <i class="fas fa-download me-1"></i> Export Issues
                    </a>
                </div>
            </div>
        </div>
        
        <!-- All Issues Card -->
        <div class="col-md-6">
            <div class="card h-100 border-warning">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-list fa-4x text-warning"></i>
                    </div>
                    <h4 class="card-title">All Issues</h4>
                    <p class="card-text text-muted">
                        View, edit, and manage all issues in one comprehensive list
                    </p>
                    <a href="<?php echo $baseDir; ?>/modules/projects/issues_all.php?project_id=<?php echo $projectId; ?>" 
                       class="btn btn-warning btn-lg">
                        <i class="fas fa-list me-1"></i> View All Issues
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Floating Project Chat (bottom-right) -->
<style>
.chat-launcher { position: fixed; bottom: 20px; right: 20px; z-index: 1060; border-radius: 999px; box-shadow: 0 10px 24px rgba(0,0,0,0.18); padding: 12px 18px; display: flex; align-items: center; gap: 8px; }
.chat-launcher i { font-size: 1.1rem; }
.chat-widget { position: fixed; bottom: 86px; right: 20px; width: 360px; max-width: 92vw; height: 520px; max-height: 78vh; background: #fff; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,0.25); border: 1px solid #e5e7eb; overflow: hidden; z-index: 1060; display: none; }
.chat-widget.open { display: block; }
.chat-widget iframe { width: 100%; height: calc(100% - 48px); border: 0; }
.chat-widget .chat-widget-header { height: 48px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #0d6efd, #4dabf7); color: #fff; }
.chat-widget .chat-widget-header .btn { color: #fff; border-color: rgba(255,255,255,0.3); }
.chat-widget .chat-widget-header .btn:hover { background: rgba(255,255,255,0.12); }
@media (max-width: 576px) {
    .chat-widget { width: 94vw; height: 70vh; bottom: 76px; right: 3vw; }
    .chat-launcher { bottom: 14px; right: 14px; }
}
</style>

<div class="chat-widget" id="projectChatWidget" aria-label="Project Chat">
    <div class="chat-widget-header">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-comments"></i>
            <strong>Project Chat</strong>
        </div>
        <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetClose" aria-label="Close chat">
                <i class="fas fa-times"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetFullscreen" aria-label="Open full chat">
                <i class="fas fa-up-right-and-down-left-from-center"></i>
            </button>
        </div>
    </div>
    <iframe src="<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo $projectId; ?>&embed=1" title="Project Chat"></iframe>
</div>

<button type="button" class="btn btn-primary chat-launcher" id="chatLauncher">
    <i class="fas fa-comments"></i>
    <span>Project Chat</span>
</button>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var launcher = document.getElementById('chatLauncher');
    var widget = document.getElementById('projectChatWidget');
    var closeBtn = document.getElementById('chatWidgetClose');
    var fullscreenBtn = document.getElementById('chatWidgetFullscreen');
    if (!launcher || !widget || !closeBtn || !fullscreenBtn) return;

    function openChatWidget() {
        widget.classList.add('open');
        launcher.style.display = 'none';
        setTimeout(function () { try { closeBtn.focus(); } catch (e) {} }, 0);
    }
    function closeChatWidget() {
        widget.classList.remove('open');
        launcher.style.display = 'inline-flex';
        setTimeout(function () { try { launcher.focus(); } catch (e) {} }, 0);
    }

    launcher.addEventListener('click', openChatWidget);
    closeBtn.addEventListener('click', closeChatWidget);
    fullscreenBtn.addEventListener('click', function () {
        window.location.href = '<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo $projectId; ?>';
    });
    window.addEventListener('message', function (event) {
        if (!event || !event.data || event.data.type !== 'pms-chat-close') return;
        closeChatWidget();
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
