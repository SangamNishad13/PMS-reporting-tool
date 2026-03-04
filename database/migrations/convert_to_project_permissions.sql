-- Migration: Convert from client-level to project-level permissions
-- Date: 2026-03-03
-- Description: Changes permission system from client-based to project-based access control

-- Step 1: Add project_id column to client_permissions table (only if not exists)
SET @dbname = DATABASE();
SET @tablename = 'client_permissions';
SET @columnname = 'project_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` int(11) DEFAULT NULL AFTER `client_id`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index on project_id (only if not exists)
SET @indexname = 'idx_client_permissions_project';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD KEY `', @indexname, '` (`project_id`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add foreign key constraint (only if not exists)
SET @fkname = 'fk_cp_project';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (constraint_name = @fkname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD CONSTRAINT `', @fkname, '` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Step 2: Update unique constraint to include project_id
-- Drop old constraint if exists
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = 'unique_client_user_permission')
  ) > 0,
  CONCAT('ALTER TABLE `', @tablename, '` DROP INDEX `unique_client_user_permission`'),
  'SELECT 1'
));
PREPARE alterIfExists FROM @preparedStatement;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

-- Add new constraint if not exists
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = 'unique_project_user_permission')
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD UNIQUE KEY `unique_project_user_permission` (`project_id`,`user_id`,`permission_type`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Step 3: Update permission types descriptions to reflect project-level access
UPDATE `client_permissions_types` 
SET description = 'Can create new projects' 
WHERE permission_type = 'create_project';

UPDATE `client_permissions_types` 
SET description = 'Can edit this project' 
WHERE permission_type = 'edit_project';

UPDATE `client_permissions_types` 
SET description = 'Can view this project' 
WHERE permission_type = 'view_project';

UPDATE `client_permissions_types` 
SET description = 'Can delete this project' 
WHERE permission_type = 'delete_project';

-- Step 4: Migrate existing client-level permissions to project-level (only if not already migrated)
-- Check if project_id column exists before attempting migration
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE table_schema = DATABASE() 
    AND table_name = 'client_permissions' 
    AND column_name = 'project_id');

SET @migration_sql = IF(@column_exists > 0,
    'INSERT IGNORE INTO client_permissions (client_id, project_id, user_id, permission_type, granted_by, granted_at, expires_at, is_active, notes, created_at, updated_at)
    SELECT 
        cp.client_id,
        p.id as project_id,
        cp.user_id,
        cp.permission_type,
        cp.granted_by,
        cp.granted_at,
        cp.expires_at,
        cp.is_active,
        CONCAT(''Migrated from client-level permission. '', COALESCE(cp.notes, '''')) as notes,
        NOW() as created_at,
        NOW() as updated_at
    FROM client_permissions cp
    CROSS JOIN projects p
    WHERE cp.project_id IS NULL 
      AND p.client_id = cp.client_id
      AND cp.is_active = 1',
    'SELECT 1'
);

PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Step 5: Deactivate old client-level permissions (where project_id is NULL)
-- Only run if project_id column exists
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE table_schema = DATABASE() 
    AND table_name = 'client_permissions' 
    AND column_name = 'project_id');

SET @deactivate_sql = IF(@column_exists > 0,
    'UPDATE client_permissions 
    SET is_active = 0, 
        notes = CONCAT(''Deprecated: Converted to project-level permissions. '', COALESCE(notes, ''''))
    WHERE project_id IS NULL AND is_active = 1',
    'SELECT 1'
);

PREPARE deactivate_stmt FROM @deactivate_sql;
EXECUTE deactivate_stmt;
DEALLOCATE PREPARE deactivate_stmt;
