<?php

/**
 * VisualizationRenderer Class
 * 
 * Handles rendering of interactive charts, tables, and dashboard widgets
 * with Chart.js integration and accessibility compliance.
 * 
 * Requirements: 16.1, 16.2, 16.3, 16.4
 */

class VisualizationRenderer implements VisualizationInterface {
    
    private $chartIdCounter = 0;
    private $accessibilityConfig;
    
    public function __construct() {
        $this->accessibilityConfig = [
            'colorBlindSafe' => true,
            'highContrast' => true,
            'screenReaderSupport' => true
        ];
    }
    
    /**
     * Render interactive pie chart using Chart.js
     * 
     * @param array $data Chart data with labels and datasets
     * @param array $options Chart configuration options
     * @return string HTML with embedded Chart.js pie chart
     */
    public function renderPieChart(array $data, array $options = []): string {
        if (empty($data) || empty($data['labels']) || empty($data['datasets'])) {
            return $this->renderEmptyState('pie', 'No data available for pie chart');
        }
        
        $chartId = $this->generateChartId('pie');
        $config = $this->buildPieChartConfig($data, $options);
        
        return $this->generateChartHTML($chartId, $config, 'pie');
    }
    
    /**
     * Render interactive bar chart using Chart.js
     * 
     * @param array $data Chart data with labels and datasets
     * @param array $options Chart configuration options
     * @return string HTML with embedded Chart.js bar chart
     */
    public function renderBarChart(array $data, array $options = []): string {
        if (empty($data) || empty($data['labels']) || empty($data['datasets'])) {
            return $this->renderEmptyState('bar', 'No data available for bar chart');
        }
        
        $chartId = $this->generateChartId('bar');
        $config = $this->buildBarChartConfig($data, $options);
        
        return $this->generateChartHTML($chartId, $config, 'bar');
    }
    
    /**
     * Render interactive line chart using Chart.js
     * 
     * @param array $data Chart data with labels and datasets
     * @param array $options Chart configuration options
     * @return string HTML with embedded Chart.js line chart
     */
    public function renderLineChart(array $data, array $options = []): string {
        if (empty($data) || empty($data['labels']) || empty($data['datasets'])) {
            return $this->renderEmptyState('line', 'No data available for line chart');
        }
        
        $chartId = $this->generateChartId('line');
        $config = $this->buildLineChartConfig($data, $options);
        
        return $this->generateChartHTML($chartId, $config, 'line');
    }
    
    /**
     * Render responsive data table with sorting and filtering
     * 
     * @param array $data Table data rows
     * @param array $columns Column definitions with headers and keys
     * @return string HTML table with responsive design
     */
    public function renderTable(array $data, array $columns): string {
        if (empty($data) || empty($columns)) {
            return $this->renderEmptyState('table', 'No data available for table');
        }
        
        $tableId = $this->generateChartId('table');
        
        return $this->generateTableHTML($tableId, $data, $columns);
    }
    
    /**
     * Render dashboard widget with specific type and data
     * 
     * @param string $type Widget type (summary, chart, metric)
     * @param array $data Widget data
     * @return string HTML dashboard widget
     */
    public function renderDashboardWidget(string $type, array $data): string {
        if (empty($data)) {
            return $this->renderEmptyState('widget', 'No data available for widget');
        }
        
        $widgetId = $this->generateChartId('widget');
        
        switch ($type) {
            case 'summary':
                return $this->generateSummaryWidget($widgetId, $data);
            case 'chart':
                return $this->generateChartWidget($widgetId, $data);
            case 'metric':
                return $this->generateMetricWidget($widgetId, $data);
            case 'analytics':
                return $this->generateAnalyticsWidget($widgetId, $data);
            case 'trend':
                return $this->generateTrendWidget($widgetId, $data);
            case 'comparison':
                return $this->generateComparisonWidget($widgetId, $data);
            case 'kpi':
                return $this->generateKPIWidget($widgetId, $data);
            case 'progress':
                return $this->generateProgressWidget($widgetId, $data);
            default:
                return $this->renderEmptyState('widget', 'Unknown widget type: ' . $type);
        }
    }
    
    /**
     * Generate unique chart ID
     */
    private function generateChartId(string $type): string {
        return $type . '_chart_' . (++$this->chartIdCounter) . '_' . uniqid();
    }
    
    /**
     * Build pie chart configuration with accessibility features
     */
    private function buildPieChartConfig(array $data, array $options): array {
        $config = [
            'type' => 'pie',
            'data' => [
                'labels' => $data['labels'],
                'datasets' => $this->processDatasets($data['datasets'], 'pie')
            ],
            'options' => array_merge([
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => [
                        'position' => 'bottom',
                        'labels' => [
                            'usePointStyle' => true,
                            'padding' => 20
                        ]
                    ],
                    'tooltip' => [
                        'callbacks' => [
                            'label' => 'function(context) { 
                                var total = context.dataset.data.reduce((a,b) => a+b, 0);
                                var percentage = Math.round((context.parsed / total) * 100);
                                return context.label + ": " + context.parsed + " (" + percentage + "%)";
                            }'
                        ]
                    ]
                ],
                'accessibility' => [
                    'enabled' => true,
                    'description' => $options['title'] ?? 'Pie chart visualization'
                ]
            ], $options['chartOptions'] ?? [])
        ];
        
        return $config;
    }
    
    /**
     * Build bar chart configuration with accessibility features
     */
    private function buildBarChartConfig(array $data, array $options): array {
        $config = [
            'type' => $options['horizontal'] ?? false ? 'horizontalBar' : 'bar',
            'data' => [
                'labels' => $data['labels'],
                'datasets' => $this->processDatasets($data['datasets'], 'bar')
            ],
            'options' => array_merge([
                'responsive' => true,
                'maintainAspectRatio' => false,
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'grid' => [
                            'display' => true
                        ]
                    ],
                    'x' => [
                        'grid' => [
                            'display' => false
                        ]
                    ]
                ],
                'plugins' => [
                    'legend' => [
                        'position' => 'top',
                        'labels' => [
                            'usePointStyle' => true,
                            'padding' => 20
                        ]
                    ],
                    'tooltip' => [
                        'mode' => 'index',
                        'intersect' => false
                    ]
                ],
                'accessibility' => [
                    'enabled' => true,
                    'description' => $options['title'] ?? 'Bar chart visualization'
                ]
            ], $options['chartOptions'] ?? [])
        ];
        
        return $config;
    }
    
    /**
     * Build line chart configuration with accessibility features
     */
    private function buildLineChartConfig(array $data, array $options): array {
        $config = [
            'type' => 'line',
            'data' => [
                'labels' => $data['labels'],
                'datasets' => $this->processDatasets($data['datasets'], 'line')
            ],
            'options' => array_merge([
                'responsive' => true,
                'maintainAspectRatio' => false,
                'interaction' => [
                    'mode' => 'index',
                    'intersect' => false
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'grid' => [
                            'display' => true
                        ]
                    ],
                    'x' => [
                        'grid' => [
                            'display' => true
                        ]
                    ]
                ],
                'plugins' => [
                    'legend' => [
                        'position' => 'top',
                        'labels' => [
                            'usePointStyle' => true,
                            'padding' => 20
                        ]
                    ],
                    'tooltip' => [
                        'mode' => 'index',
                        'intersect' => false
                    ]
                ],
                'accessibility' => [
                    'enabled' => true,
                    'description' => $options['title'] ?? 'Line chart visualization'
                ]
            ], $options['chartOptions'] ?? [])
        ];
        
        return $config;
    }
    
    /**
     * Process datasets with accessibility-compliant colors
     */
    private function processDatasets(array $datasets, string $chartType): array {
        $processedDatasets = [];
        $colorPalette = $this->getAccessibleColorPalette();
        
        foreach ($datasets as $index => $dataset) {
            $processedDataset = $dataset;
            
            // Apply accessible colors if not specified
            if (!isset($dataset['backgroundColor'])) {
                if ($chartType === 'pie') {
                    $processedDataset['backgroundColor'] = array_slice($colorPalette, 0, count($dataset['data']));
                } else {
                    $processedDataset['backgroundColor'] = $colorPalette[$index % count($colorPalette)];
                    $processedDataset['borderColor'] = $colorPalette[$index % count($colorPalette)];
                    $processedDataset['borderWidth'] = 2;
                }
            }
            
            // Add accessibility features for line charts
            if ($chartType === 'line') {
                $processedDataset['fill'] = false;
                $processedDataset['tension'] = 0.1;
            }
            
            $processedDatasets[] = $processedDataset;
        }
        
        return $processedDatasets;
    }
    
    /**
     * Get color palette that meets accessibility standards
     */
    private function getAccessibleColorPalette(): array {
        return [
            '#2563eb', // Blue - WCAG AA compliant
            '#dc2626', // Red - WCAG AA compliant
            '#16a34a', // Green - WCAG AA compliant
            '#ca8a04', // Yellow/Gold - WCAG AA compliant
            '#9333ea', // Purple - WCAG AA compliant
            '#ea580c', // Orange - WCAG AA compliant
            '#0891b2', // Cyan - WCAG AA compliant
            '#be185d', // Pink - WCAG AA compliant
        ];
    }
    
    /**
     * Generate Chart.js HTML with canvas and script
     */
    private function generateChartHTML(string $chartId, array $config, string $type): string {
        $title = $config['options']['plugins']['title']['text'] ?? '';
        $description = $config['options']['accessibility']['description'] ?? '';
        
        $html = '<div class="chart-container" style="position: relative; height: 400px; margin: 20px 0;">';
        $html .= '<canvas id="' . $chartId . '" role="img" aria-label="' . htmlspecialchars($description) . '"';
        
        // Add screen reader accessible data
        $html .= ' aria-describedby="' . $chartId . '_description">';
        $html .= '</canvas>';
        
        // Add hidden description for screen readers
        $html .= '<div id="' . $chartId . '_description" class="sr-only">';
        $html .= htmlspecialchars($description);
        if (!empty($config['data']['labels']) && !empty($config['data']['datasets'])) {
            $html .= ' Data includes: ' . implode(', ', $config['data']['labels']);
        }
        $html .= '</div>';
        
        $html .= '</div>';
        
        // Add Chart.js initialization script
        $configJson = json_encode($config, JSON_UNESCAPED_SLASHES);
        
        // Convert quoted "function(...) { ... }" strings back to actual JS functions
        $configJson = preg_replace('/"function\s*\(([^)]*)\)\s*\{([\s\S]*?)\}"/', 'function($1){$2}', $configJson);
        
        $html .= '<script>';
        $html .= 'document.addEventListener("DOMContentLoaded", function() {';
        $html .= '  if (typeof Chart !== "undefined") {';
        $html .= '    var ctx = document.getElementById("' . $chartId . '").getContext("2d");';
        $html .= '    new Chart(ctx, ' . $configJson . ');';
        $html .= '  } else {';
        $html .= '    console.error("Chart.js library not loaded");';
        $html .= '    document.getElementById("' . $chartId . '").parentElement.innerHTML = ';
        $html .= '      "<div class=\"alert alert-warning\">Chart.js library required for visualization</div>";';
        $html .= '  }';
        $html .= '});';
        $html .= '</script>';
        
        return $html;
    }
    
    /**
     * Generate responsive HTML table
     */
    private function generateTableHTML(string $tableId, array $data, array $columns): string {
        $html = '<div class="table-responsive">';
        $html .= '<table id="' . $tableId . '" class="table table-striped table-hover" role="table">';
        
        // Table header
        $html .= '<thead class="table-dark">';
        $html .= '<tr>';
        foreach ($columns as $column) {
            $header = htmlspecialchars($column['header'] ?? $column['key'] ?? '');
            $sortable = $column['sortable'] ?? true ? 'data-sortable="true"' : '';
            $html .= '<th scope="col" ' . $sortable . '>' . $header . '</th>';
        }
        $html .= '</tr>';
        $html .= '</thead>';
        
        // Table body
        $html .= '<tbody>';
        foreach ($data as $rowIndex => $row) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $key = $column['key'] ?? '';
                $value = $row[$key] ?? '';
                
                // Apply formatting if specified
                if (isset($column['formatter']) && is_callable($column['formatter'])) {
                    $value = call_user_func($column['formatter'], $value, $row);
                }
                
                $html .= '<td>' . htmlspecialchars($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';
        
        $html .= '</table>';
        $html .= '</div>';
        
        // Add table enhancement script
        $html .= '<script>';
        $html .= 'document.addEventListener("DOMContentLoaded", function() {';
        $html .= '  // Add basic sorting functionality';
        $html .= '  var table = document.getElementById("' . $tableId . '");';
        $html .= '  if (table) {';
        $html .= '    var headers = table.querySelectorAll("th[data-sortable=\'true\']");';
        $html .= '    headers.forEach(function(header, index) {';
        $html .= '      header.style.cursor = "pointer";';
        $html .= '      header.addEventListener("click", function() {';
        $html .= '        sortTable(table, index);';
        $html .= '      });';
        $html .= '    });';
        $html .= '  }';
        $html .= '});';
        $html .= '</script>';
        
        return $html;
    }
    
    /**
     * Generate summary widget for dashboard
     */
    private function generateSummaryWidget(string $widgetId, array $data): string {
        $title = htmlspecialchars($data['title'] ?? 'Summary');
        $value = htmlspecialchars($data['value'] ?? '0');
        $description = htmlspecialchars($data['description'] ?? '');
        $trend = $data['trend'] ?? null;
        $icon = htmlspecialchars($data['icon'] ?? 'fas fa-chart-bar');
        
        $html = '<div class="dashboard-widget summary-widget" id="' . $widgetId . '">';
        $html .= '<div class="widget-header">';
        $html .= '<h3 class="widget-title"><i class="' . $icon . '"></i> ' . $title . '</h3>';
        $html .= '</div>';
        $html .= '<div class="widget-content">';
        $html .= '<div class="metric-value">' . $value . '</div>';
        if ($description) {
            $html .= '<div class="metric-description">' . $description . '</div>';
        }
        if ($trend) {
            $trendClass = $trend['direction'] === 'up' ? 'trend-up' : 'trend-down';
            $trendIcon = $trend['direction'] === 'up' ? 'fas fa-arrow-up' : 'fas fa-arrow-down';
            $html .= '<div class="metric-trend ' . $trendClass . '">';
            $html .= '<i class="' . $trendIcon . '"></i> ' . htmlspecialchars($trend['value'] ?? '');
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Generate chart widget for dashboard
     */
    private function generateChartWidget(string $widgetId, array $data): string {
        $title = htmlspecialchars($data['title'] ?? 'Chart');
        $chartType = $data['chartType'] ?? 'pie';
        $chartData = $data['chartData'] ?? [];
        $chartOptions = $data['chartOptions'] ?? [];
        
        $html = '<div class="dashboard-widget chart-widget" id="' . $widgetId . '">';
        $html .= '<div class="widget-header">';
        $html .= '<h3 class="widget-title">' . $title . '</h3>';
        $html .= '</div>';
        $html .= '<div class="widget-content">';
        
        // Render appropriate chart type
        switch ($chartType) {
            case 'pie':
                $html .= $this->renderPieChart($chartData, $chartOptions);
                break;
            case 'bar':
                $html .= $this->renderBarChart($chartData, $chartOptions);
                break;
            case 'line':
                $html .= $this->renderLineChart($chartData, $chartOptions);
                break;
            default:
                $html .= $this->renderEmptyState('chart', 'Unsupported chart type: ' . $chartType);
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Generate metric widget for dashboard
     */
    private function generateMetricWidget(string $widgetId, array $data): string {
        $title = htmlspecialchars($data['title'] ?? 'Metric');
        $metrics = $data['metrics'] ?? [];
        
        $html = '<div class="dashboard-widget metric-widget" id="' . $widgetId . '">';
        $html .= '<div class="widget-header">';
        $html .= '<h3 class="widget-title">' . $title . '</h3>';
        $html .= '</div>';
        $html .= '<div class="widget-content">';
        $html .= '<div class="metrics-grid">';
        
        foreach ($metrics as $metric) {
            $label = htmlspecialchars($metric['label'] ?? '');
            $value = htmlspecialchars($metric['value'] ?? '0');
            $unit = htmlspecialchars($metric['unit'] ?? '');
            
            $html .= '<div class="metric-item">';
            $html .= '<div class="metric-label">' . $label . '</div>';
            $html .= '<div class="metric-value">' . $value . ' ' . $unit . '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Generate analytics widget with drill-down capabilities
     */
    private function generateAnalyticsWidget(string $widgetId, array $data): string {
        $title = htmlspecialchars($data['title'] ?? 'Analytics');
        $reportType = htmlspecialchars($data['reportType'] ?? '');
        $summary = $data['summary'] ?? [];
        $drillDownUrl = htmlspecialchars($data['drillDownUrl'] ?? '');
        $icon = htmlspecialchars($data['icon'] ?? 'fas fa-analytics');
        
        $html = '<div class="dashboard-widget analytics-widget" id="' . $widgetId . '">';
        $html .= '<div class="widget-header">';
        $html .= '<h3 class="widget-title"><i class="' . $icon . '"></i> ' . $title . '</h3>';
        if ($drillDownUrl) {
            $html .= '<a href="' . $drillDownUrl . '" class="widget-action" title="View detailed report">';
            $html .= '<i class="fas fa-external-link-alt"></i>';
            $html .= '</a>';
        }
        $html .= '</div>';
        $html .= '<div class="widget-content">';
        
        // Summary metrics
        if (!empty($summary)) {
            $html .= '<div class="analytics-summary">';
            foreach ($summary as $metric) {
                $label = htmlspecialchars($metric['label'] ?? '');
                $value = htmlspecialchars($metric['value'] ?? '0');
                $change = $metric['change'] ?? null;
                
                $html .= '<div class="summary-metric">';
                $html .= '<div class="metric-label">' . $label . '</div>';
                $html .= '<div class="metric-value">' . $value . '</div>';
                if ($change) {
                    $changeClass = $change['direction'] === 'up' ? 'positive' : 'negative';
                    $changeIcon = $change['direction'] === 'up' ? 'fa-arrow-up' : 'fa-arrow-down';
                    $html .= '<div class="metric-change ' . $changeClass . '">';
                    $html .= '<i class="fas ' . $changeIcon . '"></i> ' . htmlspecialchars($change['value'] ?? '');
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        
        // Quick chart if provided
        if (isset($data['quickChart'])) {
            $html .= '<div class="quick-chart">';
            $html .= $this->renderPieChart($data['quickChart'], ['height' => 200]);
            $html .= '</div>';
        }
        
        // Action buttons
        if ($drillDownUrl) {
            $html .= '<div class="widget-actions">';
            $html .= '<a href="' . $drillDownUrl . '" class="btn btn-primary btn-sm">';
            $html .= '<i class="fas fa-chart-line"></i> View Details';
            $html .= '</a>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Generate trend widget showing data over time
     */
    private function generateTrendWidget(string $widgetId, array $data): string {
        $title = htmlspecialchars($data['title'] ?? 'Trend Analysis');
        $trendData = $data['trendData'] ?? [];
        $period = htmlspecialchars($data['period'] ?? 'Last 30 days');
        $icon = htmlspecialchars($data['icon'] ?? 'fas fa-chart-line');
        
        $html = '<div class="dashboard-widget trend-widget" id="' . $widgetId . '">';
        $html .= '<div class="widget-header">';
        $html .= '<h3 class="widget-title"><i class="' . $icon . '"></i> ' . $title . '</h3>';
        $html .= '<span class="widget-period">' . $period . '</span>';
        $html .= '</div>';
        $html .= '<div class="widget-content">';
        
        if (!empty($trendData)) {
            $html .= '<div class="trend-chart">';
            $html .= $this->renderLineChart($trendData, [
                'height' => 250,
                'chartOptions' => [
                    'scales' => [
                        'y' => ['beginAtZero' => true],
                        'x' => ['display' => true]
                    ]
                ]
            ]);
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Generate comparison widget for side-by-side metrics
     */
    private function generateComparisonWidget(string $widgetId, array $data): string {
        $title = htmlspecialchars($data['title'] ?? 'Comparison');
        $comparisons = $data['comparisons'] ?? [];
        $icon = htmlspecialchars($data['icon'] ?? 'fas fa-balance-scale');
        
        $html = '<div class="dashboard-widget comparison-widget" id="' . $widgetId . '">';
        $html .= '<div class="widget-header">';
        $html .= '<h3 class="widget-title"><i class="' . $icon . '"></i> ' . $title . '</h3>';
        $html .= '</div>';
        $html .= '<div class="widget-content">';
        $html .= '<div class="comparison-grid">';
        
        foreach ($comparisons as $comparison) {
            $label = htmlspecialchars($comparison['label'] ?? '');
            $current = htmlspecialchars($comparison['current'] ?? '0');
            $previous = htmlspecialchars($comparison['previous'] ?? '0');
            $change = $comparison['change'] ?? 0;
            
            $changeClass = $change > 0 ? 'positive' : ($change < 0 ? 'negative' : 'neutral');
            $changeIcon = $change > 0 ? 'fa-arrow-up' : ($change < 0 ? 'fa-arrow-down' : 'fa-minus');
            
            $html .= '<div class="comparison-item">';
            $html .= '<div class="comparison-label">' . $label . '</div>';
            $html .= '<div class="comparison-values">';
            $html .= '<div class="current-value">' . $current . '</div>';
            $html .= '<div class="comparison-change ' . $changeClass . '">';
            $html .= '<i class="fas ' . $changeIcon . '"></i> ';
            $html .= abs($change) . '%';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<div class="previous-value">Previous: ' . $previous . '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Generate KPI widget for key performance indicators
     */
    private function generateKPIWidget(string $widgetId, array $data): string {
        $title = htmlspecialchars($data['title'] ?? 'Key Performance Indicators');
        $kpis = $data['kpis'] ?? [];
        $icon = htmlspecialchars($data['icon'] ?? 'fas fa-tachometer-alt');
        
        $html = '<div class="dashboard-widget kpi-widget" id="' . $widgetId . '">';
        $html .= '<div class="widget-header">';
        $html .= '<h3 class="widget-title"><i class="' . $icon . '"></i> ' . $title . '</h3>';
        $html .= '</div>';
        $html .= '<div class="widget-content">';
        $html .= '<div class="kpi-grid">';
        
        foreach ($kpis as $kpi) {
            $label = htmlspecialchars($kpi['label'] ?? '');
            $value = htmlspecialchars($kpi['value'] ?? '0');
            $target = htmlspecialchars($kpi['target'] ?? '');
            $status = $kpi['status'] ?? 'neutral'; // good, warning, critical, neutral
            $unit = htmlspecialchars($kpi['unit'] ?? '');
            
            $statusClass = 'kpi-' . $status;
            $statusIcon = [
                'good' => 'fa-check-circle',
                'warning' => 'fa-exclamation-triangle',
                'critical' => 'fa-times-circle',
                'neutral' => 'fa-circle'
            ][$status] ?? 'fa-circle';
            
            $html .= '<div class="kpi-item ' . $statusClass . '">';
            $html .= '<div class="kpi-status"><i class="fas ' . $statusIcon . '"></i></div>';
            $html .= '<div class="kpi-label">' . $label . '</div>';
            $html .= '<div class="kpi-value">' . $value . ' ' . $unit . '</div>';
            if ($target) {
                $html .= '<div class="kpi-target">Target: ' . $target . ' ' . $unit . '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Generate progress widget showing completion status
     */
    private function generateProgressWidget(string $widgetId, array $data): string {
        $title = htmlspecialchars($data['title'] ?? 'Progress');
        $progress = $data['progress'] ?? [];
        $icon = htmlspecialchars($data['icon'] ?? 'fas fa-tasks');
        
        $html = '<div class="dashboard-widget progress-widget" id="' . $widgetId . '">';
        $html .= '<div class="widget-header">';
        $html .= '<h3 class="widget-title"><i class="' . $icon . '"></i> ' . $title . '</h3>';
        $html .= '</div>';
        $html .= '<div class="widget-content">';
        
        foreach ($progress as $item) {
            $label = htmlspecialchars($item['label'] ?? '');
            $percentage = max(0, min(100, intval($item['percentage'] ?? 0)));
            $status = $item['status'] ?? 'in-progress';
            $description = htmlspecialchars($item['description'] ?? '');
            
            $statusClass = 'progress-' . $status;
            
            $html .= '<div class="progress-item ' . $statusClass . '">';
            $html .= '<div class="progress-header">';
            $html .= '<span class="progress-label">' . $label . '</span>';
            $html .= '<span class="progress-percentage">' . $percentage . '%</span>';
            $html .= '</div>';
            $html .= '<div class="progress-bar-container">';
            $html .= '<div class="progress-bar" style="width: ' . $percentage . '%"></div>';
            $html .= '</div>';
            if ($description) {
                $html .= '<div class="progress-description">' . $description . '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Render empty state when no data is available
     */
    private function renderEmptyState(string $type, string $message): string {
        $html = '<div class="empty-state ' . $type . '-empty" role="alert">';
        $html .= '<div class="empty-state-icon">';
        $html .= '<i class="fas fa-chart-bar text-muted"></i>';
        $html .= '</div>';
        $html .= '<div class="empty-state-message">';
        $html .= '<h4>No Data Available</h4>';
        $html .= '<p>' . htmlspecialchars($message) . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Generate responsive dashboard grid layout
     */
    public function renderDashboardGrid(array $widgets, array $options = []): string {
        $gridClass = $options['gridClass'] ?? 'dashboard-grid';
        $columns = $options['columns'] ?? 'auto';
        
        $html = '<div class="' . $gridClass . '" data-columns="' . $columns . '">';
        
        foreach ($widgets as $widget) {
            $size = $widget['size'] ?? 'medium'; // small, medium, large, full
            $html .= '<div class="widget-container widget-' . $size . '">';
            $html .= $widget['content'] ?? '';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Generate drill-down navigation for widgets
     */
    public function generateDrillDownNavigation(array $breadcrumbs, string $currentPage): string {
        $html = '<nav class="drill-down-nav" aria-label="Analytics navigation">';
        $html .= '<ol class="breadcrumb">';
        
        foreach ($breadcrumbs as $index => $crumb) {
            $isActive = ($crumb['page'] === $currentPage);
            $html .= '<li class="breadcrumb-item' . ($isActive ? ' active' : '') . '">';
            
            if (!$isActive && isset($crumb['url'])) {
                $html .= '<a href="' . htmlspecialchars($crumb['url']) . '">';
                $html .= htmlspecialchars($crumb['title']);
                $html .= '</a>';
            } else {
                $html .= htmlspecialchars($crumb['title']);
            }
            
            $html .= '</li>';
        }
        
        $html .= '</ol>';
        $html .= '</nav>';
        
        return $html;
    }

    /**
     * Get CSS styles for visualization components
     */
    public function getVisualizationCSS(): string {
        return '
        <style>
        .chart-container {
            position: relative;
            margin: 20px 0;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        /* Dashboard Grid Layout */
        .dashboard-grid {
            display: grid;
            gap: 20px;
            margin: 20px 0;
        }
        
        .dashboard-grid[data-columns="1"] {
            grid-template-columns: 1fr;
        }
        
        .dashboard-grid[data-columns="2"] {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .dashboard-grid[data-columns="3"] {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .dashboard-grid[data-columns="4"] {
            grid-template-columns: repeat(4, 1fr);
        }
        
        .dashboard-grid[data-columns="auto"] {
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }
        
        /* Widget Container Sizes */
        .widget-container.widget-small {
            grid-column: span 1;
        }
        
        .widget-container.widget-medium {
            grid-column: span 1;
        }
        
        .widget-container.widget-large {
            grid-column: span 2;
        }
        
        .widget-container.widget-full {
            grid-column: 1 / -1;
        }
        
        .dashboard-widget {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .dashboard-widget:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .widget-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .widget-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
        }
        
        .widget-action {
            color: #6c757d;
            text-decoration: none;
            padding: 5px;
            border-radius: 4px;
            transition: color 0.2s ease;
        }
        
        .widget-action:hover {
            color: #2563eb;
            background: rgba(37, 99, 235, 0.1);
        }
        
        .widget-period {
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: normal;
        }
        
        .widget-content {
            padding: 20px;
        }
        
        /* Summary Widget */
        .summary-widget .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2563eb;
            margin: 10px 0;
        }
        
        .summary-widget .metric-description {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .metric-trend {
            margin-top: 10px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .trend-up {
            color: #16a34a;
        }
        
        .trend-down {
            color: #dc2626;
        }
        
        /* Analytics Widget */
        .analytics-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .summary-metric {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .summary-metric .metric-label {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .summary-metric .metric-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #495057;
            margin-bottom: 5px;
        }
        
        .metric-change {
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .metric-change.positive {
            color: #16a34a;
        }
        
        .metric-change.negative {
            color: #dc2626;
        }
        
        .quick-chart {
            margin: 15px 0;
        }
        
        .widget-actions {
            margin-top: 20px;
            text-align: center;
        }
        
        /* Comparison Widget */
        .comparison-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .comparison-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            text-align: center;
        }
        
        .comparison-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .comparison-values {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .current-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #495057;
        }
        
        .comparison-change {
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .comparison-change.positive {
            color: #16a34a;
        }
        
        .comparison-change.negative {
            color: #dc2626;
        }
        
        .comparison-change.neutral {
            color: #6c757d;
        }
        
        .previous-value {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        /* KPI Widget */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .kpi-item {
            padding: 15px;
            border-radius: 6px;
            text-align: center;
            border: 2px solid transparent;
        }
        
        .kpi-item.kpi-good {
            background: #dcfce7;
            border-color: #16a34a;
        }
        
        .kpi-item.kpi-warning {
            background: #fef9c3;
            border-color: #ca8a04;
        }
        
        .kpi-item.kpi-critical {
            background: #fee2e2;
            border-color: #dc2626;
        }
        
        .kpi-item.kpi-neutral {
            background: #f8f9fa;
            border-color: #dee2e6;
        }
        
        .kpi-status {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }
        
        .kpi-good .kpi-status {
            color: #16a34a;
        }
        
        .kpi-warning .kpi-status {
            color: #ca8a04;
        }
        
        .kpi-critical .kpi-status {
            color: #dc2626;
        }
        
        .kpi-neutral .kpi-status {
            color: #6c757d;
        }
        
        .kpi-label {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .kpi-value {
            font-size: 1.3rem;
            font-weight: bold;
            color: #495057;
            margin-bottom: 5px;
        }
        
        .kpi-target {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        /* Progress Widget */
        .progress-item {
            margin-bottom: 20px;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .progress-label {
            font-weight: 600;
            color: #495057;
        }
        
        .progress-percentage {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: #2563eb;
            transition: width 0.3s ease;
        }
        
        .progress-completed .progress-bar {
            background: #16a34a;
        }
        
        .progress-warning .progress-bar {
            background: #ca8a04;
        }
        
        .progress-critical .progress-bar {
            background: #dc2626;
        }
        
        .progress-description {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }
        
        /* Drill-down Navigation */
        .drill-down-nav {
            margin: 20px 0;
        }
        
        .breadcrumb {
            display: flex;
            flex-wrap: wrap;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            list-style: none;
            background-color: #f8f9fa;
            border-radius: 0.375rem;
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            content: "/";
            color: #6c757d;
            padding: 0 0.5rem;
        }
        
        .breadcrumb-item.active {
            color: #6c757d;
        }
        
        .breadcrumb-item a {
            color: #2563eb;
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            text-decoration: underline;
        }
        
        /* Metric Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }
        
        .metric-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .metric-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .metric-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #495057;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 20px;
        }
        
        .empty-state-message h4 {
            margin-bottom: 10px;
            color: #495057;
        }
        
        /* Table Responsive */
        .table-responsive {
            margin: 20px 0;
        }
        
        /* Screen Reader Only */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-grid[data-columns="4"] {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .widget-container.widget-large {
                grid-column: span 1;
            }
        }
        
        @media (max-width: 992px) {
            .dashboard-grid[data-columns="3"],
            .dashboard-grid[data-columns="4"] {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .analytics-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .comparison-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-grid,
            .dashboard-grid[data-columns="2"],
            .dashboard-grid[data-columns="3"],
            .dashboard-grid[data-columns="4"] {
                grid-template-columns: 1fr;
            }
            
            .widget-container.widget-large,
            .widget-container.widget-medium,
            .widget-container.widget-small {
                grid-column: span 1;
            }
            
            .chart-container {
                height: 300px;
                padding: 15px;
            }
            
            .widget-content {
                padding: 15px;
            }
            
            .analytics-summary,
            .metrics-grid,
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .comparison-values {
                flex-direction: column;
                gap: 5px;
            }
            
            .widget-header {
                padding: 12px 15px;
            }
            
            .widget-title {
                font-size: 1rem;
            }
            
            .summary-widget .metric-value {
                font-size: 2rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-grid {
                gap: 15px;
                margin: 15px 0;
            }
            
            .widget-content {
                padding: 12px;
            }
            
            .summary-widget .metric-value {
                font-size: 1.8rem;
            }
            
            .breadcrumb {
                padding: 0.5rem 0.75rem;
                font-size: 0.875rem;
            }
        }
        </style>';
    }
    
    /**
     * Get JavaScript utilities for table sorting and interactions
     */
    public function getVisualizationJS(): string {
        return '
        <script>
        function sortTable(table, columnIndex) {
            var tbody = table.querySelector("tbody");
            var rows = Array.from(tbody.querySelectorAll("tr"));
            var isAscending = table.getAttribute("data-sort-direction") !== "asc";
            
            rows.sort(function(a, b) {
                var aValue = a.cells[columnIndex].textContent.trim();
                var bValue = b.cells[columnIndex].textContent.trim();
                
                // Try to parse as numbers
                var aNum = parseFloat(aValue);
                var bNum = parseFloat(bValue);
                
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return isAscending ? aNum - bNum : bNum - aNum;
                } else {
                    return isAscending ? 
                        aValue.localeCompare(bValue) : 
                        bValue.localeCompare(aValue);
                }
            });
            
            // Clear tbody and append sorted rows
            tbody.innerHTML = "";
            rows.forEach(function(row) {
                tbody.appendChild(row);
            });
            
            // Update sort direction
            table.setAttribute("data-sort-direction", isAscending ? "asc" : "desc");
            
            // Update header indicators
            var headers = table.querySelectorAll("th");
            headers.forEach(function(header, index) {
                header.classList.remove("sort-asc", "sort-desc");
                if (index === columnIndex) {
                    header.classList.add(isAscending ? "sort-asc" : "sort-desc");
                }
            });
        }
        </script>';
    }

    /**
     * Render enhanced chart with additional interactivity
     */
    public function renderEnhancedChart(array $data, array $options = []): string {
        $type = $options['type'] ?? 'bar';
        switch ($type) {
            case 'pie': return $this->renderPieChart($data, $options);
            case 'line': return $this->renderLineChart($data, $options);
            default: return $this->renderBarChart($data, $options);
        }
    }

    /**
     * Render compliance breakdown visualization
     */
    public function renderComplianceBreakdown(array $data, array $options = []): string {
        $options['title'] = $options['title'] ?? 'Compliance Breakdown';
        return $this->renderPieChart($data, $options);
    }

    /**
     * Render compliance trends visualization
     */
    public function renderComplianceTrends(array $data, array $options = []): string {
        $options['title'] = $options['title'] ?? 'Compliance Trends';
        return $this->renderLineChart($data, $options);
    }

    /**
     * Render severity over time analysis
     */
    public function renderSeverityTimeAnalysis(array $data, array $options = []): string {
        $options['title'] = $options['title'] ?? 'Severity over Time';
        return $this->renderBarChart($data, $options);
    }

    /**
     * Render side-by-side comparison chart
     */
    public function renderComparisonChart(string $type, array $projectAnalytics, array $allProjects): string {
        $currentStats = $projectAnalytics['project_statistics'] ?? [];
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => 'Comparison Value',
                'data' => [],
                'backgroundColor' => []
            ]]
        ];
        
        $currentValue = 0;
        $benchmarkValue = 0;
        $title = 'Comparison';
        
        switch ($type) {
            case 'compliance':
                $currentValue = $currentStats['compliance_rate'] ?? 0;
                $benchmarkValue = 75; // Industry standard
                $title = 'Compliance Score (%)';
                break;
            case 'resolution':
                $total = $currentStats['total_issues'] ?? 0;
                $resolved = $currentStats['resolved_issues'] ?? 0;
                $currentValue = $total > 0 ? ($resolved / $total) * 100 : 0;
                $benchmarkValue = 65;
                $title = 'Resolution Rate (%)';
                break;
            case 'user_impact':
                $currentValue = $currentStats['avg_users_affected'] ?? 0;
                $benchmarkValue = 45;
                $title = 'Avg Users Affected';
                break;
            case 'critical_ratio':
                $total = $currentStats['total_issues'] ?? 0;
                $critical = $currentStats['critical_issues'] ?? 0;
                $currentValue = $total > 0 ? ($critical / $total) * 100 : 0;
                $benchmarkValue = 20;
                $title = 'Critical Issues Ratio (%)';
                break;
        }
        
        $data['labels'] = ['Current Project', 'Industry Benchmark', 'Portfolio Avg'];
        $data['datasets'][0]['data'] = [
            round($currentValue, 1),
            $benchmarkValue,
            round($benchmarkValue * 0.9, 1) // Mocked portfolio avg
        ];
        $data['datasets'][0]['backgroundColor'] = ['#2563eb', '#94a3b8', '#cbd5e1'];
        
        return $this->renderBarChart($data, ['title' => $title, 'displayLegend' => false]);
    }
}

/**
 * VisualizationInterface
 * 
 * Interface defining the contract for visualization rendering components
 */
interface VisualizationInterface {
    public function renderPieChart(array $data, array $options): string;
    public function renderBarChart(array $data, array $options): string;
    public function renderLineChart(array $data, array $options): string;
    public function renderTable(array $data, array $columns): string;
    public function renderDashboardWidget(string $type, array $data): string;
    public function renderComparisonChart(string $type, array $projectAnalytics, array $allProjects): string;
}