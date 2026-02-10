        <!-- Activity Log Tab -->
        <div class="tab-pane fade" id="activity" role="tabpanel">
            <div class="mb-3">
                <h5>Recent Activity</h5>
                <p class="text-muted">Track recent changes and updates to this project.</p>
            </div>
            
            <div class="timeline">
                <?php 
                // Get recent project activity from activity_log table
                $activity = $db->prepare("
                    SELECT 
                        al.action as type,
                        CASE 
                            WHEN al.action = 'update_page_status' THEN 'Page status updated'
                            WHEN al.action = 'update_env_status' THEN 'Environment status updated'
                            WHEN al.action = 'update_phase' THEN 'Phase status updated'
                            WHEN al.action = 'created_project' THEN 'Project created'
                            WHEN al.action = 'updated_project' THEN 'Project updated'
                            WHEN al.action = 'added_page' THEN 'Page added'
                            WHEN al.action = 'assign_team' THEN 'Team member assigned'
                            WHEN al.action = 'remove_team' THEN 'Team member removed'
                            WHEN al.action = 'assign_page' THEN 'Page assignment updated'
                            WHEN al.action = 'submit_feedback' THEN 'Feedback submitted'
                            WHEN al.action = 'add_phase' THEN 'Phase added'
                            WHEN al.action = 'remove_phase' THEN 'Phase removed'
                            WHEN al.action = 'duplicated_project' THEN 'Project duplicated'
                            WHEN al.action = 'archived_project' THEN 'Project archived'
                            ELSE CONCAT(UPPER(SUBSTRING(REPLACE(al.action, '_', ' '), 1, 1)), SUBSTRING(REPLACE(al.action, '_', ' '), 2))
                        END as action,
                        CASE 
                            WHEN al.action = 'update_page_status' THEN 
                                CONCAT('Page \"', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.page_name')), '\" status changed to ', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.status')))
                            WHEN al.action = 'update_env_status' THEN 
                                CONCAT('Environment status updated for page ID ', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.page_id')), ' (', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.tester_type')), ' tester: ', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.status')), ')')
                            WHEN al.action = 'update_phase' THEN 
                                CONCAT('Phase status updated to ', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.status')))
                            WHEN al.action = 'added_page' THEN 
                                CONCAT('New page \"', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.page_name')), '\" added to project')
                            WHEN al.action = 'assign_team' THEN 
                                CONCAT('Team member assigned with role: ', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.role')))
                            WHEN al.action = 'remove_team' THEN 
                                'Team member removed from project'
                            WHEN al.action = 'assign_page' THEN 
                                'Page assignments updated'
                            WHEN al.action = 'submit_feedback' THEN 
                                'New feedback submitted'
                            WHEN al.action = 'add_phase' THEN 
                                CONCAT('Phase \"', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.phase')), '\" added')
                            WHEN al.action = 'remove_phase' THEN 
                                'Phase removed from project'
                            WHEN al.action = 'created_project' THEN 
                                CONCAT('Project \"', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.title')), '\" created')
                            WHEN al.action = 'updated_project' THEN 
                                'Project details updated'
                            WHEN al.action = 'duplicated_project' THEN 
                                CONCAT('Project duplicated from \"', JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.original_title')), '\"')
                            WHEN al.action = 'archived_project' THEN 
                                'Project archived'
                            ELSE CONCAT('Action: ', al.action)
                        END as description,
                        al.created_at as activity_date,
                        u.full_name as user_name
                    FROM activity_log al
                    LEFT JOIN users u ON al.user_id = u.id
                    WHERE (al.entity_type = 'project' AND al.entity_id = ?) 
                       OR (al.entity_type = 'page' AND al.entity_id IN (
                           SELECT id FROM project_pages WHERE project_id = ?
                       ))
                    ORDER BY al.created_at DESC
                    LIMIT 20
                ");
                $activity->execute([$projectId, $projectId]);
                
                if ($activity->rowCount() > 0):
                    while ($log = $activity->fetch()):
                ?>
                <div class="timeline-item mb-3">
                    <div class="d-flex">
                        <div class="timeline-marker me-3">
                            <i class="fas fa-<?php 
                                // Map activity types to icons
                                $iconMap = [
                                    'update_page_status' => 'edit',
                                    'update_env_status' => 'cogs',
                                    'update_phase' => 'tasks',
                                    'created_project' => 'plus-circle',
                                    'updated_project' => 'edit',
                                    'added_page' => 'file-alt',
                                    'assign_team' => 'user-plus',
                                    'remove_team' => 'user-minus',
                                    'assign_page' => 'user-cog',
                                    'submit_feedback' => 'comment-dots',
                                    'add_phase' => 'plus',
                                    'remove_phase' => 'minus',
                                    'duplicated_project' => 'copy',
                                    'archived_project' => 'archive'
                                ];
                                echo $iconMap[$log['type']] ?? 'info-circle';
                            ?> text-<?php 
                                // Map activity types to colors
                                $colorMap = [
                                    'update_page_status' => 'primary',
                                    'update_env_status' => 'info',
                                    'update_phase' => 'primary',
                                    'created_project' => 'success',
                                    'updated_project' => 'warning',
                                    'added_page' => 'success',
                                    'assign_team' => 'success',
                                    'remove_team' => 'danger',
                                    'assign_page' => 'info',
                                    'submit_feedback' => 'warning',
                                    'add_phase' => 'success',
                                    'remove_phase' => 'danger',
                                    'duplicated_project' => 'info',
                                    'archived_project' => 'secondary'
                                ];
                                echo $colorMap[$log['type']] ?? 'secondary';
                            ?>"></i>
                        </div>
                        <div class="timeline-content flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($log['action']); ?></h6>
                                    <p class="mb-1 text-muted"><?php echo htmlspecialchars($log['description']); ?></p>
                                    <?php if ($log['user_name']): ?>
                                    <small class="text-muted">by <?php echo htmlspecialchars($log['user_name']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($log['activity_date'])); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php 
                    endwhile;
                else:
                ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No recent activity found for this project.
                </div>
                <?php endif; ?>
            </div>
        </div>
