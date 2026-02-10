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

try {
    switch ($action) {
        case 'get_users':
            $stmt = $pdo->query("
                SELECT id, username, full_name, email, role 
                FROM users 
                WHERE is_active = TRUE
                ORDER BY full_name, username
            ");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'users' => $users]);
            break;

        case 'get_all_devices':
            $stmt = $pdo->query("
                SELECT d.*, 
                       da.user_id as assigned_user_id,
                       u.username as assigned_to,
                       u.full_name as assigned_to_name,
                       da.assigned_at
                FROM devices d
                LEFT JOIN device_assignments da ON d.id = da.device_id AND da.status = 'Active'
                LEFT JOIN users u ON da.user_id = u.id
                ORDER BY d.device_type, d.device_name
            ");
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'devices' => $devices]);
            break;

        case 'add_device':
            if ($user_role !== 'admin') {
                throw new Exception('Only admins can add devices');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO devices (device_name, device_type, model, version, serial_number, purchase_date, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['device_name'],
                $_POST['device_type'],
                $_POST['model'] ?? null,
                $_POST['version'] ?? null,
                $_POST['serial_number'] ?? null,
                $_POST['purchase_date'] ?? null,
                $_POST['status'] ?? 'Available',
                $_POST['notes'] ?? null
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Device added successfully', 'device_id' => $pdo->lastInsertId()]);
            break;

        case 'update_device':
            if ($user_role !== 'admin') {
                throw new Exception('Only admins can update devices');
            }
            
            $stmt = $pdo->prepare("
                UPDATE devices 
                SET device_name = ?, device_type = ?, model = ?, version = ?, 
                    serial_number = ?, purchase_date = ?, status = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['device_name'],
                $_POST['device_type'],
                $_POST['model'] ?? null,
                $_POST['version'] ?? null,
                $_POST['serial_number'] ?? null,
                $_POST['purchase_date'] ?? null,
                $_POST['status'],
                $_POST['notes'] ?? null,
                $_POST['device_id']
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Device updated successfully']);
            break;

        case 'delete_device':
            if ($user_role !== 'admin') {
                throw new Exception('Only admins can delete devices');
            }
            
            $stmt = $pdo->prepare("DELETE FROM devices WHERE id = ?");
            $stmt->execute([$_POST['device_id']]);
            
            echo json_encode(['success' => true, 'message' => 'Device deleted successfully']);
            break;

        case 'assign_device':
            if ($user_role !== 'admin') {
                throw new Exception('Only admins can assign devices');
            }
            
            $pdo->beginTransaction();
            
            // Get current assignment
            $stmt = $pdo->prepare("
                SELECT user_id FROM device_assignments 
                WHERE device_id = ? AND status = 'Active'
            ");
            $stmt->execute([$_POST['device_id']]);
            $currentAssignment = $stmt->fetch();
            $from_user_id = $currentAssignment['user_id'] ?? null;
            
            // Return current assignment if exists
            $stmt = $pdo->prepare("
                UPDATE device_assignments 
                SET status = 'Returned', returned_at = NOW()
                WHERE device_id = ? AND status = 'Active'
            ");
            $stmt->execute([$_POST['device_id']]);
            
            // Create new assignment
            $stmt = $pdo->prepare("
                INSERT INTO device_assignments (device_id, user_id, assigned_by, notes)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['device_id'],
                $_POST['user_id'],
                $user_id,
                $_POST['notes'] ?? null
            ]);
            
            // Update device status
            $stmt = $pdo->prepare("UPDATE devices SET status = 'Assigned' WHERE id = ?");
            $stmt->execute([$_POST['device_id']]);
            
            // Log rotation history
            $stmt = $pdo->prepare("
                INSERT INTO device_rotation_history (device_id, from_user_id, to_user_id, rotated_by, reason, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['device_id'],
                $from_user_id,
                $_POST['user_id'],
                $user_id,
                'Admin Assignment',
                $_POST['notes'] ?? null
            ]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Device assigned successfully']);
            break;

        case 'return_device':
            if ($user_role !== 'admin') {
                throw new Exception('Only admins can process device returns');
            }
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                UPDATE device_assignments 
                SET status = 'Returned', returned_at = NOW()
                WHERE device_id = ? AND status = 'Active'
            ");
            $stmt->execute([$_POST['device_id']]);
            
            $stmt = $pdo->prepare("UPDATE devices SET status = 'Available' WHERE id = ?");
            $stmt->execute([$_POST['device_id']]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Device returned successfully']);
            break;

        case 'request_switch':
            $device_id = $_POST['device_id'];
            
            // Get current holder
            $stmt = $pdo->prepare("
                SELECT user_id FROM device_assignments 
                WHERE device_id = ? AND status = 'Active'
            ");
            $stmt->execute([$device_id]);
            $current = $stmt->fetch();
            
            if (!$current) {
                throw new Exception('Device is not currently assigned');
            }
            
            if ($current['user_id'] == $user_id) {
                throw new Exception('You already have this device');
            }
            
            // Check for existing pending request
            $stmt = $pdo->prepare("
                SELECT id FROM device_switch_requests 
                WHERE device_id = ? AND requested_by = ? AND status = 'Pending'
            ");
            $stmt->execute([$device_id, $user_id]);
            if ($stmt->fetch()) {
                throw new Exception('You already have a pending request for this device');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO device_switch_requests (device_id, requested_by, current_holder, reason)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $device_id,
                $user_id,
                $current['user_id'],
                $_POST['reason'] ?? null
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Switch request submitted successfully']);
            break;

        case 'cancel_request':
            $request_id = $_POST['request_id'];
            
            // Verify the request belongs to the user
            $stmt = $pdo->prepare("
                SELECT * FROM device_switch_requests 
                WHERE id = ? AND requested_by = ? AND status = 'Pending'
            ");
            $stmt->execute([$request_id, $user_id]);
            $request = $stmt->fetch();
            
            if (!$request) {
                throw new Exception('Request not found or cannot be cancelled');
            }
            
            // Update request status
            $stmt = $pdo->prepare("
                UPDATE device_switch_requests 
                SET status = 'Cancelled', responded_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$request_id]);
            
            echo json_encode(['success' => true, 'message' => 'Request cancelled successfully']);
            break;

        case 'get_switch_requests':
            if ($user_role === 'admin') {
                $stmt = $pdo->query("
                    SELECT dsr.*, 
                           d.device_name, d.device_type, d.model,
                           u1.username as requester_name, u1.full_name as requester_full_name,
                           u2.username as holder_name, u2.full_name as holder_full_name
                    FROM device_switch_requests dsr
                    JOIN devices d ON dsr.device_id = d.id
                    JOIN users u1 ON dsr.requested_by = u1.id
                    JOIN users u2 ON dsr.current_holder = u2.id
                    ORDER BY dsr.requested_at DESC
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT dsr.*, 
                           d.device_name, d.device_type, d.model,
                           u1.username as requester_name, u1.full_name as requester_full_name,
                           u2.username as holder_name, u2.full_name as holder_full_name
                    FROM device_switch_requests dsr
                    JOIN devices d ON dsr.device_id = d.id
                    JOIN users u1 ON dsr.requested_by = u1.id
                    JOIN users u2 ON dsr.current_holder = u2.id
                    WHERE dsr.requested_by = ? OR dsr.current_holder = ?
                    ORDER BY dsr.requested_at DESC
                ");
                $stmt->execute([$user_id, $user_id]);
            }
            
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'requests' => $requests]);
            break;

        case 'respond_to_request':
            $request_id = $_POST['request_id'];
            $response_action = $_POST['response_action']; // 'approve' or 'reject'
            $response = $response_action === 'approve' ? 'Approved' : 'Rejected';
            
            // Get request details first
            $stmt = $pdo->prepare("SELECT * FROM device_switch_requests WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            if (!$request) {
                throw new Exception('Request not found or already processed');
            }
            
            // Check if user is admin OR the current device holder
            if ($user_role !== 'admin' && $request['current_holder'] != $user_id) {
                throw new Exception('You do not have permission to respond to this request');
            }
            
            $pdo->beginTransaction();
            
            if ($response === 'Approved') {
                // Get device name for notifications
                $stmt = $pdo->prepare("SELECT device_name, device_type FROM devices WHERE id = ?");
                $stmt->execute([$request['device_id']]);
                $device = $stmt->fetch();
                $device_name = $device['device_name'] . ' (' . $device['device_type'] . ')';
                
                // Get requester name
                $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmt->execute([$request['requested_by']]);
                $requester = $stmt->fetch();
                $requester_name = $requester['full_name'];
                
                // Return device from current holder
                $stmt = $pdo->prepare("
                    UPDATE device_assignments 
                    SET status = 'Returned', returned_at = NOW()
                    WHERE device_id = ? AND status = 'Active'
                ");
                $stmt->execute([$request['device_id']]);
                
                // Assign to requester
                $stmt = $pdo->prepare("
                    INSERT INTO device_assignments (device_id, user_id, assigned_by, notes)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $request['device_id'], 
                    $request['requested_by'], 
                    $user_id,
                    'Approved switch request: ' . ($request['reason'] ?? '')
                ]);
                
                // Log rotation history
                $stmt = $pdo->prepare("
                    INSERT INTO device_rotation_history (device_id, from_user_id, to_user_id, rotated_by, reason, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $request['device_id'],
                    $request['current_holder'],
                    $request['requested_by'],
                    $user_id,
                    'Switch Request Approved',
                    'Original request reason: ' . ($request['reason'] ?? 'No reason provided') . 
                    '. Response: ' . ($_POST['response_notes'] ?? 'No notes')
                ]);
                
                // Update device status
                $stmt = $pdo->prepare("UPDATE devices SET status = 'Assigned' WHERE id = ?");
                $stmt->execute([$request['device_id']]);
                
                // Create notification for requester (device request approved)
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, message, link, is_read)
                    VALUES (?, 'system', ?, ?, FALSE)
                ");
                $stmt->execute([
                    $request['requested_by'],
                    'Your device request for ' . $device_name . ' has been approved',
                    '/modules/devices.php'
                ]);
                
                // Create notification for previous holder (device reassigned) - only if not the one who approved
                if ($request['current_holder'] != $user_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, message, link, is_read)
                        VALUES (?, 'system', ?, ?, FALSE)
                    ");
                    $stmt->execute([
                        $request['current_holder'],
                        $device_name . ' has been reassigned to ' . $requester_name,
                        '/modules/devices.php'
                    ]);
                }
            } else {
                // Rejected - notify requester
                $stmt = $pdo->prepare("SELECT device_name, device_type FROM devices WHERE id = ?");
                $stmt->execute([$request['device_id']]);
                $device = $stmt->fetch();
                $device_name = $device['device_name'] . ' (' . $device['device_type'] . ')';
                
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, message, link, is_read)
                    VALUES (?, 'system', ?, ?, FALSE)
                ");
                $stmt->execute([
                    $request['requested_by'],
                    'Your device request for ' . $device_name . ' has been rejected',
                    '/modules/devices.php'
                ]);
            }
            
            // Update request status
            $stmt = $pdo->prepare("
                UPDATE device_switch_requests 
                SET status = ?, responded_at = NOW(), responded_by = ?, response_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$response, $user_id, $_POST['response_notes'] ?? null, $request_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Request ' . strtolower($response) . ' successfully']);
            break;

        case 'get_incoming_requests':
            // Get requests for devices currently held by this user
            $stmt = $pdo->prepare("
                SELECT dsr.*, 
                       d.device_name, d.device_type, d.model,
                       u1.username as requester_name, u1.full_name as requester_full_name
                FROM device_switch_requests dsr
                JOIN devices d ON dsr.device_id = d.id
                JOIN users u1 ON dsr.requested_by = u1.id
                WHERE dsr.current_holder = ?
                ORDER BY dsr.requested_at DESC
            ");
            $stmt->execute([$user_id]);
            
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'requests' => $requests]);
            break;

        case 'get_assignment_history':
            $device_id = $_GET['device_id'];
            
            $stmt = $pdo->prepare("
                SELECT da.*, 
                       u.username, u.full_name,
                       ab.username as assigned_by_name
                FROM device_assignments da
                JOIN users u ON da.user_id = u.id
                JOIN users ab ON da.assigned_by = ab.id
                WHERE da.device_id = ?
                ORDER BY da.assigned_at DESC
            ");
            $stmt->execute([$device_id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
