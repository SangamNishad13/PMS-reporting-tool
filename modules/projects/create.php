<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/client_permissions.php';

$auth = new Auth();
$auth->requireLogin();

$projectManager = new ProjectManager();
$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Get client_id from URL if provided
$preselectedClientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;

// Check if user has permission to create projects
$isAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin']);

if (!$isAdmin) {
    // Check if user has create_project permission for any client
    if (!hasAnyClientPermissions($db, $userId)) {
        $_SESSION['error'] = "You don't have permission to create projects.";
        redirect("/modules/projects/my_client_projects.php");
    }
    
    // If client_id is provided, verify user has permission for that specific client
    if ($preselectedClientId) {
        if (!canCreateProjectForClient($db, $userId, $preselectedClientId)) {
            $_SESSION['error'] = "You don't have permission to create projects for this client.";
            redirect("/modules/projects/my_client_projects.php");
        }
    }
}

// Get clients user can create projects for
$allowedClients = [];
if ($isAdmin) {
    $allowedClients = $db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
} else {
    $clientIds = getClientsWithPermission($db, $userId, 'create_project');
    if (!empty($clientIds)) {
        $placeholders = str_repeat('?,', count($clientIds) - 1) . '?';
        $stmt = $db->prepare("SELECT id, name FROM clients WHERE id IN ($placeholders) ORDER BY name");
        $stmt->execute($clientIds);
        $allowedClients = $stmt->fetchAll();
    }
}

// If no clients available, redirect
if (empty($allowedClients)) {
    $_SESSION['error'] = "No clients available for project creation.";
    redirect("/modules/projects/my_client_projects.php");
}

// Get project leads for dropdown
$projectLeads = $db->query("SELECT id, full_name FROM users WHERE role IN ('project_lead','admin') ORDER BY full_name")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid security token. Please try again.";
        redirect("/modules/projects/create.php");
    }
    $clientId = intval($_POST['client_id']);
    
    // Verify permission again on submission
    if (!$isAdmin && !canCreateProjectForClient($db, $userId, $clientId)) {
        $_SESSION['error'] = "You don't have permission to create projects for this client.";
        redirect("/modules/projects/create.php");
    }
    
    $projectData = [
        'po_number' => sanitizeInput($_POST['po_number'] ?? ''),
        'title' => sanitizeInput($_POST['title']),
        'description' => sanitizeInput($_POST['description']),
        'project_type' => sanitizeInput($_POST['project_type']),
        'client_id' => $clientId,
        'priority' => sanitizeInput($_POST['priority']),
        'created_by' => $userId,
        'parent_project_id' => null,
        'project_lead_id' => isset($_POST['project_lead_id']) && $_POST['project_lead_id'] !== '' ? intval($_POST['project_lead_id']) : null,
        'total_hours' => isset($_POST['total_hours']) && $_POST['total_hours'] !== '' ? floatval($_POST['total_hours']) : null
    ];
    
    if ($projectManager->createProject($projectData)) {
        $projectId = $db->lastInsertId();
        
        // Add default phases
        $phases = ['po_received', 'scoping_confirmation', 'testing', 'regression'];
        foreach ($phases as $phase) {
            $stmt = $db->prepare("INSERT INTO project_phases (project_id, phase_name) VALUES (?, ?)");
            $stmt->execute([$projectId, $phase]);
        }
        
        $_SESSION['success'] = "Project created successfully!";
        redirect("/modules/projects/view.php?id=$projectId");
    } else {
        $_SESSION['error'] = "Failed to create project. Please try again.";
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Create New Project</h2>
                <a href="<?php echo $baseDir; ?>/modules/projects/my_client_projects.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <form method="POST" id="createProjectForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Project Code (optional)</label>
                                <input type="text" name="po_number" class="form-control" 
                                       placeholder="Leave empty for auto-generation">
                                <small class="text-muted">If left empty, a code will be auto-generated based on client prefix</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Client <span class="text-danger">*</span></label>
                                <select name="client_id" class="form-select" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($allowedClients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>" 
                                                <?php echo ($preselectedClientId == $client['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($client['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Project Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control" required 
                                       placeholder="Enter project title">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Project Type <span class="text-danger">*</span></label>
                                <select name="project_type" class="form-select" required>
                                    <option value="web">Web Project</option>
                                    <option value="app">App Project</option>
                                    <option value="pdf">PDF Remediation</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority <span class="text-danger">*</span></label>
                                <select name="priority" class="form-select" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Project Lead</label>
                                <select name="project_lead_id" class="form-select">
                                    <option value="">Select Project Lead</option>
                                    <?php foreach ($projectLeads as $lead): ?>
                                        <option value="<?php echo $lead['id']; ?>">
                                            <?php echo htmlspecialchars($lead['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Total Hours (optional)</label>
                                <input type="number" name="total_hours" class="form-control" 
                                       step="0.01" min="0" placeholder="e.g., 40.50">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="4" 
                                          placeholder="Enter project description"></textarea>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2">
                            <a href="<?php echo $baseDir; ?>/modules/projects/my_client_projects.php" 
                               class="btn btn-secondary">Cancel</a>
                            <button type="submit" name="create_project" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create Project
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
