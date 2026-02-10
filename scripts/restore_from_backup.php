<?php
require_once __DIR__ . '/../config/database.php';

$backupPath = $argv[1] ?? __DIR__ . '/../backups/project_management_20260203134105.sql';
if (!file_exists($backupPath)) {
    echo "Backup file not found: $backupPath\n";
    exit(1);
}

$host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$user = defined('DB_USER') ? DB_USER : 'root';
$pass = defined('DB_PASS') ? DB_PASS : '';
$db   = defined('DB_NAME') ? DB_NAME : 'project_management';

$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_errno) {
    echo "Connect failed: " . $mysqli->connect_error . "\n";
    exit(1);
}

echo "Reading backup file...\n";
$raw = file_get_contents($backupPath);
if ($raw === false) {
    echo "Failed to read backup file.\n";
    exit(1);
}

// Detect UTF-16 BOM and convert to UTF-8 if needed
if (substr($raw, 0, 2) === "\xFF\xFE" || substr($raw, 0, 2) === "\xFE\xFF") {
    $sql = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
} else {
    $sql = $raw;
}

echo "Importing statements one-by-one (safer for packet limits)...\n";

// Split on ";\n" - naive but sufficient for typical mysqldump outputs
$parts = preg_split('/;\s*\n/', $sql);
$total = count($parts);
foreach ($parts as $i => $part) {
    $stmt = trim($part);
    if ($stmt === '') continue;
    // append semicolon that was removed by split
    $toRun = $stmt . ';';
    if (!$mysqli->query($toRun)) {
        echo "Error on statement #" . ($i+1) . ": " . $mysqli->error . "\n";
        // continue to attempt remaining statements
    }
    if ((($i+1) % 100) === 0) {
        echo "Imported " . ($i+1) . " / $total statements...\n";
    }
}

if ($mysqli->errno) {
    echo "Import finished with warnings/errors: " . $mysqli->error . "\n";
} else {
    echo "Restore complete.\n";
}

$mysqli->close();

?>
