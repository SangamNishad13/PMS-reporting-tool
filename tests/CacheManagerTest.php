<?php

require_once __DIR__ . '/../includes/models/CacheManager.php';

/**
 * Unit Tests for CacheManager Class
 * 
 * Tests Redis-based caching functionality, TTL management,
 * cache invalidation strategies, and error handling.
 */
class CacheManagerTest {
    
    private $cacheManager;
    private $testData;
    
    public function __construct() {
        $this->cacheManager = new CacheManager();
        $this->testData = [
            'report_type' => 'user_affected',
            'data' => [
                'total_users_affected' => 150,
                'categories' => [
                    '1-10' => 5,
                    '11-50' => 8,
                    '51-100' => 3,
                    '100+' => 2
                ]
            ],
            'generated_at' => time()
        ];
    }
    
    /**
     * Test cache key generation for different scenarios
     */
    public function testCacheKeyGeneration() {
        echo "Testing cache key generation...\n";
        
        // Test single project
        $key1 = $this->cacheManager->generateCacheKey('user_affected', 123);
        $expected1 = 'analytics_user_affected:project_123';
        assert($key1 === $expected1, "Single project key mismatch: {$key1} !== {$expected1}");
        
        // Test multiple projects
        $key2 = $this->cacheManager->generateCacheKey('wcag_compliance', [123, 456, 789]);
        $expected2 = 'analytics_wcag_compliance:projects_123,456,789';
        assert($key2 === $expected2, "Multiple projects key mismatch: {$key2} !== {$expected2}");
        
        // Test with client ID
        $key3 = $this->cacheManager->generateCacheKey('severity', 123, 456);
        $expected3 = 'analytics_severity:project_123:client_456';
        assert($key3 === $expected3, "Client key mismatch: {$key3} !== {$expected3}");
        
        // Test with additional parameters
        $key4 = $this->cacheManager->generateCacheKey('compliance_trend', [123], null, ['period' => 'monthly']);
        $expected4 = 'analytics_compliance_trend:projects_123:period_monthly';
        assert($key4 === $expected4, "Additional params key mismatch: {$key4} !== {$expected4}");
        
        echo "✓ Cache key generation tests passed\n";
    }
    
    /**
     * Test caching and retrieval functionality
     */
    public function testCacheOperations() {
        echo "Testing cache operations...\n";
        
        if (!$this->cacheManager->isAvailable()) {
            echo "⚠ Redis not available, skipping cache operation tests\n";
            return;
        }
        
        $cacheKey = 'test_cache_key_' . time();
        
        // Test caching
        $cacheResult = $this->cacheManager->cacheReport($cacheKey, $this->testData, 'user_affected');
        assert($cacheResult === true, "Failed to cache report");
        
        // Test retrieval
        $cachedData = $this->cacheManager->getCachedReport($cacheKey);
        assert($cachedData !== null, "Failed to retrieve cached report");
        assert($cachedData['report_type'] === 'user_affected', "Cached data mismatch");
        assert(isset($cachedData['_cache_metadata']), "Cache metadata missing");
        assert($cachedData['_cache_metadata']['cache_hit'] === true, "Cache hit flag missing");
        
        // Test non-existent key
        $nonExistentData = $this->cacheManager->getCachedReport('non_existent_key_' . time());
        assert($nonExistentData === null, "Non-existent key should return null");
        
        echo "✓ Cache operations tests passed\n";
    }
    
    /**
     * Test TTL (Time To Live) management for different report types
     */
    public function testTTLManagement() {
        echo "Testing TTL management...\n";
        
        if (!$this->cacheManager->isAvailable()) {
            echo "⚠ Redis not available, skipping TTL tests\n";
            return;
        }
        
        // Test different report types with their expected TTL behavior
        $reportTypes = [
            'user_affected' => CacheManager::TTL_REAL_TIME,
            'blocker_issues' => CacheManager::TTL_REAL_TIME,
            'severity' => CacheManager::TTL_HOURLY,
            'wcag_compliance' => CacheManager::TTL_DAILY,
            'compliance_trend' => CacheManager::TTL_WEEKLY
        ];
        
        foreach ($reportTypes as $reportType => $expectedTTL) {
            $cacheKey = 'test_ttl_' . $reportType . '_' . time();
            
            // Cache with default TTL
            $result = $this->cacheManager->cacheReport($cacheKey, $this->testData, $reportType);
            assert($result === true, "Failed to cache {$reportType} report");
            
            // Retrieve and check metadata
            $cachedData = $this->cacheManager->getCachedReport($cacheKey);
            assert($cachedData !== null, "Failed to retrieve {$reportType} report");
            assert(isset($cachedData['_cache_metadata']['ttl']), "TTL metadata missing for {$reportType}");
            assert($cachedData['_cache_metadata']['ttl'] === $expectedTTL, 
                   "TTL mismatch for {$reportType}: expected {$expectedTTL}, got {$cachedData['_cache_metadata']['ttl']}");
        }
        
        // Test custom TTL override
        $customTTL = 1800; // 30 minutes
        $cacheKey = 'test_custom_ttl_' . time();
        $result = $this->cacheManager->cacheReport($cacheKey, $this->testData, 'user_affected', $customTTL);
        assert($result === true, "Failed to cache with custom TTL");
        
        $cachedData = $this->cacheManager->getCachedReport($cacheKey);
        assert($cachedData['_cache_metadata']['ttl'] === $customTTL, "Custom TTL not applied");
        
        echo "✓ TTL management tests passed\n";
    }
    
    /**
     * Test cache invalidation strategies
     */
    public function testCacheInvalidation() {
        echo "Testing cache invalidation...\n";
        
        if (!$this->cacheManager->isAvailable()) {
            echo "⚠ Redis not available, skipping invalidation tests\n";
            return;
        }
        
        // Create test cache entries
        $projectIds = [123, 456];
        $clientId = 789;
        
        $testKeys = [];
        foreach (['user_affected', 'severity', 'wcag_compliance'] as $reportType) {
            foreach ($projectIds as $projectId) {
                $key = $this->cacheManager->generateCacheKey($reportType, $projectId, $clientId);
                $this->cacheManager->cacheReport($key, $this->testData, $reportType);
                $testKeys[] = $key;
            }
        }
        
        // Verify all keys are cached
        foreach ($testKeys as $key) {
            $data = $this->cacheManager->getCachedReport($key);
            assert($data !== null, "Test key {$key} should be cached");
        }
        
        // Test project-specific invalidation
        $result = $this->cacheManager->invalidateProjectCache([123]);
        assert($result === true, "Project invalidation should succeed");
        
        // Test report type invalidation
        $result = $this->cacheManager->invalidateReportTypeCache('user_affected');
        assert($result === true, "Report type invalidation should succeed");
        
        echo "✓ Cache invalidation tests passed\n";
    }
    
    /**
     * Test cache statistics and monitoring
     */
    public function testCacheStats() {
        echo "Testing cache statistics...\n";
        
        $stats = $this->cacheManager->getCacheStats();
        
        assert(is_array($stats), "Stats should be an array");
        assert(isset($stats['available']), "Stats should include availability");
        assert(isset($stats['total_keys']), "Stats should include total keys count");
        assert(isset($stats['analytics_keys']), "Stats should include analytics keys count");
        
        if ($this->cacheManager->isAvailable()) {
            assert($stats['available'] === true, "Stats should show Redis as available");
            assert(is_int($stats['total_keys']), "Total keys should be integer");
            assert(is_int($stats['analytics_keys']), "Analytics keys should be integer");
        } else {
            assert($stats['available'] === false, "Stats should show Redis as unavailable");
        }
        
        echo "✓ Cache statistics tests passed\n";
    }
    
    /**
     * Test error handling and fallback behavior
     */
    public function testErrorHandling() {
        echo "Testing error handling...\n";
        
        // Test behavior when Redis is unavailable
        if (!$this->cacheManager->isAvailable()) {
            // Test graceful degradation
            $key = 'test_key';
            $result = $this->cacheManager->cacheReport($key, $this->testData, 'user_affected');
            assert($result === false, "Cache operation should fail gracefully when Redis unavailable");
            
            $data = $this->cacheManager->getCachedReport($key);
            assert($data === null, "Cache retrieval should return null when Redis unavailable");
            
            $invalidateResult = $this->cacheManager->invalidateProjectCache([123]);
            assert($invalidateResult === false, "Invalidation should fail gracefully when Redis unavailable");
        }
        
        // Test invalid cache key handling
        $invalidKey = '';
        $data = $this->cacheManager->getCachedReport($invalidKey);
        assert($data === null, "Invalid cache key should return null");
        
        echo "✓ Error handling tests passed\n";
    }
    
    /**
     * Test integration with existing analytics patterns
     */
    public function testAnalyticsIntegration() {
        echo "Testing analytics integration patterns...\n";
        
        // Test cache key generation for unified dashboard
        $projectIds = [123, 456, 789];
        $clientId = 101;
        
        $dashboardKey = $this->cacheManager->generateCacheKey('unified_dashboard', $projectIds, $clientId);
        $expectedPattern = 'analytics_unified_dashboard:projects_123,456,789:client_101';
        assert($dashboardKey === $expectedPattern, "Dashboard cache key pattern mismatch");
        
        // Test cache key consistency (same inputs should generate same key)
        $key1 = $this->cacheManager->generateCacheKey('user_affected', [789, 123, 456], $clientId);
        $key2 = $this->cacheManager->generateCacheKey('user_affected', [123, 456, 789], $clientId);
        assert($key1 === $key2, "Cache keys should be consistent regardless of project order");
        
        // Test metadata structure
        if ($this->cacheManager->isAvailable()) {
            $testKey = 'integration_test_' . time();
            $this->cacheManager->cacheReport($testKey, $this->testData, 'user_affected');
            
            $cachedData = $this->cacheManager->getCachedReport($testKey);
            $metadata = $cachedData['_cache_metadata'];
            
            assert(isset($metadata['cached_at']), "Cache metadata should include cached_at timestamp");
            assert(isset($metadata['cache_key']), "Cache metadata should include cache_key");
            assert(isset($metadata['ttl']), "Cache metadata should include TTL");
            assert(isset($metadata['expires_at']), "Cache metadata should include expires_at");
            assert(isset($metadata['report_type']), "Cache metadata should include report_type");
            assert($metadata['cache_hit'] === true, "Cache metadata should indicate cache hit");
        }
        
        echo "✓ Analytics integration tests passed\n";
    }
    
    /**
     * Run all tests
     */
    public function runAllTests() {
        echo "=== CacheManager Test Suite ===\n\n";
        
        try {
            $this->testCacheKeyGeneration();
            $this->testCacheOperations();
            $this->testTTLManagement();
            $this->testCacheInvalidation();
            $this->testCacheStats();
            $this->testErrorHandling();
            $this->testAnalyticsIntegration();
            
            echo "\n✅ All CacheManager tests passed successfully!\n";
            
            // Display Redis availability status
            if ($this->cacheManager->isAvailable()) {
                echo "📊 Redis is available and functional\n";
                $stats = $this->cacheManager->getCacheStats();
                echo "📈 Cache Stats: {$stats['analytics_keys']} analytics keys, {$stats['total_keys']} total keys\n";
            } else {
                echo "⚠️  Redis is not available - caching will be disabled\n";
            }
            
        } catch (Exception $e) {
            echo "\n❌ Test failed: " . $e->getMessage() . "\n";
            echo "Stack trace: " . $e->getTraceAsString() . "\n";
        } catch (AssertionError $e) {
            echo "\n❌ Assertion failed: " . $e->getMessage() . "\n";
            echo "Stack trace: " . $e->getTraceAsString() . "\n";
        }
    }
}

// Run tests if this file is executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $tester = new CacheManagerTest();
    $tester->runAllTests();
}