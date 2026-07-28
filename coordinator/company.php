<?php
// coordinator/dashboard.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is coordinator
checkAccess('coordinator');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Coordinator';
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

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_company') {
        $company_name = trim($_POST['company_name']);
        $address = trim($_POST['address']);
        $industry = trim($_POST['industry']);
        $contact_person = trim($_POST['contact_person']);
        $contact_email = trim($_POST['contact_email']);
        $contact_number = trim($_POST['contact_number']);
        
        $stmt = $pdo->prepare("INSERT INTO companies (company_name, address, industry, contact_person, contact_email, contact_number) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$company_name, $address, $industry, $contact_person, $contact_email, $contact_number]);
        
        $_SESSION['success'] = "Company added successfully!";
    } elseif ($_POST['action'] === 'edit_company') {
        $id = (int)$_POST['company_id'];
        $company_name = trim($_POST['company_name']);
        $address = trim($_POST['address']);
        $industry = trim($_POST['industry']);
        $contact_person = trim($_POST['contact_person']);
        $contact_email = trim($_POST['contact_email']);
        $contact_number = trim($_POST['contact_number']);
        
        $stmt = $pdo->prepare("UPDATE companies SET company_name = ?, address = ?, industry = ?, contact_person = ?, contact_email = ?, contact_number = ? WHERE id = ?");
        $stmt->execute([$company_name, $address, $industry, $contact_person, $contact_email, $contact_number, $id]);
        
        $_SESSION['success'] = "Company updated successfully!";
    } elseif ($_POST['action'] === 'assign_supervisor') {
        $companyId = (int)($_POST['company_id'] ?? 0);
        $supervisorId = isset($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : 0;
        $searchQuery = trim($_POST['search'] ?? '');

        if ($companyId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $company = $stmt->fetch();

            if (!$company) {
                $_SESSION['error'] = 'Invalid company selected.';
            } else {
                if ($supervisorId > 0) {
                    $supStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'supervisor'");
                    $supStmt->execute([$supervisorId]);
                    $supervisor = $supStmt->fetch();

                    if ($supervisor) {
                        $unassignStmt = $pdo->prepare("UPDATE companies SET supervisor_id = NULL WHERE supervisor_id = ? AND id != ?");
                        $unassignStmt->execute([$supervisorId, $companyId]);

                        $assignStmt = $pdo->prepare("UPDATE companies SET supervisor_id = ? WHERE id = ?");
                        if ($assignStmt->execute([$supervisorId, $companyId])) {
                            createSystemNotification(
                                $pdo,
                                $supervisorId,
                                $userId,
                                'assignment',
                                'Company Assignment',
                                'You have been assigned to manage a company.',
                                'job.php'
                            );
                            $_SESSION['success'] = 'Supervisor assigned to company successfully.';
                        } else {
                            $_SESSION['error'] = 'Unable to assign supervisor at this time.';
                        }
                    } else {
                        $_SESSION['error'] = 'Invalid supervisor selected.';
                    }
                } else {
                    $assignStmt = $pdo->prepare("UPDATE companies SET supervisor_id = NULL WHERE id = ?");
                    if ($assignStmt->execute([$companyId])) {
                        $_SESSION['success'] = 'Supervisor unassigned from company successfully.';
                    } else {
                        $_SESSION['error'] = 'Unable to unassign supervisor at this time.';
                    }
                }
            }
        } else {
            $_SESSION['error'] = 'Please select a valid company.';
        }

        $redirectUrl = 'company.php';
        if ($searchQuery !== '') {
            $redirectUrl .= '?search=' . urlencode($searchQuery);
        }
        header("Location: $redirectUrl");
        exit;
    }

    if ($_POST['action'] !== 'assign_supervisor') {
        header("Location: company.php");
        exit;
    }
}

// Handle Delete Company
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['success'] = "Company deleted successfully!";
    header("Location: company.php");
    exit;
}

// Fetch single company for editing (via AJAX)
if (isset($_GET['get_company'])) {
    $id = (int)$_GET['get_company'];
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($company);
    exit;
}

$search = trim($_GET['search'] ?? '');

// Fetch supervisor list for assignment
$supervisors = $pdo->prepare("SELECT id, firstname, middlename, lastname, suffix FROM users WHERE role = 'supervisor' AND status = 'active' ORDER BY firstname, lastname");
$supervisors->execute();
$supervisors = $supervisors->fetchAll();

$supervisorNames = [];
foreach ($supervisors as $supervisor) {
    $supervisorNames[$supervisor['id']] = getFullName($supervisor);
}

// Fetch all active supervisor assignments to companies
$assignmentsStmt = $pdo->query("SELECT id, supervisor_id FROM companies WHERE supervisor_id IS NOT NULL");
$assignedSupervisors = [];
while ($row = $assignmentsStmt->fetch()) {
    $assignedSupervisors[(int)$row['supervisor_id']] = (int)$row['id'];
}

// Fetch companies with optional search filter
$companySql = "SELECT * FROM companies";
$params = [];
if ($search !== '') {
    $companySql .= " WHERE company_name LIKE ?";
    $params[] = '%' . $search . '%';
}
$companySql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($companySql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

// Current profile picture
$profilePicture = getUserProfilePicture($pdo, $userId);
$profilePictureUrl = $profilePicture ? $avatarPublicPath . $profilePicture : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinator - Company Management</title>
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

        /* ---- Dark Green Sidebar (now a profile panel) ---- */
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
            align-items: center;
            text-align: center;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
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

        /* ---- Profile panel (sidebar) ---- */
        .profile-panel {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }

        .avatar-editable {
            position: relative;
            width: 108px;
            height: 108px;
            margin-bottom: 16px;
            cursor: pointer;
        }

        .avatar-editable .avatar-img,
        .avatar-editable .avatar-initials {
            width: 108px;
            height: 108px;
            border: 3px solid #FFCC33;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #FFCC33;
            color: #003300;
            font-weight: 700;
            font-size: 2rem;
            text-transform: uppercase;
        }

        .avatar-editable .avatar-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-editable .avatar-edit-badge {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 32px;
            height: 32px;
            background: #003300;
            border: 2px solid #FFCC33;
            color: #FFCC33;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            transition: 0.15s;
        }

        .avatar-editable:hover .avatar-edit-badge {
            background: #FFCC33;
            color: #003300;
        }

        .avatar-editable input[type="file"] {
            display: none;
        }

        .profile-panel .name {
            font-weight: 700;
            font-size: 1.05rem;
            color: #FFCC33;
            margin-bottom: 4px;
            word-break: break-word;
        }

        .profile-panel .role-label {
            font-size: 0.75rem;
            color: #cbd5e1;
            font-weight: 500;
            text-transform: capitalize;
            margin-bottom: 20px;
        }

        .btn-change-password {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            background: rgba(255, 204, 51, 0.12);
            border: 1px solid rgba(255, 204, 51, 0.35);
            color: #FFCC33;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .btn-change-password:hover {
            background: rgba(255, 204, 51, 0.25);
            color: #fff;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid rgba(255, 204, 51, 0.3);
            padding-top: 18px;
            width: 100%;
        }

        .logout-btn-side {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 0;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: 0.15s;
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

        /* ---- Header right with navigation ---- */
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
            justify-content: flex-end;
        }

        .header-nav {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }

        .header-nav .nav-item-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 0;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.88rem;
            transition: all 0.15s;
            white-space: nowrap;
        }

        .header-nav .nav-item-header i {
            font-size: 0.9rem;
        }

        .header-nav .nav-item-header:hover {
            background: rgba(255, 204, 51, 0.2);
            color: #fff;
        }

        .header-nav .nav-item-header:hover i {
            color: #FFCC33;
        }

        .header-nav .nav-item-header.active {
            background: #FFCC33;
            color: #003300;
            font-weight: 600;
        }

        .header-nav .nav-item-header.active i {
            color: #003300;
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

        /* ---- Page card (sharp, bordered) ---- */
        .page-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            flex: 1;
            border-radius: 0;
        }

        .page-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid #e9edf2;
            flex-wrap: wrap;
            gap: 10px;
        }

        .page-card-header h3 {
            font-weight: 600;
            color: #0f172a;
            font-size: 16px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-card-header h3 i {
            color: #3b82f6;
        }

        .page-card-header p {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 0.85rem;
        }

        .page-card-actions {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-form {
            display: inline-flex;
            gap: 6px;
            align-items: center;
        }

        .search-form input[type="search"] {
            padding: 7px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 0;
            min-width: 200px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            font-size: 0.85rem;
            background: #f8fafc;
            transition: 0.15s;
        }

        .search-form input[type="search"]:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .btn-search {
            padding: 7px 14px;
            border-radius: 0;
            background: #0f172a;
            color: #fff;
            border: 1px solid #0f172a;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.8rem;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .btn-search:hover {
            background: #1e293b;
        }

        .btn-add {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
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

        .btn-add:hover {
            background: #1e293b;
        }

        /* ---- Table (compressed) ---- */
        .table-wrapper {
            overflow-x: auto;
            padding: 4px 8px 8px 8px;
        }

        .company-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .company-table th {
            text-align: left;
            padding: 8px 10px;
            color: #64748b;
            font-weight: 600;
            background: #fafcff;
            border-bottom: 2px solid #e9edf2;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .company-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
            vertical-align: middle;
        }

        .company-table tbody tr:last-child td {
            border-bottom: none;
        }

        .company-table tbody tr:hover {
            background: #fafcff;
        }

        .company-name-cell {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.82rem;
        }

        .company-address {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 1px;
        }

        .industry-tag {
            background: #f1f5f9;
            padding: 2px 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.7rem;
            font-weight: 500;
            color: #475569;
            border-radius: 0;
            display: inline-block;
        }

        .supervisor-badge {
            padding: 3px 10px;
            border-radius: 0;
            background: #eef2ff;
            color: #3730a3;
            font-size: 0.72rem;
            display: inline-flex;
            align-items: center;
            border: 1px solid #a5b4fc;
        }

        .supervisor-badge.unassigned {
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
        }

        /* ---- Action Buttons (compressed) ---- */
        .action-buttons {
            display: flex;
            gap: 4px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-assign {
            padding: 3px 10px;
            border-radius: 0;
            font-size: 0.65rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid transparent;
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .btn-assign:hover {
            background: #bfdbfe;
            transform: scale(1.02);
        }

        .btn-edit {
            padding: 3px 8px;
            border-radius: 0;
            font-size: 0.65rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid transparent;
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .btn-edit:hover {
            background: #fef08a;
            transform: scale(1.02);
        }

        .btn-delete {
            padding: 3px 8px;
            border-radius: 0;
            font-size: 0.65rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid transparent;
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
            text-decoration: none;
        }

        .btn-delete:hover {
            background: #fecaca;
            transform: scale(1.02);
        }

        /* ---- Empty State ---- */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 2.5rem;
            display: block;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        .empty-state p {
            font-size: 0.9rem;
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
            max-width: 560px;
            width: 100%;
            padding: 0;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.3);
            animation: slideUp 0.25s ease;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            border-radius: 0;
        }

        .modal-container.assign-modal {
            max-width: 640px;
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
            align-items: flex-start;
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

        .modal-header p {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 0.82rem;
        }

        .modal-close-btn {
            background: none;
            border: none;
            font-size: 1.6rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px 6px;
            transition: 0.15s;
            line-height: 1;
            flex-shrink: 0;
        }

        .modal-close-btn:hover {
            color: #1e293b;
        }

        .modal-body {
            padding: 16px 24px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-body.assign-body {
            padding: 14px 20px 0;
        }

        .modal-body.assign-body .search-box {
            margin-bottom: 14px;
        }

        .modal-body.assign-body .search-box input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.85rem;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: #fafcff;
        }

        .modal-body.assign-body .search-box input:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .modal-body.assign-body .supervisor-grid {
            display: grid;
            gap: 8px;
            max-height: 280px;
            overflow-y: auto;
        }

        .supervisor-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: background 0.2s ease;
            border-radius: 0;
        }

        .supervisor-item:hover {
            background: #f8fafc;
        }

        .supervisor-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #2563eb;
            flex-shrink: 0;
        }

        .supervisor-item .supervisor-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.85rem;
        }

        .supervisor-item .supervisor-role {
            color: #64748b;
            font-size: 0.75rem;
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

        /* ---- Form Styles (compressed) ---- */
        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            color: #334155;
            font-size: 0.82rem;
            margin-bottom: 4px;
        }

        .form-group label .required {
            color: #dc2626;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 0;
            font-size: 0.85rem;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            transition: all 0.2s ease;
            background: #f8fafc;
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
            min-height: 60px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-row .form-group {
            margin-bottom: 0;
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
            .page-card-header {
                flex-direction: column;
                align-items: stretch;
            }
            .page-card-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .search-form {
                flex-direction: column;
                width: 100%;
            }
            .search-form input[type="search"] {
                width: 100%;
                min-width: unset;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .form-row .form-group {
                margin-bottom: 14px;
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

            .header-nav {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .header-nav .nav-item-header {
                padding: 6px 12px;
                font-size: 0.8rem;
            }

            .notif-bell {
                align-self: center;
            }

            .page-card-header h3 {
                font-size: 0.95rem;
            }

            .company-table th,
            .company-table td {
                padding: 6px 8px;
                font-size: 0.72rem;
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

            .action-buttons {
                flex-direction: column;
                align-items: center;
            }

            .btn-assign,
            .btn-edit,
            .btn-delete {
                width: 100%;
                justify-content: center;
                font-size: 0.6rem;
                padding: 3px 6px;
            }

            .supervisor-item {
                padding: 8px 10px;
            }

            .modal-body.assign-body .supervisor-grid {
                max-height: 180px;
            }

            .industry-tag {
                font-size: 0.65rem;
                padding: 2px 6px;
            }

            .supervisor-badge {
                font-size: 0.65rem;
                padding: 2px 6px;
            }
        }

        @media (max-width: 480px) {
            .header-nav .nav-item-header {
                font-size: 0.7rem;
                padding: 4px 8px;
            }

            .header-nav .nav-item-header i {
                font-size: 0.7rem;
            }

            .company-table th,
            .company-table td {
                padding: 4px 6px;
                font-size: 0.65rem;
            }

            .company-name-cell {
                font-size: 0.7rem;
            }

            .company-address {
                font-size: 0.6rem;
            }

            .btn-assign,
            .btn-edit,
            .btn-delete {
                font-size: 0.55rem;
                padding: 2px 4px;
            }

            .btn-search,
            .btn-add {
                font-size: 0.75rem;
                padding: 6px 12px;
            }

            .search-form input[type="search"] {
                font-size: 0.75rem;
                padding: 6px 10px;
            }

            .modal-header h2 {
                font-size: 1rem;
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

        <!-- SIDEBAR: user profile panel -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-users-gear"></i>
                <h2>System<span>Coordinator Desk</span></h2>
            </div>

            <div class="profile-panel">
                <div class="avatar-editable" id="avatarEditable" title="Click to change your photo">
                    <?php if (!empty($profilePictureUrl)): ?>
                        <div class="avatar-img" id="avatarImgWrap">
                            <img src="<?php echo htmlspecialchars($profilePictureUrl); ?>" alt="Profile photo" id="avatarImg" />
                        </div>
                    <?php else: ?>
                        <div class="avatar-initials" id="avatarImgWrap">
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
                    <?php endif; ?>
                    <div class="avatar-edit-badge"><i class="fa-solid fa-camera"></i></div>
                    <input type="file" id="avatarInput" accept="image/png, image/jpeg, image/webp, image/gif" />
                </div>

                <div class="name"><?php echo htmlspecialchars($fullname); ?></div>
                <div class="role-label"><?php echo htmlspecialchars(getRoleDisplayName($role)); ?></div>

                <button class="btn-change-password" id="openPasswordModalBtn">
                    <i class="fa-solid fa-key"></i> Change Password
                </button>
            </div>

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
                        <i class="fa-solid fa-building"></i>
                        Company Management
                        <small>Coordinator</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Header Navigation -->
                    <nav class="header-nav">
                        <a class="nav-item-header" href="dashboard.php"></i> Dashboard</a>
                        <a class="nav-item-header active" href="company.php"></i> Companies</a>
                        <a class="nav-item-header" href="intern.php"></i> Internship</a>
                        <a class="nav-item-header" href="evaluation.php"></i> Evaluation</a>
                        <a class="nav-item-header" href="dss.php"></i> Decision Support</a>
                    </nav>

                    <!-- Notification bell -->
                    <button class="notif-bell" onclick="alert('No new notifications')" aria-label="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <span class="notif-badge">3</span>
                    </button>
                </div>
            </div>

            <!-- PAGE CARD -->
            <div class="page-card">
                <div class="page-card-header">
                    <div>
                        <h3><i class="fa-solid fa-search"></i> Search Companies</h3>
                        <p>Search by company name and assign a supervisor to a company.</p>
                    </div>
                    <div class="page-card-actions">
                        <form action="company.php" method="get" class="search-form">
                            <input type="search" name="search" placeholder="Search company name..." value="<?php echo htmlspecialchars($search); ?>" aria-label="Search companies" />
                            <button type="submit" class="btn-search"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                        </form>
                        <button onclick="openAddModal()" class="btn-add">
                            <i class="fa-solid fa-plus"></i> Add
                        </button>
                    </div>
                </div>

                <div class="table-wrapper">
                    <?php if (count($companies) > 0): ?>
                        <table class="company-table">
                            <thead>
                                <tr>
                                    <th>Company</th>
                                    <th>Industry</th>
                                    <th>Contact</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Supervisor</th>
                                    <th style="text-align: center; width: 160px; min-width: 160px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($companies as $company): ?>
                                    <tr>
                                        <td>
                                            <div class="company-name-cell"><?php echo htmlspecialchars($company['company_name']); ?></div>
                                            <div class="company-address">
                                                <i class="fa-solid fa-location-dot" style="color: #94a3b8; margin-right: 4px; font-size: 0.6rem;"></i>
                                                <?php echo htmlspecialchars(substr($company['address'], 0, 40)) . (strlen($company['address']) > 40 ? '...' : ''); ?>
                                            </div>
                                        </td>
                                        <td><span class="industry-tag"><?php echo htmlspecialchars($company['industry']); ?></span></td>
                                        <td><?php echo htmlspecialchars($company['contact_person']); ?></td>
                                        <td style="font-size: 0.75rem;"><?php echo htmlspecialchars($company['contact_email'] ?? '-'); ?></td>
                                        <td style="font-size: 0.75rem;"><?php echo htmlspecialchars($company['contact_number'] ?? '-'); ?></td>
                                        <td>
                                            <span class="supervisor-badge <?php echo !empty($company['supervisor_id']) ? '' : 'unassigned'; ?>">
                                                <?php echo !empty($company['supervisor_id']) && isset($supervisorNames[$company['supervisor_id']]) ? htmlspecialchars($supervisorNames[$company['supervisor_id']]) : 'Unassigned'; ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <div class="action-buttons">
                                                <button type="button" class="btn-assign" data-company-id="<?php echo htmlspecialchars($company['id'], ENT_QUOTES); ?>" data-company-name="<?php echo htmlspecialchars($company['company_name'], ENT_QUOTES); ?>" data-supervisor-id="<?php echo htmlspecialchars($company['supervisor_id'] ?? '', ENT_QUOTES); ?>">
                                                    <i class="fa-solid fa-user-plus"></i>
                                                </button>
                                                <button type="button" onclick="openEditModal(<?php echo $company['id']; ?>)" class="btn-edit" title="Edit">
                                                    <i class="fa-solid fa-edit"></i>
                                                </button>
                                                <a href="?delete=<?php echo $company['id']; ?>" class="btn-delete" onclick="return confirm('Delete this company?')" title="Delete">
                                                    <i class="fa-solid fa-trash-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-building-circle-exclamation"></i>
                            <p>No companies added yet. Click "Add" to get started.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- ADD COMPANY MODAL -->
    <div id="addModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <div>
                    <h2><i class="fa-solid fa-building"></i> Add Company</h2>
                    <p>Enter the company details below.</p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeAddModal()">&times;</button>
            </div>
            
            <form method="POST" action="" id="addCompanyForm" class="modal-body">
                <input type="hidden" name="action" value="add_company">
                
                <div class="form-group">
                    <label>Company Name <span class="required">*</span></label>
                    <input type="text" name="company_name" required placeholder="Enter company name" />
                </div>
                
                <div class="form-group">
                    <label>Address <span class="required">*</span></label>
                    <textarea name="address" required placeholder="Enter full address" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Industry <span class="required">*</span></label>
                    <input type="text" name="industry" required placeholder="e.g., Technology, Healthcare" />
                </div>
                
                <div class="form-group">
                    <label>Contact Person <span class="required">*</span></label>
                    <input type="text" name="contact_person" required placeholder="Full name" />
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Email</label>
                        <input type="email" name="contact_email" placeholder="email@company.com" />
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact_number" placeholder="+63 912 345 6789" />
                    </div>
                </div>
            </form>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeAddModal()">Cancel</button>
                <button type="submit" form="addCompanyForm" class="btn-primary"><i class="fa-solid fa-save"></i> Save</button>
            </div>
        </div>
    </div>

    <!-- EDIT COMPANY MODAL -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <div>
                    <h2><i class="fa-solid fa-edit"></i> Edit Company</h2>
                    <p>Update the company details below.</p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeEditModal()">&times;</button>
            </div>
            
            <form method="POST" action="" id="editCompanyForm" class="modal-body">
                <input type="hidden" name="action" value="edit_company">
                <input type="hidden" name="company_id" id="edit_company_id" value="">
                
                <div class="form-group">
                    <label>Company Name <span class="required">*</span></label>
                    <input type="text" name="company_name" id="edit_company_name" required placeholder="Enter company name" />
                </div>
                
                <div class="form-group">
                    <label>Address <span class="required">*</span></label>
                    <textarea name="address" id="edit_address" required placeholder="Enter full address" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Industry <span class="required">*</span></label>
                    <input type="text" name="industry" id="edit_industry" required placeholder="e.g., Technology, Healthcare" />
                </div>
                
                <div class="form-group">
                    <label>Contact Person <span class="required">*</span></label>
                    <input type="text" name="contact_person" id="edit_contact_person" required placeholder="Full name" />
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Email</label>
                        <input type="email" name="contact_email" id="edit_contact_email" placeholder="email@company.com" />
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact_number" id="edit_contact_number" placeholder="+63 912 345 6789" />
                    </div>
                </div>
            </form>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" form="editCompanyForm" class="btn-primary"><i class="fa-solid fa-save"></i> Update</button>
            </div>
        </div>
    </div>

    <!-- ASSIGN SUPERVISOR MODAL -->
    <div id="assignModal" class="modal-overlay">
        <div class="modal-container assign-modal">
            <div class="modal-header">
                <div>
                    <h2><i class="fa-solid fa-user-plus"></i> Assign Supervisor</h2>
                    <p>Search and select a supervisor for <span id="assignModalCompanyName" style="font-weight: 600; color: #0f172a;"></span></p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeAssignModal()">&times;</button>
            </div>
            
            <form id="assignSupervisorForm" method="post" action="company.php" class="modal-body assign-body">
                <input type="hidden" name="action" value="assign_supervisor" />
                <input type="hidden" name="company_id" id="assign_company_id" value="" />
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>" />
                
                <div class="search-box">
                    <input id="assignSupervisorSearch" type="search" placeholder="Search supervisors by name..." aria-label="Search supervisors" />
                </div>
                
                <div class="supervisor-grid" id="supervisorList">
                    <?php foreach ($supervisors as $supervisor): ?>
                        <label class="supervisor-item" data-name="<?php echo htmlspecialchars(strtolower(getFullName($supervisor))); ?>" style="display: flex;">
                            <input type="checkbox" name="supervisor_id" value="<?php echo $supervisor['id']; ?>" class="supervisor-checkbox" />
                            <div>
                                <div class="supervisor-name"><?php echo htmlspecialchars(getFullName($supervisor)); ?></div>
                                <div class="supervisor-role"><i class="fa-regular fa-user"></i> Supervisor</div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                    <div id="noSupervisorsMessage" style="display: none; text-align: center; padding: 20px 0; color: #64748b;">No unassigned supervisors available.</div>
                    <?php if (count($supervisors) === 0): ?>
                        <div style="text-align: center; padding: 20px 0; color: #64748b;">No active supervisors available.</div>
                    <?php endif; ?>
                </div>
            </form>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeAssignModal()">Cancel</button>
                <button type="button" class="btn-primary" onclick="submitAssignSupervisor()"><i class="fa-solid fa-save"></i> Assign</button>
            </div>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-container">
            <div class="modal-header">
                <div>
                    <h2><i class="fa-solid fa-key"></i> Change Password</h2>
                    <p>Enter your current password and choose a new one.</p>
                </div>
                <button type="button" class="modal-close-btn" id="closePasswordBtn">&times;</button>
            </div>
            <form id="passwordForm" class="modal-body">
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
                <button type="submit" form="passwordForm" class="btn-primary"><i class="fa-solid fa-check"></i> Update</button>
            </div>
        </div>
    </div>

    <!-- ===== TOAST ===== -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <script>
        // ===== TOAST =====
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            // Set icon based on type
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

        // Check for session messages and show as toast
        <?php if (isset($_SESSION['success'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo htmlspecialchars($_SESSION['success']); ?>', 'success');
            });
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo htmlspecialchars($_SESSION['error']); ?>', 'error');
            });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        // Toast click to dismiss
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
                    passwordModal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                    if (passwordForm) passwordForm.reset();
                }
            });
        }

        function closePasswordModal() {
            if (passwordModal) {
                passwordModal.style.display = 'none';
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

        // ===== MODAL FUNCTIONS =====
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function openEditModal(companyId) {
            fetch('?get_company=' + companyId)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_company_id').value = data.id;
                    document.getElementById('edit_company_name').value = data.company_name;
                    document.getElementById('edit_address').value = data.address;
                    document.getElementById('edit_industry').value = data.industry;
                    document.getElementById('edit_contact_person').value = data.contact_person;
                    document.getElementById('edit_contact_email').value = data.contact_email || '';
                    document.getElementById('edit_contact_number').value = data.contact_number || '';
                    
                    document.getElementById('editModal').style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                })
                .catch(error => {
                    showToast('Error loading company data. Please try again.', 'error');
                });
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        const assignedSupervisorsMap = <?php echo json_encode($assignedSupervisors); ?>;

        window.openAssignModal = function(companyId, companyName, supervisorId) {
            document.getElementById('assign_company_id').value = companyId;
            document.getElementById('assignModalCompanyName').textContent = companyName;
            const modal = document.getElementById('assignModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            document.querySelectorAll('.supervisor-checkbox').forEach(function(checkbox) {
                checkbox.checked = checkbox.value === String(supervisorId);
            });
            document.getElementById('assignSupervisorSearch').value = '';
            filterSupervisorList('');
        };

        window.closeAssignModal = function() {
            document.getElementById('assignModal').style.display = 'none';
            document.body.style.overflow = '';
        };

        window.submitAssignSupervisor = function() {
            document.getElementById('assignSupervisorForm').submit();
        };

        // Use event delegation for assign buttons
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-assign');
            if (btn) {
                const companyId = btn.dataset.companyId;
                const companyName = btn.dataset.companyName;
                const supervisorId = btn.dataset.supervisorId || '';
                openAssignModal(companyId, companyName, supervisorId);
            }
        });

        function filterSupervisorList(query) {
            query = query.trim().toLowerCase();
            const currentCompanyId = parseInt(document.getElementById('assign_company_id').value) || 0;
            let visibleCount = 0;

            document.querySelectorAll('#supervisorList .supervisor-item').forEach(function(item) {
                const checkbox = item.querySelector('.supervisor-checkbox');
                const supervisorId = parseInt(checkbox.value);
                const assignedCompanyId = assignedSupervisorsMap[supervisorId];

                // If this supervisor is assigned to another company, hide them
                if (assignedCompanyId && assignedCompanyId !== currentCompanyId) {
                    item.style.display = 'none';
                } else {
                    const name = item.getAttribute('data-name');
                    const matchesSearch = name.includes(query);
                    if (matchesSearch) {
                        item.style.display = 'flex';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                }
            });

            const noMessage = document.getElementById('noSupervisorsMessage');
            if (noMessage) {
                noMessage.style.display = (visibleCount === 0) ? 'block' : 'none';
            }
        }

        document.getElementById('assignSupervisorSearch').addEventListener('input', function() {
            filterSupervisorList(this.value);
        });

        document.querySelectorAll('.supervisor-checkbox').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    document.querySelectorAll('.supervisor-checkbox').forEach(function(other) {
                        if (other !== checkbox) {
                            other.checked = false;
                        }
                    });
                }
            });
        });

        // Close modals on overlay click
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddModal();
            }
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        document.getElementById('assignModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAssignModal();
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
                closeEditModal();
                closeAssignModal();
                closePasswordModal();
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
            }
        });
    </script>
</body>
</html>