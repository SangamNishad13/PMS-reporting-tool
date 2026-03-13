<?php
/**
 * Background Export Processor
 * 
 * This script demonstrates how to process export requests in the background.
 * In a production environment, this would be run as a cron job or daemon process.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/models/ExportRequest.php';

// Mock session for background processing
if (!isset($_SESSION)) {
    session_start();
    $_SESSION['user_id'] = 0; // System user for background processing
}

echo "=== Background Export Processor ===\n\n";

try {
    $exportRequest = new ExportRequest();
    
    // Get pending export requests
    echo "1. Checking for pending export requests...\n";
    $pendingRequests = $exportRequest->getPendingExportRequests(5);
    
    if (empty($pendingRequests)) {
        echo "✓ No pending export requests found\n";
        exit(0);
    }
    
    echo "✓ Found " . count($pendingRequests) . " pending export requests\n\n";
    
    // Process each request
    foreach ($pendingRequests as $request) {
        echo "2. Processing export request ID: " . $request['id'] . "\n";
        echo "   User ID: " . $request['user_id'] . "\n";
        echo "   Export Type: " . $request['export_type'] . "\n";
        echo "   Report Type: " . $request['report_type'] . "\n";
        echo "   Project IDs: " . implode(', ', $request['project_ids']) . "\n";
        
        try {
            // Update status to processing
            $exportRequest->updateExportStatus($request['id'], ExportRequest::STATUS_PROCESSING);
            echo "   ✓ Status updated to processing\n";
            
            // Simulate export generation (in real implementation, this would call ExportEngine)
            echo "   → Generating export file...\n";
            
            // Generate secure filename
            $extension = $request['export_type'] === 'pdf' ? 'pdf' : 'xlsx';
            $filename = $exportRequest->generateSecureFilename(
                $request['report_type'],
                $request['project_ids'],
                $request['id'],
                $extension
            );
            
            $filePath = $exportRequest->getExportFilePath($filename);
            
            // Simulate file generation (create dummy file)
            $dummyContent = "Export content for request " . $request['id'] . "\n";
            $dummyContent .= "Export Type: " . $request['export_type'] . "\n";
            $dummyContent .= "Report Type: " . $request['report_type'] . "\n";
            $dummyContent .= "Generated at: " . date('Y-m-d H:i:s') . "\n";
            
            if (file_put_contents($filePath, $dummyContent)) {
                echo "   ✓ Export file generated: $filename\n";
                
                // Update status to completed
                $exportRequest->updateExportStatus($request['id'], ExportRequest::STATUS_COMPLETED, $filePath);
                echo "   ✓ Status updated to completed\n";
                
                // In a real implementation, you would send notification email here
                echo "   → Notification email would be sent to user\n";
                
            } else {
                throw new Exception("Failed to create export file");
            }
            
        } catch (Exception $e) {
            echo "   ✗ Export failed: " . $e->getMessage() . "\n";
            
            // Update status to failed
            $exportRequest->updateExportStatus(
                $request['id'], 
                ExportRequest::STATUS_FAILED, 
                null, 
                $e->getMessage()
            );
            echo "   ✓ Status updated to failed\n";
        }
        
        echo "\n";
    }
    
    // Show statistics after processing
    echo "3. Export processing statistics:\n";
    $stats = $exportRequest->getExportStatistics();
    
    if (isset($stats['by_status'])) {
        echo "   Status breakdown:\n";
        foreach ($stats['by_status'] as $status => $count) {
            echo "     $status: $count\n";
        }
    }
    
    echo "\n=== Background Processing Completed ===\n";
    
} catch (Exception $e) {
    echo "✗ Background processing failed: " . $e->getMessage() . "\n";
    exit(1);
}