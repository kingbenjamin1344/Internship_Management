<?php
// supervisor/applicant.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/supervisor_notifications.php';

// Check if user is supervisor
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

// Get notifications count for display
$unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);
$notifications = getSupervisorNotifications($pdo, $userId, 10, 0);

// Handle Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $applicationId = (int)($_POST['application_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');

    // Get current status and job info to properly manage vacancy slots
    $checkStmt = $pdo->prepare('
        SELECT a.id, a.status AS current_status, a.job_id, a.student_id,
               j.slots_available, j.slots_filled,
               u.firstname AS student_firstname, u.lastname AS student_lastname,
               u.email AS student_email
        FROM job_applications a
        INNER JOIN jobs j ON a.job_id = j.id
        INNER JOIN users u ON a.student_id = u.id
        LEFT JOIN companies c ON j.company_id = c.id
        WHERE a.id = ? AND (c.supervisor_id = ? OR j.created_by = ?)
    ');
    $checkStmt->execute([$applicationId, $userId, $userId]);
    $appInfo = $checkStmt->fetch();

    if ($appInfo) {
        $oldStatus = $appInfo['current_status'];
        $jobId = $appInfo['job_id'];
        $studentId = $appInfo['student_id'];
        $studentName = trim(($appInfo['student_firstname'] ?? '') . ' ' . ($appInfo['student_lastname'] ?? ''));

        if ($newStatus !== $oldStatus) {
            $pdo->beginTransaction();
            try {
                // Update application status
                $updateStmt = $pdo->prepare('UPDATE job_applications SET status = ?, updated_at = NOW() WHERE id = ?');
                $updateStmt->execute([$newStatus, $applicationId]);

                // Manage vacancy counters: increment if accepted, decrement if changed from accepted
                if ($newStatus === 'accepted' && $oldStatus !== 'accepted') {
                    $pdo->prepare('UPDATE jobs SET slots_filled = slots_filled + 1 WHERE id = ?')->execute([$jobId]);
                    
                    // Get job title for notification
                    $jobStmt = $pdo->prepare('SELECT title FROM jobs WHERE id = ?');
                    $jobStmt->execute([$jobId]);
                    $jobTitle = $jobStmt->fetchColumn() ?: 'a position';
                    
                    // Create notification for student when accepted
                    createSystemNotification(
                        $pdo,
                        $studentId,
                        $userId,
                        'application_accepted',
                        'Application Accepted',
                        'Congratulations! Your application for "' . $jobTitle . '" has been accepted by the supervisor.',
                        'applications.php'
                    );
                    
                } elseif ($newStatus === 'rejected' && $oldStatus !== 'rejected') {
                    // Get job title for notification
                    $jobStmt = $pdo->prepare('SELECT title FROM jobs WHERE id = ?');
                    $jobStmt->execute([$jobId]);
                    $jobTitle = $jobStmt->fetchColumn() ?: 'a position';
                    
                    // Create notification for student when rejected
                    createSystemNotification(
                        $pdo,
                        $studentId,
                        $userId,
                        'application_rejected',
                        'Application Update',
                        'Your application for "' . $jobTitle . '" was not successful this time. Keep applying!',
                        'applications.php'
                    );
                    
                } elseif ($oldStatus === 'accepted' && $newStatus !== 'accepted') {
                    $pdo->prepare('UPDATE jobs SET slots_filled = GREATEST(0, slots_filled - 1) WHERE id = ?')->execute([$jobId]);
                }

                $pdo->commit();
                
                // User-friendly messages
                $statusDisplay = ucfirst($newStatus);
                if (!empty($studentName)) {
                    $_SESSION['toast_message'] = $studentName . ' has been ' . strtolower($statusDisplay) . ' successfully!';
                } else {
                    $_SESSION['toast_message'] = 'Applicant has been ' . strtolower($statusDisplay) . ' successfully!';
                }
                $_SESSION['toast_type'] = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['toast_message'] = 'Unable to update applicant status. Please try again.';
                $_SESSION['toast_type'] = 'error';
            }
        } else {
            $_SESSION['toast_message'] = 'No changes made to applicant status.';
            $_SESSION['toast_type'] = 'info';
        }
    } else {
        $_SESSION['toast_message'] = 'Application record not found. Please refresh and try again.';
        $_SESSION['toast_type'] = 'error';
    }

    header('Location: applicant.php');
    exit;
}

// Handle Notes / Interview details save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_notes') {
    $applicationId = (int)($_POST['application_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');

    $pdo->beginTransaction();
    try {
        // Update only the status if provided
        if (!empty($newStatus)) {
            // Get current status and job info to manage vacancy slots
            $checkStmt = $pdo->prepare('
                SELECT a.status AS current_status, a.job_id, a.student_id
                FROM job_applications a
                INNER JOIN jobs j ON a.job_id = j.id
                LEFT JOIN companies c ON j.company_id = c.id
                WHERE a.id = ? AND (c.supervisor_id = ? OR j.created_by = ?)
            ');
            $checkStmt->execute([$applicationId, $userId, $userId]);
            $appInfo = $checkStmt->fetch();

            if ($appInfo) {
                $oldStatus = $appInfo['current_status'];
                $jobId = $appInfo['job_id'];
                $studentId = $appInfo['student_id'];

                if ($newStatus !== $oldStatus) {
                    $updateStmt = $pdo->prepare('UPDATE job_applications SET status = ?, updated_at = NOW() WHERE id = ?');
                    $updateStmt->execute([$newStatus, $applicationId]);

                    // Manage vacancy counters: increment if accepted, decrement if changed from accepted
                    if ($newStatus === 'accepted' && $oldStatus !== 'accepted') {
                        $pdo->prepare('UPDATE jobs SET slots_filled = slots_filled + 1 WHERE id = ?')->execute([$jobId]);
                        
                        // Get job title for notification
                        $jobStmt = $pdo->prepare('SELECT title FROM jobs WHERE id = ?');
                        $jobStmt->execute([$jobId]);
                        $jobTitle = $jobStmt->fetchColumn() ?: 'a position';
                        
                        // Create notification for student when accepted
                        createSystemNotification(
                            $pdo,
                            $studentId,
                            $userId,
                            'application_accepted',
                            'Application Accepted',
                            'Congratulations! Your application for "' . $jobTitle . '" has been accepted by the supervisor.',
                            'applications.php'
                        );
                        
                    } elseif ($newStatus === 'rejected' && $oldStatus !== 'rejected') {
                        // Get job title for notification
                        $jobStmt = $pdo->prepare('SELECT title FROM jobs WHERE id = ?');
                        $jobStmt->execute([$jobId]);
                        $jobTitle = $jobStmt->fetchColumn() ?: 'a position';
                        
                        // Create notification for student when rejected
                        createSystemNotification(
                            $pdo,
                            $studentId,
                            $userId,
                            'application_rejected',
                            'Application Update',
                            'Your application for "' . $jobTitle . '" was not successful this time. Keep applying!',
                            'applications.php'
                        );
                        
                    } elseif ($oldStatus === 'accepted' && $newStatus !== 'accepted') {
                        $pdo->prepare('UPDATE jobs SET slots_filled = GREATEST(0, slots_filled - 1) WHERE id = ?')->execute([$jobId]);
                    }
                }
            }
        }

        $pdo->commit();
        $_SESSION['toast_message'] = 'Applicant status updated successfully.';
        $_SESSION['toast_type'] = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['toast_message'] = 'Failed to update applicant. Please try again.';
        $_SESSION['toast_type'] = 'error';
    }

    header('Location: applicant.php');
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

    // Get notifications
    if ($_POST['action'] === 'get_notifications') {
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        
        $notifications = getSupervisorNotifications($pdo, $userId, $limit, $offset);
        $unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'total' => count($notifications)
        ]);
        exit;
    }

    // Mark notification as read
    if ($_POST['action'] === 'mark_read') {
        $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        
        if ($notification_id > 0) {
$result = markSupervisorNotificationRead($pdo, $notification_id, $userId);
        $unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);
            
            echo json_encode([
                'success' => $result,
                'unread_count' => $unreadCount
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        }
        exit;
    }

    // Mark all notifications as read
    if ($_POST['action'] === 'mark_all_read') {
        $result = markSupervisorAllNotificationsRead($pdo, $userId);
        echo json_encode([
            'success' => $result,
            'unread_count' => 0
        ]);
        exit;
    }
}

// Fetch ACTIVE applicants (pending, reviewed, shortlisted, etc.)
// EXCLUDING accepted, rejected, committed
$applicantsStmt = $pdo->prepare('
    SELECT a.*, 
           j.title AS job_title, j.slots_available, j.slots_filled,
           c.company_name,
           u.username AS student_username, u.email AS student_email,
           u.firstname AS student_firstname, u.middlename AS student_middlename,
           u.lastname AS student_lastname, u.suffix AS student_suffix,
           u.phone AS student_phone, u.address AS student_address, u.birthdate AS student_birthdate
    FROM job_applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN companies c ON j.company_id = c.id
    INNER JOIN users u ON a.student_id = u.id
    WHERE (c.supervisor_id = ? OR j.created_by = ?)
    AND a.status NOT IN ("accepted", "rejected", "committed")
    ORDER BY a.application_date DESC
');
$applicantsStmt->execute([$userId, $userId]);
$applicants = $applicantsStmt->fetchAll();

// Fetch DECISION applicants (accepted, rejected, committed) - SHOW ALL of them
$decisionStmt = $pdo->prepare('
    SELECT a.*, 
           j.title AS job_title, j.slots_available, j.slots_filled,
           c.company_name,
           u.username AS student_username, u.email AS student_email,
           u.firstname AS student_firstname, u.middlename AS student_middlename,
           u.lastname AS student_lastname, u.suffix AS student_suffix,
           u.phone AS student_phone, u.address AS student_address, u.birthdate AS student_birthdate
    FROM job_applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN companies c ON j.company_id = c.id
    INNER JOIN users u ON a.student_id = u.id
    WHERE (c.supervisor_id = ? OR j.created_by = ?)
    AND a.status IN ("accepted", "rejected", "committed")
    ORDER BY a.updated_at DESC, a.application_date DESC
');
$decisionStmt->execute([$userId, $userId]);
$decisionApplicants = $decisionStmt->fetchAll();

$activeApplicants = array_values(array_filter($applicants, function ($app) {
    return !in_array($app['status'], ['accepted', 'rejected', 'committed'], true);
}));

$decisionApplicants = array_values(array_filter($decisionApplicants, function ($app) {
    return in_array($app['status'], ['accepted', 'rejected', 'committed'], true);
}));

function getFileIconClass($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return 'fa-solid fa-file-pdf';
    } elseif (in_array($ext, ['doc', 'docx'])) {
        return 'fa-solid fa-file-word';
    }
    return 'fa-solid fa-file-lines';
}

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
    <title>Supervisor - Manage Applicants</title>
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

        .page-card h2 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0f172a;
        }

        .page-card h2 i {
            color: #3b82f6;
        }

        .page-card p {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 2px;
        }

        /* ---- Search Row ---- */
        .search-row {
            margin-bottom: 20px;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 4px 16px;
            max-width: 400px;
            border-radius: 0;
        }

        .search-box i {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .search-box input {
            border: none;
            background: transparent;
            padding: 10px 0;
            font-size: 0.9rem;
            width: 100%;
            outline: none;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .search-box input::placeholder {
            color: #94a3b8;
        }

        /* ---- Decision Toolbar ---- */
        .decision-toolbar {
            margin-bottom: 16px;
        }

        .decision-filter {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 0.85rem;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border-radius: 0;
            cursor: pointer;
            color: #1e293b;
        }

        .decision-filter:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
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

        .applicant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .applicant-table th {
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

        .applicant-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .applicant-table tbody tr:last-child td {
            border-bottom: none;
        }

        .applicant-table tbody tr:hover {
            background: #fafcff;
        }

        .action-column {
            min-width: 160px;
        }

        /* ---- Status Buttons ---- */
        .action-buttons-inline {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-status-accept {
            padding: 5px 14px;
            border-radius: 0;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid transparent;
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .btn-status-accept:hover {
            background: #bbf7d0;
            transform: scale(1.02);
        }

        .btn-status-reject {
            padding: 5px 14px;
            border-radius: 0;
            font-size: 0.7rem;
            font-weight: 600;
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

        .btn-status-reject:hover {
            background: #fecaca;
            transform: scale(1.02);
        }

        .btn-view {
            padding: 6px 16px;
            border-radius: 0;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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

        .doc-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            font-size: 0.7rem;
            font-weight: 500;
            color: #2563eb;
            cursor: pointer;
            transition: 0.15s;
            text-decoration: none;
            border-radius: 0;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .doc-link:hover {
            background: #dbeafe;
            border-color: #93c5fd;
        }

        .doc-link i {
            font-size: 0.75rem;
        }

        /* ---- Status Chips ---- */
        .status-chip {
            padding: 4px 14px;
            border-radius: 0;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            border: 1px solid transparent;
        }

        .status-chip.accepted {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .status-chip.rejected {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .status-chip.committed {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .status-chip.withdrawn {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }

        /* ===== PAGINATION (bottom right - edge of page) ===== */
        .pagination-wrapper {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-top: 16px;
            gap: 6px;
            flex-wrap: wrap;
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
            width: 100%;
        }

        .pagination-wrapper .page-info {
            font-size: 0.8rem;
            color: #64748b;
            margin-right: auto;
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
            max-width: 640px;
            width: 100%;
            padding: 32px 30px 28px;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.3);
            animation: slideUp 0.25s ease;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 0;
        }

        #docViewerModal .modal-container {
            max-width: 900px;
            height: 90vh;
            padding: 24px 28px 28px;
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
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #edf2f7;
        }

        .modal-header h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 i {
            color: #2563eb;
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
        }

        .modal-close-btn:hover {
            color: #1e293b;
        }

        .modal-body {
            padding: 0;
        }

        .modal-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 24px;
            border-top: 1px solid #edf2f7;
            padding-top: 22px;
            width: 100%;
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

        .btn-sec-outline {
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

        .btn-sec-outline:hover {
            background: #e9edf4;
        }

        .btn-prim-blue {
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

        .btn-prim-blue:hover:not(:disabled) {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        /* ---- Details Grid ---- */
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        @media (max-width: 500px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .detail-card {
            background: #f8fafc;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 0;
        }

        .detail-card .lbl {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 4px;
        }

        .detail-card .val {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.9rem;
        }

        .content-section {
            margin-top: 16px;
            border-top: 1px solid #edf2f7;
            padding-top: 16px;
        }

        .content-section h4 {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .content-section h4 i {
            color: #64748b;
        }

        .content-body {
            background: #f8fafc;
            padding: 14px 18px;
            font-size: 0.9rem;
            line-height: 1.7;
            color: #1e293b;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: 1px solid #e2e8f0;
            border-radius: 0;
            max-height: 150px;
            overflow-y: auto;
        }

        /* ---- Document Viewer ---- */
        #docViewerModal .modal-body {
            padding: 0;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            max-height: none;
            height: calc(100% - 60px);
        }

        #docViewerIframe {
            width: 100%;
            height: 100%;
            border: none;
            display: none;
        }

        #docViewerLoading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
            padding: 40px;
            color: #64748b;
        }

        #docViewerLoading i {
            font-size: 32px;
            margin-bottom: 12px;
            color: #2563eb;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        #docViewerFallback {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
            padding: 40px;
            text-align: center;
        }

        #docViewerFallback i {
            font-size: 64px;
            color: #2b579a;
            margin-bottom: 20px;
        }

        #docViewerFallback h4 {
            margin: 0 0 10px;
            color: #0f172a;
            font-size: 1.2rem;
        }

        #docViewerFallback p {
            margin: 0 0 24px;
            color: #64748b;
            max-width: 400px;
            font-size: 0.9rem;
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

        .toast.info {
            background: #2563eb;
            border-color: #1d4ed8;
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
            .page-card h2 {
                font-size: 1.1rem;
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

            .page-card {
                padding: 16px;
            }

            .applicant-table th,
            .applicant-table td {
                padding: 10px 12px;
                font-size: 0.8rem;
            }

            .modal-container {
                padding: 24px 18px;
                max-height: 95vh;
                margin: 10px;
            }

            .btn-view,
            .btn-status-accept,
            .btn-status-reject {
                font-size: 0.65rem;
                padding: 4px 10px;
            }

            .doc-link {
                font-size: 0.65rem;
                padding: 2px 8px;
            }

            .action-buttons-inline {
                flex-direction: column;
                gap: 4px;
            }

            .action-column {
                min-width: 120px;
            }

            .modal-footer {
                flex-direction: column;
                gap: 12px;
            }

            .modal-footer .btn-primary,
            .modal-footer .btn-secondary,
            .modal-footer .btn-sec-outline,
            .modal-footer .btn-prim-blue {
                width: 100%;
                justify-content: center;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            #docViewerModal .modal-container {
                height: 95vh;
                padding: 16px;
            }

            .search-box {
                max-width: 100%;
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

            .applicant-table th,
            .applicant-table td {
                padding: 8px 6px;
                font-size: 0.7rem;
            }

            .btn-view,
            .btn-status-accept,
            .btn-status-reject {
                font-size: 0.6rem;
                padding: 3px 6px;
            }

            .status-chip {
                font-size: 0.65rem;
                padding: 2px 8px;
            }

            .doc-link {
                font-size: 0.6rem;
                padding: 2px 6px;
            }

            .decision-filter {
                font-size: 0.75rem;
                padding: 6px 12px;
                width: 100%;
            }

            .modal-header h3 {
                font-size: 1.1rem;
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

        .notif-wrapper {
            position: relative;
            display: inline-block;
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
                        <i class="fa-solid fa-users"></i>
                        Manage Applicants
                        <small>Supervisor</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Header Navigation -->
                    <nav class="header-nav">
                        <a class="nav-item-header" href="dashboard.php"> Dashboard</a>
                        <a class="nav-item-header" href="job.php"> Add Job</a>
                        <a class="nav-item-header active" href="applicant.php"> Applicants</a>
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

            <!-- Table Card 1: Active Applicants -->
            <div class="page-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h2><i class="fa-regular fa-users"></i> Applied Candidates</h2>
                        <p style="margin: 4px 0 0; font-size: 0.85rem; color:#64748b;">Review qualification documents and update statuses of student applicants.</p>
                    </div>
                    <div style="font-size: 0.85rem; font-weight: 600; color: #475569; padding: 8px 16px; background: #f8fafc; border: 1px solid #e2e8f0;">
                        Active Applicants: <span style="color:#2563eb; font-weight: 700;" id="activeCount"><?php echo count($activeApplicants); ?></span>
                    </div>
                </div>

                <!-- Live Search Box -->
                <div class="search-row">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="applicantSearchInput" onkeyup="filterApplicantsTable()" placeholder="Search by student name, company, or job title..." />
                    </div>
                </div>

                <?php if (count($activeApplicants) > 0): ?>
                    <div class="table-wrapper">
                        <div class="table-container" id="activeTableContainer">
                            <table class="applicant-table" id="applicantsTable">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Applied Position</th>
                                        <th>Company</th>
                                        <th>Applied Date</th>
                                        <th>Documents</th>
                                        <th class="action-column">Action</th>
                                        <th style="text-align: center;">Details</th>
                                    </tr>
                                </thead>
                                <tbody id="activeTableBody">
                                    <!-- Populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Pagination for Active Applicants -->
                    <div class="pagination-wrapper" id="activePagination">
                        <span class="page-info" id="activePageInfo">Showing 1–10 of 0</span>
                        <a href="#" class="page-link disabled" id="activePrevPage">Prev</a>
                        <span id="activePageNumbers"></span>
                        <a href="#" class="page-link" id="activeNextPage">Next</a>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="margin-top: 16px;">
                        <i class="fa-solid fa-users"></i>
                        <h3>No Student Applications</h3>
                        <p>No undecided student applications are available right now.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Table Card 2: Decision Applicants -->
            <div class="page-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h2><i class="fa-regular fa-badge-check"></i> Applied Candidates Decisions</h2>
                        <p style="margin: 4px 0 0; font-size: 0.85rem; color:#64748b;">Accepted and rejected applicants recorded by the supervisor.</p>
                    </div>
                    <div style="font-size: 0.85rem; font-weight: 600; color: #475569; padding: 8px 16px; background: #f8fafc; border: 1px solid #e2e8f0;">
                        Decision Records: <span style="color:#2563eb; font-weight: 700;" id="decisionCount"><?php echo count($decisionApplicants); ?></span>
                    </div>
                </div>

                <div class="decision-toolbar">
                    <select id="decisionStatusFilter" class="decision-filter" onchange="filterDecisionTable()">
                        <option value="all">All Decisions</option>
                        <option value="accepted">Accepted</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>

                <?php if (count($decisionApplicants) > 0): ?>
                    <div class="table-wrapper">
                        <div class="table-container" id="decisionTableContainer">
                            <table class="applicant-table" id="decisionTable">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Applied Position</th>
                                        <th>Company</th>
                                        <th>Decision Date</th>
                                        <th>Status</th>
                                        <th style="text-align: center;">Details</th>
                                    </tr>
                                </thead>
                                <tbody id="decisionTableBody">
                                    <!-- Populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Pagination for Decision Applicants -->
                    <div class="pagination-wrapper" id="decisionPagination">
                        <span class="page-info" id="decisionPageInfo">Showing 1–10 of 0</span>
                        <a href="#" class="page-link disabled" id="decisionPrevPage">Prev</a>
                        <span id="decisionPageNumbers"></span>
                        <a href="#" class="page-link" id="decisionNextPage">Next</a>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="margin-top: 16px;">
                        <i class="fa-solid fa-badge-check"></i>
                        <h3>No Decision Records</h3>
                        <p>Accepted and rejected candidates will appear here after a supervisor makes a decision.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- DETAILS / VIEW MODAL -->
    <div id="detailsModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-address-card" style="color: #2563eb;"></i> Candidate Profile</h3>
                <button type="button" class="modal-close-btn" onclick="closeDetailsModal()">&times;</button>
            </div>
            
            <form method="POST" action="applicant.php">
                <input type="hidden" name="action" value="save_notes">
                <input type="hidden" name="application_id" id="modal_app_id">
                <input type="hidden" name="status" id="modal_status" value="">

                <div class="modal-body">
                    <h4 style="margin: 0 0 10px 0; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 6px;">Student Demographics</h4>
                    
                    <div class="details-grid">
                        <div class="detail-card">
                            <div class="lbl">Full Name</div>
                            <div id="m_fullname" class="val">-</div>
                        </div>
                        <div class="detail-card">
                            <div class="lbl">Email Address</div>
                            <div id="m_email" class="val">-</div>
                        </div>
                        <div class="detail-card">
                            <div class="lbl">Contact Phone</div>
                            <div id="m_phone" class="val">-</div>
                        </div>
                        <div class="detail-card">
                            <div class="lbl">Birth Date</div>
                            <div id="m_birthdate" class="val">-</div>
                        </div>
                    </div>

                    <div class="content-section">
                        <h4>Mailing Address</h4>
                        <div id="m_address" class="content-body" style="background:#fafcff;">-</div>
                    </div>

                    <h4 style="margin: 24px 0 10px 0; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 6px;">Internship Selection</h4>
                    
                    <div class="details-grid">
                        <div class="detail-card">
                            <div class="lbl">Target Internship Position</div>
                            <div id="m_position" class="val">-</div>
                        </div>
                        <div class="detail-card">
                            <div class="lbl">Host Company</div>
                            <div id="m_company" class="val">-</div>
                        </div>
                    </div>

                    <div class="content-section">
                        <h4>Submitted Qualification Documents</h4>
                        <div style="display: flex; gap: 12px; margin-top: 4px; flex-wrap: wrap;" id="m_docs_container">
                            <!-- Populated in Javascript -->
                        </div>
                    </div>
                </div>

                <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">
                    <div id="modal_action_buttons" style="display: flex; gap: 8px;"></div>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <button type="button" class="btn-sec-outline" onclick="closeDetailsModal()">Close</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- DOCUMENT VIEWER MODAL -->
    <div id="docViewerModal" class="modal-overlay" style="z-index: 1100;">
        <div class="modal-container" style="max-width: 900px; height: 90vh;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-file-lines" style="color: #2563eb;"></i> <span id="docViewerTitle">Document Viewer</span></h3>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a id="docViewerDownloadBtn" href="#" class="btn-view" style="background: #e2e8f0; color: #1e293b; text-decoration: none;" download>
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                    <button type="button" class="modal-close-btn" onclick="closeDocViewerModal()">&times;</button>
                </div>
            </div>
            <div class="modal-body" style="padding: 0; flex-grow: 1; display: flex; flex-direction: column; overflow: hidden; max-height: none; height: calc(100% - 60px);">
                <div id="docViewerLoading" style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex-grow: 1; padding: 40px; color: #64748b;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 32px; margin-bottom: 12px; color: #2563eb;"></i>
                    <span>Loading document preview...</span>
                </div>
                <iframe id="docViewerIframe" src="" style="width: 100%; height: 100%; border: none; display: none;" onload="onDocViewerFrameLoaded()"></iframe>
                
                <div id="docViewerFallback" style="display: none; flex-direction: column; align-items: center; justify-content: center; flex-grow: 1; padding: 40px; text-align: center;">
                    <i class="fa-regular fa-file-word" style="font-size: 64px; color: #2b579a; margin-bottom: 20px;"></i>
                    <h4 style="margin: 0 0 10px; color: #0f172a; font-size: 1.2rem;">Office Document (.doc/.docx)</h4>
                    <p style="margin: 0 0 24px; color: #64748b; max-width: 400px; font-size: 0.9rem;">
                        This document type cannot be directly previewed in the browser. You can download the file to view its contents on your device.
                    </p>
                    <a id="docViewerFallbackBtn" href="#" class="btn-prim-blue" style="text-decoration: none;" download>
                        <i class="fa-solid fa-download"></i> Download Document
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-key"></i> Change Password</h3>
                <button type="button" class="modal-close-btn" id="closePasswordBtn">&times;</button>
            </div>
            <form id="passwordForm">
                <div class="modal-body">
                    <p style="color: #64748b; margin-bottom: 20px;">Enter your current password and choose a new one.</p>
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
                <div class="modal-footer" style="justify-content: flex-end;">
                    <button type="button" class="btn-secondary" id="closePasswordBtn2">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== TOAST ===== -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <!-- Javascript Actions -->
    <script>
        // ===== DATA =====
        const activeApplicants = <?php echo json_encode($activeApplicants); ?>;
        const decisionApplicants = <?php echo json_encode($decisionApplicants); ?>;
        const allApplicants = [...activeApplicants, ...decisionApplicants];

        // ===== PAGINATION VARIABLES =====
        let activeCurrentPage = 1;
        let decisionCurrentPage = 1;
        const itemsPerPage = 10;
        let activeFilteredData = [];
        let decisionFilteredData = [];

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
                            <span class="notif-time">${escapeHtml((notif.firstname && notif.lastname) ? `${notif.firstname} ${notif.lastname}` : 'System')} - ${timeAgo(notif.created_at)}</span>
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
            markNotificationRead(notificationId, function() {
                if (link && link !== '#') {
                    window.location.href = link;
                } else {
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

        // ===== TOAST =====
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            const icon = toast.querySelector('i');
            if (type === 'success') {
                icon.className = 'fa-regular fa-circle-check';
            } else if (type === 'error') {
                icon.className = 'fa-regular fa-circle-xmark';
            } else if (type === 'info') {
                icon.className = 'fa-regular fa-circle-info';
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
        function openDetailsModal(appId) {
            // Search in both active and decision lists
            const app = allApplicants.find(a => parseInt(a.id) === parseInt(appId));
            if (!app) {
                console.error('Application not found:', appId);
                showToast('Application record not found.', 'error');
                return;
            }

            document.getElementById('modal_app_id').value = app.id;

            // Student profile
            let fullname = app.student_firstname || '';
            if (app.student_middlename) fullname += ' ' + app.student_middlename;
            fullname += ' ' + (app.student_lastname || '');
            if (app.student_suffix) fullname += ' ' + app.student_suffix;
            fullname = fullname.trim();

            document.getElementById('m_fullname').innerText = fullname || app.student_username || '-';
            document.getElementById('m_email').innerText = app.student_email || '-';
            document.getElementById('m_phone').innerText = app.student_phone || 'None provided';
            document.getElementById('m_birthdate').innerText = app.student_birthdate || 'Not entered';
            document.getElementById('m_address').innerText = app.student_address || 'No address provided';

            // Job Profile
            document.getElementById('m_position').innerText = app.job_title || '-';
            document.getElementById('m_company').innerText = app.company_name || '-';

            // Document Links
            const docContainer = document.getElementById('m_docs_container');
            docContainer.innerHTML = '';

            const escapeQuote = (str) => (str || '').replace(/'/g, "\\'");
            const escapedName = escapeQuote(fullname || app.student_username || 'Student');

            if (app.cv_path) {
                const ext = app.cv_path.split('.').pop().toLowerCase();
                const iconClass = ext === 'pdf' ? 'fa-solid fa-file-pdf' : (['doc', 'docx'].includes(ext) ? 'fa-solid fa-file-word' : 'fa-solid fa-file-lines');
                docContainer.innerHTML += `
                    <a href="../${app.cv_path}" class="doc-link" title="View CV" onclick="event.preventDefault(); openDocViewer(this.href, 'CV - ${escapedName}');">
                        <i class="${iconClass}"></i> View CV
                    </a>
                `;
            } else {
                docContainer.innerHTML += `<span style="font-size:0.85rem; color:#94a3b8;"><i class="fa-solid fa-ban"></i> CV not submitted</span>`;
            }

            if (app.resume_path) {
                const ext = app.resume_path.split('.').pop().toLowerCase();
                const iconClass = ext === 'pdf' ? 'fa-solid fa-file-pdf' : (['doc', 'docx'].includes(ext) ? 'fa-solid fa-file-word' : 'fa-solid fa-file-lines');
                docContainer.innerHTML += `
                    <a href="../${app.resume_path}" class="doc-link" title="View Resume" onclick="event.preventDefault(); openDocViewer(this.href, 'Resume - ${escapedName}');">
                        <i class="${iconClass}"></i> View Resume
                    </a>
                `;
            } else {
                docContainer.innerHTML += `<span style="font-size:0.85rem; color:#94a3b8;"><i class="fa-solid fa-ban"></i> Resume not submitted</span>`;
            }

            if (app.application_letter_path) {
                const ext = app.application_letter_path.split('.').pop().toLowerCase();
                const iconClass = ext === 'pdf' ? 'fa-solid fa-file-pdf' : (['doc', 'docx'].includes(ext) ? 'fa-solid fa-file-word' : 'fa-solid fa-file-lines');
                docContainer.innerHTML += `
                    <a href="../${app.application_letter_path}" class="doc-link" title="View App Letter" onclick="event.preventDefault(); openDocViewer(this.href, 'App Letter - ${escapedName}');">
                        <i class="${iconClass}"></i> View Letter
                    </a>
                `;
            } else {
                docContainer.innerHTML += `<span style="font-size:0.85rem; color:#94a3b8;"><i class="fa-solid fa-ban"></i> Letter not submitted</span>`;
            }

            document.getElementById('modal_status').value = '';
            const actionButtons = document.getElementById('modal_action_buttons');

            if (app.status === 'withdrawn' || app.status === 'accepted' || app.status === 'committed' || app.status === 'rejected') {
                actionButtons.style.display = 'none';
            } else {
                actionButtons.style.display = 'flex';
            }

            document.getElementById('detailsModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function openDocViewer(filePath, docTitle) {
            const modal = document.getElementById('docViewerModal');
            const iframe = document.getElementById('docViewerIframe');
            const fallback = document.getElementById('docViewerFallback');
            const loading = document.getElementById('docViewerLoading');
            const downloadBtn = document.getElementById('docViewerDownloadBtn');
            const titleSpan = document.getElementById('docViewerTitle');
            const fallbackBtn = document.getElementById('docViewerFallbackBtn');

            titleSpan.innerText = docTitle;
            downloadBtn.href = filePath;
            fallbackBtn.href = filePath;

            const ext = filePath.split('.').pop().toLowerCase();

            if (ext === 'pdf') {
                loading.style.display = 'flex';
                iframe.style.display = 'none';
                fallback.style.display = 'none';
                iframe.src = filePath;
            } else {
                loading.style.display = 'none';
                iframe.style.display = 'none';
                iframe.src = '';
                fallback.style.display = 'flex';
            }

            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function onDocViewerFrameLoaded() {
            const iframe = document.getElementById('docViewerIframe');
            const loading = document.getElementById('docViewerLoading');
            if (iframe.src && !iframe.src.endsWith('#') && iframe.src !== window.location.href) {
                loading.style.display = 'none';
                iframe.style.display = 'block';
            }
        }

        function closeDocViewerModal() {
            const modal = document.getElementById('docViewerModal');
            const iframe = document.getElementById('docViewerIframe');
            iframe.src = ''; 
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        // Close on overlay Click
        window.onclick = function(event) {
            const detailsModal = document.getElementById('detailsModal');
            const docViewerModal = document.getElementById('docViewerModal');
            if (event.target === detailsModal) {
                closeDetailsModal();
            } else if (event.target === docViewerModal) {
                closeDocViewerModal();
            }
        };

        // ===== PAGINATION FUNCTIONS =====

        /**
         * Render active applicants page
         */
        function renderActivePage(page) {
            activeCurrentPage = page;
            const totalItems = activeFilteredData.length;
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            const pagination = document.getElementById('activePagination');
            if (!pagination) return;
            
            if (totalItems === 0) {
                pagination.style.display = 'flex';
                document.getElementById('activePageInfo').textContent = 'Showing 0–0 of 0';
                document.getElementById('activePrevPage').className = 'page-link disabled';
                document.getElementById('activeNextPage').className = 'page-link disabled';
                document.getElementById('activePageNumbers').innerHTML = '';
                return;
            }
            
            pagination.style.display = 'flex';
            
            const start = (page - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, totalItems);
            const pageItems = activeFilteredData.slice(start, end);
            
            document.getElementById('activePageInfo').textContent = 
                `Showing ${start + 1}–${end} of ${totalItems}`;
            
            renderActiveTable(pageItems);
            
            // Update pagination controls
            const prevLink = document.getElementById('activePrevPage');
            const nextLink = document.getElementById('activeNextPage');
            const pageNumbers = document.getElementById('activePageNumbers');
            
            prevLink.className = 'page-link' + (page <= 1 ? ' disabled' : '');
            prevLink.onclick = function(e) {
                e.preventDefault();
                if (page > 1) renderActivePage(page - 1);
            };
            
            nextLink.className = 'page-link' + (page >= totalPages ? ' disabled' : '');
            nextLink.onclick = function(e) {
                e.preventDefault();
                if (page < totalPages) renderActivePage(page + 1);
            };
            
            // Generate page numbers
            let pageHtml = '';
            const maxVisible = 5;
            let startPage = Math.max(1, page - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            
            if (endPage - startPage < maxVisible - 1) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }
            
            if (startPage > 1) {
                pageHtml += `<a href="#" class="page-link" onclick="event.preventDefault(); renderActivePage(1)">1</a>`;
                if (startPage > 2) {
                    pageHtml += `<span class="page-link disabled">…</span>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                pageHtml += `<a href="#" class="page-link${i === page ? ' active' : ''}" onclick="event.preventDefault(); renderActivePage(${i})">${i}</a>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    pageHtml += `<span class="page-link disabled">…</span>`;
                }
                pageHtml += `<a href="#" class="page-link" onclick="event.preventDefault(); renderActivePage(${totalPages})">${totalPages}</a>`;
            }
            
            pageNumbers.innerHTML = pageHtml;
        }

        /**
         * Render active applicants table
         */
        function renderActiveTable(applicants) {
            const tbody = document.getElementById('activeTableBody');
            if (!tbody) return;
            
            let html = '';
            applicants.forEach(app => {
                const appId = parseInt(app.id);
                let studentName = app.student_firstname || '';
                if (app.student_middlename) studentName += ' ' + app.student_middlename;
                studentName += ' ' + (app.student_lastname || '');
                if (app.student_suffix) studentName += ' ' + app.student_suffix;
                studentName = studentName.trim() || app.student_username || 'Student';
                
                const status = app.status || '';
                const isWithdrawn = status === 'withdrawn';
                
                html += `
                    <tr>
                        <td>
                            <strong style="color: #0f172a; font-size: 0.95rem;">${escapeHtml(studentName)}</strong>
                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                <i class="fa-regular fa-envelope" style="margin-right: 2px;"></i> ${escapeHtml(app.student_email || '')}
                            </div>
                        </td>
                        <td>${escapeHtml(app.job_title || '')}</td>
                        <td>
                            <span style="font-size:0.75rem; background:#f1f5f9; padding:2px 8px; border:1px solid #e2e8f0; font-weight: 600; color:#475569;">
                                ${escapeHtml(app.company_name || '')}
                            </span>
                        </td>
                        <td>
                            <i class="fa-regular fa-clock" style="color: #94a3b8; margin-right: 4px; font-size: 0.8rem;"></i>
                            ${app.application_date ? new Date(app.application_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : ''}
                        </td>
                        <td>
                            <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                ${app.cv_path ? `<a href="../${escapeHtml(app.cv_path)}" class="doc-link" title="View CV" onclick="event.preventDefault(); openDocViewer(this.href, 'CV - ${escapeHtml(studentName)}');"><i class="${getFileIconClass(app.cv_path)}"></i> CV</a>` : ''}
                                ${app.resume_path ? `<a href="../${escapeHtml(app.resume_path)}" class="doc-link" title="View Resume" onclick="event.preventDefault(); openDocViewer(this.href, 'Resume - ${escapeHtml(studentName)}');"><i class="${getFileIconClass(app.resume_path)}"></i> Res</a>` : ''}
                                ${app.application_letter_path ? `<a href="../${escapeHtml(app.application_letter_path)}" class="doc-link" title="View Application Letter" onclick="event.preventDefault(); openDocViewer(this.href, 'App Letter - ${escapeHtml(studentName)}');"><i class="${getFileIconClass(app.application_letter_path)}"></i> Let</a>` : ''}
                            </div>
                        </td>
                        <td class="action-column">
                            ${isWithdrawn ? 
                                `<span style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;">Withdrawn</span>` :
                                `<div class="action-buttons-inline">
                                    <form method="POST" action="applicant.php" style="margin: 0; display: inline;">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="application_id" value="${appId}">
                                        <input type="hidden" name="status" value="accepted">
                                        <button type="submit" class="btn-status-accept" title="Accept Candidate">
                                            <i class="fa-solid fa-circle-check"></i> Accept
                                        </button>
                                    </form>
                                    <form method="POST" action="applicant.php" style="margin: 0; display: inline;" onsubmit="return confirm('Are you sure you want to reject this applicant?')">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="application_id" value="${appId}">
                                        <input type="hidden" name="status" value="rejected">
                                        <button type="submit" class="btn-status-reject" title="Reject Candidate">
                                            <i class="fa-solid fa-circle-xmark"></i> Reject
                                        </button>
                                    </form>
                                </div>`
                            }
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-view" onclick="openDetailsModal(${appId})">
                                <i class="fa-solid fa-folder-open"></i> View
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        /**
         * Render decision applicants page
         */
        function renderDecisionPage(page) {
            decisionCurrentPage = page;
            const totalItems = decisionFilteredData.length;
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            const pagination = document.getElementById('decisionPagination');
            if (!pagination) return;
            
            if (totalItems === 0) {
                pagination.style.display = 'flex';
                document.getElementById('decisionPageInfo').textContent = 'Showing 0–0 of 0';
                document.getElementById('decisionPrevPage').className = 'page-link disabled';
                document.getElementById('decisionNextPage').className = 'page-link disabled';
                document.getElementById('decisionPageNumbers').innerHTML = '';
                return;
            }
            
            pagination.style.display = 'flex';
            
            const start = (page - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, totalItems);
            const pageItems = decisionFilteredData.slice(start, end);
            
            document.getElementById('decisionPageInfo').textContent = 
                `Showing ${start + 1}–${end} of ${totalItems}`;
            
            renderDecisionTable(pageItems);
            
            // Update pagination controls
            const prevLink = document.getElementById('decisionPrevPage');
            const nextLink = document.getElementById('decisionNextPage');
            const pageNumbers = document.getElementById('decisionPageNumbers');
            
            prevLink.className = 'page-link' + (page <= 1 ? ' disabled' : '');
            prevLink.onclick = function(e) {
                e.preventDefault();
                if (page > 1) renderDecisionPage(page - 1);
            };
            
            nextLink.className = 'page-link' + (page >= totalPages ? ' disabled' : '');
            nextLink.onclick = function(e) {
                e.preventDefault();
                if (page < totalPages) renderDecisionPage(page + 1);
            };
            
            // Generate page numbers
            let pageHtml = '';
            const maxVisible = 5;
            let startPage = Math.max(1, page - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            
            if (endPage - startPage < maxVisible - 1) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }
            
            if (startPage > 1) {
                pageHtml += `<a href="#" class="page-link" onclick="event.preventDefault(); renderDecisionPage(1)">1</a>`;
                if (startPage > 2) {
                    pageHtml += `<span class="page-link disabled">…</span>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                pageHtml += `<a href="#" class="page-link${i === page ? ' active' : ''}" onclick="event.preventDefault(); renderDecisionPage(${i})">${i}</a>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    pageHtml += `<span class="page-link disabled">…</span>`;
                }
                pageHtml += `<a href="#" class="page-link" onclick="event.preventDefault(); renderDecisionPage(${totalPages})">${totalPages}</a>`;
            }
            
            pageNumbers.innerHTML = pageHtml;
        }

        /**
         * Render decision applicants table
         */
        function renderDecisionTable(applicants) {
            const tbody = document.getElementById('decisionTableBody');
            if (!tbody) return;
            
            let html = '';
            applicants.forEach(app => {
                const appId = parseInt(app.id);
                let studentName = app.student_firstname || '';
                if (app.student_middlename) studentName += ' ' + app.student_middlename;
                studentName += ' ' + (app.student_lastname || '');
                if (app.student_suffix) studentName += ' ' + app.student_suffix;
                studentName = studentName.trim() || app.student_username || 'Student';
                
                const status = (app.status || '').toLowerCase();
                
                html += `
                    <tr data-status="${escapeHtml(status)}">
                        <td>
                            <strong style="color: #0f172a; font-size: 0.95rem;">${escapeHtml(studentName)}</strong>
                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                <i class="fa-regular fa-envelope" style="margin-right: 2px;"></i> ${escapeHtml(app.student_email || '')}
                            </div>
                        </td>
                        <td>${escapeHtml(app.job_title || '')}</td>
                        <td>
                            <span style="font-size:0.75rem; background:#f1f5f9; padding:2px 8px; border:1px solid #e2e8f0; font-weight: 600; color:#475569;">
                                ${escapeHtml(app.company_name || '')}
                            </span>
                        </td>
                        <td>
                            <i class="fa-regular fa-clock" style="color: #94a3b8; margin-right: 4px; font-size: 0.8rem;"></i>
                            ${app.updated_at ? new Date(app.updated_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : (app.application_date ? new Date(app.application_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '')}
                        </td>
                        <td>
                            <span class="status-chip ${escapeHtml(status)}">
                                ${escapeHtml(ucfirst(status))}
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-view" onclick="openDetailsModal(${appId})">
                                <i class="fa-solid fa-folder-open"></i> View
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        /**
         * Helper function to capitalize first letter
         */
        function ucfirst(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        /**
         * Get file icon class based on extension
         */
        function getFileIconClass(path) {
            if (!path) return 'fa-solid fa-file-lines';
            const ext = path.split('.').pop().toLowerCase();
            if (ext === 'pdf') return 'fa-solid fa-file-pdf';
            if (['doc', 'docx'].includes(ext)) return 'fa-solid fa-file-word';
            return 'fa-solid fa-file-lines';
        }

        // ===== FILTER FUNCTIONS =====

        /**
         * Filter active applicants table
         */
        function filterApplicantsTable() {
            const input = document.getElementById('applicantSearchInput');
            const filter = input.value.toLowerCase();
            
            if (filter.trim() === '') {
                activeFilteredData = [...activeApplicants];
            } else {
                activeFilteredData = activeApplicants.filter(app => {
                    let studentName = app.student_firstname || '';
                    if (app.student_middlename) studentName += ' ' + app.student_middlename;
                    studentName += ' ' + (app.student_lastname || '');
                    if (app.student_suffix) studentName += ' ' + app.student_suffix;
                    studentName = studentName.trim().toLowerCase();
                    
                    const company = (app.company_name || '').toLowerCase();
                    const job = (app.job_title || '').toLowerCase();
                    
                    return studentName.includes(filter) || company.includes(filter) || job.includes(filter);
                });
            }
            
            renderActivePage(1);
        }

        /**
         * Filter decision applicants table
         */
        function filterDecisionTable() {
            const filterSelect = document.getElementById('decisionStatusFilter');
            const filterValue = filterSelect ? filterSelect.value : 'all';
            
            if (filterValue === 'all') {
                decisionFilteredData = [...decisionApplicants];
            } else {
                decisionFilteredData = decisionApplicants.filter(app => 
                    (app.status || '').toLowerCase() === filterValue
                );
            }
            
            renderDecisionPage(1);
        }

        // ===== INITIALIZATION =====
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize active applicants
            if (activeApplicants.length > 0) {
                activeFilteredData = [...activeApplicants];
                renderActivePage(1);
            } else {
                // Hide pagination if no data
                const pagination = document.getElementById('activePagination');
                if (pagination) pagination.style.display = 'flex';
            }
            
            // Initialize decision applicants
            if (decisionApplicants.length > 0) {
                decisionFilteredData = [...decisionApplicants];
                renderDecisionPage(1);
            } else {
                const pagination = document.getElementById('decisionPagination');
                if (pagination) pagination.style.display = 'flex';
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeDetailsModal();
                closeDocViewerModal();
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