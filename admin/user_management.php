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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; }
        .navbar { background: #2c3e50; color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .navbar h1 { font-size: 24px; }
        .nav-links { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
        .nav-links a { color: white; text-decoration: none; padding: 8px 16px; border-radius: 4px; transition: background 0.3s; }
        .nav-links a:hover { background: #34495e; }
        .nav-links a.active { background: #3498db; }
        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .message { 
            background: #d4edda; 
            color: #155724; 
            padding: 15px; 
            border-radius: 4px; 
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }
        .section { background: white; padding: 25px; border-radius: 8px; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow-x: auto; }
        .section h2 { margin-bottom: 20px; color: #2c3e50; border-bottom: 2px solid #f4f6f9; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .section h2 .btn-create { 
            background: #28a745; 
            color: white; 
            padding: 8px 20px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 14px;
            transition: background 0.3s;
        }
        .section h2 .btn-create:hover { background: #218838; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e0e0e0; }
        th { background: #f8f9fa; font-weight: 600; color: #495057; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; white-space: nowrap; }
        .badge-admin { background: #e74c3c; color: white; }
        .badge-coordinator { background: #3498db; color: white; }
        .badge-supervisor { background: #f39c12; color: white; }
        .badge-student { background: #2ecc71; color: white; }
        .badge-pending { background: #f39c12; color: white; }
        .badge-active { background: #2ecc71; color: white; }
        .badge-inactive { background: #e74c3c; color: white; }
        select, button, input { padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
        select { min-width: 100px; }
        .btn-primary { background: #3498db; color: white; border: none; cursor: pointer; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #2ecc71; color: white; border: none; cursor: pointer; }
        .btn-success:hover { background: #27ae60; }
        .btn-danger { background: #e74c3c; color: white; border: none; cursor: pointer; }
        .btn-danger:hover { background: #c0392b; }
        .btn-warning { background: #f39c12; color: white; border: none; cursor: pointer; }
        .btn-warning:hover { background: #d68910; }
        .btn-info { background: #00bcd4; color: white; border: none; cursor: pointer; }
        .btn-info:hover { background: #0097a7; }
        .inline-form { display: inline-flex; gap: 8px; align-items: center; margin: 0; }
        .actions-wrap { display: inline-flex; gap: 8px; align-items: center; }
        .logout-btn { background: #e74c3c; padding: 8px 16px; border-radius: 4px; color: white; text-decoration: none; }
        .logout-btn:hover { background: #c0392b; }
        .user-info { font-size: 12px; color: #666; }
        .user-name { font-weight: 600; color: #2c3e50; }
        .edit-profile-btn { background: #00bcd4; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .edit-profile-btn:hover { background: #0097a7; }
        .profile-details { display: none; margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px; }
        .profile-details.active { display: block; }
        .profile-details .detail-row { display: grid; grid-template-columns: 150px 1fr; gap: 10px; padding: 5px 0; border-bottom: 1px solid #e0e0e0; }
        .profile-details .detail-row:last-child { border-bottom: none; }
        .profile-details .label { font-weight: 600; color: #495057; }
        
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
            border-radius: 8px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #f4f6f9;
            padding-bottom: 10px;
        }
        .modal-header h2 {
            color: #2c3e50;
        }
        .modal-close {
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
            transition: color 0.3s;
            background: none;
            border: none;
        }
        .modal-close:hover {
            color: #333;
        }
        .modal .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .modal .form-group {
            margin-bottom: 15px;
        }
        .modal .form-group.full-width {
            grid-column: 1 / -1;
        }
        .modal label {
            display: block;
            margin-bottom: 5px;
            color: #666;
            font-weight: 500;
            font-size: 13px;
        }
        .modal input, .modal select, .modal textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .modal textarea {
            resize: vertical;
            min-height: 60px;
        }
        .modal input:focus, .modal select:focus, .modal textarea:focus {
            border-color: #3498db;
            outline: none;
        }
        .modal .btn-submit {
            background: #28a745;
            color: white;
            padding: 10px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s;
            width: 100%;
        }
        .modal .btn-submit:hover {
            background: #218838;
        }
        .modal .required {
            color: #dc3545;
        }
        .modal .password-actions {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }
        .modal .password-actions button {
            background: #6c757d;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .modal .password-actions button:hover {
            background: #5a6268;
        }
        /* Right sidebar profile panel */
        .profile-sidebar-overlay { display: none; }
        #profileSidebarOverlay.open { display: block; position: fixed; inset: 0; background: rgba(0,0,0,0.35); z-index: 1050; }
        #profileSidebar { position: fixed; right: 0; top: 0; height: 100%; width: 380px; max-width: 90%; background: #fff; box-shadow: -8px 0 30px rgba(0,0,0,0.2); transform: translateX(100%); transition: transform 0.28s ease; z-index: 1100; padding: 20px; overflow-y: auto; }
        #profileSidebar.open { transform: translateX(0); }
        #profileSidebar .modal-close { font-size: 26px; }
        @media (max-width: 768px) {
            .navbar { flex-direction: column; text-align: center; }
            .nav-links { justify-content: center; margin-top: 10px; }
            .profile-details .detail-row { grid-template-columns: 1fr; }
            table { font-size: 12px; }
            th, td { padding: 5px; }
            .modal .form-row { grid-template-columns: 1fr; }
            .modal-content { padding: 20px; margin: 10px; }
        }
    </style>
    <script>
        // Open profile in right sidebar by passing serialized user JSON
        function openProfile(userJson) {
            try {
                const decoded = decodeURIComponent(userJson);
                const user = JSON.parse(decoded);
                const content = document.getElementById('profileSidebarContent');
                content.innerHTML = `
                    <h2>${escapeHtml(getFullNameJS(user))}</h2>
                    <div style="margin-top:10px; color:#666;">@${escapeHtml(user.username || '')}</div>
                    <hr style="margin:15px 0;">
                    <div style="font-size:14px; color:#333;">
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

        // Helper to escape HTML when inserting from JSON
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"'`]/g, function (s) {
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','`':'&#96;'})[s];
            });
        }

        // Recreate full name in JS from user object
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
        
        // Close create modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('createUserModal');
            if (event.target === modal) {
                closeModal();
            }
            const overlay = document.getElementById('profileSidebarOverlay');
            if (event.target === overlay) {
                closeProfile();
            }
        }
        
        // Generate random password
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
        
        // Toggle password visibility
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
    <div class="app-shell">
  <aside class="sidebar">
           <div class="sidebar-brand">
            <i class="fas fa-shield-alt"></i>
        
        </div>
            <nav class="nav-section">
                <a class="nav-item " href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item active " href="user_management.php"><i class="fa-solid fa-users-gear"></i> User Management</a>
            </nav>
            <div class="sidebar-footer">
                <div class="user-chip">
                    <i class="fa-solid fa-user-circle"></i>
                    <div>
                        <div class="name"><?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username']); ?></div>
                        <div class="role-label">Administrator</div>
                    </div>
                </div>
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <main class="main-content">
            <div class="top-bar">
              
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
                            <td colspan="8" style="text-align: center; color: #666;">No users found.</td>
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
                                    <div class="actions-wrap">
                                      
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <select name="role">
                                                <?php foreach ($roles as $role): ?>
                                                    <option value="<?php echo $role; ?>" <?php echo $user['role'] === $role ? 'selected' : ''; ?>>
                                                        <?php echo ucfirst($role); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="action" value="update_role" ><i class="fa-solid fa-pen-to-square"></i></button>
                                        </form>
                                       
                                    </div>
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
                                            <button type="submit" name="action" value="update_status"><i class="fa-solid fa-pen-to-square"></i></button>
                                        </form>
                                        
                                </td>
                                <td>
                                      <button onclick="openProfile('<?php echo rawurlencode(json_encode($user)); ?>')" ><i class="fa-solid fa-eye"></i></button>
                                </td>
                                    
                                    <!-- Profile Details -->
                                    <div id="profile-<?php echo $user['id']; ?>" class="profile-details">
                                        <h4>Full Profile</h4>
                                        <div class="detail-row">
                                            <span class="label">Full Name:</span>
                                            <span><?php echo htmlspecialchars(getFullName($user)); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">First Name:</span>
                                            <span><?php echo htmlspecialchars($user['firstname']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Middle Name:</span>
                                            <span><?php echo htmlspecialchars($user['middlename'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Last Name:</span>
                                            <span><?php echo htmlspecialchars($user['lastname']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Suffix:</span>
                                            <span><?php echo htmlspecialchars($user['suffix'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Phone:</span>
                                            <span><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Address:</span>
                                            <span><?php echo htmlspecialchars($user['address'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Birthdate:</span>
                                            <span><?php echo formatDate($user['birthdate']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Username:</span>
                                            <span><?php echo htmlspecialchars($user['username']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Email:</span>
                                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Role:</span>
                                            <span><?php echo getRoleDisplayName($user['role']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Status:</span>
                                            <span><?php echo ucfirst($user['status']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Created:</span>
                                            <span><?php echo date('F d, Y H:i', strtotime($user['created_at'])); ?></span>
                                        </div>
                                    </div>
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
                            <td colspan="6" style="text-align: center; color: #666;">No pending registrations.</td>
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
                                            <button type="submit" name="action" value="approve_user" class="btn-success">Approve</button>
                                            <button type="submit" name="action" value="decline_user" class="btn-danger">Decline</button>
                                        </form>
                                        <button onclick="openProfile('<?php echo rawurlencode(json_encode($user)); ?>')" class="edit-profile-btn">View Details</button>
                                    </div>
                                    
                                    <!-- Profile Details for Pending Users -->
                                    <div id="profile-<?php echo $user['id']; ?>" class="profile-details">
                                        <h4>Registration Details</h4>
                                        <div class="detail-row">
                                            <span class="label">Full Name:</span>
                                            <span><?php echo htmlspecialchars(getFullName($user)); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">First Name:</span>
                                            <span><?php echo htmlspecialchars($user['firstname']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Middle Name:</span>
                                            <span><?php echo htmlspecialchars($user['middlename'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Last Name:</span>
                                            <span><?php echo htmlspecialchars($user['lastname']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Suffix:</span>
                                            <span><?php echo htmlspecialchars($user['suffix'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Phone:</span>
                                            <span><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Address:</span>
                                            <span><?php echo htmlspecialchars($user['address'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Birthdate:</span>
                                            <span><?php echo formatDate($user['birthdate']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Email:</span>
                                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Username:</span>
                                            <span><?php echo htmlspecialchars($user['username']); ?></span>
                                        </div>
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
    </div>

    <!-- Create User Modal -->
    <div id="createUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New User</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_user">
                
                <!-- Account Information -->
                <h3 style="color: #2c3e50; margin-bottom: 15px; font-size: 16px;">Account Information</h3>
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
                            <button type="button" onclick="generatePassword()">Generate Password</button>
                            <button type="button" onclick="togglePasswordVisibility()">Show/Hide</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>
                
                <!-- Personal Information -->
                <h3 style="color: #2c3e50; margin: 20px 0 15px 0; font-size: 16px;">Personal Information</h3>
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
                
                <!-- Role and Status -->
                <h3 style="color: #2c3e50; margin: 20px 0 15px 0; font-size: 16px;">Role & Status</h3>
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
    
    <!-- Profile Sidebar Overlay + Panel -->
    <div id="profileSidebarOverlay" class="profile-sidebar-overlay"></div>
    <aside id="profileSidebar" aria-hidden="true">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div></div>
            <button class="modal-close" onclick="closeProfile()">&times;</button>
        </div>
        <div id="profileSidebarContent" style="margin-top:10px;"></div>
    </aside>
</body>
</html>