<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

echo "--- LOOKING FOR MISSING PAGE 25 (ID 8654) ---\n\n";

$stmt = $db->prepare("
    SELECT details, action, created_at
    FROM activity_log
    WHERE (entity_type = 'page' AND entity_id = 8654) OR (details LIKE '%\"page_id\":8654%') OR (details LIKE '%\"page_id\":\"8654\"%')
    ORDER BY created_at ASC
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($logs)) {
    echo "No specific logs found for ID 8654. Searching all Project 41 logs for potential 'Page 25' matches...\n";
    $stmt = $db->prepare("
        SELECT details, action, created_at, entity_id
        FROM activity_log
        WHERE (details LIKE '%\"project_id\":41%' OR details LIKE '%\"project_id\":\"41\"%')
        AND (action IN ('quick_add_page', 'assign_page'))
        ORDER BY created_at ASC
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($logs as $l) {
    if (strpos($l['details'], '8654') !== false || strpos($l['details'], 'Page 25') !== false || strpos($l['details'], 'Page 24') !== false) {
        echo "Match found: " . $l['action'] . " at " . $l['created_at'] . " - Details: " . $l['details'] . "\n";
    }
}
echo "\n--- Search Complete ---\n";
