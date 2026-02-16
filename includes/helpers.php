<?php
/**
 * Check if current user has admin privileges (admin or super_admin)
 * Admin users should have all rights of project leads, QA, and testers
 * 
 * @return bool True if user has admin privileges
 */
function hasAdminPrivileges() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin']);
}

/**
 * Check if current user has project lead privileges (project_lead, admin, or super_admin)
 * 
 * @return bool True if user has project lead privileges
 */
function hasProjectLeadPrivileges() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['project_lead', 'admin', 'super_admin']);
}

/**
 * Check if current user has QA privileges (qa, admin, or super_admin)
 * 
 * @return bool True if user has QA privileges
 */
function hasQAPrivileges() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['qa', 'admin', 'super_admin']);
}

/**
 * Check if current user has tester privileges (at_tester, ft_tester, admin, or super_admin)
 * 
 * @return bool True if user has tester privileges
 */
function hasTesterPrivileges() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['at_tester', 'ft_tester', 'admin', 'super_admin']);
}

/**
 * Sanitize user input to prevent XSS attacks
 * 
 * @param mixed $data The data to sanitize
 * @param bool $allowHtml Whether to allow HTML (default: false)
 * @return string Sanitized string
 */
function sanitizeInput($data, $allowHtml = false) {
    if (is_array($data)) {
        return array_map(function($item) use ($allowHtml) {
            return sanitizeInput($item, $allowHtml);
        }, $data);
    }
    
    $data = trim($data);
    
    if ($allowHtml) {
        // Allow HTML but sanitize it
        return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    // Strip all HTML tags
    return htmlspecialchars(strip_tags($data), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Validate and sanitize integer input
 * 
 * @param mixed $value The value to validate
 * @param int $default Default value if validation fails
 * @return int Validated integer
 */
function validateInt($value, $default = 0) {
    if (is_numeric($value)) {
        return (int) $value;
    }
    return $default;
}

/**
 * Output escaped string to prevent XSS
 * 
 * @param string $string The string to escape
 * @return string Escaped string
 */
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Get the base directory path (application root)
 * 
 * @return string Base directory path
 */
function getBaseDir() {
    static $baseDir = null;
    
    if ($baseDir === null) {
        // Calculate base directory from the includes directory
        // includes/helpers.php is always at: {app_root}/includes/helpers.php
        // So we go up one level from includes to get app root
        $appRoot = dirname(__DIR__);
        
        // Convert filesystem path to URL path
        $documentRoot = $_SERVER['DOCUMENT_ROOT'];
        $documentRoot = str_replace('\\', '/', rtrim($documentRoot, '/\\'));
        $appRoot = str_replace('\\', '/', $appRoot);
        
        // Get the relative path from document root
        if (strpos($appRoot, $documentRoot) === 0) {
            $baseDir = substr($appRoot, strlen($documentRoot));
            // Ensure it starts with /
            if ($baseDir === '') {
                $baseDir = '/';
            } elseif ($baseDir[0] !== '/') {
                $baseDir = '/' . $baseDir;
            }
        } else {
            // Fallback: use index.php location
            // index.php is always at the app root
            $indexPath = $_SERVER['SCRIPT_FILENAME'] ?? '';
            if (file_exists($indexPath) && basename($indexPath) === 'index.php') {
                $indexDir = dirname($indexPath);
                $indexDir = str_replace('\\', '/', $indexDir);
                if (strpos($indexDir, $documentRoot) === 0) {
                    $baseDir = substr($indexDir, strlen($documentRoot));
                    if ($baseDir === '') {
                        $baseDir = '/';
                    } elseif ($baseDir[0] !== '/') {
                        $baseDir = '/' . $baseDir;
                    }
                } else {
                    // Last resort: use SCRIPT_NAME
                    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
                    $baseDir = dirname($scriptName);
                }
            } else {
                // Last resort: use SCRIPT_NAME
                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
                $baseDir = dirname($scriptName);
            }
        }
        
        // Normalize the path
        $baseDir = str_replace('\\', '/', $baseDir);
        // Remove trailing slash if not root
        if ($baseDir !== '/' && $baseDir !== '') {
            $baseDir = rtrim($baseDir, '/');
        }
        // If it's root, set to empty string
        if ($baseDir === '/') {
            $baseDir = '';
        }
    }
    
    return $baseDir;
}

/**
 * Create an in-app notification.
 *
 * @param PDO $db
 * @param int $userId
 * @param string $type
 * @param string $message
 * @param string|null $link
 * @return bool
 */
function createNotification($db, $userId, $type, $message, $link = null) {
    $userId = (int)$userId;
    if ($userId <= 0) return false;

    $type = in_array($type, ['mention', 'assignment', 'system'], true) ? $type : 'system';
    $message = trim((string)$message);
    $link = $link ? trim((string)$link) : null;
    if ($link === '') $link = null;
    if ($link !== null) {
        // Normalize to app-relative path (avoid double baseDir in renderers)
        $baseDir = getBaseDir();
        if ($baseDir && strpos($link, $baseDir . '/') === 0) {
            $link = substr($link, strlen($baseDir));
            if ($link === '') $link = '/';
        }
    }

    try {
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$userId, $type, $message, $link]);
    } catch (Exception $e) {
        error_log('createNotification error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Redirect to a URL
 * 
 * @param string $path The path to redirect to
 * @param int $statusCode HTTP status code (default: 302)
 * @return void
 */
function redirect($path, $statusCode = 302) {
    // Prevent header injection
    $path = str_replace(["\r", "\n"], '', $path);
    
    // If it's already an absolute URL, use it as is
    if (preg_match('/^https?:\/\//', $path)) {
        http_response_code($statusCode);
        header("Location: $path");
        exit;
    }
    
    // Get base directory
    $baseDir = getBaseDir();
    
    // Ensure path starts with /
    $path = '/' . ltrim($path, '/');

    // Combine base directory with path, but avoid duplicating baseDir if already present in path
    if ($baseDir !== '' && strpos($path, $baseDir . '/') === 0) {
        $fullPath = $path; // path already contains baseDir
    } else {
        $fullPath = $baseDir . $path;
    }
    
    http_response_code($statusCode);
    header("Location: $fullPath");
    exit;
}

/**
 * Generate CSRF token
 * 
 * @return string CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * 
 * @param string $token The token to verify
 * @return bool True if token is valid
 */
function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get base URL of the application
 * 
 * @return string Base URL
 */
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = dirname($_SERVER['SCRIPT_NAME']);
    
    if ($script === '/' || $script === '\\') {
        $script = '';
    }
    
    return $protocol . '://' . $host . $script;
}

/**
 * Map user role to module directory
 * 
 * @param string $role User role
 * @return string Module directory name
 */
function getModuleDirectory($role) {
    $roleMapping = [
        'at_tester' => 'at_tester',
        'ft_tester' => 'ft_tester',
        'super_admin' => 'admin',
        'admin' => 'admin',
        'project_lead' => 'project_lead',
        'qa' => 'qa'
    ];
    
    return $roleMapping[$role] ?? $role;
}

/**
 * Resolve user full names for an array of user IDs
 *
 * @param PDO $db
 * @param array $ids
 * @return array list of full_name strings in id order
 */
function getUserNamesByIds($db, $ids) {
    if (empty($ids) || !is_array($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders) ORDER BY full_name");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_column($rows, 'full_name');
}

/**
 * Given a project_pages row, return assigned user ids for a tester type
 * Checks JSON array field first (e.g., at_tester_ids) then single-id field (at_tester_id)
 *
 * @param array $page
 * @param string $prefix 'at_tester'|'ft_tester' etc.
 * @return array of ints
 */
function getAssignedIdsFromPage($page, $prefix) {
    $jsonField = $prefix . '_ids';
    $singleField = $prefix . '_id';
    $ids = [];
    if (!empty($page[$jsonField])) {
        $decoded = json_decode($page[$jsonField], true);
        if (is_array($decoded)) {
            foreach ($decoded as $v) {
                if (is_numeric($v)) $ids[] = (int)$v;
            }
        }
    }
    if (empty($ids) && !empty($page[$singleField])) {
        if (is_numeric($page[$singleField])) $ids[] = (int)$page[$singleField];
    }
    return $ids;
}

/**
 * Return a display string (HTML) of assigned users for a page and prefix
 * Uses <br> between multiple names
 */
function getAssignedNamesHtml($db, $page, $prefix) {
    $ids = getAssignedIdsFromPage($page, $prefix);
    if (empty($ids)) return '';
    $parts = [];
    foreach ($ids as $uid) {
        $parts[] = renderUserNameLink($uid);
    }
    return implode('<br>', $parts);
}

/**
 * Compute page status based on latest testing and QA results
 * Mapping (approved):
 * - on_hold : manual override
 * - not_tested : no testing_results exist
 * - in_testing : latest testing_result status = in_progress
 * - testing_failed : latest testing_result status = fail
 * - tested : latest testing_result = pass and no QA results yet
 * - qa_review : QA exists but no final status (fallback)
 * - qa_failed : latest QA status = fail
 * - completed : latest QA status = pass
 *
 * @param PDO $db
 * @param array $page project_pages row (must include at least 'id' and may include 'status')
 * @return string one of the status keys above
 */
function computePageStatus($db, $page) {
    if (empty($page) || empty($page['id'])) return 'not_tested';
    // Manual override
    if (!empty($page['status']) && $page['status'] === 'on_hold') return 'on_hold';

    $pageId = (int)$page['id'];

    // Check environment-specific statuses first if they exist
    $envStmt = $db->prepare("SELECT status FROM page_environments WHERE page_id = ?");
    $envStmt->execute([$pageId]);
    $envStatuses = $envStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($envStatuses)) {
        if (in_array('fail', $envStatuses)) return 'testing_failed';
        if (in_array('in_progress', $envStatuses)) return 'in_testing';
        if (in_array('needs_review', $envStatuses)) return 'tested';
        if (in_array('completed', $envStatuses) && !in_array('fail', $envStatuses)) {
            // Check if ALL are completed or pass
            $allDone = true;
            foreach ($envStatuses as $status) {
                if ($status !== 'completed' && $status !== 'pass' && $status !== 'na') {
                    $allDone = false;
                    break;
                }
            }
            if ($allDone) return 'completed';
        }
        
        // If all are pass, check QA
        $allPass = true;
        foreach ($envStatuses as $status) {
            if ($status !== 'pass') {
                $allPass = false;
                break;
            }
        }
        
        if ($allPass) {
            // Check QA environment statuses
            $qaEnvStmt = $db->prepare("SELECT qa_status FROM page_environments WHERE page_id = ?");
            $qaEnvStmt->execute([$pageId]);
            $qaEnvStatuses = $qaEnvStmt->fetchAll(PDO::FETCH_COLUMN);

            $allQAPass = true;
            if (empty($qaEnvStatuses)) {
                $allQAPass = false;
            } else {
                foreach ($qaEnvStatuses as $qs) {
                    if ($qs !== 'pass' && $qs !== 'completed' && $qs !== 'na') {
                        $allQAPass = false;
                        break;
                    }
                }
            }

            if ($allQAPass) return 'completed';

            $stmt = $db->prepare("SELECT status FROM qa_results WHERE page_id = ? ORDER BY qa_date DESC LIMIT 1");
            $stmt->execute([$pageId]);
            $qr = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$qr) return 'tested';

            $qaStatus = $qr['status'] ?? null;
            if ($qaStatus === 'pass') return 'completed';
            if ($qaStatus === 'fail') return 'qa_failed';
            return 'qa_review';
        }
    }

    // Fallback to latest testing result if no environments or environment logic didn't return
    $stmt = $db->prepare("SELECT status FROM testing_results WHERE page_id = ? ORDER BY tested_at DESC LIMIT 1");
    $stmt->execute([$pageId]);
    $tr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tr) {
        return 'not_tested';
    }

    $testStatus = $tr['status'] ?? null;
    if ($testStatus === 'in_progress' || $testStatus === 'ongoing') return 'in_testing';
    if ($testStatus === 'fail') return 'testing_failed';
    if ($testStatus !== 'pass') return 'in_testing';

    // If testing passed, check QA
    $stmt = $db->prepare("SELECT status FROM qa_results WHERE page_id = ? ORDER BY qa_date DESC LIMIT 1");
    $stmt->execute([$pageId]);
    $qr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$qr) return 'tested';

    $qaStatus = $qr['status'] ?? null;
    if ($qaStatus === 'pass') return 'completed';
    if ($qaStatus === 'fail') return 'qa_failed';
    return 'qa_review';
}

/**
 * Map raw test status value to human-friendly label
 * @param string|null $status
 * @return string
 */
function formatTestStatusLabel($status) {
    if (empty($status)) return 'Not tested';
    $s = strtolower($status);
    if ($s === 'pass') return 'Tested';
    if (in_array($s, ['in_progress', 'inprogress', 'ongoing'])) return 'In Progress';
    if (in_array($s, ['on_hold', 'hold'])) return 'On Hold';
    if ($s === 'pending') return 'Pending';
    if (in_array($s, ['fail', 'failed'])) return 'Issues Found';
    return ucfirst($s);
}

/**
 * Map raw QA status value to human-friendly label
 * @param string|null $status
 * @return string
 */
function formatQAStatusLabel($status) {
    if (empty($status)) return 'Not reviewed';
    $s = strtolower($status);
    if ($s === 'pass') return 'QA Approved';
    if (in_array($s, ['in_progress', 'inprogress', 'ongoing'])) return 'In Progress';
    if (in_array($s, ['on_hold', 'hold'])) return 'On Hold';
    if ($s === 'pending') return 'Pending';
    if (in_array($s, ['fail', 'failed'])) return 'QA Rejected';
    return ucfirst($s);
}

/**
 * Map project status to human-friendly label
 * @param string|null $status
 * @return string
 */
function formatProjectStatusLabel($status) {
    if (empty($status)) return '';
    $s = strtolower($status);
    if ($s === 'in_progress') return 'In Progress';
    if ($s === 'on_hold') return 'On Hold';
    if ($s === 'completed') return 'Completed';
    if ($s === 'cancelled') return 'Cancelled';
    return ucfirst(str_replace('_', ' ', $s));
}

/**
 * Return bootstrap badge class for project status
 */
function projectStatusBadgeClass($status) {
    $s = strtolower((string)$status);
    if ($s === 'completed') return 'success';
    // In-progress projects should appear as 'warning' (yellow)
    if ($s === 'in_progress') return 'warning';
    // On-hold projects should appear as 'info' (light-blue)
    if ($s === 'on_hold') return 'info';
    if ($s === 'cancelled') return 'secondary';
    return 'secondary';
}

/**
 * Format task type for display
 * @param string $taskType
 * @return string
 */
function formatTaskType($taskType) {
    if (empty($taskType)) return '';
    $t = strtolower($taskType);
    if ($t === 'page_testing') return 'Page Testing';
    if ($t === 'page_qa') return 'Page QA';
    if ($t === 'project_phase') return 'Project Phase';
    if ($t === 'generic_task') return 'Generic Task';
    if ($t === 'at_testing') return 'AT Testing';
    if ($t === 'ft_testing') return 'FT Testing';
    return ucfirst(str_replace('_', ' ', $taskType));
}

/**
 * Map computed page status (or raw page status) to Testing home labels
 * Desired labels:
 * - Not Started
 * - In Progress
 * - On Hold
 * - QA In Progress
 * - In Fixing
 * - Needs Review
 *
 * @param PDO $db
 * @param array $page
 * @return string
 */
function formatTestingHomeStatus($db, $page) {
    // Prefer computed status when possible
    $computed = computePageStatus($db, $page);

    switch ($computed) {
        case 'not_tested':
            return 'Not Started';
        case 'in_testing':
            return 'In Progress';
        case 'testing_failed':
            return 'In Progress';
        case 'tested':
            return 'Needs Review';
        case 'qa_review':
            return 'QA In Progress';
        case 'qa_failed':
            return 'In Fixing';
        case 'completed':
            return 'Completed';
        case 'on_hold':
            return 'On Hold';
        default:
            // Fallback to raw page status if present
            $raw = strtolower($page['status'] ?? '');
            if (in_array($raw, ['in_progress', 'inprogress', 'ongoing'])) return 'In Progress';
            if ($raw === 'qa_in_progress' || $raw === 'qa_review') return 'QA In Progress';
            if (in_array($raw, ['in_fixing', 'fixing', 'qa_failed'])) return 'In Fixing';
            if ($raw === 'tested') return 'Needs Review';
            if ($raw === 'on_hold') return 'On Hold';
            if ($raw === 'completed') return 'Completed';
            return ucfirst(str_replace('_', ' ', $raw ?: 'Not Started'));
    }
}

/**
 * Render an interactive dropdown for global page status
 */
function renderPageStatusDropdown($pageId, $currentStatus) {
    if (empty($currentStatus)) {
        $currentStatus = 'not_started';
    }
    
    $statuses = [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'on_hold' => 'On Hold',
        'qa_in_progress' => 'QA In Progress',
        'in_fixing' => 'In Fixing',
        'needs_review' => 'Needs Review',
        'completed' => 'Completed'
    ];
    
    // Default badge style (solid)
    $badgeClass = 'secondary';
    
    // Map status to Bootstrap colors
    if ($currentStatus === 'completed') $badgeClass = 'success';
    elseif ($currentStatus === 'in_progress') $badgeClass = 'warning text-dark'; // Yellow needs dark text
    elseif (in_array($currentStatus, ['qa_in_progress', 'qa_review'])) $badgeClass = 'info text-dark'; // Cyan needs dark text
    elseif ($currentStatus === 'in_fixing') $badgeClass = 'danger';
    elseif ($currentStatus === 'on_hold') $badgeClass = 'light text-dark border'; // Light needs dark text + border
    
    $label = $statuses[$currentStatus] ?? ucfirst(str_replace('_', ' ', $currentStatus));
    
    $html = '<div class="btn-group status-dropdown-group">';
    // Use btn-sm and solid colors (btn-CHECK) instead of outline (btn-outline-CHECK) for better visibility
    $html .= '<button type="button" class="btn btn-sm btn-' . $badgeClass . ' dropdown-toggle page-status-badge" data-bs-toggle="dropdown" id="page-status-' . $pageId . '">';
    $html .= $label;
    $html .= '</button>';
    $html .= '<ul class="dropdown-menu">';
    foreach ($statuses as $val => $label) {
        $active = ($val === $currentStatus) ? 'active' : '';
        $html .= '<li><a class="dropdown-item ' . $active . ' status-update-link" href="#" data-page-id="' . $pageId . '" data-status="' . $val . '" data-action="update_page_status">' . $label . '</a></li>';
    }
    $html .= '</ul></div>';
    return $html;
}

/**
 * Render an interactive dropdown for environment status
 */
function renderEnvStatusDropdown($pageId, $envId, $currentStatus) {
    global $userRole, $userId;
    
    // Keep in sync with page_environments.status enum
    $statuses = [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'pass' => 'Pass',
        'fail' => 'Fail',
        'on_hold' => 'On Hold',
        'needs_review' => 'Needs Review'
    ];
    
    // Check if user can edit (admin, project_lead, or assigned tester)
    // For now, render dropdown for all - permission check happens in API
    
    $html = '<select class="form-select form-select-sm env-status-update" ';
    $html .= 'data-page-id="' . (int)$pageId . '" ';
    $html .= 'data-env-id="' . (int)$envId . '" ';
    $html .= 'data-status-type="testing" ';
    $html .= 'style="font-size: 0.75rem; min-width: 120px;">';
    
    foreach ($statuses as $val => $label) {
        $selected = ($val === $currentStatus) ? ' selected' : '';
        $html .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
    }
    
    $html .= '</select>';
    return $html;
}

/**
 * Return HTML <option> tags for environment status select
 */
function getEnvStatusOptionsHtml($selected = '') {
    $statuses = [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'pass' => 'Pass',
        'fail' => 'Fail',
        'on_hold' => 'On Hold',
        'needs_review' => 'Needs Review'
    ];
    $html = '';
    foreach ($statuses as $val => $label) {
        $sel = ($val === $selected) ? ' selected' : '';
        $html .= "<option value=\"$val\"$sel>$label</option>";
    }
    return $html;
}

/**
 * Log user activity to the database
 */
function logActivity($db, $userId, $action, $entityType, $entityId, $details = []) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            json_encode($details),
            $ip
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Ensure a project is marked as 'in_progress' if it's currently 'not_started'
 */
function ensureProjectInProgress($db, $projectId) {
    if (!$projectId) return;
    try {
        $stmt = $db->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ? AND status = 'not_started'");
        $stmt->execute([$projectId]);
    } catch (PDOException $e) {
        error_log("Failed to automate project status: " . $e->getMessage());
    }
}

/**
 * Render an interactive dropdown for QA environment status
 */
function renderQAEnvStatusDropdown($pageId, $envId, $currentStatus) {
    global $userRole, $userId;
    
    if (!$currentStatus) $currentStatus = "pending";
    $statuses = [
        "pending" => "Pending",
        "pass" => "Pass",
        "fail" => "Fail",
        "na" => "N/A",
        "completed" => "Completed"
    ];
    
    // Check if user can edit (admin, project_lead, qa, or assigned QA)
    // For now, render dropdown for all - permission check happens in API
    
    $html = '<select class="form-select form-select-sm env-status-update" ';
    $html .= 'data-page-id="' . (int)$pageId . '" ';
    $html .= 'data-env-id="' . (int)$envId . '" ';
    $html .= 'data-status-type="qa" ';
    $html .= 'style="font-size: 0.75rem; min-width: 120px;">';
    
    foreach ($statuses as $val => $label) {
        $selected = ($val === $currentStatus) ? ' selected' : '';
        $html .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
    }
    
    $html .= '</select>';
    return $html;
}

/**
 * Parse a simple user agent string into browser/os summary.
 * Best-effort, not relying on external libs.
 */
function get_browser_info($ua) {
    $info = ['browser' => 'Unknown', 'browser_version' => '', 'platform' => 'Unknown'];
    if (stripos($ua, 'Firefox') !== false) {
        $info['browser'] = 'Firefox';
        if (preg_match('/Firefox\/([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    } elseif (stripos($ua, 'Chrome') !== false && stripos($ua, 'Safari') !== false) {
        $info['browser'] = 'Chrome';
        if (preg_match('/Chrome\/([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    } elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) {
        $info['browser'] = 'Safari';
        if (preg_match('/Version\/([0-9\.]+)/', $ua, $m)) $info['browser_version'] = $m[1];
    } elseif (stripos($ua, 'Trident') !== false || stripos($ua, 'MSIE') !== false) {
        $info['browser'] = 'Internet Explorer';
    }

    if (stripos($ua, 'Windows') !== false) $info['platform'] = 'Windows';
    elseif (stripos($ua, 'Mac OS X') !== false) $info['platform'] = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $info['platform'] = 'Linux';
    elseif (stripos($ua, 'Android') !== false) $info['platform'] = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $info['platform'] = 'iOS';

    return $info;
}

/**
 * Get basic geo information for an IP address (city, region, country, postal)
 * Uses ipapi.co as a best-effort free service. Returns associative array or empty array on failure.
 */
function get_geo_info($ip) {
    $ip = trim($ip);
    if (!$ip) return [];
    // Skip private/local addresses
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) return [];

    $url = 'https://ipapi.co/' . urlencode($ip) . '/json/';
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    try {
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return [];
        $data = json_decode($json, true);
        if (!is_array($data)) return [];
        $geo = [];
        $geo['city'] = $data['city'] ?? '';
        $geo['region'] = $data['region'] ?? '';
        $geo['country'] = $data['country_name'] ?? ($data['country'] ?? '');
        $geo['postal'] = $data['postal'] ?? ($data['postal_code'] ?? '');
        $geo['latitude'] = $data['latitude'] ?? ($data['lat'] ?? '');
        $geo['longitude'] = $data['longitude'] ?? ($data['lon'] ?? '');
        return $geo;
    } catch (Exception $e) {
        return [];
    }
}
?>
