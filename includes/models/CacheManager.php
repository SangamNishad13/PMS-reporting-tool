<?php

/**
 * CacheManager Class
 * 
 * Provides Redis-based caching for analytics results with TTL management,
 * cache invalidation strategies, and performance optimization for large datasets.
 * 
 * Requirements: 18.1, 18.2, 18.4
 */

require_once __DIR__ . '/../../config/redis.php';

class CacheManager {
    
    private $redis;
    private $redisConfig;
    private $isRedisAvailable;
    
    // Cache TTL configurations based on data volatility (in seconds)
    const TTL_REAL_TIME = 300;      // 5 minutes - for frequently changing data
    const TTL_HOURLY = 3600;        // 1 hour - for moderately volatile data
    const TTL_DAILY = 86400;        // 24 hours - for stable data
    const TTL_WEEKLY = 604800;      // 7 days - for historical data
    
    // Cache key prefixes for different report types
    const PREFIX_USER_AFFECTED = 'analytics_user_affected';
    const PREFIX_WCAG_COMPLIANCE = 'analytics_wcag_compliance';
    const PREFIX_SEVERITY = 'analytics_severity';
    const PREFIX_COMMON_ISSUES = 'analytics_common_issues';
    const PREFIX_BLOCKER_ISSUES = 'analytics_blocker_issues';
    const PREFIX_PAGE_ISSUES = 'analytics_page_issues';
    const PREFIX_COMMENTED_ISSUES = 'analytics_commented_issues';
    const PREFIX_COMPLIANCE_TREND = 'analytics_compliance_trend';
    const PREFIX_UNIFIED_DASHBOARD = 'analytics_unified_dashboard';
    
    public function __construct() {
        $this->redisConfig = RedisConfig::getInstance();
        $this->isRedisAvailable = $this->redisConfig->isAvailable();
        
        if ($this->isRedisAvailable) {
            $this->redis = $this->redisConfig;
        }
    }
    
    /**
     * Check if Redis caching is available
     * 
     * @return bool
     */
    public function isAvailable(): bool {
        return $this->isRedisAvailable;
    }
    
    /**
     * Generate cache key for analytics reports
     * 
     * @param string $reportType Type of analytics report
     * @param array|int $projectIds Project ID(s) for the report
     * @param int|null $clientId Client user ID (optional)
     * @param array $additionalParams Additional parameters for cache key
     * @return string Generated cache key
     */
    public function generateCacheKey(string $reportType, $projectIds, ?int $clientId = null, array $additionalParams = []): string {
        $keyParts = [$this->getReportPrefix($reportType)];
        
        // Add project IDs
        if (is_array($projectIds)) {
            sort($projectIds); // Ensure consistent ordering
            $keyParts[] = 'projects_' . implode(',', $projectIds);
        } else {
            $keyParts[] = 'project_' . $projectIds;
        }
        
        // Add client ID if provided
        if ($clientId !== null) {
            $keyParts[] = 'client_' . $clientId;
        }
        
        // Add additional parameters
        foreach ($additionalParams as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $keyParts[] = $key . '_' . $value;
        }
        
        return implode(':', $keyParts);
    }
    
    /**
     * Get cached analytics report
     * 
     * @param string $cacheKey Cache key to retrieve
     * @return array|null Cached data or null if not found
     */
    public function getCachedReport(string $cacheKey): ?array {
        if (!$this->isAvailable()) {
            return null;
        }
        
        try {
            $cachedData = $this->redis->get($cacheKey);
            
            if ($cachedData !== null) {
                // Add cache hit metadata
                $cachedData['_cache_metadata'] = [
                    'cached_at' => time(),
                    'cache_key' => $cacheKey,
                    'cache_hit' => true
                ];
                
                return $cachedData;
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("CacheManager: Error retrieving cached report - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Cache analytics report with appropriate TTL
     * 
     * @param string $cacheKey Cache key to store under
     * @param array $reportData Report data to cache
     * @param string $reportType Type of report for TTL determination
     * @param int|null $customTTL Custom TTL override
     * @return bool Success status
     */
    public function cacheReport(string $cacheKey, array $reportData, string $reportType, ?int $customTTL = null): bool {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $ttl = $customTTL ?? $this->getTTLForReportType($reportType);
            
            // Add cache metadata
            $reportData['_cache_metadata'] = [
                'cached_at' => time(),
                'cache_key' => $cacheKey,
                'ttl' => $ttl,
                'expires_at' => time() + $ttl,
                'report_type' => $reportType
            ];
            
            return $this->redis->set($cacheKey, $reportData, $ttl);
            
        } catch (Exception $e) {
            error_log("CacheManager: Error caching report - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalidate cached data when underlying project issues are updated
     * 
     * @param array|int|null $projectIds Project IDs to invalidate (null for all)
     * @param int|null $clientId Specific client to invalidate (null for all)
     * @return bool Success status
     */
    public function invalidateProjectCache($projectIds = null, ?int $clientId = null): bool {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $patterns = [];
            
            if ($projectIds === null) {
                // Invalidate all analytics cache
                $patterns[] = 'analytics_*';
            } else {
                // Build patterns for specific projects
                $projectList = is_array($projectIds) ? $projectIds : [$projectIds];
                
                foreach ($this->getAllReportPrefixes() as $prefix) {
                    foreach ($projectList as $projectId) {
                        $patterns[] = $prefix . ':*project*' . $projectId . '*';
                        $patterns[] = $prefix . ':*projects*' . $projectId . '*';
                    }
                }
            }
            
            // Add client-specific patterns if specified
            if ($clientId !== null) {
                $clientPatterns = [];
                foreach ($patterns as $pattern) {
                    $clientPatterns[] = $pattern . '*client_' . $clientId . '*';
                }
                $patterns = array_merge($patterns, $clientPatterns);
            }
            
            $deletedCount = 0;
            foreach ($patterns as $pattern) {
                $keys = $this->getKeysByPattern($pattern);
                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        if ($this->redis->delete($key)) {
                            $deletedCount++;
                        }
                    }
                }
            }
            
            error_log("CacheManager: Invalidated {$deletedCount} cache entries for projects: " . 
                     (is_array($projectIds) ? implode(',', $projectIds) : $projectIds));
            
            return true;
            
        } catch (Exception $e) {
            error_log("CacheManager: Error invalidating project cache - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalidate cache for specific report type
     * 
     * @param string $reportType Report type to invalidate
     * @param array|int|null $projectIds Specific projects (null for all)
     * @return bool Success status
     */
    public function invalidateReportTypeCache(string $reportType, $projectIds = null): bool {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $prefix = $this->getReportPrefix($reportType);
            $pattern = $prefix . ':*';
            
            if ($projectIds !== null) {
                $projectList = is_array($projectIds) ? implode(',', $projectIds) : $projectIds;
                $pattern = $prefix . ':*project*' . $projectList . '*';
            }
            
            $keys = $this->getKeysByPattern($pattern);
            $deletedCount = 0;
            
            foreach ($keys as $key) {
                if ($this->redis->delete($key)) {
                    $deletedCount++;
                }
            }
            
            error_log("CacheManager: Invalidated {$deletedCount} cache entries for report type: {$reportType}");
            
            return true;
            
        } catch (Exception $e) {
            error_log("CacheManager: Error invalidating report type cache - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get cache statistics for monitoring
     * 
     * @return array Cache statistics
     */
    public function getCacheStats(): array {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'total_keys' => 0,
                'analytics_keys' => 0,
                'memory_usage' => 0
            ];
        }
        
        try {
            $allKeys = $this->getKeysByPattern('*');
            $analyticsKeys = $this->getKeysByPattern('analytics_*');
            
            return [
                'available' => true,
                'total_keys' => count($allKeys),
                'analytics_keys' => count($analyticsKeys),
                'memory_usage' => $this->getMemoryUsage(),
                'connection_status' => 'connected'
            ];
            
        } catch (Exception $e) {
            error_log("CacheManager: Error getting cache stats - " . $e->getMessage());
            return [
                'available' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Clear all analytics cache (use with caution)
     * 
     * @return bool Success status
     */
    public function clearAllAnalyticsCache(): bool {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $keys = $this->getKeysByPattern('analytics_*');
            $deletedCount = 0;
            
            foreach ($keys as $key) {
                if ($this->redis->delete($key)) {
                    $deletedCount++;
                }
            }
            
            error_log("CacheManager: Cleared {$deletedCount} analytics cache entries");
            
            return true;
            
        } catch (Exception $e) {
            error_log("CacheManager: Error clearing analytics cache - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get TTL for specific report type based on data volatility
     * 
     * @param string $reportType Report type
     * @return int TTL in seconds
     */
    private function getTTLForReportType(string $reportType): int {
        switch ($reportType) {
            case 'user_affected':
            case 'blocker_issues':
                return self::TTL_REAL_TIME; // 5 minutes - frequently changing
                
            case 'severity':
            case 'common_issues':
            case 'page_issues':
            case 'commented_issues':
                return self::TTL_HOURLY; // 1 hour - moderately volatile
                
            case 'wcag_compliance':
            case 'unified_dashboard':
                return self::TTL_DAILY; // 24 hours - stable data
                
            case 'compliance_trend':
                return self::TTL_WEEKLY; // 7 days - historical data
                
            default:
                return self::TTL_HOURLY; // Default to 1 hour
        }
    }
    
    /**
     * Get cache key prefix for report type
     * 
     * @param string $reportType Report type
     * @return string Cache key prefix
     */
    private function getReportPrefix(string $reportType): string {
        switch ($reportType) {
            case 'user_affected':
                return self::PREFIX_USER_AFFECTED;
            case 'wcag_compliance':
                return self::PREFIX_WCAG_COMPLIANCE;
            case 'severity':
                return self::PREFIX_SEVERITY;
            case 'common_issues':
                return self::PREFIX_COMMON_ISSUES;
            case 'blocker_issues':
                return self::PREFIX_BLOCKER_ISSUES;
            case 'page_issues':
                return self::PREFIX_PAGE_ISSUES;
            case 'commented_issues':
                return self::PREFIX_COMMENTED_ISSUES;
            case 'compliance_trend':
                return self::PREFIX_COMPLIANCE_TREND;
            case 'unified_dashboard':
                return self::PREFIX_UNIFIED_DASHBOARD;
            default:
                return 'analytics_' . $reportType;
        }
    }
    
    /**
     * Get all report prefixes
     * 
     * @return array All cache prefixes
     */
    private function getAllReportPrefixes(): array {
        return [
            self::PREFIX_USER_AFFECTED,
            self::PREFIX_WCAG_COMPLIANCE,
            self::PREFIX_SEVERITY,
            self::PREFIX_COMMON_ISSUES,
            self::PREFIX_BLOCKER_ISSUES,
            self::PREFIX_PAGE_ISSUES,
            self::PREFIX_COMMENTED_ISSUES,
            self::PREFIX_COMPLIANCE_TREND,
            self::PREFIX_UNIFIED_DASHBOARD
        ];
    }
    
    /**
     * Get keys matching a pattern (Redis KEYS command wrapper)
     * 
     * @param string $pattern Pattern to match
     * @return array Matching keys
     */
    private function getKeysByPattern(string $pattern): array {
        if (!$this->isAvailable()) {
            return [];
        }
        
        try {
            // Use Redis SCAN instead of KEYS for better performance
            $keys = [];
            $iterator = null;
            
            do {
                $result = $this->redis->scan($iterator, $pattern, 100);
                if ($result !== false) {
                    $keys = array_merge($keys, $result);
                }
            } while ($iterator > 0);
            
            return $keys;
            
        } catch (Exception $e) {
            error_log("CacheManager: Error getting keys by pattern - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get Redis memory usage information
     * 
     * @return array Memory usage stats
     */
    private function getMemoryUsage(): array {
        if (!$this->isAvailable()) {
            return ['used_memory' => 0, 'used_memory_human' => '0B'];
        }
        
        try {
            // This would require direct Redis connection, simplified for now
            return [
                'used_memory' => 0,
                'used_memory_human' => 'N/A'
            ];
            
        } catch (Exception $e) {
            error_log("CacheManager: Error getting memory usage - " . $e->getMessage());
            return ['used_memory' => 0, 'used_memory_human' => 'Error'];
        }
    }
}