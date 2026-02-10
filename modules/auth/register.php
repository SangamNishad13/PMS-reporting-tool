<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$db = Database::getInstance();

// Check if registration is allowed
$allowRegistration = true; // Set to false to disable self-registration

if (!$allowRegistration) {
    $_SESSION['error'] = "Registration is disabled. Please contact administrator.";
    require_once __DIR__ . '/../../includes/helpers.php';
    header('Location: ' . getBaseDir() . '/modules/auth/login.php');
    exit;
}

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username']);
    $email = sanitizeInput($_POST['email']);
    $fullName = sanitizeInput($_POST['full_name']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validation
    $errors = [];
    
    if (strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters";
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address";
    }
    
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters";
    }
    
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match";
    }
    
    // Check if username/email already exists
    $check = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        $errors[] = "Username or email already exists";
    }
    
    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Default role for new registrations
        $defaultRole = 'ft_tester'; // Or set as needed
        
        $stmt = $db->prepare("
            INSERT INTO users (username, email, password, full_name, role, is_active)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        
        if ($stmt->execute([$username, $email, $hashedPassword, $fullName, $defaultRole])) {
            $_SESSION['success'] = "Registration successful! Your account will be activated by an administrator.";
            require_once __DIR__ . '/../../includes/helpers.php';
            header('Location: ' . getBaseDir() . '/modules/auth/login.php');
            exit;
        } else {
            $errors[] = "Registration failed. Please try again.";
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
    <div class="row justify-content-center mt-5">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4 class="text-center">Register New Account</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">Username *</label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       required minlength="3">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email *</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name *</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Password *</label>
                                <input type="password" class="form-control" id="password" name="password" 
                                       required minlength="6">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password *</label>
                                <input type="password" class="form-control" id="confirm_password" 
                                       name="confirm_password" required>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            After registration, your account will need to be activated by an administrator.
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Register</button>
                            <a href="<?php echo $baseDir; ?>/modules/auth/login.php" class="btn btn-outline-secondary">
                                Already have an account? Login
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Password strength indicator
    $('#password').on('input', function() {
        const password = $(this).val();
        let strength = 0;
        
        if (password.length >= 6) strength++;
        if (password.match(/[a-z]/)) strength++;
        if (password.match(/[A-Z]/)) strength++;
        if (password.match(/[0-9]/)) strength++;
        if (password.match(/[^a-zA-Z0-9]/)) strength++;
        
        const indicator = $('#password-strength');
        if (!indicator.length) {
            $(this).after('<div id="password-strength" class="mt-1"></div>');
        }
        
        const strengthText = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
        const strengthClass = ['danger', 'danger', 'warning', 'info', 'success', 'success'];
        
        $('#password-strength').html(`
            <div class="progress" style="height: 5px;">
                <div class="progress-bar bg-${strengthClass[strength]}" 
                     style="width: ${(strength / 5) * 100}%"></div>
            </div>
            <small class="text-${strengthClass[strength]}">${strengthText[strength]}</small>
        `);
    });
    
    // Confirm password match
    $('#confirm_password').on('input', function() {
        const password = $('#password').val();
        const confirm = $(this).val();
        
        if (confirm && password !== confirm) {
            $(this).addClass('is-invalid');
            $('#confirm-feedback').remove();
            $(this).after('<div id="confirm-feedback" class="invalid-feedback">Passwords do not match</div>');
        } else {
            $(this).removeClass('is-invalid');
            $('#confirm-feedback').remove();
        }
    });
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>