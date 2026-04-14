<?php
// Remote Push Script
header('Content-Type: text/plain');

$dirs = ['/var/www/html/PMS', '/var/www/html/PMS-UAT'];
foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        echo "Processing $dir...\n";
        $cmd = "cd " . escapeshellarg($dir) . " && git add . 2>&1 && git commit -m 'Sync live hotfixes to git' 2>&1 && git push origin security/vapt-hardening 2>&1";
        $output = shell_exec($cmd);
        echo $output . "\n\n";
    }
}
echo "Done.";
