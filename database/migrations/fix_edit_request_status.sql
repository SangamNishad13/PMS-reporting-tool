-- Fix user_edit_requests status from 'used' to 'approved'
-- This migration fixes the status mismatch issue

-- First, check if 'used' status exists in the table
-- If yes, update all 'used' to 'approved'

-- Note: This will only work if the enum doesn't include 'used'
-- If 'used' is in enum, we need to modify the enum first

-- Step 1: Try to update any rows that might have 'used' status
-- (This will fail silently if 'used' is not a valid enum value)
UPDATE user_edit_requests 
SET status = 'approved' 
WHERE status = 'used';

-- Step 2: Verify the change
SELECT 
    status, 
    COUNT(*) as count 
FROM user_edit_requests 
GROUP BY status;
