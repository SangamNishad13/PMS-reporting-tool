<?php
require_once 'config/database.php';
$db = Database::getInstance();
$stmt = $db->prepare('SELECT id, username, role FROM users WHERE role = "client" AND is_active = 1 LIMIT 3');
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($users as $user) {
    echo "ID: {$user['id']}, Username: {$user['username']}, Role: {$user['role']}\n";
}
?>