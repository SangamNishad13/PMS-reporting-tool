<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();

// Perform logout
$auth->logout();

// If we reach here, logout didn't redirect (shouldn't happen)
// Fallback redirect
redirect("/modules/auth/login.php?logout=success");
