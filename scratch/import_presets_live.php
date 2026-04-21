<?php
/**
 * LIVE PRESET IMPORTER - RUN ONCE THEN DELETE
 * Upload this file to /scratch/ on the live server and visit it in your browser.
 * It will import all 158 web issue presets from local SQL export.
 * 
 * SECURITY: This file has a secret key check. Delete after use.
 */

// Simple secret key - change if needed
$SECRET = 'import2026_pms';
$provided = $_GET['key'] ?? '';
if ($provided !== $SECRET) {
    http_response_code(403);
    echo "<h2>403 Forbidden</h2><p>Pass ?key=import2026_pms to proceed.</p>";
    exit;
}

require_once __DIR__ . '/../includes/functions.php';
$db = Database::getInstance();

$sqlFile = __DIR__ . '/issue_presets_export.sql';
if (!file_exists($sqlFile)) {
    die("<h2>Error</h2><p>Export SQL file not found: $sqlFile</p>");
}

echo "<html><head><title>Preset Importer</title></head><body>";
echo "<h2>Issue Presets Importer</h2>";

// Count existing
$existing = (int)$db->query("SELECT COUNT(*) FROM issue_presets")->fetchColumn();
echo "<p><strong>Existing presets in DB:</strong> $existing</p>";

if ($_POST['confirm'] ?? '' === 'yes') {
    try {
        $sql = file_get_contents($sqlFile);
        // Remove BOM if present (from Windows UTF-8 export)
        $sql = preg_replace('/^\xef\xbb\xbf/', '', $sql);
        
        // Split into individual statements
        $statements = array_filter(
            array_map('trim', explode(";\n", $sql)),
            fn($s) => $s !== '' && !str_starts_with($s, '--')
        );
        
        $db->beginTransaction();
        $count = 0;
        foreach ($statements as $stmt) {
            if (trim($stmt) === '') continue;
            $db->exec($stmt);
            if (stripos($stmt, 'INSERT') === 0) $count++;
        }
        $db->commit();
        
        $newTotal = (int)$db->query("SELECT COUNT(*) FROM issue_presets")->fetchColumn();
        echo "<div style='background:#d4edda;padding:15px;border-radius:6px;'>";
        echo "<h3>✅ Import Successful!</h3>";
        echo "<p>Inserted: <strong>$count rows</strong></p>";
        echo "<p>Total presets now: <strong>$newTotal</strong></p>";
        echo "<p style='color:red;'><strong>⚠️ Delete this file from the server now!</strong></p>";
        echo "</div>";
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "<div style='background:#f8d7da;padding:15px;border-radius:6px;'>";
        echo "<h3>❌ Error</h3><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        echo "</div>";
    }
} else {
    echo "<form method='post'>";
    echo "<input type='hidden' name='confirm' value='yes'>";
    echo "<p>This will <strong>TRUNCATE</strong> the existing issue_presets table and import <strong>158 web presets</strong> from the SQL export.</p>";
    echo "<p>Existing records will be <strong>deleted</strong>.</p>";
    echo "<button type='submit' style='background:#dc3545;color:#fff;padding:10px 24px;border:none;border-radius:4px;cursor:pointer;font-size:1rem;'>⚠️ Confirm Import (Overwrites existing)</button>";
    echo "</form>";
}

echo "</body></html>";
