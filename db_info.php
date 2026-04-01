<?php
require_once 'config/database.php';
$db = Database::getInstance();
$output = "";
function desc($db, $table, &$output) {
    $output .= "Table: $table\n";
    $stmt = $db->query("DESCRIBE $table");
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($res as $row) {
        $output .= "  {$row['Field']} ({$row['Type']})\n";
    }
    $output .= "\n";
}
desc($db, 'projects', $output);
desc($db, 'user_assignments', $output);
desc($db, 'users', $output);
file_put_contents('db_info.txt', $output);
echo "Done\n";
?>
