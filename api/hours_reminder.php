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
        case 'check_reminder_time':
            // Check if it's time to show reminder
            $stmt = $pdo->query("SELECT * FROM hours_reminder_settings WHERE enabled = TRUE LIMIT 1");
            $settings = $stmt->fetch();
            
            if (!$settings) {
                echo json_encode(['success' => true, 'show_reminder' => false]);
                break;
            }
            
            $currentTime = date('H:i:s');
            $reminderTime = $settings['reminder_time'];
            $minimumHours = $settings['minimum_hours'];
            
            // Check if current time is within 5 minutes of reminder time
            $current = strtotime($currentTime);
            $reminder = strtotime($reminderTime);
            $diff = abs($current - $reminder);
            
            $showReminder = false;
            $message = '';
            
            if ($diff <= 300) { // Within 5 minutes
                // Check today's hours for current user
                $today = date('Y-m-d');
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(hours_spent), 0) as total_hours
                    FROM project_time_logs
                    WHERE user_id = ? AND DATE(log_date) = ?
                ");
                $stmt->execute([$user_id, $today]);
                $result = $stmt->fetch();
                $totalHours = $result['total_hours'];
                
                if ($totalHours < $minimumHours) {
                    // Check if reminder already sent today
                    $stmt = $pdo->prepare("
                        SELECT id FROM daily_hours_compliance
                        WHERE user_id = ? AND date = ? AND reminder_sent = TRUE
                    ");
                    $stmt->execute([$user_id, $today]);
                    
                    if (!$stmt->fetch()) {
                        $showReminder = true;
                        $message = $settings['notification_message'];
                        $hoursNeeded = $minimumHours - $totalHours;
                        $message .= " You have logged {$totalHours} hours. {$hoursNeeded} more hours needed.";
                        
                        // Mark reminder as sent
                        $stmt = $pdo->prepare("
                            INSERT INTO daily_hours_compliance 
                            (user_id, date, total_hours, is_compliant, reminder_sent, reminder_sent_at)
                            VALUES (?, ?, ?, FALSE, TRUE, NOW())
                            ON DUPLICATE KEY UPDATE 
                            reminder_sent = TRUE, 
                            reminder_sent_at = NOW(),
                            total_hours = ?
                        ");
                        $stmt->execute([$user_id, $today, $totalHours, $totalHours]);
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'show_reminder' => $showReminder,
                'message' => $message,
                'current_hours' => $totalHours ?? 0,
                'minimum_hours' => $minimumHours
            ]);
            break;

        case 'get_compliance_report':
            if (!in_array($user_role, ['admin', 'super_admin'])) {
                throw new Exception('Only admins can view compliance reports');
            }
            
            $date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
            
            // Get all active users (excluding admins)
            $stmt = $pdo->prepare("
                SELECT 
                    u.id,
                    u.username,
                    u.full_name,
                    u.email,
                    u.role,
                    COALESCE(SUM(ptl.hours_spent), 0) as total_hours,
                    dhc.is_compliant,
                    dhc.reminder_sent,
                    dhc.reminder_sent_at
                FROM users u
                LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND DATE(ptl.log_date) = ?
                LEFT JOIN daily_hours_compliance dhc ON u.id = dhc.user_id AND dhc.date = ?
                WHERE u.is_active = TRUE AND u.role NOT IN ('super_admin', 'admin')
                GROUP BY u.id
                ORDER BY total_hours ASC, u.full_name
            ");
            $stmt->execute([$date, $date]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get settings
            $stmt = $pdo->query("SELECT minimum_hours FROM hours_reminder_settings LIMIT 1");
            $settings = $stmt->fetch();
            $minimumHours = $settings['minimum_hours'] ?? 8;
            
            // Categorize users
            $nonCompliant = [];
            $compliant = [];
            
            foreach ($users as $user) {
                $user['is_compliant'] = $user['total_hours'] >= $minimumHours;
                if ($user['is_compliant']) {
                    $compliant[] = $user;
                } else {
                    $nonCompliant[] = $user;
                }
            }
            
            echo json_encode([
                'success' => true,
                'date' => $date,
                'minimum_hours' => $minimumHours,
                'non_compliant' => $nonCompliant,
                'compliant' => $compliant,
                'summary' => [
                    'total_users' => count($users),
                    'compliant_count' => count($compliant),
                    'non_compliant_count' => count($nonCompliant),
                    'compliance_rate' => count($users) > 0 ? round((count($compliant) / count($users)) * 100, 2) : 0
                ]
            ]);
            break;

        case 'update_settings':
            if (!in_array($user_role, ['admin', 'super_admin'])) {
                throw new Exception('Only admins can update settings');
            }
            
            $stmt = $pdo->prepare("
                UPDATE hours_reminder_settings 
                SET reminder_time = ?, 
                    minimum_hours = ?, 
                    enabled = ?,
                    notification_message = ?
                WHERE id = 1
            ");
            $stmt->execute([
                $_POST['reminder_time'],
                $_POST['minimum_hours'],
                $_POST['enabled'] ? 1 : 0,
                $_POST['notification_message']
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
            break;

        case 'get_settings':
            if (!in_array($user_role, ['admin', 'super_admin'])) {
                throw new Exception('Only admins can view settings');
            }
            
            $stmt = $pdo->query("SELECT * FROM hours_reminder_settings LIMIT 1");
            $settings = $stmt->fetch();
            
            echo json_encode(['success' => true, 'settings' => $settings]);
            break;

        case 'dismiss_reminder':
            // User dismissed the reminder
            $today = date('Y-m-d');
            $stmt = $pdo->prepare("
                UPDATE daily_hours_compliance 
                SET reminder_sent = TRUE, reminder_sent_at = NOW()
                WHERE user_id = ? AND date = ?
            ");
            $stmt->execute([$user_id, $today]);
            
            echo json_encode(['success' => true, 'message' => 'Reminder dismissed']);
            break;

        case 'get_my_hours_today':
            $today = date('Y-m-d');
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(hours_spent), 0) as total_hours
                FROM project_time_logs
                WHERE user_id = ? AND DATE(log_date) = ?
            ");
            $stmt->execute([$user_id, $today]);
            $result = $stmt->fetch();
            
            $stmt = $pdo->query("SELECT minimum_hours FROM hours_reminder_settings LIMIT 1");
            $settings = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'total_hours' => $result['total_hours'],
                'minimum_hours' => $settings['minimum_hours'] ?? 8,
                'is_compliant' => $result['total_hours'] >= ($settings['minimum_hours'] ?? 8)
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
