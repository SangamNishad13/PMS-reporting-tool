<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$error = '';
$success = '';

// Check for logout success message (only show on GET, not on POST submissions)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $success = 'You have been successfully logged out.';
}

// If already logged in, redirect to dashboard
if ($auth->isLoggedIn()) {
    $role = $_SESSION['role'];
    $moduleDir = getModuleDirectory($role);
    redirect("/modules/{$moduleDir}/dashboard.php");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = "Username and password are required";
        } elseif ($auth->login($username, $password)) {
            // Redirect based on role with proper mapping
            $role = $_SESSION['role'];
            $moduleDir = getModuleDirectory($role);
            redirect("/modules/{$moduleDir}/dashboard.php");
        } else {
            $error = "Invalid username or password";
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container" role="main" id="main-content" tabindex="-1">
    <div class="row justify-content-center mt-5">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h1 class="text-center">Login to Project Management System</h1>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert"><?php echo e($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert"><?php echo e($success); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username or Email</label>
                            <input type="text" autocomplete="username" class="form-control" id="username" name="username" value="<?php echo e($_POST['username'] ?? ''); ?>" required <?php echo !$error ? 'autofocus' : ''; ?>>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" autocomplete="current-password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Login</button>
                    </form>
                    
                    <?php if ($error): ?>
                    <script>
                        // Focus on username field when there's an error
                        document.addEventListener('DOMContentLoaded', function() {
                            const usernameField = document.getElementById('username');
                            if (usernameField) {
                                usernameField.focus();
                                usernameField.select(); // Also select the text for easy correction
                            }
                        });
                    </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>