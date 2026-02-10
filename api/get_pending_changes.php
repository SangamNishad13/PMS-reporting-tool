<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Only admins can access this
if (!hasAdminPrivileges()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$db = Database::getInstance();
$userId = $_GET['user_id'] ?? null;
$date = $_GET['date'] ?? null;

if (!$userId || !$date) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

try {
    // Get pending changes
    $stmt = $db->prepare("SELECT * FROM user_pending_changes WHERE user_id = ? AND req_date = ?");
    $stmt->execute([$userId, $date]);
    $pendingData = $stmt->fetch(PDO::FETCH_ASSOC);
    // decode JSON pending_time_logs if present
    if ($pendingData && !empty($pendingData['pending_time_logs'])) {
        $decoded = json_decode($pendingData['pending_time_logs'], true);
        $pendingData['pending_time_logs_decoded'] = $decoded === null ? [] : $decoded;
    } else {
        $pendingData['pending_time_logs_decoded'] = [];
    }
    
    echo json_encode([
        'success' => true,
        'pending' => $pendingData
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>