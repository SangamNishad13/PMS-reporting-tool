<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/performance_helper.php';
$auth = new Auth();
$auth->requireRole('admin');

header('Content-Type: application/json');

$projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int) $_GET['project_id'] : null;
$userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int) $_GET['user_id'] : 0;
$startDate = isset($_GET['start_date']) ? trim((string) $_GET['start_date']) : null;
$endDate = isset($_GET['end_date']) ? trim((string) $_GET['end_date']) : null;

try {
    $helper = new PerformanceHelper();
    [$startDate, $endDate] = $helper->normalizeDateRange($startDate, $endDate);

    $rawStats = $helper->getResourceStats($userId ?: null, $projectId, $startDate, $endDate);
    $userIds = array_values(array_filter(array_map(static function ($row) {
        return (int) ($row['user_id'] ?? 0);
    }, $rawStats)));

    if (!empty($userIds)) {
        $helper->queueInsightGeneration($projectId, $startDate, $endDate, $userIds);
        $helper->dispatchBackgroundInsightWorker();
    }

    $recordMap = $userId > 0
        ? [$userId => $helper->getInsightRecord($userId, $projectId, $startDate, $endDate)]
        : $helper->getInsightRecordsForScope($projectId, $startDate, $endDate);

    $results = [];

    foreach ($rawStats as $userStats) {
        $resolvedUserId = (int) ($userStats['user_id'] ?? 0);
        $record = $recordMap[$resolvedUserId] ?? null;
        $snapshotStats = $userStats['stats'] ?? [];

        if (!empty($record['stats_snapshot_json'])) {
            $cachedStats = json_decode((string) $record['stats_snapshot_json'], true);
            if (is_array($cachedStats) && !empty($cachedStats)) {
                $snapshotStats = array_replace_recursive($snapshotStats, $cachedStats);
            }
        }

        $summaryInput = $userStats;
        $summaryInput['stats'] = $snapshotStats;
        $status = (string) ($record['analysis_status'] ?? 'queued');

        $results[] = [
            'user_id' => $resolvedUserId,
            'name' => (string) ($userStats['name'] ?? ''),
            'role' => (string) ($userStats['role'] ?? ''),
            'cached' => $record !== null && $status === 'ready',
            'report_status' => $status,
            'report_generated_at' => (string) ($record['generated_at'] ?? ''),
            'summary' => $helper->buildInsightPayload($record, $summaryInput),
            'stats' => $snapshotStats,
        ];
    }

    echo json_encode([
        'success' => true,
        'queued' => !empty($userIds),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'data' => $results,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load resource performance insights.',
        'error' => $exception->getMessage(),
    ]);
}
