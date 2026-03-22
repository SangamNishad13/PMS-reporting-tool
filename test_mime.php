<?php
$f = __DIR__ . '/test_mime.php'; // Just use itself
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $fi = @finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $detected = @finfo_file($fi, $f);
        if (is_string($detected) && $detected !== '') $mime = $detected;
        @finfo_close($fi);
    }
}
$ext = 'png';
if ($mime === 'application/octet-stream' || $mime === '' || $mime === 'text/plain' || $mime === 'text/x-php') {
    $mimeTypes = ['png' => 'image/png'];
    if (isset($mimeTypes[$ext])) $mime = $mimeTypes[$ext];
}
echo "Final MIME: " . $mime . "\n";
