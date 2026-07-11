<?php
// admin/user_management.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is admin
checkAccess('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_role':
                $userId = (int)$_POST['user_id'];
                $newRole = $_POST['role'];
                if (updateUserRole($pdo, $userId, $newRole)) {
                    $_SESSION['message'] = 'User role updated successfully.';
                }
                break;
            case 'update_status':
                $userId = (int)$_POST['user_id'];
                $newStatus = $_POST['status'];
                if (updateUserStatus($pdo, $userId, $newStatus)) {
                    $_SESSION['message'] = 'User status updated successfully.';
                }
                break;
            case 'approve_user':
                $userId = (int)$_POST['user_id'];
                if (approveUser($pdo, $userId)) {
                    $_SESSION['message'] = 'User approved successfully.';
                }
                break;
            case 'decline_user':
                $userId = (int)$_POST['user_id'];
                if (declineUser($pdo, $userId)) {
                    $_SESSION['message'] = 'User declined and removed.';
                }
                break;
            case 'update_profile':
                $userId = (int)$_POST['user_id'];
                $data = [
                    'firstname' => sanitize($_POST['firstname']),
                    'middlename' => sanitize($_POST['middlename']),
                    'lastname' => sanitize($_POST['lastname']),
                    'suffix' => sanitize($_POST['suffix']),
                    'phone' => sanitize($_POST['phone']),
                    'address' => sanitize($_POST['address']),
                    'birthdate' => sanitize($_POST['birthdate'])
                ];
                if (updateUserProfile($pdo, $userId, $data)) {
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
                redirect('user_management.php');
                break;
        }
        redirect('user_management.php');
    }
}

// Get all users except unapproved pending accounts
$stmt = $pdo->query("SELECT * FROM users WHERE id != " . getUserId() . " AND status != 'pending' ORDER BY created_at DESC");
$users = $stmt->fetchAll();

// Get pending users
$pendingUsers = getPendingUsers($pdo);

// Get messages
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// Role options
$roles = ['admin', 'coordinator', 'supervisor', 'student'];
$statuses = ['active', 'inactive'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        /* ----- reset & base ----- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
        }

        /* ----- SIDEBAR ----- */
        .sidebar {
            width: 250px;
            background: #0f172a;
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            padding: 24px 18px 20px;
            flex-shrink: 0;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
        }

        .sidebar-brand i {
            font-size: 1.6rem;
            color: #38bdf8;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: #ffffff;
        }

        .sidebar-brand h2 span {
            display: block;
            font-weight: 400;
            font-size: 0.65rem;
            color: #94a3b8;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .nav-section {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 12px;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.15s;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }

        .nav-item:hover {
            background: #1e293b;
            color: #f1f5f9;
        }

        .nav-item.active {
            background: #1e293b;
            color: #38bdf8;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid #1e293b;
            padding-top: 18px;
        }

        .logout-btn-side {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 12px;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: 0.15s;
        }

        .logout-btn-side:hover {
            background: #1e293b;
            color: #f1f5f9;
        }

        /* ----- MAIN CONTENT ----- */
        .main-content {
            flex: 1;
            padding: 0 32px 32px 32px;
            display: flex;
            flex-direction: column;
        }

        /* ----- TOP HEADER (blue theme matching sidebar) ----- */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 32px;
            background: #0f172a;
            border-radius: 0;
            margin: 0 -32px 24px -32px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-left h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #f8fafc;
            letter-spacing: -0.3px;
        }

        .header-left h1 small {
            font-weight: 400;
            font-size: 0.85rem;
            color: #94a3b8;
            margin-left: 8px;
        }

        .header-left h1 i {
            color: #38bdf8;
            margin-right: 8px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Notification bell */
        .notif-bell {
            position: relative;
            color: #eef5ff;
            background: #1a2f58;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.18s ease;
            cursor: pointer;
            border: 1px solid rgba(255,255,255,0.18);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), 0 8px 18px rgba(15, 23, 42, 0.3);
            -webkit-tap-highlight-color: transparent;
        }

        .notif-bell i {
            font-size: 1.12rem;
        }

        .notif-bell:hover {
            background: #203867;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .notif-bell:active {
            transform: translateY(0);
        }

        .notif-bell:focus-visible {
            outline: 2px solid #7dd3fc;
            outline-offset: 2px;
        }

        .notif-badge {
            position: absolute;
            top: -5px;
            right: -6px;
            background: #ef4444;
            color: #fff;
            font-size: 0.64rem;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #1a2f58;
            line-height: 1;
        }

        /* User profile chip */
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.08);
            padding: 4px 16px 4px 6px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.12);
            cursor: default;
            backdrop-filter: blur(2px);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3b82f6;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .user-info .name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #f1f5f9;
        }

        .user-info .role-label {
            font-size: 0.7rem;
            color: #94a3b8;
            font-weight: 500;
            text-transform: capitalize;
        }

        /* ----- PAGE CARD / CONTAINER ----- */
        .container {
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
        }

        .message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }

        .section {
            background: white;
            padding: 25px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #eef2f7;
            overflow-x: auto;
        }
        .section h2 {
            margin-bottom: 20px;
            color: #0f172a;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            font-size: 1.2rem;
        }
        .section h2 .btn-create {
            background: #2563eb;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        .section h2 .btn-create:hover { background: #1d4ed8; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #64748b;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            position: sticky;
            top: 0;
        }
        tr:hover td { background: #f8fafc; }

        .badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
            display: inline-block;
        }
        .badge-admin { background: #e74c3c; color: white; }
        .badge-coordinator { background: #3498db; color: white; }
        .badge-supervisor { background: #f39c12; color: white; }
        .badge-student { background: #2ecc71; color: white; }
        .badge-pending { background: #f39c12; color: white; }
        .badge-active { background: #2ecc71; color: white; }
        .badge-inactive { background: #e74c3c; color: white; }

        .user-name { font-weight: 600; color: #0f172a; }
        .user-info { font-size: 12px; color: #94a3b8; }

        .inline-form {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            margin: 0;
        }
        .actions-wrap {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }

        select {
            padding: 4px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
            background: white;
        }

        button {
            padding: 4px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        button i { font-size: 13px; }
        button:hover { opacity: 0.85; }

        .btn-success { background: #2ecc71; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-primary { background: #3498db; color: white; }

        .edit-profile-btn {
            background: #00bcd4;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow-y: auto;
        }
        .modal.active {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 16px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 10px;
        }
        .modal-header h2 { color: #0f172a; }
        .modal-close {
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
            background: none;
            border: none;
        }
        .modal-close:hover { color: #333; }
        .modal .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .modal .form-group { margin-bottom: 15px; }
        .modal .form-group.full-width { grid-column: 1 / -1; }
        .modal label {
            display: block;
            margin-bottom: 5px;
            color: #64748b;
            font-weight: 500;
            font-size: 13px;
        }
        .modal input, .modal select, .modal textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .modal input:focus, .modal select:focus, .modal textarea:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .modal .btn-submit {
            background: #2563eb;
            color: white;
            padding: 10px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s;
            width: 100%;
        }
        .modal .btn-submit:hover { background: #1d4ed8; }
        .modal .required { color: #dc2626; }
        .modal .password-actions {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }
        .modal .password-actions button {
            background: #94a3b8;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .modal .password-actions button:hover { background: #64748b; }

        /* Profile Sidebar */
        .profile-sidebar-overlay { display: none; }
        #profileSidebarOverlay.open {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.35);
            z-index: 1050;
        }
        #profileSidebar {
            position: fixed;
            right: 0;
            top: 0;
            height: 100%;
            width: 380px;
            max-width: 90%;
            background: #fff;
            box-shadow: -8px 0 30px rgba(0,0,0,0.2);
            transform: translateX(100%);
            transition: transform 0.28s ease;
            z-index: 1100;
            padding: 24px;
            overflow-y: auto;
        }
        #profileSidebar.open { transform: translateX(0); }
        #profileSidebar .modal-close {
            font-size: 26px;
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
        }
        #profileSidebar .modal-close:hover { color: #333; }

        /* ----- RESPONSIVE ----- */
        @media (max-width: 720px) {
            body {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding: 16px 20px;
            }
            .nav-section {
                flex-direction: row;
                flex-wrap: wrap;
            }
            .nav-item {
                padding: 8px 14px;
                font-size: 0.85rem;
            }
            .main-content {
                padding: 0 16px 16px 16px;
            }
            .top-header {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 16px;
                margin: 0 -16px 16px -16px;
            }
            .header-right {
                justify-content: flex-start;
            }
            .modal .form-row { grid-template-columns: 1fr; }
            .modal-content { padding: 20px; margin: 10px; }
            table { font-size: 12px; }
            th, td { padding: 6px 8px; }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script>
        function openProfile(userJson) {
            try {
                const decoded = decodeURIComponent(userJson);
                const user = JSON.parse(decoded);
                const content = document.getElementById('profileSidebarContent');
                content.innerHTML = `
                    <h2>${escapeHtml(getFullNameJS(user))}</h2>
                    <div style="margin-top:10px; color:#94a3b8;">@${escapeHtml(user.username || '')}</div>
                    <hr style="margin:15px 0; border-color:#f1f5f9;">
                    <div style="font-size:14px; color:#334155;">
                        <div style="margin-bottom:8px;"><strong>Full Name:</strong> ${escapeHtml(getFullNameJS(user))}</div>
                        <div style="margin-bottom:8px;"><strong>First Name:</strong> ${escapeHtml(user.firstname || '')}</div>
                        <div style="margin-bottom:8px;"><strong>Middle Name:</strong> ${escapeHtml(user.middlename || 'N/A')}</div>
                        <div style="margin-bottom:8px;"><strong>Last Name:</strong> ${escapeHtml(user.lastname || '')}</div>
                        <div style="margin-bottom:8px;"><strong>Suffix:</strong> ${escapeHtml(user.suffix || 'N/A')}</div>
                        <div style="margin-bottom:8px;"><strong>Phone:</strong> ${escapeHtml(user.phone || 'N/A')}</div>
                        <div style="margin-bottom:8px;"><strong>Address:</strong> ${escapeHtml(user.address || 'N/A')}</div>
                        <div style="margin-bottom:8px;"><strong>Birthdate:</strong> ${escapeHtml(user.birthdate || '')}</div>
                        <div style="margin-bottom:8px;"><strong>Email:</strong> ${escapeHtml(user.email || '')}</div>
                        <div style="margin-bottom:8px;"><strong>Role:</strong> ${escapeHtml(user.role || '')}</div>
                        <div style="margin-bottom:8px;"><strong>Status:</strong> ${escapeHtml(user.status || '')}</div>
                        <div style="margin-bottom:8px;"><strong>Created:</strong> ${escapeHtml(user.created_at || '')}</div>
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
    </script>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-brand">
            <i class="fa-solid fa-shield-alt"></i>
            <h2>Admin<span>Panel</span></h2>
        </div>
        <nav class="nav-section">
            <a class="nav-item" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
            <a class="nav-item active" href="user_management.php"><i class="fa-solid fa-users-gear"></i> User Management</a>
        </nav>
        <div class="sidebar-footer">
            <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <!-- TOP HEADER -->
        <div class="top-header">
            <div class="header-left">
                <h1>
                    <i class="fa-solid fa-users-gear"></i>
                    User Management
                    <small>Admin</small>
                </h1>
            </div>
            <div class="header-right">
                <button class="notif-bell" onclick="alert('No new notifications')" aria-label="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <span class="notif-badge">3</span>
                </button>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php
                            $fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin';
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
                        <div class="role-label">Administrator</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <?php if ($message): 
                $isError = strpos($message, 'Error:') !== false || strpos($message, 'Failed') !== false;
            ?>
                <div class="message <?php echo $isError ? 'error' : ''; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="section">
                <h2>
                    All Users
                    <button class="btn-create" onclick="openModal()">+ Create New User</button>
                </h2>
                <table>
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
                                <td colspan="8" style="text-align: center; color: #94a3b8;">No users found.</td>
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
                                            <button type="submit" name="action" value="update_role" style="background:#dbeafe; color:#1d4ed8; padding:6px 12px;"><i class="fa-solid fa-pen-to-square"></i></button>
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
                                            <button type="submit" name="action" value="update_status" style="background:#dbeafe; color:#1d4ed8; padding:6px 12px;"><i class="fa-solid fa-pen-to-square"></i></button>
                                        </form>
                                    </td>
                                    <td>
                                        <button onclick="openProfile('<?php echo rawurlencode(json_encode($user)); ?>')" style="background:#e0e7ff; color:#4338ca; padding:6px 12px; border-radius:6px; font-size:14px;"><i class="fa-solid fa-eye"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section pending-section">
                <h2>Pending Approvals</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingUsers)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #94a3b8;">No pending registrations.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingUsers as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td>
                                        <div class="user-name"><?php echo htmlspecialchars(getFullName($user)); ?></div>
                                        <div class="user-info">@<?php echo htmlspecialchars($user['username']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="actions-wrap">
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="action" value="approve_user" class="btn-success" style="padding:6px 14px;">Approve</button>
                                                <button type="submit" name="action" value="decline_user" class="btn-danger" style="padding:6px 14px;">Decline</button>
                                            </form>
                                            <button onclick="openProfile('<?php echo rawurlencode(json_encode($user)); ?>')" class="edit-profile-btn" style="padding:6px 12px;">View</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Create User Modal -->
    <div id="createUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New User</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_user">
                
                <h3 style="color: #0f172a; margin-bottom: 15px; font-size: 16px;">Account Information</h3>
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
                
                <h3 style="color: #0f172a; margin: 20px 0 15px 0; font-size: 16px;">Personal Information</h3>
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
                
                <h3 style="color: #0f172a; margin: 20px 0 15px 0; font-size: 16px;">Role & Status</h3>
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
                
                <button type="submit" class="btn-submit">Create User</button>
            </form>
        </div>
    </div>
    
    <!-- Profile Sidebar -->
    <div id="profileSidebarOverlay" class="profile-sidebar-overlay"></div>
    <aside id="profileSidebar">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <div></div>
            <button class="modal-close" onclick="closeProfile()">&times;</button>
        </div>
        <div id="profileSidebarContent"></div>
    </aside>

</body>
</html>