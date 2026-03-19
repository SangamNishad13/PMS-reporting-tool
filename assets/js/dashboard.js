/**
 * Client Dashboard JavaScript
 * Handles widget interactions, AJAX data loading, and chart rendering
 * Implements responsive design and accessibility compliance
 */

class ClientDashboard {
    constructor() {
        this.config = window.dashboardConfig || {};
        this.charts = {};
        this.refreshInterval = null;
        this.isLoading = false;
        
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.loadInitialData();
        this.setupAutoRefresh();
        this.initializeCharts();
    }
    
    bindEvents() {
        // Refresh button
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="refresh"]')) {
                e.preventDefault();
                this.refreshAllWidgets();
            }
        });
        
        // Export buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="export"]')) {
                e.preventDefault();
                const format = e.target.dataset.format;
                this.requestExport(format);
            }
        });
        
        // Drill-down buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="drill-down"]')) {
                e.preventDefault();
                const widget = e.target.dataset.widget;
                this.drillDown(widget);
            }
        });
        
        // Widget refresh buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="refresh-widget"]')) {
                e.preventDefault();
                const widget = e.target.dataset.widget;
                this.refreshWidget(widget);
            }
        });
    }
    
    loadInitialData() {
        this.showGlobalLoading();
        
        // Load all widgets
        const widgets = [
            'user_affected_summary',
            'wcag_compliance_summary', 
            'severity_distribution',
            'common_issues_top',
            'blocker_issues_summary',
            'page_issues_top',
            'commented_issues_summary',
            'compliance_trend',
            'recent_activity'
        ];
        
        const promises = widgets.map(widget => this.loadWidget(widget));
        
        Promise.allSettled(promises)
            .then(results => {
                this.hideGlobalLoading();
                this.handleLoadResults(results);
            })
            .catch(error => {
                this.hideGlobalLoading();
                this.showError('Failed to load dashboard data: ' + error.message);
            });
    }
    
    async loadWidget(widgetType) {
        const widgetElement = document.getElementById(`widget-${widgetType}`);
        if (!widgetElement) {
            console.warn(`Widget element not found: widget-${widgetType}`);
            return;
        }
        
        this.showWidgetLoading(widgetElement);
        
        try {
            const response = await fetch('/client/ajax/widget', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    csrf_token: this.config.csrfToken,
                    widget_type: widgetType,
                    project_ids: this.config.projectIds.join(',')
                })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.renderWidget(widgetType, data, widgetElement);
            this.hideWidgetLoading(widgetElement);
            
        } catch (error) {
            console.error(`Error loading widget ${widgetType}:`, error);
            this.showWidgetError(widgetElement, error.message);
        }
    }
    
    renderWidget(widgetType, data, element) {
        const contentElement = element.querySelector('.widget-content');
        if (!contentElement) return;
        
        switch (widgetType) {
            case 'user_affected_summary':
                this.renderUserAffectedWidget(data, contentElement);
                break;
            case 'wcag_compliance_summary':
                this.renderWCAGComplianceWidget(data, contentElement);
                break;
            case 'severity_distribution':
                this.renderSeverityWidget(data, contentElement);
                break;
            case 'common_issues_top':
                this.renderCommonIssuesWidget(data, contentElement);
                break;
            case 'blocker_issues_summary':
                this.renderBlockerIssuesWidget(data, contentElement);
                break;
            case 'page_issues_top':
                this.renderPageIssuesWidget(data, contentElement);
                break;
            case 'commented_issues_summary':
                this.renderCommentedIssuesWidget(data, contentElement);
                break;
            case 'compliance_trend':
                this.renderComplianceTrendWidget(data, contentElement);
                break;
            case 'recent_activity':
                this.renderRecentActivityWidget(data, contentElement);
                break;
        }
    }
    
    renderUserAffectedWidget(data, element) {
        const canvas = element.querySelector('#userAffectedChart');
        if (canvas && data.chart_data) {
            this.createPieChart(canvas, data.chart_data, {
                title: 'Users Affected by Category',
                colors: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0']
            });
        }
        
        const summary = element.querySelector('.widget-summary');
        if (summary && data.summary_text) {
            summary.textContent = data.summary_text;
        }
    }
    
    renderWCAGComplianceWidget(data, element) {
        const canvas = element.querySelector('#wcagComplianceChart');
        if (canvas && data.chart_data) {
            this.createBarChart(canvas, data.chart_data, {
                title: 'WCAG Compliance by Level',
                colors: ['#28a745', '#ffc107', '#dc3545']
            });
        }
        
        const summary = element.querySelector('.widget-summary');
        if (summary && data.summary_text) {
            summary.textContent = data.summary_text;
        }
    }
    
    renderSeverityWidget(data, element) {
        const canvas = element.querySelector('#severityChart');
        if (canvas && data.chart_data) {
            this.createHorizontalBarChart(canvas, data.chart_data, {
                title: 'Issues by Severity',
                colors: ['#dc3545', '#fd7e14', '#ffc107', '#28a745']
            });
        }
    }
    
    renderCommonIssuesWidget(data, element) {
        const tableContainer = element.querySelector('.widget-table');
        if (tableContainer && data.top_issues) {
            const table = this.createTable(
                ['Issue', 'Frequency', 'Impact'],
                data.top_issues.map(issue => [
                    issue.title,
                    issue.frequency,
                    this.formatImpactScore(issue.impact_score)
                ])
            );
            tableContainer.innerHTML = '';
            tableContainer.appendChild(table);
        }
    }
    
    renderBlockerIssuesWidget(data, element) {
        const html = `
            <div class="row text-center">
                <div class="col-6">
                    <div class="h3 text-danger mb-1">${data.total_blockers || 0}</div>
                    <div class="small text-muted">Total Blockers</div>
                </div>
                <div class="col-6">
                    <div class="h3 text-warning mb-1">${data.open_blockers || 0}</div>
                    <div class="small text-muted">Open Blockers</div>
                </div>
            </div>
            <div class="mt-3">
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-success" role="progressbar" 
                         style="width: ${data.resolution_rate || 0}%" 
                         aria-valuenow="${data.resolution_rate || 0}" 
                         aria-valuemin="0" aria-valuemax="100">
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    ${data.resolution_rate || 0}% Resolution Rate
                </div>
            </div>
        `;
        element.innerHTML = html;
    }
    
    renderPageIssuesWidget(data, element) {
        const tableContainer = element.querySelector('.widget-table');
        if (tableContainer && data.top_pages) {
            const table = this.createTable(
                ['Page', 'Issues', 'Severity'],
                data.top_pages.map(page => [
                    this.truncateUrl(page.url),
                    page.issue_count,
                    this.formatSeverity(page.avg_severity)
                ])
            );
            tableContainer.innerHTML = '';
            tableContainer.appendChild(table);
        }
    }
    
    renderCommentedIssuesWidget(data, element) {
        const html = `
            <div class="row text-center mb-3">
                <div class="col-6">
                    <div class="h4 text-info mb-1">${data.total_commented || 0}</div>
                    <div class="small text-muted">With Comments</div>
                </div>
                <div class="col-6">
                    <div class="h4 text-warning mb-1">${data.needs_attention || 0}</div>
                    <div class="small text-muted">Need Attention</div>
                </div>
            </div>
            <div class="engagement-meter">
                <div class="small text-muted mb-1">Engagement Level</div>
                <div class="progress" style="height: 6px;">
                    <div class="progress-bar bg-info" role="progressbar" 
                         style="width: ${data.engagement_level || 0}%" 
                         aria-valuenow="${data.engagement_level || 0}" 
                         aria-valuemin="0" aria-valuemax="100">
                    </div>
                </div>
            </div>
        `;
        element.innerHTML = html;
    }
    
    renderComplianceTrendWidget(data, element) {
        const canvas = element.querySelector('#complianceTrendChart');
        if (canvas && data.chart_data) {
            this.createLineChart(canvas, data.chart_data, {
                title: 'Compliance Trend (30 Days)',
                colors: ['#28a745', '#17a2b8']
            });
        }
    }
    
    renderRecentActivityWidget(data, element) {
        if (!data.activities || data.activities.length === 0) {
            element.innerHTML = '<div class="text-center text-muted py-3">No recent activity</div>';
            return;
        }
        
        const html = data.activities.map(activity => `
            <div class="activity-item d-flex align-items-start mb-3">
                <div class="activity-icon me-3">
                    <i class="fas ${this.getActivityIcon(activity.type)} text-${this.getActivityColor(activity.type)}"></i>
                </div>
                <div class="activity-content flex-grow-1">
                    <div class="activity-title fw-medium">${activity.title}</div>
                    <div class="activity-meta small text-muted">
                        ${activity.project_name} • ${this.formatTimestamp(activity.timestamp)}
                        <span class="badge bg-${this.getSeverityColor(activity.severity)} ms-2">${activity.severity}</span>
                    </div>
                </div>
            </div>
        `).join('');
        
        element.innerHTML = html;
    }
    
    // Chart creation methods
    createPieChart(canvas, data, options = {}) {
        const ctx = canvas.getContext('2d');
        
        if (this.charts[canvas.id]) {
            this.charts[canvas.id].destroy();
        }
        
        this.charts[canvas.id] = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: data.labels || [],
                datasets: [{
                    data: data.values || [],
                    backgroundColor: options.colors || this.getDefaultColors(),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: !!options.title,
                        text: options.title
                    },
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
    
    createBarChart(canvas, data, options = {}) {
        const ctx = canvas.getContext('2d');
        
        if (this.charts[canvas.id]) {
            this.charts[canvas.id].destroy();
        }
        
        this.charts[canvas.id] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: options.title || 'Data',
                    data: data.values || [],
                    backgroundColor: options.colors || this.getDefaultColors(),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: !!options.title,
                        text: options.title
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
    
    createHorizontalBarChart(canvas, data, options = {}) {
        const ctx = canvas.getContext('2d');
        
        if (this.charts[canvas.id]) {
            this.charts[canvas.id].destroy();
        }
        
        this.charts[canvas.id] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: options.title || 'Data',
                    data: data.values || [],
                    backgroundColor: options.colors || this.getDefaultColors(),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    title: {
                        display: !!options.title,
                        text: options.title
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
    
    createLineChart(canvas, data, options = {}) {
        const ctx = canvas.getContext('2d');
        
        if (this.charts[canvas.id]) {
            this.charts[canvas.id].destroy();
        }
        
        this.charts[canvas.id] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: data.datasets || [{
                    label: options.title || 'Data',
                    data: data.values || [],
                    borderColor: options.colors?.[0] || '#007bff',
                    backgroundColor: (options.colors?.[0] || '#007bff') + '20',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: !!options.title,
                        text: options.title
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
    
    // Utility methods
    createTable(headers, rows) {
        const table = document.createElement('table');
        table.className = 'table table-sm table-hover';
        
        // Create header
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        headers.forEach(header => {
            const th = document.createElement('th');
            th.textContent = header;
            th.className = 'small text-muted';
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);
        
        // Create body
        const tbody = document.createElement('tbody');
        rows.forEach(row => {
            const tr = document.createElement('tr');
            row.forEach(cell => {
                const td = document.createElement('td');
                td.textContent = cell;
                td.className = 'small';
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        
        return table;
    }
    
    getDefaultColors() {
        return ['#007bff', '#28a745', '#ffc107', '#dc3545', '#6f42c1', '#fd7e14', '#20c997', '#6c757d'];
    }
    
    getActivityIcon(type) {
        const icons = {
            'issue_resolved': 'fa-check-circle',
            'issue_created': 'fa-plus-circle',
            'comment_added': 'fa-comment',
            'issue_updated': 'fa-edit'
        };
        return icons[type] || 'fa-circle';
    }
    
    getActivityColor(type) {
        const colors = {
            'issue_resolved': 'success',
            'issue_created': 'primary',
            'comment_added': 'info',
            'issue_updated': 'warning'
        };
        return colors[type] || 'secondary';
    }
    
    getSeverityColor(severity) {
        const colors = {
            'Critical': 'danger',
            'High': 'warning',
            'Medium': 'info',
            'Low': 'secondary'
        };
        return colors[severity] || 'secondary';
    }
    
    formatTimestamp(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;
        
        if (diff < 3600000) { // Less than 1 hour
            const minutes = Math.floor(diff / 60000);
            return `${minutes}m ago`;
        } else if (diff < 86400000) { // Less than 1 day
            const hours = Math.floor(diff / 3600000);
            return `${hours}h ago`;
        } else {
            return date.toLocaleDateString();
        }
    }
    
    formatImpactScore(score) {
        if (score >= 8) return 'High';
        if (score >= 5) return 'Medium';
        return 'Low';
    }
    
    formatSeverity(severity) {
        return severity || 'Unknown';
    }
    
    truncateUrl(url, maxLength = 30) {
        if (!url || url.length <= maxLength) return url;
        return url.substring(0, maxLength) + '...';
    }
    
    // Loading and error handling
    showGlobalLoading() {
        this.isLoading = true;
        const refreshBtn = document.querySelector('[data-action="refresh"]');
        if (refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        }
    }
    
    hideGlobalLoading() {
        this.isLoading = false;
        const refreshBtn = document.querySelector('[data-action="refresh"]');
        if (refreshBtn) {
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
        }
    }
    
    showWidgetLoading(element) {
        const loading = element.querySelector('.widget-loading');
        const content = element.querySelector('.widget-content');
        if (loading) loading.style.display = 'block';
        if (content) content.style.display = 'none';
    }
    
    hideWidgetLoading(element) {
        const loading = element.querySelector('.widget-loading');
        const content = element.querySelector('.widget-content');
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';
    }
    
    showWidgetError(element, message) {
        const content = element.querySelector('.widget-content');
        if (content) {
            content.innerHTML = `
                <div class="alert alert-danger text-center">
                    <i class="fas fa-exclamation-triangle mb-2"></i><br>
                    <small>${message}</small>
                </div>
            `;
            content.style.display = 'block';
        }
        this.hideWidgetLoading(element);
    }
    
    showError(message) {
        // Create or update global error alert
        let errorAlert = document.querySelector('.dashboard-error-alert');
        if (!errorAlert) {
            errorAlert = document.createElement('div');
            errorAlert.className = 'alert alert-danger alert-dismissible dashboard-error-alert';
            errorAlert.innerHTML = `
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                <i class="fas fa-exclamation-triangle me-2"></i>
                <span class="error-message"></span>
            `;
            document.querySelector('.client-dashboard').prepend(errorAlert);
        }
        
        errorAlert.querySelector('.error-message').textContent = message;
        errorAlert.style.display = 'block';
    }
    
    // Auto-refresh functionality
    setupAutoRefresh() {
        if (this.config.autoRefresh && this.config.refreshInterval) {
            this.refreshInterval = setInterval(() => {
                if (!this.isLoading) {
                    this.refreshAllWidgets();
                }
            }, this.config.refreshInterval);
        }
    }
    
    refreshAllWidgets() {
        if (this.isLoading) return;
        this.loadInitialData();
    }
    
    refreshWidget(widgetType) {
        this.loadWidget(widgetType);
    }
    
    // Export functionality
    async requestExport(format) {
        try {
            const response = await fetch('/client/export/request', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    csrf_token: this.config.csrfToken,
                    export_type: format,
                    report_type: 'unified_dashboard',
                    project_ids: this.config.projectIds.join(',')
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                if (data.status === 'completed') {
                    // Direct download
                    window.location.href = data.download_url;
                } else {
                    // Queued for processing
                    this.showExportStatus(data.request_id);
                }
            } else {
                throw new Error(data.error);
            }
            
        } catch (error) {
            this.showError('Export failed: ' + error.message);
        }
    }
    
    showExportStatus(requestId) {
        // Show export status modal or notification
        alert('Export queued for processing. You will be notified when ready.');
    }
    
    // Drill-down functionality
    drillDown(widget) {
        // Navigate to detailed view
        const urls = {
            'user_affected': '/client/reports/user-affected',
            'wcag_compliance': '/client/reports/wcag-compliance',
            'severity': '/client/reports/severity',
            'common_issues': '/client/reports/common-issues',
            'blockers': '/client/reports/blockers',
            'page_issues': '/client/reports/page-issues',
            'compliance_trend': '/client/reports/compliance-trend'
        };
        
        if (urls[widget]) {
            window.location.href = urls[widget];
        }
    }
    
    initializeCharts() {
        // Ensure Chart.js is loaded
        if (typeof Chart === 'undefined') {
            console.error('Chart.js is not loaded');
            return;
        }
        
        // Set global Chart.js defaults
        Chart.defaults.font.family = 'system-ui, -apple-system, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#6c757d';
    }
    
    handleLoadResults(results) {
        const failedWidgets = results
            .filter(result => result.status === 'rejected')
            .map((result, index) => ({ index, error: result.reason }));
        
        if (failedWidgets.length > 0) {
            console.warn('Some widgets failed to load:', failedWidgets);
        }
    }
    
    destroy() {
        // Clean up charts
        Object.values(this.charts).forEach(chart => chart.destroy());
        this.charts = {};
        
        // Clear intervals
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
    }
}

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.clientDashboard = new ClientDashboard();
});

// Clean up on page unload
window.addEventListener('beforeunload', () => {
    if (window.clientDashboard) {
        window.clientDashboard.destroy();
    }
});