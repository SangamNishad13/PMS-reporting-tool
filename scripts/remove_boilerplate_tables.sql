-- Remove unused boilerplate tables
-- These tables were never fully implemented and are not used anywhere in the codebase

-- Drop tables in correct order (child tables first due to foreign key constraints)
DROP TABLE IF EXISTS `boilerplate_audit_log`;
DROP TABLE IF EXISTS `boilerplate_custom_fields`;
DROP TABLE IF EXISTS `boilerplate_issue_types`;
DROP TABLE IF EXISTS `boilerplate_user_access`;

-- Note: issue_boilerplates table doesn't exist in schema but is referenced by foreign keys
-- The above tables reference it, so they need to be dropped first
