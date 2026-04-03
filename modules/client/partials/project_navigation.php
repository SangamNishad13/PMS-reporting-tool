<?php
/**
 * Project Navigation Partial
 * 
 * Navigation breadcrumbs and project switcher
 */
?>

<div class="row mb-3">
    <div class="col-12">
        <nav aria-label="Project navigation" class="project-nav">
            <!-- Breadcrumb Navigation -->
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="<?php echo $baseDir; ?>/client/dashboard">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="breadcrumb-item">
                    <a href="<?php echo $baseDir; ?>/modules/client/projects.php">
                        <i class="fas fa-folder-open"></i> Projects
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">
                    <i class="fas fa-chart-line"></i> <?php echo htmlspecialchars($project['title']); ?>
                </li>
            </ol>
            
            <!-- Project Switcher -->
            <div class="project-switcher">
                <label for="projectNavSelect" class="form-label small text-muted mb-1">Switch Project</label>
                <select id="projectNavSelect" class="form-select form-select-sm">
                    <option value="">Select a project...</option>
                    <?php foreach ($assignedProjects as $proj): ?>
                        <option value="<?php echo $proj['id']; ?>" 
                                <?php echo ($proj['id'] == $projectId) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($proj['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
<script>
document.getElementById('projectNavSelect').addEventListener('change', function() {
    var id = this.value;
    if (id) {
        var base = '<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>';
        window.location.href = base + '/client/project/' + id;
    }
});
</script>
            </div>
        </nav>
    </div>
</div>

<style>
.project-nav {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 16px 20px;
    border: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.breadcrumb {
    margin: 0;
    background: none;
    padding: 0;
    font-size: 0.9rem;
}

.breadcrumb-item a {
    color: #2563eb;
    text-decoration: none;
    transition: color 0.2s ease;
}

.breadcrumb-item a:hover {
    color: #1d4ed8;
    text-decoration: underline;
}

.breadcrumb-item.active {
    color: #6c757d;
    font-weight: 500;
}

.breadcrumb-item i {
    margin-right: 4px;
    font-size: 0.85rem;
    opacity: 0.8;
}

.project-switcher {
    min-width: 200px;
}

.project-switcher .form-label {
    font-weight: 600;
    margin-bottom: 4px;
}

.project-switcher .form-select {
    border-radius: 6px;
    border: 1px solid #ced4da;
    font-size: 0.875rem;
}

@media (max-width: 768px) {
    .project-nav {
        flex-direction: column;
        align-items: stretch;
        padding: 12px 16px;
    }
    
    .breadcrumb {
        font-size: 0.8rem;
    }
    
    .project-switcher {
        min-width: auto;
        width: 100%;
    }
}
</style>