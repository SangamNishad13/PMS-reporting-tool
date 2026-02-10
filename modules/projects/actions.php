<?php
// Compatibility endpoint for legacy JS that posts to /modules/projects/actions.php.
// Routes known actions to the newer API endpoints and returns JSON for unknown actions.

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// Map status update actions to the consolidated status API
$statusActions = ['update_page_status', 'update_env_status', 'update_qa_env_status'];

if (in_array($action, $statusActions, true)) {
    require __DIR__ . '/../../api/status.php';
    exit;
}

// Legacy env status updater (select-based controls)
if (!empty($_POST['tester_type']) || isset($_POST['env_id']) || isset($_POST['environment_id'])) {
    require __DIR__ . '/../../api/update_env_status.php';
    exit;
}

http_response_code(404);
echo json_encode([
    'success' => false,
    'message' => 'Unknown action',
    'action' => $action
]);
