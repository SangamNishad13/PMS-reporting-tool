<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Get user's client_id if they are a client user
$clientId = null;
$stmt = $db->prepare("SELECT client_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$clientId = $user['client_id'] ?? null;

// If not a client user, check if admin viewing specific client
if (!$clientId && in_array($userRole, ['admin', 'super_admin'])) {
    $clientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
}

// Get accessible projects for non-admin users
$accessibleProjectIds = [];
if (!in_array($userRole, ['admin', 'super_admin'])) {
    require_once __DIR__ . '/../../includes/client_permissions.php';
    $accessibleProjectIds = getProjectsWithPermission($db, $userId, 'view_project');
    
    // If no accessible projects, redirect
    if (empty($accessibleProjectIds) && !$clientId) {
        $_SESSION['error'] = "No project access found.";
        redirect("/index.php");
    }
}

if (!$clientId && empty($accessibleProjectIds)) {
    $_SESSION['error'] = "No client or project access found.";
    redirect("/index.php");
}

// Get client information
if ($clientId) {
    $stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        $_SESSION['error'] = "Client not found.";
        redirect("/index.php");
    }
}

// Get all projects for this client or accessible projects
if ($clientId) {
    // For client users, only show projects they have permission to view
    if ($userRole === 'client') {
        require_once __DIR__ . '/../../includes/client_permissions.php';
        $accessibleProjectIds = getProjectsWithPermission($db, $userId, 'view_project');
        
        if (!empty($accessibleProjectIds)) {
            $placeholders = str_repeat('?,', count($accessibleProjectIds) - 1) . '?';
            $stmt = $db->prepare("SELECT id, title, status FROM projects WHERE id IN ($placeholders) AND client_id = ? ORDER BY created_at DESC");
            $params = array_merge($accessibleProjectIds, [$clientId]);
            $stmt->execute($params);
            $projects = $stmt->fetchAll();
        } else {
            $projects = []; // No accessible projects
        }
    } else {
        // For admin viewing client dashboard, show all projects
        $stmt = $db->prepare("SELECT id, title, status FROM projects WHERE client_id = ? ORDER BY created_at DESC");
        $stmt->execute([$clientId]);
        $projects = $stmt->fetchAll();
    }
} else if (!empty($accessibleProjectIds)) {
    $placeholders = str_repeat('?,', count($accessibleProjectIds) - 1) . '?';
    $stmt = $db->prepare("SELECT id, title, status FROM projects WHERE id IN ($placeholders) ORDER BY created_at DESC");
    $stmt->execute($accessibleProjectIds);
    $projects = $stmt->fetchAll();
    
    // Get client name from first project
    if (!empty($projects)) {
        $stmt = $db->prepare("SELECT c.* FROM clients c JOIN projects p ON c.id = p.client_id WHERE p.id = ? LIMIT 1");
        $stmt->execute([$projects[0]['id']]);
        $client = $stmt->fetch();
    }
}

// Selected project filter
$selectedProjectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$projectFilter = $selectedProjectId ? "AND i.project_id = ?" : "";
$projectParams = $selectedProjectId ? [$clientId, $selectedProjectId] : [$clientId];

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* Center charts within their containers */
.card-body > div[style*="position: relative"] {
    display: flex;
    align-items: center;
    justify-content: center;
}

.card-body canvas {
    max-width: 100%;
    height: auto !important;
}
</style>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-chart-line"></i> <?php echo htmlspecialchars($client['name'] ?? 'My Projects'); ?> - Dashboard</h2>
                    <p class="text-muted">Comprehensive accessibility compliance analytics</p>
                </div>
                <div class="d-flex gap-2">
                    <select id="projectFilter" class="form-select" style="width: 300px;">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" <?php echo ($selectedProjectId == $proj['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($proj['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-success" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <button class="btn btn-primary" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6 class="card-title">Total Issues</h6>
                    <h2 id="totalIssues">-</h2>
                    <small>Across all projects</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h6 class="card-title">Blocker Issues</h6>
                    <h2 id="blockerIssues">-</h2>
                    <small>Critical priority</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h6 class="card-title">Open Issues</h6>
                    <h2 id="openIssues">-</h2>
                    <small>Pending resolution</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h6 class="card-title">Compliance Score</h6>
                    <h2 id="complianceScore">-</h2>
                    <small>Overall percentage</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Projects List -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-folder-open"></i> Your Projects</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="projectsTable">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Status</th>
                                    <th>Total Issues</th>
                                    <th>Open Issues</th>
                                    <th>Compliance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projects as $proj): 
                                    // Get project stats with WCAG-based compliance
                                    $totalWCAGCriteria = 57;
                                    
                                    $statsStmt = $db->prepare("
                                        SELECT 
                                            COUNT(DISTINCT i.id) as total_issues,
                                            COUNT(DISTINCT CASE WHEN LOWER(ist.name) IN ('open', 'in progress', 'reopened', 'in_progress') THEN i.id END) as open_issues,
                                            COUNT(DISTINCT CASE WHEN LOWER(ist.name) IN ('resolved', 'closed', 'fixed') THEN i.id END) as resolved_issues
                                        FROM issues i
                                        LEFT JOIN issue_statuses ist ON i.status_id = ist.id
                                        WHERE i.project_id = ? AND i.client_ready = 1
                                    ");
                                    $statsStmt->execute([$proj['id']]);
                                    $stats = $statsStmt->fetch();
                                    
                                    // Calculate WCAG-based compliance
                                    if ($stats['total_issues'] == 0) {
                                        $compliance = 100;
                                    } else {
                                        // Get WCAG criteria data
                                        $criteriaStmt = $db->prepare("
                                            SELECT 
                                                im.meta_value as wcag_sc,
                                                MAX(CASE 
                                                    WHEN i.severity = 'blocker' THEN 4
                                                    WHEN i.severity = 'critical' THEN 3
                                                    WHEN i.severity = 'major' THEN 2
                                                    WHEN i.severity = 'minor' THEN 1
                                                    ELSE 1
                                                END) as max_severity_weight,
                                                COUNT(DISTINCT i.id) as total_issues_in_sc,
                                                COUNT(DISTINCT CASE 
                                                    WHEN LOWER(ist.name) IN ('resolved', 'closed', 'fixed') THEN i.id 
                                                END) as resolved_issues_in_sc
                                            FROM issues i
                                            LEFT JOIN issue_statuses ist ON i.status_id = ist.id
                                            LEFT JOIN issue_metadata im ON i.id = im.issue_id AND im.meta_key = 'wcagsuccesscriteria'
                                            WHERE i.project_id = ? AND i.client_ready = 1
                                            AND im.meta_value IS NOT NULL AND im.meta_value != ''
                                            GROUP BY im.meta_value
                                        ");
                                        $criteriaStmt->execute([$proj['id']]);
                                        $criteriaData = $criteriaStmt->fetchAll();
                                        
                                        $totalWeight = 0;
                                        $earnedPoints = 0;
                                        
                                        foreach ($criteriaData as $criteria) {
                                            $weight = intval($criteria['max_severity_weight']);
                                            $totalIssuesInSC = intval($criteria['total_issues_in_sc']);
                                            $resolvedIssuesInSC = intval($criteria['resolved_issues_in_sc']);
                                            
                                            $totalWeight += $weight;
                                            
                                            if ($totalIssuesInSC > 0 && $resolvedIssuesInSC == $totalIssuesInSC) {
                                                $earnedPoints += $weight;
                                            }
                                        }
                                        
                                        $passingCriteriaCount = $totalWCAGCriteria - count($criteriaData);
                                        $passingCriteriaWeight = $passingCriteriaCount * 1;
                                        $totalWeight += $passingCriteriaWeight;
                                        $earnedPoints += $passingCriteriaWeight;
                                        
                                        if ($totalWeight > 0) {
                                            $compliance = (($earnedPoints + $totalWeight) / (2 * $totalWeight)) * 100;
                                            $compliance = round(max(0, min(100, $compliance)), 1);
                                        } else {
                                            $compliance = 100;
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($proj['title']); ?></strong></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $proj['status'] === 'completed' ? 'success' : 
                                                 ($proj['status'] === 'in_progress' ? 'primary' : 'secondary');
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $proj['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $stats['total_issues']; ?></td>
                                    <td>
                                        <?php if ($stats['open_issues'] > 0): ?>
                                            <span class="badge bg-warning"><?php echo $stats['open_issues']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-<?php echo $compliance >= 80 ? 'success' : ($compliance >= 50 ? 'warning' : 'danger'); ?>" 
                                                 style="width: <?php echo $compliance; ?>%">
                                                <?php echo $compliance; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="<?php echo $baseDir; ?>/modules/projects/view.php?id=<?php echo $proj['id']; ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 1 -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-users"></i> User-Affected Data</h5>
                </div>
                <div class="card-body">
                    <div style="height: 300px; position: relative;">
                        <canvas id="userAffectedChart"></canvas>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm" id="userAffectedTable">
                            <thead>
                                <tr>
                                    <th>User Type</th>
                                    <th>Count</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar"></i> WCAG Failures by Conformance Level</h5>
                </div>
                <div class="card-body">
                    <div style="height: 300px; position: relative;">
                        <canvas id="wcagLevelChart"></canvas>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm" id="wcagLevelTable">
                            <thead>
                                <tr>
                                    <th>Level</th>
                                    <th>Count</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-exclamation-triangle"></i> Issues by Severity</h5>
                </div>
                <div class="card-body">
                    <div style="height: 300px; position: relative; display: flex; align-items: center; justify-content: center;">
                        <canvas id="severityChart"></canvas>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm" id="severityTable">
                            <thead>
                                <tr>
                                    <th>Severity</th>
                                    <th>Count</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-redo"></i> Most Common Issues</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm" id="commonIssuesTable">
                            <thead>
                                <tr>
                                    <th>Issue Title</th>
                                    <th>Occurrences</th>
                                    <th>Severity</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Issues Row -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-ban"></i> Top Critical Issues</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm" id="topBlockersTable">
                            <thead>
                                <tr>
                                    <th>Issue Key</th>
                                    <th>Title</th>
                                    <th>Severity</th>
                                    <th>Status</th>
                                    <th>Page</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-file-alt"></i> Top Pages with Most Issues</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm" id="topPagesTable">
                            <thead>
                                <tr>
                                    <th>Page Name</th>
                                    <th>Issue Count</th>
                                    <th>Project</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Comments and Trends Row -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-comments"></i> Top 5 Most Commented Issues</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm" id="topCommentsTable">
                            <thead>
                                <tr>
                                    <th>Issue Key</th>
                                    <th>Title</th>
                                    <th>Comments</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line"></i> Compliance Score Trend</h5>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary active" data-period="daily">Daily</button>
                        <button type="button" class="btn btn-outline-primary" data-period="weekly">Weekly</button>
                        <button type="button" class="btn btn-outline-primary" data-period="monthly">Monthly</button>
                        <button type="button" class="btn btn-outline-primary" data-period="yearly">Yearly</button>
                    </div>
                </div>
                <div class="card-body">
                    <div style="height: 300px; position: relative;">
                        <canvas id="trendChart"></canvas>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm" id="trendTable">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th>Compliance Score</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const clientId = <?php echo $clientId ?: 'null'; ?>;
const projectId = <?php echo $selectedProjectId ?: 'null'; ?>;
const accessibleProjects = <?php echo !empty($accessibleProjectIds) ? json_encode($accessibleProjectIds) : 'null'; ?>;
let charts = {};
let isLoadingData = false;

// Load all dashboard data
async function loadDashboardData() {
    if (isLoadingData) {
        return;
    }
    
    isLoadingData = true;
    try {
        let url = '<?php echo $baseDir; ?>/api/client_dashboard.php?';
        if (clientId) {
            url += `client_id=${clientId}`;
        } else if (accessibleProjects) {
            url += `project_ids=${accessibleProjects.join(',')}`;
        }
        if (projectId) {
            url += `&project_id=${projectId}`;
        }
        
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success) {
            updateSummaryCards(data.summary);
            renderUserAffectedChart(data.userAffected);
            renderWCAGLevelChart(data.wcagLevels);
            renderSeverityChart(data.severity);
            renderCommonIssues(data.commonIssues);
            renderTopBlockers(data.topBlockers);
            renderTopPages(data.topPages);
            renderTopComments(data.topComments);
            renderTrendChart(data.trend, 'daily');
        } else {
            console.error('Dashboard API error:', data.error);
            alert('Error loading dashboard data: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error loading dashboard data:', error);
        alert('Failed to load dashboard data. Please check console for details.');
    } finally {
        isLoadingData = false;
    }
}

function updateSummaryCards(summary) {
    document.getElementById('totalIssues').textContent = summary.total || 0;
    document.getElementById('blockerIssues').textContent = summary.blockers || 0;
    document.getElementById('openIssues').textContent = summary.open || 0;
    const compliance = summary.compliance !== undefined && summary.compliance !== null ? summary.compliance : 0;
    document.getElementById('complianceScore').textContent = compliance + '%';
}

function renderUserAffectedChart(data) {
    const ctx = document.getElementById('userAffectedChart');
    const wrapper = ctx.parentElement;
    const container = wrapper.parentElement;
    
    if (charts.userAffected) {
        charts.userAffected.destroy();
        charts.userAffected = null;
    }
    
    // Remove any existing no-data messages first
    const existingNoData = container.querySelectorAll('.no-data-message');
    existingNoData.forEach(msg => msg.remove());
    
    // Check if data is empty
    if (!data.values || data.values.length === 0 || data.values.every(v => v === 0)) {
        wrapper.style.display = 'none';
        const noDataDiv = document.createElement('div');
        noDataDiv.className = 'no-data-message text-center text-muted py-5';
        noDataDiv.innerHTML = '<i class="fas fa-chart-pie fa-3x mb-3 opacity-25"></i><p>No data available</p>';
        container.insertBefore(noDataDiv, wrapper);
        // Clear table
        const tbody = document.querySelector('#userAffectedTable tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No data available</td></tr>';
        return;
    }
    
    // Show wrapper if hidden
    wrapper.style.display = 'block';
    
    charts.userAffected = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: data.labels || [],
            datasets: [{
                data: data.values || [],
                backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            animation: false,
            plugins: {
                legend: { position: 'bottom' },
                title: { display: true, text: `Total Issues: ${data.total || 0}` }
            }
        }
    });
    
    // Update table
    const tbody = document.querySelector('#userAffectedTable tbody');
    if (!tbody) return;
    
    const total = data.values.reduce((a, b) => a + b, 0);
    const rows = data.labels.map((label, i) => {
        const percentage = total > 0 ? ((data.values[i] / total) * 100).toFixed(1) : 0;
        return `
            <tr>
                <td>${label}</td>
                <td>${data.values[i]}</td>
                <td>${percentage}%</td>
            </tr>
        `;
    }).join('');
    tbody.innerHTML = rows;
}

function renderWCAGLevelChart(data) {
    const ctx = document.getElementById('wcagLevelChart');
    const wrapper = ctx.parentElement;
    const container = wrapper.parentElement;
    
    if (charts.wcagLevel) {
        charts.wcagLevel.destroy();
        charts.wcagLevel = null;
    }
    
    // Remove any existing no-data messages first
    const existingNoData = container.querySelectorAll('.no-data-message');
    existingNoData.forEach(msg => msg.remove());
    
    // Check if data is empty
    if (!data.values || data.values.length === 0 || data.values.every(v => v === 0)) {
        wrapper.style.display = 'none';
        const noDataDiv = document.createElement('div');
        noDataDiv.className = 'no-data-message text-center text-muted py-5';
        noDataDiv.innerHTML = '<i class="fas fa-chart-bar fa-3x mb-3 opacity-25"></i><p>No data available</p>';
        container.insertBefore(noDataDiv, wrapper);
        // Clear table
        const tbody = document.querySelector('#wcagLevelTable tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No data available</td></tr>';
        return;
    }
    
    // Show wrapper if hidden
    wrapper.style.display = 'block';
    
    charts.wcagLevel = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels || ['Level A', 'Level AA', 'Level AAA'],
            datasets: [{
                label: 'Issue Count',
                data: data.values || [],
                backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            animation: false,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
    
    // Update table
    const tbody = document.querySelector('#wcagLevelTable tbody');
    if (!tbody) return;
    
    const total = data.values.reduce((a, b) => a + b, 0);
    const rows = data.labels.map((label, i) => {
        const percentage = total > 0 ? ((data.values[i] / total) * 100).toFixed(1) : 0;
        return `
            <tr>
                <td><span class="badge bg-info">${label}</span></td>
                <td>${data.values[i]}</td>
                <td>${percentage}%</td>
            </tr>
        `;
    }).join('');
    tbody.innerHTML = rows;
}

function renderSeverityChart(data) {
    const ctx = document.getElementById('severityChart');
    const wrapper = ctx.parentElement;
    const container = wrapper.parentElement;
    
    if (charts.severity) {
        charts.severity.destroy();
        charts.severity = null;
    }
    
    // Remove any existing no-data messages first
    const existingNoData = container.querySelectorAll('.no-data-message');
    existingNoData.forEach(msg => msg.remove());
    
    // Check if data is empty
    if (!data.values || data.values.length === 0 || data.values.every(v => v === 0)) {
        wrapper.style.display = 'none';
        const noDataDiv = document.createElement('div');
        noDataDiv.className = 'no-data-message text-center text-muted py-5';
        noDataDiv.innerHTML = '<i class="fas fa-chart-pie fa-3x mb-3 opacity-25"></i><p>No data available</p>';
        container.insertBefore(noDataDiv, wrapper);
        // Clear table
        const tbody = document.querySelector('#severityTable tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No data available</td></tr>';
        return;
    }
    
    // Show wrapper if hidden
    wrapper.style.display = 'block';
    
    charts.severity = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.labels || [],
            datasets: [{
                data: data.values || [],
                backgroundColor: ['#DC3545', '#FD7E14', '#FFC107', '#28A745', '#17A2B8']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            animation: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
    
    // Update table
    const tbody = document.querySelector('#severityTable tbody');
    if (!tbody) return;
    
    const total = data.values.reduce((a, b) => a + b, 0);
    const rows = data.labels.map((label, i) => {
        const percentage = total > 0 ? ((data.values[i] / total) * 100).toFixed(1) : 0;
        return `
            <tr>
                <td><span class="badge bg-secondary">${label}</span></td>
                <td>${data.values[i]}</td>
                <td>${percentage}%</td>
            </tr>
        `;
    }).join('');
    tbody.innerHTML = rows;
}

function renderCommonIssues(data) {
    const tbody = document.querySelector('#commonIssuesTable tbody');
    if (!tbody) return;
    
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 opacity-25 d-block"></i>No common issues found</td></tr>';
        return;
    }
    
    const rows = data.map(issue => `
        <tr>
            <td>${issue.title}</td>
            <td><span class="badge bg-primary">${issue.count}</span></td>
            <td><span class="badge bg-${getSeverityColor(issue.severity)}">${issue.severity}</span></td>
        </tr>
    `).join('');
    tbody.innerHTML = rows;
}

function renderTopBlockers(data) {
    const tbody = document.querySelector('#topBlockersTable tbody');
    if (!tbody) return;
    
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 opacity-25 d-block"></i>No critical issues found</td></tr>';
        return;
    }
    
    const rows = data.map(issue => {
        const pageLink = issue.page_id ? 
            `<a href="<?php echo $baseDir; ?>/modules/projects/issues_page_detail.php?project_id=${issue.project_id}&page_id=${issue.page_id}" class="text-decoration-none">${issue.page_name}</a>` : 
            'N/A';
        const issueLink = `<a href="<?php echo $baseDir; ?>/modules/projects/issues_page_detail.php?project_id=${issue.project_id}&page_id=${issue.page_id}&issue_id=${issue.id}" class="text-decoration-none"><span class="badge bg-primary">${issue.issue_key}</span></a>`;
        
        return `
            <tr>
                <td>${issueLink}</td>
                <td>${issue.title}</td>
                <td><span class="badge bg-${getSeverityColor(issue.severity)}">${issue.severity}</span></td>
                <td><span class="badge bg-${getStatusColor(issue.status)}">${issue.status}</span></td>
                <td>${pageLink}</td>
            </tr>
        `;
    }).join('');
    tbody.innerHTML = rows;
}

function renderTopPages(data) {
    const tbody = document.querySelector('#topPagesTable tbody');
    if (!tbody) return;
    
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 opacity-25 d-block"></i>No pages with issues found</td></tr>';
        return;
    }
    
    const rows = data.map(page => {
        const pageLink = `<a href="<?php echo $baseDir; ?>/modules/projects/issues_page_detail.php?project_id=${page.project_id}&page_id=${page.page_id}" class="text-decoration-none">${page.page_name}</a>`;
        
        return `
            <tr>
                <td>${pageLink}</td>
                <td><span class="badge bg-danger">${page.issue_count}</span></td>
                <td>${page.project_title}</td>
            </tr>
        `;
    }).join('');
    tbody.innerHTML = rows;
}

function renderTopComments(data) {
    const tbody = document.querySelector('#topCommentsTable tbody');
    if (!tbody) return;
    
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 opacity-25 d-block"></i>No commented issues found</td></tr>';
        return;
    }
    
    const rows = data.map(issue => {
        const issueLink = issue.page_id ? 
            `<a href="<?php echo $baseDir; ?>/modules/projects/issues_page_detail.php?project_id=${issue.project_id}&page_id=${issue.page_id}&issue_id=${issue.id}" class="text-decoration-none"><span class="badge bg-primary">${issue.issue_key}</span></a>` :
            `<span class="badge bg-primary">${issue.issue_key}</span>`;
        
        return `
            <tr>
                <td>${issueLink}</td>
                <td>${issue.title}</td>
                <td><span class="badge bg-info">${issue.comment_count}</span></td>
                <td><span class="badge bg-${getStatusColor(issue.status)}">${issue.status}</span></td>
            </tr>
        `;
    }).join('');
    tbody.innerHTML = rows;
}

function renderTrendChart(data, period) {
    const ctx = document.getElementById('trendChart');
    if (charts.trend) {
        charts.trend.destroy();
        charts.trend = null;
    }
    
    const trendData = data[period] || { labels: [], values: [] };
    
    charts.trend = new Chart(ctx, {
        type: 'line',
        data: {
            labels: trendData.labels,
            datasets: [{
                label: 'Compliance Score %',
                data: trendData.values,
                borderColor: '#28A745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                y: { 
                    beginAtZero: true,
                    max: 100
                }
            }
        }
    });
    
    // Populate trend table
    const tbody = document.querySelector('#trendTable tbody');
    
    if (trendData.labels.length === 0) {
        tbody.innerHTML = '<tr><td colspan="2" class="text-center text-muted">No data available</td></tr>';
        return;
    }
    
    const rows = trendData.labels.map((label, index) => {
        const compliance = trendData.values[index];
        return `
            <tr>
                <td>${label}</td>
                <td>
                    <span class="badge bg-${compliance >= 80 ? 'success' : (compliance >= 50 ? 'warning' : 'danger')}">
                        ${compliance}%
                    </span>
                </td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rows;
}

function getSeverityColor(severity) {
    const colors = {
        'blocker': 'danger',
        'critical': 'danger',
        'major': 'warning',
        'minor': 'info',
        'low': 'secondary'
    };
    return colors[severity] || 'secondary';
}

function getStatusColor(status) {
    const colors = {
        'open': 'danger',
        'in_progress': 'warning',
        'resolved': 'success',
        'closed': 'secondary'
    };
    return colors[status.toLowerCase()] || 'secondary';
}

function exportToPDF() {
    let url = '<?php echo $baseDir; ?>/api/client_export.php?format=pdf';
    if (clientId) {
        url += `&client_id=${clientId}`;
    } else if (accessibleProjects) {
        url += `&project_ids=${accessibleProjects.join(',')}`;
    }
    if (projectId) {
        url += `&project_id=${projectId}`;
    }
    window.open(url, '_blank');
}

function exportToExcel() {
    let url = '<?php echo $baseDir; ?>/api/client_export.php?format=excel';
    if (clientId) {
        url += `&client_id=${clientId}`;
    } else if (accessibleProjects) {
        url += `&project_ids=${accessibleProjects.join(',')}`;
    }
    if (projectId) {
        url += `&project_id=${projectId}`;
    }
    window.open(url, '_blank');
}

// Project filter change
document.getElementById('projectFilter').addEventListener('change', function() {
    const projectId = this.value;
    let url = '<?php echo $baseDir; ?>/modules/client/dashboard.php?';
    if (clientId) {
        url += `client_id=${clientId}`;
    }
    if (projectId) {
        url += `${clientId ? '&' : ''}project_id=${projectId}`;
    }
    window.location.href = url;
});

// Trend period buttons
document.querySelectorAll('[data-period]').forEach(btn => {
    btn.addEventListener('click', async function() {
        document.querySelectorAll('[data-period]').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        const period = this.dataset.period;
        let url = '<?php echo $baseDir; ?>/api/client_dashboard.php?';
        if (clientId) {
            url += `client_id=${clientId}`;
        } else if (accessibleProjects) {
            url += `project_ids=${accessibleProjects.join(',')}`;
        }
        if (projectId) {
            url += `${clientId || accessibleProjects ? '&' : ''}project_id=${projectId}`;
        }
        
        const response = await fetch(url);
        const data = await response.json();
        if (data.success) {
            renderTrendChart(data.trend, period);
        }
    });
});

// Load data on page load
loadDashboardData();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
