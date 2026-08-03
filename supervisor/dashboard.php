<?php
// supervisor/dashboard.php
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

// ===== DPR NOTIFICATION FUNCTIONS =====

/**
 * Create DPR notification when student submits
 */
function createDPRNotification($pdo, $student_id, $supervisor_id, $dpr_id, $job_title, $company_name, $student_name) {
    try {
        $title = "New DPR Submission";
        $message = "Student {$student_name} submitted a DPR for '{$job_title}' at {$company_name}";
        $link = "dpr_review.php?id=" . $dpr_id;
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, sender_id, title, message, link, is_read, created_at) 
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        return $stmt->execute([$supervisor_id, $student_id, $title, $message, $link]);
    } catch (PDOException $e) {
        error_log("Create DPR notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get DPR submission notifications for supervisor
 */
function getDPRSubmissionNotifications($pdo, $supervisor_id, $limit = 20, $offset = 0) {
    try {
        // First, check for pending DPRs that don't have notifications
        $stmt = $pdo->prepare("
            SELECT 
                d.id as dpr_id,
                d.student_id,
                d.submitted_at,
                u.firstname,
                u.lastname,
                u.profile_picture,
                j.title as job_title,
                c.name as company_name,
                CONCAT('Student ', u.firstname, ' ', u.lastname, ' submitted a DPR for ', j.title, ' at ', c.name) as message,
                CONCAT('dpr_review.php?id=', d.id) as link
            FROM dpr_submissions d
            INNER JOIN users u ON d.student_id = u.id
            INNER JOIN jobs j ON d.job_id = j.id
            INNER JOIN companies c ON j.company_id = c.id
            WHERE c.supervisor_id = ? 
                AND d.status = 'pending'
                AND d.submitted_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.user_id = c.supervisor_id 
                    AND n.link = CONCAT('dpr_review.php?id=', d.id)
                    AND n.title = 'New DPR Submission'
                )
            ORDER BY d.submitted_at DESC
        ");
        $stmt->execute([$supervisor_id]);
        $pendingDPRs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pendingDPRs as $dpr) {
            // Create notification for each pending DPR
            $studentName = $dpr['firstname'] . ' ' . $dpr['lastname'];
            createDPRNotification(
                $pdo,
                $dpr['student_id'],
                $supervisor_id,
                $dpr['dpr_id'],
                $dpr['job_title'],
                $dpr['company_name'],
                $studentName
            );
        }
        
        // Now fetch all DPR notifications
        $stmt = $pdo->prepare("
            SELECT 
                n.id,
                n.user_id,
                n.sender_id,
                n.title,
                n.message,
                n.link,
                n.is_read,
                n.created_at,
                'dpr_submission' as type,
                u.firstname,
                u.lastname,
                u.profile_picture,
                d.id as dpr_id
            FROM notifications n
            LEFT JOIN users u ON n.sender_id = u.id
            LEFT JOIN dpr_submissions d ON n.link LIKE CONCAT('dpr_review.php?id=', d.id)
            WHERE n.user_id = ? 
                AND n.title = 'New DPR Submission'
                AND n.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$supervisor_id, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get DPR notifications error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get unread DPR submission count
 */
function getUnreadDPRCount($pdo, $supervisor_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM notifications n
            WHERE n.user_id = ? 
                AND n.title = 'New DPR Submission'
                AND n.is_read = 0
                AND n.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$supervisor_id]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Get DPR count error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get unread notifications count for supervisor (includes DPR)
 */
function getUnreadNotificationCount($pdo, $supervisor_id) {
    try {
        // Regular notifications (excluding DPR)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = ? AND is_read = 0 AND title != 'New DPR Submission'
        ");
        $stmt->execute([$supervisor_id]);
        $count = (int)$stmt->fetchColumn();
        
        // DPR notifications
        $dprCount = getUnreadDPRCount($pdo, $supervisor_id);
        
        return $count + $dprCount;
    } catch (PDOException $e) {
        error_log("Notification count error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get notifications for supervisor with pagination (includes DPR)
 */
function getNotifications($pdo, $supervisor_id, $limit = 20, $offset = 0) {
    try {
        // Get regular notifications (excluding DPR)
        $stmt = $pdo->prepare("
            SELECT 
                n.id,
                n.user_id,
                n.sender_id,
                n.type,
                n.title,
                n.message,
                n.link,
                n.is_read,
                n.created_at,
                'notification' as type_group,
                u.firstname,
                u.lastname,
                u.profile_picture
            FROM notifications n
            LEFT JOIN users u ON n.sender_id = u.id
            WHERE n.user_id = ? AND n.title != 'New DPR Submission'
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$supervisor_id, $limit, $offset]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get DPR submission notifications
        $dprNotifications = getDPRSubmissionNotifications($pdo, $supervisor_id, $limit, $offset);
        
        // Merge and sort by created_at
        $allNotifications = array_merge($notifications, $dprNotifications);
        usort($allNotifications, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Apply limit after merge
        return array_slice($allNotifications, 0, $limit);
    } catch (PDOException $e) {
        error_log("Get notifications error: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as read (handles both regular and DPR)
 */
function markNotificationRead($pdo, $notification_id, $supervisor_id, $type = 'notification') {
    try {
        if ($type === 'dpr_submission') {
            // First get the notification to find the DPR ID
            $stmt = $pdo->prepare("SELECT link FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$notification_id, $supervisor_id]);
            $link = $stmt->fetchColumn();
            
            if ($link && preg_match('/id=(\d+)/', $link, $matches)) {
                $dpr_id = $matches[1];
                // Mark DPR as reviewed
                $stmt = $pdo->prepare("
                    UPDATE dpr_submissions 
                    SET status = 'reviewed' 
                    WHERE id = ?
                ");
                $stmt->execute([$dpr_id]);
            }
            
            // Mark notification as read
            $stmt = $pdo->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE id = ? AND user_id = ?
            ");
            return $stmt->execute([$notification_id, $supervisor_id]);
        } else {
            // Regular notification
            $stmt = $pdo->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE id = ? AND user_id = ?
            ");
            return $stmt->execute([$notification_id, $supervisor_id]);
        }
    } catch (PDOException $e) {
        error_log("Mark notification read error: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read (includes DPR)
 */
function markAllNotificationsRead($pdo, $supervisor_id) {
    try {
        // Mark all DPR notifications as read and update DPR status
        $stmt = $pdo->prepare("
            SELECT link FROM notifications 
            WHERE user_id = ? AND title = 'New DPR Submission' AND is_read = 0
        ");
        $stmt->execute([$supervisor_id]);
        $links = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($links as $link) {
            if ($link && preg_match('/id=(\d+)/', $link, $matches)) {
                $dpr_id = $matches[1];
                $stmt2 = $pdo->prepare("UPDATE dpr_submissions SET status = 'reviewed' WHERE id = ?");
                $stmt2->execute([$dpr_id]);
            }
        }
        
        // Mark all notifications as read
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        return $stmt->execute([$supervisor_id]);
    } catch (PDOException $e) {
        error_log("Mark all notifications read error: " . $e->getMessage());
        return false;
    }
}

// Get notifications count for display
$unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);
$notifications = getSupervisorNotifications($pdo, $userId, 10, 0);

// Handle AJAX requests
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
        $type = isset($_POST['type']) ? $_POST['type'] : 'notification';
        
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

    if ($_POST['action'] === 'delete') {
        $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        if ($notification_id > 0) {
            $result = deleteSupervisorNotification($pdo, $notification_id, $userId);
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
}

// Get real statistics
$stats = [
    'active_jobs' => 0,
    'total_applicants' => 0,
    'accepted_interns' => 0,
    'pending_reviews' => 0,
    'pending_dpr' => 0
];

try {
    // Active Jobs (jobs with available slots)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM jobs j
        INNER JOIN companies c ON j.company_id = c.id
        WHERE c.supervisor_id = ? AND j.slots_available > j.slots_filled
    ");
    $stmt->execute([$userId]);
    $stats['active_jobs'] = (int)$stmt->fetchColumn();

    // Total Applicants (all applications for jobs under this supervisor)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM job_applications a
        INNER JOIN jobs j ON a.job_id = j.id
        INNER JOIN companies c ON j.company_id = c.id
        WHERE c.supervisor_id = ? OR j.created_by = ?
    ");
    $stmt->execute([$userId, $userId]);
    $stats['total_applicants'] = (int)$stmt->fetchColumn();

    // Accepted Interns (committed or accepted status)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM job_applications a
        INNER JOIN jobs j ON a.job_id = j.id
        INNER JOIN companies c ON j.company_id = c.id
        WHERE (c.supervisor_id = ? OR j.created_by = ?)
        AND a.status IN ('accepted', 'committed')
    ");
    $stmt->execute([$userId, $userId]);
    $stats['accepted_interns'] = (int)$stmt->fetchColumn();

    // Pending Reviews (applications with pending status)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM job_applications a
        INNER JOIN jobs j ON a.job_id = j.id
        INNER JOIN companies c ON j.company_id = c.id
        WHERE (c.supervisor_id = ? OR j.created_by = ?)
        AND a.status = 'pending'
    ");
    $stmt->execute([$userId, $userId]);
    $stats['pending_reviews'] = (int)$stmt->fetchColumn();

    // Pending DPR submissions
    $stats['pending_dpr'] = getUnreadDPRCount($pdo, $userId);

} catch (PDOException $e) {
    // If tables don't exist yet, keep stats at 0
    error_log("Dashboard stats error: " . $e->getMessage());
}

// Current profile picture
$profilePicture = getUserProfilePicture($pdo, $userId);
$profilePictureUrl = $profilePicture ? $avatarPublicPath . $profilePicture : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Dashboard</title>
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
            position: relative;
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
            position: relative;
        }

        .notif-item .notif-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .notif-item .notif-avatar .dpr-badge {
            position: absolute;
            bottom: -2px;
            right: -2px;
            background: #003300;
            color: #FFCC33;
            border-radius: 0;
            width: 16px;
            height: 16px;
            font-size: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #FFCC33;
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

        .notif-item .notif-content .notif-title .dpr-tag {
            background: #FFCC33;
            color: #003300;
            font-size: 0.6rem;
            padding: 1px 6px;
            margin-left: 6px;
            font-weight: 700;
            display: inline-block;
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
            padding: 32px 28px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            flex: 1;
            border-radius: 0;
        }

        .page-card h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-card h2 i {
            color: #3b82f6;
        }

        .page-card p {
            color: #64748b;
            font-size: 1rem;
            line-height: 1.7;
            margin-bottom: 28px;
        }

        /* ---- Dashboard Stats Grid ---- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .stat-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 20px 24px;
            transition: 0.15s;
            border-radius: 0;
        }

        .stat-card:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .stat-card .stat-icon {
            font-size: 1.8rem;
            color: #3b82f6;
            margin-bottom: 8px;
        }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-card .stat-label {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 4px;
        }

        .stat-card .stat-link {
            display: inline-block;
            margin-top: 12px;
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;
            transition: 0.15s;
        }

        .stat-card .stat-link:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        /* ---- Quick Actions ---- */
        .quick-actions {
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid #edf2f7;
        }

        .quick-actions h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quick-actions h3 i {
            color: #64748b;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #1e293b;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            text-decoration: none;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border-radius: 0;
        }

        .action-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .action-btn.primary {
            background: #0f172a;
            color: #fff;
            border-color: #0f172a;
        }

        .action-btn.primary:hover {
            background: #1e293b;
            border-color: #1e293b;
        }

        .action-btn.success {
            background: #059669;
            color: #fff;
            border-color: #059669;
        }

        .action-btn.success:hover {
            background: #047857;
            border-color: #047857;
        }

        .action-btn.warning {
            background: #d97706;
            color: #fff;
            border-color: #d97706;
        }

        .action-btn.warning:hover {
            background: #b45309;
            border-color: #b45309;
        }

        .action-btn.dpr {
            background: #003300;
            color: #FFCC33;
            border-color: #003300;
        }

        .action-btn.dpr:hover {
            background: #004400;
            border-color: #004400;
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
            max-width: 480px;
            width: 100%;
            padding: 32px 30px 28px;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.3);
            animation: slideUp 0.25s ease;
            border-radius: 0;
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

        .modal-body p {
            color: #64748b;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }

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

        .form-group label i {
            margin-right: 6px;
            color: #64748b;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.95rem;
            background: #fafcff;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .form-group input:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .modal-actions {
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
            .stats-grid {
                grid-template-columns: 1fr 1fr;
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
                padding: 20px 16px;
            }

            .page-card h2 {
                font-size: 1.2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .modal-container {
                padding: 24px 18px;
                max-height: 95vh;
                margin: 10px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-btn {
                justify-content: center;
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card .stat-number {
                font-size: 1.5rem;
            }

            .modal-actions {
                flex-direction: column;
            }

            .modal-actions .btn-primary,
            .modal-actions .btn-secondary {
                width: 100%;
                justify-content: center;
            }

            .notif-dropdown {
                width: 280px;
                right: -5px;
                left: auto;
            }
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
                        <i class="fa-solid fa-gauge-high"></i>
                        Dashboard
                        <small>Supervisor</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Header Navigation -->
                    <nav class="header-nav">
                        <a class="nav-item-header active" href="dashboard.php"> Dashboard</a>
                        <a class="nav-item-header" href="job.php"> Add Job</a>
                        <a class="nav-item-header" href="applicant.php"> Applicants</a>
                        <a class="nav-item-header" href="myintern.php"> My Interns</a>
                    </nav>

                    <?php renderSupervisorNotificationBell($userId); ?>
                </div>
            </div>

            <!-- PAGE CARD -->
            <div class="page-card">
                <h2><i class="fa-regular fa-hand-peace"></i> Welcome back, <?php echo htmlspecialchars($fullname); ?>!</h2>
                <p>You have supervisor-level access to manage internship opportunities, review applicants, and oversee your interns.</p>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-briefcase"></i></div>
                        <div class="stat-number"><?php echo $stats['active_jobs']; ?></div>
                        <div class="stat-label">Active Jobs</div>
                        <a href="job.php" class="stat-link">Manage jobs →</a>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                        <div class="stat-number"><?php echo $stats['total_applicants']; ?></div>
                        <div class="stat-label">Total Applicants</div>
                        <a href="applicant.php" class="stat-link">Review applicants →</a>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-user-check"></i></div>
                        <div class="stat-number"><?php echo $stats['accepted_interns']; ?></div>
                        <div class="stat-label">Accepted Interns</div>
                        <a href="myintern.php" class="stat-link">View interns →</a>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
                        <div class="stat-number"><?php echo $stats['pending_reviews']; ?></div>
                        <div class="stat-label">Pending Reviews</div>
                        <a href="applicant.php" class="stat-link">Review now →</a>
                    </div>

                    <div class="stat-card" style="border-left: 3px solid #003300;">
                        <div class="stat-icon" style="color: #003300;"><i class="fa-solid fa-file-pen"></i></div>
                        <div class="stat-number"><?php echo $stats['pending_dpr']; ?></div>
                        <div class="stat-label">Pending DPR Submissions</div>
                        <?php if ($stats['pending_dpr'] > 0): ?>
                            <a href="#" onclick="document.getElementById('notifBell').click(); return false;" class="stat-link">View pending →</a>
                        <?php else: ?>
                            <span class="stat-link" style="color: #94a3b8; cursor: default;">No pending</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h3><i class="fa-regular fa-bolt"></i> Quick Actions</h3>
                    <div class="action-buttons">
                        <a href="job.php" class="action-btn primary">
                            <i class="fa-solid fa-plus"></i> Create Job Posting
                        </a>
                        <a href="applicant.php" class="action-btn warning">
                            <i class="fa-regular fa-file-lines"></i> Review Applicants
                        </a>
                        <a href="myintern.php" class="action-btn success">
                            <i class="fa-regular fa-user"></i> View My Interns
                        </a>
                        <?php if ($stats['pending_dpr'] > 0): ?>
                            <a href="#" class="action-btn dpr" onclick="document.getElementById('notifBell').click(); return false;">
                                <i class="fa-solid fa-file-pen"></i> Review DPRs (<?php echo $stats['pending_dpr']; ?>)
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-key"></i> Change Password</h3>
                <button type="button" class="modal-close-btn" id="closePasswordBtn">&times;</button>
            </div>
            <div class="modal-body">
                <p>Enter your current password and choose a new one.</p>
                <form id="passwordForm">
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
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" id="closePasswordBtn2">Cancel</button>
                        <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <script>
        // ===== Helper Functions =====
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

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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
            } else if (type === 'warning') {
                icon.className = 'fa-regular fa-circle-exclamation';
            }
            
            toast.className = 'toast ' + type + ' show';
            toastMessage.textContent = message;
            
            clearTimeout(toast._timeout);
            toast._timeout = setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        document.getElementById('toast').addEventListener('click', function() {
            this.classList.remove('show');
        });

        // ===== NOTIFICATION FUNCTIONS =====

        function toggleNotifications() {
            const dropdown = document.getElementById('notifDropdown');
            if (dropdown.classList.contains('active')) {
                dropdown.classList.remove('active');
            } else {
                dropdown.classList.add('active');
                loadNotifications();
            }
        }

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

        function renderNotifications(notifications, unreadCount) {
            const list = document.getElementById('notifList');
            
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
                const isDPR = notif.type === 'dpr_submission' || notif.type_group === 'dpr_submission';
                const link = notif.link || '#';
                const id = isDPR ? (notif.dpr_id || 0) : (notif.id || 0);
                const type = isDPR ? 'dpr_submission' : 'notification';
                const avatarUrl = notif.profile_picture ? '<?php echo $avatarPublicPath; ?>' + notif.profile_picture : '';
                const initials = notif.firstname && notif.lastname ? 
                    (notif.firstname.charAt(0) + notif.lastname.charAt(0)).toUpperCase() : 'UN';
                
                // Get message - if both title and message exist and are the same, only show once
                let displayMessage = notif.message || '';
                const title = notif.title || 'Notification';
                
                // If message is empty, don't show it (avoid showing title twice)
                if (!displayMessage || displayMessage.trim() === title.trim()) {
                    displayMessage = '';
                }
                
                html += `
                    <a href="${link}" 
                       class="notif-item ${isUnread ? 'unread' : ''}"
                       data-id="${id}"
                       data-type="${type}"
                       onclick="handleNotificationClick(event, ${id}, '${link}', '${type}')">
                        <div class="notif-avatar">
                            ${avatarUrl ? `<img src="${avatarUrl}" alt="Avatar">` : initials}
                            ${isDPR ? `<span class="dpr-badge"><i class="fa-solid fa-file-pen"></i></span>` : ''}
                        </div>
                        <div class="notif-content">
                            <div class="notif-title">
                                ${escapeHtml(title)}
                                ${isDPR ? `<span class="dpr-tag">DPR</span>` : ''}
                            </div>
                            ${displayMessage ? `<div class="notif-message">${escapeHtml(displayMessage)}</div>` : ''}
                            <span class="notif-time">${escapeHtml((notif.firstname && notif.lastname) ? `${notif.firstname} ${notif.lastname}` : 'System')} - ${timeAgo(notif.created_at)}</span>
                        </div>
                    </a>
                `;
            });
            
            list.innerHTML = html;
            updateBadge(unreadCount);
        }

        function updateBadge(count) {
            const badge = document.getElementById('notifBadge');
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }

        function handleNotificationClick(event, notificationId, link, type) {
            event.preventDefault();
            
            const item = event.currentTarget;
            if (item.classList.contains('processing')) return;
            item.classList.add('processing');
            item.classList.remove('unread');
            
            markNotificationRead(notificationId, type, function() {
                if (link && link !== '#') {
                    window.location.href = link;
                } else {
                    document.getElementById('notifDropdown').classList.remove('active');
                }
            });
        }

        function markNotificationRead(notificationId, type, callback) {
            const formData = new FormData();
            formData.append('action', 'mark_read');
            formData.append('notification_id', notificationId);
            formData.append('type', type || 'notification');
            
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
                        item.classList.remove('processing');
                    }
                    if (callback) callback();
                }
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
                if (callback) callback();
            });
        }

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
                    // Update DPR count in stats
                    const statCards = document.querySelectorAll('.stat-card');
                    if (statCards.length >= 5) {
                        statCards[4].querySelector('.stat-number').textContent = '0';
                    }
                    showToast('All notifications marked as read', 'success');
                }
            })
            .catch(error => {
                console.error('Error marking all as read:', error);
            });
        }

        // ===== NOTIFICATION EVENT LISTENERS =====
        
        document.getElementById('notifBell').addEventListener('click', function(e) {
            e.stopPropagation();
            document.querySelectorAll('.notif-item.unread').forEach(item => item.classList.remove('unread'));
            document.getElementById('notifBadge').classList.add('hidden');
            document.getElementById('notifBadge').textContent = '';
            toggleNotifications();
        });

        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.notif-wrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                document.getElementById('notifDropdown').classList.remove('active');
            }
        });

        document.getElementById('markAllRead').addEventListener('click', function(e) {
            e.stopPropagation();
            markAllNotificationsRead();
        });

        // Periodic notification check (every 30 seconds)
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
                    // Update DPR count in stats if needed
                    const dprCount = data.notifications ? 
                        data.notifications.filter(n => n.type === 'dpr_submission').length : 0;
                    const statCards = document.querySelectorAll('.stat-card');
                    if (statCards.length >= 5) {
                        statCards[4].querySelector('.stat-number').textContent = dprCount;
                    }
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

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
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