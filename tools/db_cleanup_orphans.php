<?php
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();

// candidate parent table names for a child column like project_id
function parentCandidates(string $base): array
{
    $cands = [];
    $cands[] = $base;
    $cands[] = $base . 's';
    if (str_ends_with($base, 'y')) {
        $cands[] = substr($base, 0, -1) . 'ies';
    }
    if (str_ends_with($base, 's')) {
        $cands[] = $base . 'es';
    }
    return array_values(array_unique($cands));
}

$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$tableSet = array_fill_keys($tables, true);

$checks = [];
foreach ($tables as $table) {
    $cols = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        $name = (string)$col['Field'];
        if ($name === 'id') {
            continue;
        }
        if (!str_ends_with($name, '_id')) {
            continue;
        }
        $base = substr($name, 0, -3);
        foreach (parentCandidates($base) as $parent) {
            if (isset($tableSet[$parent])) {
                $checks[] = [
                    'child_table' => $table,
                    'child_col' => $name,
                    'parent_table' => $parent
                ];
                break;
            }
        }
    }
}

$totalDeleted = 0;
$totalPairsTouched = 0;
echo "Scanning " . count($checks) . " candidate FK pairs...\n";

foreach ($checks as $check) {
    $childTable = $check['child_table'];
    $childCol = $check['child_col'];
    $parentTable = $check['parent_table'];

    $orphanSql = "SELECT COUNT(*) FROM `{$childTable}` c LEFT JOIN `{$parentTable}` p ON p.id = c.`{$childCol}` WHERE c.`{$childCol}` IS NOT NULL AND p.id IS NULL";
    $orphans = (int)$db->query($orphanSql)->fetchColumn();
    if ($orphans <= 0) {
        continue;
    }

    $deleteSql = "DELETE c FROM `{$childTable}` c LEFT JOIN `{$parentTable}` p ON p.id = c.`{$childCol}` WHERE c.`{$childCol}` IS NOT NULL AND p.id IS NULL";
    $deleted = (int)$db->exec($deleteSql);
    if ($deleted > 0) {
        $totalDeleted += $deleted;
        $totalPairsTouched++;
        echo "[CLEANED] {$childTable}.{$childCol} -> {$parentTable}.id : deleted {$deleted}\n";
    }
}

echo "Done. Total pairs cleaned: {$totalPairsTouched}, total orphan rows deleted: {$totalDeleted}\n";

// Ensure known FK that failed during import can now be created.
try {
    $fkExists = (int)$db->query("
        SELECT COUNT(*)
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unique_pages'
          AND CONSTRAINT_NAME = 'fk_unique_pages_project'
    ")->fetchColumn();

    if ($fkExists === 0) {
        $db->exec("ALTER TABLE `unique_pages`
                   ADD CONSTRAINT `fk_unique_pages_project`
                   FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE");
        echo "Added FK: fk_unique_pages_project\n";
    } else {
        echo "FK already present: fk_unique_pages_project\n";
    }
} catch (Throwable $e) {
    echo "FK check/add error: " . $e->getMessage() . "\n";
}
