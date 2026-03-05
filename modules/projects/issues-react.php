<?php
require_once '../../includes/auth.php';
requireLogin();

$projectId = $_GET['id'] ?? 0;

if (!$projectId) {
    header('Location: /PMS/modules/admin/projects.php');
    exit;
}

// Get project details
$db = Database::getInstance();
$stmt = $db->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: /PMS/modules/admin/projects.php');
    exit;
}

// Fetch issue statuses
$statusesStmt = $db->query("SELECT id, name as status_name, color, category FROM issue_statuses ORDER BY name ASC");
$issueStatuses = $statusesStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Issues (React) - ' . htmlspecialchars($project['title']);
$baseDir = getBaseDir();
include __DIR__ . '/../../includes/header.php';
?>

<!-- React App Styles -->
<style>
    #react-root {
        margin-top: -20px; /* Adjust for header spacing */
    }
    .react-app-container {
        background-color: #f8f9fa;
        min-height: calc(100vh - 100px);
    }
</style>

<!-- React App Root -->
<div id="react-root"></div>

    <!-- Pass PHP data to React -->
    <script>
        window.APP_CONFIG = {
            projectId: <?php echo (int)$projectId; ?>,
            projectTitle: <?php echo json_encode($project['title']); ?>,
            projectType: <?php echo json_encode($project['project_type']); ?>,
            userId: <?php echo (int)$_SESSION['user_id']; ?>,
            userName: <?php echo json_encode($_SESSION['full_name'] ?? $_SESSION['username']); ?>,
            userRole: <?php echo json_encode($_SESSION['role']); ?>,
            apiBase: '/PMS/api',
            baseUrl: '<?php echo $baseDir; ?>',
            issueStatuses: <?php echo json_encode($issueStatuses); ?>
        };
    </script>
    
    <!-- React App Bundle -->
    <script type="module" src="<?php echo $baseDir; ?>/modules/react/issues-app/dist/assets/index.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
