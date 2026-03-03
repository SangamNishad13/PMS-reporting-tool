# Chat Unread Badge Implementation

## Overview
Project chat window mein ab unread messages ka badge dikhega jo user ko batayega ki kitne messages unread hain.

## Files Created/Modified

### 1. Database Migration
**File:** `database/migrations/add_chat_read_tracking.sql`
- Creates `chat_read_status` table to track which messages have been read by which users
- Adds indexes on `chat_messages` for better performance
- Foreign keys ensure data integrity

### 2. Helper Functions
**File:** `includes/chat_helpers.php`
- `getUnreadChatCount($db, $userId, $projectId, $pageId)` - Returns unread count for specific context
- `markChatMessagesAsRead($db, $userId, $projectId, $pageId)` - Marks messages as read
- `getTotalUnreadChatCount($db, $userId)` - Returns total unread across all chats

### 3. Project View Page
**File:** `modules/projects/view.php`
- Added `require_once` for `chat_helpers.php`
- Fetches unread count: `$unreadChatCount = getUnreadChatCount($db, $_SESSION['user_id'], $projectId)`
- Displays badge on "Project Chat" button with count

### 4. Project Chat Page
**File:** `modules/chat/project_chat.php`
- Added `require_once` for `chat_helpers.php`
- Automatically marks messages as read when chat is opened
- Calls `markChatMessagesAsRead()` after fetching messages

## How It Works

1. **Tracking Reads:**
   - When a user opens chat, all unread messages are marked as read in `chat_read_status` table
   - Each user-message pair is tracked uniquely

2. **Counting Unreads:**
   - Counts messages where:
     - Message is not from the current user (own messages don't count)
     - No read status exists for current user
     - Message is not deleted
   - Context-aware: counts only for specific project/page/general chat

3. **Displaying Badge:**
   - Red badge appears on "Project Chat" button
   - Shows count (99+ if more than 99)
   - Only visible when count > 0
   - Uses Bootstrap's position-absolute badge styling

## Database Schema

```sql
CREATE TABLE chat_read_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message_id INT NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_message (user_id, message_id),
    KEY idx_user_id (user_id),
    KEY idx_message_id (message_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
);
```

## Setup Instructions

1. **Run Migration:**
   ```bash
   # Execute the SQL migration
   mysql -u your_user -p your_database < database/migrations/add_chat_read_tracking.sql
   ```

2. **Test:**
   - Login as User A
   - Go to a project
   - Have User B send a message in that project's chat
   - User A should see badge with count "1" on Project Chat button
   - When User A opens chat, badge should disappear

## Features

- ✅ Real-time unread count display
- ✅ Context-aware (project/page/general chat)
- ✅ Auto-mark as read when chat opened
- ✅ Excludes own messages from count
- ✅ Excludes deleted messages
- ✅ Performance optimized with indexes
- ✅ Handles 99+ messages gracefully
- ✅ Accessible (includes visually-hidden text)

## Future Enhancements

1. Add badge to sidebar chat link
2. Add badge to header notifications
3. Real-time updates via WebSocket/polling
4. Per-page chat unread counts
5. Mark individual messages as unread
6. Desktop notifications for new messages

## Notes

- Badge uses Bootstrap 5 positioning classes
- Table auto-creates if not exists (safe for existing installations)
- Foreign keys ensure cleanup when users/messages deleted
- Unique constraint prevents duplicate read entries
