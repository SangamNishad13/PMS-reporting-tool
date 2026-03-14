<?php
/**
 * Production Cache Configuration
 * Requirement 18.1: Performance and Caching
 * 
 * This file provides production-specific caching strategies with appropriate TTL settings
 * for the Client Reporting and Analytics System.
 */

class ProductionCacheConfig {
    
    /**
     * Get cache configuration for production environment
     */
    public static function getConfig() {
        return [
            // Redis connection settings
            'redis' => [
                'host' => $_ENV['REDIS_HOST'] ?? 'localhost',
                'port' => $_ENV['REDIS_PORT'] ?? 6379,
                'password' => $_ENV['REDIS_PASSWORD'] ?? null,
                'database' => $_ENV['REDIS_DB'] ?? 0,
                'timeout' => 5.0,
                'read_timeout' => 10.0,
                'persistent' => true,
                'prefix' => 'pms_prod_',
                'serializer' => class_exists('Redis') ? (defined('Redis::SERIALIZER_JSON') ? constant('Redis::SERIALIZER_JSON') : 1) : null,
            ],
            
            // Cache TTL settings optimized for production
            'ttl' => [
                // Analytics reports - balanced between freshness and performance
                'analytics_user_affected' => 1800,      // 30 minutes
                'analytics_wcag_compliance' => 1800,    // 30 minutes
                'analytics_severity' => 1800,           // 30 minutes
                'analytics_common_issues' => 1800,      // 30 minutes
                'analytics_blocker_issues' => 900,      // 15 minutes (critical data)
                'analytics_page_issues' => 1800,        // 30 minutes
                'analytics_commented_issues' => 600,    // 10 minutes (frequently updated)
                'analytics_compliance_trend' => 3600,   // 1 hour (historical data)
                'analytics_unified_dashboard' => 1800,  // 30 minutes
                
                // Project and user data
                'project_assignments' => 3600,          // 1 hour
                'client_permissions' => 3600,           // 1 hour
                'project_metadata' => 7200,             // 2 hours
                'user_roles' => 14400,                  // 4 hours
                
                // System configuration
                'system_settings' => 14400,             // 4 hours
                'email_templates' => 7200,              // 2 hours
                
                // Session and authentication
                'user_sessions' => 1800,                // 30 minutes
                'auth_tokens' => 1800,                  // 30 minutes
                'failed_login_attempts' => 3600,       // 1 hour
                
                // Export and file operations
                'export_queue' => 300,                  // 5 minutes
                'file_metadata' => 3600,               // 1 hour
            ],
            
            // Cache warming strategies
            'warming' => [
                'enabled' => true,
                'schedule' => [
                    // Warm up analytics cache every 25 minutes (before expiry)
                    'analytics' => [
                        'interval' => 1500, // 25 minutes
                        'reports' => [
                            'analytics_user_affected',
                            'analytics_wcag_compliance',
                            'analytics_severity',
                            'analytics_unified_dashboard'
                        ]
                    ],
                    // Warm up project data every 55 minutes
                    'projects' => [
                        'interval' => 3300, // 55 minutes
                        'data' => [
                            'project_assignments',
                            'client_permissions'
                        ]
                    ]
                ]
            ],
            
            // Cache invalidation strategies
            'invalidation' => [
                'strategies' => [
                    'immediate' => [
                        'analytics_blocker_issues',
                        'analytics_commented_issues'
                    ],
                    'delayed' => [
                        'analytics_user_affected',
                        'analytics_wcag_compliance',
                        'analytics_severity',
                        'analytics_common_issues',
                        'analytics_page_issues',
                        'analytics_unified_dashboard'
                    ],
                    'batch' => [
                        'project_assignments',
                        'client_permissions',
                        'project_metadata'
                    ]
                ],
                'delay_time' => 60, // 1 minute delay for batch invalidation
                'batch_size' => 10,
            ],
            
            // Memory management
            'memory' => [
                'max_memory' => '256M',
                'warning_threshold' => 0.8,
                'cleanup_threshold' => 0.9,
                'eviction_policy' => 'allkeys-lru',
                'max_memory_samples' => 5,
            ],
            
            // Performance monitoring
            'monitoring' => [
                'enabled' => true,
                'metrics' => [
                    'hit_rate',
                    'miss_rate',
                    'memory_usage',
                    'connection_count',
                    'slow_queries'
                ],
                'alert_thresholds' => [
                    'hit_rate_min' => 0.8,        // Alert if hit rate < 80%
                    'memory_usage_max' => 0.9,    // Alert if memory > 90%
                    'slow_query_time' => 1.0,     // Alert if query > 1 second
                ]
            ]
        ];
    }
    
    /**
     * Get cache keys patterns for different data types
     */
    public static function getCacheKeyPatterns() {
        return [
            'analytics' => 'analytics_{type}_projects_{projects}_user_{user}',
            'project' => 'project_{id}_{type}',
            'user' => 'user_{id}_{type}',
            'session' => 'session_{id}',
            'export' => 'export_{user}_{type}_{timestamp}',
            'system' => 'system_{setting}',
        ];
    }
    
    /**
     * Get cache tags for organized invalidation
     */
    public static function getCacheTags() {
        return [
            'analytics' => [
                'user_affected',
                'wcag_compliance',
                'severity',
                'common_issues',
                'blocker_issues',
                'page_issues',
                'commented_issues',
                'compliance_trend',
                'unified_dashboard'
            ],
            'project' => [
                'assignments',
                'permissions',
                'metadata'
            ],
            'user' => [
                'roles',
                'sessions',
                'auth'
            ],
            'system' => [
                'settings',
                'templates',
                'configuration'
            ]
        ];
    }
    
    /**
     * Initialize production cache configuration
     */
    public static function initialize() {
        $config = self::getConfig();
        
        // Set Redis configuration
        if (class_exists('Redis')) {
            $redisClass = 'Redis';
            $redis = new $redisClass();
            try {
                $redis->connect(
                    $config['redis']['host'],
                    $config['redis']['port'],
                    $config['redis']['timeout']
                );
                
                if ($config['redis']['password']) {
                    $redis->auth($config['redis']['password']);
                }
                
                $redis->select($config['redis']['database']);
                
                // Configure Redis for production
                try {
                    $redis->config('SET', 'maxmemory', $config['memory']['max_memory']);
                    $redis->config('SET', 'maxmemory-policy', $config['memory']['eviction_policy']);
                    $redis->config('SET', 'maxmemory-samples', $config['memory']['max_memory_samples']);
                } catch (Exception $e) {
                    // Redis config commands may not be available in all environments
                    error_log('Redis config warning: ' . $e->getMessage());
                }
                
                // Set up monitoring if enabled
                if ($config['monitoring']['enabled']) {
                    self::setupMonitoring($redis, $config['monitoring']);
                }
                
                return $redis;
            } catch (Exception $e) {
                error_log('Failed to initialize production cache: ' . $e->getMessage());
                return null;
            }
        } else {
            error_log('Redis extension not available. Using fallback caching.');
            return null;
        }
    }
    
    /**
     * Setup cache monitoring
     */
    private static function setupMonitoring($redis, $monitoringConfig) {
        // Log cache statistics periodically
        $stats = [
            'memory_usage' => $redis->info('memory'),
            'stats' => $redis->info('stats'),
            'keyspace' => $redis->info('keyspace')
        ];
        
        // Check alert thresholds
        $memoryUsage = $stats['memory']['used_memory'] / $stats['memory']['maxmemory'];
        if ($memoryUsage > $monitoringConfig['alert_thresholds']['memory_usage_max']) {
            error_log("Cache memory usage high: {$memoryUsage}%");
        }
        
        // Calculate hit rate
        $hits = $stats['stats']['keyspace_hits'] ?? 0;
        $misses = $stats['stats']['keyspace_misses'] ?? 0;
        $hitRate = $hits + $misses > 0 ? $hits / ($hits + $misses) : 0;
        
        if ($hitRate < $monitoringConfig['alert_thresholds']['hit_rate_min']) {
            error_log("Cache hit rate low: {$hitRate}%");
        }
    }
}