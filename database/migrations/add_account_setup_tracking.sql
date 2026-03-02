-- Migration: Add account setup tracking columns to users table
-- Date: 2026-03-02

-- Add account_setup_completed column
ALTER TABLE `users` 
ADD COLUMN `account_setup_completed` tinyint(1) DEFAULT 0 AFTER `can_manage_devices`;

-- Add temp_password column to store temporary passwords
ALTER TABLE `users` 
ADD COLUMN `temp_password` varchar(255) DEFAULT NULL AFTER `account_setup_completed`;

-- Mark existing users with force_password_reset=0 as having completed setup
UPDATE `users` 
SET `account_setup_completed` = 1 
WHERE `force_password_reset` = 0;
