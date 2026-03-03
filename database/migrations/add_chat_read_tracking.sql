-- Add chat read tracking
-- This allows tracking which messages have been read by which users

CREATE TABLE IF NOT EXISTS chat_read_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message_id INT NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_message (user_id, message_id),
    KEY idx_user_id (user_id),
    KEY idx_message_id (message_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index on chat_messages for better performance
ALTER TABLE chat_messages 
ADD INDEX idx_project_page (project_id, page_id),
ADD INDEX idx_created_at (created_at);
