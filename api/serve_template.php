<?php
/**
 * Serves the Excel report template file securely (authenticated users only).
 */
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

$templatePath = __DIR__ . '/../assets/templates/report_template.xlsx';

if (!file_exists($templatePath)) {
    http_response_code(404);
    exit('Template not found');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: inline; filename="template.xlsx"');
header('Content-Length: ' . filesize($templatePath));
header('Cache-Control: private, max-age=3600');
readfile($templatePath);
exit;
