<?php
require_once __DIR__ . '/../config/database.php';

/**
 * ProjectManager class for managing projects
 */
class ProjectManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function getProjectById($id) {
        $stmt = $this->db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getAllProjects() {
        return $this->db->query("SELECT * FROM projects ORDER BY created_at DESC")->fetchAll();
    }
    
    public function getProjectsByStatus($status) {
        $stmt = $this->db->prepare("SELECT * FROM projects WHERE status = ? ORDER BY created_at DESC");
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }

    /**
     * Create a project with optional parent and auto-generated project_code.
     * Returns boolean success.
     */
    public function createProject($data) {
        // Expected keys: title, description, project_type, client_id, priority, created_by, project_lead_id (optional), total_hours (optional), project_code (optional), parent_project_id (optional)
        $db = $this->db;
        $title = $data['title'] ?? '';
        $description = $data['description'] ?? null;
        $project_type = $data['project_type'] ?? 'web';
        $client_id = isset($data['client_id']) ? intval($data['client_id']) : null;
        $priority = $data['priority'] ?? 'medium';
        $created_by = $data['created_by'] ?? null;
        $parent_id = isset($data['parent_project_id']) ? intval($data['parent_project_id']) : null;
        $project_lead_id = isset($data['project_lead_id']) && $data['project_lead_id'] !== '' ? intval($data['project_lead_id']) : null;
        $total_hours = isset($data['total_hours']) && $data['total_hours'] !== '' ? floatval($data['total_hours']) : null;

        // Determine project_code
        $project_code = null;
        // If admin provided project_code and it looks like a code, use it
        if (!empty($data['po_number'])) {
            $project_code = $data['po_number'];
        }

        try {
            // get client prefix
            $prefix = null;
            if ($client_id) {
                $stmt = $db->prepare("SELECT project_code_prefix, name FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $c = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($c) {
                    $prefix = $c['project_code_prefix'] ?: strtoupper(substr(preg_replace('/[^A-Za-z]/','', $c['name']),0,3));
                }
            }

            if ($parent_id) {
                // generate child code like PARENTcode + a/b/c
                $p = $this->getProjectById($parent_id);
                if (!$p) return false;
                $parentCode = $p['project_code'] ?: $p['po_number'];
                if (!$parentCode) return false;
                // find existing siblings and determine next letter
                $stmt = $db->prepare("SELECT project_code FROM projects WHERE parent_project_id = ? ORDER BY project_code");
                $stmt->execute([$parent_id]);
                $sibs = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $nextLetter = 'a';
                if ($sibs) {
                    // collect trailing letters
                    $letters = array_map(function($c) use ($parentCode){ return substr($c, strlen($parentCode)); }, $sibs);
                    // find next unused letter
                    $used = array_map('strtolower', $letters);
                    for ($i=0;$i<26;$i++) {
                        $ch = chr(97+$i);
                        if (!in_array($ch, $used)) { $nextLetter = $ch; break; }
                    }
                }
                $project_code = $parentCode . $nextLetter;
            } else {
                // top-level project: generate numeric sequence using prefix
                if (!$project_code) {
                    $prefix = $prefix ?: 'PRJ';
                    // Find existing project_codes starting with prefix and extract numbers
                    $stmt = $db->prepare("SELECT project_code FROM projects WHERE project_code LIKE ?");
                    $stmt->execute([$prefix . '%']);
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $max = 0;
                    foreach ($rows as $rc) {
                        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)/', $rc, $m)) {
                            $n = intval($m[1]); if ($n>$max) $max=$n;
                        }
                    }
                    $num = $max + 1;
                    $project_code = $prefix . $num;
                }
            }

            // Insert project; keep po_number set to project_code for compatibility
            $stmt = $db->prepare(
                "INSERT INTO projects (po_number, project_code, title, description, project_type, client_id, priority, project_lead_id, total_hours, created_by, parent_project_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $res = $stmt->execute([$project_code, $project_code, $title, $description, $project_type, $client_id, $priority, $project_lead_id, $total_hours, $created_by, $parent_id]);
            return $res;
        } catch (PDOException $e) {
            error_log('createProject error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get active status options for a given entity type
 * 
 * @param string $entityType Entity type (project, page, phase, test_result, qa_result)
 * @return array Array of status options
 */
function getStatusOptions($entityType) {
    $db = Database::getInstance();
    
    // For project status, use the new project_statuses table
    if ($entityType === 'project') {
        $stmt = $db->query("
            SELECT status_key, status_label, badge_color as color 
            FROM project_statuses 
            ORDER BY display_order, status_label
        ");
        return $stmt->fetchAll();
    }
    
    // For other entity types, check if status_options table exists
    try {
        $stmt = $db->prepare("
            SELECT status_key, status_label, color 
            FROM status_options 
            WHERE entity_type = ? AND is_active = TRUE 
            ORDER BY display_order, status_label
        ");
        $stmt->execute([$entityType]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table doesn't exist, return empty array
        return [];
    }
}

/**
 * Sanitize chat HTML allowing only a small whitelist of tags and safe attributes.
 * Allows <a href>, <img src> (data: or http/https), <b>, <strong>, <i>, <em>, <u>, <br>, <p>, <ul>, <ol>, <li>
 */
function sanitize_chat_html($html) {
    // Simple fallback sanitizer: strip <script> tags and on* attributes, prevent javascript: URIs.
    if (trim($html) === '') return '';
    // Remove script blocks
    $html = preg_replace('#<script.*?>.*?</script>#is', '', $html);
    // Remove on* attributes (onclick, onerror, etc.)
    $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    // Remove javascript: URIs in href/src
    $html = preg_replace_callback('/<(a|img)\b([^>]*)>/i', function($m){
        $tag = $m[1]; $attrs = $m[2];
        // remove javascript: in attributes
        $attrs = preg_replace_callback('/(href|src)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', function($ma){
            $name = $ma[1]; $val = trim($ma[2], "'\"");
            if (preg_match('#^\s*javascript:#i', $val)) {
                return '';
            }
            return $name . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
        }, $attrs);
        return '<' . $tag . $attrs . '>';
    }, $html);
    return $html;
}

if (!function_exists('ensureAvailabilityStatusMaster')) {
    function ensureAvailabilityStatusMaster($db) {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS availability_status_master (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    status_key VARCHAR(50) NOT NULL UNIQUE,
                    status_label VARCHAR(100) NOT NULL,
                    badge_color VARCHAR(30) NOT NULL DEFAULT 'secondary',
                    description TEXT NULL,
                    display_order INT NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_active_order (is_active, display_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $seedRows = [
                ['not_updated', 'Not Updated', 'secondary', 'No status update submitted yet', 0],
                ['available', 'Available', 'success', 'Available for work', 10],
                ['working', 'Working', 'primary', 'Actively working', 20],
                ['busy', 'Busy / In Meeting', 'warning', 'Busy or in a meeting', 30],
                ['on_leave', 'On Leave', 'danger', 'On planned leave', 40],
                ['sick_leave', 'Sick Leave', 'danger', 'Out due to sickness', 50]
            ];

            $seedStmt = $db->prepare("
                INSERT INTO availability_status_master
                    (status_key, status_label, badge_color, description, display_order, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    status_key = status_key
            ");
            foreach ($seedRows as $row) {
                $seedStmt->execute($row);
            }
        } catch (Exception $e) {
            // Keep call-sites resilient; they will use fallback options.
        }
    }
}

if (!function_exists('getAvailabilityStatusOptions')) {
    function getAvailabilityStatusOptions($includeInactive = false) {
        $fallback = [
            ['status_key' => 'not_updated', 'status_label' => 'Not Updated', 'badge_color' => 'secondary', 'display_order' => 0, 'is_active' => 1],
            ['status_key' => 'available', 'status_label' => 'Available', 'badge_color' => 'success', 'display_order' => 10, 'is_active' => 1],
            ['status_key' => 'working', 'status_label' => 'Working', 'badge_color' => 'primary', 'display_order' => 20, 'is_active' => 1],
            ['status_key' => 'busy', 'status_label' => 'Busy / In Meeting', 'badge_color' => 'warning', 'display_order' => 30, 'is_active' => 1],
            ['status_key' => 'on_leave', 'status_label' => 'On Leave', 'badge_color' => 'danger', 'display_order' => 40, 'is_active' => 1],
            ['status_key' => 'sick_leave', 'status_label' => 'Sick Leave', 'badge_color' => 'danger', 'display_order' => 50, 'is_active' => 1]
        ];

        try {
            $db = Database::getInstance();
            ensureAvailabilityStatusMaster($db);
            $sql = "
                SELECT status_key, status_label, badge_color, display_order, is_active
                FROM availability_status_master
            ";
            if (!$includeInactive) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY display_order ASC, status_label ASC";
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            return !empty($rows) ? $rows : $fallback;
        } catch (Exception $e) {
            return $fallback;
        }
    }
}

if (!function_exists('normalizeAvailabilityStatusKey')) {
    function normalizeAvailabilityStatusKey($status, array $allowedStatuses, $default = 'not_updated') {
        $status = strtolower(trim((string)$status));
        return in_array($status, $allowedStatuses, true) ? $status : $default;
    }
}

/**
 * Rewrite local upload URLs in HTML to secure file API URLs.
 * This avoids direct /uploads access issues on restrictive hosts.
 */
function rewrite_upload_urls_to_secure($html) {
    if (trim((string)$html) === '') return '';

    $baseDir = '';
    if (function_exists('getBaseDir')) {
        try {
            $baseDir = (string)getBaseDir();
        } catch (Exception $e) {
            $baseDir = '';
        }
    }
    $baseDir = rtrim($baseDir, '/');
    $secureBase = $baseDir . '/api/secure_file.php?path=';

    $mapUrl = function ($url) use ($secureBase) {
        $url = html_entity_decode(trim((string)$url), ENT_QUOTES, 'UTF-8');
        if ($url === '' || preg_match('#^(data:|javascript:|mailto:|tel:)#i', $url)) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $url;
        }

        $rel = null;
        $posUploads = strpos($path, '/uploads/');
        $posAssets = strpos($path, '/assets/uploads/');

        if ($posUploads !== false) {
            $rel = ltrim(substr($path, $posUploads + 1), '/');
        } elseif ($posAssets !== false) {
            $rel = ltrim(substr($path, $posAssets + 1), '/');
        } else {
            $pathTrim = ltrim($path, '/');
            if (strpos($pathTrim, 'uploads/') === 0 || strpos($pathTrim, 'assets/uploads/') === 0) {
                $rel = $pathTrim;
            }
        }

        if ($rel === null || $rel === '') {
            return $url;
        }

        return $secureBase . rawurlencode($rel);
    };

    $html = preg_replace_callback('/\b(src|href)\s*=\s*("([^"]*)"|\'([^\']*)\')/i', function ($m) use ($mapUrl) {
        $attr = $m[1];
        $quoteWrapped = $m[2];
        $val = isset($m[3]) && $m[3] !== '' ? $m[3] : (isset($m[4]) ? $m[4] : '');
        $newVal = $mapUrl($val);
        return $attr . '="' . htmlspecialchars($newVal, ENT_QUOTES, 'UTF-8') . '"';
    }, $html);

    return $html;
}

/**
 * Extract local upload-relative paths from HTML src/href attributes.
 * Supports direct /uploads URLs and secure_file.php?path=... URLs.
 */
function extract_local_upload_paths_from_html($html, $allowedPrefixes = ['uploads/', 'assets/uploads/']) {
    $html = (string)$html;
    if (trim($html) === '') return [];

    $paths = [];
    $matches = [];
    preg_match_all('/\b(?:src|href)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $matches, PREG_SET_ORDER);

    $normalize = function ($rawUrl) use ($allowedPrefixes) {
        $url = html_entity_decode(trim((string)$rawUrl), ENT_QUOTES, 'UTF-8');
        if ($url === '' || preg_match('#^(data:|javascript:|mailto:|tel:)#i', $url)) return '';

        $path = '';
        $urlPath = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');

        if ($urlPath !== '' && stripos($urlPath, '/api/secure_file.php') !== false) {
            $qp = [];
            parse_str($query, $qp);
            $path = (string)($qp['path'] ?? '');
            $path = rawurldecode($path);
        } else {
            $candidate = $urlPath !== '' ? $urlPath : $url;
            $candidate = str_replace('\\', '/', $candidate);
            $candidate = ltrim($candidate, '/');

            $posUploads = strpos($candidate, 'uploads/');
            $posAssets = strpos($candidate, 'assets/uploads/');
            if ($posUploads !== false) {
                $path = substr($candidate, $posUploads);
            } elseif ($posAssets !== false) {
                $path = substr($candidate, $posAssets);
            }
        }

        $path = ltrim(str_replace('\\', '/', (string)$path), '/');
        if ($path === '' || strpos($path, "\0") !== false || strpos($path, '..') !== false) return '';

        $allowed = false;
        foreach ((array)$allowedPrefixes as $prefix) {
            $prefix = ltrim(str_replace('\\', '/', (string)$prefix), '/');
            if ($prefix !== '' && strpos($path, $prefix) === 0) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) return '';

        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'];
        if (!in_array($ext, $imageExts, true)) return '';

        return $path;
    };

    foreach ($matches as $m) {
        $url = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
        $rel = $normalize($url);
        if ($rel !== '') $paths[$rel] = true;
    }

    return array_keys($paths);
}

/**
 * Delete local upload files referenced in HTML. Missing files are ignored.
 */
function delete_local_upload_files_from_html($html, $allowedPrefixes = ['uploads/', 'assets/uploads/']) {
    $relPaths = extract_local_upload_paths_from_html($html, $allowedPrefixes);
    if (empty($relPaths)) return ['deleted' => 0, 'paths' => []];

    $baseDir = realpath(__DIR__ . '/..');
    if ($baseDir === false) return ['deleted' => 0, 'paths' => []];
    $baseNorm = rtrim(str_replace('\\', '/', $baseDir), '/');
    $deleted = 0;
    $deletedPaths = [];

    foreach ($relPaths as $rel) {
        $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
        if ($rel === '' || strpos($rel, '..') !== false) continue;

        $candidate = $baseNorm . '/' . $rel;
        $full = realpath($candidate);
        if ($full === false) {
            if (!is_file($candidate)) continue;
            $full = $candidate;
        }

        $fullNorm = str_replace('\\', '/', $full);
        if (strpos($fullNorm, $baseNorm . '/uploads/') !== 0 && strpos($fullNorm, $baseNorm . '/assets/uploads/') !== 0) {
            continue;
        }
        if (is_file($fullNorm) && @unlink($fullNorm)) {
            $deleted++;
            $deletedPaths[] = $rel;
        }
    }

    return ['deleted' => $deleted, 'paths' => $deletedPaths];
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode((string)$data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $s = strtr((string)$data, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($s, true);
}

function get_public_image_token_secret() {
    static $secret = null;
    if ($secret !== null) return $secret;

    $fromEnv = trim((string)getenv('PMS_PUBLIC_IMAGE_SECRET'));
    if ($fromEnv !== '') {
        $secret = $fromEnv;
        return $secret;
    }

    $parts = [
        (string)DB_HOST,
        (string)DB_NAME,
        (string)DB_USER,
        (string)DB_PASS,
        __DIR__
    ];
    $secret = hash('sha256', implode('|', $parts));
    return $secret;
}

function normalize_local_upload_path_from_src($src, $allowedPrefixes = ['uploads/', 'assets/uploads/']) {
    $src = html_entity_decode(trim((string)$src), ENT_QUOTES, 'UTF-8');
    if ($src === '') return null;

    $urlPath = (string)(parse_url($src, PHP_URL_PATH) ?? '');
    $query = (string)(parse_url($src, PHP_URL_QUERY) ?? '');

    $path = '';
    if ($urlPath !== '' && stripos($urlPath, '/api/secure_file.php') !== false) {
        $qp = [];
        parse_str($query, $qp);
        $path = rawurldecode((string)($qp['path'] ?? ''));
    } else {
        $candidate = $urlPath !== '' ? $urlPath : $src;
        $candidate = str_replace('\\', '/', $candidate);
        $candidate = ltrim($candidate, '/');
        $posUploads = strpos($candidate, 'uploads/');
        $posAssetsUploads = strpos($candidate, 'assets/uploads/');
        if ($posUploads !== false) {
            $path = substr($candidate, $posUploads);
        } elseif ($posAssetsUploads !== false) {
            $path = substr($candidate, $posAssetsUploads);
        }
    }

    $path = ltrim(str_replace('\\', '/', (string)$path), '/');
    if ($path === '' || strpos($path, "\0") !== false || strpos($path, '..') !== false) {
        return null;
    }

    $allowed = false;
    foreach ((array)$allowedPrefixes as $prefix) {
        $prefix = ltrim(str_replace('\\', '/', (string)$prefix), '/');
        if ($prefix !== '' && strpos($path, $prefix) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) return null;

    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'];
    if (!in_array($ext, $imageExts, true)) return null;

    return $path;
}

function build_public_image_url_from_src($src) {
    $relPath = normalize_local_upload_path_from_src($src, ['uploads/issues/', 'uploads/chat/', 'assets/uploads/']);
    if ($relPath === null) {
        return (string)$src;
    }

    $payload = json_encode(['p' => $relPath], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return (string)$src;
    }
    $payloadB64 = base64url_encode($payload);
    $sig = hash_hmac('sha256', $payloadB64, get_public_image_token_secret());
    $token = $payloadB64 . '.' . $sig;

    $baseDir = '';
    if (function_exists('getBaseDir')) {
        try { $baseDir = (string)getBaseDir(); } catch (Exception $e) { $baseDir = ''; }
    }
    return rtrim($baseDir, '/') . '/api/public_image.php?t=' . rawurlencode($token);
}

/**
 * Render a user's full name as a link to their profile unless the user is an admin/super_admin.
 * Accepts either a user id or an array with keys ['id','full_name','role'].
 */
function renderUserNameLink($user) {
    $db = Database::getInstance();
    $id = null; $name = null; $role = null;
    if (is_array($user)) {
        $id = isset($user['id']) ? (int)$user['id'] : null;
        $name = $user['full_name'] ?? null;
        $role = $user['role'] ?? null;
    } else {
        $id = (int)$user;
    }
    if (!$id) return htmlspecialchars($name ?: 'Unknown', ENT_QUOTES, 'UTF-8');

    if (!$name || !$role) {
        $stmt = $db->prepare("SELECT full_name, role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (!$name) $name = $row['full_name'];
            if (!$role) $role = $row['role'];
        }
    }

    $name = $name ?: 'User';
    if (in_array($role, ['admin', 'super_admin'])) {
        return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    }

    $baseDir = isset($GLOBALS['baseDir']) ? $GLOBALS['baseDir'] : '';
    $href = ($baseDir ? $baseDir : '') . "/modules/profile.php?id=" . $id;
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
}

?>
