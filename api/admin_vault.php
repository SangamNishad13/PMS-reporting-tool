<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Only admins can access
if (!in_array($user_role, ['admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Simple encryption/decryption (use stronger encryption in production)
function encryptPassword($password) {
    $key = 'your-secret-key-change-this'; // Change this in production
    return base64_encode(openssl_encrypt($password, 'AES-128-ECB', $key));
}

function decryptPassword($encrypted) {
    $key = 'your-secret-key-change-this'; // Change this in production
    return openssl_decrypt(base64_decode($encrypted), 'AES-128-ECB', $key);
}

try {
    switch ($action) {
        // ===== CREDENTIALS =====
        case 'get_credentials':
            $stmt = $pdo->prepare("
                SELECT id, title, category, username, url, notes, tags, last_used, created_at, updated_at
                FROM admin_credentials
                WHERE admin_id = ?
                ORDER BY category, title
            ");
            $stmt->execute([$user_id]);
            $credentials = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'credentials' => $credentials]);
            break;

        case 'get_credential_password':
            $stmt = $pdo->prepare("SELECT password_encrypted FROM admin_credentials WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_GET['id'], $user_id]);
            $result = $stmt->fetch();
            if ($result) {
                $password = decryptPassword($result['password_encrypted']);
                echo json_encode(['success' => true, 'password' => $password]);
            } else {
                throw new Exception('Credential not found');
            }
            break;

        case 'add_credential':
            $stmt = $pdo->prepare("
                INSERT INTO admin_credentials (admin_id, title, category, username, password_encrypted, url, notes, tags)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $encrypted = encryptPassword($_POST['password']);
            $stmt->execute([
                $user_id,
                $_POST['title'],
                $_POST['category'],
                $_POST['username'] ?? null,
                $encrypted,
                $_POST['url'] ?? null,
                $_POST['notes'] ?? null,
                $_POST['tags'] ?? null
            ]);
            echo json_encode(['success' => true, 'message' => 'Credential added', 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_credential':
            $sql = "UPDATE admin_credentials SET title = ?, category = ?, username = ?, url = ?, notes = ?, tags = ?";
            $params = [
                $_POST['title'],
                $_POST['category'],
                $_POST['username'] ?? null,
                $_POST['url'] ?? null,
                $_POST['notes'] ?? null,
                $_POST['tags'] ?? null
            ];
            
            if (!empty($_POST['password'])) {
                $sql .= ", password_encrypted = ?";
                $params[] = encryptPassword($_POST['password']);
            }
            
            $sql .= " WHERE id = ? AND admin_id = ?";
            $params[] = $_POST['id'];
            $params[] = $user_id;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Credential updated']);
            break;

        case 'delete_credential':
            $stmt = $pdo->prepare("DELETE FROM admin_credentials WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Credential deleted']);
            break;

        case 'mark_credential_used':
            $stmt = $pdo->prepare("UPDATE admin_credentials SET last_used = NOW() WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Marked as used']);
            break;

        // ===== NOTES =====
        case 'get_notes':
            $stmt = $pdo->prepare("
                SELECT * FROM admin_notes
                WHERE admin_id = ?
                ORDER BY is_pinned DESC, updated_at DESC
            ");
            $stmt->execute([$user_id]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'notes' => $notes]);
            break;

        case 'add_note':
            $stmt = $pdo->prepare("
                INSERT INTO admin_notes (admin_id, title, content, category, color, is_pinned, tags)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $_POST['title'],
                $_POST['content'] ?? null,
                $_POST['category'] ?? 'General',
                $_POST['color'] ?? '#ffffff',
                $_POST['is_pinned'] ?? 0,
                $_POST['tags'] ?? null
            ]);
            echo json_encode(['success' => true, 'message' => 'Note added', 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_note':
            $stmt = $pdo->prepare("
                UPDATE admin_notes 
                SET title = ?, content = ?, category = ?, color = ?, is_pinned = ?, tags = ?
                WHERE id = ? AND admin_id = ?
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['content'] ?? null,
                $_POST['category'] ?? 'General',
                $_POST['color'] ?? '#ffffff',
                $_POST['is_pinned'] ?? 0,
                $_POST['tags'] ?? null,
                $_POST['id'],
                $user_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Note updated']);
            break;

        case 'delete_note':
            $stmt = $pdo->prepare("DELETE FROM admin_notes WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Note deleted']);
            break;

        // ===== TODOS =====
        case 'get_todos':
            $stmt = $pdo->prepare("
                SELECT * FROM admin_todos
                WHERE admin_id = ?
                ORDER BY 
                    CASE status 
                        WHEN 'Pending' THEN 1 
                        WHEN 'In Progress' THEN 2 
                        WHEN 'Completed' THEN 3 
                        ELSE 4 
                    END,
                    CASE priority 
                        WHEN 'Urgent' THEN 1 
                        WHEN 'High' THEN 2 
                        WHEN 'Medium' THEN 3 
                        ELSE 4 
                    END,
                    due_date ASC
            ");
            $stmt->execute([$user_id]);
            $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'todos' => $todos]);
            break;

        case 'add_todo':
            $stmt = $pdo->prepare("
                INSERT INTO admin_todos (admin_id, title, description, priority, status, due_date, tags)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $_POST['title'],
                $_POST['description'] ?? null,
                $_POST['priority'] ?? 'Medium',
                $_POST['status'] ?? 'Pending',
                $_POST['due_date'] ?? null,
                $_POST['tags'] ?? null
            ]);
            echo json_encode(['success' => true, 'message' => 'Todo added', 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_todo':
            $stmt = $pdo->prepare("
                UPDATE admin_todos 
                SET title = ?, description = ?, priority = ?, status = ?, due_date = ?, tags = ?,
                    completed_at = CASE WHEN ? = 'Completed' AND status != 'Completed' THEN NOW() ELSE completed_at END
                WHERE id = ? AND admin_id = ?
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'] ?? null,
                $_POST['priority'] ?? 'Medium',
                $_POST['status'] ?? 'Pending',
                $_POST['due_date'] ?? null,
                $_POST['tags'] ?? null,
                $_POST['status'] ?? 'Pending',
                $_POST['id'],
                $user_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Todo updated']);
            break;

        case 'delete_todo':
            $stmt = $pdo->prepare("DELETE FROM admin_todos WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Todo deleted']);
            break;

        // ===== MEETINGS =====
        case 'get_meetings':
            $stmt = $pdo->prepare("
                SELECT * FROM admin_meetings
                WHERE admin_id = ?
                ORDER BY meeting_date DESC, meeting_time DESC
            ");
            $stmt->execute([$user_id]);
            $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'meetings' => $meetings]);
            break;

        case 'add_meeting':
            $stmt = $pdo->prepare("
                INSERT INTO admin_meetings (admin_id, title, description, meeting_with, meeting_date, meeting_time, 
                                           duration_minutes, location, meeting_link, reminder_minutes, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $_POST['title'],
                $_POST['description'] ?? null,
                $_POST['meeting_with'] ?? null,
                $_POST['meeting_date'],
                $_POST['meeting_time'],
                $_POST['duration_minutes'] ?? 30,
                $_POST['location'] ?? null,
                $_POST['meeting_link'] ?? null,
                $_POST['reminder_minutes'] ?? 15,
                $_POST['notes'] ?? null
            ]);
            echo json_encode(['success' => true, 'message' => 'Meeting added', 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_meeting':
            $stmt = $pdo->prepare("
                UPDATE admin_meetings 
                SET title = ?, description = ?, meeting_with = ?, meeting_date = ?, meeting_time = ?,
                    duration_minutes = ?, location = ?, meeting_link = ?, reminder_minutes = ?, status = ?, notes = ?
                WHERE id = ? AND admin_id = ?
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'] ?? null,
                $_POST['meeting_with'] ?? null,
                $_POST['meeting_date'],
                $_POST['meeting_time'],
                $_POST['duration_minutes'] ?? 30,
                $_POST['location'] ?? null,
                $_POST['meeting_link'] ?? null,
                $_POST['reminder_minutes'] ?? 15,
                $_POST['status'] ?? 'Scheduled',
                $_POST['notes'] ?? null,
                $_POST['id'],
                $user_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Meeting updated']);
            break;

        case 'delete_meeting':
            $stmt = $pdo->prepare("DELETE FROM admin_meetings WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Meeting deleted']);
            break;

        // ===== DEVICE ROTATION HISTORY =====
        case 'get_device_rotation_history':
            $device_id = $_GET['device_id'] ?? null;
            if ($device_id) {
                $stmt = $pdo->prepare("
                    SELECT drh.*, 
                           d.device_name, d.device_type,
                           u1.full_name as from_user_name,
                           u2.full_name as to_user_name,
                           u3.full_name as rotated_by_name
                    FROM device_rotation_history drh
                    JOIN devices d ON drh.device_id = d.id
                    LEFT JOIN users u1 ON drh.from_user_id = u1.id
                    JOIN users u2 ON drh.to_user_id = u2.id
                    JOIN users u3 ON drh.rotated_by = u3.id
                    WHERE drh.device_id = ?
                    ORDER BY drh.rotation_date DESC
                ");
                $stmt->execute([$device_id]);
            } else {
                $stmt = $pdo->query("
                    SELECT drh.*, 
                           d.device_name, d.device_type,
                           u1.full_name as from_user_name,
                           u2.full_name as to_user_name,
                           u3.full_name as rotated_by_name
                    FROM device_rotation_history drh
                    JOIN devices d ON drh.device_id = d.id
                    LEFT JOIN users u1 ON drh.from_user_id = u1.id
                    JOIN users u2 ON drh.to_user_id = u2.id
                    JOIN users u3 ON drh.rotated_by = u3.id
                    ORDER BY drh.rotation_date DESC
                    LIMIT 100
                ");
            }
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
