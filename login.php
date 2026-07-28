<?php


// login.php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $result = authenticateUser($username, $password, $pdo);
        
        if (isset($result['success'])) {
            redirect(getRoleDashboard($result['role']));
        } else {
            $error = $result['error'];
        }
    }
}

// If already logged in, redirect to dashboard
if (isLoggedIn() && isActive()) {
    redirect(getRoleDashboard(getUserRole()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RBAC System</title>
    <link rel="stylesheet" href="assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <div class="auth-icon"><i class="fa-solid fa-lock"></i></div>
            <h2>Welcome back</h2>
            <p>Sign in to access your role-based dashboard.</p>
            <?php if ($error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button class="btn-primary" type="submit">Login</button>
            </form>
            <div class="auth-link">
                Don't have an account? <a href="register.php">Register here</a>
            </div>
        </div>
    </div>
</body>
</html>