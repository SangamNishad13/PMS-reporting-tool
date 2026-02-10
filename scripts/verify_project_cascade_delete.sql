-- Verify and ensure all project-related tables have proper CASCADE delete
-- Run this to check which tables will be affected when a project is deleted

-- Tables with CASCADE delete (already configured):
-- ✓ assignments
-- ✓ chat_messages  
-- ✓ common_issues
-- ✓ grouped_urls
-- ✓ issue_config_permissions
-- ✓ issue_drafts
-- ✓ issue_templates
-- ✓ issues (and all child tables via CASCADE)
-- ✓ project_assets
-- ✓ project_permissions
-- ✓ project_phases
-- ✓ regression_rounds
-- ✓ unique_pages (and project_pages view)
-- ✓ user_assignments

-- Tables with SET NULL (won't delete, just nullify):
-- ⚠ user_qa_performance (project_id SET NULL)
-- ⚠ production_hours (project_id can be NULL)

-- Check for any orphaned data after project deletion
-- Run this query after deleting a project to verify cleanup:

SELECT 'grouped_urls' as table_name, COUNT(*) as orphaned_count 
FROM grouped_urls 
WHERE project_id NOT IN (SELECT id FROM projects)
UNION ALL
SELECT 'unique_pages', COUNT(*) 
FROM unique_pages 
WHERE project_id NOT IN (SELECT id FROM projects)
UNION ALL
SELECT 'issues', COUNT(*) 
FROM issues 
WHERE project_id NOT IN (SELECT id FROM projects)
UNION ALL
SELECT 'assignments', COUNT(*) 
FROM assignments 
WHERE project_id NOT IN (SELECT id FROM projects)
UNION ALL
SELECT 'user_assignments', COUNT(*) 
FROM user_assignments 
WHERE project_id NOT IN (SELECT id FROM projects)
UNION ALL
SELECT 'chat_messages', COUNT(*) 
FROM chat_messages 
WHERE project_id NOT IN (SELECT id FROM projects)
UNION ALL
SELECT 'project_assets', COUNT(*) 
FROM project_assets 
WHERE project_id NOT IN (SELECT id FROM projects);
