<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

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
$can_manage_devices = in_array($user_role, ['admin', 'super_admin']) || !empty($_SESSION['can_manage_devices']);
$baseDir = getBaseDir();
$devicesLink = $baseDir . '/modules/devices.php';
$adminDevicesLink = $baseDir . '/modules/admin/devices.php';

function notifyAdmins($db, $message, $link) {
    try {
        $stmt = $db->query("SELECT id FROM users WHERE role IN ('admin','super_admin') AND is_active = 1");
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $adminId) {
            createNotification($db, (int)$adminId, 'system', $message, $link);
        }
    } catch (Exception $e) {
        error_log('notifyAdmins failed: ' . $e->getMessage());
    }
}

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
            if (!$can_manage_devices) {
                throw new Exception('You do not have permission to add devices');
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
            if (!$can_manage_devices) {
                throw new Exception('You do not have permission to update devices');
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
            if (!$can_manage_devices) {
                throw new Exception('You do not have permission to delete devices');
            }
            
            $stmt = $pdo->prepare("DELETE FROM devices WHERE id = ?");
            $stmt->execute([$_POST['device_id']]);
            
            echo json_encode(['success' => true, 'message' => 'Device deleted successfully']);
            break;

        case 'assign_device':
            if (!$can_manage_devices) {
                throw new Exception('You do not have permission to assign devices');
            }

            // Fetch device and user names for notifications
            $deviceStmt = $pdo->prepare("SELECT device_name, device_type FROM devices WHERE id = ? LIMIT 1");
            $deviceStmt->execute([$_POST['device_id']]);
            $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);
            $deviceLabel = $deviceRow ? ($deviceRow['device_name'] . ' (' . $deviceRow['device_type'] . ')') : 'device';
            $actorStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            $actorStmt->execute([$user_id]);
            $actorRow = $actorStmt->fetch(PDO::FETCH_ASSOC);
            $actorName = $actorRow['full_name'] ?? 'Admin';
            
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

            // Notifications: assigned user + previous holder (if any)
            createNotification(
                $pdo,
                (int)$_POST['user_id'],
                'system',
                'Device assigned: ' . $deviceLabel . ' (by ' . $actorName . ')',
                $devicesLink
            );
            if (!empty($from_user_id) && (int)$from_user_id !== (int)$_POST['user_id']) {
                createNotification(
                    $pdo,
                    (int)$from_user_id,
                    'system',
                    'Device removed: ' . $deviceLabel . ' (by ' . $actorName . ')',
                    $devicesLink
                );
            }
            echo json_encode(['success' => true, 'message' => 'Device assigned successfully']);
            break;

        case 'return_device':
            if (!$can_manage_devices) {
                throw new Exception('You do not have permission to process device returns');
            }

            // Fetch device and current holder for notifications
            $deviceStmt = $pdo->prepare("SELECT device_name, device_type FROM devices WHERE id = ? LIMIT 1");
            $deviceStmt->execute([$_POST['device_id']]);
            $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);
            $deviceLabel = $deviceRow ? ($deviceRow['device_name'] . ' (' . $deviceRow['device_type'] . ')') : 'device';
            $actorStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            $actorStmt->execute([$user_id]);
            $actorRow = $actorStmt->fetch(PDO::FETCH_ASSOC);
            $actorName = $actorRow['full_name'] ?? 'Admin';
            $holderStmt = $pdo->prepare("SELECT user_id FROM device_assignments WHERE device_id = ? AND status = 'Active' LIMIT 1");
            $holderStmt->execute([$_POST['device_id']]);
            $holderRow = $holderStmt->fetch(PDO::FETCH_ASSOC);
            $holderId = $holderRow['user_id'] ?? null;
            
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

            if (!empty($holderId)) {
                createNotification(
                    $pdo,
                    (int)$holderId,
                    'system',
                    'Device removed: ' . $deviceLabel . ' (by ' . $actorName . ')',
                    $devicesLink
                );
            }
            notifyAdmins(
                $pdo,
                'Device returned to office: ' . $deviceLabel . ' (by ' . $actorName . ')',
                $adminDevicesLink
            );
            echo json_encode(['success' => true, 'message' => 'Device returned successfully']);
            break;

        case 'submit_device':
            // User submits their assigned device back to office
            $device_id = (int)($_POST['device_id'] ?? 0);
            if (!$device_id) {
                throw new Exception('Device ID is required');
            }

            // Verify this device is assigned to current user
            $stmt = $pdo->prepare("
                SELECT id FROM device_assignments
                WHERE device_id = ? AND user_id = ? AND status = 'Active'
                LIMIT 1
            ");
            $stmt->execute([$device_id, $user_id]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$assignment) {
                throw new Exception('Device is not assigned to you');
            }

            $deviceStmt = $pdo->prepare("SELECT device_name, device_type FROM devices WHERE id = ? LIMIT 1");
            $deviceStmt->execute([$device_id]);
            $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);
            $deviceLabel = $deviceRow ? ($deviceRow['device_name'] . ' (' . $deviceRow['device_type'] . ')') : 'device';

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE device_assignments
                SET status = 'Returned', returned_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$assignment['id']]);

            $stmt = $pdo->prepare("UPDATE devices SET status = 'Available' WHERE id = ?");
            $stmt->execute([$device_id]);

            $pdo->commit();
            createNotification(
                $pdo,
                (int)$user_id,
                'system',
                'You submitted ' . $deviceLabel . ' to office. Status is now Available.',
                $devicesLink
            );
            notifyAdmins(
                $pdo,
                'Device submitted to office: ' . $deviceLabel,
                $adminDevicesLink
            );
            echo json_encode(['success' => true, 'message' => 'Device submitted to office successfully']);
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

            // Notify current holder and admins
            try {
                $deviceStmt = $pdo->prepare("SELECT device_name, device_type FROM devices WHERE id = ? LIMIT 1");
                $deviceStmt->execute([$device_id]);
                $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);
                $deviceLabel = $deviceRow ? ($deviceRow['device_name'] . ' (' . $deviceRow['device_type'] . ')') : 'device';
                $requesterStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
                $requesterStmt->execute([$user_id]);
                $requesterRow = $requesterStmt->fetch(PDO::FETCH_ASSOC);
                $requesterName = $requesterRow['full_name'] ?? 'User';

                createNotification(
                    $pdo,
                    (int)$current['user_id'],
                    'system',
                    $requesterName . ' requested your device: ' . $deviceLabel,
                    $devicesLink
                );

                notifyAdmins(
                    $pdo,
                    'Device switch request: ' . $requesterName . ' requested ' . $deviceLabel,
                    $adminDevicesLink
                );
            } catch (Exception $e) {
                error_log('request_switch notify failed: ' . $e->getMessage());
            }
            
            echo json_encode(['success' => true, 'message' => 'Switch request submitted successfully']);
            break;

        case 'request_available':
            $device_id = (int)($_POST['device_id'] ?? 0);
            if (!$device_id) {
                throw new Exception('Device ID is required');
            }

            // Ensure device is available and not assigned
            $stmt = $pdo->prepare("SELECT status, device_name, device_type FROM devices WHERE id = ? LIMIT 1");
            $stmt->execute([$device_id]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$device) {
                throw new Exception('Device not found');
            }
            if ($device['status'] !== 'Available') {
                throw new Exception('Device is not available');
            }

            $stmt = $pdo->prepare("SELECT id FROM device_assignments WHERE device_id = ? AND status = 'Active' LIMIT 1");
            $stmt->execute([$device_id]);
            if ($stmt->fetch()) {
                throw new Exception('Device is not currently available');
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
                VALUES (?, ?, NULL, ?)
            ");
            $stmt->execute([
                $device_id,
                $user_id,
                $_POST['reason'] ?? null
            ]);

            $deviceLabel = $device['device_name'] . ' (' . $device['device_type'] . ')';
            notifyAdmins(
                $pdo,
                'New request for available device: ' . $deviceLabel,
                $adminDevicesLink
            );

            echo json_encode(['success' => true, 'message' => 'Request submitted to admin']);
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
                    LEFT JOIN users u2 ON dsr.current_holder = u2.id
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
                    LEFT JOIN users u2 ON dsr.current_holder = u2.id
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
            $response_action = $_POST['response_action'] ?? '';
            $response = $_POST['response'] ?? '';
            if ($response_action === 'approve') {
                $response = 'Approved';
            } elseif ($response_action === 'reject') {
                $response = 'Rejected';
            }
            if ($response !== 'Approved' && $response !== 'Rejected') {
                throw new Exception('Invalid response');
            }
            
            // Get request details first
            $stmt = $pdo->prepare("SELECT * FROM device_switch_requests WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            if (!$request) {
                throw new Exception('Request not found or already processed');
            }
            
            // Check if user is admin OR the current device holder
            if (!$can_manage_devices && $request['current_holder'] != $user_id) {
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
                
                // Return device from current holder (if any)
                if (!empty($request['current_holder'])) {
                    $stmt = $pdo->prepare("
                        UPDATE device_assignments 
                        SET status = 'Returned', returned_at = NOW()
                        WHERE device_id = ? AND status = 'Active'
                    ");
                    $stmt->execute([$request['device_id']]);
                }
                
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
                    empty($request['current_holder']) ? 'Available Device Request Approved' : 'Switch Request Approved',
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
                    $devicesLink
                ]);
                
                // Create notification for previous holder (device reassigned) - only if not the one who approved
                if (!empty($request['current_holder']) && $request['current_holder'] != $user_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, message, link, is_read)
                        VALUES (?, 'system', ?, ?, FALSE)
                    ");
                    $stmt->execute([
                        $request['current_holder'],
                        $device_name . ' has been reassigned to ' . $requester_name,
                        $devicesLink
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
                    $devicesLink
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
