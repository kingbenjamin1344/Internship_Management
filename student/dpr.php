<?php
// student/dpr.php
// Full working code with database integration and modal functionality
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/student_notifications.php';
require_once __DIR__ . '/notification_component.php';

// Check if user is student
checkAccess('student');

$username = $_SESSION['username'] ?? 'Student';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Student';
$role = getUserRole();
$student_id = $_SESSION['user_id'] ?? 0;

// Check if student has committed to a job - REQUIRED FOR DPR ACCESS
$committedJob = getStudentCommittedJob($pdo, $student_id);
$hasCommittedJob = (bool)$committedJob;
if (!$committedJob) {
    $_SESSION['error'] = 'You must be committed to an internship position to access Daily Progress Reports. Please commit to a job first from your applications.';
    header('Location: applications.php');
    exit;
}

// Load notifications
$notifications = getStudentNotifications($pdo, $student_id, 10, 0);
$unreadCount = getStudentUnreadNotificationCount($pdo, $student_id);

// ===== PROFILE PICTURE / PASSWORD SETTINGS =====
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

// ===== DPR MONITORING FUNCTIONS =====

/**
 * Check for retroactive submission flags (multiple DPRs in short timeframe)
 */
function checkRetroactiveSubmissions($pdo, $student_id, $new_entry_date) {
    $flags = [];
    
    // Check submissions in the last 7 days
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $stmt = $pdo->prepare("
        SELECT date, created_at 
        FROM dpr_entries 
        WHERE student_id = ? 
        AND date >= ? 
        AND date <= ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$student_id, $weekAgo, $new_entry_date]);
    $recentEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($recentEntries) > 0) {
        // Check for multiple entries on same date
        $dateCounts = [];
        $submissionTimes = [];
        
        foreach ($recentEntries as $entry) {
            $date = $entry['date'];
            $created_at = strtotime($entry['created_at']);
            
            if (!isset($dateCounts[$date])) {
                $dateCounts[$date] = 0;
            }
            $dateCounts[$date]++;
            
            if (!isset($submissionTimes[$date])) {
                $submissionTimes[$date] = [];
            }
            $submissionTimes[$date][] = date('H:i', $created_at);
        }
        
        // Check for multiple entries on same day (retroactive flag)
        foreach ($dateCounts as $date => $count) {
            if ($count >= 2) {
                $flags[] = [
                    'type' => 'multiple_entries',
                    'severity' => 'warning',
                    'date' => $date,
                    'message' => "Multiple DPR entries ({$count}) submitted for {$date}",
                    'times' => $submissionTimes[$date]
                ];
            }
        }
        
        // Check for rapid submissions (within 5 minutes of each other)
        if (count($recentEntries) >= 2) {
            $times = array_column($recentEntries, 'created_at');
            $timestamps = array_map('strtotime', $times);
            sort($timestamps);
            
            for ($i = 0; $i < count($timestamps) - 1; $i++) {
                $diff = $timestamps[$i + 1] - $timestamps[$i];
                if ($diff <= 300) { // 5 minutes
                    $flags[] = [
                        'type' => 'rapid_submission',
                        'severity' => 'warning',
                        'message' => "Rapid DPR submissions detected (" . round($diff/60, 1) . " minutes apart)",
                        'date1' => date('Y-m-d H:i', $timestamps[$i]),
                        'date2' => date('Y-m-d H:i', $timestamps[$i + 1])
                    ];
                    break;
                }
            }
        }
    }
    
    return $flags;
}

/**
 * Check for missed DPR submissions for working days
 */
function checkMissedDPRSubmissions($pdo, $student_id, $committed_date) {
    $missedDates = [];
    
    // Get the current date and the committed date
    $today = date('Y-m-d');
    $committed = date('Y-m-d', strtotime($committed_date));
    
    // Get all submitted dates
    $stmt = $pdo->prepare("
        SELECT DISTINCT date 
        FROM dpr_entries 
        WHERE student_id = ? 
        AND date >= ?
        AND date <= ?
        ORDER BY date ASC
    ");
    $stmt->execute([$student_id, $committed, $today]);
    $submittedDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Check each day from committed date to today
    $current = strtotime($committed);
    $end = strtotime($today);
    
    while ($current <= $end) {
        $currentDate = date('Y-m-d', $current);
        $dayOfWeek = date('N', $current); // 1=Monday, 7=Sunday
        
        // Skip weekends (Saturday and Sunday)
        if ($dayOfWeek < 6) {
            // Check if this date has a DPR entry
            if (!in_array($currentDate, $submittedDates)) {
                // Check if this date is more than 1 day old (allow same-day submissions)
                $daysAgo = (strtotime($today) - $current) / (60 * 60 * 24);
                if ($daysAgo > 1) { // More than 1 day old = truly missed
                    $missedDates[] = [
                        'date' => $currentDate,
                        'day' => date('l', $current),
                        'days_ago' => round($daysAgo)
                    ];
                }
            }
        }
        
        $current = strtotime('+1 day', $current);
    }
    
    return $missedDates;
}

/**
 * Get submission statistics
 */
function getDPRStatistics($pdo, $student_id) {
    // Total entries
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM dpr_entries WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $total = $stmt->fetchColumn();
    
    // Entries by status
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM dpr_entries WHERE student_id = ? GROUP BY status");
    $stmt->execute([$student_id]);
    $byStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Last submission
    $stmt = $pdo->prepare("SELECT MAX(created_at) as last_submission FROM dpr_entries WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $lastSubmission = $stmt->fetchColumn();
    
    // Average submission time (based on time_in if available)
    $stmt = $pdo->prepare("
        SELECT AVG(TIME_TO_SEC(TIMEDIFF(created_at, CONCAT(date, ' ', time_in)))) as avg_time 
        FROM dpr_entries 
        WHERE student_id = ? AND time_in IS NOT NULL
    ");
    $stmt->execute([$student_id]);
    $avgTime = $stmt->fetchColumn();
    
    return [
        'total_entries' => $total,
        'by_status' => $byStatus,
        'last_submission' => $lastSubmission,
        'avg_submission_time' => $avgTime,
        'has_entries' => $total > 0
    ];
}

// ===== END MONITORING FUNCTIONS =====

// Handle AJAX requests for adding DPR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Notification actions
    if ($_POST['action'] === 'get_notifications') {
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        $notifs = getStudentNotifications($pdo, $student_id, $limit, $offset);
        $unread = getStudentUnreadNotificationCount($pdo, $student_id);
        echo json_encode(['success' => true, 'notifications' => $notifs, 'unread_count' => $unread]);
        exit;
    }
    
    if ($_POST['action'] === 'mark_read') {
        $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        $result = markStudentNotificationRead($pdo, $notificationId, $student_id);
        $unread = getStudentUnreadNotificationCount($pdo, $student_id);
        echo json_encode(['success' => $result, 'unread_count' => $unread]);
        exit;
    }
    
    if ($_POST['action'] === 'mark_all_read') {
        $result = markStudentAllNotificationsRead($pdo, $student_id);
        $unread = getStudentUnreadNotificationCount($pdo, $student_id);
        echo json_encode(['success' => $result, 'unread_count' => $unread]);
        exit;
    }

    if ($_POST['action'] === 'delete') {
        $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        $result = deleteStudentNotification($pdo, $notificationId, $student_id);
        $unread = getStudentUnreadNotificationCount($pdo, $student_id);
        echo json_encode(['success' => $result, 'unread_count' => $unread]);
        exit;
    }
    
    if ($_POST['action'] === 'add_dpr') {
        $date = $_POST['date'] ?? date('Y-m-d');
        $time_in = $_POST['time_in'] ?? null;
        $time_out = $_POST['time_out'] ?? null;
        $tasks = $_POST['tasks'] ?? '';
        $feedback = $_POST['feedback'] ?? '';
        $status = $_POST['status'] ?? 'In Progress';
        
        // Validate
        if (empty($tasks)) {
            echo json_encode(['success' => false, 'message' => 'Task description is required']);
            exit;
        }
        
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Check for retroactive submissions BEFORE inserting
            $retroFlags = checkRetroactiveSubmissions($pdo, $student_id, $date);
            
            // Check if this is a missed submission
            $today = date('Y-m-d');
            $isMissed = ($date < $today);
            $daysLate = (strtotime($today) - strtotime($date)) / (60 * 60 * 24);
            
            // Insert the entry
            $stmt = $pdo->prepare("
                INSERT INTO dpr_entries (student_id, date, time_in, time_out, tasks, feedback, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$student_id, $date, $time_in, $time_out, $tasks, $feedback, $status]);
            $entryId = $pdo->lastInsertId();
            
            // Log the submission for monitoring
            $submissionLog = [
                'entry_id' => $entryId,
                'student_id' => $student_id,
                'date' => $date,
                'submitted_at' => date('Y-m-d H:i:s'),
                'days_late' => $isMissed ? round($daysLate, 1) : 0,
                'retroactive_flags' => $retroFlags
            ];
            
            $_SESSION['dpr_submission_info'] = $submissionLog;

            $supervisorId = findSupervisorForStudent($pdo, $student_id);
            if ($supervisorId) {
                createSystemNotification(
                    $pdo,
                    $supervisorId,
                    $student_id,
                    'dpr',
                    'New DPR Submission',
                    'A student submitted a DPR for review.',
                    'dashboard.php'
                );
            }
            
            $pdo->commit();
            
            // Prepare response with monitoring data
            $response = [
                'success' => true, 
                'message' => 'DPR added successfully',
                'monitoring' => [
                    'is_missed' => $isMissed,
                    'days_late' => $isMissed ? round($daysLate, 1) : 0,
                    'retroactive_flags' => $retroFlags,
                    'submission_time' => date('Y-m-d H:i:s')
                ]
            ];
            
            echo json_encode($response);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'update_status') {
        $id = $_POST['id'] ?? 0;
        $status = $_POST['status'] ?? '';
        
        try {
            $stmt = $pdo->prepare("UPDATE dpr_entries SET status = ? WHERE id = ? AND student_id = ?");
            $stmt->execute([$status, $id, $student_id]);
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    // Change password (from the sidebar profile modal)
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
            $stmt->execute([$student_id]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($currentPassword, $hash)) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
                exit;
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$newHash, $student_id]);

            echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Update profile picture (from the sidebar avatar)
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
        $newFileName = 'user_' . $student_id . '_' . time() . '.' . strtolower($ext);
        $destination = $avatarUploadDir . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt->execute([$newFileName, $student_id]);

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

// Get filter parameters
$filter_date = $_GET['filter_date'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

// Fetch DPR entries from database with filters
$dprEntries = [];
try {
    if ($student_id > 0) {
        $sql = "SELECT * FROM dpr_entries WHERE student_id = ?";
        $params = [$student_id];
        
        if (!empty($filter_date)) {
            $sql .= " AND date = ?";
            $params[] = $filter_date;
        }
        
        if (!empty($filter_status)) {
            $sql .= " AND status = ?";
            $params[] = $filter_status;
        }
        
        $sql .= " ORDER BY date DESC, id DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $dprEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Table') !== false) {
        $dprEntries = [];
        $tableError = 'DPR table not found. Please run the database setup script.';
    } else {
        $dprEntries = [];
    }
}

// ===== PAGINATION SETUP =====
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$limit = 10; // items per page
$offset = ($currentPage - 1) * $limit;
$totalEntries = count($dprEntries);
$totalPages = max(1, ceil($totalEntries / $limit));
$paginatedEntries = array_slice($dprEntries, $offset, $limit);

// Get monitoring data
$dprStats = getDPRStatistics($pdo, $student_id);
$missedSubmissions = checkMissedDPRSubmissions($pdo, $student_id, $committedJob['committed_at']);
$retroactiveFlags = checkRetroactiveSubmissions($pdo, $student_id, date('Y-m-d'));

// Get distinct dates from the database for the filter dropdown
$availableDates = [];
try {
    if ($student_id > 0) {
        $stmt = $pdo->prepare("SELECT DISTINCT date FROM dpr_entries WHERE student_id = ? ORDER BY date DESC");
        $stmt->execute([$student_id]);
        $availableDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    $availableDates = [];
}

// Current profile picture
$profilePicture = getUserProfilePicture($pdo, $student_id);
$profilePictureUrl = $profilePicture ? $avatarPublicPath . $profilePicture : '';

// Format dates for display
function formatDateDisplay($date) {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    return date('d M Y', $timestamp);
}

function formatTimeDisplay($time) {
    if (empty($time)) return '—';
    return date('h:i A', strtotime($time));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Progress Report · DPR</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <?php renderStudentNotificationCSS(); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
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

        .header-nav .nav-item {
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

        .header-nav .nav-item i {
            font-size: 0.9rem;
        }

        .header-nav .nav-item:hover {
            background: rgba(255, 204, 51, 0.2);
            color: #fff;
        }

        .header-nav .nav-item:hover i {
            color: #FFCC33;
        }

        .header-nav .nav-item.active {
            background: #FFCC33;
            color: #003300;
            font-weight: 600;
        }

        .header-nav .nav-item.active i {
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
        }

        /* ---- Section Header ---- */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .section-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-header h2 i {
            color: #3b82f6;
        }

        .badge-count {
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

        .job-commitment-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
            border-radius: 0;
        }

        .job-commitment-info .job-icon {
            background: #3b82f6;
            color: white;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
            border-radius: 0;
        }

        .job-commitment-info .job-details {
            flex: 1;
            min-width: 0;
        }

        .job-commitment-info .job-details .job-title {
            font-weight: 700;
            color: #0f172a;
            display: block;
            font-size: 0.9rem;
        }

        .job-commitment-info .job-details .job-meta {
            color: #64748b;
            font-size: 0.75rem;
            display: block;
            margin-top: 2px;
        }

        .job-commitment-info .job-details .job-meta i {
            margin-right: 4px;
            font-size: 0.7rem;
            color: #3b82f6;
        }

        /* ---- Table Controls (sharp) ---- */
        .table-controls {
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

        .controls-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .controls-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 4px;
        }

        .filter-item {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #fff;
            padding: 4px 10px 4px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 0;
        }

        .filter-item:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-item i {
            color: #94a3b8;
            font-size: 0.8rem;
        }

        .filter-item select {
            border: none;
            padding: 6px 4px;
            font-size: 0.8rem;
            background: transparent;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: #0a1628;
            min-width: 110px;
            cursor: pointer;
        }

        .filter-item select:focus {
            outline: none;
        }

        .btn-sm {
            padding: 6px 14px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-sm-primary {
            background: #0f172a;
            color: #fff;
            border-color: #0f172a;
        }

        .btn-sm-primary:hover {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .btn-sm-success {
            background: #059669;
            color: #fff;
            border-color: #059669;
        }

        .btn-sm-success:hover {
            background: #047857;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2);
        }

        .btn-sm-filter {
            background: #3b82f6;
            color: #fff;
            border-color: #3b82f6;
        }

        .btn-sm-filter:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }

        .btn-sm-reset {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        .btn-sm-reset:hover {
            background: #e9edf4;
            color: #0f172a;
        }

        /* ---- Table wrapper (sharp) ---- */
        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
            min-height: 320px;
        }

        .dpr-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .dpr-table th {
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

        .dpr-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .dpr-table tbody tr:last-child td {
            border-bottom: none;
        }

        .dpr-table tbody tr:hover {
            background: #fafcff;
        }

        .dpr-table .date-cell {
            font-weight: 600;
            color: #0f172a;
        }

        .badge-status {
            padding: 3px 12px;
            border-radius: 0;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            border: 1px solid transparent;
        }

        .badge-status.in-progress {
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .badge-status.completed {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .badge-status.pending {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }

        .view-btn {
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
        }

        .view-btn-task {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .view-btn-task:hover {
            background: #bfdbfe;
            transform: scale(1.02);
        }

        .view-btn-feedback {
            background: #eef2ff;
            color: #4338ca;
            border-color: #a5b4fc;
        }

        .view-btn-feedback:hover {
            background: #c7d2fe;
            transform: scale(1.02);
        }

        .view-btn.no-content {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: default;
            border-color: #e2e8f0;
        }

        .view-btn.no-content:hover {
            background: #f1f5f9;
            transform: none;
        }

        .empty-row td {
            padding: 32px 16px;
            text-align: center;
            color: #94a3b8;
        }

        .empty-row td i {
            font-size: 2rem;
            display: block;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        /* ===== PAGINATION ===== */
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

        #passwordModal .modal-card {
            max-width: 440px;
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
            font-size: 1.8rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 8px;
            transition: 0.15s;
            line-height: 1;
        }

        .modal-close:hover {
            color: #1e293b;
        }

        .modal-hint {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 18px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.82rem;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .form-group label i {
            margin-right: 6px;
            color: #64748b;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.9rem;
            background: #fafcff;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .form-group textarea {
            min-height: 70px;
            resize: vertical;
            max-height: 180px;
        }

        .form-row-three {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .form-row-three .form-group {
            margin-bottom: 0;
        }

        .form-row-three .form-group input {
            width: 100%;
            padding: 8px 10px;
        }

        .form-row-three .form-group label {
            font-size: 0.78rem;
            margin-bottom: 3px;
        }

        @media (max-width: 600px) {
            .form-row-three {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .form-row-three .form-group {
                margin-bottom: 0;
            }
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            border-top: 1px solid #edf2f7;
            padding-top: 18px;
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

        .btn-submit {
            background: #0f172a;
            border: 1px solid #0f172a;
            color: #fff;
            padding: 8px 24px;
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

        .btn-submit:hover {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .view-content-display {
            padding: 4px 0;
        }

        .view-content-display .meta-info {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid #edf2f7;
        }

        .view-content-display .meta-info .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            color: #64748b;
        }

        .view-content-display .meta-info .meta-item strong {
            color: #0f172a;
            font-weight: 600;
        }

        .view-content-display .content-text {
            background: #f8fafc;
            padding: 14px 18px;
            font-size: 0.9rem;
            line-height: 1.7;
            color: #1e293b;
            min-height: 50px;
            max-height: 280px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: 1px solid #e2e8f0;
            border-radius: 0;
        }

        .view-content-display .content-text.task-text {
            border-left: 4px solid #2563eb;
        }

        .view-content-display .content-text.feedback-text {
            border-left: 4px solid #7c3aed;
        }

        .view-content-display .content-text .empty-text {
            color: #94a3b8;
            font-style: italic;
        }

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

        .alert-box {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-radius: 0;
        }

        .alert-box i {
            font-size: 1.1rem;
        }

        /* ---- Responsive ---- */
        @media (max-width: 1024px) {
            .section-header {
                flex-direction: column;
                align-items: stretch;
            }
            .job-commitment-info {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
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
            .header-nav .nav-item {
                padding: 6px 12px;
                font-size: 0.8rem;
            }
            .notif-bell {
                align-self: center;
            }
            .page-card {
                padding: 16px;
            }
            .dpr-table th,
            .dpr-table td {
                padding: 6px 8px;
                font-size: 0.72rem;
            }
            .modal-card {
                padding: 20px 16px;
                max-height: 95vh;
                margin: 10px;
            }
            .table-controls {
                flex-direction: column;
                align-items: stretch;
            }
            .controls-left,
            .controls-right {
                justify-content: center;
                flex-wrap: wrap;
            }
            .filter-item select {
                min-width: 90px;
            }
            .view-btn {
                font-size: 0.6rem;
                padding: 2px 8px;
            }
            .job-commitment-info {
                padding: 10px 14px;
            }
            .job-commitment-info .job-icon {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
            .job-commitment-info .job-details .job-title {
                font-size: 0.85rem;
            }
            .pagination-wrapper {
                justify-content: center;
            }
            .pagination-wrapper .page-info {
                width: 100%;
                text-align: center;
                margin-right: 0;
                margin-bottom: 8px;
            }
            .view-content-display .meta-info {
                flex-direction: column;
                gap: 6px;
            }
        }

        @media (max-width: 480px) {
            .header-nav .nav-item {
                font-size: 0.7rem;
                padding: 4px 8px;
            }
            .header-nav .nav-item i {
                font-size: 0.7rem;
            }
            .dpr-table th,
            .dpr-table td {
                padding: 4px 6px;
                font-size: 0.65rem;
            }
            .btn-sm {
                font-size: 0.65rem;
                padding: 4px 10px;
            }
            .badge-status {
                font-size: 0.6rem;
                padding: 2px 8px;
            }
            .view-btn {
                font-size: 0.55rem;
                padding: 2px 6px;
            }
            .modal-actions {
                flex-direction: column;
            }
            .modal-actions .btn-close-modal,
            .modal-actions .btn-submit {
                width: 100%;
                justify-content: center;
            }
            .pagination-wrapper .page-link {
                padding: 2px 8px;
                font-size: 0.7rem;
                min-width: 28px;
            }
            .controls-left,
            .controls-right {
                flex-wrap: wrap;
            }
            .filter-item {
                flex: 1;
                min-width: 120px;
            }
            .filter-item select {
                min-width: 70px;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <!-- SIDEBAR: user profile panel -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-graduation-cap"></i>
                <h2>Role Based<span>Student Portal</span></h2>
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

        <main class="main-content">
            <div class="top-header">
                <div class="header-left">
                    <h1>
                        <i class="fa-regular fa-calendar-check"></i>
                        DPR
                    </h1>
                </div>
                <div class="header-right">
                    <nav class="header-nav">
                        <a class="nav-item" href="dashboard.php"> Dashboard</a>
                        <a class="nav-item" href="applications.php"> My Applications</a>
                        <?php if (!$hasCommittedJob): ?>
                            <a class="nav-item" href="apply.php"> Apply Job</a>
                        <?php endif; ?>
                        <?php if ($hasCommittedJob): ?>
                            <a class="nav-item active" href="dpr.php"> Daily Progress Report</a>
                        <?php endif; ?>
                    </nav>
                    <?php renderStudentNotificationBell($unreadCount, $notifications); ?>
                </div>
            </div>

            <!-- PAGE CARD -->
            <div class="page-card">
                <div class="section-header">
                    <h2>
                        <i class="fa-regular fa-clock"></i> 
                        Progress Reports 
                        <span class="badge-count"><?php echo $totalEntries; ?></span>
                    </h2>
                    <div class="job-commitment-info">
                        <div class="job-icon">
                            <i class="fa-solid fa-briefcase"></i>
                        </div>
                        <div class="job-details">
                            <span class="job-title">
                                <?php echo htmlspecialchars($committedJob['job_title']); ?>
                            </span>
                            <span class="job-meta">
                                <i class="fa-solid fa-building"></i>
                                <?php echo htmlspecialchars($committedJob['company_name']); ?>
                                <i class="fa-regular fa-calendar-check" style="margin-left: 8px;"></i>
                                <?php echo date('M j, Y', strtotime($committedJob['committed_at'])); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if (isset($tableError)): ?>
                    <div class="alert-box">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span><?php echo htmlspecialchars($tableError); ?></span>
                    </div>
                <?php endif; ?>

                <div class="table-controls">
                    <div class="controls-left">
                        <span class="filter-label"><i class="fa-solid fa-sliders"></i> Filters</span>
                        
                        <div class="filter-item">
                            <i class="fa-regular fa-calendar"></i>
                            <select id="filterDate">
                                <option value="">All Dates</option>
                                <?php if (!empty($availableDates)): ?>
                                    <?php foreach ($availableDates as $date): ?>
                                        <option value="<?php echo htmlspecialchars($date); ?>" <?php echo $filter_date === $date ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(formatDateDisplay($date)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="filter-item">
                            <i class="fa-regular fa-flag"></i>
                            <select id="filterStatus">
                                <option value="">All Status</option>
                                <option value="In Progress" <?php echo $filter_status === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo $filter_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>

                        <button class="btn-sm btn-sm-filter" onclick="applyFilters()">
                            <i class="fa-solid fa-filter"></i> Apply
                        </button>
                        <button class="btn-sm btn-sm-reset" onclick="resetFilters()">
                            <i class="fa-solid fa-rotate-right"></i> Reset
                        </button>
                    </div>

                    <div class="controls-right">
                        <button class="btn-sm btn-sm-success" onclick="exportPDF()">
                            <i class="fa-regular fa-file-pdf"></i> Export PDF
                        </button>
                        <button class="btn-sm btn-sm-primary" id="openModalBtn">
                            <i class="fa-regular fa-plus"></i> Add Entry
                        </button>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="dpr-table" id="dprTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Task Accomplished</th>
                                <th>Student Feedback</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="dprTableBody">
                            <?php if (!empty($paginatedEntries) && count($paginatedEntries) > 0): ?>
                                <?php foreach ($paginatedEntries as $entry): ?>
                                    <?php
                                    $statusClass = strtolower($entry['status']);
                                    $statusClass = str_replace(' ', '-', $statusClass);
                                    $hasTask = !empty($entry['tasks']);
                                    $hasFeedback = !empty($entry['feedback']);
                                    $displayDate = formatDateDisplay($entry['date']);
                                    ?>
                                    <tr data-id="<?php echo $entry['id']; ?>">
                                        <td class="date-cell"><?php echo htmlspecialchars($displayDate); ?></td>
                                        <td><?php echo htmlspecialchars(formatTimeDisplay($entry['time_in'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(formatTimeDisplay($entry['time_out'] ?? '')); ?></td>
                                        <td>
                                            <?php if ($hasTask): ?>
                                                <button class="view-btn view-btn-task view-task-btn" 
                                                        data-id="<?php echo $entry['id']; ?>"
                                                        data-task="<?php echo htmlspecialchars($entry['tasks'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-date="<?php echo htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-timein="<?php echo htmlspecialchars($entry['time_in'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-timeout="<?php echo htmlspecialchars($entry['time_out'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <i class="fa-regular fa-list-check"></i> View Task
                                                </button>
                                            <?php else: ?>
                                                <button class="view-btn no-content" disabled>
                                                    <i class="fa-regular fa-list-check"></i> No Task
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($hasFeedback): ?>
                                                <button class="view-btn view-btn-feedback view-feedback-btn" 
                                                        data-id="<?php echo $entry['id']; ?>"
                                                        data-feedback="<?php echo htmlspecialchars($entry['feedback'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-date="<?php echo htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-task="<?php echo htmlspecialchars($entry['tasks'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <i class="fa-regular fa-comment"></i> View Feedback
                                                </button>
                                            <?php else: ?>
                                                <button class="view-btn no-content" disabled>
                                                    <i class="fa-regular fa-comment"></i> No Feedback
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status <?php echo $statusClass; ?>">
                                                <?php echo htmlspecialchars($entry['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="empty-row">
                                    <td colspan="6">
                                        <i class="fa-regular fa-calendar-circle-plus"></i>
                                        No progress reports found.<br>
                                        <span style="font-size:0.85rem; color:#cbd5e1;">Start your first entry today!</span>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ===== PAGINATION (ALWAYS VISIBLE) ===== -->
                <div class="pagination-wrapper">
                    <span class="page-info">
                        <?php if ($totalEntries > 0): ?>
                            Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $limit, $totalEntries); ?> of <?php echo $totalEntries; ?>
                        <?php else: ?>
                            No entries to display
                        <?php endif; ?>
                    </span>
                    <?php
                    // Previous link
                    if ($currentPage > 1) {
                        echo '<a href="?page=' . ($currentPage - 1) . '&filter_date=' . urlencode($filter_date) . '&filter_status=' . urlencode($filter_status) . '" class="page-link">Prev</a>';
                    } else {
                        echo '<span class="page-link disabled">Prev</span>';
                    }

                    // Page numbers (always show at least page 1)
                    if ($totalPages > 1) {
                        $start = max(1, $currentPage - 2);
                        $end = min($totalPages, $currentPage + 2);
                        if ($start > 1) {
                            echo '<a href="?page=1&filter_date=' . urlencode($filter_date) . '&filter_status=' . urlencode($filter_status) . '" class="page-link">1</a>';
                            if ($start > 2) echo '<span class="page-link disabled">…</span>';
                        }
                        for ($i = $start; $i <= $end; $i++) {
                            $active = ($i == $currentPage) ? 'active' : '';
                            echo '<a href="?page=' . $i . '&filter_date=' . urlencode($filter_date) . '&filter_status=' . urlencode($filter_status) . '" class="page-link ' . $active . '">' . $i . '</a>';
                        }
                        if ($end < $totalPages) {
                            if ($end < $totalPages - 1) echo '<span class="page-link disabled">…</span>';
                            echo '<a href="?page=' . $totalPages . '&filter_date=' . urlencode($filter_date) . '&filter_status=' . urlencode($filter_status) . '" class="page-link">' . $totalPages . '</a>';
                        }
                    } else {
                        // Show page 1 when only one page or no items
                        echo '<a href="?page=1" class="page-link active">1</a>';
                    }

                    // Next link
                    if ($currentPage < $totalPages) {
                        echo '<a href="?page=' . ($currentPage + 1) . '&filter_date=' . urlencode($filter_date) . '&filter_status=' . urlencode($filter_status) . '" class="page-link">Next</a>';
                    } else {
                        echo '<span class="page-link disabled">Next</span>';
                    }
                    ?>
                </div>
            </div>
        </main>
    </div>

    <!-- ADD DPR MODAL -->
    <div class="modal-overlay" id="dprModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-regular fa-pen-to-square"></i> Add DPR Entry</h2>
                <button class="modal-close" id="closeModalBtn">&times;</button>
            </div>
            <div class="modal-hint">Fill in your daily progress details below.</div>

            <form id="dprForm">
                <!-- THREE COLUMN LAYOUT: Date, Time In, Time Out -->
                <div class="form-row-three">
                    <div class="form-group">
                        <label for="entryDate"><i class="fa-regular fa-calendar"></i> Date</label>
                        <input type="date" id="entryDate" required />
                    </div>
                    <div class="form-group">
                        <label for="entryTimeIn"><i class="fa-regular fa-clock"></i> Time In</label>
                        <input type="time" id="entryTimeIn" />
                    </div>
                    <div class="form-group">
                        <label for="entryTimeOut"><i class="fa-regular fa-clock"></i> Time Out</label>
                        <input type="time" id="entryTimeOut" />
                    </div>
                </div>

                <div class="form-group">
                    <label for="entryTasks"><i class="fa-regular fa-list-check"></i> Task Accomplished *</label>
                    <textarea id="entryTasks" placeholder="Describe what you accomplished today..." required></textarea>
                </div>

                <div class="form-group">
                    <label for="entryFeedback"><i class="fa-regular fa-comment"></i> Student Feedback</label>
                    <textarea id="entryFeedback" placeholder="Any feedback or questions?"></textarea>
                </div>

                <div class="form-group">
                    <label for="entryStatus"><i class="fa-regular fa-flag"></i> Status</label>
                    <select id="entryStatus">
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-close-modal" id="closeModalBtn2">Cancel</button>
                    <button type="submit" class="btn-submit"><i class="fa-regular fa-check"></i> Save Entry</button>
                </div>
            </form>
        </div>
    </div>

    <!-- VIEW TASK MODAL -->
    <div class="modal-overlay" id="taskModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-regular fa-list-check"></i> Task Accomplished</h2>
                <button class="modal-close" id="closeTaskBtn">&times;</button>
            </div>
            <div class="modal-hint">View the task details for this entry.</div>

            <div class="view-content-display">
                <div class="meta-info">
                    <div class="meta-item">
                        <i class="fa-regular fa-calendar"></i>
                        <strong>Date:</strong> <span id="taskDate">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-regular fa-clock"></i>
                        <strong>Time:</strong> <span id="taskTime">—</span>
                    </div>
                </div>
                <div class="content-text task-text" id="taskTextDisplay">
                    <span class="empty-text">No task description provided.</span>
                </div>
            </div>

            <div class="modal-actions" style="border-top: none; padding-top: 16px; margin-top: 8px;">
                <button class="btn-close-modal" id="closeTaskBtn2">Close</button>
            </div>
        </div>
    </div>

    <!-- VIEW FEEDBACK MODAL -->
    <div class="modal-overlay" id="feedbackModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-regular fa-comment"></i> Student Feedback</h2>
                <button class="modal-close" id="closeFeedbackBtn">&times;</button>
            </div>
            <div class="modal-hint">View the feedback details for this entry.</div>

            <div class="view-content-display">
                <div class="meta-info">
                    <div class="meta-item">
                        <i class="fa-regular fa-calendar"></i>
                        <strong>Date:</strong> <span id="feedbackDate">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-regular fa-list-check"></i>
                        <strong>Task:</strong> <span id="feedbackTask">—</span>
                    </div>
                </div>
                <div class="content-text feedback-text" id="feedbackTextDisplay">
                    <span class="empty-text">No feedback provided.</span>
                </div>
            </div>

            <div class="modal-actions" style="border-top: none; padding-top: 16px; margin-top: 8px;">
                <button class="btn-close-modal" id="closeFeedbackBtn2">Close</button>
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
                    <button type="submit" class="btn-submit"><i class="fa-solid fa-check"></i> Update Password</button>
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
        // ===== GLOBAL FUNCTIONS =====
        
        function closeTaskModal() {
            var modal = document.getElementById('taskModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function closeFeedbackModal() {
            var modal = document.getElementById('feedbackModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function closeDprModal() {
            var modal = document.getElementById('dprModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function closePasswordModal() {
            var modal = document.getElementById('passwordModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function applyFilters() {
            var date = document.getElementById('filterDate').value;
            var status = document.getElementById('filterStatus').value;

            var url = window.location.pathname + '?';
            if (date) url += 'filter_date=' + date + '&';
            if (status) url += 'filter_status=' + encodeURIComponent(status) + '&';

            window.location.href = url;
        }

        function resetFilters() {
            window.location.href = window.location.pathname;
        }

        // ===== DOM READY =====
        document.addEventListener('DOMContentLoaded', function() {
            // Get elements
            var dprModal = document.getElementById('dprModal');
            var openBtn = document.getElementById('openModalBtn');
            var closeBtns = document.getElementById('closeModalBtn');
            var closeBtn2 = document.getElementById('closeModalBtn2');
            var form = document.getElementById('dprForm');
            var toast = document.getElementById('toast');
            var toastMessage = document.getElementById('toastMessage');

            // Task modal close buttons
            document.getElementById('closeTaskBtn').addEventListener('click', closeTaskModal);
            document.getElementById('closeTaskBtn2').addEventListener('click', closeTaskModal);

            // Feedback modal close buttons
            document.getElementById('closeFeedbackBtn').addEventListener('click', closeFeedbackModal);
            document.getElementById('closeFeedbackBtn2').addEventListener('click', closeFeedbackModal);

            // DPR modal close buttons
            if (closeBtns) closeBtns.addEventListener('click', closeDprModal);
            if (closeBtn2) closeBtn2.addEventListener('click', closeDprModal);

            // Password modal open/close
            var passwordModal = document.getElementById('passwordModal');
            var openPasswordBtn = document.getElementById('openPasswordModalBtn');
            var closePasswordBtn = document.getElementById('closePasswordBtn');
            var closePasswordBtn2 = document.getElementById('closePasswordBtn2');
            var passwordForm = document.getElementById('passwordForm');

            if (openPasswordBtn) {
                openPasswordBtn.addEventListener('click', function() {
                    if (passwordModal) {
                        passwordModal.classList.add('active');
                        document.body.style.overflow = 'hidden';
                        if (passwordForm) passwordForm.reset();
                    }
                });
            }
            if (closePasswordBtn) closePasswordBtn.addEventListener('click', closePasswordModal);
            if (closePasswordBtn2) closePasswordBtn2.addEventListener('click', closePasswordModal);
            if (passwordModal) {
                passwordModal.addEventListener('click', function(e) {
                    if (e.target === passwordModal) closePasswordModal();
                });
            }

            function showToast(message, type) {
                type = type || 'success';
                if (!toast || !toastMessage) return;
                toast.className = 'toast ' + type + ' show';
                toastMessage.textContent = message;
                clearTimeout(toast._timeout);
                toast._timeout = setTimeout(function() {
                    toast.classList.remove('show');
                }, 4000);
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

            // ===== Avatar upload =====
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

            // Form fields
            var dateInput = document.getElementById('entryDate');
            var timeInInput = document.getElementById('entryTimeIn');
            var timeOutInput = document.getElementById('entryTimeOut');
            var tasksInput = document.getElementById('entryTasks');
            var feedbackInput = document.getElementById('entryFeedback');
            var statusSelect = document.getElementById('entryStatus');

            // Set default date
            var today = new Date().toISOString().slice(0, 10);
            if (dateInput) dateInput.value = today;

            // Open modal
            if (openBtn) {
                openBtn.addEventListener('click', function() {
                    if (dprModal) {
                        dprModal.classList.add('active');
                        document.body.style.overflow = 'hidden';
                        if (dateInput) dateInput.value = today;
                        if (timeInInput) timeInInput.value = '';
                        if (timeOutInput) timeOutInput.value = '';
                        if (tasksInput) tasksInput.value = '';
                        if (feedbackInput) feedbackInput.value = '';
                        if (statusSelect) statusSelect.value = 'In Progress';
                        setTimeout(function() { if (tasksInput) tasksInput.focus(); }, 100);
                    }
                });
            }

            // Close on overlay click
            if (dprModal) {
                dprModal.addEventListener('click', function(e) {
                    if (e.target === dprModal) closeDprModal();
                });
            }

            var taskModal = document.getElementById('taskModal');
            if (taskModal) {
                taskModal.addEventListener('click', function(e) {
                    if (e.target === taskModal) closeTaskModal();
                });
            }

            var feedbackModal = document.getElementById('feedbackModal');
            if (feedbackModal) {
                feedbackModal.addEventListener('click', function(e) {
                    if (e.target === feedbackModal) closeFeedbackModal();
                });
            }

            // Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (taskModal && taskModal.classList.contains('active')) {
                        closeTaskModal();
                    }
                    if (feedbackModal && feedbackModal.classList.contains('active')) {
                        closeFeedbackModal();
                    }
                    if (dprModal && dprModal.classList.contains('active')) {
                        closeDprModal();
                    }
                    if (passwordModal && passwordModal.classList.contains('active')) {
                        closePasswordModal();
                    }
                }
            });

            // ===== VIEW TASK BUTTONS (Event Delegation) =====
            document.addEventListener('click', function(e) {
                var target = e.target.closest('.view-task-btn');
                if (target) {
                    var task = target.getAttribute('data-task');
                    var date = target.getAttribute('data-date');
                    var timeIn = target.getAttribute('data-timein');
                    var timeOut = target.getAttribute('data-timeout');
                    var id = target.getAttribute('data-id');
                    
                    var taskModal = document.getElementById('taskModal');
                    var taskDate = document.getElementById('taskDate');
                    var taskTime = document.getElementById('taskTime');
                    var taskTextDisplay = document.getElementById('taskTextDisplay');

                    if (!taskModal) {
                        return;
                    }

                    taskDate.textContent = date || '—';
                    
                    var timeStr = (timeIn && timeOut) ? formatTimeDisplay(timeIn) + ' - ' + formatTimeDisplay(timeOut) : (timeIn ? formatTimeDisplay(timeIn) : '—');
                    taskTime.textContent = timeStr;
                    
                    if (task && task.trim() !== '') {
                        taskTextDisplay.textContent = task;
                        taskTextDisplay.className = 'content-text task-text';
                    } else {
                        taskTextDisplay.innerHTML = '<span class="empty-text">No task description provided for this entry.</span>';
                        taskTextDisplay.className = 'content-text task-text';
                    }
                    
                    taskModal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            });

            // ===== VIEW FEEDBACK BUTTONS (Event Delegation) =====
            document.addEventListener('click', function(e) {
                var target = e.target.closest('.view-feedback-btn');
                if (target) {
                    var feedback = target.getAttribute('data-feedback');
                    var date = target.getAttribute('data-date');
                    var task = target.getAttribute('data-task');
                    var id = target.getAttribute('data-id');
                    
                    var feedbackModal = document.getElementById('feedbackModal');
                    var feedbackDate = document.getElementById('feedbackDate');
                    var feedbackTask = document.getElementById('feedbackTask');
                    var feedbackTextDisplay = document.getElementById('feedbackTextDisplay');

                    if (!feedbackModal) {
                        return;
                    }

                    feedbackDate.textContent = date || '—';
                    feedbackTask.textContent = task || '—';
                    
                    if (feedback && feedback.trim() !== '') {
                        feedbackTextDisplay.textContent = feedback;
                        feedbackTextDisplay.className = 'content-text feedback-text';
                    } else {
                        feedbackTextDisplay.innerHTML = '<span class="empty-text">No feedback provided for this entry.</span>';
                        feedbackTextDisplay.className = 'content-text feedback-text';
                    }
                    
                    feedbackModal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            });

            if (toast) {
                toast.addEventListener('click', function() {
                    toast.classList.remove('show');
                });
            }

            // Form submission
            var isSubmittingDPR = false;
            
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();

                    if (isSubmittingDPR) {
                        return false;
                    }

                    var date = dateInput ? dateInput.value.trim() : '';
                    if (!date) {
                        showToast('Please select a date.', 'error');
                        return;
                    }

                    var tasks = tasksInput ? tasksInput.value.trim() : '';
                    if (!tasks) {
                        showToast('Please describe your task accomplished.', 'error');
                        if (tasksInput) tasksInput.focus();
                        return;
                    }

                    var timeIn = timeInInput ? timeInInput.value : '';
                    var timeOut = timeOutInput ? timeOutInput.value : '';
                    var feedback = feedbackInput ? feedbackInput.value.trim() : '';
                    var status = statusSelect ? statusSelect.value : 'In Progress';

                    var formData = new FormData();
                    formData.append('action', 'add_dpr');
                    formData.append('date', date);
                    formData.append('time_in', timeIn);
                    formData.append('time_out', timeOut);
                    formData.append('tasks', tasks);
                    formData.append('feedback', feedback);
                    formData.append('status', status);

                    var submitBtn = form.querySelector('button[type="submit"]');
                    
                    isSubmittingDPR = true;
                    
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
                    }

                    fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        })
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            if (data.success) {
                                var message = 'DPR entry added successfully!';
                                var type = 'success';
                                
                                if (data.monitoring) {
                                    if (data.monitoring.is_missed) {
                                        message += ' (⚠️ ' + data.monitoring.days_late + ' days late)';
                                        type = 'warning';
                                    }
                                    if (data.monitoring.retroactive_flags && data.monitoring.retroactive_flags.length > 0) {
                                        message += ' (⚠️ Retroactive submission detected)';
                                        type = 'warning';
                                    }
                                }
                                
                                showToast(message, type);
                                if (closeDprModal) closeDprModal();
                                setTimeout(function() { window.location.reload(); }, 1500);
                            } else {
                                showToast(data.message || 'Failed to add entry.', 'error');
                                isSubmittingDPR = false;
                            }
                        })
                        .catch(function(error) {
                            console.error('Error:', error);
                            showToast('An error occurred. Please try again.', 'error');
                            isSubmittingDPR = false;
                        })
                        .finally(function() {
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = '<i class="fa-regular fa-check"></i> Save Entry';
                            }
                        });
                });
            }

            // Filter enter key
            var filterDate = document.getElementById('filterDate');
            var filterStatus = document.getElementById('filterStatus');

            if (filterDate) {
                filterDate.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') applyFilters();
                });
            }

            if (filterStatus) {
                filterStatus.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') applyFilters();
                });
            }
        });

        // Helper function for time formatting
        function formatTimeDisplay(timeStr) {
            if (!timeStr) return '—';
            var parts = timeStr.split(':');
            if (parts.length < 2) return timeStr;
            var hour = parseInt(parts[0]);
            var minute = parts[1];
            var ampm = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12 || 12;
            return hour + ':' + minute + ' ' + ampm;
        }

        // ----- PDF Export -----
        function exportPDF() {
            if (typeof window.jspdf === 'undefined') {
                alert('PDF library not loaded. Please check your internet connection.');
                return;
            }

            var { jsPDF } = window.jspdf;
            var doc = new jsPDF('landscape', 'mm', 'a4');

            var table = document.getElementById('dprTable');
            var rows = table.querySelectorAll('tbody tr');

            if (rows.length === 0 || rows[0].classList.contains('empty-row')) {
                alert('No data to export!');
                return;
            }

            var tableData = [];
            var headers = ['Date', 'Time In', 'Time Out', 'Task Accomplished', 'Student Feedback', 'Status'];

            rows.forEach(function(row) {
                var cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    var rowData = [];
                    cells.forEach(function(cell) {
                        var text = cell.textContent.trim();
                        text = text.replace(/\s+/g, ' ');
                        rowData.push(text);
                    });
                    tableData.push(rowData);
                }
            });

            var studentNameEl = document.querySelector('.profile-panel .name');
            var studentName = studentNameEl ? studentNameEl.textContent : 'Student';

            doc.setFontSize(20);
            doc.setTextColor(15, 23, 42);
            doc.setFont('helvetica', 'bold');
            doc.text('Daily Progress Report', 14, 22);

            doc.setFontSize(10);
            doc.setTextColor(100, 116, 139);
            doc.setFont('helvetica', 'normal');
            doc.text('Student: ' + studentName, 14, 30);
            doc.text('Generated: ' + new Date().toLocaleString(), 14, 36);

            var filterText = '';
            var filterDate = document.getElementById('filterDate') ? document.getElementById('filterDate').value : '';
            var filterStatus = document.getElementById('filterStatus') ? document.getElementById('filterStatus').value : '';
            if (filterDate || filterStatus) {
                filterText = 'Filtered: ';
                if (filterDate) filterText += 'Date: ' + filterDate + ' ';
                if (filterStatus) filterText += 'Status: ' + filterStatus;
                doc.text(filterText, 14, 42);
            }

            doc.autoTable({
                head: [headers],
                body: tableData,
                startY: filterText ? 50 : 44,
                theme: 'striped',
                styles: {
                    fontSize: 8,
                    cellPadding: 3,
                    overflow: 'linebreak',
                    lineColor: [226, 232, 240],
                    lineWidth: 0.1,
                },
                headStyles: {
                    fillColor: [15, 23, 42],
                    textColor: [255, 255, 255],
                    fontSize: 8,
                    fontStyle: 'bold',
                },
                alternateRowStyles: {
                    fillColor: [248, 250, 252],
                },
                columnStyles: {
                    0: { cellWidth: 25 },
                    1: { cellWidth: 22 },
                    2: { cellWidth: 22 },
                    3: { cellWidth: 50 },
                    4: { cellWidth: 50 },
                    5: { cellWidth: 25 },
                },
                margin: { left: 14, right: 14 },
                didParseCell: function(data) {
                    if (data.section === 'body' && data.column.index === 5) {
                        var status = data.cell.text[0];
                        if (status === 'Completed') {
                            data.cell.styles.textColor = [22, 101, 52];
                        } else if (status === 'In Progress') {
                            data.cell.styles.textColor = [133, 77, 14];
                        } else if (status === 'Pending') {
                            data.cell.styles.textColor = [71, 85, 105];
                        }
                    }
                }
            });

            doc.save('DPR_Report_' + new Date().toISOString().slice(0, 10) + '.pdf');
        }
    </script>

    <?php renderStudentNotificationScript($student_id); ?>
</body>
</html>