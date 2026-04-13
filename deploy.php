<?php
// ============================================================
// Auto-deploy webhook — triggered by GitHub push event
// ============================================================
// Setup:
//   1. Add DEPLOY_WEBHOOK_SECRET to your server .env file
//   2. In GitHub repo → Settings → Webhooks → Add webhook:
//      - Payload URL : https://yourdomain.com/PMS/deploy.php
//      - Content type: application/json
//      - Secret      : same value as DEPLOY_WEBHOOK_SECRET
//      - Events      : Just the push event
// ============================================================

define('DEPLOY_SECRET', getenv('DEPLOY_WEBHOOK_SECRET') ?: 'change-me-strong-secret');
define('BRANCH',        'security/vapt-hardening');
define('LOG_FILE',      __DIR__ . '/tmp/deploy.log');

// Verify GitHub HMAC signature
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $payload, DEPLOY_SECRET);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    exit('Forbidden');
}

// Only deploy for the target branch
$data         = json_decode($payload, true);
$pushedBranch = $data['ref'] ?? '';

if ($pushedBranch !== 'refs/heads/' . BRANCH) {
    http_response_code(200);
    exit('Branch not matched, skipping.');
}

// Deploy to both Production and UAT
// NOTE: uploads/, storage/, tmp/ are in .gitignore and will NEVER be touched
$dirs = [
    '/var/www/html/PMS',
    '/var/www/html/PMS-UAT',
];

$log = date('[Y-m-d H:i:s]') . " Deploy triggered for branch: " . BRANCH . "\n";

foreach ($dirs as $dir) {
    $safedir    = escapeshellarg($dir);
    $safebranch = escapeshellarg(BRANCH);
    $cmd        = "cd $safedir && git fetch origin $safebranch 2>&1 && git checkout FETCH_HEAD -- . 2>&1";
    $output     = shell_exec($cmd);
    $log       .= "[$dir]\n$output\n";
}

file_put_contents(LOG_FILE, $log, FILE_APPEND);
http_response_code(200);
echo 'Deployed successfully.';
