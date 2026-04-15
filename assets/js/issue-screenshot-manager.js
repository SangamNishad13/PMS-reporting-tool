/**
 * Issue Page Screenshot Manager
 * Handles screenshot uploads and management for issues
 */

if (!window.IssueScreenshotManager) {
window.IssueScreenshotManager = class IssueScreenshotManager {
    constructor(config = {}) {
        this.baseDir = config.baseDir || '';
        this.projectId = config.projectId || 0;
        this.apiUrl = `${this.baseDir}/api/issue_screenshot_upload.php`;
        this.pendingDeleteScreenshotId = null;
        this.screenshots = [];
        this.filteredScreenshots = [];
        this.pageSize = 10;
        this.init();
        this.updateCountBadge();
    }

    init() {
        this.setupEventListeners();
        this.setupDeleteModal();
        this.setupViewControls();
    }

    setupEventListeners() {
        // Upload button click
        document.addEventListener('click', (e) => {
            if (e.target.closest('.btn-upload-page-screenshots')) {
                const pageId = e.target.closest('[data-page-id]')?.dataset.pageId;
                if (pageId) {
                    this.openUploadModal(pageId);
                }
            }

            if (e.target.closest('.btn-open-page-screenshots')) {
                const pageId = e.target.closest('[data-page-id]')?.dataset.pageId;
                if (pageId) {
                    this.openViewModal(pageId);
                }
            }

            // Delete screenshot
            if (e.target.closest('.btn-delete-screenshot')) {
                const screenshotId = e.target.closest('[data-screenshot-id]')?.dataset.screenshotId;
                if (screenshotId) {
                    this.promptDeleteScreenshot(screenshotId);
                }
            }

            if (e.target.closest('.btn-view-screenshot')) {
                const screenshotId = e.target.closest('[data-screenshot-id]')?.dataset.screenshotId;
                if (screenshotId) {
                    this.viewScreenshot(screenshotId);
                }
            }
        });

        // Upload form submit — delegated so it works regardless of when modal renders
        document.addEventListener('submit', (e) => {
            if (e.target && e.target.id === 'screenshotUploadForm') {
                this.uploadScreenshots(e);
            }
        });
    }

    setupDeleteModal() {
        const confirmBtn = document.getElementById('confirmDeleteScreenshotBtn');
        if (!confirmBtn) {
            return;
        }

        confirmBtn.addEventListener('click', () => {
            if (!this.pendingDeleteScreenshotId) {
                return;
            }
            this.deleteScreenshot(this.pendingDeleteScreenshotId);
        });
    }

    setupViewControls() {
        const searchInput = document.getElementById('pageScreenshotsSearchInput');
        const urlFilter = document.getElementById('pageScreenshotsUrlFilter');
        const pageSizeSelect = document.getElementById('pageScreenshotsPageSize');
        const resetBtn = document.getElementById('pageScreenshotsResetFiltersBtn');
        const prevBtn = document.getElementById('pageScreenshotsPrevBtn');
        const nextBtn = document.getElementById('pageScreenshotsNextBtn');

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                this.currentPage = 1;
                this.applyScreenshotFilters();
            });
        }

        if (urlFilter) {
            urlFilter.addEventListener('change', () => {
                this.currentPage = 1;
                this.applyScreenshotFilters();
            });
        }

        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', () => {
                this.pageSize = parseInt(pageSizeSelect.value, 10) || 10;
                this.currentPage = 1;
                this.renderScreenshotsTable();
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', () => this.resetViewFilters());
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (this.currentPage > 1) {
                    this.currentPage -= 1;
                    this.renderScreenshotsTable();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const totalPages = this.getTotalPages();
                if (this.currentPage < totalPages) {
                    this.currentPage += 1;
                    this.renderScreenshotsTable();
                }
            });
        }
    }

    openUploadModal(pageId) {
        const modal = new bootstrap.Modal(document.getElementById('issueScreenshotUploadModal'));
        
        // Set form values
        document.getElementById('screenshotUploadForm').dataset.pageId = pageId;
        
        // Reset form
        this.resetUploadForm();
        
        // Load grouped URLs for this page
        this.loadGroupedUrls(pageId);

        modal.show();
    }

    openViewModal(pageId) {
        const modal = new bootstrap.Modal(document.getElementById('pageScreenshotsViewModal'));
        const tableBody = document.getElementById('pageScreenshotsTableBody');

        if (tableBody) {
            tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading screenshots...</td></tr>';
        }

        this.resetViewFilters();
        this.loadScreenshots(pageId);
        modal.show();
    }

    setStatus(message, type = 'info') {
        const status = document.getElementById('screenshotUploadStatus');
        if (!status) return;

        if (!message) {
            status.className = 'd-none';
            status.innerHTML = '';
            return;
        }

        status.className = `alert alert-${type} small mb-3`;
        status.classList.remove('d-none'); // Explicitly show
        status.innerHTML = message;
    }

    buildSecureFileUrl(filePath) {
        const normalizedPath = String(filePath || '').trim().replace(/^\/+/, '');
        if (!normalizedPath) {
            return '';
        }

        let base = this.baseDir ? this.baseDir.replace(/\/$/, '') : '';
        // Ensure base starts with / if it's not empty and not absolute
        if (base && !base.startsWith('/') && !base.includes('://')) {
            base = '/' + base;
        }
        
        return `${base}/api/secure_file.php?path=${encodeURIComponent(normalizedPath)}`;
    }

    resolveImageUrl(screenshot) {
        if (screenshot?.public_url) {
            return screenshot.public_url;
        }

        const filePath = String(screenshot?.file_path || '').trim().replace(/^\/+/, '');
        if (!filePath) {
            return '';
        }

        return this.buildSecureFileUrl(filePath);
    }

    setupGroupedUrlSelect() {
        const select = document.getElementById('screenshotGroupedUrlSelect');
        if (!select) return;

        if (window.jQuery && typeof window.jQuery.fn.select2 === 'function') {
            const $select = window.jQuery(select);
            if (!$select.hasClass('select2-hidden-accessible')) {
                $select.select2({
                    width: '100%',
                    placeholder: select.dataset.placeholder || '-- Select URL (optional) --',
                    allowClear: true,
                    dropdownParent: window.jQuery('#issueScreenshotUploadModal')
                });
            }
        }
    }

    renderGroupedUrlOptions(groupedUrls) {
        const select = document.getElementById('screenshotGroupedUrlSelect');
        if (!select) return;

        select.innerHTML = '<option value="">-- Select URL (optional) --</option>';
        
        groupedUrls.forEach((url, index) => {
            const urlText = String(url?.url || url?.normalized_url || '').trim();
            if (!urlText) {
                return;
            }

            const option = document.createElement('option');
            option.value = url.id ? String(url.id) : `fallback_${index}`;
            option.textContent = urlText;
            select.appendChild(option);
        });

        if (window.jQuery && typeof window.jQuery.fn.select2 === 'function') {
            window.jQuery(select).val('').trigger('change.select2');
        }
    }

    loadGroupedUrls(pageId) {
        const select = document.getElementById('screenshotGroupedUrlSelect');
        if (!select) return;

        this.setupGroupedUrlSelect();
        select.innerHTML = '<option value="">Loading URLs...</option>';

        fetch(`${this.apiUrl}?action=grouped_urls&page_id=${encodeURIComponent(pageId)}`)
            .then((response) => response.json())
            .then((data) => {
                const groupedUrls = Array.isArray(data.grouped_urls)
                    ? data.grouped_urls
                    : (window.ProjectConfig?.groupedUrls || []);
                this.renderGroupedUrlOptions(groupedUrls);
            })
            .catch((error) => {
                console.error('Error loading grouped URLs:', error);
                this.renderGroupedUrlOptions(window.ProjectConfig?.groupedUrls || []);
            });
    }

    loadScreenshots(pageId) {
        fetch(`${this.apiUrl}?action=list&page_id=${pageId}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    this.screenshots = Array.isArray(data.screenshots) ? data.screenshots : [];
                    this.populateUrlFilterOptions();
                    this.applyScreenshotFilters();
                    this.updateCountBadge(this.screenshots.length);
                }
            })
            .catch(err => console.error('Error loading screenshots:', err));
    }

    async updateCountBadge(count = null) {
        const pageId = this.pageId || window.ProjectConfig?.pageId;
        if (!pageId) return;

        if (count === null) {
            try {
                const r = await fetch(`${this.apiUrl}?action=count&page_id=${pageId}`);
                const data = await r.json();
                if (data.success) {
                    count = data.count;
                }
            } catch (e) {}
        }

        if (count !== null) {
            const badges = document.querySelectorAll(`.screenshot-count-badge[data-page-id="${pageId}"]`);
            badges.forEach(badge => {
                badge.textContent = count;
                badge.classList.toggle('d-none', count === 0);
            });
        }
    }

    populateUrlFilterOptions() {
        const urlFilter = document.getElementById('pageScreenshotsUrlFilter');
        if (!urlFilter) return;

        const options = new Map();
        this.screenshots.forEach((item) => {
            const key = String(item.grouped_url || item.description || '').trim();
            if (key) {
                options.set(key, key);
            }
        });

        urlFilter.innerHTML = '<option value="">All</option>';
        Array.from(options.values()).sort().forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            urlFilter.appendChild(option);
        });
    }

    resetViewFilters() {
        const searchInput = document.getElementById('pageScreenshotsSearchInput');
        const urlFilter = document.getElementById('pageScreenshotsUrlFilter');
        const pageSizeSelect = document.getElementById('pageScreenshotsPageSize');

        if (searchInput) searchInput.value = '';
        if (urlFilter) urlFilter.value = '';
        if (pageSizeSelect) pageSizeSelect.value = '10';

        this.pageSize = 10;
        this.currentPage = 1;
        this.filteredScreenshots = Array.isArray(this.screenshots) ? [...this.screenshots] : [];
        this.updatePaginationControls();
    }

    applyScreenshotFilters() {
        const searchValue = String(document.getElementById('pageScreenshotsSearchInput')?.value || '').trim().toLowerCase();
        const urlFilterValue = String(document.getElementById('pageScreenshotsUrlFilter')?.value || '').trim();

        this.filteredScreenshots = this.screenshots.filter((item) => {
            const haystack = [
                item.original_filename,
                item.grouped_url,
                item.description,
                item.full_name,
                item.created_at
            ].join(' ').toLowerCase();

            const matchesSearch = !searchValue || haystack.includes(searchValue);
            const filterTarget = String(item.grouped_url || item.description || '').trim();
            const matchesUrl = !urlFilterValue || filterTarget === urlFilterValue;
            return matchesSearch && matchesUrl;
        });

        this.renderScreenshotsTable();
    }

    getTotalPages() {
        return Math.max(1, Math.ceil(this.filteredScreenshots.length / this.pageSize));
    }

    renderScreenshotsTable() {
        const totalPages = this.getTotalPages();
        if (this.currentPage > totalPages) {
            this.currentPage = totalPages;
        }

        const startIndex = (this.currentPage - 1) * this.pageSize;
        const visibleScreenshots = this.filteredScreenshots.slice(startIndex, startIndex + this.pageSize);
        this.displayScreenshotsTable(visibleScreenshots, startIndex);
        this.updatePaginationControls();
    }

    updatePaginationControls() {
        const total = this.filteredScreenshots.length;
        const totalPages = this.getTotalPages();
        const info = document.getElementById('pageScreenshotsPaginationInfo');
        const prevBtn = document.getElementById('pageScreenshotsPrevBtn');
        const nextBtn = document.getElementById('pageScreenshotsNextBtn');

        const start = total === 0 ? 0 : ((this.currentPage - 1) * this.pageSize) + 1;
        const end = total === 0 ? 0 : Math.min(this.currentPage * this.pageSize, total);

        if (info) {
            info.textContent = `Showing ${start}-${end} of ${total} screenshots`;
        }

        if (prevBtn) prevBtn.disabled = this.currentPage <= 1;
        if (nextBtn) nextBtn.disabled = this.currentPage >= totalPages;
    }

    displayScreenshotsTable(screenshots, startIndex = 0) {
        const tableBody = document.getElementById('pageScreenshotsTableBody');
        if (!tableBody) return;

        if (screenshots.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No screenshots uploaded yet.</td></tr>';
            return;
        }

        let html = '';
        screenshots.forEach((ss, index) => {
            const imageUrl = this.resolveImageUrl(ss);
            const createdAt = ss.created_at ? new Date(ss.created_at).toLocaleString() : '-';
            html += `
                <tr class="screenshot-item" data-screenshot-id="${ss.id}">
                    <td>${startIndex + index + 1}</td>
                    <td>
                        <img src="${imageUrl}" class="img-thumbnail" style="width: 140px; height: 88px; object-fit: cover;" alt="${ss.original_filename}">
                        <div class="small text-muted mt-1 text-break">${ss.original_filename}</div>
                    </td>
                    <td>
                        ${ss.grouped_url ? `<div><a href="${ss.grouped_url}" target="_blank" class="link-primary">${this.shortenUrl(ss.grouped_url, 70)}</a></div>` : '<div>-</div>'}
                    </td>
                    <td>
                        ${ss.description ? `<div class="text-break">${ss.description}</div>` : '<div>-</div>'}
                    </td>
                    <td>
                        <div>${createdAt}</div>
                        <div class="small text-muted">${ss.full_name || ''}</div>
                    </td>
                    <td>
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-sm btn-outline-primary btn-view-screenshot" data-screenshot-id="${ss.id}">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-sm btn-outline-danger btn-delete-screenshot" data-screenshot-id="${ss.id}">
                                <i class="fas fa-trash me-1"></i>Delete
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        tableBody.innerHTML = html;
    }

    shortenUrl(url, maxLength = 40) {
        if (url.length <= maxLength) return url;
        return url.substring(0, maxLength - 3) + '...';
    }

    resetUploadForm() {
        const form = document.getElementById('screenshotUploadForm');
        if (form) {
            form.reset();
            document.getElementById('screenshotFileInput').value = '';
        }
        this.setStatus('');
    }

    async uploadScreenshots(e) {
        e.preventDefault();
        const form = e.target;
        const issueId = form.dataset.issueId;
        const pageId = form.dataset.pageId;
        const files = document.getElementById('screenshotFileInput').files;
        const groupedUrlSelect = document.getElementById('screenshotGroupedUrlSelect');
        const groupedUrlId = groupedUrlSelect ? groupedUrlSelect.value : '';
        const selectedUrlText = groupedUrlSelect && groupedUrlSelect.selectedIndex >= 0
            ? groupedUrlSelect.options[groupedUrlSelect.selectedIndex].text.trim()
            : '';
        const description = document.getElementById('screenshotDescription').value;

        if (files.length === 0) {
            alert('Please select at least one screenshot file');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('issue_id', issueId || 0);
        formData.append('page_id', pageId);
        formData.append('grouped_url_id', /^\d+$/.test(String(groupedUrlId)) ? groupedUrlId : 0);
        if (selectedUrlText && !selectedUrlText.startsWith('-- Select URL')) {
            formData.append('selected_url_text', selectedUrlText);
        }
        formData.append('description', description);

        for (let i = 0; i < files.length; i++) {
            formData.append('screenshots[]', files[i]);
        }

        const uploadBtn = form.querySelector('[type="submit"]');
        const originalText = uploadBtn.innerHTML;
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Uploading...';

        try {
            this.setStatus('Uploading screenshots...', 'info');
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                body: formData
            });

            const raw = await response.text();
            let data = null;

            try {
                data = raw ? JSON.parse(raw) : null;
            } catch (parseError) {
                // If parsing fails, the response might be a PHP error or HTML - log for debug
                console.error('Server response was not valid JSON:', raw);
                throw new Error('Server returned an invalid response. This often happens if there is a database error or a misconfiguration.');
            }

            if (!response.ok) {
                throw new Error(data?.message || `Upload failed with status ${response.status}`);
            }

            if (data.success) {
                this.setStatus(data.message || 'Screenshots uploaded successfully', 'success');
                showNotification('Screenshots uploaded successfully', 'success');
                this.loadScreenshots(pageId);
                this.resetUploadForm();
            } else {
                const errorText = Array.isArray(data.errors) && data.errors.length
                    ? `${data.message}<br>${data.errors.map((item) => `- ${item}`).join('<br>')}`
                    : (data.message || 'Upload failed');
                this.setStatus(errorText, 'danger');
                showNotification(data.message || 'Upload failed', 'danger');
            }
        } catch (error) {
            console.error('Upload error:', error);
            const msg = error.message || 'Upload failed unknown error';
            this.setStatus(msg, 'danger');
            showNotification('Upload failed: ' + msg, 'danger');
        } finally {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = originalText;
        }
    }

    deleteScreenshot(screenshotId) {
        const confirmBtn = document.getElementById('confirmDeleteScreenshotBtn');
        if (confirmBtn) {
            confirmBtn.disabled = true;
        }

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('screenshot_id', screenshotId);

        fetch(this.apiUrl, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showNotification('Screenshot deleted', 'success');
                this.screenshots = this.screenshots.filter((item) => String(item.id) !== String(screenshotId));
                this.applyScreenshotFilters();
            } else {
                showNotification('Delete failed', 'danger');
            }
        })
        .catch(err => {
            console.error('Delete error:', err);
            showNotification('Delete failed', 'danger');
        })
        .finally(() => {
            const modalEl = document.getElementById('deleteScreenshotConfirmModal');
            if (modalEl && window.bootstrap?.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
            this.pendingDeleteScreenshotId = null;
            if (confirmBtn) {
                confirmBtn.disabled = false;
            }
        });
    }

    promptDeleteScreenshot(screenshotId) {
        this.pendingDeleteScreenshotId = screenshotId;

        const modalEl = document.getElementById('deleteScreenshotConfirmModal');
        if (!modalEl || !window.bootstrap?.Modal) {
            this.deleteScreenshot(screenshotId);
            return;
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    viewScreenshot(screenshotId) {
        const item = document.querySelector(`[data-screenshot-id="${screenshotId}"]`);
        if (!item) return;

        const imgSrc = item.querySelector('img')?.src;

        if (imgSrc) {
            // Open in modal or new window
            window.open(imgSrc, '_blank');
        }
    }
}

// Helper function to show notifications
function showNotification(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('[role="main"]') || document.body;
    const firstChild = container.querySelector('.card') || container.firstChild;
    if (firstChild) {
        firstChild.before(alertDiv);
    } else {
        container.appendChild(alertDiv);
    }
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        window.issueScreenshotManager = new IssueScreenshotManager({
            baseDir: window.ProjectConfig?.baseDir || '',
            projectId: window.ProjectConfig?.projectId || 0
        });
    });
}
