<?php

require_once __DIR__ . '/../includes/models/UserAffectedAnalytics.php';
require_once __DIR__ . '/../includes/models/AnalyticsEngine.php';
require_once __DIR__ . '/../includes/models/AnalyticsReport.php';
require_once __DIR__ . '/../includes/exceptions/UnauthorizedAccessException.php';

/**
 * Unit tests for UserAffectedAnalytics class
 * 
 * Tests calculation accuracy with various datasets
 * Requirements: 4.1, 4.2, 4.5
 */
class UserAffectedAnalyticsTest extends PHPUnit\Framework\TestCase {
    
    private $analytics;
    private $mockDb;
    private $mockRedis;
    private $mockAccessControl;
    
    protected function setUp(): void {
        // Create mock dependencies
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockRedis = $this->createMock(stdClass::class);
        $this->mockAccessControl = $this->createMock(ClientAccessControlManager::class);
        
        // Create analytics instance with mocked dependencies
        $this->analytics = new UserAffectedAnalytics();
        
        // Use reflection to inject mocks
        $reflection = new ReflectionClass($this->analytics);
        
        $dbProperty = $reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($this->analytics, $this->mockDb);
        
        $redisProperty = $reflection->getProperty('redis');
        $redisProperty->setAccessible(true);
        $redisProperty->setValue($this->analytics, $this->mockRedis);
        
        $accessControlProperty = $reflection->getProperty('accessControl');
        $accessControlProperty->setAccessible(true);
        $accessControlProperty->setValue($this->analytics, $this->mockAccessControl);
        
        $cacheEnabledProperty = $reflection->getProperty('cacheEnabled');
        $cacheEnabledProperty->setAccessible(true);
        $cacheEnabledProperty->setValue($this->analytics, false); // Disable cache for testing
    }
    
    /**
     * Test user count range categorization
     * Requirements: 4.2 - Categorize by affected user count ranges (1-10, 11-50, 51-100, 100+)
     */
    public function testUserCountRangeCategorization() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('getUserCountRange');
        $method->setAccessible(true);
        
        // Test range boundaries
        $this->assertEquals(UserAffectedAnalytics::RANGE_VERY_LOW, $method->invoke($this->analytics, 1));
        $this->assertEquals(UserAffectedAnalytics::RANGE_VERY_LOW, $method->invoke($this->analytics, 10));
        $this->assertEquals(UserAffectedAnalytics::RANGE_LOW, $method->invoke($this->analytics, 11));
        $this->assertEquals(UserAffectedAnalytics::RANGE_LOW, $method->invoke($this->analytics, 50));
        $this->assertEquals(UserAffectedAnalytics::RANGE_MEDIUM, $method->invoke($this->analytics, 51));
        $this->assertEquals(UserAffectedAnalytics::RANGE_MEDIUM, $method->invoke($this->analytics, 100));
        $this->assertEquals(UserAffectedAnalytics::RANGE_HIGH, $method->invoke($this->analytics, 101));
        $this->assertEquals(UserAffectedAnalytics::RANGE_HIGH, $method->invoke($this->analytics, 1000));
        
        // Test edge case
        $this->assertEquals(UserAffectedAnalytics::RANGE_VERY_LOW, $method->invoke($this->analytics, 0));
    }
    
    /**
     * Test range label generation
     */
    public function testRangeLabels() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('getRangeLabel');
        $method->setAccessible(true);
        
        $this->assertEquals('1-10 users', $method->invoke($this->analytics, UserAffectedAnalytics::RANGE_VERY_LOW));
        $this->assertEquals('11-50 users', $method->invoke($this->analytics, UserAffectedAnalytics::RANGE_LOW));
        $this->assertEquals('51-100 users', $method->invoke($this->analytics, UserAffectedAnalytics::RANGE_MEDIUM));
        $this->assertEquals('100+ users', $method->invoke($this->analytics, UserAffectedAnalytics::RANGE_HIGH));
        $this->assertEquals('Unknown range', $method->invoke($this->analytics, 'invalid_range'));
    }
    
    /**
     * Test distribution calculation with sample data
     * Requirements: 4.1, 4.2 - Calculate metrics based on users_affected field values
     */
    public function testDistributionCalculation() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('calculateDistribution');
        $method->setAccessible(true);
        
        // Sample issues with different user counts
        $issues = [
            ['users_affected' => 5],   // RANGE_VERY_LOW
            ['users_affected' => 8],   // RANGE_VERY_LOW
            ['users_affected' => 25],  // RANGE_LOW
            ['users_affected' => 75],  // RANGE_MEDIUM
            ['users_affected' => 150], // RANGE_HIGH
            ['users_affected' => 200]  // RANGE_HIGH
        ];
        
        $distribution = $method->invoke($this->analytics, $issues);
        
        // Verify counts
        $this->assertEquals(2, $distribution[UserAffectedAnalytics::RANGE_VERY_LOW]['count']);
        $this->assertEquals(1, $distribution[UserAffectedAnalytics::RANGE_LOW]['count']);
        $this->assertEquals(1, $distribution[UserAffectedAnalytics::RANGE_MEDIUM]['count']);
        $this->assertEquals(2, $distribution[UserAffectedAnalytics::RANGE_HIGH]['count']);
        
        // Verify percentages (should sum to 100%)
        $totalPercentage = 0;
        foreach ($distribution as $range => $data) {
            $totalPercentage += $data['percentage'];
            $this->assertArrayHasKey('range_label', $data);
        }
        $this->assertEquals(100.0, $totalPercentage, '', 0.1);
        
        // Verify specific percentages
        $this->assertEquals(33.33, $distribution[UserAffectedAnalytics::RANGE_VERY_LOW]['percentage'], '', 0.01);
        $this->assertEquals(16.67, $distribution[UserAffectedAnalytics::RANGE_LOW]['percentage'], '', 0.01);
    }
    
    /**
     * Test distribution calculation with empty data
     */
    public function testDistributionCalculationEmpty() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('calculateDistribution');
        $method->setAccessible(true);
        
        $distribution = $method->invoke($this->analytics, []);
        
        // All counts should be 0
        foreach ($distribution as $range => $data) {
            $this->assertEquals(0, $data['count']);
            $this->assertEquals(0, $data['percentage']);
            $this->assertArrayHasKey('range_label', $data);
        }
    }
    
    /**
     * Test summary statistics calculation
     * Requirements: 4.4 - Calculate total affected users across all client-ready issues
     */
    public function testSummaryCalculation() {
        $reflection = new ReflectionClass($this->analytics);
        $calculateSummaryMethod = $reflection->getMethod('calculateSummary');
        $calculateSummaryMethod->setAccessible(true);
        
        $calculateDistributionMethod = $reflection->getMethod('calculateDistribution');
        $calculateDistributionMethod->setAccessible(true);
        
        // Sample issues
        $issues = [
            ['users_affected' => 10],  // RANGE_VERY_LOW
            ['users_affected' => 30],  // RANGE_LOW
            ['users_affected' => 80],  // RANGE_MEDIUM
            ['users_affected' => 150], // RANGE_HIGH
            ['users_affected' => 0]    // RANGE_VERY_LOW (edge case)
        ];
        
        $distribution = $calculateDistributionMethod->invoke($this->analytics, $issues);
        $summary = $calculateSummaryMethod->invoke($this->analytics, $issues, $distribution);
        
        // Verify basic counts
        $this->assertEquals(5, $summary['total_issues']);
        $this->assertEquals(270, $summary['total_users_affected']); // 10+30+80+150+0
        $this->assertEquals(54.0, $summary['average_users_per_issue']); // 270/5
        
        // Verify range analysis
        $this->assertEquals(1, $summary['high_impact_issues']); // Only 150 users issue
        $this->assertEquals(2, $summary['low_impact_issues']); // 10 and 0 users issues
        
        // Most common range should be RANGE_VERY_LOW (2 issues)
        $this->assertEquals(UserAffectedAnalytics::RANGE_VERY_LOW, $summary['most_common_range']);
        $this->assertNotEmpty($summary['most_common_range_label']);
    }
    
    /**
     * Test impact analysis calculation
     */
    public function testImpactAnalysisCalculation() {
        $reflection = new ReflectionClass($this->analytics);
        $calculateImpactMethod = $reflection->getMethod('calculateImpactAnalysis');
        $calculateImpactMethod->setAccessible(true);
        
        $calculateDistributionMethod = $reflection->getMethod('calculateDistribution');
        $calculateDistributionMethod->setAccessible(true);
        
        // Sample issues with high impact
        $issues = [
            ['users_affected' => 5],   // Low impact
            ['users_affected' => 75],  // Medium impact
            ['users_affected' => 150], // High impact
            ['users_affected' => 200]  // High impact
        ];
        
        $distribution = $calculateDistributionMethod->invoke($this->analytics, $issues);
        $impact = $calculateImpactMethod->invoke($this->analytics, $issues, $distribution);
        
        // Verify critical issues count (medium + high)
        $this->assertEquals(3, $impact['critical_issues_count']); // 75, 150, 200 users
        $this->assertEquals(75.0, $impact['critical_issues_percentage']); // 3/4 * 100
        
        // Verify impact score is calculated
        $this->assertIsFloat($impact['impact_score']);
        $this->assertGreaterThan(0, $impact['impact_score']);
        $this->assertLessThanOrEqual(100, $impact['impact_score']);
        
        // Verify potential reduction data
        $this->assertArrayHasKey('potential_user_impact_reduction', $impact);
        $reduction = $impact['potential_user_impact_reduction'];
        $this->assertArrayHasKey('high_impact_issues_count', $reduction);
        $this->assertArrayHasKey('potential_users_helped', $reduction);
        $this->assertArrayHasKey('percentage_of_total', $reduction);
        
        // Verify priority recommendation exists
        $this->assertNotEmpty($impact['priority_recommendation']);
        $this->assertIsString($impact['priority_recommendation']);
    }
    
    /**
     * Test potential reduction calculation
     */
    public function testPotentialReductionCalculation() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('calculatePotentialReduction');
        $method->setAccessible(true);
        
        $issues = [
            ['users_affected' => 10],  // Low impact (not counted)
            ['users_affected' => 60],  // Medium impact (counted)
            ['users_affected' => 150], // High impact (counted)
            ['users_affected' => 25]   // Low impact (not counted)
        ];
        
        $reduction = $method->invoke($this->analytics, $issues);
        
        $this->assertEquals(2, $reduction['high_impact_issues_count']); // 60 and 150
        $this->assertEquals(210, $reduction['potential_users_helped']); // 60 + 150
        $this->assertEquals(50.0, $reduction['percentage_of_total']); // 2/4 * 100
    }
    
    /**
     * Test impact score calculation
     */
    public function testImpactScoreCalculation() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('calculateImpactScore');
        $method->setAccessible(true);
        
        // Test with high impact issues
        $highImpactIssues = [
            ['users_affected' => 150], // High (100 points)
            ['users_affected' => 200], // High (100 points)
        ];
        $highScore = $method->invoke($this->analytics, $highImpactIssues);
        $this->assertEquals(100.0, $highScore); // Perfect score
        
        // Test with mixed impact issues
        $mixedIssues = [
            ['users_affected' => 5],   // Very low (25 points)
            ['users_affected' => 25],  // Low (50 points)
            ['users_affected' => 75],  // Medium (75 points)
            ['users_affected' => 150]  // High (100 points)
        ];
        $mixedScore = $method->invoke($this->analytics, $mixedIssues);
        $expectedScore = (25 + 50 + 75 + 100) / 4; // Average of scores
        $this->assertEquals($expectedScore, $mixedScore);
        
        // Test with empty issues
        $emptyScore = $method->invoke($this->analytics, []);
        $this->assertEquals(0.0, $emptyScore);
    }
    
    /**
     * Test priority recommendation logic
     */
    public function testPriorityRecommendation() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('getPriorityRecommendation');
        $method->setAccessible(true);
        
        // Test high impact scenario
        $highImpactDistribution = [
            UserAffectedAnalytics::RANGE_VERY_LOW => ['count' => 1],
            UserAffectedAnalytics::RANGE_LOW => ['count' => 0],
            UserAffectedAnalytics::RANGE_MEDIUM => ['count' => 0],
            UserAffectedAnalytics::RANGE_HIGH => ['count' => 3]
        ];
        $recommendation = $method->invoke($this->analytics, $highImpactDistribution);
        $this->assertStringContainsString('high-impact', $recommendation);
        $this->assertStringContainsString('3', $recommendation);
        
        // Test medium impact scenario
        $mediumImpactDistribution = [
            UserAffectedAnalytics::RANGE_VERY_LOW => ['count' => 1],
            UserAffectedAnalytics::RANGE_LOW => ['count' => 0],
            UserAffectedAnalytics::RANGE_MEDIUM => ['count' => 2],
            UserAffectedAnalytics::RANGE_HIGH => ['count' => 0]
        ];
        $recommendation = $method->invoke($this->analytics, $mediumImpactDistribution);
        $this->assertStringContainsString('medium-impact', $recommendation);
        $this->assertStringContainsString('2', $recommendation);
        
        // Test low impact scenario
        $lowImpactDistribution = [
            UserAffectedAnalytics::RANGE_VERY_LOW => ['count' => 5],
            UserAffectedAnalytics::RANGE_LOW => ['count' => 0],
            UserAffectedAnalytics::RANGE_MEDIUM => ['count' => 0],
            UserAffectedAnalytics::RANGE_HIGH => ['count' => 0]
        ];
        $recommendation = $method->invoke($this->analytics, $lowImpactDistribution);
        $this->assertStringContainsString('severity', $recommendation);
    }
    
    /**
     * Test empty report generation
     */
    public function testEmptyReportGeneration() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('getEmptyReport');
        $method->setAccessible(true);
        
        $emptyReport = $method->invoke($this->analytics);
        
        // Verify structure
        $this->assertArrayHasKey('project_ids', $emptyReport);
        $this->assertArrayHasKey('total_issues', $emptyReport);
        $this->assertArrayHasKey('distribution', $emptyReport);
        $this->assertArrayHasKey('summary', $emptyReport);
        $this->assertArrayHasKey('impact_analysis', $emptyReport);
        $this->assertArrayHasKey('generated_at', $emptyReport);
        
        // Verify empty values
        $this->assertEquals([], $emptyReport['project_ids']);
        $this->assertEquals(0, $emptyReport['total_issues']);
        $this->assertEquals(0, $emptyReport['summary']['total_users_affected']);
        $this->assertEquals('No issues found', $emptyReport['impact_analysis']['priority_recommendation']);
        
        // Verify all distribution ranges are present with zero counts
        foreach ([UserAffectedAnalytics::RANGE_VERY_LOW, UserAffectedAnalytics::RANGE_LOW, 
                  UserAffectedAnalytics::RANGE_MEDIUM, UserAffectedAnalytics::RANGE_HIGH] as $range) {
            $this->assertArrayHasKey($range, $emptyReport['distribution']);
            $this->assertEquals(0, $emptyReport['distribution'][$range]['count']);
        }
    }
    
    /**
     * Test chart configuration generation
     */
    public function testChartConfigGeneration() {
        $sampleReportData = [
            'distribution' => [
                UserAffectedAnalytics::RANGE_VERY_LOW => ['count' => 5, 'range_label' => '1-10 users'],
                UserAffectedAnalytics::RANGE_LOW => ['count' => 3, 'range_label' => '11-50 users'],
                UserAffectedAnalytics::RANGE_MEDIUM => ['count' => 2, 'range_label' => '51-100 users'],
                UserAffectedAnalytics::RANGE_HIGH => ['count' => 1, 'range_label' => '100+ users']
            ]
        ];
        
        $chartConfig = $this->analytics->getChartConfig($sampleReportData);
        
        // Verify chart structure
        $this->assertEquals('pie', $chartConfig['type']);
        $this->assertEquals('Issues by User Impact Range', $chartConfig['title']);
        $this->assertArrayHasKey('data', $chartConfig);
        $this->assertArrayHasKey('options', $chartConfig);
        
        // Verify data structure
        $data = $chartConfig['data'];
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('datasets', $data);
        $this->assertCount(4, $data['labels']); // 4 ranges
        $this->assertCount(1, $data['datasets']); // 1 dataset
        
        // Verify dataset
        $dataset = $data['datasets'][0];
        $this->assertArrayHasKey('data', $dataset);
        $this->assertArrayHasKey('backgroundColor', $dataset);
        $this->assertEquals([5, 3, 2, 1], $dataset['data']);
        $this->assertCount(4, $dataset['backgroundColor']); // 4 colors
        
        // Verify options
        $this->assertTrue($chartConfig['options']['responsive']);
        $this->assertArrayHasKey('plugins', $chartConfig['options']);
    }
    
    /**
     * Test unauthorized access handling
     */
    public function testUnauthorizedAccessHandling() {
        // Mock access control to deny access
        $this->mockAccessControl
            ->method('hasProjectAccess')
            ->willReturn(false);
        
        $this->expectException(UnauthorizedAccessException::class);
        $this->expectExceptionMessage('Client does not have access to project 1');
        
        // This should throw an exception
        $this->analytics->generateProjectReport(1, 999);
    }
    
    /**
     * Test project breakdown calculation
     */
    public function testProjectBreakdownCalculation() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('calculateProjectBreakdown');
        $method->setAccessible(true);
        
        $issues = [
            ['project_id' => 1, 'users_affected' => 10],
            ['project_id' => 1, 'users_affected' => 50],
            ['project_id' => 2, 'users_affected' => 100],
            ['project_id' => 2, 'users_affected' => 200]
        ];
        
        $projectIds = [1, 2];
        $breakdown = $method->invoke($this->analytics, $issues, $projectIds);
        
        // Verify structure
        $this->assertArrayHasKey(1, $breakdown);
        $this->assertArrayHasKey(2, $breakdown);
        
        // Verify project 1 data
        $project1 = $breakdown[1];
        $this->assertEquals(2, $project1['issue_count']);
        $this->assertArrayHasKey('distribution', $project1);
        $this->assertArrayHasKey('summary', $project1);
        
        // Verify project 2 data
        $project2 = $breakdown[2];
        $this->assertEquals(2, $project2['issue_count']);
        $this->assertArrayHasKey('distribution', $project2);
        $this->assertArrayHasKey('summary', $project2);
    }
    
    /**
     * Test data validation with missing users_affected field
     */
    public function testMissingUsersAffectedField() {
        $reflection = new ReflectionClass($this->analytics);
        $method = $reflection->getMethod('calculateDistribution');
        $method->setAccessible(true);
        
        // Issues with missing or null users_affected field
        $issues = [
            ['title' => 'Issue 1'], // Missing users_affected
            ['users_affected' => null, 'title' => 'Issue 2'], // Null users_affected
            ['users_affected' => '', 'title' => 'Issue 3'], // Empty string
            ['users_affected' => 25, 'title' => 'Issue 4'] // Valid
        ];
        
        $distribution = $method->invoke($this->analytics, $issues);
        
        // All issues with missing/invalid users_affected should be treated as 0 (RANGE_VERY_LOW)
        $this->assertEquals(4, $distribution[UserAffectedAnalytics::RANGE_VERY_LOW]['count']);
        $this->assertEquals(0, $distribution[UserAffectedAnalytics::RANGE_LOW]['count']);
    }
}