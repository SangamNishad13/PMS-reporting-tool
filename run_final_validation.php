<?php
/**
 * Final System Validation Script
 * Comprehensive validation of the Client Reporting and Analytics System
 */

// Prevent session conflicts
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

require_once 'includes/functions.php';

class FinalSystemValidator {
    private $results = [];
    private $pdo;
    
    public function __construct() {
        $this->pdo = $this->getDatabaseConnection();
    }
    
    private function getDatabaseConnection() {
        try {
            $pdo = new PDO("mysql:host=localhost;dbname=pms", "root", "");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (Exception $e) {
            $this->addResult('Database Connection', false, $e->getMessage());
            return null;
        }
    }
    
    public function runValidation() {
        echo "<h1>Client Reporting and Analytics System - Final Validation</h1>\n";
        echo "<div style='font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px;'>\n";
        
        $this->validateDatabaseSchema();
        $this->validateCoreModels();
        $this->validateAnalyticsEngines();
        $this->validateControllers();
        $this->validateVisualization();
        $this->validateExportFunctionality();
        $this->validateSecurityFeatures();
        $this->validateClientInterface();
        
        $this->displayResults();
        
        echo "</div>\n";
    }
    
    private function validateDatabaseSchema() {
        echo "<h2>🗄️ Database Schema Validation</h2>\n";
        
        // Check required tables exist
        $requiredTables = [
            'users', 'projects', 'issues', 'client_project_assignments',
            'analytics_reports', 'export_requests', 'audit_logs'
        ];
        
        foreach ($requiredTables as $table) {
            try {
                $stmt = $this->pdo->query("DESCRIBE $table");
                $this->addResult("Table '$table' exists", true);
            } catch (Exception $e) {
                $t