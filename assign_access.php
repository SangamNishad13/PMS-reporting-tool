<?php
try {
    $db = new PDO('mysql:host=localhost;dbname=project_management', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Get User ID
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute(['sangamnishad13@gmail.com']);
    $userId = $stmt->fetchColumn();
    
    if (!$userId) {
        die("User not found\n");
    }
    echo "User ID: $userId\n";
    
    // 2. Get a Project ID
    $stmt = $db->query("SELECT id, title, client_id FROM projects LIMIT 1");
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        die("No active projects found\n");
    }
    $projectId = $project['id'];
    $clientId = $project['client_id'];
    echo "Project: {$project['title']} (ID: $projectId, Client ID: $clientId)\n";
    
    // 3. Check for existing permission
    $stmt = $db->prepare("SELECT id FROM client_permissions WHERE user_id = ? AND project_id = ?");
    $stmt->execute([$userId, $projectId]);
    $existing = $stmt->fetchColumn();
    
    if ($existing) {
        echo "Access already exists (ID: $existing)\n";
    } else {
        // 4. Insert permission
        $stmt = $db->prepare("INSERT INTO client_permissions (client_id, project_id, user_id, permission_type, is_active) VALUES (?, ?, ?, 'view_project', 1)");
        $stmt->execute([$clientId, $projectId, $userId]);
        echo "Access granted successfully!\n";
    }
    
    // 5. Also insert into the newer table if it exists
    try {
        $db->query("SELECT 1 FROM client_project_assignments LIMIT 1");
        $stmt = $db->prepare("INSERT INTO client_project_assignments (client_user_id, project_id, assigned_by_admin_id, is_active) VALUES (?, ?, (SELECT id FROM users WHERE role='admin' LIMIT 1), 1)");
        $stmt->execute([$userId, $projectId]);
        echo "Also added to client_project_assignments.\n";
    } catch (Exception $e) {
        echo "client_project_assignments table skip: " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
