<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

echo "<h3>API Debug: hours_reminder.php</h3>";

try {
    require_once 'config/database.php';
    require_once 'includes/auth.php';
    require_once 'includes/helpers.php';
    
    $pdo = Database::getInstance();
    $date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
    
    echo "Date: $date<br>";
    echo "Checking tables...<br>";
    
    $tables = ['users', 'project_time_logs', 'daily_hours_compliance', 'hours_reminder_settings'];
    foreach ($tables as $t) {
        $db = Database::getInstance();
        try {
            $db->query("SELECT 1 FROM $t LIMIT 1");
            echo "✅ Table $t: OK<br>";
        } catch (Exception $e) {
            echo "❌ Table $t: FAILED (" . $e->getMessage() . ")<br>";
        }
    }
    
    echo "Running Compliance Query...<br>";
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
        WHERE u.is_active = TRUE AND u.role NOT IN ('admin')
        GROUP BY u.id
        ORDER BY total_hours ASC, u.full_name
    ");
    $stmt->execute([$date, $date]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ Query executed successfully. Count: " . count($users) . "<br>";

} catch (Throwable $t) {
    echo "❌ ERROR: " . $t->getMessage() . " on line " . $t->getLine() . " in " . $t->getFile() . "<br>";
    echo "<pre>" . $t->getTraceAsString() . "</pre>";
}
?>
