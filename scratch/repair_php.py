
import os

path = r'c:\xampp\htdocs\PMS\modules\projects\issues_page_detail.php'
with open(path, 'r', encoding='utf-8', newline='') as f:
    lines = f.readlines()

# Verify state: find line 987
# lines[986] should be '                                         </option>\n'
# lines[987] should be '                    <div class=\"final-issues-table-wrap\">\n'

insertion_point = 987 # Default

for idx, line in enumerate(lines):
    if 'div class="final-issues-table-wrap"' in line and idx > 970:
        insertion_point = idx
        break

new_segment = [
    '                                    <?php endforeach; ?>\n',
    '                                </select>\n',
    '                            </div>\n',
    '                            <div class="col-md-3">\n',
    '                                <label class="form-label small mb-1 fw-bold"><i class="fas fa-user me-1"></i> Reporter</label>\n',
    '                                <select class="form-select form-select-sm" id="filterReporter" multiple>\n',
    '                                    <option value="">All Reporters</option>\n',
    '                                    <?php foreach ($projectUsers as $reporter): ?>\n',
    '                                        <option value="<?php echo $reporter[\'id\']; ?>"><?php echo htmlspecialchars($reporter[\'full_name\']); ?></option>\n',
    '                                    <?php endforeach; ?>\n',
    '                                </select>\n',
    '                            </div>\n',
    '                            <?php endif; ?>\n',
    '                            <div class="col-md-1">\n',
    '                                <button class="btn btn-sm btn-secondary w-100" id="clearFilters">\n',
    '                                    <i class="fas fa-times"></i> Clear\n',
    '                                </button>\n',
    '                            </div>\n',
    '                        </div>\n',
    '                    </div>\n',
    '\n',
    '                    <!-- Pagination Top -->\n',
    '                    <div class="px-3 py-2 border-bottom bg-white">\n',
    '                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">\n',
    '                            <div class="d-flex align-items-center gap-2 flex-wrap">\n',
    '                                <div class="d-flex align-items-center gap-1">\n',
    '                                    <label class="text-muted small mb-0">Per page:</label>\n',
    '                                    <select id="perPageSelect" class="form-select form-select-sm" style="width:auto; min-width:75px;">\n',
    '                                        <option value="25" selected>25</option>\n',
    '                                        <option value="50">50</option>\n',
    '                                        <option value="100">100</option>\n',
    '                                        <option value="250">250</option>\n',
    '                                        <option value="500">500</option>\n',
    '                                    </select>\n',
    '                                </div>\n',
    '                                <span class="text-muted small" id="paginationInfoTop"></span>\n',
    '                                <nav aria-label="Issues pagination top">\n',
    '                                    <ul class="pagination pagination-sm mb-0" id="paginationControlsTop"></ul>\n',
    '                                </nav>\n',
    '                            </div>\n',
    '                            <div>\n',
    '                                <button class="btn btn-sm btn-outline-primary" id="refreshBtn">\n',
    '                                    <i class="fas fa-sync-alt"></i> Refresh\n',
    '                                </button>\n',
    '                            </div>\n',
    '                        </div>\n',
    '                    </div>\n'
]

output_lines = lines[:insertion_point] + new_segment + lines[insertion_point:]

with open(path, 'w', encoding='utf-8', newline='') as f:
    f.writelines(output_lines)

print(f"Successfully repaired {path} at line {insertion_point}")
