<?php
$f = 'c:/xampp/htdocs/PMS/api/issues.php';
$c = file_get_contents($f);
$o = "'common_title' => isset(\$meta['common_title']) && is_array(\$meta['common_title']) ? \$meta['common_title'][0] : (\$meta['common_title'] ?? ''),";
$n = "'common_title' => (string)(\$i['common_title_val'] ?? (isset(\$meta['common_title']) && is_array(\$meta['common_title']) ? \$meta['common_title'][0] : (\$meta['common_title'] ?? ''))),";

if (strpos($c, $o) !== false) {
    $nc = str_replace($o, $n, $c);
    file_put_contents($f, $nc);
    echo "SUCCESS\n";
} else {
    echo "NOT FOUND\n";
}
