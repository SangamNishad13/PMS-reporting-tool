-- Migration: Add client_ready field to issues table
-- Purpose: Allow marking issues as ready for client viewing
-- Date: 2026-03-03

-- Add client_ready column to issues table (only if not exists)
SET @dbname = DATABASE();
SET @tablename = 'issues';
SET @columnname = 'client_ready';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Whether issue is ready for client viewing (0=No, 1=Yes)''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index for better query performance when filtering client-ready issues (only if not exists)
SET @indexname = 'idx_client_ready';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) > 0,
  'SELECT 1',
  CONCAT('CREATE INDEX `', @indexname, '` ON `', @tablename, '`(`', @columnname, '`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

