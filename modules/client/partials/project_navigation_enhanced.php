<?php
/**
 * Enhanced Project Navigation Partial
 * 
 * Advanced navigation between individual project pages and unified dashboard
 * Provides easy switching with enhanced user experience
 * 
 * Requirements: 13.5 - Provide easy switching between individual project pages and unified dashboard
 */

$assignedProjects = $assignedProjects ?? [];
$currentProjectIndex = array_search($projectId, array_column($assignedProjects, 'id'));
?>

<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="Enhanced project navigation" class="enhanced-project-nav">
            
            <!-- Main Navigation Bar -->
            <div class="nav-main-bar">
                
                <!-- Breadcrumb with Enhanced Links -->
                <div class="nav-breadcrumb">
                    <ol class="breadcrumb enhanced-breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="<?php echo $baseDir; ?>/modules/client/dashboard.php" class="nav-link-enhanced">
                                <i class="fas fa-tachometer-alt"></i>
                                <span>Unified Dashboard</span>
                                <small>All projects overview</small>
                            </a>
                        </li>
                        <li class="breadcrumb-item">
                            <a href="<?php echo $baseDir; ?>/modules/client/projects.php" class="nav-link-enhanced">
                                <i class="fas fa-folder-open"></i>
                                <span>Projects List</span>
                                <small><?php echo count($assignedProjects); ?> assigned</small>
                            </a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">
                            <div class="nav-link-enhanced current">
                                <i class="fas fa-chart-line"></i>
                                <span><?php echo htmlspecialchars($project['title']); ?></span>
                                <small>Project analytics</small>
                            </div>
                        </li>
                    </ol>
                </div>
                
                <!-- Quick Actions -->
                <div class="nav-quick-actions">
                    <div class="btn-group" role="group" aria-label="Navigation actions">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="showProjectSwitcher()">
                            <i class="fas fa-exchange-alt"></i>
                            <span class="d-none d-md-inline">Switch Project</span>
                        </button>
                        <a href="<?php echo $baseDir; ?>/modules/client/dashboard.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-th-large"></i>
                            <span class="d-none d-md-inline">Dashboard</span>
                        </a>
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="showNavigationHelp()">
                            <i class="fas fa-question-circle"></i>
                            <span class="d-none d-lg-inline">Help</span>
                        </button>
                    </div>
                </div>
                
            </div>
            
            <!-- Enhanced Project Switcher (Initially Hidden) -->
            <div id="projectSwitcherPanel" class="project-switcher-panel" style="display: none;">
                <div class="switcher-header">
                    <h5>
                        <i class="fas fa-exchange-alt text-primary"></i>
                        Switch to Another Project
                    </h5>
                    <button type="button" class="btn-close" onclick="hideProjectSwitcher()"></button>
                </div>
                
                <div class="switcher-content">
                    <!-- Search/Filter -->
                    <div class="switcher-search">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" class="form-control" id="projectSearchInput" 
                                   placeholder="Search projects..." onkeyup="filterProjects()">
                        </div>
                    </div>
                    
                    <!-- Projects Grid -->
                    <div class="switcher-projects-grid" id="projectsGrid">
                        <?php foreach ($assignedProjects as $index => $proj): 
                            $isCurrentProject = ($proj['id'] == $projectId);
                            $projectStats = $accessControl->getProjectStatistics($clientUserId, $proj['id']);
                        ?>
                        <div class="switcher-project-card <?php echo $isCurrentProject ? 'current-project' : ''; ?>" 
                             data-project-name="<?php echo strtolower($proj['title']); ?>"
                             data-project-status="<?php echo $proj['status']; ?>">
                            
                            <?php if ($isCurrentProject): ?>
                            <div class="current-project-indicator">
                                <i class="fas fa-eye"></i> Currently Viewing
                            </div>
                            <?php else: ?>
                            <a href="<?php echo $baseDir; ?>/modules/client/project_dashboard.php?id=<?php echo $proj['id']; ?>" 
                               class="project-switch-link">
                            <?php endif; ?>
                            
                                <div class="switcher-project-header">
                                    <h6 class="project-name"><?php echo htmlspecialchars($proj['title']); ?></h6>
                                    <span class="project-status badge bg-<?php 
                                        echo $proj['status'] === 'completed' ? 'success' : 
                                             ($proj['status'] === 'in_progress' ? 'primary' : 'secondary');
                                    ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $proj['status'])); ?>
                                    </span>
                                </div>
                                
                                <div class="switcher-project-stats">
                                    <div class="stat-mini">
                                        <span class="stat-value"><?php echo $projectStats['client_ready_issues'] ?? 0; ?></span>
                                        <span class="stat-label">Issues</span>
                                    </div>
                                    <div class="stat-mini">
                                        <span class="stat-value text-success"><?php echo $projectStats['resolved_issues'] ?? 0; ?></span>
                                        <span class="stat-label">Resolved</span>
                                    </div>
                                    <div class="stat-mini">
                                        <span class="stat-value text-info"><?php echo round($projectStats['compliance_score'] ?? 0, 0); ?>%</span>
                                        <span class="stat-label">Compliance</span>
                                    </div>
                                </div>
                                
                                <?php if (!$isCurrentProject): ?>
                                <div class="switcher-project-actions">
                                    <span class="switch-hint">
                                        <i class="fas fa-arrow-right"></i> Click to switch
                                    </span>
                                </div>
                                <?php endif; ?>
                            
                            <?php if (!$isCurrentProject): ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Quick Navigation -->
                    <div class="switcher-quick-nav">
                        <h6>Quick Navigation</h6>
                        <div class="quick-nav-buttons">
                            <a href="<?php echo $baseDir; ?>/modules/client/dashboard.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-th-large"></i> Unified Dashboard
                            </a>
                            <a href="<?php echo $baseDir; ?>/modules/client/projects.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-list"></i> All Projects
                            </a>
                            <?php if (count($assignedProjects) > 1): ?>
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="showRandomProject()">
                                <i class="fas fa-random"></i> Random Project
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Project Navigation Arrows -->
            <?php if (count($assignedProjects) > 1): ?>
            <div class="project-nav-arrows">
                <?php 
                $prevProject = null;
                $nextProject = null;
                
                if ($currentProjectIndex !== false) {
                    if ($currentProjectIndex > 0) {
                        $prevProject = $assignedProjects[$currentProjectIndex - 1];
                    }
                    if ($currentProjectIndex < count($assignedProjects) - 1) {
                        $nextProject = $assignedProjects[$currentProjectIndex + 1];
                    }
                }
                ?>
                
                <div class="nav-arrow prev-arrow">
                    <?php if ($prevProject): ?>
                    <a href="<?php echo $baseDir; ?>/modules/client/project_dashboard.php?id=<?php echo $prevProject['id']; ?>" 
                       class="nav-arrow-link" title="Previous: <?php echo htmlspecialchars($prevProject['title']); ?>">
                        <i class="fas fa-chevron-left"></i>
                        <span class="arrow-text">
                            <small>Previous</small>
                            <strong><?php echo htmlspecialchars(substr($prevProject['title'], 0, 20)); ?><?php echo strlen($prevProject['title']) > 20 ? '...' : ''; ?></strong>
                        </span>
                    </a>
                    <?php else: ?>
                    <div class="nav-arrow-link disabled">
                        <i class="fas fa-chevron-left"></i>
                        <span class="arrow-text">
                            <small>Previous</small>
                            <strong>None</strong>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="nav-position-indicator">
                    <span class="position-text">
                        <?php echo ($currentProjectIndex + 1); ?> of <?php echo count($assignedProjects); ?>
                    </span>
                    <div class="position-dots">
                        <?php for ($i = 0; $i < count($assignedProjects); $i++): ?>
                        <span class="position-dot <?php echo ($i === $currentProjectIndex) ? 'active' : ''; ?>"></span>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="nav-arrow next-arrow">
                    <?php if ($nextProject): ?>
                    <a href="<?php echo $baseDir; ?>/modules/client/project_dashboard.php?id=<?php echo $nextProject['id']; ?>" 
                       class="nav-arrow-link" title="Next: <?php echo htmlspecialchars($nextProject['title']); ?>">
                        <span class="arrow-text">
                            <small>Next</small>
                            <strong><?php echo htmlspecialchars(substr($nextProject['title'], 0, 20)); ?><?php echo strlen($nextProject['title']) > 20 ? '...' : ''; ?></strong>
                        </span>
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php else: ?>
                    <div class="nav-arrow-link disabled">
                        <span class="arrow-text">
                            <small>Next</small>
                            <strong>None</strong>
                        </span>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
        </nav>
    </div>
</div>

<!-- Navigation Help Modal -->
<div class="modal fade" id="navigationHelpModal" tabindex="-1" aria-labelledby="navigationHelpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="navigationHelpModalLabel">
                    <i class="fas fa-compass text-primary"></i>
                    Navigation Guide
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="help-section">
                    <h6><i class="fas fa-th-large text-primary"></i> Unified Dashboard</h6>
                    <p>View analytics from all your assigned projects in one comprehensive dashboard. Perfect for getting an overview of your entire portfolio.</p>
                </div>
                
                <div class="help-section">
                    <h6><i class="fas fa-chart-line text-success"></i> Individual Project Pages</h6>
                    <p>Dive deep into specific project analytics with enhanced detail levels, project-specific insights, and detailed breakdowns.</p>
                </div>
                
                <div class="help-section">
                    <h6><i class="fas fa-exchange-alt text-info"></i> Project Switching</h6>
                    <p>Quickly switch between projects using the project switcher panel, navigation arrows, or keyboard shortcuts (← → arrow keys).</p>
                </div>
                
                <div class="help-section">
                    <h6><i class="fas fa-keyboard text-warning"></i> Keyboard Shortcuts</h6>
                    <ul class="shortcut-list">
                        <li><kbd>←</kbd> Previous project</li>
                        <li><kbd>→</kbd> Next project</li>
                        <li><kbd>D</kbd> Go to unified dashboard</li>
                        <li><kbd>P</kbd> Show project switcher</li>
                        <li><kbd>Esc</kbd> Close project switcher</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Got it!</button>
            </div>
        </div>
    </div>
</div>

<style>
.enhanced-project-nav {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    border: 1px solid #e9ecef;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.nav-main-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: #fff;
    border-bottom: 1px solid #e9ecef;
}

.enhanced-breadcrumb {
    margin: 0;
    background: none;
    padding: 0;
}

.nav-link-enhanced {
    display: flex;
    flex-direction: column;
    gap: 2px;
    text-decoration: none;
    color: #2563eb;
    transition: all 0.2s ease;
    padding: 8px 12px;
    border-radius: 6px;
}

.nav-link-enhanced:hover {
    background: #f0f7ff;
    color: #1d4ed8;
    text-decoration: none;
}

.nav-link-enhanced.current {
    color: #6c757d;
    cursor: default;
}

.nav-link-enhanced span {
    font-weight: 500;
    font-size: 0.9rem;
}

.nav-link-enhanced small {
    font-size: 0.75rem;
    opacity: 0.8;
}

.nav-link-enhanced i {
    font-size: 0.8rem;
    opacity: 0.7;
    margin-right: 4px;
}

.nav-quick-actions .btn {
    border-radius: 6px;
    font-weight: 500;
}

.project-switcher-panel {
    background: #fff;
    border-top: 1px solid #e9ecef;
    animation: slideDown 0.3s ease-out;
}

.switcher-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #e9ecef;
    background: #f8f9fa;
}

.switcher-header h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 8px;
}

.switcher-content {
    padding: 20px;
}

.switcher-search {
    margin-bottom: 20px;
}

.switcher-projects-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.switcher-project-card {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 16px;
    transition: all 0.2s ease;
    position: relative;
}

.switcher-project-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border-color: #2563eb;
}

.switcher-project-card.current-project {
    background: #e3f2fd;
    border-color: #2196f3;
}

.current-project-indicator {
    position: absolute;
    top: -1px;
    right: -1px;
    background: #2196f3;
    color: white;
    font-size: 0.7rem;
    padding: 4px 8px;
    border-radius: 0 8px 0 8px;
    font-weight: 500;
}

.project-switch-link {
    text-decoration: none;
    color: inherit;
    display: block;
}

.project-switch-link:hover {
    text-decoration: none;
    color: inherit;
}

.switcher-project-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
    gap: 8px;
}

.project-name {
    font-size: 0.95rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
    line-height: 1.3;
    flex: 1;
}

.project-status {
    font-size: 0.7rem;
    padding: 2px 6px;
    flex-shrink: 0;
}

.switcher-project-stats {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.stat-mini {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.stat-mini .stat-value {
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1;
}

.stat-mini .stat-label {
    font-size: 0.7rem;
    color: #6c757d;
    margin-top: 2px;
}

.switcher-project-actions {
    text-align: center;
    margin-top: 8px;
}

.switch-hint {
    font-size: 0.75rem;
    color: #2563eb;
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.switcher-quick-nav {
    border-top: 1px solid #e9ecef;
    padding-top: 16px;
}

.switcher-quick-nav h6 {
    font-size: 0.9rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 12px;
}

.quick-nav-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.project-nav-arrows {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
}

.nav-arrow {
    flex: 1;
    max-width: 200px;
}

.nav-arrow.next-arrow {
    text-align: right;
}

.nav-arrow-link {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    color: #2563eb;
    padding: 8px 12px;
    border-radius: 6px;
    transition: all 0.2s ease;
    font-size: 0.85rem;
}

.nav-arrow-link:hover {
    background: #e3f2fd;
    color: #1d4ed8;
    text-decoration: none;
}

.nav-arrow-link.disabled {
    color: #6c757d;
    cursor: not-allowed;
    opacity: 0.6;
}

.arrow-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.arrow-text small {
    font-size: 0.7rem;
    opacity: 0.8;
}

.arrow-text strong {
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1.2;
}

.nav-position-indicator {
    text-align: center;
    flex: 0 0 auto;
}

.position-text {
    font-size: 0.8rem;
    color: #6c757d;
    font-weight: 500;
    display: block;
    margin-bottom: 4px;
}

.position-dots {
    display: flex;
    justify-content: center;
    gap: 4px;
}

.position-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #dee2e6;
    transition: background 0.2s ease;
}

.position-dot.active {
    background: #2563eb;
}

.help-section {
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e9ecef;
}

.help-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.help-section h6 {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.shortcut-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.shortcut-list li {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
    font-size: 0.9rem;
}

.shortcut-list kbd {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 0.8rem;
    font-weight: 500;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive Design */
@media (max-width: 992px) {
    .nav-main-bar {
        flex-direction: column;
        gap: 12px;
        align-items: stretch;
    }
    
    .nav-breadcrumb {
        order: 2;
    }
    
    .nav-quick-actions {
        order: 1;
        text-align: center;
    }
    
    .switcher-projects-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    }
    
    .project-nav-arrows {
        flex-direction: column;
        gap: 12px;
    }
    
    .nav-arrow {
        max-width: none;
        text-align: center;
    }
    
    .nav-position-indicator {
        order: -1;
    }
}

@media (max-width: 768px) {
    .enhanced-project-nav {
        margin: 0 -15px;
        border-radius: 0;
    }
    
    .nav-main-bar,
    .switcher-content {
        padding: 16px;
    }
    
    .switcher-projects-grid {
        grid-template-columns: 1fr;
    }
    
    .nav-link-enhanced {
        padding: 6px 8px;
    }
    
    .quick-nav-buttons {
        justify-content: center;
    }
}

@media (max-width: 576px) {
    .nav-main-bar,
    .switcher-content {
        padding: 12px;
    }
    
    .switcher-project-card {
        padding: 12px;
    }
    
    .nav-arrow-link {
        padding: 6px 8px;
        font-size: 0.8rem;
    }
    
    .arrow-text strong {
        font-size: 0.75rem;
    }
}
</style>

<script>
// Project switcher functionality
function showProjectSwitcher() {
    const panel = document.getElementById('projectSwitcherPanel');
    panel.style.display = 'block';
    
    // Focus on search input
    const searchInput = document.getElementById('projectSearchInput');
    if (searchInput) {
        setTimeout(() => searchInput.focus(), 100);
    }
}

function hideProjectSwitcher() {
    const panel = document.getElementById('projectSwitcherPanel');
    panel.style.display = 'none';
}

function filterProjects() {
    const searchTerm = document.getElementById('projectSearchInput').value.toLowerCase();
    const projectCards = document.querySelectorAll('.switcher-project-card');
    
    projectCards.forEach(card => {
        const projectName = card.getAttribute('data-project-name');
        const projectStatus = card.getAttribute('data-project-status');
        
        if (projectName.includes(searchTerm) || projectStatus.includes(searchTerm)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function showRandomProject() {
    const projectCards = document.querySelectorAll('.switcher-project-card:not(.current-project)');
    if (projectCards.length > 0) {
        const randomIndex = Math.floor(Math.random() * projectCards.length);
        const randomCard = projectCards[randomIndex];
        const link = randomCard.querySelector('.project-switch-link');
        if (link) {
            window.location.href = link.href;
        }
    }
}

function showNavigationHelp() {
    const modal = new bootstrap.Modal(document.getElementById('navigationHelpModal'));
    modal.show();
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Don't trigger shortcuts if user is typing in an input
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
        return;
    }
    
    switch(e.key) {
        case 'ArrowLeft':
            e.preventDefault();
            const prevLink = document.querySelector('.prev-arrow .nav-arrow-link:not(.disabled)');
            if (prevLink) {
                window.location.href = prevLink.href;
            }
            break;
            
        case 'ArrowRight':
            e.preventDefault();
            const nextLink = document.querySelector('.next-arrow .nav-arrow-link:not(.disabled)');
            if (nextLink) {
                window.location.href = nextLink.href;
            }
            break;
            
        case 'd':
        case 'D':
            e.preventDefault();
            window.location.href = '<?php echo $baseDir; ?>/modules/client/dashboard.php';
            break;
            
        case 'p':
        case 'P':
            e.preventDefault();
            showProjectSwitcher();
            break;
            
        case 'Escape':
            hideProjectSwitcher();
            break;
    }
});

// Close project switcher when clicking outside
document.addEventListener('click', function(e) {
    const panel = document.getElementById('projectSwitcherPanel');
    const switcherButton = document.querySelector('[onclick="showProjectSwitcher()"]');
    
    if (panel.style.display === 'block' && 
        !panel.contains(e.target) && 
        !switcherButton.contains(e.target)) {
        hideProjectSwitcher();
    }
});

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>