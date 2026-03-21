<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$baseDir = getBaseDir();

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

function ensureUsernameHistoryTable($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS username_change_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                old_username VARCHAR(100) NOT NULL,
                new_username VARCHAR(100) NOT NULL,
                changed_by INT NOT NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                INDEX idx_user_changed_at (user_id, changed_at),
                INDEX idx_changed_by (changed_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        // Keep page functional even if history table creation fails.
    }
}

// Allow users to update only their own username
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_username'])) {
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId !== (int)$userId) {
        $_SESSION['error'] = "You can only update your own username.";
        header("Location: " . $baseDir . "/modules/profile.php?id=" . $userId);
        exit;
    }

    $newUsername = trim((string)($_POST['username'] ?? ''));
    if ($newUsername === '') {
        $_SESSION['error'] = "Username is required.";
        header("Location: " . $baseDir . "/modules/profile.php?id=" . $userId);
        exit;
    }
    if (strlen($newUsername) < 3 || strlen($newUsername) > 50) {
        $_SESSION['error'] = "Username must be between 3 and 50 characters.";
        header("Location: " . $baseDir . "/modules/profile.php?id=" . $userId);
        exit;
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $newUsername)) {
        $_SESSION['error'] = "Username can contain only letters, numbers, dot, underscore, and hyphen.";
        header("Location: " . $baseDir . "/modules/profile.php?id=" . $userId);
        exit;
    }

    $checkStmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id <> ? LIMIT 1");
    $checkStmt->execute([$newUsername, $currentUserId]);
    if ($checkStmt->fetch()) {
        $_SESSION['error'] = "Username already exists. Please choose a different username.";
        header("Location: " . $baseDir . "/modules/profile.php?id=" . $userId);
        exit;
    }

    $currentStmt = $db->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $currentStmt->execute([$currentUserId]);
    $currentUsername = (string)($currentStmt->fetchColumn() ?? '');

    if (strcasecmp($currentUsername, $newUsername) === 0) {
        $_SESSION['success'] = "Username is already up to date.";
        header("Location: " . $baseDir . "/modules/profile.php?id=" . $userId);
        exit;
    }

    try {
        // Ensure history table outside transaction to avoid implicit DDL commits.
        ensureUsernameHistoryTable($db);

        $db->beginTransaction();
        $updateStmt = $db->prepare("UPDATE users SET username = ? WHERE id = ?");
        $ok = $updateStmt->execute([$newUsername, $currentUserId]);
        if (!$ok) {
            $db->rollBack();
            $_SESSION['error'] = "Failed to update username.";
        } else {
            $db->commit();
            $_SESSION['username'] = $newUsername;
            $_SESSION['success'] = "Username updated successfully.";

            // History logging should not fail the username update.
            try {
                $historyStmt = $db->prepare("
                    INSERT INTO username_change_history (user_id, old_username, new_username, changed_by, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $historyStmt->execute([
                    $currentUserId,
                    $currentUsername,
                    $newUsername,
                    $currentUserId,
                    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
                ]);
            } catch (Exception $historyEx) {
                error_log("Username history insert failed for user {$currentUserId}: " . $historyEx->getMessage());
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "Failed to update username.";
        error_log("Username update failed for user {$currentUserId}: " . $e->getMessage());
    }
    header("Location: " . $baseDir . "/modules/profile.php?id=" . $userId);
    exit;
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

// Get user's assigned projects with robust multi-source lookup
$projects = [];
try {
    $projectRoleMap = [];
    $rolePriority = [
        'Project Lead' => 1,
        'QA' => 2,
        'AT Tester' => 3,
        'FT Tester' => 4,
        'Tester' => 5,
        'Team Member' => 99
    ];
    $setRole = function ($projectId, $role) use (&$projectRoleMap, $rolePriority) {
        $projectId = (int)$projectId;
        if ($projectId <= 0) return;
        if (!isset($projectRoleMap[$projectId]) || ($rolePriority[$role] ?? 999) < ($rolePriority[$projectRoleMap[$projectId]] ?? 999)) {
            $projectRoleMap[$projectId] = $role;
        }
    };

    $stmt = $db->prepare("SELECT id FROM projects WHERE project_lead_id = ?");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $setRole($pid, 'Project Lead');
    }

    $stmt = $db->prepare("SELECT project_id, role FROM user_assignments WHERE user_id = ? AND (is_removed IS NULL OR is_removed = 0)");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rawRole = strtolower((string)($r['role'] ?? ''));
        $label = $rawRole === 'qa' ? 'QA' : ($rawRole === 'at_tester' ? 'AT Tester' : ($rawRole === 'ft_tester' ? 'FT Tester' : 'Team Member'));
        $setRole($r['project_id'], $label);
    }

    $stmt = $db->prepare("
        SELECT DISTINCT project_id,
               CASE
                   WHEN at_tester_id = ? THEN 'AT Tester'
                   WHEN ft_tester_id = ? THEN 'FT Tester'
                   WHEN qa_id = ? THEN 'QA'
                   ELSE 'Team Member'
               END AS role_name
        FROM project_pages
        WHERE at_tester_id = ? OR ft_tester_id = ? OR qa_id = ?
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $setRole($r['project_id'], $r['role_name']);
    }

    $stmt = $db->prepare("
        SELECT DISTINCT pp.project_id,
               CASE
                   WHEN pe.at_tester_id = ? THEN 'AT Tester'
                   WHEN pe.ft_tester_id = ? THEN 'FT Tester'
                   WHEN pe.qa_id = ? THEN 'QA'
                   ELSE 'Team Member'
               END AS role_name
        FROM page_environments pe
        JOIN project_pages pp ON pp.id = pe.page_id
        WHERE pe.at_tester_id = ? OR pe.ft_tester_id = ? OR pe.qa_id = ?
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $setRole($r['project_id'], $r['role_name']);
    }

    if (!empty($projectRoleMap)) {
        $projectIds = array_keys($projectRoleMap);
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $projStmt = $db->prepare("
            SELECT p.id, p.title, p.status, p.priority, p.created_at,
                   (SELECT phase_name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'in_progress' ORDER BY ph.start_date DESC LIMIT 1) AS current_phase
            FROM projects p
            WHERE p.id IN ($placeholders)
            ORDER BY p.created_at DESC
        ");
        $projStmt->execute($projectIds);
        $projects = $projStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($projects as &$p) {
            $p['role_in_project'] = $projectRoleMap[(int)$p['id']] ?? 'Team Member';
        }
        unset($p);
    }

    $user['total_projects'] = count($projects);
    $completedCount = 0;
    foreach ($projects as $p) {
        if (($p['status'] ?? '') === 'completed') {
            $completedCount++;
        }
    }
    $user['completed_projects'] = $completedCount;
} catch (Exception $e) {
    error_log('Profile projects query failed for user ' . (int)$userId . ': ' . $e->getMessage());
    $projects = [];
}

// Get assigned task list (project pages/environment tasks)
try {
    $taskStmt = $db->prepare("
        SELECT
            pp.id AS page_id,
            pp.page_name,
            pp.status AS page_status,
            p.id AS project_id,
            p.title AS project_title
        FROM project_pages pp
        JOIN projects p ON p.id = pp.project_id
        WHERE
            pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ?
            OR EXISTS (
                SELECT 1
                FROM page_environments pe
                WHERE pe.page_id = pp.id
                  AND (pe.at_tester_id = ? OR pe.ft_tester_id = ? OR pe.qa_id = ?)
            )
        GROUP BY pp.id, pp.page_name, pp.status, p.id, p.title
        ORDER BY p.created_at DESC, pp.id DESC
        LIMIT 30
    ");
    $taskStmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    $assignedTasks = $taskStmt->fetchAll();
} catch (Exception $e) {
    error_log('Profile tasks query failed for user ' . (int)$userId . ': ' . $e->getMessage());
    $assignedTasks = [];
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

$canViewUsernameHistory = in_array((string)($_SESSION['role'] ?? ''), ['admin', 'super_admin'], true);
$usernameHistory = [];
if ($canViewUsernameHistory) {
    ensureUsernameHistoryTable($db);
    try {
        $historyListStmt = $db->prepare("
            SELECT h.*, u.full_name AS changed_by_name, u.username AS changed_by_username
            FROM username_change_history h
            LEFT JOIN users u ON u.id = h.changed_by
            WHERE h.user_id = ?
            ORDER BY h.changed_at DESC, h.id DESC
            LIMIT 100
        ");
        $historyListStmt->execute([$userId]);
        $usernameHistory = $historyListStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $usernameHistory = [];
    }
}

$flashSuccess = isset($_SESSION['success']) ? (string)$_SESSION['success'] : '';
$flashError = isset($_SESSION['error']) ? (string)$_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <?php if ($flashSuccess !== ''): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($flashSuccess); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($flashError); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
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
                    <?php if ((int)$userId === (int)($_SESSION['user_id'] ?? 0)): ?>
                    <form method="POST" action="<?php echo $baseDir; ?>/modules/profile.php?id=<?php echo (int)$userId; ?>" class="mb-2">
                        <input type="hidden" name="update_username" value="1">
                        <label for="profileUsername" class="form-label mb-1"><strong>Username:</strong></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">@</span>
                            <input type="text"
                                   class="form-control"
                                   id="profileUsername"
                                   name="username"
                                   value="<?php echo htmlspecialchars($user['username']); ?>"
                                   minlength="3"
                                   maxlength="50"
                                   pattern="[A-Za-z0-9._-]+"
                                   required>
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>
                        <small class="text-muted">Allowed: letters, numbers, dot, underscore, hyphen. Must be unique.</small>
                    </form>
                    <?php else: ?>
                    <p><strong>Username:</strong> @<?php echo htmlspecialchars($user['username']); ?></p>
                    <?php endif; ?>
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
                                        <th>Current Phase</th>
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

            <?php if ($canViewUsernameHistory): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-user-edit"></i> Username Change History</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($usernameHistory)): ?>
                        <p class="text-muted mb-0">No username changes found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Changed At</th>
                                        <th>Old Username</th>
                                        <th>New Username</th>
                                        <th>Changed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usernameHistory as $h): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y H:i:s', strtotime($h['changed_at'])); ?></td>
                                        <td>@<?php echo htmlspecialchars($h['old_username']); ?></td>
                                        <td>@<?php echo htmlspecialchars($h['new_username']); ?></td>
                                        <td><?php echo htmlspecialchars($h['changed_by_name'] ?: ($h['changed_by_username'] ?: ('User #' . (int)$h['changed_by']))); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Assigned Tasks -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-tasks"></i> Assigned Tasks (<?php echo count($assignedTasks); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($assignedTasks)): ?>
                        <p class="text-muted">No assigned tasks found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Task/Page</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignedTasks as $task): ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo intval($task['project_id']); ?>">
                                                    <?php echo htmlspecialchars($task['project_title']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($task['page_name'] ?: ('Page #' . intval($task['page_id']))); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo (($task['page_status'] ?? '') === 'completed') ? 'success' : 'secondary'; ?>">
                                                    <?php echo htmlspecialchars(formatProjectStatusLabel($task['page_status'] ?: 'not_started')); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a class="btn btn-sm btn-outline-primary" href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo intval($task['project_id']); ?>">
                                                    Open
                                                </a>
                                            </td>
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
                <div class="card-body recent-activity-scroll">
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

.recent-activity-scroll {
    max-height: 420px;
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
