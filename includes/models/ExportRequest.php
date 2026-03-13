<?php

require_once __DIR__ . '/../../config/database.php';

/**
 * ExportRequest - Handle export queuing and status tracking
 * 
 * Provides comprehensive export request management:
 * - Export request creation and validation
 * - Status tracking throughout export lifecycle
 * - Background processing support for large exports
 * - Secure file handling and cleanup
 * - Integration with ExportEngine base class
 * 
 * Requirements: 14.5, 15.5, 18.3
 */
class ExportRequest {
    
    private $db;
    private $exportDir;
    private $maxFileAge;
    private $allowedFormats;
    private $allowedReportTypes;
    private $maxFileSize;
    
    // Export status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    
    // Export type constants
    const TYPE_PDF = 'pdf';
    const TYPE_EXCEL = 'excel';
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->initializeConfiguration();
        $this->initializeExportDirectory();
    }
    
    /**
     * Initialize configuration settings
     */
    private function initializeConfiguration() {
        $this->exportDir = __DIR__ . '/../../tmp/exports/';
        $this->maxFileAge = 24 * 3600; // 24 hours
        $this->maxFileSize = 100 * 1024 * 1024; // 100MB
        
        $this->allowedFormats = [
            self::TYPE_PDF,
            self::TYPE_EXCEL
        ];
        
        $this->allowedReportTypes = [
            'user_affected',
            'wcag_compliance',
            'severity_analysis',
            'common_issues',
            'blocker_issues',
            'page_issues',
            'commented_issues',
            'compliance_trend',
            'unified_dashboard'
        ];
    }
    
    /**
     * Initialize export directory with security
     */
    private function initializeExportDirectory() {
        if (!is_dir($this->exportDir)) {
            if (!mkdir($this->exportDir, 0750, true)) {
                throw new Exception('Failed to create export directory');
            }
        }
        
        // Create .htaccess for security
        $htaccessPath = $this->exportDir . '.htaccess';
        if (!file_exists($htaccessPath)) {
            $htaccessContent = "# Deny direct access to export files\n";
            $htaccessContent .= "Deny from all\n";
            $htaccessContent .= "Options -Indexes\n";
            file_put_contents($htaccessPath, $htaccessContent);
        }
        
        // Create index.php to prevent directory listing
        $indexPath = $this->exportDir . 'index.php';
        if (!file_exists($indexPath)) {
            file_put_contents($indexPath, "<?php\n// Access denied\nheader('HTTP/1.0 403 Forbidden');\nexit;\n");
        }
    }
    
    /**
     * Create a new export request
     * 
     * @param int $userId User requesting the export
     * @param string $exportType Type of export (pdf/excel)
     * @param string $reportType Type of analytics report
     * @param array $projectIds Array of project IDs
     * @param array $options Export configuration options
     * @return int Export request ID
     * @throws Exception If validation fails
     */
    public function createExportRequest($userId, $exportType, $reportType, $projectIds, $options = []) {
        // Validate the export request
        $this->validateExportRequest($userId, $exportType, $reportType, $projectIds, $options);
        
        // Calculate expiration time (24 hours from now)
        $expiresAt = date('Y-m-d H:i:s', time() + $this->maxFileAge);
        
        // Prepare the SQL statement
        $sql = "INSERT INTO export_requests (
            user_id, export_type, report_type, project_ids, 
            export_options, status, expires_at, requested_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare export request statement');
        }
        
        // Encode arrays as JSON
        $projectIdsJson = json_encode($projectIds);
        $optionsJson = json_encode($options);
        
        if (!$stmt->execute([
            $userId,
            $exportType,
            $reportType,
            $projectIdsJson,
            $optionsJson,
            self::STATUS_PENDING,
            $expiresAt
        ])) {
            throw new Exception('Failed to create export request');
        }
        
        $requestId = $this->db->lastInsertId();
        
        // Log the export request creation
        $this->logExportActivity($requestId, 'created', "Export request created for user $userId");
        
        return $requestId;
    }
    
    /**
     * Update export request status
     * 
     * @param int $requestId Export request ID
     * @param string $status New status
     * @param string $filePath Path to generated file (optional)
     * @param string $errorMessage Error message if failed (optional)
     * @throws Exception If update fails
     */
    public function updateExportStatus($requestId, $status, $filePath = null, $errorMessage = null) {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_FAILED])) {
            throw new Exception('Invalid export status: ' . $status);
        }
        
        $sql = "UPDATE export_requests SET status = ?, updated_at = NOW()";
        $params = [$status];
        
        // Add completion timestamp for completed/failed status
        if ($status === self::STATUS_COMPLETED || $status === self::STATUS_FAILED) {
            $sql .= ", completed_at = NOW()";
        }
        
        // Add file path if provided
        if ($filePath !== null) {
            $sql .= ", file_path = ?";
            $params[] = $filePath;
            
            // Add file size if file exists
            if (file_exists($filePath)) {
                $fileSize = filesize($filePath);
                $sql .= ", file_size = ?";
                $params[] = $fileSize;
            }
        }
        
        // Add error message if provided
        if ($errorMessage !== null) {
            $sql .= ", error_message = ?";
            $params[] = $errorMessage;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $requestId;
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare status update statement');
        }
        
        if (!$stmt->execute($params)) {
            throw new Exception('Failed to update export status');
        }
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Export request not found: ' . $requestId);
        }
        
        // Log the status update
        $this->logExportActivity($requestId, 'status_updated', "Status updated to: $status");
    }
    
    /**
     * Get export request by ID
     * 
     * @param int $requestId Export request ID
     * @return array|null Export request data or null if not found
     */
    public function getExportRequest($requestId) {
        $sql = "SELECT * FROM export_requests WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Failed to prepare get request statement');
        }
        
        if (!$stmt->execute([$requestId])) {
            throw new Exception('Failed to get export request');
        }
        
        $request = $stmt->fetch();
        
        if ($request) {
            // Decode JSON fields
            $request['project_ids'] = json_decode($request['project_ids'], true);
            $request['export_options'] = json_decode($request['export_options'], true);
        }
        
        return $request ?: null;
    }
    
    /**
     * Get export requests for a user
     * 
     * @param int $userId User ID
     * @param int $limit Maximum number of requests to return
     * @param int $offset Offset for pagination
     * @return array Array of export requests
     */
    public function getUserExportRequests($userId, $limit = 50, $offset = 0) {
        $sql = "SELECT * FROM export_requests 
                WHERE user_id = ? 
                ORDER BY requested_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare user requests statement');
        }
        
        if (!$stmt->execute([$userId, $limit, $offset])) {
            throw new Exception('Failed to get user export requests');
        }
        
        $requests = [];
        while ($row = $stmt->fetch()) {
            // Decode JSON fields
            $row['project_ids'] = json_decode($row['project_ids'], true);
            $row['export_options'] = json_decode($row['export_options'], true);
            $requests[] = $row;
        }
        
        return $requests;
    }
    
    /**
     * Get pending export requests for background processing
     * 
     * @param int $limit Maximum number of requests to return
     * @return array Array of pending export requests
     */
    public function getPendingExportRequests($limit = 10) {
        $sql = "SELECT * FROM export_requests 
                WHERE status = ? 
                ORDER BY requested_at ASC 
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare pending requests statement');
        }
        
        if (!$stmt->execute([self::STATUS_PENDING, $limit])) {
            throw new Exception('Failed to get pending export requests');
        }
        
        $requests = [];
        while ($row = $stmt->fetch()) {
            // Decode JSON fields
            $row['project_ids'] = json_decode($row['project_ids'], true);
            $row['export_options'] = json_decode($row['export_options'], true);
            $requests[] = $row;
        }
        
        return $requests;
    }
    
    /**
     * Check if export request belongs to user
     * 
     * @param int $requestId Export request ID
     * @param int $userId User ID
     * @return bool True if request belongs to user
     */
    public function isUserExportRequest($requestId, $userId) {
        $sql = "SELECT COUNT(*) as count FROM export_requests WHERE id = ? AND user_id = ?";
        $stmt = $this->db->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Failed to prepare ownership check statement');
        }
        
        if (!$stmt->execute([$requestId, $userId])) {
            throw new Exception('Failed to check export request ownership');
        }
        
        $row = $stmt->fetch();
        
        return $row['count'] > 0;
    }
    
    /**
     * Clean up expired export files and requests
     * 
     * @return int Number of files cleaned up
     */
    public function cleanupExpiredExports() {
        $cleanedCount = 0;
        
        // Get expired export requests
        $sql = "SELECT id, file_path FROM export_requests 
                WHERE expires_at < NOW() AND file_path IS NOT NULL";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt || !$stmt->execute()) {
            throw new Exception('Failed to get expired exports');
        }
        
        while ($row = $stmt->fetch()) {
            $filePath = $row['file_path'];
            
            // Delete the file if it exists
            if (file_exists($filePath)) {
                if (unlink($filePath)) {
                    $cleanedCount++;
                    $this->logExportActivity($row['id'], 'file_deleted', "Expired file deleted: $filePath");
                }
            }
            
            // Update the database record
            $updateSql = "UPDATE export_requests SET file_path = NULL, file_size = NULL WHERE id = ?";
            $updateStmt = $this->db->prepare($updateSql);
            if ($updateStmt) {
                $updateStmt->execute([$row['id']]);
            }
        }
        
        // Delete old export request records (older than 30 days)
        $deleteSql = "DELETE FROM export_requests WHERE requested_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $this->db->exec($deleteSql);
        
        return $cleanedCount;
    }
    
    /**
     * Generate secure filename for export
     * 
     * @param string $reportType Type of report
     * @param array $projectIds Project IDs
     * @param int $requestId Request ID
     * @param string $extension File extension
     * @return string Secure filename
     */
    public function generateSecureFilename($reportType, $projectIds, $requestId, $extension) {
        $timestamp = date('Y-m-d_H-i-s');
        $projectsHash = substr(md5(implode(',', $projectIds)), 0, 8);
        $randomSuffix = bin2hex(random_bytes(4));
        
        return sprintf(
            '%s_%s_%s_%d_%s.%s',
            $reportType,
            $projectsHash,
            $timestamp,
            $requestId,
            $randomSuffix,
            $extension
        );
    }
    
    /**
     * Get full file path for export
     * 
     * @param string $filename Filename
     * @return string Full file path
     */
    public function getExportFilePath($filename) {
        return $this->exportDir . $filename;
    }
    
    /**
     * Validate export request parameters
     * 
     * @param int $userId User ID
     * @param string $exportType Export type
     * @param string $reportType Report type
     * @param array $projectIds Project IDs
     * @param array $options Export options
     * @throws Exception If validation fails
     */
    private function validateExportRequest($userId, $exportType, $reportType, $projectIds, $options = []) {
        // Validate user ID
        if (!is_numeric($userId) || $userId <= 0) {
            throw new Exception('Invalid user ID provided');
        }
        
        // Validate export type
        if (!in_array($exportType, $this->allowedFormats)) {
            throw new Exception('Invalid export type. Allowed: ' . implode(', ', $this->allowedFormats));
        }
        
        // Validate report type
        if (!in_array($reportType, $this->allowedReportTypes)) {
            throw new Exception('Invalid report type. Allowed: ' . implode(', ', $this->allowedReportTypes));
        }
        
        // Validate project IDs
        if (!is_array($projectIds) || empty($projectIds)) {
            throw new Exception('At least one project ID is required');
        }
        
        foreach ($projectIds as $projectId) {
            if (!is_numeric($projectId) || $projectId <= 0) {
                throw new Exception('Invalid project ID: ' . $projectId);
            }
        }
        
        // Validate options if provided
        if (!empty($options) && !is_array($options)) {
            throw new Exception('Export options must be an array');
        }
        
        // Check for rate limiting (max 5 pending requests per user)
        $pendingCount = $this->getUserPendingRequestCount($userId);
        if ($pendingCount >= 5) {
            throw new Exception('Too many pending export requests. Please wait for current exports to complete.');
        }
    }
    
    /**
     * Get count of pending requests for a user
     * 
     * @param int $userId User ID
     * @return int Number of pending requests
     */
    private function getUserPendingRequestCount($userId) {
        $sql = "SELECT COUNT(*) as count FROM export_requests 
                WHERE user_id = ? AND status IN (?, ?)";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 0; // Fail gracefully
        }
        
        if (!$stmt->execute([$userId, self::STATUS_PENDING, self::STATUS_PROCESSING])) {
            return 0; // Fail gracefully
        }
        
        $row = $stmt->fetch();
        
        return (int)$row['count'];
    }
    
    /**
     * Log export activity for audit trail
     * 
     * @param int $requestId Export request ID
     * @param string $action Action performed
     * @param string $details Additional details
     */
    private function logExportActivity($requestId, $action, $details) {
        // Check if audit log table exists
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'client_audit_log'");
            if ($tableCheck && $tableCheck->rowCount() > 0) {
                $sql = "INSERT INTO client_audit_log (
                    user_id, action, resource_type, resource_id, 
                    details, ip_address, user_agent, created_at
                ) VALUES (?, ?, 'export_request', ?, ?, ?, ?, NOW())";
                
                $stmt = $this->db->prepare($sql);
                if ($stmt) {
                    $userId = $this->getCurrentUserId();
                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                    
                    $stmt->execute([$userId, $action, $requestId, $details, $ipAddress, $userAgent]);
                }
            }
        } catch (Exception $e) {
            // Fail silently for audit logging
        }
    }
    
    /**
     * Get current user ID from session
     * 
     * @return int User ID or 0 if not available
     */
    private function getCurrentUserId() {
        if (isset($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }
        return 0;
    }
    
    /**
     * Get export statistics for monitoring
     * 
     * @return array Export statistics
     */
    public function getExportStatistics() {
        $stats = [];
        
        try {
            // Total requests by status
            $sql = "SELECT status, COUNT(*) as count FROM export_requests GROUP BY status";
            $stmt = $this->db->prepare($sql);
            if ($stmt && $stmt->execute()) {
                while ($row = $stmt->fetch()) {
                    $stats['by_status'][$row['status']] = (int)$row['count'];
                }
            }
            
            // Total requests by type
            $sql = "SELECT export_type, COUNT(*) as count FROM export_requests GROUP BY export_type";
            $stmt = $this->db->prepare($sql);
            if ($stmt && $stmt->execute()) {
                while ($row = $stmt->fetch()) {
                    $stats['by_type'][$row['export_type']] = (int)$row['count'];
                }
            }
            
            // Recent activity (last 24 hours)
            $sql = "SELECT COUNT(*) as count FROM export_requests WHERE requested_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            $stmt = $this->db->prepare($sql);
            if ($stmt && $stmt->execute()) {
                $row = $stmt->fetch();
                $stats['recent_24h'] = (int)$row['count'];
            }
            
            // Average processing time for completed exports
            $sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, requested_at, completed_at)) as avg_time 
                    FROM export_requests 
                    WHERE status = 'completed' AND completed_at IS NOT NULL";
            $stmt = $this->db->prepare($sql);
            if ($stmt && $stmt->execute()) {
                $row = $stmt->fetch();
                $stats['avg_processing_time'] = round((float)$row['avg_time'], 2);
            }
        } catch (Exception $e) {
            // Return partial stats on error
        }
        
        return $stats;
    }
}