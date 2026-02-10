        <!-- Pages Tab -->
        <div class="tab-pane fade" id="pages" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Project Pages</h5>
                <?php if (in_array($userRole, ['admin', 'super_admin', 'project_lead', 'qa'])): ?>
                <a href="<?php echo $baseDir; ?>/modules/projects/manage_assignments.php?project_id=<?php echo $projectId; ?>&tab=pages" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-edit"></i> Manage Assignments
                </a>
                <?php endif; ?>
            </div>

            <!-- Pages sub-tabs: Unique Pages, All URLs -->
            <ul class="nav nav-tabs mb-3" id="pagesSubTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="unique-sub-tab" data-bs-toggle="tab" data-bs-target="#unique_pages_sub" type="button">Unique Pages</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="allurls-sub-tab" data-bs-toggle="tab" data-bs-target="#all_urls_sub" type="button">All URLs</button>
                </li>
            </ul>

            <div class="tab-content">
            <div class="tab-pane fade" id="pages_main" role="tabpanel" style="display:none;">

            <?php 
            // Get project pages with environment details (show all pages here; dashboards control visibility)
            $pages = $db->prepare("SELECT pp.* FROM project_pages pp WHERE pp.project_id = ? ORDER BY pp.page_number, pp.page_name");
            $pages->execute([$projectId]);
            
            if ($pages->rowCount() > 0):
                while ($page = $pages->fetch()): 
                    // Get environments for this page
                    $environments = $db->prepare("
                        SELECT pe.*, te.name as env_name, te.type as env_type, te.browser, te.assistive_tech,
                               at_user.full_name as at_tester_name,
                               ft_user.full_name as ft_tester_name,
                               qa_user.full_name as qa_name
                        FROM page_environments pe
                        JOIN testing_environments te ON pe.environment_id = te.id
                        LEFT JOIN users at_user ON pe.at_tester_id = at_user.id
                        LEFT JOIN users ft_user ON pe.ft_tester_id = ft_user.id
                        LEFT JOIN users qa_user ON pe.qa_id = qa_user.id
                        WHERE pe.page_id = ?
                        ORDER BY te.name
                    ");
                    $environments->execute([$page['id']]);
            ?>
            <div class="card mb-3">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center">
                                <button class="btn btn-sm btn-outline-secondary me-2 page-toggle-btn" 
                                        type="button" 
                                        data-bs-toggle="collapse" 
                                        data-bs-target="#page-details-<?php echo $page['id']; ?>" 
                                        aria-expanded="false" 
                                        aria-controls="page-details-<?php echo $page['id']; ?>"
                                        title="Expand/Collapse Details">
                                    <i class="fas fa-chevron-down toggle-icon"></i>
                                </button>
                                <div>
                                    <h6 class="mb-0">
                                        <strong><?php echo htmlspecialchars($page['page_name']); ?></strong>
                                        <?php if ($page['url'] || $page['screen_name'] || $page['page_number']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($page['url'] ?: $page['screen_name'] ?: $page['page_number']); ?></small>
                                        <?php endif; ?>
                                    </h6>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="d-flex gap-1 justify-content-end align-items-center">
                                <!-- Page Status Dropdown -->
                                <div class="me-2">
                                    <?php echo renderPageStatusDropdown($page['id'], $page['status']); ?>
                                </div>
                                
                                <!-- Summary badges when collapsed -->
                                <div class="page-summary me-2">
                                    <?php 
                                    // Get environment summary
                                    $envSummary = $db->prepare("
                                        SELECT 
                                            COUNT(*) as total_envs,
                                            SUM(CASE WHEN pe.status = 'completed' OR pe.status = 'pass' THEN 1 ELSE 0 END) as completed_envs,
                                            SUM(CASE WHEN pe.qa_status = 'completed' OR pe.qa_status = 'pass' THEN 1 ELSE 0 END) as qa_completed_envs
                                        FROM page_environments pe
                                        WHERE pe.page_id = ?
                                    ");
                                    $envSummary->execute([$page['id']]);
                                    $summary = $envSummary->fetch();
                                    
                                    $totalEnvs = $summary['total_envs'] ?: 0;
                                    $completedEnvs = $summary['completed_envs'] ?: 0;
                                    $qaCompletedEnvs = $summary['qa_completed_envs'] ?: 0;
                                    ?>
                                    <small class="text-muted">
                                        <span class="badge bg-secondary"><?php echo $totalEnvs; ?> Envs</span>
                                        <?php if ($totalEnvs > 0): ?>
                                        <span class="badge bg-<?php echo $completedEnvs == $totalEnvs ? 'success' : ($completedEnvs > 0 ? 'warning' : 'secondary'); ?>">
                                            <?php echo $completedEnvs; ?>/<?php echo $totalEnvs; ?> Testing
                                        </span>
                                        <span class="badge bg-<?php echo $qaCompletedEnvs == $totalEnvs ? 'success' : ($qaCompletedEnvs > 0 ? 'info' : 'secondary'); ?>">
                                            <?php echo $qaCompletedEnvs; ?>/<?php echo $totalEnvs; ?> QA
                                        </span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <a href="<?php echo $baseDir; ?>/modules/chat/project_chat.php?page_id=<?php echo $page['id']; ?>" 
                                   class="btn btn-sm btn-outline-primary" title="Page Chat">
                                    <i class="fas fa-comments"></i>
                                </a>
                                <?php if (!in_array($userRole, ['at_tester', 'ft_tester'])): ?>
                                <a href="<?php echo $baseDir; ?>/modules/projects/manage_assignments.php?project_id=<?php echo $projectId; ?>&tab=pages&open_page_id=<?php echo $page['id']; ?>&return_to=<?php echo urlencode($baseDir . '/modules/projects/view.php?id=' . $projectId); ?>" 
                                   class="btn btn-sm btn-outline-secondary" title="Manage Assignments" data-page-edit-id="<?php echo $page['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="collapse" id="page-details-<?php echo $page['id']; ?>">
                    <div class="card-body p-0">
                        <?php if ($environments->rowCount() > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;">Environment</th>
                                        <th style="width: 20%;">AT Tester</th>
                                        <th style="width: 15%;">AT Status</th>
                                        <th style="width: 20%;">FT Tester</th>
                                        <th style="width: 15%;">FT Status</th>
                                        <th style="width: 15%;">QA</th>
                                        <th style="width: 15%;">QA Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($env = $environments->fetch()): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <strong class="small"><?php echo htmlspecialchars($env['env_name']); ?></strong>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($env['browser']); ?>
                                                <?php if ($env['assistive_tech']): ?>
                                                / <?php echo htmlspecialchars($env['assistive_tech']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($env['at_tester_name']): ?>
                                        <span class="badge bg-primary small">
                                            <i class="fas fa-user-check"></i> <?php echo htmlspecialchars(explode(' ', $env['at_tester_name'])[0]); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary small">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (in_array($userRole, ['admin', 'super_admin', 'project_lead', 'qa']) || 
                                                  $env['at_tester_id'] == $userId): ?>
                                        <select class="form-select form-select-sm env-status-update" 
                                                data-page-id="<?php echo $page['id']; ?>" 
                                                data-env-id="<?php echo $env['environment_id']; ?>"
                                                data-status-type="testing"
                                                style="font-size: 0.75rem;">
                                            <option value="not_started" <?php echo $env['status'] === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                            <option value="in_progress" <?php echo $env['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="pass" <?php echo $env['status'] === 'pass' ? 'selected' : ''; ?>>Pass</option>
                                            <option value="fail" <?php echo $env['status'] === 'fail' ? 'selected' : ''; ?>>Fail</option>
                                            <option value="on_hold" <?php echo $env['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                            <option value="needs_review" <?php echo $env['status'] === 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="badge bg-<?php 
                                            echo $env['status'] === 'pass' ? 'success' : 
                                                 ($env['status'] === 'fail' ? 'danger' : 
                                                  ($env['status'] === 'in_progress' ? 'primary' : 
                                                   ($env['status'] === 'on_hold' ? 'warning' : 
                                                    ($env['status'] === 'needs_review' ? 'info' : 'secondary'))));
                                        ?> small">
                                            <?php echo ucfirst(str_replace('_', ' ', $env['status'] ?: 'not_started')); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($env['ft_tester_name']): ?>
                                        <span class="badge bg-success small">
                                            <i class="fas fa-user-cog"></i> <?php echo htmlspecialchars(explode(' ', $env['ft_tester_name'])[0]); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary small">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (in_array($userRole, ['admin', 'super_admin', 'project_lead', 'qa']) || 
                                                  $env['ft_tester_id'] == $userId): ?>
                                        <select class="form-select form-select-sm env-status-update" 
                                                data-page-id="<?php echo $page['id']; ?>" 
                                                data-env-id="<?php echo $env['environment_id']; ?>"
                                                data-status-type="testing"
                                                style="font-size: 0.75rem;">
                                            <option value="not_started" <?php echo $env['status'] === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                            <option value="in_progress" <?php echo $env['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="pass" <?php echo $env['status'] === 'pass' ? 'selected' : ''; ?>>Pass</option>
                                            <option value="fail" <?php echo $env['status'] === 'fail' ? 'selected' : ''; ?>>Fail</option>
                                            <option value="on_hold" <?php echo $env['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                            <option value="needs_review" <?php echo $env['status'] === 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="badge bg-<?php 
                                            echo $env['status'] === 'pass' ? 'success' : 
                                                 ($env['status'] === 'fail' ? 'danger' : 
                                                  ($env['status'] === 'in_progress' ? 'primary' : 
                                                   ($env['status'] === 'on_hold' ? 'warning' : 
                                                    ($env['status'] === 'needs_review' ? 'info' : 'secondary'))));
                                        ?> small">
                                            <?php echo ucfirst(str_replace('_', ' ', $env['status'] ?: 'not_started')); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($env['qa_name']): ?>
                                        <span class="badge bg-info small">
                                            <i class="fas fa-user-shield"></i> <?php echo htmlspecialchars(explode(' ', $env['qa_name'])[0]); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary small">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (in_array($userRole, ['admin', 'super_admin', 'project_lead', 'qa']) || 
                                                  $env['qa_id'] == $userId): ?>
                                        <select class="form-select form-select-sm env-status-update" 
                                                data-page-id="<?php echo $page['id']; ?>" 
                                                data-env-id="<?php echo $env['environment_id']; ?>"
                                                data-status-type="qa"
                                                style="font-size: 0.75rem;">
                                            <option value="pending" <?php echo $env['qa_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="pass" <?php echo $env['qa_status'] === 'pass' ? 'selected' : ''; ?>>Pass</option>
                                            <option value="fail" <?php echo $env['qa_status'] === 'fail' ? 'selected' : ''; ?>>Fail</option>
                                            <option value="na" <?php echo $env['qa_status'] === 'na' ? 'selected' : ''; ?>>N/A</option>
                                            <option value="completed" <?php echo $env['qa_status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="badge bg-<?php 
                                            echo $env['qa_status'] === 'pass' ? 'success' : 
                                                 ($env['qa_status'] === 'fail' ? 'danger' : 
                                                  ($env['qa_status'] === 'pending' ? 'primary' : 
                                                   ($env['qa_status'] === 'completed' ? 'success' : 'secondary')));
                                        ?> small">
                                            <?php echo ucfirst(str_replace('_', ' ', $env['qa_status'] ?: 'pending')); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-3 text-center text-muted">
                        <i class="fas fa-info-circle"></i> No environments assigned to this page.
                        <br><small>Use "Manage Assignments" to assign environments and testers.</small>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php 
                endwhile;
            else:
            ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No pages found for this project.
            </div>
            <?php endif; ?>
            </div> <!-- end #pages_main -->

            <!-- Unique Pages sub-pane -->
            <div class="tab-pane fade show active" id="unique_pages_sub" role="tabpanel" aria-labelledby="unique-sub-tab">
                <!-- Header with title and action buttons -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Unique Pages (URLs)</h5>
                    <div class="btn-group" role="group">
                        <button class="btn btn-sm btn-danger" id="deleteSelectedUnique">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                        <a href="#" class="btn btn-sm btn-primary" id="importUrlsBtn">
                            <i class="fas fa-upload"></i> Import CSV
                        </a>
                        <button class="btn btn-sm btn-outline-primary" id="addUniqueBtn">
                            <i class="fas fa-plus"></i> Add Unique
                        </button>
                    </div>
                </div>
                
                <!-- Filters row -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Search</label>
                        <input id="uniqueFilter" class="form-control form-control-sm" placeholder="Search name or URL..." />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">User Filter</label>
                        <select id="uniqueFilterUser" class="form-select form-select-sm">
                            <option value="">All Users</option>
                            <?php foreach ($projectUsers as $pu): ?>
                                <option value="<?php echo htmlspecialchars($pu['full_name']); ?>"><?php echo htmlspecialchars($pu['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Environment</label>
                        <select id="uniqueFilterEnv" class="form-select form-select-sm">
                            <option value="">All Environments</option>
                            <?php
                                $envListStmt = $db->prepare('SELECT id, name FROM testing_environments ORDER BY name');
                                $envListStmt->execute();
                                $envList = $envListStmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($envList as $env) {
                                    echo '<option value="' . htmlspecialchars($env['name']) . '">' . htmlspecialchars($env['name']) . '</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">QA Filter</label>
                        <select id="uniqueFilterQa" class="form-select form-select-sm">
                            <option value="">All QA</option>
                            <?php foreach ($projectUsers as $pu): ?>
                                <option value="<?php echo htmlspecialchars($pu['full_name']); ?>"><?php echo htmlspecialchars($pu['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php
                // prepare statements for mapped page and environment aggregation
                // Try to find a mapped project page. Prefer grouped_urls linkage, but also
                // accept project_pages that were created from a Unique (page_number = unique.name)
                // or whose url equals unique.canonical_url. This ensures newly-created project_pages
                // are discovered even if grouped_urls linkage wasn't present.
                $mpStmt = $db->prepare(
                    'SELECT pp.id, pp.page_number, pp.page_name, pp.status, pp.notes
                     FROM project_pages pp
                     WHERE pp.project_id = ? AND (
                         pp.id IN (SELECT DISTINCT pp2.id FROM project_pages pp2 
                                   LEFT JOIN grouped_urls gu ON pp2.project_id = gu.project_id AND (pp2.url = gu.url OR pp2.url = gu.normalized_url)
                                   WHERE pp2.project_id = ? AND gu.unique_page_id = ?)
                         OR pp.page_number = (SELECT name FROM unique_pages WHERE id = ?)
                         OR pp.url = (SELECT canonical_url FROM unique_pages WHERE id = ?)
                     )
                     LIMIT 1'
                );
                $envStmt = $db->prepare('SELECT GROUP_CONCAT(DISTINCT at_u.full_name SEPARATOR ", ") as at_testers, GROUP_CONCAT(DISTINCT ft_u.full_name SEPARATOR ", ") as ft_testers, GROUP_CONCAT(DISTINCT qa_u.full_name SEPARATOR ", ") as qa_names, GROUP_CONCAT(DISTINCT te.name SEPARATOR ", ") as env_names FROM page_environments pe JOIN testing_environments te ON pe.environment_id = te.id LEFT JOIN users at_u ON pe.at_tester_id = at_u.id LEFT JOIN users ft_u ON pe.ft_tester_id = ft_u.id LEFT JOIN users qa_u ON pe.qa_id = qa_u.id WHERE pe.page_id = ?');
                // QA summary per page (used in Unique list)
                $qaSummaryStmt = $db->prepare('SELECT COUNT(*) AS total_envs, SUM(CASE WHEN pe.qa_status IN ("pass","completed") THEN 1 ELSE 0 END) AS qa_passed FROM page_environments pe WHERE pe.page_id = ?');
                // list detailed env assignments per page
                $envListStmt = $db->prepare('SELECT pe.environment_id, te.name as env_name, pe.status AS env_status, pe.qa_status AS env_qa_status, pe.at_tester_id, at_u.full_name AS at_name, pe.ft_tester_id, ft_u.full_name AS ft_name, pe.qa_id, qa_u.full_name AS qa_name FROM page_environments pe JOIN testing_environments te ON pe.environment_id = te.id LEFT JOIN users at_u ON pe.at_tester_id = at_u.id LEFT JOIN users ft_u ON pe.ft_tester_id = ft_u.id LEFT JOIN users qa_u ON pe.qa_id = qa_u.id WHERE pe.page_id = ? ORDER BY te.name');
                $envStmt = $db->prepare('SELECT GROUP_CONCAT(DISTINCT at_u.full_name SEPARATOR ", ") as at_testers, GROUP_CONCAT(DISTINCT ft_u.full_name SEPARATOR ", ") as ft_testers, GROUP_CONCAT(DISTINCT qa_u.full_name SEPARATOR ", ") as qa_names, GROUP_CONCAT(DISTINCT te.name SEPARATOR ", ") as env_names FROM page_environments pe JOIN testing_environments te ON pe.environment_id = te.id LEFT JOIN users at_u ON pe.at_tester_id = at_u.id LEFT JOIN users ft_u ON pe.ft_tester_id = ft_u.id LEFT JOIN users qa_u ON pe.qa_id = qa_u.id WHERE pe.page_id = ?');
                // grouped URLs per unique
                $groupForUnique = $db->prepare('SELECT url FROM grouped_urls WHERE project_id = ? AND unique_page_id = ? ORDER BY url');
                ?>
                <div class="table-responsive">
                    <table class="table table-striped table-sm resizable-table" id="uniquePagesTable">
                        <thead>
                            <tr>
                                <th style="width:40px; position: relative;">
                                    <input type="checkbox" id="selectAllUnique">
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:100px; position: relative;">
                                    Page No.
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:200px; position: relative;">
                                    Page Name
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    Unique URLs
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:120px; position: relative;">
                                    Grouped URLs
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    FT (Env · Status)
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    AT (Env · Status)
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    QA (Env · Status)
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:200px; position: relative;">
                                    Notes
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    Actions
                                    <div class="col-resizer"></div>
                                </th>
                                </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($uniquePages)): foreach ($uniquePages as $u): 
                                $mapped = null; $envs = null; $pageIdForEnv = null;
                                $mpStmt->execute([$projectId, $projectId, $u['id'], $u['id'], $u['id']]);
                                $mapped = $mpStmt->fetch(PDO::FETCH_ASSOC);
                                if ($mapped) $pageIdForEnv = $mapped['id'];
                                if ($pageIdForEnv) { $envStmt->execute([$pageIdForEnv]); $envs = $envStmt->fetch(PDO::FETCH_ASSOC); }
                        ?>
                            <tr id="unique-row-<?php echo (int)$u['id']; ?>">
                                <td><input type="checkbox" class="unique-check" value="<?php echo (int)$u['id']; ?>"></td>
                                <?php
                                    // Determine display for Page No and Page Name.
                                    $displayPageNo = '';
                                    $displayPageName = '';
                                    if (!empty($mapped) && !empty($mapped['page_number'])) {
                                        $displayPageNo = $mapped['page_number'];
                                        $displayPageName = $mapped['page_name'] ?? ($u['name'] ?? '');
                                    } else {
                                        // If no mapped project page, prefer moving generated "Page N" (from unique name) into Page No
                                        $genLike = '';
                                        if (!empty($u['name']) && preg_match('/^Page\s+\d+/i', $u['name'])) {
                                            $genLike = $u['name'];
                                        }
                                        if ($genLike) {
                                            $displayPageNo = $genLike;
                                            // keep page name blank (or show canonical_url)
                                            $displayPageName = $u['canonical_url'] ?? '';
                                        } else {
                                            // fallback: show mapped page_name if exists, otherwise unique name
                                            $displayPageNo = '';
                                            $displayPageName = $mapped['page_name'] ?? ($u['name'] ?? '');
                                        }
                                    }
                                ?>
                                <td><?php echo htmlspecialchars($displayPageNo); ?></td>
                                <td>
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="page-name-display flex-grow-1 text-truncate"><?php echo htmlspecialchars($displayPageName); ?></span>
                                        <button type="button" class="btn btn-sm btn-link flex-shrink-0 edit-page-name" data-field="page_name" data-unique-id="<?php echo (int)$u['id']; ?>" data-page-id="<?php echo (int)($mapped['id'] ?? 0); ?>" data-current-name="<?php echo htmlspecialchars($displayPageName); ?>" onclick="return window.handleEditPageName(this);">Edit</button>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                        $uniqueDisplay = $u['canonical_url'] ?? $u['name'] ?? '';
                                        echo htmlspecialchars($uniqueDisplay);
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        $groupForUnique->execute([$projectId, $u['id']]);
                                        $grows = $groupForUnique->fetchAll(PDO::FETCH_COLUMN);
                                        if (!empty($grows)) {
                                            echo '<div class="unique-grouped-list" data-unique-id="' . (int)$u['id'] . '">';
                                            foreach ($grows as $gurl) { echo '<div class="grouped-url-item">' . htmlspecialchars($gurl) . '</div>'; }
                                            echo '</div>';
                                        } else {
                                            echo '<div class="unique-grouped-list" data-unique-id="' . (int)$u['id'] . '"><span class="text-muted">No grouped URLs</span></div>';
                                        }
                                    ?>
                                </td>
                                <?php
                                    // prepare per-environment rows for merged columns
                                    $envRows = [];
                                    if ($pageIdForEnv) {
                                        try {
                                            $envListStmt->execute([$pageIdForEnv]);
                                            $envRows = $envListStmt->fetchAll(PDO::FETCH_ASSOC);
                                        } catch (Exception $e) { $envRows = []; }
                                    }
                                ?>
                                <td>
                                    <?php
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            // Only show if FT tester is assigned
                                            if ($er['ft_tester_id']) {
                                                $ft = htmlspecialchars($er['ft_name']);
                                                $envLabel = htmlspecialchars($er['env_name']);
                                                $statusHtml = renderEnvStatusDropdown($pageIdForEnv, $er['environment_id'], $er['env_status']);
                                                echo '<div class="d-flex align-items-center justify-content-between gap-2 mb-1">';
                                                echo '<div class="flex-grow-1 text-truncate"><strong>' . $ft . '</strong> <small class="text-muted">&middot; ' . $envLabel . '</small></div>';
                                                echo '<div class="flex-shrink-0">' . $statusHtml . '</div>';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                    // Show message only if no FT assignments at all
                                    $hasFT = false;
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            if ($er['ft_tester_id']) { $hasFT = true; break; }
                                        }
                                    }
                                    if (!$hasFT) {
                                        echo '<span class="text-muted">No FT assignments</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            // Only show if AT tester is assigned
                                            if ($er['at_tester_id']) {
                                                $at = htmlspecialchars($er['at_name']);
                                                $envLabel = htmlspecialchars($er['env_name']);
                                                $statusHtml = renderEnvStatusDropdown($pageIdForEnv, $er['environment_id'], $er['env_status']);
                                                echo '<div class="d-flex align-items-center justify-content-between gap-2 mb-1">';
                                                echo '<div class="flex-grow-1 text-truncate"><strong>' . $at . '</strong> <small class="text-muted">&middot; ' . $envLabel . '</small></div>';
                                                echo '<div class="flex-shrink-0">' . $statusHtml . '</div>';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                    // Show message only if no AT assignments at all
                                    $hasAT = false;
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            if ($er['at_tester_id']) { $hasAT = true; break; }
                                        }
                                    }
                                    if (!$hasAT) {
                                        echo '<span class="text-muted">No AT assignments</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            // Only show if QA is assigned
                                            if ($er['qa_id']) {
                                                $qa = htmlspecialchars($er['qa_name']);
                                                $envLabel = htmlspecialchars($er['env_name']);
                                                $qaStatus = $er['env_qa_status'] ?? 'pending';
                                                $statusHtml = renderQAEnvStatusDropdown($pageIdForEnv, $er['environment_id'], $qaStatus);
                                                echo '<div class="d-flex align-items-center justify-content-between gap-2 mb-1">';
                                                echo '<div class="flex-grow-1 text-truncate"><strong>' . $qa . '</strong> <small class="text-muted">&middot; ' . $envLabel . '</small></div>';
                                                echo '<div class="flex-shrink-0">' . $statusHtml . '</div>';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                    // Show message only if no QA assignments at all
                                    $hasQA = false;
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            if ($er['qa_id']) { $hasQA = true; break; }
                                        }
                                    }
                                    if (!$hasQA) {
                                        echo '<span class="text-muted">No QA assignments</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php $notesDisplay = (isset($mapped['notes']) && strlen(trim((string)$mapped['notes'])) > 0) ? $mapped['notes'] : ($u['notes'] ?? ''); ?>
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="notes-display flex-grow-1 text-truncate"><?php echo htmlspecialchars($notesDisplay); ?></span>
                                        <button type="button" class="btn btn-sm btn-link flex-shrink-0 edit-page-name" data-field="notes" data-unique-id="<?php echo (int)$u['id']; ?>" data-page-id="<?php echo (int)($mapped['id'] ?? 0); ?>" data-current-name="<?php echo htmlspecialchars($notesDisplay); ?>" onclick="return window.handleEditPageName(this);">Edit</button>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($pageIdForEnv): ?>
                                        <button class="btn btn-sm btn-outline-primary assign-page-btn me-1" data-bs-toggle="modal" data-bs-target="#assignPageModal-<?php echo $pageIdForEnv; ?>">Assign</button>
                                    <?php else: ?>
                                        <span class="text-muted small">No mapped page</span>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-danger delete-unique" data-id="<?php echo (int)$u['id']; ?>">Delete</button>
                                </td>
                            </tr>
                            <?php if ($pageIdForEnv): 
                                // Build env assignment map for modal defaults
                                $envListStmt->execute([$pageIdForEnv]);
                                $envRowsModal = $envListStmt->fetchAll(PDO::FETCH_ASSOC);
                                $envMap = [];
                                foreach ($envRowsModal as $erow) {
                                    $envMap[(int)$erow['environment_id']] = $erow;
                                }
                                $pageInfoStmt = $db->prepare('SELECT at_tester_id, ft_tester_id, qa_id FROM project_pages WHERE id = ?');
                                $pageInfoStmt->execute([$pageIdForEnv]);
                                $pageInfo = $pageInfoStmt->fetch(PDO::FETCH_ASSOC) ?: ['at_tester_id'=>null,'ft_tester_id'=>null,'qa_id'=>null];
                            ?>
                            <div class="modal fade" id="assignPageModal-<?php echo $pageIdForEnv; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/manage_assignments.php?project_id=<?php echo $projectId; ?>&tab=pages">
                                            <input type="hidden" name="assign_page" value="1">
                                            <input type="hidden" name="page_id" value="<?php echo $pageIdForEnv; ?>">
                                            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($baseDir . '/modules/projects/view.php?id=' . $projectId . '#unique_pages_sub'); ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Assign testers & environments</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row g-2">
                                                    <div class="col-md-4">
                                                        <label class="form-label">AT Tester</label>
                                                        <select name="at_tester_id" class="form-select form-select-sm">
                                                            <option value="">-- None --</option>
                                                            <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'at_tester'): ?>
                                                                <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$pageInfo['at_tester_id'] === (int)$tm['id']) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($tm['full_name']); ?>
                                                                </option>
                                                            <?php endif; endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">FT Tester</label>
                                                        <select name="ft_tester_id" class="form-select form-select-sm">
                                                            <option value="">-- None --</option>
                                                            <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'ft_tester'): ?>
                                                                <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$pageInfo['ft_tester_id'] === (int)$tm['id']) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($tm['full_name']); ?>
                                                                </option>
                                                            <?php endif; endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">QA</label>
                                                        <select name="qa_id" class="form-select form-select-sm">
                                                            <option value="">-- None --</option>
                                                            <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'qa'): ?>
                                                                <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$pageInfo['qa_id'] === (int)$tm['id']) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($tm['full_name']); ?>
                                                                </option>
                                                            <?php endif; endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <hr class="my-3">
                                                <div class="mb-2 d-flex justify-content-between align-items-center">
                                                    <strong class="mb-0">Environments</strong>
                                                    <small class="text-muted">Check envs and set per-env testers (optional)</small>
                                                </div>
                                                <div class="row">
                                                    <?php foreach ($allEnvironments as $env): 
                                                        $envId = (int)$env['id'];
                                                        $linked = isset($envMap[$envId]);
                                                        $atSel = $linked ? ($envMap[$envId]['at_tester_id'] ?? '') : '';
                                                        $ftSel = $linked ? ($envMap[$envId]['ft_tester_id'] ?? '') : '';
                                                        $qaSel = $linked ? ($envMap[$envId]['qa_id'] ?? '') : '';
                                                    ?>
                                                    <div class="col-md-6 mb-3">
                                                        <div class="border rounded p-2">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="envs[]" value="<?php echo $envId; ?>" id="env_chk_<?php echo $pageIdForEnv . '_' . $envId; ?>" <?php echo $linked ? 'checked' : ''; ?>>
                                                                <label class="form-check-label fw-bold" for="env_chk_<?php echo $pageIdForEnv . '_' . $envId; ?>"><?php echo htmlspecialchars($env['name']); ?></label>
                                                            </div>
                                                            <div class="row g-2 mt-2">
                                                                <div class="col-4">
                                                                    <select name="at_tester_env_<?php echo $envId; ?>" class="form-select form-select-sm">
                                                                        <option value="">AT</option>
                                                                        <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'at_tester'): ?>
                                                                            <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$atSel === (int)$tm['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                                        <?php endif; endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-4">
                                                                    <select name="ft_tester_env_<?php echo $envId; ?>" class="form-select form-select-sm">
                                                                        <option value="">FT</option>
                                                                        <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'ft_tester'): ?>
                                                                            <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$ftSel === (int)$tm['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                                        <?php endif; endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-4">
                                                                    <select name="qa_env_<?php echo $envId; ?>" class="form-select form-select-sm">
                                                                        <option value="">QA</option>
                                                                        <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'qa'): ?>
                                                                            <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$qaSel === (int)$tm['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                                        <?php endif; endforeach; ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($allEnvironments)): ?>
                                                        <div class="col-12 text-muted">No environments configured.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save assignments</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; else: ?>
                            <tr><td colspan="10" class="text-muted">No unique pages defined for this project.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div> <!-- end #unique_pages_sub -->

            <!-- All URLs sub-pane -->
            <div class="tab-pane fade" id="all_urls_sub" role="tabpanel" aria-labelledby="allurls-sub-tab">
                <!-- Header with title and action buttons -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">All URLs</h5>
                    <div class="btn-group" role="group">
                        <button class="btn btn-sm btn-danger" id="deleteSelectedGrouped">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                        <a href="#" class="btn btn-sm btn-outline-primary" id="importAllUrlsBtn">
                            <i class="fas fa-upload"></i> Import All URLs CSV
                        </a>
                        <?php if (in_array($userRole, ['admin', 'super_admin', 'project_lead', 'qa'])): ?>
                            <button class="btn btn-sm btn-primary" id="openAddGroupedUrlModal">
                                <i class="fas fa-plus"></i> Add Page
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Filters row -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Search URL</label>
                        <input id="allUrlsFilter" class="form-control form-control-sm" placeholder="Search URL..." />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Unique Page Filter</label>
                        <select id="allUrlsUniqueFilter" class="form-select form-select-sm">
                            <option value="">All Unique Pages</option>
                            <?php foreach ($uniquePages as $up): ?>
                                <option value="<?php echo htmlspecialchars($up['name'] ?? $up['canonical_url'] ?? ''); ?>"><?php echo htmlspecialchars($up['name'] ?? $up['canonical_url'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Mapping Status</label>
                        <select id="allUrlsMappingFilter" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="mapped">Mapped</option>
                            <option value="unassigned">Unassigned</option>
                        </select>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-striped table-sm resizable-table" id="allUrlsTable">
                        <thead>
                            <tr>
                                <th style="width:40px; position: relative;">
                                    <input type="checkbox" id="selectAllGrouped">
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:300px; position: relative;">
                                    URL
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:180px; position: relative;">
                                    Unique Page
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:200px; position: relative;">
                                    Mapped
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    Actions
                                    <div class="col-resizer"></div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($groupedUrls)): foreach ($groupedUrls as $g): ?>
                            <tr id="grouped-row-<?php echo (int)$g['grouped_id']; ?>">
                                <td><input type="checkbox" class="grouped-check" value="<?php echo (int)$g['grouped_id']; ?>"></td>
                                <td><?php echo htmlspecialchars($g['url']); ?></td>
                                <td class="dropdown-cell">
                                    <select class="form-select form-select-sm grouped-unique-select" data-grouped-id="<?php echo (int)$g['grouped_id']; ?>" style="min-width:160px;">
                                        <option value="">(Unassigned)</option>
                                        <?php foreach ($uniquePages as $uopt): ?>
                                            <?php $optLabel = htmlspecialchars($uopt['name'] ?? $uopt['canonical_url'] ?? ''); $optCanonical = htmlspecialchars($uopt['canonical_url'] ?? ''); ?>
                                            <option value="<?php echo (int)$uopt['id']; ?>" data-canonical="<?php echo $optCanonical; ?>" <?php echo ((int)($g['unique_page_id'] ?? 0) === (int)$uopt['id']) ? 'selected' : ''; ?>><?php echo $optLabel; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="mapped-col">
                                    <?php if (!empty($g['unique_id']) || !empty($g['mapped_page_name'])): ?>
                                        <?php if (!empty($g['unique_id'])): ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($g['unique_name'] ?? $g['mapped_page_name'] ?? ''); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($g['canonical_url'] ?? $g['url'] ?? ''); ?></small>
                                            </div>
                                        <?php else: ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($g['mapped_page_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($g['url'] ?? ''); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div><span class="text-muted">(Unassigned)</span></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger delete-grouped" data-id="<?php echo (int)$g['grouped_id']; ?>">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="4" class="text-muted">No URLs uploaded for this project.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Grouped URL Modal (only for All URLs tab) -->
            <div class="modal fade" id="addGroupedUrlModal" tabindex="-1" aria-labelledby="addGroupedUrlModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addGroupedUrlModalLabel">Add URL to All URLs</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="addGroupedUrlForm">
                                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                                <div class="mb-3">
                                    <label class="form-label">URL *</label>
                                    <input type="text" name="url" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Map to Unique (optional)</label>
                                    <select name="unique_page_id" class="form-select">
                                        <option value="">(Unassigned)</option>
                                        <?php foreach ($uniquePages as $uopt): ?>
                                            <?php $optCanon = htmlspecialchars($uopt['canonical_url'] ?? ''); ?>
                                            <option value="<?php echo (int)$uopt['id']; ?>" data-canonical="<?php echo $optCanon; ?>"><?php echo htmlspecialchars($uopt['name'] ?? $uopt['canonical_url'] ?? ''); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="text-end">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Add URL</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            </div> <!-- end .tab-content for pages sub-tabs -->
        </div>
