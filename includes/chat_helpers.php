<?php
// Chat helper functions

/**
 * Get unread message count for a user in a specific context
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @param int|null $projectId Project ID (null for general chat)
 * @param int|null $pageId Page ID (null for project/general chat)
 * @return int Unread message count
 */
function getUnreadChatCount($db, $userId, $projectId = null, $pageId = null) {
    try {
        // Ensure chat_read_status table exists
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_read_status (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                message_id INT NOT NULL,
                read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_message (user_id, message_id),
                KEY idx_user_id (user_id),
                KEY idx_message_id (message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Check if deleted_at column exists
        $hasDeletedAt = false;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM chat_messages LIKE 'deleted_at'");
            $hasDeletedAt = $colCheck->rowCount() > 0;
        } catch (Exception $e) {
            $hasDeletedAt = false;
        }
        
        $deletedCondition = $hasDeletedAt ? "AND (cm.deleted_at IS NULL OR cm.deleted_at = '0000-00-00 00:00:00')" : "";
        
        // Build query based on context
        if ($pageId > 0) {
            // Page-level chat
            $stmt = $db->prepare("
                SELECT COUNT(*) as unread_count
                FROM chat_messages cm
                LEFT JOIN chat_read_status crs ON cm.id = crs.message_id AND crs.user_id = ?
                WHERE cm.page_id = ?
                AND cm.user_id != ?
                AND crs.id IS NULL
                $deletedCondition
            ");
            $stmt->execute([$userId, $pageId, $userId]);
        } elseif ($projectId > 0) {
            // Project-level chat
            $stmt = $db->prepare("
                SELECT COUNT(*) as unread_count
                FROM chat_messages cm
                LEFT JOIN chat_read_status crs ON cm.id = crs.message_id AND crs.user_id = ?
                WHERE cm.project_id = ? AND cm.page_id IS NULL
                AND cm.user_id != ?
                AND crs.id IS NULL
                $deletedCondition
            ");
            $stmt->execute([$userId, $projectId, $userId]);
        } else {
            // General chat
            $stmt = $db->prepare("
                SELECT COUNT(*) as unread_count
                FROM chat_messages cm
                LEFT JOIN chat_read_status crs ON cm.id = crs.message_id AND crs.user_id = ?
                WHERE cm.project_id IS NULL AND cm.page_id IS NULL
                AND cm.user_id != ?
                AND crs.id IS NULL
                $deletedCondition
            ");
            $stmt->execute([$userId, $userId]);
        }
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['unread_count'] ?? 0);
        
    } catch (Exception $e) {
        error_log('getUnreadChatCount error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Mark messages as read for a user
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @param int|null $projectId Project ID
 * @param int|null $pageId Page ID
 * @return bool Success status
 */
function markChatMessagesAsRead($db, $userId, $projectId = null, $pageId = null) {
    try {
        // Ensure table exists
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_read_status (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                message_id INT NOT NULL,
                read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_message (user_id, message_id),
                KEY idx_user_id (user_id),
                KEY idx_message_id (message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Check if deleted_at column exists
        $hasDeletedAt = false;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM chat_messages LIKE 'deleted_at'");
            $hasDeletedAt = $colCheck->rowCount() > 0;
        } catch (Exception $e) {
            $hasDeletedAt = false;
        }
        
        $deletedCondition = $hasDeletedAt ? "AND (cm.deleted_at IS NULL OR cm.deleted_at = '0000-00-00 00:00:00')" : "";
        
        // Get message IDs to mark as read
        if ($pageId > 0) {
            $stmt = $db->prepare("
                SELECT cm.id
                FROM chat_messages cm
                LEFT JOIN chat_read_status crs ON cm.id = crs.message_id AND crs.user_id = ?
                WHERE cm.page_id = ?
                AND cm.user_id != ?
                AND crs.id IS NULL
                $deletedCondition
            ");
            $stmt->execute([$userId, $pageId, $userId]);
        } elseif ($projectId > 0) {
            $stmt = $db->prepare("
                SELECT cm.id
                FROM chat_messages cm
                LEFT JOIN chat_read_status crs ON cm.id = crs.message_id AND crs.user_id = ?
                WHERE cm.project_id = ? AND cm.page_id IS NULL
                AND cm.user_id != ?
                AND crs.id IS NULL
                $deletedCondition
            ");
            $stmt->execute([$userId, $projectId, $userId]);
        } else {
            $stmt = $db->prepare("
                SELECT cm.id
                FROM chat_messages cm
                LEFT JOIN chat_read_status crs ON cm.id = crs.message_id AND crs.user_id = ?
                WHERE cm.project_id IS NULL AND cm.page_id IS NULL
                AND cm.user_id != ?
                AND crs.id IS NULL
                $deletedCondition
            ");
            $stmt->execute([$userId, $userId]);
        }
        
        $messageIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($messageIds)) {
            return true;
        }
        
        // Insert read status for all unread messages
        $insertStmt = $db->prepare("
            INSERT IGNORE INTO chat_read_status (user_id, message_id, read_at)
            VALUES (?, ?, NOW())
        ");
        
        foreach ($messageIds as $messageId) {
            $insertStmt->execute([$userId, $messageId]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log('markChatMessagesAsRead error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get total unread count across all chats for a user
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @return int Total unread count
 */
function getTotalUnreadChatCount($db, $userId) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_read_status (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                message_id INT NOT NULL,
                read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_message (user_id, message_id),
                KEY idx_user_id (user_id),
                KEY idx_message_id (message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Check if deleted_at column exists
        $hasDeletedAt = false;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM chat_messages LIKE 'deleted_at'");
            $hasDeletedAt = $colCheck->rowCount() > 0;
        } catch (Exception $e) {
            $hasDeletedAt = false;
        }
        
        $deletedCondition = $hasDeletedAt ? "AND (cm.deleted_at IS NULL OR cm.deleted_at = '0000-00-00 00:00:00')" : "";
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as unread_count
            FROM chat_messages cm
            LEFT JOIN chat_read_status crs ON cm.id = crs.message_id AND crs.user_id = ?
            WHERE cm.user_id != ?
            AND crs.id IS NULL
            $deletedCondition
        ");
        $stmt->execute([$userId, $userId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['unread_count'] ?? 0);
        
    } catch (Exception $e) {
        error_log('getTotalUnreadChatCount error: ' . $e->getMessage());
        return 0;
    }
}
