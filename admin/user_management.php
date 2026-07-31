<?php
// admin/user_management.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is admin
checkAccess('admin');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin';
$role = getUserRole();
$userId = getUserId();

// ===== PROFILE PICTURE SETTINGS =====
$avatarUploadDir = __DIR__ . '/../assets/uploads/avatars/';
$avatarPublicPath = '../assets/uploads/avatars/';

function getUserProfilePicture($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

// Handle AJAX requests for password change and avatar update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Change password
    if ($_POST['action'] === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }
        if ($newPassword !== $confirmPassword) {
            echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
            exit;
        }
        if (strlen($newPassword) < 8) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($currentPassword, $hash)) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
                exit;
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$newHash, $userId]);

            echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Update profile picture
    if ($_POST['action'] === 'update_avatar' && isset($_FILES['avatar'])) {
        $file = $_FILES['avatar'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $maxSize = 2 * 1024 * 1024; // 2MB

        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Upload failed. Please try again.']);
            exit;
        }
        if (!in_array($file['type'], $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, WEBP or GIF images are allowed.']);
            exit;
        }
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'message' => 'Image must be smaller than 2MB.']);
            exit;
        }

        if (!is_dir($avatarUploadDir)) {
            @mkdir($avatarUploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newFileName = 'user_' . $userId . '_' . time() . '.' . strtolower($ext);
        $destination = $avatarUploadDir . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt->execute([$newFileName, $userId]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Profile picture updated.',
                    'path' => $avatarPublicPath . $newFileName
                ]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not save the uploaded file.']);
        }
        exit;
    }
}

// Handle non-AJAX POST actions (update role, status, create user, delete user)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_role':
                $targetUserId = (int)$_POST['user_id'];
                $newRole = $_POST['role'];
                if (updateUserRole($pdo, $targetUserId, $newRole)) {
                    $_SESSION['message'] = 'User role updated successfully.';
                }
                break;
            case 'update_status':
                $targetUserId = (int)$_POST['user_id'];
                $newStatus = $_POST['status'];
                if (updateUserStatus($pdo, $targetUserId, $newStatus)) {
                    $_SESSION['message'] = 'User status updated successfully.';
                }
                break;
            case 'delete_user':
                $targetUserId = (int)$_POST['user_id'];
                // Prevent admin from deleting themselves
                if ($targetUserId == $userId) {
                    $_SESSION['message'] = 'Error: You cannot delete your own account.';
                } else {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        if ($stmt->execute([$targetUserId])) {
                            $_SESSION['message'] = 'User deleted successfully.';
                        } else {
                            $_SESSION['message'] = 'Failed to delete user.';
                        }
                    } catch (PDOException $e) {
                        $_SESSION['message'] = 'Database error: ' . $e->getMessage();
                    }
                }
                break;
            case 'update_profile':
                $targetUserId = (int)$_POST['user_id'];
                $data = [
                    'firstname' => sanitize($_POST['firstname']),
                    'middlename' => sanitize($_POST['middlename']),
                    'lastname' => sanitize($_POST['lastname']),
                    'suffix' => sanitize($_POST['suffix']),
                    'phone' => sanitize($_POST['phone']),
                    'address' => sanitize($_POST['address']),
                    'birthdate' => sanitize($_POST['birthdate'])
                ];
                if (updateUserProfile($pdo, $targetUserId, $data)) {
                    $_SESSION['message'] = 'User profile updated successfully.';
                }
                break;
            case 'create_user':
                $username = sanitize($_POST['username']);
                $email = sanitize($_POST['email']);
                $password = $_POST['password'];
                $firstname = sanitize($_POST['firstname']);
                $middlename = sanitize($_POST['middlename']);
                $lastname = sanitize($_POST['lastname']);
                $suffix = sanitize($_POST['suffix']);
                $phone = sanitize($_POST['phone']);
                $address = sanitize($_POST['address']);
                $birthdate = sanitize($_POST['birthdate']);
                $role = sanitize($_POST['role']);
                $status = sanitize($_POST['status']);
                
                // Validation
                $errors = [];
                if (empty($username)) $errors[] = 'Username is required.';
                if (empty($email)) $errors[] = 'Email is required.';
                if (empty($password)) $errors[] = 'Password is required.';
                if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
                if (empty($firstname)) $errors[] = 'First name is required.';
                if (empty($lastname)) $errors[] = 'Last name is required.';
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
                if (!empty($birthdate) && !validateBirthdate($birthdate)) {
                    $errors[] = 'User must be at least 15 years old.';
                }
                
                // Check if username exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) $errors[] = 'Username already exists.';
                
                // Check if email exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) $errors[] = 'Email already exists.';
                
                if (empty($errors)) {
                    $hashedPassword = hashPassword($password);
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, firstname, middlename, lastname, suffix, phone, address, birthdate, role, status) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt->execute([$username, $email, $hashedPassword, $firstname, $middlename, $lastname, $suffix, $phone, $address, $birthdate, $role, $status])) {
                        $_SESSION['message'] = 'User created successfully!';
                    } else {
                        $_SESSION['message'] = 'Failed to create user.';
                    }
                } else {
                    $_SESSION['message'] = 'Error: ' . implode('<br>', $errors);
                }
                // Preserve current page after redirect
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                redirect('user_management.php?page=' . $page);
                break;
        }
        // Preserve page for other actions as well
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        redirect('user_management.php?page=' . $page);
    }
}

// ===== PAGINATION SETUP =====
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$limit = 10; // items per page
$offset = ($currentPage - 1) * $limit;

// Get total count of users (excluding current user and pending)
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id != ? AND status != 'pending'");
$countStmt->execute([getUserId()]);
$totalUsers = $countStmt->fetchColumn();
$totalPages = ceil($totalUsers / $limit);

// Fetch users for current page – BIND AS INTEGERS to avoid SQL error
$stmt = $pdo->prepare("SELECT * FROM users WHERE id != ? AND status != 'pending' ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, getUserId(), PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

// Get messages
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// Role options
$roles = ['admin', 'coordinator', 'supervisor', 'student'];
$statuses = ['active', 'inactive'];

// Current profile picture
$profilePicture = getUserProfilePicture($pdo, $userId);
$profilePictureUrl = $profilePicture ? $avatarPublicPath . $profilePicture : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <style>
        /* ============================================================
           Dark Green (#003300) & Golden Yellow (#FFCC33) theme
           Sharp card edges, no rounded corners.
           Header spans full width, flush with top.
           ============================================================ */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: #f0f2f5;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: #0f172a;
        }

        .app-shell {
            display: flex;
            min-height: 100vh;
        }

        /* ---- Dark Green Sidebar (nav only) ---- */
        .sidebar {
            width: 250px;
            background: #003300;
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            padding: 28px 18px 20px;
            flex-shrink: 0;
            border-right: 1px solid #1a4a1a;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
            padding: 0 6px;
        }

        .sidebar-brand i {
            font-size: 1.6rem;
            color: #FFCC33;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: #FFCC33;
        }

        .sidebar-brand h2 span {
            display: block;
            font-weight: 400;
            font-size: 0.65rem;
            color: #FFCC33;
            opacity: 0.8;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        /* ---- Navigation ---- */
        .nav-section {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 0;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.15s;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }

        .nav-item:hover {
            background: rgba(255, 204, 51, 0.15);
            color: #fff;
        }

        .nav-item.active {
            background: #FFCC33;
            color: #003300;
            font-weight: 600;
        }

        .nav-item.active i {
            color: #003300;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid rgba(255, 204, 51, 0.3);
            padding-top: 18px;
        }

        .logout-btn-side {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 0;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: 0.15s;
        }

        .logout-btn-side i {
            width: 20px;
            text-align: center;
        }

        .logout-btn-side:hover {
            background: rgba(255, 204, 51, 0.2);
            color: #fff;
        }

        /* ---- Main content ---- */
        .main-content {
            flex: 1;
            padding: 0 32px 32px 32px;
            display: flex;
            flex-direction: column;
        }

        /* ---- Dark Green Top Header (full width, flush) ---- */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 32px;
            background: #003300;
            margin: 0 -32px 24px -32px;
            flex-wrap: wrap;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 200;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 28px;
            flex-wrap: wrap;
        }

        .header-left h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #FFCC33;
            letter-spacing: -0.3px;
            white-space: nowrap;
        }

        .header-left h1 small {
            font-weight: 400;
            font-size: 0.85rem;
            color: #FFCC33;
            opacity: 0.8;
            margin-left: 8px;
        }

        .header-left h1 i {
            color: #FFCC33;
            margin-right: 8px;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: #FFCC33;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 4px 8px;
        }

        /* ---- Header right ---- */
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
            justify-content: flex-end;
        }

        .notif-bell {
            position: relative;
            font-size: 1.3rem;
            color: #FFCC33;
            background: rgba(255, 204, 51, 0.2);
            width: 44px;
            height: 44px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.15s;
            cursor: pointer;
            border: none;
            flex-shrink: 0;
        }

        .notif-bell:hover {
            background: rgba(255, 204, 51, 0.4);
            color: #fff;
        }

        .notif-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #ef4444;
            color: #fff;
            font-size: 0.6rem;
            font-weight: 700;
            width: 20px;
            height: 20px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #003300;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: default;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 0;
            background: #FFCC33;
            color: #003300;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            text-transform: uppercase;
            border: 2px solid #FFCC33;
        }

        .user-info .name {
            font-weight: 600;
            color: #FFCC33;
            font-size: 0.9rem;
        }

        .user-info .role-label {
            font-size: 0.7rem;
            color: #cbd5e1;
            font-weight: 500;
        }

        /* ---- Page content (compressed) ---- */
        .container {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .page-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 20px 24px 28px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            border-radius: 0;
        }

        .page-card .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .page-card .section-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-card .section-header h2 i {
            color: #3b82f6;
        }

        .page-card .section-header .badge-count {
            display: inline-flex;
            align-items: center;
            padding: 2px 12px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            font-size: 0.75rem;
            font-weight: 600;
            color: #475569;
            border-radius: 0;
        }

        .btn-create {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.8rem;
            background: #0f172a;
            color: #ffffff;
            border: 1px solid #0f172a;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .btn-create:hover {
            background: #1e293b;
        }

        /* ---- Tables (compressed) ---- */
        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
            min-height: 320px; /* Added min-height */
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .user-table th {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 600;
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .user-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .user-table tbody tr:last-child td {
            border-bottom: none;
        }

        .user-table tbody tr:hover {
            background: #fafcff;
        }

        .user-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.82rem;
        }

        .user-info {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 1px;
        }

        /* ---- Form Elements (compressed) ---- */
        .inline-form {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin: 0;
        }

        .inline-form select {
            padding: 4px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 0;
            font-size: 0.7rem;
            background: #fff;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: #0f172a;
            min-width: 80px;
        }

        .inline-form select:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .inline-form button {
            padding: 4px 10px;
            border-radius: 0;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid transparent;
        }

        .btn-edit {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .btn-edit:hover {
            background: #bfdbfe;
            transform: scale(1.02);
        }

        .btn-view {
            background: #eef2ff;
            color: #4338ca;
            border-color: #a5b4fc;
            padding: 4px 10px;
        }

        .btn-view:hover {
            background: #c7d2fe;
            transform: scale(1.02);
        }

        .btn-success {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .btn-success:hover {
            background: #bbf7d0;
            transform: scale(1.02);
        }

        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .btn-danger:hover {
            background: #fecaca;
            transform: scale(1.02);
        }

        .actions-wrap {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* ---- Pending Section ---- */
        .pending-section {
            border-left: 3px solid #f59e0b;
        }

        /* ---- Empty State ---- */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 2rem;
            display: block;
            margin-bottom: 8px;
            color: #cbd5e1;
        }

        .empty-state p {
            font-size: 0.85rem;
        }

        /* ===== PAGINATION (bottom right) ===== */
        .pagination-wrapper {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-top: 16px;
            gap: 6px;
            flex-wrap: wrap;
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
        }

        .pagination-wrapper .page-info {
            font-size: 0.8rem;
            color: #64748b;
            margin-right: 12px;
        }

        .pagination-wrapper .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #1e293b;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            transition: 0.15s;
            min-width: 36px;
            border-radius: 0;
        }

        .pagination-wrapper .page-link:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .pagination-wrapper .page-link.active {
            background: #003300;
            color: #FFCC33;
            border-color: #003300;
            pointer-events: none;
        }

        .pagination-wrapper .page-link.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* ===== MODAL STYLES (sharp) ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-container {
            background: #fff;
            border: 1px solid #e2e8f0;
            max-width: 600px;
            width: 100%;
            padding: 0;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.3);
            animation: slideUp 0.25s ease;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            border-radius: 0;
        }

        #passwordModal .modal-container {
            max-width: 480px;
        }

        @keyframes slideUp {
            0% {
                transform: translateY(30px);
                opacity: 0.6;
            }
            100% {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px 14px 24px;
            border-bottom: 1px solid #f1f5f9;
            flex-shrink: 0;
        }

        .modal-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h2 i {
            color: #2563eb;
        }

        .modal-close-btn {
            background: none;
            border: none;
            font-size: 1.6rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 6px;
            transition: 0.15s;
            line-height: 1;
        }

        .modal-close-btn:hover {
            color: #1e293b;
        }

        .modal-body {
            padding: 16px 24px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-body .subtitle {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-weight: 500;
            color: #334155;
            font-size: 0.8rem;
            margin-bottom: 4px;
        }

        .form-group label .required {
            color: #dc2626;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 0;
            font-size: 0.85rem;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: #f8fafc;
            transition: 0.15s;
            width: 100%;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 50px;
        }

        .password-actions {
            display: flex;
            gap: 6px;
            margin-top: 4px;
        }

        .password-actions button {
            padding: 4px 12px;
            border-radius: 0;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid #e2e8f0;
            background: #f1f5f9;
            color: #1e293b;
        }

        .password-actions button:hover {
            background: #e9edf4;
        }

        .modal-footer {
            padding: 14px 24px 20px 24px;
            border-top: 1px solid #f1f5f9;
            flex-shrink: 0;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-primary {
            background: #0f172a;
            border: 1px solid #0f172a;
            color: #fff;
            padding: 8px 22px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary:hover:not(:disabled) {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .btn-secondary {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            padding: 8px 20px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .btn-secondary:hover {
            background: #e9edf4;
        }

        .btn-submit {
            width: 100%;
            padding: 10px;
            background: #0f172a;
            color: #fff;
            border: 1px solid #0f172a;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border-radius: 0;
            margin-top: 8px;
        }

        .btn-submit:hover {
            background: #1e293b;
        }

        /* ---- Profile Sidebar ---- */
        .profile-sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .profile-sidebar-overlay.open {
            display: block;
        }

        #profileSidebar {
            position: fixed;
            top: 0;
            right: -380px;
            width: 380px;
            height: 100vh;
            background: #fff;
            border-left: 1px solid #e2e8f0;
            padding: 24px;
            z-index: 1001;
            transition: right 0.3s ease;
            overflow-y: auto;
            box-shadow: -10px 0 30px rgba(0,0,0,0.05);
        }

        #profileSidebar.open {
            right: 0;
        }

        #profileSidebar .modal-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 8px;
            transition: 0.15s;
            line-height: 1;
        }

        #profileSidebar .modal-close:hover {
            color: #1e293b;
        }

        #profileSidebar h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }

        #profileSidebar hr {
            border: none;
            border-top: 1px solid #f1f5f9;
            margin: 12px 0;
        }

        #profileSidebar .profile-detail {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 0.85rem;
            border-bottom: 1px solid #f8fafc;
        }

        #profileSidebar .profile-detail .label {
            color: #64748b;
            font-weight: 500;
        }

        #profileSidebar .profile-detail .value {
            color: #0f172a;
            font-weight: 500;
            text-align: right;
            word-break: break-word;
            max-width: 60%;
        }

        /* ---- Toast ---- */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #0f172a;
            color: #f1f5f9;
            padding: 14px 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: none;
            align-items: center;
            gap: 10px;
            z-index: 2000;
            font-weight: 500;
            max-width: 400px;
            animation: slideUp 0.3s ease;
            border: 1px solid #334155;
            border-radius: 0;
        }

        .toast.success {
            background: #059669;
            border-color: #047857;
        }

        .toast.error {
            background: #dc2626;
            border-color: #b91c1c;
        }

        .toast.warning {
            background: #d97706;
            border-color: #b45309;
        }

        .toast.show {
            display: flex;
        }

        .toast i {
            font-size: 1.1rem;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* ---- Responsive ---- */
        @media (max-width: 1024px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .form-row .form-group {
                margin-bottom: 0;
            }
            .page-card .section-header {
                flex-direction: column;
                align-items: stretch;
            }
            .btn-create {
                justify-content: center;
            }
            #profileSidebar {
                width: 320px;
                right: -320px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -280px;
                width: 280px;
                height: 100vh;
                z-index: 1001;
                transition: left 0.3s ease;
                overflow-y: auto;
            }

            .sidebar.open {
                left: 0;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .top-header {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 16px;
                margin: 0 -16px 16px -16px;
            }

            .header-left {
                flex-direction: row;
                align-items: center;
                gap: 12px;
                justify-content: space-between;
                width: 100%;
            }

            .header-left h1 {
                font-size: 1.1rem;
            }

            .header-right {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                justify-content: center;
                width: 100%;
            }

            .notif-bell {
                align-self: center;
            }

            .user-profile {
                justify-content: center;
            }

            .page-card {
                padding: 14px;
            }

            .user-table th,
            .user-table td {
                padding: 6px 8px;
                font-size: 0.72rem;
            }

            .user-name {
                font-size: 0.72rem;
            }

            .user-info {
                font-size: 0.65rem;
            }

            .inline-form select {
                font-size: 0.65rem;
                padding: 3px 6px;
                min-width: 60px;
            }

            .inline-form button {
                font-size: 0.6rem;
                padding: 3px 8px;
            }

            .modal-container {
                max-height: 95vh;
                margin: 10px;
            }

            .modal-header {
                padding: 14px 16px 10px 16px;
            }

            .modal-body {
                padding: 12px 16px;
            }

            .modal-footer {
                padding: 10px 16px 14px 16px;
                flex-direction: column;
            }

            .modal-footer .btn-primary,
            .modal-footer .btn-secondary {
                width: 100%;
                justify-content: center;
            }

            #profileSidebar {
                width: 280px;
                right: -280px;
                padding: 16px;
            }

            .actions-wrap {
                flex-direction: column;
                align-items: stretch;
            }

            .actions-wrap form,
            .actions-wrap button {
                width: 100%;
            }

            .actions-wrap .inline-form {
                width: 100%;
            }

            .password-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .user-table th,
            .user-table td {
                padding: 4px 6px;
                font-size: 0.65rem;
            }

            .user-name {
                font-size: 0.65rem;
            }

            .inline-form select {
                font-size: 0.6rem;
                padding: 2px 4px;
                min-width: 50px;
            }

            .inline-form button {
                font-size: 0.55rem;
                padding: 2px 6px;
            }

            .btn-create {
                font-size: 0.75rem;
                padding: 6px 12px;
            }

            #profileSidebar {
                width: 100%;
                right: -100%;
            }

            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }

            .user-info .name {
                font-size: 0.75rem;
            }

            .user-info .role-label {
                font-size: 0.6rem;
            }
        }

        /* ===== PASSWORD MODAL ===== */
        #passwordModal .modal-container {
            max-width: 480px;
        }

        #passwordModal .form-group {
            margin-bottom: 14px;
        }

        #passwordModal .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.82rem;
            color: #1e293b;
            margin-bottom: 4px;
        }

        #passwordModal .form-group label i {
            margin-right: 6px;
            color: #64748b;
        }

        #passwordModal .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.9rem;
            background: #fafcff;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        #passwordModal .form-group input:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        #passwordModal .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            border-top: 1px solid #edf2f7;
            padding-top: 16px;
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <!-- Sidebar Overlay for mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- SIDEBAR: Navigation only -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-shield-alt"></i>
                <h2>Admin<span>Panel</span></h2>
            </div>
            <nav class="nav-section">
                <a class="nav-item " href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item active" href="user_management.php"><i class="fa-solid fa-users-gear"></i> User Management</a>
                <a class="nav-item" href="pending.php"><i class="fa-solid fa-clock-rotate-left"></i> Pending</a>
                <a class="nav-item" href="company.php"><i class="fa-solid fa-building"></i> Company</a>
            </nav>
            <div class="sidebar-footer">
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <!-- HEADER: full width, dark green, flush with top -->
            <div class="top-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle" id="menuToggle" aria-label="Toggle menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <h1>
                        <i class="fa-solid fa-users-gear"></i>
                        User Management
                        <small>Admin</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Notification bell -->
                    <button class="notif-bell" onclick="alert('No new notifications')" aria-label="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <span class="notif-badge">3</span>
                    </button>

                    <!-- User Profile -->
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php
                                $initials = '';
                                $parts = explode(' ', trim($fullname));
                                if (count($parts) >= 2) {
                                    $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
                                } else {
                                    $initials = strtoupper(substr($fullname, 0, 2));
                                }
                                echo htmlspecialchars($initials);
                            ?>
                        </div>
                        <div class="user-info">
                            <div class="name"><?php echo htmlspecialchars($fullname); ?></div>
                            <div class="role-label"><?php echo htmlspecialchars(getRoleDisplayName($role)); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PAGE CONTENT -->
            <div class="container">
                <!-- All Users Section -->
                <div class="page-card">
                    <div class="section-header">
                        <h2><i class="fa-regular fa-users"></i> All Users <span class="badge-count"><?php echo $totalUsers; ?></span></h2>
                        <button class="btn-create" onclick="openModal()"><i class="fa-solid fa-plus"></i> Create User</button>
                    </div>

                    <div class="table-wrap">
                        <table class="user-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Created</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="8" class="empty-state">
                                            <i class="fa-regular fa-users"></i>
                                            <p>No users found.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo $user['id']; ?></td>
                                            <td>
                                                <div class="user-name"><?php echo htmlspecialchars(getFullName($user)); ?></div>
                                                <div class="user-info">@<?php echo htmlspecialchars($user['username']); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <select name="role">
                                                        <?php foreach ($roles as $role): ?>
                                                            <option value="<?php echo $role; ?>" <?php echo $user['role'] === $role ? 'selected' : ''; ?>>
                                                                <?php echo ucfirst($role); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="action" value="update_role" class="btn-edit" title="Update Role"><i class="fa-solid fa-pen-to-square"></i></button>
                                                </form>
                                            </td>
                                            <td>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <select name="status">
                                                        <?php foreach ($statuses as $status): ?>
                                                            <option value="<?php echo $status; ?>" <?php echo $user['status'] === $status ? 'selected' : ''; ?>>
                                                                <?php echo ucfirst($status); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="action" value="update_status" class="btn-edit" title="Update Status"><i class="fa-solid fa-pen-to-square"></i></button>
                                                </form>
                                            </td>
                                            <td>
                                                <div class="actions-wrap">
                                                    <button onclick="openProfile('<?php echo rawurlencode(json_encode($user)); ?>')" class="btn-view" title="View Profile"><i class="fa-solid fa-eye"></i></button>
                                                    <form method="POST" class="inline-form" onsubmit="return confirmDelete(this, <?php echo $user['id']; ?>, '<?php echo addslashes(htmlspecialchars(getFullName($user))); ?>');">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" name="action" value="delete_user" class="btn-danger" title="Delete User"><i class="fa-solid fa-trash-can"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ===== PAGINATION (always visible) ===== -->
                    <div class="pagination-wrapper">
                        <span class="page-info">
                            <?php if ($totalUsers > 0): ?>
                                Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $limit, $totalUsers); ?> of <?php echo $totalUsers; ?>
                            <?php else: ?>
                                No users to display
                            <?php endif; ?>
                        </span>
                        <?php
                        // Previous link
                        if ($currentPage > 1) {
                            echo '<a href="?page=' . ($currentPage - 1) . '" class="page-link">Prev</a>';
                        } else {
                            echo '<span class="page-link disabled">Prev</span>';
                        }

                        // Page numbers (only if there are pages)
                        if ($totalPages > 0) {
                            $start = max(1, $currentPage - 2);
                            $end = min($totalPages, $currentPage + 2);
                            if ($start > 1) {
                                echo '<a href="?page=1" class="page-link">1</a>';
                                if ($start > 2) echo '<span class="page-link disabled">…</span>';
                            }
                            for ($i = $start; $i <= $end; $i++) {
                                $active = ($i == $currentPage) ? 'active' : '';
                                echo '<a href="?page=' . $i . '" class="page-link ' . $active . '">' . $i . '</a>';
                            }
                            if ($end < $totalPages) {
                                if ($end < $totalPages - 1) echo '<span class="page-link disabled">…</span>';
                                echo '<a href="?page=' . $totalPages . '" class="page-link">' . $totalPages . '</a>';
                            }
                        }

                        // Next link
                        if ($currentPage < $totalPages) {
                            echo '<a href="?page=' . ($currentPage + 1) . '" class="page-link">Next</a>';
                        } else {
                            echo '<span class="page-link disabled">Next</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Create User Modal -->
    <div id="createUserModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h2><i class="fa-solid fa-user-plus"></i> Create New User</h2>
                <button type="button" class="modal-close-btn" onclick="closeModal()">&times;</button>
            </div>
            
            <form method="POST" action="" class="modal-body">
                <input type="hidden" name="action" value="create_user">
                
                <div class="subtitle"><i class="fa-regular fa-circle-info"></i> Fill in the user's account and personal details.</div>
                
                <h3 style="color: #0f172a; margin-bottom: 10px; font-size: 0.9rem; font-weight: 600;">Account Information</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="username">Username <span class="required">*</span></label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email <span class="required">*</span></label>
                        <input type="email" id="email" name="email" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password <span class="required">*</span></label>
                        <input type="password" id="password" name="password" required>
                        <div class="password-actions">
                            <button type="button" onclick="generatePassword()">Generate</button>
                            <button type="button" onclick="togglePasswordVisibility()">Show/Hide</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>
                
                <h3 style="color: #0f172a; margin: 16px 0 10px 0; font-size: 0.9rem; font-weight: 600;">Personal Information</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="firstname">First Name <span class="required">*</span></label>
                        <input type="text" id="firstname" name="firstname" required>
                    </div>
                    <div class="form-group">
                        <label for="middlename">Middle Name</label>
                        <input type="text" id="middlename" name="middlename">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="lastname">Last Name <span class="required">*</span></label>
                        <input type="text" id="lastname" name="lastname" required>
                    </div>
                    <div class="form-group">
                        <label for="suffix">Suffix</label>
                        <input type="text" id="suffix" name="suffix" placeholder="Jr., Sr., II, III">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" placeholder="+63 912 345 6789">
                    </div>
                    <div class="form-group">
                        <label for="birthdate">Birthdate</label>
                        <input type="date" id="birthdate" name="birthdate">
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="2"></textarea>
                </div>
                
                <h3 style="color: #0f172a; margin: 16px 0 10px 0; font-size: 0.9rem; font-weight: 600;">Role & Status</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="role">Role <span class="required">*</span></label>
                        <select id="role" name="role" required>
                            <option value="student">Student</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="coordinator">Coordinator</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status">Status <span class="required">*</span></label>
                        <select id="status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit"><i class="fa-solid fa-save"></i> Create User</button>
            </form>
        </div>
    </div>

    <!-- Profile Sidebar -->
    <div id="profileSidebarOverlay" class="profile-sidebar-overlay"></div>
    <aside id="profileSidebar">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h2><i class="fa-regular fa-user"></i> User Profile</h2>
            <button class="modal-close" onclick="closeProfile()">&times;</button>
        </div>
        <div id="profileSidebarContent"></div>
    </aside>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2><i class="fa-solid fa-key"></i> Change Password</h2>
                <button type="button" class="modal-close-btn" id="closePasswordBtn">&times;</button>
            </div>
            <form id="passwordForm" class="modal-body">
                <div class="subtitle">Enter your current password and choose a new one.</div>
                <div class="form-group">
                    <label for="currentPassword"><i class="fa-solid fa-lock"></i> Current Password</label>
                    <input type="password" id="currentPassword" autocomplete="current-password" required />
                </div>
                <div class="form-group">
                    <label for="newPassword"><i class="fa-solid fa-lock"></i> New Password</label>
                    <input type="password" id="newPassword" autocomplete="new-password" minlength="8" required />
                </div>
                <div class="form-group">
                    <label for="confirmPassword"><i class="fa-solid fa-lock"></i> Confirm New Password</label>
                    <input type="password" id="confirmPassword" autocomplete="new-password" minlength="8" required />
                </div>
            </form>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="closePasswordBtn2">Cancel</button>
                <button type="submit" form="passwordForm" class="btn-primary"><i class="fa-solid fa-check"></i> Update Password</button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <script>
        function openProfile(userJson) {
            try {
                const decoded = decodeURIComponent(userJson);
                const user = JSON.parse(decoded);
                const content = document.getElementById('profileSidebarContent');
                content.innerHTML = `
                    <div style="text-align:center;margin-bottom:16px;">
                        <div style="width:80px;height:80px;border-radius:50%;background:#003300;color:#FFCC33;display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;margin:0 auto 8px;border:3px solid #FFCC33;text-transform:uppercase;">
                            ${getFullNameJS(user).split(' ').map(n=>n[0]).join('').substring(0,2) || 'U'}
                        </div>
                        <div style="font-weight:700;font-size:1.1rem;color:#0f172a;">${escapeHtml(getFullNameJS(user))}</div>
                        <div style="color:#94a3b8;font-size:0.85rem;">@${escapeHtml(user.username || '')}</div>
                    </div>
                    <hr>
                    <div style="font-size:0.85rem;color:#334155;">
                        <div class="profile-detail"><span class="label">First Name</span><span class="value">${escapeHtml(user.firstname || '')}</span></div>
                        <div class="profile-detail"><span class="label">Middle Name</span><span class="value">${escapeHtml(user.middlename || 'N/A')}</span></div>
                        <div class="profile-detail"><span class="label">Last Name</span><span class="value">${escapeHtml(user.lastname || '')}</span></div>
                        <div class="profile-detail"><span class="label">Suffix</span><span class="value">${escapeHtml(user.suffix || 'N/A')}</span></div>
                        <div class="profile-detail"><span class="label">Phone</span><span class="value">${escapeHtml(user.phone || 'N/A')}</span></div>
                        <div class="profile-detail"><span class="label">Address</span><span class="value">${escapeHtml(user.address || 'N/A')}</span></div>
                        <div class="profile-detail"><span class="label">Birthdate</span><span class="value">${escapeHtml(user.birthdate || 'N/A')}</span></div>
                        <div class="profile-detail"><span class="label">Email</span><span class="value">${escapeHtml(user.email || '')}</span></div>
                        <div class="profile-detail"><span class="label">Role</span><span class="value"><span style="text-transform:capitalize;">${escapeHtml(user.role || '')}</span></span></div>
                        <div class="profile-detail"><span class="label">Status</span><span class="value"><span style="text-transform:capitalize;">${escapeHtml(user.status || '')}</span></span></div>
                        <div class="profile-detail"><span class="label">Created</span><span class="value">${escapeHtml(user.created_at || '')}</span></div>
                    </div>
                `;
                document.getElementById('profileSidebar').classList.add('open');
                document.getElementById('profileSidebarOverlay').classList.add('open');
                document.body.style.overflow = 'hidden';
            } catch (e) {
                console.error('Error parsing user data', e);
            }
        }

        function closeProfile() {
            document.getElementById('profileSidebar').classList.remove('open');
            document.getElementById('profileSidebarOverlay').classList.remove('open');
            document.body.style.overflow = 'auto';
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"'`]/g, function (s) {
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','`':'&#96;'})[s];
            });
        }

        function getFullNameJS(user) {
            return [user.firstname, user.middlename, user.lastname, user.suffix].filter(Boolean).join(' ');
        }
        
        function openModal() {
            document.getElementById('createUserModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            document.getElementById('createUserModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('createUserModal');
            if (event.target === modal) closeModal();
            const overlay = document.getElementById('profileSidebarOverlay');
            if (event.target === overlay) closeProfile();
        }
        
        function generatePassword() {
            const length = 12;
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let password = "";
            for (let i = 0; i < length; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            document.getElementById('password').value = password;
            document.getElementById('confirm_password').value = password;
        }
        
        function togglePasswordVisibility() {
            const passwordField = document.getElementById('password');
            const confirmField = document.getElementById('confirm_password');
            const type = passwordField.type === 'password' ? 'text' : 'password';
            passwordField.type = type;
            confirmField.type = type;
        }

        // ===== TOAST =====
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            const icon = toast.querySelector('i');
            if (type === 'success') {
                icon.className = 'fa-regular fa-circle-check';
            } else if (type === 'error') {
                icon.className = 'fa-regular fa-circle-xmark';
            }
            
            toast.className = 'toast ' + type + ' show';
            toastMessage.textContent = message;
            
            clearTimeout(toast._timeout);
            toast._timeout = setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        <?php if ($message): 
            $isError = strpos($message, 'Error:') !== false || strpos($message, 'Failed') !== false;
        ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo htmlspecialchars($message); ?>', '<?php echo $isError ? 'error' : 'success'; ?>');
            });
        <?php endif; ?>

        document.getElementById('toast').addEventListener('click', function() {
            this.classList.remove('show');
        });

        // ===== MOBILE MENU TOGGLE =====
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        if (menuToggle) {
            menuToggle.addEventListener('click', toggleSidebar);
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeSidebar);
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });

        // ===== PASSWORD MODAL =====
        const passwordModal = document.getElementById('passwordModal');
        const openPasswordBtn = document.getElementById('openPasswordModalBtn');
        const closePasswordBtn = document.getElementById('closePasswordBtn');
        const closePasswordBtn2 = document.getElementById('closePasswordBtn2');
        const passwordForm = document.getElementById('passwordForm');

        if (openPasswordBtn) {
            openPasswordBtn.addEventListener('click', function() {
                if (passwordModal) {
                    passwordModal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                    if (passwordForm) passwordForm.reset();
                }
            });
        }

        function closePasswordModal() {
            if (passwordModal) {
                passwordModal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        if (closePasswordBtn) closePasswordBtn.addEventListener('click', closePasswordModal);
        if (closePasswordBtn2) closePasswordBtn2.addEventListener('click', closePasswordModal);
        if (passwordModal) {
            passwordModal.addEventListener('click', function(e) {
                if (e.target === passwordModal) closePasswordModal();
            });
        }

        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var currentPassword = document.getElementById('currentPassword').value;
                var newPassword = document.getElementById('newPassword').value;
                var confirmPassword = document.getElementById('confirmPassword').value;

                if (newPassword !== confirmPassword) {
                    showToast('New password and confirmation do not match.', 'error');
                    return;
                }
                if (newPassword.length < 8) {
                    showToast('New password must be at least 8 characters.', 'error');
                    return;
                }

                var submitBtn = passwordForm.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating...';
                }

                var formData = new FormData();
                formData.append('action', 'change_password');
                formData.append('current_password', currentPassword);
                formData.append('new_password', newPassword);
                formData.append('confirm_password', confirmPassword);

                fetch(window.location.href, { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            showToast(data.message || 'Password updated successfully.', 'success');
                            closePasswordModal();
                        } else {
                            showToast(data.message || 'Failed to update password.', 'error');
                        }
                    })
                    .catch(function() {
                        showToast('An error occurred. Please try again.', 'error');
                    })
                    .finally(function() {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> Update Password';
                        }
                    });
            });
        }

        // ===== DELETE CONFIRMATION =====
        function confirmDelete(form, userId, userName) {
            if (!confirm('Are you sure you want to delete user "' + userName + '" (ID: ' + userId + ')? This action cannot be undone.')) {
                return false;
            }
            return true;
        }

        // ===== AVATAR UPLOAD =====
        var avatarEditable = document.getElementById('avatarEditable');
        var avatarInput = document.getElementById('avatarInput');
        var avatarImgWrap = document.getElementById('avatarImgWrap');

        if (avatarEditable && avatarInput) {
            avatarEditable.addEventListener('click', function() {
                avatarInput.click();
            });

            avatarInput.addEventListener('change', function() {
                var file = avatarInput.files[0];
                if (!file) return;

                if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
                    showToast('Only JPG, PNG, WEBP or GIF images are allowed.', 'error');
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    showToast('Image must be smaller than 2MB.', 'error');
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'update_avatar');
                formData.append('avatar', file);

                fetch(window.location.href, { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            showToast('Profile picture updated.', 'success');
                            if (avatarImgWrap && data.path) {
                                avatarImgWrap.className = 'avatar-img';
                                avatarImgWrap.innerHTML = '<img src="' + data.path + '?t=' + Date.now() + '" alt="Profile photo" id="avatarImg" />';
                            }
                        } else {
                            showToast(data.message || 'Failed to update profile picture.', 'error');
                        }
                    })
                    .catch(function() {
                        showToast('An error occurred while uploading. Please try again.', 'error');
                    });
            });
        }

        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (document.getElementById('createUserModal').classList.contains('active')) {
                    closeModal();
                }
                if (document.getElementById('profileSidebar').classList.contains('open')) {
                    closeProfile();
                }
                closePasswordModal();
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
            }
        });
    </script>
</body>
</html>