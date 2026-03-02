# Database Migration: Account Setup Tracking

## Overview
This migration adds functionality to track user account setup status and store temporary passwords for admin visibility.

## Changes Made

### 1. Database Schema Changes
Added two new columns to the `users` table:
- `account_setup_completed` (tinyint(1), default 0): Tracks whether user has completed initial account setup
- `temp_password` (varchar(255), nullable): Stores temporary password for admin visibility (only for users who haven't completed setup)

### 2. Application Changes

#### modules/admin/users.php
- Added "Setup Status" column showing whether user has completed account setup
- Added "Temp Password" column showing temporary password for users who haven't completed setup
- Removed reset password modal
- Changed reset password button to send email directly
- Added JavaScript handler for send reset email button
- Updated user creation to store temporary password
- Updated password reset email function to store temporary password

#### modules/auth/force_reset.php
- Updated to clear temp_password when user completes password reset
- Updated to set account_setup_completed = 1 when user completes password reset

#### database/schema.sql
- Updated users table definition with new columns

## How to Run Migration

### Option 1: Run PHP Migration Script
```bash
php database/run_migration.php
```

### Option 2: Run SQL Directly
```sql
-- Add columns
ALTER TABLE `users` 
ADD COLUMN `account_setup_completed` tinyint(1) DEFAULT 0 AFTER `can_manage_devices`;

ALTER TABLE `users` 
ADD COLUMN `temp_password` varchar(255) DEFAULT NULL AFTER `account_setup_completed`;

-- Mark existing users as setup completed
UPDATE `users` 
SET `account_setup_completed` = 1 
WHERE `force_password_reset` = 0;
```

## Features

### For Admins
1. **Visible Temporary Passwords**: When creating a new user or resetting password, the temporary password is visible in the users table
2. **Setup Status Tracking**: Can see which users have completed their account setup
3. **Email-Based Reset**: Reset password button now sends email directly (no modal needed)
4. **@sisenable.com Support**: Users with @sisenable.com email addresses will receive reset emails with their temporary password

### For Users
1. **Secure Password Reset**: After receiving reset email, users must change their password on first login
2. **Password Privacy**: Once user completes setup/reset, their actual password is never visible to admin
3. **Temporary Password Cleared**: After successful password change, temporary password is removed from database

## Security Notes
- Temporary passwords are only stored for users who haven't completed setup
- Once user changes password, temp_password is set to NULL
- Actual user passwords are always hashed and never visible
- Only temporary passwords (before first login) are visible to admin

## Rollback
If you need to rollback this migration:
```sql
ALTER TABLE `users` DROP COLUMN `temp_password`;
ALTER TABLE `users` DROP COLUMN `account_setup_completed`;
```
