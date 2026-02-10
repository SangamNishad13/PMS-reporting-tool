<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

$keep = ['users'];

echo "Preparing to truncate all tables except: " . implode(', ', $keep) . "\n";

$tables = [];
$stmt = $db->query('SHOW TABLES');
foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
    $tables[] = $r[0];
}

$toTruncate = array_values(array_diff($tables, $keep));
if (empty($toTruncate)) {
    echo "No tables to truncate.\n";
    exit(0);
}

echo "Found " . count($toTruncate) . " tables to truncate.\n";

// Disable FK checks to allow truncation
$db->exec('SET FOREIGN_KEY_CHECKS=0');

foreach ($toTruncate as $t) {
    try {
        $cstmt = $db->query("SELECT COUNT(*) AS c FROM `$t`");
        $count = $cstmt ? $cstmt->fetch(PDO::FETCH_ASSOC)['c'] : 'unknown';
    } catch (Exception $e) {
        $count = 'error';
    }

    try {
        $db->exec("TRUNCATE TABLE `$t`");
        echo "Truncated: $t (was: $count rows)\n";
    } catch (Exception $e) {
        echo "Failed to truncate $t: " . $e->getMessage() . "\n";
    }
}

$db->exec('SET FOREIGN_KEY_CHECKS=1');

echo "Truncate operation complete.\n";

?>
