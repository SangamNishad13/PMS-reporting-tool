<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$userId = $_SESSION['user_id'];
$db = Database::getInstance();

// If no force reset is needed, redirect back to dashboard
if (!($_SESSION['force_reset'] ?? false)) {
    $role = $_SESSION['role'] ?? 'auth';
    $moduleDir = getModuleDirectory($role);
    redirect("/modules/$moduleDir/dashboard.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (strlen($newPassword) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match!";
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password and clear the flag
        $stmt = $db->prepare("UPDATE users SET password = ?, force_password_reset = 0 WHERE id = ?");
        if ($stmt->execute([$hashedPassword, $userId])) {
            $_SESSION['force_reset'] = 0;
            $_SESSION['success'] = "Password updated successfully. Welcome!";
            
            $role = $_SESSION['role'] ?? 'auth';
            $moduleDir = getModuleDirectory($role);
            redirect("/modules/$moduleDir/dashboard.php");
            exit;
        } else {
            $error = "Failed to update password. Please try again.";
        }
    }
}

// We don't include header.php because it might cause a redirect loop
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - First Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; }
        .reset-container { max-width: 450px; margin-top: 100px; }
    </style>
</head>
<body>
    <div class="container reset-container">
        <div class="card shadow">
            <div class="card-header bg-primary text-white text-center">
                <h4><i class="fas fa-lock"></i> Change Password</h4>
                <p class="mb-0">This is your first login. Please set a new password.</p>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required placeholder="Min 6 characters">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <div class="d-grid shadow-sm">
                        <button type="submit" class="btn btn-primary">Update Password & Continue</button>
                    </div>
                </form>
            </div>
            <div class="card-footer text-center">
                <small class="text-muted">You will be redirected to your dashboard after update.</small>
            </div>
        </div>
    </div>
</body>
</html>
