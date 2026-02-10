<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    
    echo "Seeding sample performance data...\n\n";
    
    // Get some users
    $users = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "❌ No active users found!\n";
        exit(1);
    }
    
    // Get some projects
    $projects = $db->query("SELECT id FROM projects LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    
    // Get QA statuses
    $qaStatuses = $db->query("SELECT id, status_label, error_points FROM qa_status_master WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($qaStatuses)) {
        echo "❌ No QA statuses found! Please run migration 052 first.\n";
        exit(1);
    }
    
    echo "Found:\n";
    echo "- " . count($users) . " users\n";
    echo "- " . count($projects) . " projects\n";
    echo "- " . count($qaStatuses) . " QA statuses\n\n";
    
    // Clear existing test data
    $db->exec("DELETE FROM user_qa_performance WHERE comment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    echo "✓ Cleared existing test data\n\n";
    
    $insertedCount = 0;
    
    // Generate random performance data for last 30 days
    foreach ($users as $user) {
        $commentsCount = rand(5, 20); // Each user gets 5-20 comments
        
        echo "Generating {$commentsCount} comments for {$user['full_name']}...\n";
        
        for ($i = 0; $i < $commentsCount; $i++) {
            // Random date in last 30 days
            $daysAgo = rand(0, 30);
            $commentDate = date('Y-m-d', strtotime("-{$daysAgo} days"));
            
            // Random project (or null)
            $projectId = !empty($projects) && rand(0, 1) ? $projects[array_rand($projects)] : null;
            
            // Random issue ID
            $issueId = rand(1, 100);
            
            // Random QA status
            $qaStatus = $qaStatuses[array_rand($qaStatuses)];
            
            // Insert performance record
            $stmt = $db->prepare("
                INSERT INTO user_qa_performance 
                (user_id, project_id, issue_id, qa_status_id, error_points, comment_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $user['id'],
                $projectId,
                $issueId,
                $qaStatus['id'],
                $qaStatus['error_points'],
                $commentDate
            ]);
            
            $insertedCount++;
        }
    }
    
    echo "\n✅ Successfully inserted {$insertedCount} performance records!\n\n";
    
    // Show summary
    echo "Summary by user:\n";
    $summary = $db->query("
        SELECT 
            u.full_name,
            COUNT(*) as comments,
            SUM(uqp.error_points) as total_points,
            ROUND(AVG(uqp.error_points), 2) as avg_points
        FROM user_qa_performance uqp
        JOIN users u ON uqp.user_id = u.id
        WHERE uqp.comment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY u.id, u.full_name
        ORDER BY total_points DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($summary as $row) {
        echo "  - {$row['full_name']}: {$row['comments']} comments, {$row['total_points']} total points, {$row['avg_points']} avg\n";
    }
    
    echo "\n✅ Sample data seeded successfully!\n";
    echo "Visit: http://localhost/PMS/modules/admin/performance.php\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
