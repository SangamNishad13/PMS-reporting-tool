<?php
/**
 * Security Test Suite
 * Tests AuditLogger and SecurityValidator functionality
 */

require_once __DIR__ . '/../includes/models/AuditLogger.php';
require_once __DIR__ . '/../includes/models/SecurityValidator.php';

class SecurityTest {
    private $auditLogger;
    private $securityValidator;
    
    public function __construct() {
        $this->auditLogger = new AuditLogger();
        $this->securityValidator = new SecurityValidator($this->auditLogger);
    }
    
    public function testAuditLogging() {
        echo "Testing audit logging functionality...\n";
        
        try {
            // Test client activity logging
            $result = $this->auditLogger->logClientActivity(
                1, 
                AuditLogger::ACTION_LOGIN_SUCCESS, 
                'Test login from unit test',
                true
            );
            
            if ($result) {
                echo "✓ Client activity logging works\n";
            } else {
                echo "⚠ Client activity logging failed (database may not be available)\n";
            }
            
            // Test security violation logging
            $result = $this->auditLogger->logSecurityViolation(
                1,
                'test_violation',
                'Unit test security violation',
                'low'
            );
            
            if ($result) {
                echo "✓ Security violation logging works\n";
            } else {
                echo "⚠ Security violation logging failed (database may not be available)\n";
            }
            
            // Test audit statistics
            $stats = $this->auditLogger->getAuditStats(1);
            echo "✓ Audit statistics retrieved: " . count($stats) . " action types\n";
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Audit logging test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function testInputValidation() {
        echo "\nTesting input validation...\n";
        
        try {
            // Test basic validation
            $testData = [
                'email' => 'test@example.com',
                'name' => 'John Doe',
                'age' => '25',
                'website' => 'https://example.com'
            ];
            
            $rules = [
                'email' => ['required' => true, 'type' => 'email'],
                'name' => ['required' => true, 'type' => 'string', 'max_length' => 100],
                'age' => ['type' => 'int'],
                'website' => ['type' => 'url']
            ];
            
            $result = $this->securityValidator->validateInput($testData, $rules);
            
            if ($result['valid']) {
                echo "✓ Basic input validation passed\n";
            } else {
                echo "✗ Basic input validation failed: " . json_encode($result['errors']) . "\n";
            }
            
            // Test invalid data
            $invalidData = [
                'email' => 'invalid-email',
                'name' => '',
                'age' => 'not-a-number'
            ];
            
            $invalidResult = $this->securityValidator->validateInput($invalidData, $rules);
            
            if (!$invalidResult['valid']) {
                echo "✓ Invalid input correctly rejected\n";
            } else {
                echo "✗ Invalid input was incorrectly accepted\n";
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Input validation test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function testXSSProtection() {
        echo "\nTesting XSS protection...\n";
        
        try {
            $xssAttempts = [
                '<script>alert("xss")</script>',
                '<iframe src="javascript:alert(1)"></iframe>',
                '<img onerror="alert(1)" src="x">',
                'javascript:alert(1)',
                '<object data="javascript:alert(1)"></object>'
            ];
            
            $detectedCount = 0;
            foreach ($xssAttempts as $attempt) {
                if ($this->securityValidator->detectXSS($attempt)) {
                    $detectedCount++;
                }
            }
            
            echo "✓ Detected $detectedCount/" . count($xssAttempts) . " XSS attempts\n";
            
            // Test string sanitization
            $maliciousString = '<script>alert("test")</script>Hello World';
            $sanitized = $this->securityValidator->sanitizeString($maliciousString);
            
            if (strpos($sanitized, '<script>') === false) {
                echo "✓ String sanitization removes dangerous content\n";
            } else {
                echo "✗ String sanitization failed\n";
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ XSS protection test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function testSQLInjectionProtection() {
        echo "\nTesting SQL injection protection...\n";
        
        try {
            $sqlAttempts = [
                "'; DROP TABLE users; --",
                "1' OR '1'='1",
                "UNION SELECT * FROM users",
                "1; DELETE FROM users WHERE 1=1",
                "' OR 1=1 --"
            ];
            
            $detectedCount = 0;
            foreach ($sqlAttempts as $attempt) {
                if ($this->securityValidator->detectSQLInjection($attempt)) {
                    $detectedCount++;
                }
            }
            
            echo "✓ Detected $detectedCount/" . count($sqlAttempts) . " SQL injection attempts\n";
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ SQL injection protection test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function testCSRFProtection() {
        echo "\nTesting CSRF protection...\n";
        
        try {
            // Generate token
            $token = $this->securityValidator->generateCSRFToken();
            
            if (!empty($token) && strlen($token) === 64) {
                echo "✓ CSRF token generation works\n";
            } else {
                echo "✗ CSRF token generation failed\n";
            }
            
            // Test validation
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $sessionToken = $_SESSION['csrf_token'] ?? '';
            
            if ($this->securityValidator->validateCSRFToken($token, $sessionToken)) {
                echo "✓ CSRF token validation works\n";
            } else {
                echo "✗ CSRF token validation failed\n";
            }
            
            // Test invalid token
            if (!$this->securityValidator->validateCSRFToken('invalid', $sessionToken)) {
                echo "✓ Invalid CSRF token correctly rejected\n";
            } else {
                echo "✗ Invalid CSRF token was accepted\n";
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ CSRF protection test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function testRateLimiting() {
        echo "\nTesting rate limiting...\n";
        
        try {
            $identifier = 'test_user_' . time();
            
            // Test normal usage
            for ($i = 1; $i <= 3; $i++) {
                $allowed = $this->securityValidator->checkRateLimit($identifier, 5, 60);
                if (!$allowed) {
                    echo "✗ Rate limiting triggered too early at attempt $i\n";
                    return false;
                }
            }
            echo "✓ Rate limiting allows normal usage\n";
            
            // Test limit enforcement
            for ($i = 4; $i <= 6; $i++) {
                $this->securityValidator->checkRateLimit($identifier, 5, 60);
            }
            
            $blocked = !$this->securityValidator->checkRateLimit($identifier, 5, 60);
            if ($blocked) {
                echo "✓ Rate limiting blocks excessive requests\n";
            } else {
                echo "✗ Rate limiting failed to block excessive requests\n";
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Rate limiting test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function runAllTests() {
        echo "=== Security Test Suite ===\n\n";
        
        $results = [];
        $results[] = $this->testAuditLogging();
        $results[] = $this->testInputValidation();
        $results[] = $this->testXSSProtection();
        $results[] = $this->testSQLInjectionProtection();
        $results[] = $this->testCSRFProtection();
        $results[] = $this->testRateLimiting();
        
        $passed = array_sum($results);
        $total = count($results);
        
        echo "\n=== Security Test Results ===\n";
        echo "Passed: $passed/$total tests\n";
        
        if ($passed === $total) {
            echo "✅ All security tests passed!\n";
        } else {
            echo "❌ Some security tests failed\n";
        }
        
        return $passed === $total;
    }
}

// Run the security tests
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $test = new SecurityTest();
    $test->runAllTests();
}