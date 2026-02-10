        <!-- Assets Tab -->
        <div class="tab-pane fade" id="assets" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Project Assets</h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAssetModal">
                    <i class="fas fa-plus"></i> Add Asset
                </button>
            </div>

            <?php 
            // Get project assets
            $assets = $db->prepare("
                SELECT pa.*, u.full_name as creator_name 
                FROM project_assets pa 
                LEFT JOIN users u ON pa.created_by = u.id 
                WHERE pa.project_id = ?
                ORDER BY pa.created_at DESC
            ");
            $assets->execute([$projectId]);
            
            if ($assets->rowCount() > 0): ?>
            <div class="row">
                <?php while ($asset = $assets->fetch()): ?>
                <div class="col-md-4 mb-3">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($asset['asset_name']); ?></h5>
                                <?php if ($asset['asset_type'] === 'file'): ?>
                                    <span class="badge bg-secondary"><i class="fas fa-file"></i> File</span>
                                <?php elseif ($asset['asset_type'] === 'text'): ?>
                                    <span class="badge bg-success"><i class="fas fa-edit"></i> Text/Blog</span>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark"><i class="fas fa-link"></i> Link</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($asset['asset_type'] === 'link'): ?>
                                <?php if ($asset['link_type']): ?>
                                    <p class="mb-1 text-muted small"><strong>Type:</strong> <?php echo htmlspecialchars($asset['link_type']); ?></p>
                                <?php endif; ?>
                                <p class="card-text">
                                    <a href="<?php echo htmlspecialchars($asset['main_url']); ?>" target="_blank" class="text-break">
                                        <i class="fas fa-external-link-alt small"></i> <?php echo htmlspecialchars($asset['main_url']); ?>
                                    </a>
                                </p>
                            <?php elseif ($asset['asset_type'] === 'text'): ?>
                                <?php if ($asset['link_type']): ?>
                                    <p class="mb-1 text-muted small"><strong>Category:</strong> <?php echo htmlspecialchars($asset['link_type']); ?></p>
                                <?php endif; ?>
                                <div class="card-text">
                                    <div class="text-content-preview" style="max-height: 150px; overflow: hidden;">
                                        <?php 
                                        $content = $asset['text_content'] ?: $asset['description'] ?: '';
                                        echo strlen($content) > 200 ? substr(strip_tags($content), 0, 200) . '...' : strip_tags($content);
                                        ?>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#viewTextModal"
                                            data-title="<?php echo htmlspecialchars($asset['asset_name']); ?>"
                                            data-content="<?php echo htmlspecialchars($content); ?>">
                                        <i class="fas fa-eye"></i> View Full Content
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="d-grid">
                                    <a href="<?php echo $baseDir . '/' . htmlspecialchars($asset['file_path']); ?>" 
                                       target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-download"></i> Download File
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-transparent border-top-0 pt-0 d-flex justify-content-between align-items-end">
                            <small class="text-muted">
                                By: <?php echo htmlspecialchars($asset['creator_name'] ?: 'System'); ?>
                                <br>
                                <?php echo date('M d, Y', strtotime($asset['created_at'])); ?>
                            </small>
                            <?php 
                            $canDelete = in_array($userRole, ['admin', 'super_admin']) || 
                                         ($userRole === 'project_lead' && $project['project_lead_id'] == $userId);
                            if ($canDelete): 
                            ?>
                            <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/handle_asset.php" 
                                  onsubmit="var form = this; confirmModal('Are you sure you want to delete this asset?', function(){ form.submit(); }); return false;"
                                  class="d-inline">
                                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                                <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                <button type="submit" name="delete_asset" class="btn btn-sm btn-link text-danger p-0 border-0">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No assets uploaded for this project.
            </div>
            <?php endif; ?>
        </div>
