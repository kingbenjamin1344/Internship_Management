<?php
// coordinator/evaluation.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is coordinator
checkAccess('coordinator');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Coordinator';
$role = getUserRole();
$userId = getUserId();

// Initialize notifications
require_once __DIR__ . '/../includes/coordinator_notifications.php';
checkAndCreateCoordinatorNotifications($pdo, $userId);
$unreadCount = getCoordinatorUnreadNotificationCount($pdo, $userId);
$notificationsList = getCoordinatorNotifications($pdo, $userId, 10, 0);

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

    if ($_POST['action'] === 'run_sentiment_service') {
        $portOpen = false;
        $socket = @fsockopen('localhost', 8000, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            $portOpen = true;
        }

        if (!$portOpen) {
            $scriptPath = realpath(__DIR__ . '/../sentiment_service.py');
            if ($scriptPath !== false) {
                $command = 'cmd /c start /B "" python "' . str_replace('/', '\\', $scriptPath) . '" > NUL 2>&1';
                @pclose(@popen($command, 'r'));
            }
        }

        $socket = @fsockopen('localhost', 8000, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            echo json_encode(['success' => true, 'message' => 'Sentiment service is running.']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Sentiment service launch requested.']);
        }
        exit;
    }

    // ===== NEW: Stop sentiment service =====
    if ($_POST['action'] === 'stop_sentiment_service') {
        $stopped = false;
        $port = 8000;

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = shell_exec('netstat -ano | findstr :' . $port . ' | findstr LISTENING');
            if ($output) {
                $lines = explode("\n", $output);
                foreach ($lines as $line) {
                    if (strpos($line, 'LISTENING') !== false) {
                        $parts = preg_split('/\s+/', trim($line));
                        $pid = end($parts);
                        if (is_numeric($pid)) {
                            shell_exec('taskkill /PID ' . $pid . ' /F');
                            $stopped = true;
                            break;
                        }
                    }
                }
            }
        } else {
            $pid = shell_exec('lsof -t -i:' . $port . ' 2>/dev/null');
            if (trim($pid)) {
                shell_exec('kill -9 ' . trim($pid));
                $stopped = true;
            }
        }

        $socket = @fsockopen('localhost', $port, $errno, $errstr, 1);
        $stillRunning = ($socket !== false);
        if ($socket) fclose($socket);

        if ($stopped && !$stillRunning) {
            echo json_encode(['success' => true, 'message' => 'Sentiment service stopped.']);
        } elseif ($stillRunning) {
            echo json_encode(['success' => false, 'message' => 'Failed to stop the service. It is still running.']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Service was not running.']);
        }
        exit;
    }
    
    // Handle notification actions first
    if (in_array($_POST['action'], ['get_notifications', 'mark_read', 'mark_all_read'])) {
        $action = $_POST['action'];
        
        if ($action === 'get_notifications') {
            $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
            $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
            $notifs = getCoordinatorNotifications($pdo, $userId, $limit, $offset);
            $count = getCoordinatorUnreadNotificationCount($pdo, $userId);
            echo json_encode(['success' => true, 'notifications' => $notifs, 'unread_count' => $count]);
            exit;
        }
        
        if ($action === 'mark_read') {
            $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
            if ($notification_id > 0) {
                $result = markCoordinatorNotificationRead($pdo, $notification_id, $userId);
                $count = getCoordinatorUnreadNotificationCount($pdo, $userId);
                echo json_encode(['success' => $result, 'unread_count' => $count]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
            }
            exit;
        }
        
        if ($action === 'mark_all_read') {
            $result = markCoordinatorAllNotificationsRead($pdo, $userId);
            echo json_encode(['success' => $result, 'unread_count' => 0]);
            exit;
        }
    }
    
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

// Handle AJAX request for fetching evaluations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_evaluations') {
    header('Content-Type: application/json');
    
    try {
        // Fetch all evaluations with student, company, supervisor, job, and date details
        $stmt = $pdo->prepare("
            SELECT 
                d.id,
                d.student_id,
                d.date AS dpr_date,
                d.score,
                d.supervisor_feedback,
                d.evaluated_at,
                s.firstname AS student_firstname,
                s.lastname AS student_lastname,
                c.company_name,
                sp.firstname AS supervisor_firstname,
                sp.lastname AS supervisor_lastname,
                j.title AS job_title
            FROM dpr_entries d
            INNER JOIN users s ON d.student_id = s.id
            INNER JOIN job_applications a ON s.id = a.student_id
            INNER JOIN jobs j ON a.job_id = j.id
            INNER JOIN companies c ON j.company_id = c.id
            LEFT JOIN users sp ON sp.id = IFNULL(c.supervisor_id, j.created_by)
            WHERE d.score IS NOT NULL 
            AND d.score > 0 
            AND d.evaluated_at IS NOT NULL
            AND a.status = 'committed'
            ORDER BY d.evaluated_at DESC
        ");
        
        $stmt->execute();
        $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'evaluations' => $evaluations,
            'count' => count($evaluations)
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in evaluation.php: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred',
            'evaluations' => []
        ]);
    }
    exit;
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
    <title>Coordinator - Evaluation Analysis</title>
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
            padding: 20px 24px 28px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            flex: 1;
            border-radius: 0;
            display: flex;
            flex-direction: column;
            min-height: 500px;
        }

        .page-header {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 16px;
        }

        .page-header h2 {
            font-size: 1.2rem;
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
            font-size: 0.85rem;
        }

        /* ---- Loading Spinner ---- */
        .loading-spinner {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .loading-spinner i {
            font-size: 2.5rem;
            color: #FFCC33;
            animation: spin 1s linear infinite;
            display: block;
            margin-bottom: 12px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-spinner p {
            font-size: 0.95rem;
        }

        /* ---- Notice Banner ---- */
        .notice-banner {
            margin-bottom: 16px;
            padding: 10px 16px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            border-radius: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            flex-wrap: wrap;
        }

        .notice-banner.success {
            background: #ecfdf5;
            border-color: #86efac;
            color: #166534;
        }

        .notice-banner i {
            font-size: 1rem;
            color: #d97706;
        }

        .notice-banner.success i {
            color: #16a34a;
        }

        .notice-banner-action {
            border: 1px solid #b45309;
            background: #f59e0b;
            color: #fff;
            padding: 4px 10px;
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
            border-radius: 0;
            transition: 0.15s;
            margin-left: auto;
        }

        .notice-banner-action:hover {
            background: #d97706;
        }

        .sentiment-progress-wrapper {
            margin-top: 14px;
        }

        .sentiment-progress-label {
            font-size: 0.78rem;
            color: #475569;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }

        .sentiment-progress-bar {
            width: 100%;
            height: 10px;
            background: #e2e8f0;
            overflow: hidden;
            border-radius: 999px;
        }

        .sentiment-progress-fill {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #003300, #FFCC33);
            transition: width 0.3s ease;
        }

        /* ---- Table (compressed) - matches intern.php style ---- */
        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
            min-height: 320px;
            flex: 1;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .summary-table th {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 600;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .summary-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .summary-table tbody tr:last-child td {
            border-bottom: none;
        }

        .summary-table tbody tr:hover {
            background: #fafcff;
        }

        .student-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.85rem;
        }

        .muted-text {
            color: #94a3b8;
            font-size: 0.78rem;
        }

        /* ---- Detail View Table (matches intern.php detail style) ---- */
        .view-container {
            display: none;
            flex: 1;
        }

        .view-container.active {
            display: block;
        }

        .summary-container.hidden {
            display: none;
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
            padding: 12px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: #0f172a;
            border: 1px solid #0f172a;
            color: #fff;
            font-weight: 600;
            font-size: 0.78rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border-radius: 0;
        }

        .back-btn:hover {
            background: #1e293b;
        }

        .student-info-card {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            font-size: 0.82rem;
        }

        .student-info-card .name {
            font-weight: 700;
            color: #0f172a;
        }

        .student-info-card .sep {
            color: #cbd5e1;
        }

        .student-info-card .detail {
            color: #475569;
        }

        .student-info-card .detail i {
            color: #94a3b8;
            margin-right: 4px;
        }

        .eval-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            background: #dbeafe;
            border: 1px solid #93c5fd;
            font-size: 0.7rem;
            font-weight: 600;
            color: #1d4ed8;
            border-radius: 0;
        }

        .notice-banner.blue {
    background: #2563eb;        /* Bright blue */
    border-color: #1d4ed8;
    color: #ffffff;
}
.notice-banner.blue i {
    color: #ee571b;
}
.notice-banner.blue .notice-banner-action {
    background: #2b8d17;
    border-color: #1e3a8a;
}
.notice-banner.blue .notice-banner-action:hover {
    background: #b6651a;
}

        .detail-table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
        }

        .detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .detail-table th {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 600;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .detail-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .detail-table tbody tr:last-child td {
            border-bottom: none;
        }

        .detail-table tbody tr:hover {
            background: #fafcff;
        }

        /* ---- Buttons ---- */
        .view-eval-btn {
            padding: 4px 14px;
            border-radius: 0;
            font-size: 0.72rem;
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

        .view-eval-btn:hover:not(:disabled) {
            background: #bfdbfe;
            transform: scale(1.02);
        }

        .view-eval-btn:disabled {
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .view-eval-btn:disabled:hover {
            transform: none;
        }

        .fb-detail-btn {
            padding: 3px 12px;
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
            background: #eef2ff;
            color: #4338ca;
            border-color: #a5b4fc;
        }

        .fb-detail-btn:hover:not(:disabled) {
            background: #c7d2fe;
            transform: scale(1.02);
        }

        .fb-detail-btn.no-feedback {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: default;
            border-color: #e2e8f0;
        }

        .fb-detail-btn.no-feedback:hover {
            background: #f1f5f9;
            transform: none;
        }

        /* ---- Badges ---- */
        .score-badge {
            display: inline-block;
            padding: 2px 10px;
            font-weight: 600;
            font-size: 0.72rem;
            border: 1px solid transparent;
            border-radius: 0;
        }

        .score-badge.very-high {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .score-badge.high {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .score-badge.medium {
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .score-badge.low {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .score-badge.very-low {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .sentiment-badge {
            display: inline-block;
            padding: 2px 10px;
            font-weight: 600;
            font-size: 0.7rem;
            border: 1px solid transparent;
            border-radius: 0;
        }

        .sentiment-badge.very-positive {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .sentiment-badge.positive {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .sentiment-badge.neutral {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }

        .sentiment-badge.negative {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .sentiment-badge.very-negative {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .flag-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            font-weight: 600;
            font-size: 0.7rem;
            border: 1px solid transparent;
            border-radius: 0;
        }

        .flag-indicator.high-mismatch {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .flag-indicator.moderate-mismatch {
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .flag-indicator.no-mismatch {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .flag-indicator.no-feedback {
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
        }

        .flag-indicator.no-analysis {
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
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
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        .empty-state h3 {
            color: #1e293b;
            margin-bottom: 6px;
            font-size: 1.1rem;
        }

        .empty-state p {
            font-size: 0.9rem;
        }

        .empty-state .btn-retry {
            margin-top: 12px;
            padding: 6px 16px;
            background: #0f172a;
            color: #fff;
            border: 1px solid #0f172a;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.8rem;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border-radius: 0;
        }

        .empty-state .btn-retry:hover {
            background: #1e293b;
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

        /* ---- Modal ---- */
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

        .modal-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            max-width: 560px;
            width: 100%;
            padding: 28px 26px 24px;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.3);
            animation: slideUp 0.25s ease;
            max-height: 90vh;
            overflow-y: auto;
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
            margin-bottom: 4px;
        }

        .modal-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h2 i {
            color: #2563eb;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.6rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 6px;
            transition: 0.15s;
            line-height: 1;
        }

        .modal-close:hover {
            color: #1e293b;
        }

        .modal-hint {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }

        .fb-modal-content {
            padding: 0;
        }

        .fb-modal-content .meta-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #edf2f7;
        }

        .fb-modal-content .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #64748b;
        }

        .fb-modal-content .meta-item strong {
            color: #0f172a;
            font-weight: 600;
        }

        .fb-modal-content .content-text {
            background: #f8fafc;
            padding: 14px 18px;
            font-size: 0.9rem;
            line-height: 1.7;
            color: #1e293b;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: 1px solid #e2e8f0;
            border-radius: 0;
            min-height: 60px;
            max-height: 250px;
            overflow-y: auto;
        }

        .fb-modal-content .content-text .empty-text {
            color: #94a3b8;
            font-style: italic;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            border-top: 1px solid #edf2f7;
            padding-top: 16px;
        }

        .btn-close-modal {
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

        .btn-close-modal:hover {
            background: #e9edf4;
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

        /* ===== PASSWORD MODAL ===== */
        #passwordModal .modal-card {
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

        /* ---- Responsive ---- */
        @media (max-width: 1024px) {
            .fb-modal-content .meta-info {
                grid-template-columns: 1fr;
            }
            .detail-header {
                flex-direction: column;
                align-items: stretch;
            }
            .student-info-card {
                flex-direction: column;
                align-items: flex-start;
            }
            .student-info-card .sep {
                display: none;
            }
            .page-card {
                min-height: 400px;
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

            .page-card {
                padding: 14px;
                min-height: 350px;
            }

            .page-header h2 {
                font-size: 1rem;
            }

            .summary-table th,
            .summary-table td,
            .detail-table th,
            .detail-table td {
                padding: 6px 8px;
                font-size: 0.72rem;
            }

            .modal-card {
                padding: 20px 16px;
                max-height: 95vh;
                margin: 10px;
            }

            .view-eval-btn {
                font-size: 0.65rem;
                padding: 3px 10px;
            }

            .fb-detail-btn {
                font-size: 0.65rem;
                padding: 2px 8px;
            }

            .score-badge,
            .sentiment-badge,
            .flag-indicator {
                font-size: 0.65rem;
                padding: 1px 6px;
            }

            .student-info-card {
                font-size: 0.75rem;
            }

            .fb-modal-content .meta-info {
                grid-template-columns: 1fr;
            }

            .fb-modal-content .meta-item {
                font-size: 0.78rem;
            }

            .detail-header {
                padding: 10px 12px;
            }

            .back-btn {
                font-size: 0.72rem;
                padding: 4px 12px;
            }

            .eval-badge {
                font-size: 0.65rem;
                padding: 2px 8px;
            }

            .table-wrap {
                min-height: 250px;
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

            .summary-table th,
            .summary-table td,
            .detail-table th,
            .detail-table td {
                padding: 4px 6px;
                font-size: 0.65rem;
            }

            .view-eval-btn {
                font-size: 0.6rem;
                padding: 2px 6px;
            }

            .fb-detail-btn {
                font-size: 0.6rem;
                padding: 2px 6px;
            }

            .modal-actions {
                flex-direction: column;
            }

            .modal-actions .btn-close-modal {
                width: 100%;
                justify-content: center;
            }

            .notice-banner {
                font-size: 0.75rem;
                padding: 8px 12px;
            }

            .page-card {
                min-height: 300px;
                padding: 10px;
            }

            .table-wrap {
                min-height: 200px;
            }

            .pagination-wrapper .page-link {
                padding: 2px 8px;
                font-size: 0.7rem;
                min-width: 28px;
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
                        <i class="fa-solid fa-chart-line"></i>
                        Evaluation Analysis
                        <small>Coordinator</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Header Navigation -->
                    <nav class="header-nav">
                        <a class="nav-item-header" href="dashboard.php"> Dashboard</a>
                        <a class="nav-item-header" href="company.php">Companies</a>
                        <a class="nav-item-header" href="intern.php"> Internship</a>
                        <a class="nav-item-header active" href="evaluation.php"> Evaluation</a>
                        <a class="nav-item-header" href="dss.php"> Decision Support</a>
                    </nav>

                    <!-- Notification bell -->
                    <?php 
                    require_once __DIR__ . '/notification_component.php';
                    renderNotificationBell($unreadCount, $notificationsList);
                    ?>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-card">
                <div class="page-header">
                    <!-- <h2><i class="fa-solid fa-star"></i> Evaluative Sentiment Discrepancy Analysis</h2>
                    <p>Identifies contradictions between supervisor scores and feedback sentiment to flag potential evaluation inconsistencies.</p> -->
                </div>

                <!-- Loading spinner -->
                <div id="loadingSpinner" class="loading-spinner" style="display: flex;">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <p>Analyzing evaluations and sentiment...</p>
                </div>

                <!-- Results container -->
                <div id="resultsContainer" style="display: none; flex: 1; display: flex; flex-direction: column;">
                    <!-- Summary Table -->
                    <div id="summaryContainer" class="summary-container">
                        <div class="table-wrap">
                            <table class="summary-table">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Company</th>
                                        <th>Supervisor</th>
                                        <th>Job</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="summaryTableBody">
                                    <tr>
                                        <td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;">
                                            <i class="fa-regular fa-smile" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                                            Loading evaluations...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Detail View -->
                    <div id="detailContainer" class="view-container"></div>
                </div>

                <!-- ===== PAGINATION (Always Visible) ===== -->
                <div class="pagination-wrapper" id="paginationWrapper">
                    <span class="page-info" id="pageInfo">Loading...</span>
                    <a href="#" class="page-link disabled" id="prevPage">Prev</a>
                    <span id="pageNumbers"></span>
                    <a href="#" class="page-link disabled" id="nextPage">Next</a>
                </div>
            </div>
        </main>
    </div>

    <!-- FEEDBACK MODAL -->
    <div class="modal-overlay" id="feedbackModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-regular fa-comment"></i> Supervisor Feedback</h2>
                <button class="modal-close" id="closeFeedbackBtn">&times;</button>
            </div>
            <div class="modal-hint">View the supervisor's feedback for this evaluation.</div>

            <div class="fb-modal-content">
                <div class="meta-info">
                    <div class="meta-item">
                        <i class="fa-regular fa-user"></i>
                        <strong>Student:</strong> <span id="fbStudent">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-regular fa-building"></i>
                        <strong>Company:</strong> <span id="fbCompany">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-regular fa-user"></i>
                        <strong>Supervisor:</strong> <span id="fbSupervisor">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-solid fa-star"></i>
                        <strong>Score:</strong> <span id="fbScore">—</span>
                    </div>
                </div>
                <div class="content-text" id="fbTextDisplay">
                    <span class="empty-text">No feedback provided.</span>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn-close-modal" id="closeFeedbackBtn2">Close</button>
            </div>
        </div>
    </div>

    <!-- SENTIMENT SERVICE MODAL -->
    <div class="modal-overlay" id="sentimentServiceModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-solid fa-robot"></i> Machine Learning Implementation</h2>
                <button class="modal-close" id="closeSentimentServiceBtn">&times;</button>
            </div>
            <div class="modal-hint">Starting the Python sentiment analysis service in the background.</div>

            <div class="sentiment-progress-wrapper">
                <div class="sentiment-progress-label">
                    <span id="sentimentProgressText">Initializing service...</span>
                    <span id="sentimentProgressPercent">0%</span>
                </div>
                <div class="sentiment-progress-bar">
                    <div class="sentiment-progress-fill" id="sentimentProgressFill"></div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-close-modal" id="closeSentimentServiceBtn2">Close</button>
            </div>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-solid fa-key"></i> Change Password</h2>
                <button class="modal-close" id="closePasswordBtn">&times;</button>
            </div>
            <div class="modal-hint">Enter your current password and choose a new one.</div>

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
                    <button type="button" class="btn-close-modal" id="closePasswordBtn2">Cancel</button>
                    <button type="submit" class="btn-close-modal" style="background: #0f172a; color: #fff; border-color: #0f172a;"><i class="fa-solid fa-check"></i> Update Password</button>
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
        // Store all evaluations globally
        let allEvaluations = [];
        let allGroupedStudents = [];
        let currentStudentId = null;
        let isSentimentServiceRunning = false;

        // ===== PAGINATION VARIABLES =====
        let currentPage = 1;
        const itemsPerPage = 10;
        let filteredStudents = [];

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

        // ===== EVALUATION FUNCTIONS =====
        document.addEventListener('DOMContentLoaded', function() {
            loadEvaluationData();
        });

        // Modal functions for feedback
        function openFeedbackModal(student, company, supervisor, score, feedback) {
            const modal = document.getElementById('feedbackModal');
            if (!modal) return;
            
            document.getElementById('fbStudent').textContent = student || 'Unknown';
            document.getElementById('fbCompany').textContent = company || 'N/A';
            document.getElementById('fbSupervisor').textContent = supervisor || 'N/A';
            document.getElementById('fbScore').textContent = score ? score + '%' : 'N/A';
            
            const fbTextDisplay = document.getElementById('fbTextDisplay');
            if (feedback && feedback.trim() !== '') {
                fbTextDisplay.textContent = feedback;
                fbTextDisplay.className = 'content-text';
            } else {
                fbTextDisplay.innerHTML = '<span class="empty-text">No feedback provided for this evaluation.</span>';
                fbTextDisplay.className = 'content-text';
            }
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeFeedbackModal() {
            const modal = document.getElementById('feedbackModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        document.getElementById('closeFeedbackBtn').addEventListener('click', closeFeedbackModal);
        document.getElementById('closeFeedbackBtn2').addEventListener('click', closeFeedbackModal);
        document.getElementById('feedbackModal').addEventListener('click', function(e) {
            if (e.target === this) closeFeedbackModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('feedbackModal').classList.contains('active')) {
                    closeFeedbackModal();
                }
                if (document.getElementById('passwordModal').classList.contains('active')) {
                    closePasswordModal();
                }
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
            }
        });

        // View functions
        function viewStudentDetails(studentId) {
            // Block access if ML service is not running
            if (!isSentimentServiceRunning) {
                showToast('ML Service must be running to view evaluation details. Please start the service first.', 'warning');
                return;
            }
            
            const student = allGroupedStudents.find(s => s.student_id == studentId);
            if (!student) {
                console.error('Student not found.');
                return;
            }

            currentStudentId = studentId;

            document.getElementById('summaryContainer').style.display = 'none';
            document.getElementById('detailContainer').classList.add('active');
            document.getElementById('paginationWrapper').style.display = 'none';
            renderDetailView(student);
        }

        function backToSummary() {
            document.getElementById('detailContainer').classList.remove('active');
            document.getElementById('summaryContainer').style.display = 'block';
            document.getElementById('paginationWrapper').style.display = 'flex';
            currentStudentId = null;
            renderPage(currentPage);
        }

        function renderDetailView(student) {
            const container = document.getElementById('detailContainer');
            
            let html = `
                <div class="detail-header">
                    <button class="back-btn" onclick="backToSummary()">
                        <i class="fa-solid fa-arrow-left"></i> Back to Summary
                    </button>
                    <div class="student-info-card">
                        <span class="name">${escapeHtml(student.student_name)}</span>
                        <span class="sep">|</span>
                        <span class="detail"><i class="fa-solid fa-building"></i> ${escapeHtml(student.company_name)}</span>
                        <span class="sep">|</span>
                        <span class="detail"><i class="fa-solid fa-user"></i> ${escapeHtml(student.supervisor_name)}</span>
                        <span class="sep">|</span>
                        <span class="detail"><i class="fa-solid fa-briefcase"></i> ${escapeHtml(student.job_title)}</span>
                        <span class="eval-badge"><i class="fa-regular fa-file-lines"></i> ${student.evaluations.length} eval${student.evaluations.length > 1 ? 's' : ''}</span>
                    </div>
                </div>
                <div class="detail-table-wrap">
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Feedback</th>
                                <th>Score</th>
                                <th>Sentiment Analysis</th>
                                <th>Discrepancy</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            student.evaluations.forEach(evalItem => {
                const hasFeedback = evalItem.supervisor_feedback && evalItem.supervisor_feedback.trim() !== '';
                const sentiment = evalItem.sentiment_analysis;
                const sentimentClass = sentiment ? getSentimentClass(sentiment.sentiment) : 'neutral';
                const date = evalItem.dpr_date ? formatDate(evalItem.dpr_date) : '—';
                
                html += `
                    <tr>
                        <td>${escapeHtml(date)}</td>
                        <td>
                            ${hasFeedback ? 
                                `<button class="fb-detail-btn" onclick="openFeedbackModal(
                                    '${escapeJs(student.student_name)}',
                                    '${escapeJs(student.company_name)}',
                                    '${escapeJs(student.supervisor_name)}',
                                    '${evalItem.score || 0}',
                                    '${escapeJs(evalItem.supervisor_feedback)}'
                                )">
                                    <i class="fa-regular fa-comment"></i> View
                                </button>` :
                                `<button class="fb-detail-btn no-feedback" disabled>
                                    <i class="fa-regular fa-comment"></i> No Feedback
                                </button>`
                            }
                        </td>
                        <td>
                            <span class="score-badge ${getScoreClass(evalItem.score)}">
                                ${evalItem.score || 'N/A'}${evalItem.score ? '%' : ''}
                            </span>
                        </td>
                        <td>
                            ${sentiment ? 
                                `<span class="sentiment-badge ${sentimentClass}">
                                    ${sentiment.sentiment}
                                </span>` :
                                `<span class="sentiment-badge neutral">Neutral</span>`
                            }
                        </td>
                        <td>
                            <span class="flag-indicator ${evalItem.discrepancy_flag ? evalItem.discrepancy_flag.type : 'no-analysis'}" 
                                  title="${escapeHtml(evalItem.discrepancy_flag ? evalItem.discrepancy_flag.message : 'No analysis available')}">
                                ${evalItem.discrepancy_flag ? getFlagIcon(evalItem.discrepancy_flag.type) : '❓'}
                                ${evalItem.discrepancy_flag ? getFlagLabel(evalItem.discrepancy_flag.type) : 'No Analysis'}
                            </span>
                        </td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            container.innerHTML = html;
        }

        function formatDate(dateStr) {
            if (!dateStr) return '—';
            const d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d)) return dateStr;
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function openSentimentServiceModal() {
            const modal = document.getElementById('sentimentServiceModal');
            if (!modal) return;
            modal.classList.add('active');
        }

        function closeSentimentServiceModal() {
            const modal = document.getElementById('sentimentServiceModal');
            if (!modal) return;
            modal.classList.remove('active');
        }

        async function runSentimentServiceFromBanner() {
            openSentimentServiceModal();
            const progressFill = document.getElementById('sentimentProgressFill');
            const progressText = document.getElementById('sentimentProgressText');
            const progressPercent = document.getElementById('sentimentProgressPercent');

            const animateProgress = (value, label) => {
                if (progressFill) progressFill.style.width = value + '%';
                if (progressText) progressText.textContent = label;
                if (progressPercent) progressPercent.textContent = value + '%';
            };

            animateProgress(15, 'Launching Python service...');

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=run_sentiment_service'
                });
                const result = await response.json();

                if (!result.success) {
                    animateProgress(100, 'Unable to start service.');
                    throw new Error(result.message || 'Unable to start service.');
                }
            } catch (error) {
                console.error('Error launching sentiment service:', error);
            }

            let progress = 15;
            const progressInterval = setInterval(() => {
                progress = Math.min(progress + 8, 98);
                animateProgress(progress, progress < 40 ? 'Launching Python service...' : progress < 75 ? 'Loading machine learning implementation...' : 'Finalizing evaluation refresh...');

                if (progress >= 98) {
                    clearInterval(progressInterval);
                    animateProgress(100, 'Service launch complete. Returning...');
                    setTimeout(() => {
                        closeSentimentServiceModal();
                        window.location.reload();
                    }, 900);
                }
            }, 450);
        }

        // ===== NEW: Check sentiment service status =====
        async function checkSentimentService() {
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 1500);
                const resp = await fetch('http://localhost:8000/health', {
                    method: 'GET',
                    signal: controller.signal
                });
                clearTimeout(timeoutId);
                return resp.ok;
            } catch {
                return false;
            }
        }

        // ===== NEW: Stop sentiment service =====
        async function stopSentimentService() {
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=stop_sentiment_service'
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    // Update banner state after stopping
                    const running = await checkSentimentService();
                    isSentimentServiceRunning = running;
                    updateBannerServiceState(running);
                } else {
                    showToast(result.message || 'Failed to stop service.', 'error');
                }
            } catch (error) {
                console.error('Error stopping service:', error);
                showToast('An error occurred while stopping the service.', 'error');
            }
        }

        // ===== NEW: Update the banner based on service state =====
       function updateBannerServiceState(running) {
    const banner = document.querySelector('.notice-banner');
    if (!banner) return;
    
    if (running) {
        // Use blue banner when service is running
        banner.className = 'notice-banner blue';
        banner.dataset.bannerType = 'success';
        banner.innerHTML = `
            <i class="fa-solid fa-circle-check"></i>
            <div style="display:flex; flex-direction:column; align-items:flex-start; flex:1; gap:2px;">
                <strong style="color: #ffffff;">ML Service Running - FastAPI + Uvicorn</strong>
                <span style="color: #ffffff; font-size: 0.8rem;">Hugging Face Tagalog RoBERTa | You can now view evaluation details</span>
            </div>
            <span style="margin-left:auto; display:flex; gap:6px;">
                <button type="button" class="notice-banner-action" onclick="stopSentimentService()"><i class="fa-solid fa-stop"></i> Stop Service</button>
            </span>
        `;
    } else {
        // Warning banner when service is not running
        banner.className = 'notice-banner';
        banner.dataset.bannerType = 'fallback';
        banner.innerHTML = `
            <i class="fa-solid fa-exclamation-triangle"></i>
            <strong>ML Service Required!</strong>
            Start the ML service to access evaluation and sentiment analysis.
            <span style="margin-left:auto; display:flex; gap:6px;">
                <button type="button" class="notice-banner-action" onclick="runSentimentServiceFromBanner()"><i class="fa-solid fa-play"></i> Run Machine Learning Service</button>
            </span>
        `;
    }
}

        document.addEventListener('DOMContentLoaded', function() {
            const closeSentimentServiceBtn = document.getElementById('closeSentimentServiceBtn');
            const closeSentimentServiceBtn2 = document.getElementById('closeSentimentServiceBtn2');
            const sentimentServiceModal = document.getElementById('sentimentServiceModal');

            if (closeSentimentServiceBtn) {
                closeSentimentServiceBtn.addEventListener('click', closeSentimentServiceModal);
            }
            if (closeSentimentServiceBtn2) {
                closeSentimentServiceBtn2.addEventListener('click', closeSentimentServiceModal);
            }
            if (sentimentServiceModal) {
                sentimentServiceModal.addEventListener('click', function(event) {
                    if (event.target === sentimentServiceModal) {
                        closeSentimentServiceModal();
                    }
                });
            }
        });

        async function loadEvaluationData() {
            const loadingSpinner = document.getElementById('loadingSpinner');
            const resultsContainer = document.getElementById('resultsContainer');
            
            try {
                loadingSpinner.style.display = 'flex';
                resultsContainer.style.display = 'none';
                
                // Show pagination with loading state
                const paginationWrapper = document.getElementById('paginationWrapper');
                paginationWrapper.style.display = 'flex';
                document.getElementById('pageInfo').textContent = 'Loading...';
                document.getElementById('prevPage').className = 'page-link disabled';
                document.getElementById('nextPage').className = 'page-link disabled';
                document.getElementById('pageNumbers').innerHTML = '';
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_evaluations'
                });
                
                const data = await response.json();
                
                if (data.success && data.evaluations.length > 0) {
                    allEvaluations = data.evaluations;
                    // Always analyze sentiments - will use fallback if ML service is not running
                    await analyzeSentiments(data.evaluations);
                } else {
                    displayEmptyState();
                }
            } catch (error) {
                console.error('Error loading evaluation data:', error);
                displayError('Failed to load evaluation data. Please try again.');
            } finally {
                loadingSpinner.style.display = 'none';
                resultsContainer.style.display = 'flex';
            }
        }

        async function analyzeSentiments(evaluations) {
            let sentimentServiceAvailable = false;
            try {
                const testResponse = await fetch('http://localhost:8000/health', { method: 'GET' });
                sentimentServiceAvailable = testResponse.ok;
            } catch (error) {
                console.log('Sentiment service not available, using fallback analysis');
            }
            
            for (let evaluation of evaluations) {
                if (evaluation.supervisor_feedback && evaluation.supervisor_feedback.trim() !== '') {
                    if (sentimentServiceAvailable) {
                        try {
                            const sentimentResponse = await fetch('http://localhost:8000/analyze-sentiment', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ text: evaluation.supervisor_feedback })
                            });
                            if (sentimentResponse.ok) {
                                const sentimentData = await sentimentResponse.json();
                                evaluation.sentiment_analysis = sentimentData;
                                evaluation.discrepancy_flag = analyzeDiscrepancy(evaluation);
                            } else {
                                evaluation.sentiment_analysis = fallbackSentimentAnalysis(evaluation.supervisor_feedback);
                                evaluation.discrepancy_flag = analyzeDiscrepancy(evaluation);
                            }
                        } catch (error) {
                            console.error('Sentiment analysis error:', error);
                            evaluation.sentiment_analysis = fallbackSentimentAnalysis(evaluation.supervisor_feedback);
                            evaluation.discrepancy_flag = analyzeDiscrepancy(evaluation);
                        }
                    } else {
                        evaluation.sentiment_analysis = fallbackSentimentAnalysis(evaluation.supervisor_feedback);
                        evaluation.discrepancy_flag = analyzeDiscrepancy(evaluation);
                    }
                } else {
                    evaluation.sentiment_analysis = { sentiment: 'Neutral', confidence: 0, text: '', model: 'default' };
                    evaluation.discrepancy_flag = { type: 'no-feedback', message: 'No feedback provided' };
                }
            }
            
            const groupedByStudent = groupByStudent(evaluations);
            allGroupedStudents = groupedByStudent;
            filteredStudents = groupedByStudent;
            displayResults(groupedByStudent, !sentimentServiceAvailable);
        }

        function groupByStudent(evaluations) {
            const grouped = {};
            evaluations.forEach(evalItem => {
                const studentId = evalItem.student_id;
                if (!grouped[studentId]) {
                    grouped[studentId] = {
                        student_id: studentId,
                        student_name: `${evalItem.student_firstname || ''} ${evalItem.student_lastname || ''}`.trim() || 'Unknown Student',
                        company_name: evalItem.company_name || 'N/A',
                        supervisor_name: `${evalItem.supervisor_firstname || ''} ${evalItem.supervisor_lastname || ''}`.trim() || 'N/A',
                        job_title: evalItem.job_title || 'N/A',
                        evaluations: []
                    };
                }
                grouped[studentId].evaluations.push({
                    dpr_date: evalItem.dpr_date,
                    score: evalItem.score,
                    supervisor_feedback: evalItem.supervisor_feedback,
                    sentiment_analysis: evalItem.sentiment_analysis,
                    discrepancy_flag: evalItem.discrepancy_flag,
                });
            });
            return Object.values(grouped);
        }

        function fallbackSentimentAnalysis(text) {
            const lowerText = text.toLowerCase();
            const positive = ['excellent','outstanding','great','good','amazing','fantastic','wonderful','impressive','remarkable','exceptional','superb','brilliant','awesome','perfect','well done','congratulations','keep up','proud','satisfied','productive','efficient','dedicated','hardworking','reliable','consistent'];
            const negative = ['poor','bad','terrible','awful','disappointing','unsatisfactory','unacceptable','lacking','inadequate','below expectations','needs improvement','concerning','problematic','issues','problems','late','absent','missed','failed','careless','sloppy','unprofessional','irresponsible','unreliable'];
            const veryPositive = ['exceptionally','extraordinarily','incredibly','extremely good','absolutely','perfectly','flawlessly','beyond expectations'];
            const veryNegative = ['completely unacceptable','extremely poor','totally inadequate','absolutely terrible','major concerns','serious issues','frequently absent','consistently late'];
            let pCount=0,nCount=0,vpCount=0,vnCount=0;
            veryPositive.forEach(w=>{if(lowerText.includes(w)) vpCount++;});
            veryNegative.forEach(w=>{if(lowerText.includes(w)) vnCount++;});
            positive.forEach(w=>{if(lowerText.includes(w)) pCount++;});
            negative.forEach(w=>{if(lowerText.includes(w)) nCount++;});
            let sentiment, confidence;
            if(vpCount>0){ sentiment='Very Positive'; confidence=Math.min(0.9,0.7+vpCount*0.1); }
            else if(vnCount>0){ sentiment='Very Negative'; confidence=Math.min(0.9,0.7+vnCount*0.1); }
            else if(pCount>nCount && pCount>0){ sentiment='Positive'; confidence=Math.min(0.85,0.6+pCount*0.1); }
            else if(nCount>pCount && nCount>0){ sentiment='Negative'; confidence=Math.min(0.85,0.6+nCount*0.1); }
            else { sentiment='Neutral'; confidence=0.5; }
            return { sentiment, confidence, text, model:'keyword-based-fallback' };
        }

        function analyzeDiscrepancy(evaluation) {
            if(!evaluation.sentiment_analysis || !evaluation.score) return { type:'no-analysis', message:'Insufficient data' };
            const score = parseFloat(evaluation.score);
            const sentiment = evaluation.sentiment_analysis.sentiment.toLowerCase();
            let scoreCategory;
            if(score>=90) scoreCategory='very-positive';
            else if(score>=70) scoreCategory='positive';
            else if(score>=40) scoreCategory='neutral';
            else if(score>=20) scoreCategory='negative';
            else scoreCategory='very-negative';
            let sentimentCategory;
            if(sentiment.includes('very positive')) sentimentCategory='very-positive';
            else if(sentiment.includes('positive')) sentimentCategory='positive';
            else if(sentiment.includes('very negative')) sentimentCategory='very-negative';
            else if(sentiment.includes('negative')) sentimentCategory='negative';
            else sentimentCategory='neutral';
            const mismatch = detectMismatch(scoreCategory, sentimentCategory, score);
            return {
                type: mismatch.type,
                message: mismatch.message,
                score_category: scoreCategory,
                sentiment_category: sentimentCategory,
                confidence: evaluation.sentiment_analysis.confidence || 0,
                score_value: score
            };
        }

        function detectMismatch(scoreCategory, sentimentCategory, score) {
            if(scoreCategory === sentimentCategory) {
                return { type:'no-mismatch', message:`Score (${getScoreRangeText(score)}) aligned with ${sentimentCategory.replace('-',' ')} feedback` };
            }
            const high = [
                {score:'very-positive',sentiment:'negative',message:'Very high score (90-100%) but negative feedback - Major contradiction!'},
                {score:'very-positive',sentiment:'very-negative',message:'Very high score (90-100%) but very negative feedback - Extreme contradiction!'},
                {score:'very-negative',sentiment:'positive',message:'Very low score (0-19%) but positive feedback - Major contradiction!'},
                {score:'very-negative',sentiment:'very-positive',message:'Very low score (0-19%) but very positive feedback - Extreme contradiction!'},
                {score:'positive',sentiment:'very-negative',message:'Good score (70-89%) but very negative feedback - Clear contradiction!'},
                {score:'negative',sentiment:'very-positive',message:'Low score (20-39%) but very positive feedback - Clear contradiction!'}
            ];
            const moderate = [
                {score:'very-positive',sentiment:'neutral',message:'Very high score (90-100%) but neutral feedback - Slightly misaligned'},
                {score:'positive',sentiment:'negative',message:'Good score (70-89%) but negative feedback - Moderate mismatch'},
                {score:'neutral',sentiment:'very-positive',message:'Average score (40-69%) but very positive feedback - Moderate mismatch'},
                {score:'neutral',sentiment:'very-negative',message:'Average score (40-69%) but very negative feedback - Moderate mismatch'},
                {score:'negative',sentiment:'positive',message:'Low score (20-39%) but positive feedback - Moderate mismatch'},
                {score:'very-negative',sentiment:'neutral',message:'Very low score (0-19%) but neutral feedback - Slightly misaligned'},
                {score:'neutral',sentiment:'negative',message:'Average score (40-69%) but negative feedback - Moderate mismatch'},
                {score:'negative',sentiment:'neutral',message:'Low score (20-39%) but neutral feedback - Slightly misaligned'}
            ];
            for(let m of high) if(scoreCategory===m.score && sentimentCategory===m.sentiment) return { type:'high-mismatch', message:m.message };
            for(let m of moderate) if(scoreCategory===m.score && sentimentCategory===m.sentiment) return { type:'moderate-mismatch', message:m.message };
            return { type:'no-mismatch', message:'Score and sentiment are reasonably aligned' };
        }

        function getScoreRangeText(score) {
            if(score>=90) return 'Very High (90-100%)';
            if(score>=70) return 'Good (70-89%)';
            if(score>=40) return 'Average (40-69%)';
            if(score>=20) return 'Low (20-39%)';
            return 'Very Low (0-19%)';
        }

        function getSentimentClass(sentiment) {
            const lower = sentiment.toLowerCase();
            if(lower.includes('very positive')) return 'very-positive';
            if(lower.includes('positive')) return 'positive';
            if(lower.includes('very negative')) return 'very-negative';
            if(lower.includes('negative')) return 'negative';
            return 'neutral';
        }

        // ===== PAGINATION FUNCTIONS =====
        function renderPage(page) {
            currentPage = page;
            const totalItems = filteredStudents.length;
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            // Always show pagination wrapper
            const paginationWrapper = document.getElementById('paginationWrapper');
            paginationWrapper.style.display = 'flex';
            
            if (totalItems === 0) {
                // Update pagination for empty state
                document.getElementById('pageInfo').textContent = 'Showing 0–0 of 0';
                document.getElementById('prevPage').className = 'page-link disabled';
                document.getElementById('nextPage').className = 'page-link disabled';
                document.getElementById('pageNumbers').innerHTML = '';
                return;
            }
            
            const start = (page - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, totalItems);
            const pageItems = filteredStudents.slice(start, end);
            
            // Update page info
            document.getElementById('pageInfo').textContent = 
                `Showing ${totalItems > 0 ? start + 1 : 0}–${end} of ${totalItems}`;
            
            // Render the table with current page items
            renderTableRows(pageItems);
            
            // Update pagination controls
            const prevLink = document.getElementById('prevPage');
            const nextLink = document.getElementById('nextPage');
            const pageNumbers = document.getElementById('pageNumbers');
            
            prevLink.className = 'page-link' + (page <= 1 ? ' disabled' : '');
            prevLink.href = '#';
            prevLink.onclick = function(e) {
                e.preventDefault();
                if (page > 1) renderPage(page - 1);
            };
            
            nextLink.className = 'page-link' + (page >= totalPages ? ' disabled' : '');
            nextLink.href = '#';
            nextLink.onclick = function(e) {
                e.preventDefault();
                if (page < totalPages) renderPage(page + 1);
            };
            
            // Generate page number links
            let pageHtml = '';
            const maxVisible = 5;
            let startPage = Math.max(1, page - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            
            if (endPage - startPage < maxVisible - 1) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }
            
            if (startPage > 1) {
                pageHtml += `<a href="#" class="page-link" onclick="event.preventDefault(); renderPage(1)">1</a>`;
                if (startPage > 2) {
                    pageHtml += `<span class="page-link disabled">…</span>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                pageHtml += `<a href="#" class="page-link${i === page ? ' active' : ''}" onclick="event.preventDefault(); renderPage(${i})">${i}</a>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    pageHtml += `<span class="page-link disabled">…</span>`;
                }
                pageHtml += `<a href="#" class="page-link" onclick="event.preventDefault(); renderPage(${totalPages})">${totalPages}</a>`;
            }
            
            pageNumbers.innerHTML = pageHtml;
        }

        function renderTableRows(students) {
            const tbody = document.getElementById('summaryTableBody');
            if (!tbody) return;
            
            let html = '';
            
            if (students.length === 0) {
                html = `<tr>
                    <td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;">
                        <i class="fa-regular fa-smile" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                        No students with evaluations found on this page.
                    </td>
                </tr>`;
            } else {
                students.forEach(student => {
                    // Check if ML service is running - disable button if not
                    const buttonDisabled = !isSentimentServiceRunning ? 'disabled' : '';
                    const buttonTitle = !isSentimentServiceRunning ? 'ML Service must be running to view details' : 'View evaluation details';
                    
                    html += `<tr>
                        <td><span class="student-name">${escapeHtml(student.student_name)}</span></td>
                        <td>${escapeHtml(student.company_name)}</td>
                        <td>${escapeHtml(student.supervisor_name)}</td>
                        <td>${escapeHtml(student.job_title)}</td>
                        <td>
                            <button class="view-eval-btn" 
                                    onclick="viewStudentDetails(${student.student_id})" 
                                    ${buttonDisabled}
                                    title="${buttonTitle}">
                                <i class="fa-regular fa-eye"></i> View
                            </button>
                        </td>
                    </tr>`;
                });
            }
            
            tbody.innerHTML = html;
        }

        async function displayResults(groupedStudents, usingFallback) {
            const resultsContainer = document.getElementById('resultsContainer');
            
            // Show results container
            resultsContainer.style.display = 'flex';
            
            // Remove any existing banner
            const existingBanner = document.querySelector('.notice-banner');
            if (existingBanner) existingBanner.remove();

            // Create a new banner with a loading state
            const banner = document.createElement('div');
            banner.className = 'notice-banner';
            banner.dataset.bannerType = 'unknown';
            banner.innerHTML = `
                <i class="fa-solid fa-spinner fa-spin"></i>
                <strong>Checking service status...</strong>
            `;
            resultsContainer.insertBefore(banner, resultsContainer.firstChild);

            // Check actual service status
            const running = await checkSentimentService();
            isSentimentServiceRunning = running;
            updateBannerServiceState(running);

            // Store and display data
            filteredStudents = groupedStudents.sort((a,b) => a.student_name.localeCompare(b.student_name));
            
            // Show pagination
            const paginationWrapper = document.getElementById('paginationWrapper');
            paginationWrapper.style.display = 'flex';
            
            if (filteredStudents.length > 0) {
                renderPage(1);
            } else {
                // Show empty state
                const tbody = document.getElementById('summaryTableBody');
                if (tbody) {
                    tbody.innerHTML = `<tr>
                        <td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;">
                            <i class="fa-regular fa-smile" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                            No students with evaluations found.
                        </td>
                    </tr>`;
                }
                
                // Update pagination for empty state
                document.getElementById('pageInfo').textContent = 'Showing 0–0 of 0';
                document.getElementById('prevPage').className = 'page-link disabled';
                document.getElementById('nextPage').className = 'page-link disabled';
                document.getElementById('pageNumbers').innerHTML = '';
            }
        }

        function getScoreClass(score) {
            if(!score) return 'low';
            const s = parseFloat(score);
            if(s>=90) return 'very-high';
            if(s>=81) return 'high';
            if(s>=75) return 'medium';
            if(s>=50) return 'low';
            return 'very-low';
        }

        function getFlagIcon(type) {
            const map = { 'high-mismatch':'🚨','moderate-mismatch':'⚠️','no-mismatch':'✅','no-feedback':'ℹ️', 'no-analysis':'❓' };
            return map[type] || '❓';
        }

        function getFlagLabel(type) {
            const map = { 'high-mismatch':'High Mismatch','moderate-mismatch':'Moderate Mismatch','no-mismatch':'Aligned','no-feedback':'No Feedback', 'no-analysis':'No Analysis' };
            return map[type] || 'No Analysis';
        }

        function displayEmptyState() {
            const resultsContainer = document.getElementById('resultsContainer');
            resultsContainer.style.display = 'flex';
            
            const tbody = document.getElementById('summaryTableBody');
            if (tbody) {
                tbody.innerHTML = `<tr>
                    <td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;">
                        <i class="fa-regular fa-smile" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                        No students with evaluations found.
                    </td>
                </tr>`;
            }
            
            // Show pagination with empty state
            const paginationWrapper = document.getElementById('paginationWrapper');
            paginationWrapper.style.display = 'flex';
            document.getElementById('pageInfo').textContent = 'Showing 0–0 of 0';
            document.getElementById('prevPage').className = 'page-link disabled';
            document.getElementById('nextPage').className = 'page-link disabled';
            document.getElementById('pageNumbers').innerHTML = '';
        }

        function displayError(message) {
            const resultsContainer = document.getElementById('resultsContainer');
            resultsContainer.style.display = 'flex';
            
            const tbody = document.getElementById('summaryTableBody');
            if (tbody) {
                tbody.innerHTML = `<tr>
                    <td colspan="5" style="text-align:center;padding:40px;">
                        <div style="display:flex;flex-direction:column;align-items:center;gap:12px;">
                            <i class="fa-solid fa-exclamation-triangle" style="font-size:2rem;color:#ef4444;"></i>
                            <span style="color:#1e293b;font-weight:600;">Error</span>
                            <span style="color:#64748b;font-size:0.9rem;">${message}</span>
                            <button class="btn-retry" onclick="loadEvaluationData()" style="margin-top:4px;">
                                <i class="fa-solid fa-rotate"></i> Try Again
                            </button>
                        </div>
                    </td>
                </tr>`;
            }
            
            // Show pagination with error state
            const paginationWrapper = document.getElementById('paginationWrapper');
            paginationWrapper.style.display = 'flex';
            document.getElementById('pageInfo').textContent = 'Error loading data';
            document.getElementById('prevPage').className = 'page-link disabled';
            document.getElementById('nextPage').className = 'page-link disabled';
            document.getElementById('pageNumbers').innerHTML = '';
        }

        function escapeHtml(text) {
            if(!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function escapeJs(text) {
            if(!text) return '';
            return String(text).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"').replace(/\n/g,'\\n').replace(/\r/g,'\\r');
        }

        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (document.getElementById('feedbackModal').classList.contains('active')) {
                    closeFeedbackModal();
                }
                if (document.getElementById('passwordModal').classList.contains('active')) {
                    closePasswordModal();
                }
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
            }
        });
    </script>

    <?php renderNotificationScript(); ?>
</body>
</html>