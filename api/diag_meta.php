<?php
// Temporary diagnostic - delete after use
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

// 1. Check meta fields
$db = Database::getInstance();
echo "=== issue_metadata_fields ===\n";
$rows = $db->query("SELECT field_key, field_label, is_active FROM issue_metadata_fields ORDER BY field_label ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['field_key'] . ' | ' . $r['field_label'] . ' | active=' . $r['is_active'] . "\n";
}

// 2. Check secret derivation
echo "\n=== Image Token Secret Debug ===\n";
$envSecret = trim((string)getenv('PMS_PUBLIC_IMAGE_SECRET'));
$appKey    = trim((string)getenv('APP_KEY'));
echo "PMS_PUBLIC_IMAGE_SECRET set: " . ($envSecret !== '' ? 'YES (len=' . strlen($envSecret) . ')' : 'NO') . "\n";
echo "APP_KEY set: " . ($appKey !== '' ? 'YES (len=' . strlen($appKey) . ')' : 'NO') . "\n";

$parts = [
    (string)DB_HOST,
    (string)DB_NAME,
    (string)DB_USER,
    (string)DB_PASS,
    strtolower(str_replace('\\', '/', realpath(__DIR__)))
];
$computedSecret = hash('sha256', implode('|', $parts));
echo "Computed normalized secret (first 8 chars): " . substr($computedSecret, 0, 8) . "...\n";
echo "__DIR__: " . __DIR__ . "\n";

// 3. Validate the sample token from the issue URL
$sampleToken = 'eyJwIjoidXBsb2Fkcy9pc3N1ZXMvMjAyNjAzMzAvaXNzdWVfNjljYTRjMTQ2YjVlNzcuMTE4ODcxMDkucG5nIn0.f4d9d9362650d75fd5a8d7cefe50271c43ba8d16a16034cb113009e5f3b8c195';
$partsToken = explode('.', $sampleToken, 2);
$payloadB64 = $partsToken[0] ?? '';
$sig        = $partsToken[1] ?? '';
$expected   = hash_hmac('sha256', $payloadB64, $computedSecret);
echo "\n=== Token Validation ===\n";
echo "Submitted sig: " . $sig . "\n";
echo "Expected sig:  " . $expected . "\n";
echo "Match: " . ($expected === $sig ? 'YES' : 'NO') . "\n";
$decoded = base64url_decode($payloadB64);
echo "Decoded payload: " . $decoded . "\n";
