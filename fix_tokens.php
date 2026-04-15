<?php
ob_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
ob_end_clean();

$db = Database::getInstance();

echo "Starting migration of public_image.php tokens...\n\n";

$tables = [
    'issues' => ['id', 'description'],
    'issue_comments' => ['id', 'comment_html']
];

$updatedCount = 0;

$secret = get_public_image_token_secret();
echo "Using current secret...\n";

foreach ($tables as $table => $cols) {
    $idCol = $cols[0];
    $textCol = $cols[1];
    
    $stmt = $db->query("SELECT $idCol, $textCol FROM $table WHERE $textCol LIKE '%api/public_image.php?t=%'");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = $row[$idCol];
        $text = $row[$textCol];
        
        // Find all public_image.php tokens
        $pattern = '/api\/public_image\.php\?t=([A-Za-z0-9\-_]+\.[A-Za-z0-9]+)/';
        $newText = preg_replace_callback($pattern, function($matches) use ($secret) {
            $token = $matches[1];
            $parts = explode('.', $token, 2);
            if (count($parts) === 2) {
                $payloadB64 = $parts[0];
                // Generate NEW correct signature
                $newSig = hash_hmac('sha256', $payloadB64, $secret);
                $newToken = $payloadB64 . '.' . $newSig;
                return 'api/public_image.php?t=' . $newToken;
            }
            return $matches[0];
        }, $text);
        
        if ($newText !== $text) {
            $upd = $db->prepare("UPDATE $table SET $textCol = ? WHERE $idCol = ?");
            $upd->execute([$newText, $id]);
            $updatedCount++;
            echo "Updated $table ID $id\n";
        }
    }
}

echo "\nMigration complete. $updatedCount records updated.\n";
