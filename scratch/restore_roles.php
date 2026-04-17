<?php

function processDirectory($dir) {
    if (!is_dir($dir)) return;
    
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $path = $file->getRealPath();
            
            // Exclude vendor or standard lib dirs if needed
            if (strpos($path, 'vendor') !== false || strpos($path, 'node_modules') !== false) {
                continue;
            }

            $content = file_get_contents($path);
            $modified = false;

            // Replace 'admin', 'super_admin' with 'admin', 'super_admin'
            if (preg_match("/['\"]admin['\"]\s*,\s*['\"]admin['\"]/", $content)) {
                $content = preg_replace("/(['\"]admin['\"])\s*,\s*(['\"]admin['\"])/", "$1, 'super_admin'", $content);
                $modified = true;
            }

            // Fix issues_page_detail.php requireRole array having duplicate 'admin'
            if (preg_match("/\['admin',\s*'project_lead',\s*'qa',\s*'at_tester',\s*'ft_tester',\s*'admin',\s*'client'\]/", $content)) {
                $content = preg_replace("/\['admin',\s*'project_lead',\s*'qa',\s*'at_tester',\s*'ft_tester',\s*'admin',\s*'client'\]/", "['super_admin', 'admin', 'project_lead', 'qa', 'at_tester', 'ft_tester', 'client']", $content);
                $modified = true;
            }

            // Fix ['admin'] arrays back to ['admin', 'super_admin'] in core permission files
            // Only do this where it's safe (e.g. project_permissions.php, auth.php, or where we know it was changed)
            if (strpos($path, 'project_permissions.php') !== false || strpos($path, 'auth.php') !== false || strpos($path, 'issues_page_detail.php') !== false) {
                if (preg_match("/in_array\([^,]*, \['admin'\](?:, true)?\)/", $content)) {
                    $content = preg_replace("/in_array\(([^,]*), \['admin'\](, true)?\)/", "in_array($1, ['admin', 'super_admin']$2)", $content);
                    $modified = true;
                }
                if (preg_match("/in_array\([^,]*, \['admin', 'super_admin'\w/", $content)) {
                    // Prevent messing up already matching stuff, just checking
                }
                
                // Fix `['admin']` for requireRole in auth.php
                if (preg_match("/requireRole\(\['admin'\]\)/", $content)) {
                    $content = preg_replace("/requireRole\(\['admin'\]\)/", "requireRole(['admin', 'super_admin'])", $content);
                    $modified = true;
                }
            }
            
            // Fix auth.php hierarchy
            if (strpos($path, 'auth.php') !== false) {
                if (strpos($content, "'super_admin' => 6") === false && strpos($content, "'admin' => 5") !== false) {
                    $content = str_replace("'admin' => 5,", "'super_admin' => 6,\n            'admin' => 5,", $content);
                    $modified = true;
                }
            }
            
            if ($modified) {
                file_put_contents($path, $content);
                echo "Fixed $path\n";
            }
        }
    }
}

processDirectory(__DIR__ . '/..');
echo "Done.\n";
