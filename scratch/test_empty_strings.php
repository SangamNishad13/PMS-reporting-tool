<?php
// Simulate testing devices API
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/project_permissions.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

// Pretend we are admin
$_POST = [
    'action' => 'add_device',
    'device_name' => 'Test Empty String Input',
    'device_type' => 'Android',
    'model' => '',
    'version' => '',
    'serial_number' => '',
    'purchase_date' => '', // This will fail if strict mode is ON and we don't handle '' -> NULL
    'status' => 'Available',
    'ownership_type' => 'Owned',
    'lease_owner' => '',
    'storage_capacity' => '', // This will fail if strict mode is ON and we don't handle '' -> NULL
    'charger_wire' => '',
    'notes' => ''
];

try {
    $stmt = $pdo->prepare("
        INSERT INTO devices (device_name, device_type, model, version, serial_number, purchase_date, status, ownership_type, lease_owner, storage_capacity, charger_wire, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_POST['device_name'],
        $_POST['device_type'],
        $_POST['model'] !== '' ? $_POST['model'] : null,
        $_POST['version'] !== '' ? $_POST['version'] : null,
        $_POST['serial_number'] !== '' ? $_POST['serial_number'] : null,
        $_POST['purchase_date'] !== '' ? $_POST['purchase_date'] : null,
        $_POST['status'] !== '' ? $_POST['status'] : 'Available',
        $_POST['ownership_type'] !== '' ? $_POST['ownership_type'] : 'Owned',
        $_POST['lease_owner'] !== '' ? $_POST['lease_owner'] : null,
        $_POST['storage_capacity'] !== '' ? (int)$_POST['storage_capacity'] : null,
        $_POST['charger_wire'] !== '' ? $_POST['charger_wire'] : null,
        $_POST['notes'] !== '' ? $_POST['notes'] : null
    ]);
    echo "Success! Device ID: " . $pdo->lastInsertId() . "\n";

    // Clean up
    $pdo->exec("DELETE FROM devices WHERE device_name = 'Test Empty String Input'");
} catch (Exception $e) {
    echo "Error inserting with empty string handling: " . $e->getMessage() . "\n";
}

// Now let's try the existing approach '?? null' which doesn't catch ''
try {
    $stmt->execute([
        'Test Empty String Failure',
        'Android',
        $_POST['model'] ?? null,
        $_POST['version'] ?? null,
        $_POST['serial_number'] ?? null,
        $_POST['purchase_date'] ?? null, // This resolves to '' not null
        $_POST['status'] ?? 'Available',
        $_POST['ownership_type'] ?? 'Owned',
        $_POST['lease_owner'] ?? null,
        $_POST['storage_capacity'] ?? null, // Resolves to '' not null
        $_POST['charger_wire'] ?? null,
        $_POST['notes'] ?? null
    ]);
    echo "Success with existing approach! Device ID: " . $pdo->lastInsertId() . "\n";
    $pdo->exec("DELETE FROM devices WHERE device_name = 'Test Empty String Failure'");
} catch (Exception $e) {
    echo "Failure with existing approach: " . $e->getMessage() . "\n";
}
