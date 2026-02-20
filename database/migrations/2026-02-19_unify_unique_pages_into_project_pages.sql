-- Migration: unify unique_pages into project_pages
-- Target: MySQL/MariaDB
-- Run on the target database selected with USE <db_name>;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

DROP PROCEDURE IF EXISTS migrate_unique_pages_to_project_pages;
DELIMITER $$
CREATE PROCEDURE migrate_unique_pages_to_project_pages()
main: BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE v_table_name VARCHAR(128);
    DECLARE v_constraint_name VARCHAR(128);

    DECLARE v_id INT;
    DECLARE v_project_id INT;
    DECLARE v_name VARCHAR(255);
    DECLARE v_page_number VARCHAR(255);
    DECLARE v_canonical_url TEXT;
    DECLARE v_screen_name VARCHAR(255);
    DECLARE v_status VARCHAR(50);
    DECLARE v_at_tester_id INT;
    DECLARE v_ft_tester_id INT;
    DECLARE v_qa_id INT;
    DECLARE v_at_tester_ids TEXT;
    DECLARE v_ft_tester_ids TEXT;
    DECLARE v_created_by INT;
    DECLARE v_created_at DATETIME;
    DECLARE v_updated_at DATETIME;
    DECLARE v_notes TEXT;
    DECLARE v_new_page_id INT;

    DECLARE cur_drop_fk CURSOR FOR
        SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE kcu
        WHERE kcu.TABLE_SCHEMA = DATABASE()
          AND kcu.REFERENCED_TABLE_NAME = 'unique_pages';

    DECLARE cur_unmapped CURSOR FOR
        SELECT
            up.id,
            up.project_id,
            up.name,
            up.page_number,
            up.canonical_url,
            up.screen_name,
            up.status,
            up.at_tester_id,
            up.ft_tester_id,
            up.qa_id,
            up.at_tester_ids,
            up.ft_tester_ids,
            up.created_by,
            up.created_at,
            up.updated_at,
            up.notes
        FROM unique_pages up
        LEFT JOIN migration_unique_to_project_pages_map m ON m.unique_page_id = up.id
        WHERE m.unique_page_id IS NULL;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unique_pages'
    ) THEN
        SELECT 'unique_pages table not found. Nothing to migrate.' AS message;
        LEAVE main;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'project_pages'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'project_pages table not found. Migration cannot continue.';
    END IF;

    -- Backup unique_pages before any destructive step.
    SET @backup_table := CONCAT('backup_unique_pages_', DATE_FORMAT(NOW(), '%Y%m%d_%H%i%S'));
    SET @sql := CONCAT('CREATE TABLE `', @backup_table, '` AS SELECT * FROM `unique_pages`');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    CREATE TABLE IF NOT EXISTS migration_unique_to_project_pages_map (
        unique_page_id INT NOT NULL PRIMARY KEY,
        project_page_id INT NOT NULL,
        mapped_by VARCHAR(32) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    TRUNCATE TABLE migration_unique_to_project_pages_map;

    -- 1) Direct ID + project match.
    INSERT INTO migration_unique_to_project_pages_map (unique_page_id, project_page_id, mapped_by)
    SELECT up.id, pp.id, 'id'
    FROM unique_pages up
    INNER JOIN project_pages pp
        ON pp.id = up.id
       AND pp.project_id = up.project_id;

    -- 2) Match by canonical_url <-> url (same project).
    INSERT INTO migration_unique_to_project_pages_map (unique_page_id, project_page_id, mapped_by)
    SELECT up.id, MIN(pp.id) AS project_page_id, 'url'
    FROM unique_pages up
    INNER JOIN project_pages pp
        ON pp.project_id = up.project_id
       AND TRIM(COALESCE(pp.url, '')) <> ''
       AND TRIM(COALESCE(up.canonical_url, '')) <> ''
       AND TRIM(pp.url) = TRIM(up.canonical_url)
    LEFT JOIN migration_unique_to_project_pages_map m
        ON m.unique_page_id = up.id
    WHERE m.unique_page_id IS NULL
    GROUP BY up.id;

    -- 3) Match by name (same project).
    INSERT INTO migration_unique_to_project_pages_map (unique_page_id, project_page_id, mapped_by)
    SELECT up.id, MIN(pp.id) AS project_page_id, 'name'
    FROM unique_pages up
    INNER JOIN project_pages pp
        ON pp.project_id = up.project_id
       AND TRIM(COALESCE(pp.page_name, '')) <> ''
       AND TRIM(COALESCE(up.name, '')) <> ''
       AND TRIM(pp.page_name) = TRIM(up.name)
    LEFT JOIN migration_unique_to_project_pages_map m
        ON m.unique_page_id = up.id
    WHERE m.unique_page_id IS NULL
    GROUP BY up.id;

    -- 4) Match by page_number (same project).
    INSERT INTO migration_unique_to_project_pages_map (unique_page_id, project_page_id, mapped_by)
    SELECT up.id, MIN(pp.id) AS project_page_id, 'page_number'
    FROM unique_pages up
    INNER JOIN project_pages pp
        ON pp.project_id = up.project_id
       AND TRIM(COALESCE(pp.page_number, '')) <> ''
       AND TRIM(COALESCE(up.page_number, '')) <> ''
       AND TRIM(pp.page_number) = TRIM(up.page_number)
    LEFT JOIN migration_unique_to_project_pages_map m
        ON m.unique_page_id = up.id
    WHERE m.unique_page_id IS NULL
    GROUP BY up.id;

    -- 5) For remaining rows, create a project_pages row and map it.
    SET done = 0;
    OPEN cur_unmapped;
    read_unmapped: LOOP
        FETCH cur_unmapped INTO
            v_id, v_project_id, v_name, v_page_number, v_canonical_url, v_screen_name, v_status,
            v_at_tester_id, v_ft_tester_id, v_qa_id, v_at_tester_ids, v_ft_tester_ids,
            v_created_by, v_created_at, v_updated_at, v_notes;
        IF done = 1 THEN
            LEAVE read_unmapped;
        END IF;

        INSERT INTO project_pages (
            project_id, page_name, page_number, url, screen_name, status,
            at_tester_id, ft_tester_id, qa_id, at_tester_ids, ft_tester_ids,
            created_by, created_at, updated_at, notes
        ) VALUES (
            v_project_id,
            COALESCE(NULLIF(TRIM(v_name), ''), NULLIF(TRIM(v_page_number), ''), CONCAT('Page ', v_id)),
            NULLIF(TRIM(v_page_number), ''),
            NULLIF(TRIM(v_canonical_url), ''),
            NULLIF(TRIM(v_screen_name), ''),
            COALESCE(NULLIF(TRIM(v_status), ''), 'not_started'),
            v_at_tester_id, v_ft_tester_id, v_qa_id, v_at_tester_ids, v_ft_tester_ids,
            v_created_by, COALESCE(v_created_at, NOW()), v_updated_at, v_notes
        );

        SET v_new_page_id = LAST_INSERT_ID();

        INSERT INTO migration_unique_to_project_pages_map (unique_page_id, project_page_id, mapped_by)
        VALUES (v_id, v_new_page_id, 'created');
    END LOOP;
    CLOSE cur_unmapped;

    -- Drop all foreign keys that still point to unique_pages.
    SET done = 0;
    OPEN cur_drop_fk;
    drop_fk_loop: LOOP
        FETCH cur_drop_fk INTO v_table_name, v_constraint_name;
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
    CLOSE cur_drop_fk;

    -- Rewrite references from unique_pages IDs to project_pages IDs.
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grouped_urls' AND COLUMN_NAME = 'unique_page_id') THEN
        UPDATE grouped_urls gu
        INNER JOIN migration_unique_to_project_pages_map m ON gu.unique_page_id = m.unique_page_id
        SET gu.unique_page_id = m.project_page_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignments' AND COLUMN_NAME = 'page_id') THEN
        UPDATE assignments a
        INNER JOIN migration_unique_to_project_pages_map m ON a.page_id = m.unique_page_id
        SET a.page_id = m.project_page_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'page_id') THEN
        UPDATE chat_messages cm
        INNER JOIN migration_unique_to_project_pages_map m ON cm.page_id = m.unique_page_id
        SET cm.page_id = m.project_page_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issues' AND COLUMN_NAME = 'page_id') THEN
        UPDATE issues i
        INNER JOIN migration_unique_to_project_pages_map m ON i.page_id = m.unique_page_id
        SET i.page_id = m.project_page_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issue_pages' AND COLUMN_NAME = 'page_id') THEN
        UPDATE issue_pages ip
        INNER JOIN migration_unique_to_project_pages_map m ON ip.page_id = m.unique_page_id
        SET ip.page_id = m.project_page_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_environments' AND COLUMN_NAME = 'page_id') THEN
        UPDATE page_environments pe
        INNER JOIN migration_unique_to_project_pages_map m ON pe.page_id = m.unique_page_id
        SET pe.page_id = m.project_page_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_time_logs' AND COLUMN_NAME = 'page_id') THEN
        UPDATE project_time_logs ptl
        INNER JOIN migration_unique_to_project_pages_map m ON ptl.page_id = m.unique_page_id
        SET ptl.page_id = m.project_page_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_results' AND COLUMN_NAME = 'page_id') THEN
        UPDATE qa_results qr
        INNER JOIN migration_unique_to_project_pages_map m ON qr.page_id = m.unique_page_id
        SET qr.page_id = m.project_page_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'testing_results' AND COLUMN_NAME = 'page_id') THEN
        UPDATE testing_results tr
        INNER JOIN migration_unique_to_project_pages_map m ON tr.page_id = m.unique_page_id
        SET tr.page_id = m.project_page_id;
    END IF;

    -- Recreate foreign keys to project_pages.
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignments' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignments' AND CONSTRAINT_NAME = 'fk_assignments_page_id_project') THEN
        ALTER TABLE assignments
            ADD CONSTRAINT fk_assignments_page_id_project
            FOREIGN KEY (page_id) REFERENCES project_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND CONSTRAINT_NAME = 'fk_chat_messages_page_id_project') THEN
        ALTER TABLE chat_messages
            ADD CONSTRAINT fk_chat_messages_page_id_project
            FOREIGN KEY (page_id) REFERENCES project_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grouped_urls' AND COLUMN_NAME = 'unique_page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grouped_urls' AND CONSTRAINT_NAME = 'fk_grouped_urls_page') THEN
        ALTER TABLE grouped_urls
            ADD CONSTRAINT fk_grouped_urls_page
            FOREIGN KEY (unique_page_id) REFERENCES project_pages (id) ON DELETE SET NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issues' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issues' AND CONSTRAINT_NAME = 'fk_issues_page_id_project') THEN
        ALTER TABLE issues
            ADD CONSTRAINT fk_issues_page_id_project
            FOREIGN KEY (page_id) REFERENCES project_pages (id) ON DELETE SET NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issue_pages' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issue_pages' AND CONSTRAINT_NAME = 'fk_issue_pages_page_id_project') THEN
        ALTER TABLE issue_pages
            ADD CONSTRAINT fk_issue_pages_page_id_project
            FOREIGN KEY (page_id) REFERENCES project_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_environments' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_environments' AND CONSTRAINT_NAME = 'fk_page_environments_page_id_project') THEN
        ALTER TABLE page_environments
            ADD CONSTRAINT fk_page_environments_page_id_project
            FOREIGN KEY (page_id) REFERENCES project_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_time_logs' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_time_logs' AND CONSTRAINT_NAME = 'fk_project_time_logs_page_id_project') THEN
        ALTER TABLE project_time_logs
            ADD CONSTRAINT fk_project_time_logs_page_id_project
            FOREIGN KEY (page_id) REFERENCES project_pages (id) ON DELETE SET NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_results' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_results' AND CONSTRAINT_NAME = 'fk_qa_results_page_id_project') THEN
        ALTER TABLE qa_results
            ADD CONSTRAINT fk_qa_results_page_id_project
            FOREIGN KEY (page_id) REFERENCES project_pages (id) ON DELETE CASCADE;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'testing_results' AND COLUMN_NAME = 'page_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'testing_results' AND CONSTRAINT_NAME = 'fk_testing_results_page_id_project') THEN
        ALTER TABLE testing_results
            ADD CONSTRAINT fk_testing_results_page_id_project
            FOREIGN KEY (page_id) REFERENCES project_pages (id) ON DELETE CASCADE;
    END IF;

    -- Final drop of legacy table.
    DROP TABLE IF EXISTS unique_pages;

    SELECT 'Migration completed: unique_pages merged into project_pages.' AS message;
END $$
DELIMITER ;

CALL migrate_unique_pages_to_project_pages();
DROP PROCEDURE IF EXISTS migrate_unique_pages_to_project_pages;

-- Verification checks (all should return 0 orphan_count).
SELECT 'grouped_urls.unique_page_id' AS ref_name, COUNT(*) AS orphan_count
FROM grouped_urls gu
LEFT JOIN project_pages pp ON pp.id = gu.unique_page_id
WHERE gu.unique_page_id IS NOT NULL AND pp.id IS NULL
UNION ALL
SELECT 'assignments.page_id', COUNT(*)
FROM assignments a
LEFT JOIN project_pages pp ON pp.id = a.page_id
WHERE a.page_id IS NOT NULL AND pp.id IS NULL
UNION ALL
SELECT 'chat_messages.page_id', COUNT(*)
FROM chat_messages cm
LEFT JOIN project_pages pp ON pp.id = cm.page_id
WHERE cm.page_id IS NOT NULL AND pp.id IS NULL
UNION ALL
SELECT 'issues.page_id', COUNT(*)
FROM issues i
LEFT JOIN project_pages pp ON pp.id = i.page_id
WHERE i.page_id IS NOT NULL AND pp.id IS NULL
UNION ALL
SELECT 'issue_pages.page_id', COUNT(*)
FROM issue_pages ip
LEFT JOIN project_pages pp ON pp.id = ip.page_id
WHERE ip.page_id IS NOT NULL AND pp.id IS NULL
UNION ALL
SELECT 'page_environments.page_id', COUNT(*)
FROM page_environments pe
LEFT JOIN project_pages pp ON pp.id = pe.page_id
WHERE pe.page_id IS NOT NULL AND pp.id IS NULL
UNION ALL
SELECT 'project_time_logs.page_id', COUNT(*)
FROM project_time_logs ptl
LEFT JOIN project_pages pp ON pp.id = ptl.page_id
WHERE ptl.page_id IS NOT NULL AND pp.id IS NULL
UNION ALL
SELECT 'qa_results.page_id', COUNT(*)
FROM qa_results qr
LEFT JOIN project_pages pp ON pp.id = qr.page_id
WHERE qr.page_id IS NOT NULL AND pp.id IS NULL
UNION ALL
SELECT 'testing_results.page_id', COUNT(*)
FROM testing_results tr
LEFT JOIN project_pages pp ON pp.id = tr.page_id
WHERE tr.page_id IS NOT NULL AND pp.id IS NULL;
