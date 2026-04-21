<?php
// Force host to localhost or 127.0.0.1
$dbName = 'athenaeu_project_management';
$dbUser = 'root'; // Try root for local XAMPP
$dbPass = ''; 
$dbHost = '127.0.0.1';

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $db = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    echo "--- Client Visible Statuses ---\n";
    $stmt = $db->query("SELECT id, name, visible_to_client FROM issue_statuses");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['id']} | Name: {$row['name']} | Visible to Client: {$row['visible_to_client']}\n";
    }
} catch (Exception $e) {
    echo "Connection failed (root/empty): " . $e->getMessage() . "\n";
    // Try original credentials
    try {
        $db = new PDO("mysql:host=localhost;dbname=athenaeu_project_management;charset=utf8mb4", 'athenaeu_pms', '$Sis@2026$', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "--- Client Visible Statuses (Live Creds) ---\n";
        $stmt = $db->query("SELECT id, name, visible_to_client FROM issue_statuses");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "ID: {$row['id']} | Name: {$row['name']} | Visible to Client: {$row['visible_to_client']}\n";
        }
    } catch (Exception $e2) {
        echo "Connection failed (live): " . $e2->getMessage() . "\n";
    }
}
