<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';

// Mock session
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = 'mock_token';

$db = Database::getInstance();

// Find a valid user ID to test with
$userId = $db->query("SELECT id FROM users WHERE is_active = 1 LIMIT 1")->fetchColumn();
if (!$userId) {
    echo "No active user found in database to test with.\n";
    exit;
}

// Find a page_id and environment_id to test with
$row = $db->query("SELECT page_id, environment_id FROM page_environments LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    // Try to find any project page and environment to create a mock record
    $pageId = $db->query("SELECT id FROM project_pages LIMIT 1")->fetchColumn();
    $envId = $db->query("SELECT id FROM testing_environments LIMIT 1")->fetchColumn();
    
    if ($pageId && $envId) {
        $db->prepare("INSERT IGNORE INTO page_environments (page_id, environment_id, status) VALUES (?, ?, 'not_started')")->execute([$pageId, $envId]);
        $row = ['page_id' => $pageId, 'environment_id' => $envId];
    } else {
        echo "No Page/Environment data found to verify fix.\n";
        exit;
    }
}

$pageId = $row['page_id'];
$envId = $row['environment_id'];

echo "Testing update for page_id=$pageId, env_id=$envId with user_id=$userId\n";

// Emulate POST request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'mock_token';

try {
    $statusType = 'testing';
    $status = 'completed';
    
    $columnName = 'status';
    $updateStmt = $db->prepare("
        UPDATE page_environments 
        SET $columnName = ?, last_updated_by = ?, last_updated_at = NOW() 
        WHERE page_id = ? AND environment_id = ?
    ");
    $ok = $updateStmt->execute([$status, $userId, $pageId, $envId]);
    
    if ($ok) {
        echo "Successfully updated status to 'completed' without errors.\n";
    } else {
        echo "Failed to update status.\n";
    }
} catch (Exception $e) {
    echo "Caught exception: " . $e->getMessage() . "\n";
}
