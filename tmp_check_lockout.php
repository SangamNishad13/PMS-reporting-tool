<?php
require_once __DIR__ . '/config/database.php';
try {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT * FROM login_attempts ORDER BY attempted_at DESC LIMIT 20");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Recent login attempts:\n";
    foreach ($rows as $row) {
        echo "ID: {$row['id']}, IP: {$row['ip_address']}, Hash: {$row['username_hash']}, Time: {$row['attempted_at']}\n";
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$ip]);
    echo "\nIP Block Check for $ip: " . $stmt->fetchColumn() . " attempts\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
