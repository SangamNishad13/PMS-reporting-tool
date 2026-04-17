-- Migration: Add ownership_type to devices table
-- Date: 2026-04-17
-- Description: Adds an ownership_type column to track whether a device is Owned or Leased.

ALTER TABLE `devices`
ADD COLUMN `ownership_type` ENUM('Owned', 'Leased') NOT NULL DEFAULT 'Owned'
AFTER `status`;
