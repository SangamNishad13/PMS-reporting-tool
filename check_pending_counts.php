<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$auth = new Auth();
$auth->requireRole('admin');
$db = Database::getInstance();

echo "<h2>Pending Counts Diagnostic</h2>";

// Count from user_edit_requests
$editRequestsCount = $db->query("SELECT COUNT(*) FROM user_edit_requests WHERE status = 'pending'")->fetchColumn();
echo "<p><strong>Pending Edit Requests (user_edit_requests table):</strong> " . $editRequestsCount . "</p>";

// Count from user_pending_log_edits
$logEditsCount = $db->query("SELECT COUNT(*) FROM user_pending_log_edits WHERE status = 'pending'")->fetchColumn();
echo "<p><strong>Pending Log Edit Items (user_pending_log_edits table):</strong> " . $logEditsCount . "</p>";

// Count from user_pending_log_deletions
$logDeletionsCount = $db->query("SELECT COUNT(*) FROM user_pending_log_deletions WHERE status = 'pending'")->fetchColumn();
echo "<p><strong>Pending Log Delete Items (user_pending_log_deletions table):</strong> " . $logDeletionsCount . "</p>";

echo "<hr>";
echo "<h3>Total Pending: " . ($editRequestsCount + $logEditsCount + $logDeletionsCount) . "</h3>";

echo "<hr>";
echo "<h3>Details from user_pending_log_edits:</h3>";
$logEdits = $db->query("
    SELECT ple.*, u.full_name, u.username 
    FROM user_pending_log_edits ple
    JOIN users u ON ple.user_id = u.id
    WHERE ple.status = 'pending'
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($logEdits)) {
    echo "<p>No pending log edits found.</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>User</th><th>Date</th><th>Log ID</th><th>New Hours</th><th>Status</th><th>Created At</th></tr>";
    foreach ($logEdits as $edit) {
        echo "<tr>";
        echo "<td>" . $edit['id'] . "</td>";
        echo "<td>" . htmlspecialchars($edit['full_name']) . " (@" . htmlspecialchars($edit['username']) . ")</td>";
        echo "<td>" . $edit['req_date'] . "</td>";
        echo "<td>" . $edit['log_id'] . "</td>";
        echo "<td>" . $edit['new_hours'] . "</td>";
        echo "<td>" . $edit['status'] . "</td>";
        echo "<td>" . $edit['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<h3>Details from user_edit_requests:</h3>";
$editRequests = $db->query("
    SELECT uer.*, u.full_name, u.username 
    FROM user_edit_requests uer
    JOIN users u ON uer.user_id = u.id
    WHERE uer.status = 'pending'
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($editRequests)) {
    echo "<p>No pending edit requests found.</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>User</th><th>Request Date</th><th>Request Type</th><th>Status</th><th>Created At</th></tr>";
    foreach ($editRequests as $req) {
        echo "<tr>";
        echo "<td>" . $req['id'] . "</td>";
        echo "<td>" . htmlspecialchars($req['full_name']) . " (@" . htmlspecialchars($req['username']) . ")</td>";
        echo "<td>" . $req['req_date'] . "</td>";
        echo "<td>" . ($req['request_type'] ?? 'N/A') . "</td>";
        echo "<td>" . $req['status'] . "</td>";
        echo "<td>" . $req['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
