<?php

/**
 * AnalyticsReport - Model for analytics report storage and management
 * 
 * Handles report structure with caching and metadata:
 * - Report validation and serialization
 * - Database storage and retrieval
 * - Chart configuration management
 * - Export functionality
 */
class AnalyticsReport {
    
    private $data;
    private $type;
    private $title;
    private $description;
    private $metadata;
    private $visualizationConfig;
    
    public function __construct($data = []) {
        $this->data = $data['data'] ?? [];
        $this->type = $data['type'] ?? 'unknown';
        $this->title = $data['title'] ?? 'Analytics Report';
        $this->description = $data['description'] ?? '';
        $this->metadata = $data['metadata'] ?? [];
        $this->visualizationConfig = $data['visualization_config'] ?? [];
    }
    
    public function getType() {
        return $this->type;
    }
    
    public function getTitle() {
        return $this->title;
    }
    
    public function getDescription() {
        return $this->description;
    }
    
    public function getData() {
        return $this->data;
    }
    
    public function getMetadata() {
        return $this->metadata;
    }
    
    public function getVisualizationConfig() {
        return $this->visualizationConfig;
    }
    
    public function toArray() {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'data' => $this->data,
            'metadata' => $this->metadata,
            'visualization_config' => $this->visualizationConfig
        ];
    }
    
    public function toJson() {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
    
    public function isValid() {
        return !empty($this->type) && !empty($this->data);
    }
    
    /**
     * Get top issues from the report data
     * Used by UnifiedDashboardController for common issues display
     */
    public function getTopIssues($limit = 5) {
        if (!isset($this->data['issues']) || !is_array($this->data['issues'])) {
            return [];
        }
        
        // Sort issues by frequency/count if available
        $issues = $this->data['issues'];
        if (isset($issues[0]['count'])) {
            usort($issues, function($a, $b) {
                return ($b['count'] ?? 0) - ($a['count'] ?? 0);
            });
        }
        
        return array_slice($issues, 0, $limit);
    }
    
    /**
     * Factory method to create a new report instance
     * 
     * @param string $type
     * @param array $projectIds
     * @param int $clientId
     * @param array $data
     * @return AnalyticsReport
     */
    public static function create($type, $projectIds, $clientId, $data) {
        return new self([
            'type' => $type,
            'data' => $data,
            'metadata' => [
                'project_ids' => $projectIds,
                'client_id' => $clientId,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ]);
    }

    /**
     * Save the report to the database
     * 
     * @return bool Success status
     * @throws Exception If database error occurs
     */
    public function save() {
        require_once __DIR__ . '/../../config/database.php';
        try {
            $db = Database::getInstance();
            
            $projectIds = $this->metadata['project_ids'] ?? [];
            $userId = $this->metadata['client_id'] ?? 0;
            
            // Generate a unique cache key based on report parameters and current time
            $cacheKey = md5($this->type . implode(',', $projectIds) . $userId . microtime());
            
            $sql = "INSERT INTO analytics_reports (
                        report_type, project_ids, generated_by_user_id, 
                        report_data, chart_config, cache_key, expires_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new Exception("Failed to prepare analytics report save statement");
            }
            
            // Expiration time: 24 hours from now
            $expiresAt = date('Y-m-d H:i:s', time() + 86400);
            
            return $stmt->execute([
                $this->type,
                json_encode($projectIds),
                $userId,
                json_encode($this->data),
                json_encode($this->visualizationConfig),
                $cacheKey,
                $expiresAt
            ]);
        } catch (Exception $e) {
            error_log("Failed to save analytics report: " . $e->getMessage());
            return false;
        }
    }
}