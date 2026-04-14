<?php
header('Content-Type: text/html');
echo "<h2>PMS Deployment Diagnostics</h2>";
echo "<b>Current Hash:</b> " . shell_exec("git rev-parse HEAD") . "<br>";
echo "<b>Last Commit Msg:</b> " . shell_exec("git log -1 --pretty=%B") . "<br>";
echo "<b>Last Commit Date:</b> " . shell_exec("git log -1 --pretty=%ai") . "<br>";
echo "<b>Server PHP User:</b> " . posix_getpwuid(posix_geteuid())['name'] . "<br>";
echo "<b>Server Time:</b> " . date('Y-m-d H:i:s') . "<br>";
echo "<hr>";
echo "<b>Remote Branch Status:</b><br>";
echo "<pre>" . shell_exec("git status") . "</pre>";
?>
