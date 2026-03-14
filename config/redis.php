<?php
/**
 * Redis Configuration for Client Reporting System
 * Handles caching for analytics reports and session management
 */

class RedisConfig {
    private static $instance = null;
    private $redis = null;
    
    private function __construct() {
        try {
            if (class_exists('Redis')) {
                $redisClass = 'Redis';
                $this->redis = new $redisClass();
                
                // Redis connection settings
                $host = $_ENV['REDIS_HOST'] ?? 'localhost';
                $port = $_ENV['REDIS_PORT'] ?? 6379;
                $password = $_ENV['REDIS_PASSWORD'] ?? null;
                $database = $_ENV['REDIS_DB'] ?? 0;
                
                $this->redis->connect($host, $port);
                
                if ($password) {
                    $this->redis->auth($password);
                }
                
                $this->redis->select($database);
                
                // Test connection
                $this->redis->ping();
                
            } else {
                error_log('Redis extension not available. Caching will be disabled.');
            }
        } catch (Exception $e) {
            error_log('Redis connection failed: ' . $e->getMessage());
            $this->redis = null;
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function isAvailable() {
        return $this->redis !== null;
    }
    
    public function get($key) {
        if (!$this->isAvailable()) {
            return null;
        }
        
        try {
            $value = $this->redis->get($key);
            return $value === false ? null : json_decode($value, true);
        } catch (Exception $e) {
            error_log('Redis get error: ' . $e->getMessage());
            return null;
        }
    }
    
    public function set($key, $value, $ttl = 3600) {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            return $this->redis->setex($key, $ttl, json_encode($value));
        } catch (Exception $e) {
            error_log('Redis set error: ' . $e->getMessage());
            return false;
        }
    }
    
    public function delete($key) {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            return $this->redis->del($key) > 0;
        } catch (Exception $e) {
            error_log('Redis delete error: ' . $e->getMessage());
            return false;
        }
    }
    
    public function exists($key) {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            return $this->redis->exists($key) > 0;
        } catch (Exception $e) {
            error_log('Redis exists error: ' . $e->getMessage());
            return false;
        }
    }
    
    public function flush() {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            return $this->redis->flushDB();
        } catch (Exception $e) {
            error_log('Redis flush error: ' . $e->getMessage());
            return false;
        }
    }

    public function keys($pattern) {
        if (!$this->isAvailable()) {
            return [];
        }
        try {
            return $this->redis->keys($pattern);
        } catch (Exception $e) {
            error_log('Redis keys error: ' . $e->getMessage());
            return [];
        }
    }

    public function scan(&$iterator, $pattern = null, $count = 0) {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            return $this->redis->scan($iterator, $pattern, $count);
        } catch (Exception $e) {
            error_log('Redis scan error: ' . $e->getMessage());
            return false;
        }
    }
    
    public function generateCacheKey($prefix, $params = []) {
        $keyParts = [$prefix];
        
        if (is_array($params)) {
            foreach ($params as $key => $value) {
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $keyParts[] = $key . ':' . $value;
            }
        }
        
        return implode('_', $keyParts);
    }
    
    // Analytics-specific cache methods
    public function getCachedAnalytics($reportType, $projectIds, $userId) {
        $cacheKey = $this->generateCacheKey('analytics', [
            'type' => $reportType,
            'projects' => is_array($projectIds) ? implode(',', $projectIds) : $projectIds,
            'user' => $userId
        ]);
        
        return $this->get($cacheKey);
    }
    
    public function setCachedAnalytics($reportType, $projectIds, $userId, $data, $ttl = 1800) {
        $cacheKey = $this->generateCacheKey('analytics', [
            'type' => $reportType,
            'projects' => is_array($projectIds) ? implode(',', $projectIds) : $projectIds,
            'user' => $userId
        ]);
        
        return $this->set($cacheKey, $data, $ttl);
    }
    
    public function invalidateAnalyticsCache($projectIds = null) {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            if ($projectIds === null) {
                // Invalidate all analytics cache
                $keys = $this->redis->keys('analytics_*');
            } else {
                // Invalidate cache for specific projects
                $projectList = is_array($projectIds) ? implode(',', $projectIds) : $projectIds;
                $keys = $this->redis->keys('analytics_*projects:' . $projectList . '*');
            }
            
            if (!empty($keys)) {
                return $this->redis->del($keys) > 0;
            }
            
            return true;
        } catch (Exception $e) {
            error_log('Redis cache invalidation error: ' . $e->getMessage());
            return false;
        }
    }
}