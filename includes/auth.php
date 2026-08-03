<?php
// includes/auth.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function authenticateUser($username, $password, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    
    if ($user && verifyPassword($password, $user['password'])) {
        if ($user['status'] === 'pending') {
            return ['error' => 'Your account is pending approval.'];
        }
        if ($user['status'] === 'inactive') {
            return ['error' => 'Your account has been deactivated.'];
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['status'] = $user['status'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['firstname'] = $user['firstname'];
        $_SESSION['lastname'] = $user['lastname'];
        $_SESSION['fullname'] = getFullName($user);
        
        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        return ['success' => true, 'role' => $user['role']];
    }
    
    return ['error' => 'Invalid username or password.'];
}

function registerUser($username, $email, $password, $firstname, $lastname, $middlename = null, $suffix = null, $phone = null, $address = null, $birthdate = null, $pdo) {
    // Check if username exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return ['error' => 'Username already exists.'];
    }
    
    // Check if email exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['error' => 'Email already exists.'];
    }
    
    // Validate birthdate if provided
    if ($birthdate && !validateBirthdate($birthdate)) {
        return ['error' => 'You must be at least 15 years old to register.'];
    }
    
    $hashedPassword = hashPassword($password);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, firstname, middlename, lastname, suffix, phone, address, birthdate, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    
    if ($stmt->execute([$username, $email, $hashedPassword, $firstname, $middlename, $lastname, $suffix, $phone, $address, $birthdate])) {
        $newUserId = $pdo->lastInsertId();
        notifyAdmins(
            $pdo,
            $newUserId,
            'pending_user',
            'Pending User Registration',
            trim($firstname . ' ' . $lastname) . ' is pending approval.',
            'pending.php'
        );
        return ['success' => true, 'message' => 'Registration successful. Please wait for approval.'];
    }
    
    return ['error' => 'Registration failed. Please try again.'];
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('../login.php');
    }
}

function requireRole($allowedRoles) {
    requireLogin();
    $role = getUserRole();
    if (!in_array($role, $allowedRoles)) {
        redirect('../' . getRoleDashboard($role));
    }
}

function getRoleDashboard($role) {
    $dashboards = [
        'admin' => 'admin/dashboard.php',
        'coordinator' => 'coordinator/dashboard.php',
        'supervisor' => 'supervisor/dashboard.php',
        'student' => 'student/dashboard.php'
    ];
    return $dashboards[$role] ?? 'login.php';
}

function logoutUser() {
    session_destroy();
    redirect('login.php');
}
?>