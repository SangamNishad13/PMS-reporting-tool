<?php
// includes/auth.php

// Set timezone to IST (Indian Standard Time) for all time operations
require_once __DIR__ . '/../config/timezone.php';

// Start session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    // Configure session security
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    
    // For localhost development, use Lax; for production, use Strict
    $samesite = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false) ? 'Lax' : 'Strict';
    ini_set('session.cookie_samesite', $samesite);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
    
    // Prefer project-specific temp folder for sessions (more reliable permissions)
    $preferredSessionPath = __DIR__ . '/../tmp/sessions';
    $sessionPathSet = false;
    
    // Try to create and set permissions on project session folder
    if (!is_dir($preferredSessionPath)) {
        @mkdir($preferredSessionPath, 0777, true);
    }
    
    // Check if writable and try to fix permissions
    if (is_dir($preferredSessionPath)) {
        if (!is_writable($preferredSessionPath)) {
            // Try to fix permissions
            @chmod($preferredSessionPath, 0777);
        }
        
        if (is_writable($preferredSessionPath)) {
            session_save_path($preferredSessionPath);
            $sessionPathSet = true;
        }
    }
    
    // Fallback: common Linux temp path on shared hosting.
    if (!$sessionPathSet && is_dir('/tmp') && is_writable('/tmp')) {
        session_save_path('/tmp');
        $sessionPathSet = true;
    }

    // Last resort: system temp directory
    if (!$sessionPathSet) {
        $sysTemp = sys_get_temp_dir();
        if (is_dir($sysTemp) && is_writable($sysTemp)) {
            session_save_path($sysTemp);
        }
    }
    
    session_start();
    
    // Regenerate session ID periodically to prevent session fixation
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        // Regenerate session ID every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

// Include configuration
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/helpers.php';

// Keep session permissions in sync with DB (so role/perm updates apply immediately)
if (isset($_SESSION['user_id'])) {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT role, is_active, force_password_reset, can_manage_issue_config, can_manage_devices FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $_SESSION['role'] = $u['role'];
            $_SESSION['can_manage_issue_config'] = (bool)$u['can_manage_issue_config'];
            $_SESSION['can_manage_devices'] = !empty($u['can_manage_devices']);
            $_SESSION['force_reset'] = !empty($u['force_password_reset']);
            if ((int)$u['is_active'] !== 1) {
                // user deactivated; force logout
                session_destroy();
                header("Location: /modules/auth/login.php");
                exit;
            }
        }
    } catch (Exception $e) {
        // non-fatal; keep existing session values
    }
}

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function login($username, $password) {
        // Validate input
        if (empty($username) || empty($password)) {
            return false;
        }
        
        // Sanitize username input
        $username = filter_var(trim($username), FILTER_SANITIZE_STRING);
        
        $stmt = $this->db->prepare("
            SELECT id, username, email, password, full_name, role, is_active, force_password_reset, can_manage_issue_config, can_manage_devices
            FROM users 
            WHERE (username = ? OR email = ?) AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['can_manage_issue_config'] = (bool)$user['can_manage_issue_config'];
            $_SESSION['can_manage_devices'] = !empty($user['can_manage_devices']);
            $_SESSION['force_reset'] = $user['force_password_reset'];
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            
            // Log login activity with device/ip/session info
            try {
                $details = [
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'session_id' => session_id(),
                    'device_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_sections' => $_SESSION['user_sections'] ?? []
                ];
                // attempt to parse basic device/browser info
                if (!empty($details['user_agent'])) {
                    $details['ua_parsed'] = get_browser_info($details['user_agent']);
                }

                // Geo lookup (best-effort)
                $geo = get_geo_info($details['device_ip'] ?? '');
                if (!empty($geo)) $details['geo'] = $geo;

                logActivity($this->db, $user['id'], 'login', 'auth', null, $details);

                // Persist session record including ip_location JSON
                $stmt = $this->db->prepare("INSERT INTO user_sessions (user_id, session_id, user_agent, ip_address, ip_location, active) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE last_activity = NOW(), active = 1, ip_location = VALUES(ip_location)");
                try {
                    $ipLoc = !empty($geo) ? json_encode($geo) : null;
                    $stmt->execute([$user['id'], session_id(), $details['user_agent'], $details['device_ip'], $ipLoc]);
                } catch (Exception $_) {}
            } catch (Exception $e) {
                // non-fatal
            }

            return true;
        }
        
        return false;
    }
    
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            // Log logout activity with device/ip/session info
            try {
                $details = [
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'session_id' => session_id(),
                    'device_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_sections' => $_SESSION['user_sections'] ?? []
                ];
                        if (!empty($details['user_agent'])) {
                            $details['ua_parsed'] = get_browser_info($details['user_agent']);
                        }
                        // Geo lookup on logout too
                        $geo = get_geo_info($details['device_ip'] ?? '');
                        if (!empty($geo)) $details['geo'] = $geo;
                        logActivity($this->db, $_SESSION['user_id'], 'logout', 'auth', null, $details);
            } catch (Exception $e) {
                // non-fatal
            }

            // Mark session as logged out in user_sessions
            try {
                $sid = session_id();
                $userId = $_SESSION['user_id'];
                // Update current session
                $ust = $this->db->prepare("UPDATE user_sessions SET logout_at = NOW(), active = 0, last_activity = NOW(), logout_type = 'manual' WHERE session_id = ? AND user_id = ?");
                $ust->execute([$sid, $userId]);
                
                // Optional: Also logout all other sessions for this user (uncomment if you want single-device login)
                // $ust2 = $this->db->prepare("UPDATE user_sessions SET logout_at = NOW(), active = 0, logout_type = 'manual_all' WHERE user_id = ? AND active = 1");
                // $ust2->execute([$userId]);
            } catch (Exception $_) {}
        }

        // Store logout message in session before destroying it
        // We'll use a cookie or URL parameter instead since session will be destroyed
        $logoutMessage = 'You have been successfully logged out.';

        session_unset();
        session_destroy();
        
        // Redirect to login page with success message
        require_once __DIR__ . '/helpers.php';
        $baseDir = getBaseDir();
        redirect("/modules/auth/login.php?logout=success");
    }
    
    public function isLoggedIn() {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        // Auto-logout only after 30 minutes of inactivity (no requests)
        $idleTimeout = 30 * 60;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
            try {
                $sid = session_id();
                $ust = $this->db->prepare("UPDATE user_sessions SET logout_at = NOW(), active = 0, last_activity = NOW(), logout_type = 'idle_30m' WHERE session_id = ? AND user_id = ?");
                $ust->execute([$sid, $_SESSION['user_id']]);
            } catch (Exception $_) {}
            try {
                $details = [
                    'reason' => 'idle_30m',
                    'session_id' => session_id(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'device_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ];
                logActivity($this->db, $_SESSION['user_id'], 'auto_logout', 'auth', null, $details);
            } catch (Exception $_) {}
            session_unset();
            session_destroy();
            return false;
        }
        
        // Verify session is still active in user_sessions (if table exists)
        try {
            $sid = session_id();
            $stmt = $this->db->prepare("SELECT active FROM user_sessions WHERE session_id = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$sid, $_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                // Session row missing (e.g., session ID rotated). Recreate it.
                try {
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $ust = $this->db->prepare("INSERT INTO user_sessions (user_id, session_id, user_agent, ip_address, active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE last_activity = NOW(), active = 1");
                    $ust->execute([$_SESSION['user_id'], $sid, $ua, $ip]);
                } catch (Exception $_) {
                    // ignore
                }
            } else if (intval($row['active']) !== 1) {
                // session was invalidated server-side
                $this->logout();
                return false;
            }
        } catch (Exception $e) {
            // ignore DB errors and proceed with normal session-based login
        }

        // Update last activity time
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    public function checkRole($requiredRole) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $userRole = $_SESSION['role'] ?? '';
        
        // If array of roles provided
        if (is_array($requiredRole)) {
            return in_array($userRole, $requiredRole);
        }
        
        // Single role with hierarchy
        $roleHierarchy = [
            'super_admin' => 6,
            'admin' => 5,
            'project_lead' => 4,
            'qa' => 3,
            'at_tester' => 2,
            'ft_tester' => 1
        ];
        
        // Check if roles exist in hierarchy
        if (!isset($roleHierarchy[$userRole]) || !isset($roleHierarchy[$requiredRole])) {
            return false;
        }
        
        $userLevel = $roleHierarchy[$userRole];
        $requiredLevel = $roleHierarchy[$requiredRole];
        
        return $userLevel >= $requiredLevel;
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            require_once __DIR__ . '/helpers.php';
            $baseDir = getBaseDir();
            redirect($baseDir . "/modules/auth/login.php");
        }
    }

    public function requireRole($requiredRole) {
        if (!$this->isLoggedIn()) {
            $baseDir = getBaseDir();
            header("Location: " . $baseDir . "/modules/auth/login.php");
            exit;
        }
        
        if (!$this->checkRole($requiredRole)) {
            $_SESSION['error'] = "You don't have permission to access this page.";
            $baseDir = getBaseDir();
            header("Location: " . $baseDir . "/modules/auth/login.php?error=access_denied");
            exit;
        }
    }
    
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    public function getUserRole() {
        return $_SESSION['role'] ?? null;
    }
}

// Helper functions for backward compatibility
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        require_once __DIR__ . '/helpers.php';
        $baseDir = getBaseDir();
        redirect($baseDir . "/modules/auth/login.php");
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
        $_SESSION['error'] = "You don't have permission to access this page.";
        require_once __DIR__ . '/helpers.php';
        $baseDir = getBaseDir();
        redirect($baseDir . "/");
        exit;
    }
}

function requireDeviceManager() {
    requireLogin();
    $role = $_SESSION['role'] ?? '';
    $canManage = !empty($_SESSION['can_manage_devices']);
    if (!$canManage && isset($_SESSION['user_id'])) {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT can_manage_devices FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $val = $stmt->fetchColumn();
            $canManage = !empty($val);
            $_SESSION['can_manage_devices'] = $canManage;
        } catch (Exception $_) {
            // ignore
        }
    }
    if (!in_array($role, ['admin', 'super_admin']) && !$canManage) {
        $_SESSION['error'] = "You don't have permission to access this page.";
        require_once __DIR__ . '/helpers.php';
        $baseDir = getBaseDir();
        redirect($baseDir . "/");
        exit;
    }
}

function requireRole($role) {
    requireLogin();
    if (is_array($role)) {
        if (!in_array($_SESSION['role'] ?? '', $role)) {
            $_SESSION['error'] = "You don't have permission to access this page.";
            require_once __DIR__ . '/helpers.php';
            $baseDir = getBaseDir();
            redirect($baseDir . "/");
            exit;
        }
    } else {
        if (($_SESSION['role'] ?? '') !== $role) {
            $_SESSION['error'] = "You don't have permission to access this page.";
            require_once __DIR__ . '/helpers.php';
            $baseDir = getBaseDir();
            redirect($baseDir . "/");
            exit;
        }
    }
}
?>
