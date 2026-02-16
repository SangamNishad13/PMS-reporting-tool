<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// disable output buffering
if (ob_get_level()) ob_end_clean();

$db = Database::getInstance();
echo "Starting Database Reset and Seed...\n";

try {
    $tableExists = function (string $table) use ($db): bool {
        $q = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $db->quote($table) . " AND TABLE_TYPE = 'BASE TABLE'";
        return ((int)$db->query($q)->fetchColumn()) > 0;
    };

    // 1. Disable Foreign Keys
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "Foreign Keys Disabled.\n";

    // 2. Truncate Tables (Except users and metadata)
    // Keep: users, testing_environments, status_options, generic_task_categories (maybe? let's clear and re-seed if needed, but user said keep users only. I will keep structure tables if they look like constants)
    // Actually, safer to clear 'project driven' data.
    
    $tablesToTruncate = [
        'activity_log',
        'chat_messages',
        'clients',
        'feedbacks', 
        'feedback_recipients',
        'notifications',
        'page_environments',
        'project_assets',
        'project_pages',
        'project_permissions',
        'project_phases',
        'project_time_logs',
        'projects', 
        'qa_results',
        'testing_results',
        'user_assignments',
        'user_calendar_notes',
        'user_daily_status',
        'user_edit_requests',
        'user_generic_tasks',
        'user_pending_changes'
    ];

    foreach ($tablesToTruncate as $table) {
        if ($tableExists($table)) {
            $db->exec("TRUNCATE TABLE `$table`");
            echo "Truncated: $table\n";
        } else {
            echo "Skipped (not found): $table\n";
        }
    }
    
    // Note: Not truncating 'users', 'testing_environments', 'status_options', 'generic_task_categories', 'project_permissions_types' as they might contain static config or the users we want to keep.
    // However, if 'clients' are needed, we truncate them.

    // 3. Re-enable Foreign Keys
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "Foreign Keys Enabled.\n";
    
    // 4. Seed Dummy Data
    echo "Seeding Dummy Data...\n";
    
    // Fetch a Project Lead, QA, and Tester to assign things to
    $stmt = $db->query("SELECT id FROM users WHERE role = 'project_lead' LIMIT 1");
    $plId = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT id FROM users WHERE role = 'qa' LIMIT 1");
    $qaId = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT id FROM users WHERE role = 'at_tester' LIMIT 1");
    $testerId = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT id FROM users WHERE role = 'ft_tester' LIMIT 1");
    $ftTesterId = $stmt->fetchColumn();
    
    $adminId = 1; // Assuming ID 1 is admin/super_admin
    
    if (!$plId) { echo "Warning: No Project Lead found. Using Admin.\n"; $plId = $adminId; }
    if (!$qaId) { echo "Warning: No QA found. Using Admin.\n"; $qaId = $adminId; }
    if (!$testerId) { echo "Warning: No AT Tester found. Using Admin.\n"; $testerId = $adminId; }
    if (!$ftTesterId) { echo "Warning: No FT Tester found. Using Admin.\n"; $ftTesterId = $adminId; }
    
    // Create Clients
    $clientId = null;
    if ($tableExists('clients')) {
        $db->exec("INSERT INTO clients (name, description, created_by) VALUES 
            ('Acme Corp', 'A large retail company', $adminId),
            ('Globex Inc', 'Global logistics provider', $adminId)");
        $clientId = $db->lastInsertId();
        echo "Created Clients.\n";
    }
    
    // Create Projects
    // Project 1: Web Project
    $clientSql1 = $clientId ? (int)$clientId : "NULL";
    $db->exec("INSERT INTO projects (po_number, title, description, project_type, client_id, priority, status, total_hours, project_lead_id, created_by) VALUES 
        ('PO-001-WEB', 'E-Commerce Revamp', 'Redesigning the main store', 'web', $clientSql1, 'high', 'in_progress', 500.00, $plId, $adminId)");
    $proj1Id = $db->lastInsertId();
    
    // Project 2: App Project
    $clientSql2 = $clientId ? (int)$clientId : "NULL";
    $db->exec("INSERT INTO projects (po_number, title, description, project_type, client_id, priority, status, total_hours, project_lead_id, created_by) VALUES 
        ('PO-002-APP', 'Driver Logistics App', 'Mobile app for drivers', 'app', $clientSql2, 'critical', 'in_progress', 800.00, $plId, $adminId)");
    $proj2Id = $db->lastInsertId();
    echo "Created Projects.\n";
    
    // Create Project Phases
    if ($tableExists('project_phases')) {
        $db->exec("INSERT INTO project_phases (project_id, phase_name, start_date, planned_hours) VALUES 
            ($proj1Id, 'Design', CURDATE(), 50),
            ($proj1Id, 'Development', CURDATE(), 200),
            ($proj2Id, 'MVP', CURDATE(), 300)");
        echo "Created Phases.\n";
    }
    
    // Create Pages for Project 1
    $homePageId = null;
    $checkoutPageId = null;
    $productListPageId = null;
    if ($tableExists('project_pages')) {
        $pagesSql = "INSERT INTO project_pages (project_id, page_name, url, status, at_tester_id, qa_id, created_by) VALUES 
            ($proj1Id, 'Home Page', '/home', 'in_progress', $testerId, $qaId, $plId),
            ($proj1Id, 'Checkout', '/checkout', 'needs_review', $testerId, $qaId, $plId),
            ($proj1Id, 'Product List', '/products', 'qa_in_progress', $testerId, $qaId, $plId)";
        $db->exec($pagesSql);
        echo "Created Pages.\n";

        $homePageId = (int)$db->query("SELECT id FROM project_pages WHERE project_id = $proj1Id AND page_name = 'Home Page' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $checkoutPageId = (int)$db->query("SELECT id FROM project_pages WHERE project_id = $proj1Id AND page_name = 'Checkout' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $productListPageId = (int)$db->query("SELECT id FROM project_pages WHERE project_id = $proj1Id AND page_name = 'Product List' ORDER BY id DESC LIMIT 1")->fetchColumn();
    }
    
    // Create Time Logs (Production Logs)
    if ($tableExists('project_time_logs')) {
        // Log for tester on Home Page (Utilized)
        $db->exec("INSERT INTO project_time_logs (project_id, user_id, page_id, task_type, log_date, hours_spent, description, is_utilized) VALUES 
            ($proj1Id, $testerId, " . ($homePageId ?: 'NULL') . ", 'page_testing', CURDATE(), 4.5, 'Testing hero banner responsiveness', 1)");

        // Log for tester on Bench (Not Utilized)
        $db->exec("INSERT INTO project_time_logs (project_id, user_id, task_type, log_date, hours_spent, description, is_utilized) VALUES 
            (NULL, $testerId, 'other', CURDATE(), 3.5, 'Self learning: Automation tools', 0)");

        // Log for QA
        $db->exec("INSERT INTO project_time_logs (project_id, user_id, page_id, task_type, log_date, hours_spent, description, is_utilized) VALUES 
            ($proj1Id, $qaId, " . ($productListPageId ?: 'NULL') . ", 'page_testing', CURDATE(), 6.0, 'Verifying filters and sort', 1)");

        // Past Logs
        $db->exec("INSERT INTO project_time_logs (project_id, user_id, page_id, task_type, log_date, hours_spent, description, is_utilized) VALUES 
            ($proj1Id, $testerId, " . ($checkoutPageId ?: 'NULL') . ", 'page_testing', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 8.0, 'Full regression on checkout flow', 1)");

        echo "Created Time Logs.\n";
    }
    
    // Create User Assignments
    if ($tableExists('user_assignments')) {
        $db->exec("INSERT INTO user_assignments (project_id, user_id, role, assigned_by, hours_allocated) VALUES 
            ($proj1Id, $testerId, 'at_tester', $adminId, 100.00),
            ($proj1Id, $qaId, 'qa', $adminId, 50.00),
            ($proj2Id, $ftTesterId, 'ft_tester', $adminId, 200.00)");
        echo "Created Assignments.\n";
    }
    
    // Create User Daily Status (Calendar)
    if ($tableExists('user_daily_status')) {
        $db->exec("INSERT INTO user_daily_status (user_id, status_date, status, notes) VALUES 
            ($testerId, CURDATE(), 'working', 'Working on E-Commerce'),
            ($qaId, CURDATE(), 'working', 'QA on E-Commerce')");
        echo "Created Daily Status.\n";
    }
    
    echo "Database Reset and Seed Completed Successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
