<?php
// includes/rbac.php
require_once __DIR__ . '/auth.php';

function checkAccess($requiredRole) {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
    
    $userRole = getUserRole();
    $roleHierarchy = ['admin' => 4, 'coordinator' => 3, 'supervisor' => 2, 'student' => 1];
    
    // If required role is higher than user's role, deny access
    if ($roleHierarchy[$requiredRole] > $roleHierarchy[$userRole]) {
        redirect(getRoleDashboard($userRole));
    }
}

function canManageUsers($pdo, $userId) {
    $role = getUserRole();
    if ($role === 'admin') {
        return true;
    }
    
    // Check if user exists in a department managed by this coordinator/supervisor
    // This is where you'd add additional logic for department-based permissions
    return false;
}

function getUsersByRole($pdo, $role = null) {
    $sql = "SELECT * FROM users";
    if ($role) {
        $sql .= " WHERE role = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$role]);
    } else {
        $stmt = $pdo->query($sql);
    }
    return $stmt->fetchAll();
}

function updateUserRole($pdo, $userId, $newRole) {
    $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
    return $stmt->execute([$newRole, $userId]);
}

function updateUserStatus($pdo, $userId, $newStatus) {
    $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
    return $stmt->execute([$newStatus, $userId]);
}

function getPendingUsers($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE status = 'pending'");
    $stmt->execute();
    return $stmt->fetchAll();
}

function approveUser($pdo, $userId) {
    $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
    return $stmt->execute([$userId]);
}

function declineUser($pdo, $userId) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    return $stmt->execute([$userId]);
}

function getUserDetails($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function updateUserProfile($pdo, $userId, $data) {
    $sql = "UPDATE users SET 
            firstname = ?, 
            middlename = ?, 
            lastname = ?, 
            suffix = ?, 
            phone = ?, 
            address = ?, 
            birthdate = ? 
            WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        $data['firstname'],
        $data['middlename'],
        $data['lastname'],
        $data['suffix'],
        $data['phone'],
        $data['address'],
        $data['birthdate'],
        $userId
    ]);
}
?>