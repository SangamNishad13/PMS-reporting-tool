<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/models/ClientAccessControlManager.php';

$userId = 20; // sangamnishad13@gmail.com
$manager = new ClientAccessControlManager();

echo "Testing getAssignedProjects...\n";
$projects = $manager->getAssignedProjects($userId);
print_r($projects);

echo "\nTesting getProjectStatistics...\n";
$stats = $manager->getProjectStatistics($userId);
print_r($stats);
