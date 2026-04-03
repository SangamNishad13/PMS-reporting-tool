<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/performance_helper.php';

$auth = new Auth();
$auth->requireRole('admin');

header('Content-Type: application/json');

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$refresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';

$helper = new PerformanceHelper();
$db = Database::getInstance();

// 1. Get raw stats
$rawStats = $helper->getResourceStats($userId, $projectId, $startDate, $endDate);

$results = [];

foreach ($rawStats as $uStats) {
    $uId = $uStats['user_id'];
    
    // Check cache
    $stmt = $db->prepare("SELECT * FROM resource_performance_feedback WHERE user_id = ? AND (project_id = ? OR (project_id IS NULL AND ? IS NULL))");
    $stmt->execute([$uId, $projectId, $projectId]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $aiSummary = null;
    $shouldGenerateAI = ($userId && $uId == $userId); // Only generate AI if specifically requested via user_id

    if ($cached) {
        $aiSummary = json_decode($cached['ai_summary'], true);
        
        // If refreshing specifically for this user, or if we need AI and don't have it
        if ($shouldGenerateAI && ($refresh || !$aiSummary)) {
            $aiSummary = generateAIInsight($uStats);
            saveInsightToDb($db, $uId, $projectId, $uStats, $aiSummary);
        }
    } else if ($shouldGenerateAI) {
        // No cache, but specifically requested
        $aiSummary = generateAIInsight($uStats);
        saveInsightToDb($db, $uId, $projectId, $uStats, $aiSummary);
    }

    $results[] = [
        'user_id' => $uId,
        'name' => $uStats['name'],
        'role' => $uStats['role'],
        'cached' => (bool)$cached,
        'summary' => $aiSummary ?: [
            'overall_summary' => 'Click "Analyze" for detailed AI insights.',
            'positive' => [],
            'negative' => []
        ],
        'stats' => $uStats['stats']
    ];
}

echo json_encode(['success' => true, 'data' => $results]);

/**
 * Helper to save insight
 */
function saveInsightToDb($db, $uId, $projectId, $uStats, $aiSummary) {
    $upsert = $db->prepare("
        INSERT INTO resource_performance_feedback 
        (user_id, project_id, accuracy_score, activity_score, positive_feedback, negative_feedback, ai_summary)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        accuracy_score = VALUES(accuracy_score),
        activity_score = VALUES(activity_score),
        positive_feedback = VALUES(positive_feedback),
        negative_feedback = VALUES(negative_feedback),
        ai_summary = VALUES(ai_summary),
        last_updated_at = NOW()
    ");
    
    $upsert->execute([
        $uId,
        $projectId,
        $uStats['stats']['accuracy']['accuracy_percentage'],
        $uStats['stats']['activity']['total_actions'],
        implode("\n", $aiSummary['positive'] ?? []),
        implode("\n", $aiSummary['negative'] ?? []),
        json_encode($aiSummary)
    ]);
}

/**
 * Calls Ollama to generate a performance insight
 */
function generateAIInsight($stats) {
    $projectContext = "Overall Portfolio";
    if (isset($stats['project_id'])) {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT title FROM projects WHERE id = ?");
        $stmt->execute([$stats['project_id']]);
        $projectName = $stmt->fetchColumn();
        if ($projectName) $projectContext = "Project: $projectName";
    }

    $prompt = "Analyze the following performance data for a Resource (QA/Tester) in a Project Management System.
    Context: $projectContext
    Resource Name: {$stats['name']}
    Role: {$stats['role']}
    
    Quantitative Stats:
    - Accessibility Finding Accuracy: {$stats['stats']['accuracy']['accuracy_percentage']}% (Higher is better)
    - Total Findings Created: {$stats['stats']['accuracy']['total_findings']}
    - Total Admin Corrections: {$stats['stats']['accuracy']['corrected_count']}
    - Total Activity Actions: {$stats['stats']['activity']['total_actions']}
    - Total Issue Comments: {$stats['stats']['communication']['total_comments']}
    
    Qualitative Data (Recent Communication Samples):
    ";
    
    foreach ($stats['stats']['communication']['recent_samples'] as $sample) {
        $cleanText = strip_tags($sample['text']);
        $prompt .= "- \"$cleanText\"\n";
    }
    
    $prompt .= "\nBased on this data, provide:
    1. A short overall summary (1-2 sentences).
    2. 2-3 Positive Feedback points.
    3. 1-2 Negative Feedback points or Areas for Improvement.
    
    Return the response strictly in this JSON format:
    {
        \"overall_summary\": \"...\",
        \"positive\": [\"...\", \"...\"],
        \"negative\": [\"...\", \"...\"]
    }";

    // Call Ollama API
    $ch = curl_init('http://127.0.0.1:11434/api/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90); 
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'llama3:latest',
        'prompt' => $prompt,
        'stream' => false,
        'format' => 'json'
    ]));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    // Diagnostic Log for VPS
    $logData = date('[Y-m-d H:i:s] ') . "URL: 127.0.0.1 | Code: $httpCode | Error: $error | Response: $response\n";
    file_put_contents(__DIR__ . '/ai_debug_log.txt', $logData, FILE_APPEND);
    
    $fallback = [
        'overall_summary' => 'AI analysis is currently unavailable (See ai_debug_log.txt on server).',
        'positive' => ['Quantitative data collection: Success', 'Performance tracking: Active'],
        'negative' => ['Detailed qualitative insight: Troubleshooting']
    ];

    if ($error || $httpCode !== 200) {
        return $fallback;
    }

    $resData = json_decode($response, true);
    $aiContent = $resData['response'] ?? '';
    
    // Robust JSON extraction (AI sometimes wraps JSON in extra text)
    if (preg_match('/\{.*\}/s', $aiContent, $matches)) {
        $cleanJson = $matches[0];
        $parsed = json_decode($cleanJson, true);
        
        if ($parsed) {
            // Map keys in case AI gets creative
            return [
                'overall_summary' => (string)($parsed['overall_summary'] ?? ($parsed['summary'] ?? $fallback['overall_summary'])),
                'positive' => (array)($parsed['positive'] ?? ($parsed['positive_feedback'] ?? ($parsed['strengths'] ?? $fallback['positive']))),
                'negative' => (array)($parsed['negative'] ?? ($parsed['negative_feedback'] ?? ($parsed['weaknesses'] ?? $fallback['negative']))),
            ];
        }
    }

    return $fallback;
}
