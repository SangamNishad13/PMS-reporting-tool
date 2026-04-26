<?php
/**
 * One-click Migration Runner
 * 
 * Open in browser to run all pending migrations.
 * Protected by a secret token — change RUNNER_TOKEN below before deploying.
 * 
 * Usage: https://yourdomain.com/database/run_migrations.php?token=YOUR_SECRET_TOKEN
 * 
 * DELETE THIS FILE after running on production.
 */

// ─── CHANGE THIS TOKEN before deploying ───────────────────────────────────────
define('RUNNER_TOKEN', 'athenaeum_migrate_2026');
// ──────────────────────────────────────────────────────────────────────────────

// Token check
$providedToken = $_GET['token'] ?? '';
if (!hash_equals(RUNNER_TOKEN, $providedToken)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;background:#f8d7da;color:#721c24;">
        <h2>🔒 Access Denied</h2>
        <p>Provide the correct token: <code>?token=YOUR_TOKEN</code></p>
    </body></html>');
}

require_once __DIR__ . '/config/database.php';

$db = Database::getInstance();

// Create migration_history table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS `migration_history` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `migration_file` varchar(255) NOT NULL,
    `executed_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `status` enum('success','failed') DEFAULT 'success',
    `error_message` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_migration_file` (`migration_file`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Get already-executed migrations
$executed = [];
try {
    $rows = $db->query("SELECT migration_file FROM migration_history WHERE status = 'success'")->fetchAll(PDO::FETCH_COLUMN);
    $executed = array_flip($rows);
} catch (Exception $e) {}

// Collect all migration files (SQL only — PHP migrations handled separately)
$migrationDir = __DIR__ . '/database/migrations/';
$sqlFiles = glob($migrationDir . '*.sql');
sort($sqlFiles);

// Also collect PHP migration files
$phpFiles = glob($migrationDir . '*.php');
sort($phpFiles);

$allFiles = array_merge($sqlFiles, $phpFiles);
sort($allFiles);

$results = [];

foreach ($allFiles as $filePath) {
    $filename = basename($filePath);

    // Skip README
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'md') continue;

    $alreadyRun = isset($executed[$filename]);

    if ($alreadyRun) {
        $results[] = ['file' => $filename, 'status' => 'skipped', 'msg' => 'Already executed'];
        continue;
    }

    try {
        if (pathinfo($filename, PATHINFO_EXTENSION) === 'php') {
            // PHP migration: include it (it should use $db)
            include $filePath;
            $results[] = ['file' => $filename, 'status' => 'success', 'msg' => 'PHP migration executed'];
        } else {
            // SQL migration: split on semicolons and run each statement
            $sql = file_get_contents($filePath);

            // Remove BOM if present
            $sql = ltrim($sql, "\xEF\xBB\xBF");

            // Split into individual statements (handle multi-statement files)
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => $s !== '' && !preg_match('/^--/', $s)
            );

            $db->beginTransaction();
            foreach ($statements as $stmt) {
                if (trim($stmt) === '') continue;
                $db->exec($stmt);
            }
            $db->commit();

            $results[] = ['file' => $filename, 'status' => 'success', 'msg' => 'SQL migration executed'];
        }

        // Record success
        $db->prepare("INSERT INTO migration_history (migration_file, status) VALUES (?, 'success')
                      ON DUPLICATE KEY UPDATE executed_at = NOW(), status = 'success', error_message = NULL")
           ->execute([$filename]);

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();

        $errMsg = $e->getMessage();

        // Treat "already exists" / "duplicate column" as success (idempotent)
        $isIdempotent = preg_match('/already exists|duplicate column|duplicate key|can\'t drop.*check that column.*exists/i', $errMsg);

        if ($isIdempotent) {
            $results[] = ['file' => $filename, 'status' => 'skipped', 'msg' => 'Already applied (idempotent): ' . $errMsg];
            $db->prepare("INSERT INTO migration_history (migration_file, status) VALUES (?, 'success')
                          ON DUPLICATE KEY UPDATE executed_at = NOW(), status = 'success', error_message = NULL")
               ->execute([$filename]);
        } else {
            $results[] = ['file' => $filename, 'status' => 'error', 'msg' => $errMsg];
            try {
                $db->prepare("INSERT INTO migration_history (migration_file, status, error_message) VALUES (?, 'failed', ?)
                              ON DUPLICATE KEY UPDATE executed_at = NOW(), status = 'failed', error_message = ?")
                   ->execute([$filename, $errMsg, $errMsg]);
            } catch (Exception $logErr) {}
        }
    }
}

// Count results
$successCount = count(array_filter($results, fn($r) => $r['status'] === 'success'));
$skippedCount = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));
$errorCount   = count(array_filter($results, fn($r) => $r['status'] === 'error'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration Runner</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; padding: 30px 20px; color: #333; }
        .wrap { max-width: 860px; margin: 0 auto; }
        h1 { font-size: 1.6rem; margin-bottom: 6px; }
        .subtitle { color: #666; margin-bottom: 24px; font-size: 0.9rem; }
        .summary { display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
        .stat { flex: 1; min-width: 140px; background: #fff; border-radius: 8px; padding: 16px 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .stat .num { font-size: 2rem; font-weight: 700; }
        .stat .lbl { font-size: 0.8rem; color: #888; margin-top: 2px; }
        .stat.green .num { color: #16a34a; }
        .stat.blue  .num { color: #2563eb; }
        .stat.red   .num { color: #dc2626; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th { background: #f8f9fa; padding: 10px 14px; text-align: left; font-weight: 600; border-bottom: 1px solid #e5e7eb; }
        td { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
        .badge-success { background: #dcfce7; color: #16a34a; }
        .badge-skipped { background: #f3f4f6; color: #6b7280; }
        .badge-error   { background: #fee2e2; color: #dc2626; }
        .msg { color: #6b7280; font-size: 0.8rem; margin-top: 3px; word-break: break-word; }
        .msg.err { color: #dc2626; }
        .warn { background: #fef9c3; border: 1px solid #fde047; border-radius: 8px; padding: 14px 18px; margin-top: 24px; font-size: 0.875rem; color: #713f12; }
        .warn strong { display: block; margin-bottom: 4px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>🗄️ Migration Runner</h1>
    <p class="subtitle">Ran at <?php echo date('Y-m-d H:i:s T'); ?></p>

    <div class="summary">
        <div class="stat green">
            <div class="num"><?php echo $successCount; ?></div>
            <div class="lbl">Applied</div>
        </div>
        <div class="stat blue">
            <div class="num"><?php echo $skippedCount; ?></div>
            <div class="lbl">Skipped (already run)</div>
        </div>
        <div class="stat red">
            <div class="num"><?php echo $errorCount; ?></div>
            <div class="lbl">Errors</div>
        </div>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Migration File</th>
                    <th>Result</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['file']); ?></td>
                    <td>
                        <?php if ($r['status'] === 'success'): ?>
                            <span class="badge badge-success">✓ Applied</span>
                        <?php elseif ($r['status'] === 'skipped'): ?>
                            <span class="badge badge-skipped">— Skipped</span>
                        <?php else: ?>
                            <span class="badge badge-error">✗ Error</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="msg <?php echo $r['status'] === 'error' ? 'err' : ''; ?>">
                            <?php echo htmlspecialchars($r['msg']); ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="warn">
        <strong>⚠️ Security reminder</strong>
        Delete or restrict access to this file after running migrations on production/UAT.
    </div>
</div>
</body>
</html>
