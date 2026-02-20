-- Rollback for: 2026-02-19_unify_unique_pages_into_project_pages.sql
-- This recreates unique_pages from current project_pages data and repoints FKs back.
-- Use only if you explicitly need to revert.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

DROP PROCEDURE IF EXISTS rollback_project_pages_to_unique_pages;
DELIMITER $$
CREATE PROCEDURE rollback_project_pages_to_unique_pages()
main: BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE v_table_name VARCHAR(128);
    DECLARE v_constraint_name VARCHAR(128);

    DECLARE cur_drop_fk_project CURSOR FOR
        SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE kcu
        WHERE kcu.TABLE_SCHEMA = DATABASE()
          AND kcu.REFERENCED_TABLE_NAME = 'project_pages'
          AND (
              (kcu.TABLE_NAME = 'assignments' AND kcu.COLUMN_NAME = 'page_id') OR
              (kcu.TABLE_NAME = 'chat_messages' AND kcu.COLUMN_NAME = 'page_id') OR
              (kcu.TABLE_NAME = 'grouped_urls' AND kcu.COLUMN_NAME = 'unique_page_id') OR
              (kcu.TABLE_NAME = 'issues' AND kcu.COLUMN_NAME = 'page_id') OR
              (kcu.TABLE_NAME = 'issue_pages' AND kcu.COLUMN_NAME = 'page_id') OR
              (kcu.TABLE_NAME = 'page_environments' AND kcu.COLUMN_NAME = 'page_id') OR
              (kcu.TABLE_NAME = 'project_time_logs' AND kcu.COLUMN_NAME = 'page_id') OR
              (kcu.TABLE_NAME = 'qa_results' AND kcu.COLUMN_NAME = 'page_id') OR
              (kcu.TABLE_NAME = 'testing_results' AND kcu.COLUMN_NAME = 'page_id')
          );

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_pages'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'project_pages not found. Rollback cannot continue.';
    END IF;

    CREATE TABLE IF NOT EXISTS unique_pages (
      id int NOT NULL AUTO_INCREMENT,
      project_id int NOT NULL,
      name varchar(255) NOT NULL,
      page_number varchar(255) DEFAULT NULL,
      canonical_url text,
      screen_name varchar(255) DEFAULT NULL,
      status enum('not_started','in_progress','completed','blocked') DEFAULT 'not_started',
      at_tester_id int DEFAULT NULL,
      ft_tester_id int DEFAULT NULL,
      qa_id int DEFAULT NULL,
      at_tester_ids text,
      ft_tester_ids text,
      created_by int DEFAULT NULL,
      created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      notes text,
      PRIMARY KEY (id),
      KEY idx_unique_pages_project_id (project_id),
      KEY idx_unique_pages_page_number (page_number),
      KEY idx_unique_pages_status (status),
      KEY idx_unique_pages_url (canonical_url(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Refill unique_pages from project_pages (ID-preserving where possible).
    INSERT INTO unique_pages (
        id, project_id, name, page_number, canonical_url, screen_name, status,
        at_tester_id, ft_tester_id, qa_id, at_tester_ids, ft_tester_ids,
        created_by, created_at, updated_at, notes
    )
    SELECT
        pp.id, pp.project_id, pp.page_name, pp.page_number, pp.url, pp.screen_name, pp.status,
        pp.at_tester_id, pp.ft_tester_id, pp.qa_id, pp.at_tester_ids, pp.ft_tester_ids,
        pp.created_by, pp.created_at, pp.updated_at, pp.notes
    FROM project_pages pp
    LEFT JOIN unique_pages up ON up.id = pp.id
    WHERE up.id IS NULL;

    -- Drop project_pages FKs from dependent tables.
    SET done = 0;
    OPEN cur_drop_fk_project;
    drop_fk_loop: LOOP
        FETCH cur_drop_fk_project INTO v_table_name, v_constraint_name;
        IF done = 1 THEN
            LEAVE drop_fk_loop;
        END IF;
        SET @sql := CONCAT(
            'ALTER TABLE `', v_table_name, '` DROP FOREIGN KEY `', v_constraint_name, '`'
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;
    CLOSE cur_drop_fk_project;

    -- Add back foreign keys to unique_pages.
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignments' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignments' AND CONSTRAINT_NAME = 'fk_assignments_page_id_unique') THEN
        ALTER TABLE assignments
            ADD CONSTRAINT fk_assignments_page_id_unique
            FOREIGN KEY (page_id) REFERENCES unique_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND CONSTRAINT_NAME = 'fk_chat_messages_page_id_unique') THEN
        ALTER TABLE chat_messages
            ADD CONSTRAINT fk_chat_messages_page_id_unique
            FOREIGN KEY (page_id) REFERENCES unique_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grouped_urls' AND COLUMN_NAME = 'unique_page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grouped_urls' AND CONSTRAINT_NAME = 'fk_grouped_urls_unique_page') THEN
        ALTER TABLE grouped_urls
            ADD CONSTRAINT fk_grouped_urls_unique_page
            FOREIGN KEY (unique_page_id) REFERENCES unique_pages (id) ON DELETE SET NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issues' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issues' AND CONSTRAINT_NAME = 'fk_issues_page_id_unique') THEN
        ALTER TABLE issues
            ADD CONSTRAINT fk_issues_page_id_unique
            FOREIGN KEY (page_id) REFERENCES unique_pages (id) ON DELETE SET NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issue_pages' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issue_pages' AND CONSTRAINT_NAME = 'fk_issue_pages_page_id_unique') THEN
        ALTER TABLE issue_pages
            ADD CONSTRAINT fk_issue_pages_page_id_unique
            FOREIGN KEY (page_id) REFERENCES unique_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_environments' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_environments' AND CONSTRAINT_NAME = 'fk_page_environments_page_id_unique') THEN
        ALTER TABLE page_environments
            ADD CONSTRAINT fk_page_environments_page_id_unique
            FOREIGN KEY (page_id) REFERENCES unique_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_time_logs' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_time_logs' AND CONSTRAINT_NAME = 'fk_project_time_logs_page_id_unique') THEN
        ALTER TABLE project_time_logs
            ADD CONSTRAINT fk_project_time_logs_page_id_unique
            FOREIGN KEY (page_id) REFERENCES unique_pages (id) ON DELETE SET NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_results' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_results' AND CONSTRAINT_NAME = 'fk_qa_results_page_id_unique') THEN
        ALTER TABLE qa_results
            ADD CONSTRAINT fk_qa_results_page_id_unique
            FOREIGN KEY (page_id) REFERENCES unique_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'testing_results' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'testing_results' AND CONSTRAINT_NAME = 'fk_testing_results_page_id_unique') THEN
        ALTER TABLE testing_results
            ADD CONSTRAINT fk_testing_results_page_id_unique
            FOREIGN KEY (page_id) REFERENCES unique_pages (id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unique_pages'
          AND CONSTRAINT_NAME = 'fk_unique_pages_project'
    ) THEN
        ALTER TABLE unique_pages
            ADD CONSTRAINT fk_unique_pages_project
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE;
    END IF;

    SELECT 'Rollback completed: unique_pages recreated and dependent FKs repointed.' AS message;
END $$
DELIMITER ;

CALL rollback_project_pages_to_unique_pages();
DROP PROCEDURE IF EXISTS rollback_project_pages_to_unique_pages;
