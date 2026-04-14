<?php
$host = 'localhost';
$db   = 'athenaeu_project_management';
$user = 'athenaeu_pms';
$pass = '$Sis@2026$'; 
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     echo "Connection successful!";
} catch (\PDOException $e) {
     echo "Connection failed: " . $e->getMessage();
}
