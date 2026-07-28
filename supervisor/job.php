<?php
// supervisor/job.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/supervisor_notifications.php';

checkAccess('supervisor');
ensureInternshipTables($pdo);

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Supervisor';
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

$unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);
$notifications = getSupervisorNotifications($pdo, $userId, 10, 0);

// Handle AJAX requests for notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'get_notifications') {
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        $notifications = getSupervisorNotifications($pdo, $userId, $limit, $offset);
        $unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);
        echo json_encode(['success' => true, 'notifications' => $notifications, 'unread_count' => $unreadCount]);
        exit;
    }

    if ($_POST['action'] === 'mark_read') {
        $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        if ($notification_id > 0) {
            $result = markSupervisorNotificationRead($pdo, $notification_id, $userId);
            $unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);
            echo json_encode(['success' => $result, 'unread_count' => $unreadCount]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        }
        exit;
    }

    if ($_POST['action'] === 'mark_all_read') {
        $result = markSupervisorAllNotificationsRead($pdo, $userId);
        echo json_encode(['success' => $result, 'unread_count' => 0]);
        exit;
    }
}

// Handle Create Job
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_job') {
    $companyId = (int)($_POST['company_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $responsibility = trim($_POST['responsibility'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '');
    $slotsAvailable = max(1, (int)($_POST['slots_available'] ?? 1));
    $durationHours = max(0, (int)($_POST['duration_hours'] ?? 0));

    $companyStmt = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND supervisor_id = ?');
    $companyStmt->execute([$companyId, $userId]);
    $company = $companyStmt->fetch();

    if (!$company || $title === '') {
        $_SESSION['toast_message'] = 'Please choose a valid assigned company and enter a job title.';
        $_SESSION['toast_type'] = 'error';
    } else {
        $stmt = $pdo->prepare('INSERT INTO jobs (company_id, title, description, responsibility, requirements, slots_available, duration_hours, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $title, $description, $responsibility, $requirements, $slotsAvailable, $durationHours, $userId, $userId]);
        $_SESSION['toast_message'] = 'Internship position created successfully!';
        $_SESSION['toast_type'] = 'success';
    }

    header('Location: job.php');
    exit;
}

// Handle Update Job
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_job') {
    $jobId = (int)($_POST['job_id'] ?? 0);
    $companyId = (int)($_POST['company_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $responsibility = trim($_POST['responsibility'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '');
    $slotsAvailable = max(1, (int)($_POST['slots_available'] ?? 1));
    $durationHours = max(0, (int)($_POST['duration_hours'] ?? 0));

    // Check permission
    $checkStmt = $pdo->prepare('SELECT j.id FROM jobs j INNER JOIN companies c ON j.company_id = c.id WHERE j.id = ? AND c.supervisor_id = ?');
    $checkStmt->execute([$jobId, $userId]);
    if ($checkStmt->fetch() && $title !== '') {
        $stmt = $pdo->prepare('UPDATE jobs SET company_id = ?, title = ?, description = ?, responsibility = ?, requirements = ?, slots_available = ?, duration_hours = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$companyId, $title, $description, $responsibility, $requirements, $slotsAvailable, $durationHours, $userId, $jobId]);
        $_SESSION['toast_message'] = 'Job updated successfully!';
        $_SESSION['toast_type'] = 'success';
    } else {
        $_SESSION['toast_message'] = 'You do not have permission to update this job or title is empty.';
        $_SESSION['toast_type'] = 'error';
    }
    
    header('Location: job.php');
    exit;
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_job') {
    $jobId = (int)($_POST['job_id'] ?? 0);
    
    $checkStmt = $pdo->prepare('SELECT j.id FROM jobs j INNER JOIN companies c ON j.company_id = c.id WHERE j.id = ? AND c.supervisor_id = ?');
    $checkStmt->execute([$jobId, $userId]);
    if ($checkStmt->fetch()) {
        $deleteStmt = $pdo->prepare('DELETE FROM jobs WHERE id = ?');
        $deleteStmt->execute([$jobId]);
        $_SESSION['toast_message'] = 'Job deleted successfully!';
        $_SESSION['toast_type'] = 'success';
    } else {
        $_SESSION['toast_message'] = 'You do not have permission to delete this job.';
        $_SESSION['toast_type'] = 'error';
    }
    
    header('Location: job.php');
    exit;
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

// Get job data for edit
$editJob = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT j.*, c.company_name FROM jobs j INNER JOIN companies c ON j.company_id = c.id WHERE j.id = ? AND c.supervisor_id = ?');
    $stmt->execute([$editId, $userId]);
    $editJob = $stmt->fetch();
}

$stmt = $pdo->prepare('SELECT * FROM companies WHERE supervisor_id = ? ORDER BY updated_at DESC');
$stmt->execute([$userId]);
$assignedCompanies = $stmt->fetchAll();

$jobsStmt = $pdo->prepare('SELECT j.*, c.company_name FROM jobs j INNER JOIN companies c ON j.company_id = c.id WHERE c.supervisor_id = ? ORDER BY j.created_at DESC');
$jobsStmt->execute([$userId]);
$jobs = $jobsStmt->fetchAll();

// Get toast message and type
$toastMessage = $_SESSION['toast_message'] ?? '';
$toastType = $_SESSION['toast_type'] ?? 'success';
unset($_SESSION['toast_message'], $_SESSION['toast_type']);

// Current profile picture
$profilePicture = getUserProfilePicture($pdo, $userId);
$profilePictureUrl = $profilePicture ? $avatarPublicPath . $profilePicture : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor - Manage Jobs</title>
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
            gap: 24px;
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

        /* ===== NOTIFICATION BELL & DROPDOWN ===== */
        .notif-wrapper {
            position: relative;
            display: inline-block;
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
            min-width: 20px;
            height: 20px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #003300;
            padding: 0 4px;
        }

        .notif-badge.hidden {
            display: none;
        }

        /* Notification Dropdown */
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 380px;
            max-height: 420px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            display: none;
            z-index: 1000;
            overflow: hidden;
            border-radius: 0;
        }

        .notif-dropdown.active {
            display: block;
            animation: slideDown 0.2s ease;
        }

        @keyframes slideDown {
            0% {
                opacity: 0;
                transform: translateY(-10px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .notif-dropdown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #edf2f7;
            background: #f8fafc;
        }

        .notif-dropdown-header h3 {
            font-size: 0.85rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .notif-dropdown-header .mark-all-read {
            background: none;
            border: none;
            color: #2563eb;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            padding: 4px 8px;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .notif-dropdown-header .mark-all-read:hover {
            text-decoration: underline;
        }

        .notif-list {
            max-height: 320px;
            overflow-y: auto;
            padding: 0;
        }

        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.15s;
            text-decoration: none;
            color: inherit;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-item:hover {
            background: #f8fafc;
        }

        .notif-item.unread {
            background: #eff6ff;
            border-left: 3px solid #2563eb;
        }

        .notif-item .notif-avatar {
            width: 32px;
            height: 32px;
            flex-shrink: 0;
            background: #e2e8f0;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.7rem;
            color: #475569;
            overflow: hidden;
        }

        .notif-item .notif-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .notif-item .notif-content {
            flex: 1;
            min-width: 0;
        }

        .notif-item .notif-content .notif-title {
            font-weight: 600;
            font-size: 0.82rem;
            color: #0f172a;
            margin-bottom: 2px;
        }

        .notif-item .notif-content .notif-message {
            font-size: 0.78rem;
            color: #64748b;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notif-item .notif-content .notif-time {
            font-size: 0.65rem;
            color: #94a3b8;
            margin-top: 3px;
            display: block;
        }

        .notif-empty {
            padding: 32px 16px;
            text-align: center;
            color: #94a3b8;
        }

        .notif-empty i {
            font-size: 2rem;
            display: block;
            margin-bottom: 8px;
            color: #cbd5e1;
        }

        .notif-empty p {
            font-size: 0.9rem;
        }

        /* ---- Page card (sharp, bordered) ---- */
        .page-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 24px 28px 32px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            border-radius: 0;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
        }

        .page-header h2 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0f172a;
        }

        .page-header h2 i {
            color: #3b82f6;
        }

        .page-header p {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 2px;
        }

        .company-count {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-weight: 600;
            font-size: 0.85rem;
            color: #475569;
            border-radius: 0;
        }

        .company-count i {
            color: #3b82f6;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #0f172a;
            border: 1px solid #0f172a;
            color: #fff;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border-radius: 0;
        }

        .action-btn:hover {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        /* ---- Tables ---- */
        .table-wrapper {
            overflow-x: auto;
        }

        .table-container {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
        }

        .company-table,
        .job-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .company-table th,
        .job-table th {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 600;
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .company-table td,
        .job-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .company-table tbody tr:last-child td,
        .job-table tbody tr:last-child td {
            border-bottom: none;
        }

        .company-table tbody tr:hover,
        .job-table tbody tr:hover {
            background: #fafcff;
        }

        .company-name-cell {
            font-weight: 600;
            color: #0f172a;
        }

        .industry-tag {
            display: inline-block;
            padding: 2px 10px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            font-size: 0.75rem;
            font-weight: 500;
            color: #475569;
            border-radius: 0;
        }

        .email-link {
            color: #2563eb;
            text-decoration: none;
            transition: 0.15s;
        }

        .email-link:hover {
            text-decoration: underline;
        }

        .address-text {
            max-width: 200px;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 0.85rem;
        }

        /* ---- Job Table Columns ---- */
        .col-title { min-width: 150px; }
        .col-company { min-width: 120px; }
        .col-available { min-width: 80px; text-align: center; }
        .col-filled { min-width: 80px; text-align: center; }
        .col-duration { min-width: 80px; text-align: center; }
        .col-created { min-width: 100px; }
        .col-description { min-width: 50px; text-align: center; }
        .col-responsibilities { min-width: 50px; text-align: center; }
        .col-requirements { min-width: 50px; text-align: center; }
        .col-actions { min-width: 80px; text-align: center; }

        .btn-view {
            padding: 4px 10px;
            border-radius: 0;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid transparent;
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .btn-view:hover {
            background: #bfdbfe;
            transform: scale(1.02);
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
        }

        .btn-edit {
            padding: 4px 10px;
            border-radius: 0;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
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
            padding: 4px 10px;
            border-radius: 0;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid transparent;
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .btn-delete:hover {
            background: #fecaca;
            transform: scale(1.02);
        }

        /* ---- Empty State ---- */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 3rem;
            display: block;
            margin-bottom: 16px;
            color: #cbd5e1;
        }

        .empty-state h3 {
            color: #1e293b;
            margin-bottom: 8px;
        }

        .empty-state p {
            font-size: 0.95rem;
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
            padding: 32px 30px 28px;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.3);
            animation: slideUp 0.25s ease;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 0;
        }

        #passwordModal .modal-container {
            max-width: 480px;
        }

        .delete-modal .modal-container {
            max-width: 450px;
        }

        .view-modal .modal-container {
            max-width: 580px;
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
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #edf2f7;
        }

        .modal-header-left h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header-left h3 i {
            color: #2563eb;
        }

        .modal-header-left p {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 4px;
        }

        .modal-close-btn {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 8px;
            transition: 0.15s;
            line-height: 1;
            flex-shrink: 0;
        }

        .modal-close-btn:hover {
            color: #1e293b;
        }

        .modal-body {
            padding: 0;
        }

        .modal-body .fa-triangle-exclamation {
            font-size: 3rem;
            color: #dc2626;
            display: block;
            text-align: center;
            margin-bottom: 12px;
        }

        .modal-body h4 {
            text-align: center;
            font-size: 1.1rem;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .modal-body p {
            text-align: center;
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            border-top: 1px solid #edf2f7;
            padding-top: 22px;
        }

        .btn-primary {
            background: #0f172a;
            border: 1px solid #0f172a;
            color: #fff;
            padding: 10px 28px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
            padding: 10px 24px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .btn-secondary:hover {
            background: #e9edf4;
        }

        .btn-danger {
            background: #dc2626;
            border: 1px solid #dc2626;
            color: #fff;
            padding: 10px 28px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-danger:hover {
            background: #b91c1c;
            border-color: #b91c1c;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
        }

        /* ---- Form Styles ---- */
        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            color: #1e293b;
            margin-bottom: 5px;
        }

        .form-group label .required {
            color: #dc2626;
        }

        .form-group .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.95rem;
            background: #fafcff;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .form-group .form-control:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .form-group .form-control[readonly] {
            background: #f1f5f9;
            color: #475569;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-row .form-group {
            margin-bottom: 0;
        }

        /* ---- View Content Modal ---- */
        .content-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .content {
            background: #f8fafc;
            padding: 16px 20px;
            font-size: 0.95rem;
            line-height: 1.7;
            color: #1e293b;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: 1px solid #e2e8f0;
            border-radius: 0;
            max-height: 300px;
            overflow-y: auto;
            min-height: 80px;
        }

        /* ---- Toast ---- */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #0f172a;
            color: #f1f5f9;
            padding: 16px 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: none;
            align-items: center;
            gap: 12px;
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
            font-size: 1.2rem;
        }

        /* ---- Sidebar Overlay ---- */
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
            .page-header {
                flex-direction: column;
            }
            .page-header .action-btn {
                width: 100%;
                justify-content: center;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .form-row .form-group {
                margin-bottom: 18px;
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

            .notif-wrapper {
                align-self: center;
            }

            .notif-dropdown {
                width: 300px;
                right: -10px;
                left: auto;
            }

            .page-card {
                padding: 16px;
            }

            .company-table th,
            .company-table td,
            .job-table th,
            .job-table td {
                padding: 10px 12px;
                font-size: 0.8rem;
            }

            .modal-container {
                padding: 24px 18px;
                max-height: 95vh;
                margin: 10px;
            }

            .btn-view,
            .btn-edit,
            .btn-delete {
                font-size: 0.65rem;
                padding: 3px 8px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn-primary,
            .modal-footer .btn-secondary,
            .modal-footer .btn-danger {
                width: 100%;
                justify-content: center;
            }

            .col-description,
            .col-responsibilities,
            .col-requirements {
                display: none;
            }

            .company-count {
                font-size: 0.75rem;
                padding: 6px 12px;
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
            .company-table td,
            .job-table th,
            .job-table td {
                padding: 8px 6px;
                font-size: 0.7rem;
            }

            .btn-view,
            .btn-edit,
            .btn-delete {
                font-size: 0.6rem;
                padding: 2px 6px;
            }

            .industry-tag {
                font-size: 0.65rem;
                padding: 1px 6px;
            }

            .col-created {
                display: none;
            }

            .col-duration {
                display: none;
            }

            .address-text {
                max-width: 100px;
                font-size: 0.7rem;
            }

            .modal-header-left h3 {
                font-size: 1.1rem;
            }

            .notif-dropdown {
                width: 280px;
                right: -5px;
                left: auto;
            }
        }

        /* ===== PASSWORD MODAL ===== */
        #passwordModal .modal-container {
            max-width: 480px;
        }

        #passwordModal .form-group {
            margin-bottom: 18px;
        }

        #passwordModal .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            color: #1e293b;
            margin-bottom: 5px;
        }

        #passwordModal .form-group label i {
            margin-right: 6px;
            color: #64748b;
        }

        #passwordModal .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.95rem;
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
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            border-top: 1px solid #edf2f7;
            padding-top: 22px;
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
                <i class="fa-solid fa-clipboard-check"></i>
                <h2>System<span>Supervisor Panel</span></h2>
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
                        <i class="fa-solid fa-briefcase"></i>
                        Manage Jobs
                        <small>Supervisor</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Header Navigation -->
                    <nav class="header-nav">
                        <a class="nav-item-header" href="dashboard.php"> Dashboard</a>
                        <a class="nav-item-header active" href="job.php"> Add Job</a>
                        <a class="nav-item-header" href="applicant.php"> Applicants</a>
                        <a class="nav-item-header" href="myintern.php"> My Interns</a>
                    </nav>

                    <!-- Notification bell with dropdown -->
                    <div class="notif-wrapper">
                        <button class="notif-bell" id="notifBell" aria-label="Notifications">
                            <i class="fa-regular fa-bell"></i>
                            <span class="notif-badge <?php echo $unreadCount > 0 ? '' : 'hidden'; ?>" id="notifBadge">
                                <?php echo $unreadCount > 0 ? $unreadCount : ''; ?>
                            </span>
                        </button>

                        <!-- Notification Dropdown -->
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-dropdown-header">
                                <h3>Notifications</h3>
                                <button class="mark-all-read" id="markAllRead">Mark all as read</button>
                            </div>
                            <div class="notif-list" id="notifList">
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $notif): ?>
                                        <?php
                                            $messageText = getSupervisorNotificationMessage($notif);
                                            $source = getSupervisorNotificationSource($notif);
                                        ?>
                                        <a href="<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>"
                                           class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>"
                                           data-id="<?php echo $notif['id']; ?>"
                                           onclick="handleNotificationClick(event, <?php echo $notif['id']; ?>, '<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>')">
                                            <div class="notif-avatar">
                                                <?php if (!empty($notif['profile_picture'])): ?>
                                                    <img src="<?php echo htmlspecialchars($avatarPublicPath . $notif['profile_picture']); ?>" alt="Avatar">
                                                <?php else: ?>
                                                    <?php 
                                                        $initials = strtoupper(substr($notif['firstname'] ?? 'U', 0, 1) . substr($notif['lastname'] ?? 'N', 0, 1));
                                                        echo htmlspecialchars($initials ?: 'UN');
                                                    ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notif-content">
                                                <div class="notif-title"><?php echo htmlspecialchars($notif['title'] ?? 'Notification'); ?></div>
                                                <div class="notif-message"><?php echo htmlspecialchars($messageText); ?></div>
                                                <span class="notif-time"><?php echo htmlspecialchars($source); ?> - <?php echo htmlspecialchars(timeAgo($notif['created_at'] ?? '')); ?></span>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notif-empty">
                                        <i class="fa-regular fa-bell-slash"></i>
                                        <p>No notifications yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- YOUR ASSIGNED COMPANIES -->
            <div class="page-card">
                <div class="page-header">
                    <div>
                        <h2><i class="fa-regular fa-building"></i> Your Assigned Companies</h2>
                        <p>Companies assigned to your supervisor account</p>
                    </div>
                    <div class="company-count">
                        <i class="fa-regular fa-building"></i>
                        <span><?php echo count($assignedCompanies); ?></span> 
                        <?php echo count($assignedCompanies) === 1 ? 'company' : 'companies'; ?>
                    </div>
                </div>

                <?php if (count($assignedCompanies) > 0): ?>
                    <div class="table-wrapper">
                        <div class="table-container">
                            <table class="company-table">
                                <thead>
                                    <tr>
                                        <th>Company Name</th>
                                        <th>Industry</th>
                                        <th>Contact Person</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignedCompanies as $company): ?>
                                        <tr>
                                            <td class="company-name-cell">
                                                <i class="fa-solid fa-building" style="color: #2563eb; margin-right: 8px;"></i>
                                                <?php echo htmlspecialchars($company['company_name']); ?>
                                            </td>
                                            <td><span class="industry-tag"><?php echo htmlspecialchars($company['industry']); ?></span></td>
                                            <td><i class="fa-regular fa-user" style="color: #94a3b8; margin-right: 6px;"></i><?php echo htmlspecialchars($company['contact_person']); ?></td>
                                            <td>
                                                <?php if ($company['contact_email']): ?>
                                                    <a href="mailto:<?php echo htmlspecialchars($company['contact_email']); ?>" class="email-link">
                                                        <i class="fa-regular fa-envelope" style="margin-right: 4px;"></i><?php echo htmlspecialchars($company['contact_email']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($company['contact_number']): ?>
                                                    <i class="fa-solid fa-phone" style="color: #94a3b8; margin-right: 4px;"></i><?php echo htmlspecialchars($company['contact_number']); ?>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="address-text">
                                                    <i class="fa-solid fa-location-dot" style="color: #94a3b8; margin-right: 4px;"></i><?php echo nl2br(htmlspecialchars($company['address'])); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-briefcase"></i>
                        <h3>No Companies Assigned</h3>
                        <p>You haven't been assigned to any companies yet. Please contact your coordinator.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- INTERNSHIP POSITIONS -->
            <div class="page-card">
                <div class="page-header">
                    <div>
                        <h2><i class="fa-regular fa-briefcase"></i> Internship Positions</h2>
                        <p>Create and manage internship roles for your assigned companies.</p>
                    </div>
                    <?php if (count($assignedCompanies) > 0): ?>
                        <button class="action-btn" type="button" onclick="openJobModal()"><i class="fa-solid fa-plus"></i> Add Job</button>
                    <?php endif; ?>
                </div>

                <?php if (count($jobs) > 0): ?>
                    <div class="table-wrapper">
                        <div class="table-container">
                            <table class="job-table">
                                <thead>
                                    <tr>
                                        <th class="col-title">Title</th>
                                        <th class="col-company">Company</th>
                                        <th class="col-available">Available</th>
                                        <th class="col-filled">Filled</th>
                                        <th class="col-duration">Duration</th>
                                        <th class="col-created">Created</th>
                                        <th class="col-description">Desc</th>
                                        <th class="col-responsibilities">Resp</th>
                                        <th class="col-requirements">Req</th>
                                        <th class="col-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($jobs as $job): ?>
                                        <tr>
                                            <td class="col-title"><strong><?php echo htmlspecialchars($job['title']); ?></strong></td>
                                            <td class="col-company"><?php echo htmlspecialchars($job['company_name']); ?></td>
                                            <td class="col-available">
                                                <?php 
                                                $available = (int)$job['slots_available'] - (int)$job['slots_filled'];
                                                $color = $available > 0 ? '#16a34a' : '#dc2626';
                                                ?>
                                                <span style="font-weight: 600; color: <?php echo $color; ?>;">
                                                    <?php echo $available; ?>
                                                </span>
                                            </td>
                                            <td class="col-filled">
                                                <span style="font-weight: 600; color: #2563eb;">
                                                    <?php echo (int)$job['slots_filled']; ?>
                                                </span>
                                            </td>
                                            <td class="col-duration"><?php echo (int)$job['duration_hours'] > 0 ? (int)$job['duration_hours'] . ' hrs' : '-'; ?></td>
                                            <td class="col-created"><?php echo htmlspecialchars(date('M d, Y', strtotime($job['created_at']))); ?></td>
                                            <td class="col-description">
                                                <button class="btn-view" onclick="viewContent('description', <?php echo (int)$job['id']; ?>)">
                                                    <i class="fa-solid fa-file-lines"></i>
                                                </button>
                                            </td>
                                            <td class="col-responsibilities">
                                                <button class="btn-view" onclick="viewContent('responsibility', <?php echo (int)$job['id']; ?>)">
                                                    <i class="fa-solid fa-tasks"></i>
                                                </button>
                                            </td>
                                            <td class="col-requirements">
                                                <button class="btn-view" onclick="viewContent('requirements', <?php echo (int)$job['id']; ?>)">
                                                    <i class="fa-solid fa-list-check"></i>
                                                </button>
                                            </td>
                                            <td class="col-actions action-column">
                                                <div class="action-buttons">
                                                    <button class="btn-edit" onclick="editJob(<?php echo (int)$job['id']; ?>)">
                                                        <i class="fa-solid fa-edit"></i>
                                                    </button>
                                                    <button class="btn-delete" onclick="confirmDelete(<?php echo (int)$job['id']; ?>, '<?php echo htmlspecialchars($job['title']); ?>')">
                                                        <i class="fa-solid fa-trash-alt"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-briefcase"></i>
                        <h3>No Internship Positions Yet</h3>
                        <p>Create your first opportunity for one of your assigned companies.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Create Job Modal -->
    <div id="jobModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-header-left">
                    <h3><i class="fa-solid fa-plus-circle" style="color: #2563eb; margin-right: 10px;"></i>Create Internship Position</h3>
                    <p>Fill in the details below to post a new internship opportunity</p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeJobModal()">&times;</button>
            </div>
            
            <form method="POST" action="job.php">
                <input type="hidden" name="action" value="create_job">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Company <span class="required">*</span></label>
                        <select name="company_id" class="form-control" required>
                            <option value="">Select an assigned company</option>
                            <?php foreach ($assignedCompanies as $company): ?>
                                <option value="<?php echo (int)$company['id']; ?>"><?php echo htmlspecialchars($company['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Job Title <span class="required">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. Software Development Intern" />
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe the internship role and what the intern will do..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Responsibilities</label>
                        <textarea name="responsibility" class="form-control" rows="3" placeholder="List the responsibilities of this position..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Requirements</label>
                        <textarea name="requirements" class="form-control" rows="3" placeholder="List the requirements for this position..."></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Slots Available <span class="required">*</span></label>
                            <input type="number" name="slots_available" class="form-control" min="1" value="1" required />
                        </div>
                        <div class="form-group">
                            <label>Duration (Hours)</label>
                            <input type="number" name="duration_hours" class="form-control" min="0" value="0" placeholder="e.g. 400" />
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeJobModal()">Cancel</button>
                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-save"></i> Create Job
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Job Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-header-left">
                    <h3><i class="fa-solid fa-pen" style="color: #2563eb; margin-right: 10px;"></i>Edit Internship Position</h3>
                    <p>Update the details of this internship opportunity</p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeEditModal()">&times;</button>
            </div>
            
            <form method="POST" action="job.php">
                <input type="hidden" name="action" value="update_job">
                <input type="hidden" name="job_id" id="edit_job_id">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Company <span class="required">*</span></label>
                        <select name="company_id" id="edit_company_id" class="form-control" required>
                            <option value="">Select an assigned company</option>
                            <?php foreach ($assignedCompanies as $company): ?>
                                <option value="<?php echo (int)$company['id']; ?>"><?php echo htmlspecialchars($company['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Job Title <span class="required">*</span></label>
                        <input type="text" name="title" id="edit_title" class="form-control" required placeholder="e.g. Software Development Intern" />
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3" placeholder="Describe the internship role and what the intern will do..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Responsibilities</label>
                        <textarea name="responsibility" id="edit_responsibility" class="form-control" rows="3" placeholder="List the responsibilities of this position..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Requirements</label>
                        <textarea name="requirements" id="edit_requirements" class="form-control" rows="3" placeholder="List the requirements for this position..."></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Slots Available <span class="required">*</span></label>
                            <input type="number" name="slots_available" id="edit_slots_available" class="form-control" min="1" required />
                        </div>
                        <div class="form-group">
                            <label>Duration (Hours)</label>
                            <input type="number" name="duration_hours" id="edit_duration_hours" class="form-control" min="0" placeholder="e.g. 400" />
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-save"></i> Update Job
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-overlay delete-modal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-header-left">
                    <h3><i class="fa-solid fa-trash" style="color: #dc2626; margin-right: 10px;"></i>Delete Job</h3>
                    <p>Confirm deletion of this position</p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeDeleteModal()">&times;</button>
            </div>
            
            <form method="POST" action="job.php">
                <input type="hidden" name="action" value="delete_job">
                <input type="hidden" name="job_id" id="delete_job_id">
                
                <div class="modal-body">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <h4>Are you sure?</h4>
                    <p>You are about to delete "<span id="delete_job_title"></span>". This action cannot be undone.</p>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-danger">
                        <i class="fa-solid fa-trash"></i> Delete Job
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Content Modal -->
    <div id="viewModal" class="modal-overlay view-modal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-header-left">
                    <h3 id="viewModalTitle"><i class="fa-solid fa-file-lines" style="color: #2563eb; margin-right: 10px;"></i>Content</h3>
                    <p id="viewModalSubtitle">View details</p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeViewModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="content-label" id="viewContentLabel">Description</div>
                <div class="content" id="viewContent"></div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-header-left">
                    <h3><i class="fa-solid fa-key"></i> Change Password</h3>
                    <p>Enter your current password and choose a new one.</p>
                </div>
                <button type="button" class="modal-close-btn" id="closePasswordBtn">&times;</button>
            </div>
            <form id="passwordForm">
                <div class="modal-body">
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="closePasswordBtn2">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <script>
        // Store job data for view modal
        const jobData = <?php echo json_encode($jobs); ?>;

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

        // Check for session toast messages
        <?php if (!empty($toastMessage)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo htmlspecialchars($toastMessage); ?>', '<?php echo $toastType; ?>');
            });
        <?php endif; ?>

        // Toast click to dismiss
        document.getElementById('toast').addEventListener('click', function() {
            this.classList.remove('show');
        });

        // ===== NOTIFICATION FUNCTIONS =====

        /**
         * Toggle notification dropdown
         */
        function toggleNotifications() {
            const dropdown = document.getElementById('notifDropdown');
            const bell = document.getElementById('notifBell');
            
            if (dropdown.classList.contains('active')) {
                dropdown.classList.remove('active');
            } else {
                dropdown.classList.add('active');
                // Load fresh notifications when opening
                loadNotifications();
            }
        }

        /**
         * Load notifications via AJAX
         */
        function loadNotifications() {
            const formData = new FormData();
            formData.append('action', 'get_notifications');
            formData.append('limit', '20');
            formData.append('offset', '0');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderNotifications(data.notifications, data.unread_count);
                    updateBadge(data.unread_count);
                }
            })
            .catch(error => {
                console.error('Error loading notifications:', error);
            });
        }

        /**
         * Render notifications in dropdown
         */
        function renderNotifications(notifications, unreadCount) {
            const list = document.getElementById('notifList');
            const badge = document.getElementById('notifBadge');
            
            if (!notifications || notifications.length === 0) {
                list.innerHTML = `
                    <div class="notif-empty">
                        <i class="fa-regular fa-bell-slash"></i>
                        <p>No notifications yet</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            notifications.forEach(notif => {
                const isUnread = notif.is_read == 0;
                const link = notif.link || '#';
                const avatarUrl = notif.profile_picture ? '<?php echo $avatarPublicPath; ?>' + notif.profile_picture : '';
                const initials = notif.firstname && notif.lastname ? 
                    (notif.firstname.charAt(0) + notif.lastname.charAt(0)).toUpperCase() : 'UN';
                
                html += `
                    <a href="${link}" 
                       class="notif-item ${isUnread ? 'unread' : ''}"
                       data-id="${notif.id}"
                       onclick="handleNotificationClick(event, ${notif.id}, '${link}')">
                        <div class="notif-avatar">
                            ${avatarUrl ? `<img src="${avatarUrl}" alt="Avatar">` : initials}
                        </div>
                        <div class="notif-content">
                            <div class="notif-title">${escapeHtml(notif.title || 'Notification')}</div>
                            <div class="notif-message">${escapeHtml(notif.message || '')}</div>
                            <span class="notif-time">${escapeHtml((notif.firstname && notif.lastname) ? `${notif.firstname} ${notif.lastname}` : 'System')} - ${escapeHtml(notif.message || '')} - ${timeAgo(notif.created_at)}</span>
                        </div>
                    </a>
                `;
            });
            
            list.innerHTML = html;
            updateBadge(unreadCount);
        }

        /**
         * Update notification badge count
         */
        function updateBadge(count) {
            const badge = document.getElementById('notifBadge');
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }

        /**
         * Handle notification click
         */
        function handleNotificationClick(event, notificationId, link) {
            event.preventDefault();
            
            const item = event.currentTarget;
            item.classList.remove('unread');
            // Mark as read
            markNotificationRead(notificationId, function() {
                // Navigate to the link
                if (link && link !== '#') {
                    window.location.href = link;
                } else {
                    // Close dropdown
                    document.getElementById('notifDropdown').classList.remove('active');
                }
            });
        }

        /**
         * Mark a single notification as read
         */
        function markNotificationRead(notificationId, callback) {
            const formData = new FormData();
            formData.append('action', 'mark_read');
            formData.append('notification_id', notificationId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateBadge(data.unread_count);
                    // Update the UI immediately
                    const item = document.querySelector(`.notif-item[data-id="${notificationId}"]`);
                    if (item) {
                        item.classList.remove('unread');
                    }
                    if (callback) callback();
                }
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
                if (callback) callback();
            });
        }

        /**
         * Mark all notifications as read
         */
        function markAllNotificationsRead() {
            const formData = new FormData();
            formData.append('action', 'mark_all_read');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateBadge(0);
                    // Update all items in dropdown
                    document.querySelectorAll('.notif-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    showToast('All notifications marked as read', 'success');
                }
            })
            .catch(error => {
                console.error('Error marking all as read:', error);
            });
        }

        /**
         * Get time ago string
         */
        function timeAgo(dateStr) {
            if (!dateStr) return '';
            
            const date = new Date(dateStr);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);
            
            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            if (diff < 2592000) return Math.floor(diff / 604800) + 'w ago';
            return date.toLocaleDateString();
        }

        /**
         * Escape HTML for safe display
         */
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ===== NOTIFICATION EVENT LISTENERS =====
        
        // Notification bell toggle
        document.getElementById('notifBell').addEventListener('click', function(e) {
            e.stopPropagation();
            toggleNotifications();
        });

        // Close dropdown on outside click
        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.notif-wrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                document.getElementById('notifDropdown').classList.remove('active');
            }
        });

        // Mark all as read
        document.getElementById('markAllRead').addEventListener('click', function(e) {
            e.stopPropagation();
            markAllNotificationsRead();
        });

        // Periodically check for new notifications (every 30 seconds)
        setInterval(function() {
            const formData = new FormData();
            formData.append('action', 'get_notifications');
            formData.append('limit', '1');
            formData.append('offset', '0');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateBadge(data.unread_count);
                }
            })
            .catch(error => {
                console.error('Error checking notifications:', error);
            });
        }, 30000);

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
        function openJobModal() {
            document.getElementById('jobModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeJobModal() {
            document.getElementById('jobModal').style.display = 'none';
            document.body.style.overflow = '';
        }
        
        function editJob(jobId) {
            // Find the job data
            const job = jobData.find(j => j.id === jobId);
            if (!job) {
                showToast('Job not found', 'error');
                return;
            }
            
            // Populate edit form
            document.getElementById('edit_job_id').value = job.id;
            document.getElementById('edit_company_id').value = job.company_id;
            document.getElementById('edit_title').value = job.title;
            document.getElementById('edit_description').value = job.description || '';
            document.getElementById('edit_responsibility').value = job.responsibility || '';
            document.getElementById('edit_requirements').value = job.requirements || '';
            document.getElementById('edit_slots_available').value = job.slots_available;
            document.getElementById('edit_duration_hours').value = job.duration_hours || 0;
            
            // Open edit modal
            document.getElementById('editModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.body.style.overflow = '';
        }
        
        function confirmDelete(jobId, jobTitle) {
            document.getElementById('delete_job_id').value = jobId;
            document.getElementById('delete_job_title').textContent = jobTitle;
            document.getElementById('deleteModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            document.body.style.overflow = '';
        }
        
        function viewContent(type, jobId) {
            const job = jobData.find(j => j.id === jobId);
            if (!job) {
                showToast('Job not found', 'error');
                return;
            }
            
            let content = '';
            let label = '';
            let title = '';
            let subtitle = '';
            
            if (type === 'description') {
                content = job.description || 'No description provided.';
                label = 'Description';
                title = 'Job Description';
                subtitle = 'Detailed description of the internship position';
            } else if (type === 'responsibility') {
                content = job.responsibility || 'No responsibilities listed.';
                label = 'Responsibilities';
                title = 'Job Responsibilities';
                subtitle = 'Responsibilities for this internship position';
            } else if (type === 'requirements') {
                content = job.requirements || 'No requirements provided.';
                label = 'Requirements';
                title = 'Job Requirements';
                subtitle = 'Requirements for this internship position';
            }
            
            document.getElementById('viewContentLabel').textContent = label;
            document.getElementById('viewContent').textContent = content;
            document.getElementById('viewModalTitle').innerHTML = '<i class="fa-solid fa-file-lines" style="color: #2563eb; margin-right: 10px;"></i>' + title;
            document.getElementById('viewModalSubtitle').textContent = subtitle;
            
            document.getElementById('viewModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
            document.body.style.overflow = '';
        }
        
        // Close modals when clicking outside
        document.getElementById('jobModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeJobModal();
            }
        });
        
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
        
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });
        
        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeViewModal();
            }
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeJobModal();
                closeEditModal();
                closeDeleteModal();
                closeViewModal();
                closePasswordModal();
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
                if (document.getElementById('notifDropdown').classList.contains('active')) {
                    document.getElementById('notifDropdown').classList.remove('active');
                }
            }
        });
    </script>
</body>
</html>