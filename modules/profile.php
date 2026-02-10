<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: " . $baseDir . "/modules/auth/login.php");
    exit;
}

// Get user ID from URL parameter
$userId = isset($_GET['id']) ? intval($_GET['id']) : $_SESSION['user_id'];

if (!$userId) {
    header("Location: " . $baseDir . "/index.php");
    exit;
}

// Connect to database
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get user details
try {
    $stmt = $db->prepare("
        SELECT u.*, COUNT(DISTINCT p.id) as total_projects,
               COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.id END) as completed_projects,
               COUNT(DISTINCT pp.id) as total_pages,
               COUNT(DISTINCT CASE WHEN pp.status = 'completed' THEN pp.id END) as completed_pages,
               COALESCE(SUM(tr.hours_spent), 0) as total_hours_spent
        FROM users u
        LEFT JOIN projects p ON (
            p.project_lead_id = u.id OR
            EXISTS (SELECT 1 FROM project_pages pp2 WHERE pp2.project_id = p.id AND (
                pp2.at_tester_id = u.id OR pp2.ft_tester_id = u.id OR pp2.qa_id = u.id
            ))
        )
        LEFT JOIN project_pages pp ON (
            pp.at_tester_id = u.id OR pp.ft_tester_id = u.id OR pp.qa_id = u.id
        )
        LEFT JOIN testing_results tr ON tr.tester_id = u.id
        WHERE u.id = ? AND u.is_active = 1
        GROUP BY u.id
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION['error'] = "User not found.";
        header("Location: " . $baseDir . "/index.php");
        exit;
    }

} catch (Exception $e) {
    die("Error loading user: " . $e->getMessage());
}

// Get user's recent projects
try {
    $stmt = $db->prepare("\
        SELECT DISTINCT p.id, p.title, p.status, p.priority, p.created_at,
               (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) as current_phase,
               CASE
                   WHEN p.project_lead_id = ? THEN 'Project Lead'
                   WHEN EXISTS (SELECT 1 FROM project_pages pp WHERE pp.project_id = p.id AND pp.at_tester_id = ?) THEN 'AT Tester'
                   WHEN EXISTS (SELECT 1 FROM project_pages pp WHERE pp.project_id = p.id AND pp.ft_tester_id = ?) THEN 'FT Tester'
                   WHEN EXISTS (SELECT 1 FROM project_pages pp WHERE pp.project_id = p.id AND pp.qa_id = ?) THEN 'QA'
                   ELSE 'Team Member'
               END as role_in_project
        FROM projects p
        WHERE p.project_lead_id = ? OR EXISTS (
            SELECT 1 FROM project_pages pp
            WHERE pp.project_id = p.id AND (pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ?)
        )
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    $projects = $stmt->fetchAll();

} catch (Exception $e) {
    $projects = [];
}

// Get user's recent activity (include activity_log and project_time_logs)
try {
    $sql = "(SELECT al.id, al.user_id, al.action, al.entity_type, al.entity_id, al.details, al.ip_address, al.created_at, p.title as project_title, pp.page_name, COALESCE(p.id, pp.project_id) as project_ref_id
        FROM activity_log al
        LEFT JOIN projects p ON al.entity_id = p.id AND al.entity_type = 'project'
        LEFT JOIN project_pages pp ON al.entity_id = pp.id AND al.entity_type = 'page'
        WHERE al.user_id = ?)
        UNION ALL
        (SELECT ptl.id, ptl.user_id, 'hours_logged' as action, 'project_time_log' as entity_type, ptl.id as entity_id, CONCAT('hours=', ptl.hours_spent, ', date=', ptl.log_date, ', desc=', COALESCE(ptl.description, '')) as details, '' as ip_address, ptl.created_at, pr.title as project_title, pp2.page_name, ptl.project_id as project_ref_id
        FROM project_time_logs ptl
        LEFT JOIN projects pr ON ptl.project_id = pr.id
        LEFT JOIN project_pages pp2 ON ptl.page_id = pp2.id
        WHERE ptl.user_id = ?)
        ORDER BY created_at DESC
        LIMIT 10";
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $userId]);
    $activities = $stmt->fetchAll();

} catch (Exception $e) {
    $activities = [];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- User Profile Card -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-user-circle fa-5x text-primary"></i>
                    </div>
                    <h4><?php echo htmlspecialchars($user['full_name']); ?></h4>
                    <p class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></p>
                    <span class="badge bg-<?php
                        echo $user['role'] === 'super_admin' ? 'danger' :
                             ($user['role'] === 'admin' ? 'warning' :
                             ($user['role'] === 'project_lead' ? 'info' :
                             ($user['role'] === 'qa' ? 'success' : 'primary')));
                    ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                    </span>
                    <hr>
                    <div class="row text-center">
                        <div class="col-4">
                            <h5 class="text-primary"><?php echo $user['total_projects']; ?></h5>
                            <small>Projects</small>
                        </div>
                        <div class="col-4">
                            <h5 class="text-success"><?php echo $user['completed_projects']; ?></h5>
                            <small>Completed</small>
                        </div>
                        <div class="col-4">
                            <h5 class="text-info"><?php echo $user['total_pages']; ?></h5>
                            <small>Pages</small>
                        </div>
                    </div>
                    <?php if ($user['role'] === 'at_tester' || $user['role'] === 'ft_tester'): ?>
                    <hr>
                    <div class="text-center">
                        <h6>Total Hours Spent</h6>
                        <h4 class="text-warning"><?php echo number_format($user['total_hours_spent'], 1); ?> hrs</h4>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-address-card"></i> Contact Information</h5>
                </div>
                <div class="card-body recent-activity-body">
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                    <p><strong>Member Since:</strong> <?php echo date('M d, Y', strtotime($user['created_at'])); ?></p>
                    <p><strong>Status:</strong>
                        <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </p>
                </div>
            </div>
            <!-- Admin: View Production Hours By Day -->
            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','super_admin'])): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clock"></i> Production Hours (By Day)</h5>
                </div>
                <div class="card-body">
                    <div class="row g-2 align-items-center">
                        <div class="col-auto">
                            <input type="date" id="ph_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-auto">
                            <button id="ph_fetch" class="btn btn-primary">View</button>
                        </div>
                        <div class="col-12 mt-3">
                            <div id="ph_result">
                                <p class="text-muted">Select a date and click <strong>View</strong> to load production hours.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- User Details -->
        <div class="col-md-8">
            <!-- Recent Projects -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-project-diagram"></i> Projects (<?php echo count($projects); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($projects)): ?>
                        <p class="text-muted">No projects found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Priority</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projects as $project): ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $project['id']; ?>">
                                                    <?php echo htmlspecialchars($project['title']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo $project['role_in_project']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php
                                                    echo $project['status'] === 'completed' ? 'success' :
                                                         ($project['status'] === 'in_progress' ? 'primary' :
                                                         ($project['status'] === 'on_hold' ? 'warning' : 'secondary'));
                                                ?>">
                                                    <?php echo formatProjectStatusLabel($project['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php
                                                    echo $project['priority'] === 'critical' ? 'danger' :
                                                         ($project['priority'] === 'high' ? 'warning' : 'secondary');
                                                ?>">
                                                    <?php echo ucfirst($project['priority']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($project['current_phase'])): ?>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($project['current_phase']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Recent Activity</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($activities)): ?>
                        <p class="text-muted">No recent activity.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($activities as $activity): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-primary"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">
                                        <?php echo htmlspecialchars($activity['action']); ?>
                                        <?php if (!empty($activity['project_ref_id']) && $activity['project_title']): ?>
                                            in <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo intval($activity['project_ref_id']); ?>">
                                                <?php echo htmlspecialchars($activity['project_title']); ?>
                                            </a>
                                        <?php elseif (!empty($activity['page_name'])): ?>
                                            on page "<?php echo htmlspecialchars($activity['page_name']); ?>"
                                        <?php endif; ?>
                                    </h6>
                                    <small class="text-muted">
                                        <?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-marker {
    position: absolute;
    left: -22px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #fff;
}

.timeline-content {
    background: #f8f9fa;
    padding: 10px 15px;
    border-radius: 5px;
    border-left: 3px solid #007bff;
}

.recent-activity-body {
    max-height: 360px;
    overflow-y: auto;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var phFetch = document.getElementById('ph_fetch');
    if (!phFetch) return;
    var phDate = document.getElementById('ph_date');
    var phResult = document.getElementById('ph_result');

    phFetch.addEventListener('click', function() {
        var date = phDate.value;
        phResult.innerHTML = '<p class="text-muted">Loading...</p>';

        var xhr = new XMLHttpRequest();
        var params = 'user_id=' + encodeURIComponent(<?php echo intval($userId); ?>) + '&date=' + encodeURIComponent(date);
        xhr.open('GET', '<?php echo $baseDir; ?>/api/user_hours.php?' + params, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                } catch (e) {
                    phResult.innerHTML = '<p class="text-danger">Invalid response from server.</p>';
                    return;
                }

                if (!res.success) {
                    phResult.innerHTML = '<p class="text-danger">' + (res.error || 'Error loading hours') + '</p>';
                    return;
                }

                var html = '<h6>Total: <span class="badge bg-info">' + parseFloat(res.total_hours).toFixed(2) + ' hrs</span></h6>';
                if (res.entries && res.entries.length) {
                    html += '<div class="list-group mt-2">';
                    res.entries.forEach(function(en) {
                        var title = en.project_title ? en.project_title : '—';
                        var page = en.page_name ? en.page_name : '—';
                        var time = en.tested_at ? new Date(en.tested_at).toLocaleString() : '';
                        html += '<div class="list-group-item">';
                        html += '<div class="d-flex w-100 justify-content-between"><strong>' + escapeHtml(title) + '</strong><small>' + escapeHtml(time) + '</small></div>';
                        html += '<div class="mb-1">Page: ' + escapeHtml(page) + '</div>';
                        html += '<div>Hours: <span class="badge bg-secondary">' + parseFloat(en.hours_spent || 0).toFixed(2) + '</span></div>';
                        if (en.comments) html += '<div class="mt-1 text-muted">' + escapeHtml(en.comments) + '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                } else {
                    html += '<p class="text-muted mt-2">No entries for this date.</p>';
                }

                phResult.innerHTML = html;
            } else if (xhr.status === 403) {
                phResult.innerHTML = '<p class="text-danger">Access denied.</p>';
            } else {
                phResult.innerHTML = '<p class="text-danger">Error loading data.</p>';
            }
        };
        xhr.send();
    });

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[s];
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>