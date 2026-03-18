-- Performance indexes for 100+ concurrent users
-- Run once: php database/migrate.php

-- issues table: most queried columns
ALTER TABLE issues
    ADD INDEX IF NOT EXISTS idx_issues_project_key      (project_id, issue_key),
    ADD INDEX IF NOT EXISTS idx_issues_project_reporter (project_id, reporter_id),
    ADD INDEX IF NOT EXISTS idx_issues_project_client   (project_id, client_ready),
    ADD INDEX IF NOT EXISTS idx_issues_project_status   (project_id, status_id),
    ADD INDEX IF NOT EXISTS idx_issues_updated          (updated_at);

-- issue_metadata: batch fetch by issue_id
ALTER TABLE issue_metadata
    ADD INDEX IF NOT EXISTS idx_meta_issue_key (issue_id, meta_key);

-- issue_pages: batch fetch by issue_id
ALTER TABLE issue_pages
    ADD INDEX IF NOT EXISTS idx_issue_pages_issue (issue_id);

-- issue_reporter_qa_status: qa_breakdown.php joins
ALTER TABLE issue_reporter_qa_status
    ADD INDEX IF NOT EXISTS idx_irqs_reporter (reporter_user_id),
    ADD INDEX IF NOT EXISTS idx_irqs_issue    (issue_id);

-- project_pages: grouped URL lookups
ALTER TABLE project_pages
    ADD INDEX IF NOT EXISTS idx_pp_project_url  (project_id, url(191));

-- grouped_urls: unique page lookups
ALTER TABLE grouped_urls
    ADD INDEX IF NOT EXISTS idx_gu_project_unique (project_id, unique_page_id);

-- user_sessions: isLoggedIn() check on every request
ALTER TABLE user_sessions
    ADD INDEX IF NOT EXISTS idx_us_session_user (session_id, user_id, active);
