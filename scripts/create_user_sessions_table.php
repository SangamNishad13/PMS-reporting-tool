<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

$sql = "CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  session_id VARCHAR(128) NOT NULL,
  user_agent TEXT,
  ip_address VARCHAR(45),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_activity DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  logout_at DATETIME DEFAULT NULL,
  active TINYINT(1) DEFAULT 1,
  INDEX(user_id),
  INDEX(session_id)
);";

try {
    $db->exec($sql);
    echo "user_sessions table created or already exists.\n";
} catch (Exception $e) {
    echo "Failed to create user_sessions: " . $e->getMessage() . "\n";
    exit(1);
}

?>
