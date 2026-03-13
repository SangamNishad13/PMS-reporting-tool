<?php
require_once 'config/database.php';
$email = 'sangamnishad13@gmail.com';
$db = Database::getInstance();
$stmt = $db->prepare('SELECT id, role, is_active, account_setup_completed FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo "USER_DATA: " . json_encode($user) . "\n";
?>
