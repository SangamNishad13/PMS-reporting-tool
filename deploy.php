<?php
// GitHub push webhook -> deploy PMS + PMS-UAT from security/vapt-hardening branch.

define('BRANCH', 'security/vapt-hardening');
define('LOG_FILE', __DIR__ . '/tmp/deploy.log');

function deploy_load_env_value($filePath, $key) {
    if (!is_readable($filePath)) {
        return '';
    }
    $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }
    $prefix = $key . '=';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }
        if (strpos($trimmed, $prefix) !== 0) {
            continue;
        }
        $value = trim(substr($trimmed, strlen($prefix)));
        // Strip optional quotes from .env value
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        return $value;
    }
    return '';
}

function deploy_log($message) {
    @file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

// Accept only POST from webhook.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$secret = getenv('DEPLOY_WEBHOOK_SECRET') ?: deploy_load_env_value('/var/www/html/PMS/.env', 'DEPLOY_WEBHOOK_SECRET');
if ($secret === '' || $secret === 'change-me-strong-secret') {
    deploy_log('Blocked: DEPLOY_WEBHOOK_SECRET missing or default');
    http_response_code(500);
    echo 'Webhook secret not configured';
    exit;
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    deploy_log('Blocked: signature mismatch');
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event !== 'push') {
    http_response_code(200);
    echo 'Ignored non-push event';
    exit;
}

$data = json_decode($payload, true);
$pushedBranch = $data['ref'] ?? '';
if ($pushedBranch !== 'refs/heads/' . BRANCH) {
    http_response_code(200);
    echo 'Branch not matched, skipping.';
    exit;
}

$dirs = [
    '/var/www/html/PMS',
    '/var/www/html/PMS-UAT',
];

deploy_log('Deploy triggered for branch: ' . BRANCH);

foreach ($dirs as $dir) {
    $safeDir = escapeshellarg($dir);
    $safeBranch = escapeshellarg(BRANCH);
    $cmd = "cd $safeDir"
        . " && git fetch origin $safeBranch 2>&1"
        . " && git diff --name-only --diff-filter=AMT HEAD FETCH_HEAD"
        . " | grep -Ev '^(uploads/|storage/|tmp/)'"
        . " | while IFS= read -r f; do"
        . " [ -z \"$f\" ] && continue;"
        . " mkdir -p \"$(dirname \"$f\")\";"
        . " git show \"FETCH_HEAD:$f\" > \"$f\";"
        . " chown www-data:www-data \"$f\" 2>/dev/null || true;"
        . " done 2>&1";
    $output = shell_exec($cmd);
    deploy_log("[$dir]\n" . (string)$output);
}

http_response_code(200);
echo 'Deployed successfully.';
