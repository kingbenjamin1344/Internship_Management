<?php
// student/apply.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_notifications.php';
require_once __DIR__ . '/notification_component.php';

// Check if user is student
checkAccess('student');
ensureInternshipTables($pdo);

$username = $_SESSION['username'] ?? 'Student';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Student';
$studentId = getUserId();

// Get user role for display
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$studentId]);
    $role = $stmt->fetchColumn() ?: 'student';
} catch (PDOException $e) {
    $role = 'student';
}

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

// Check if student has committed to a job - use helper function
$committedJob = getStudentCommittedJob($pdo, $studentId);
$hasCommittedJob = (bool)$committedJob;

// Load notifications
$notifications = getStudentNotifications($pdo, $studentId, 10, 0);
$unreadCount = getStudentUnreadNotificationCount($pdo, $studentId);

// If student has committed to a job, redirect them with message
if ($hasCommittedJob) {
    $_SESSION['info'] = 'You have already committed to "' . $committedJob['job_title'] . '" at ' . $committedJob['company_name'] . '. You cannot apply to other positions while committed.';
    header('Location: applications.php');
    exit;
}

// Handle Job Application Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_job') {
    $jobId = (int)($_POST['job_id'] ?? 0);

    // 1. Check if already applied
    $checkStmt = $pdo->prepare('SELECT id FROM job_applications WHERE job_id = ? AND student_id = ?');
    $checkStmt->execute([$jobId, $studentId]);
    if ($checkStmt->fetch()) {
        $_SESSION['error'] = 'You have already applied for this internship position.';
        header('Location: apply.php');
        exit;
    }

    // 2. Verify job exists and has open slots
    $jobCheckStmt = $pdo->prepare('SELECT title, slots_available, slots_filled FROM jobs WHERE id = ?');
    $jobCheckStmt->execute([$jobId]);
    $jobObj = $jobCheckStmt->fetch();
    if (!$jobObj) {
        $_SESSION['error'] = 'Invalid job position.';
        header('Location: apply.php');
        exit;
    }

    $availableSlots = (int)$jobObj['slots_available'] - (int)$jobObj['slots_filled'];
    if ($availableSlots <= 0) {
        $_SESSION['error'] = 'Sorry, this internship position is already filled.';
        header('Location: apply.php');
        exit;
    }

    // 3. Validate file uploads
    $allowedExtensions = ['pdf', 'doc', 'docx'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB

    $uploadedFiles = [];
    $fileFields = ['cv' => 'CV', 'resume' => 'Resume', 'app_letter' => 'Application letter'];
    $errors = [];

    // Ensure uploads directory exists
    $uploadDir = __DIR__ . '/../uploads/applications/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    foreach ($fileFields as $field => $label) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = "$label file is required.";
            continue;
        }

        if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading $label file.";
            continue;
        }

        $fileSize = $_FILES[$field]['size'];
        $fileName = $_FILES[$field]['name'];
        $fileTmpPath = $_FILES[$field]['tmp_name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileSize > $maxFileSize) {
            $errors[] = "$label file size must be less than 5MB.";
            continue;
        }

        if (!in_array($ext, $allowedExtensions)) {
            $errors[] = "$label must be a PDF, DOC, or DOCX document.";
            continue;
        }

        // Generate unique filename to avoid overwrites
        $newFileName = $studentId . '_' . $jobId . '_' . $field . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $uploadedFiles[$field] = 'uploads/applications/' . $newFileName;
        } else {
            $errors[] = "Failed to save $label file locally.";
        }
    }

    // 4. Final Insert or Cleanup
    if (!empty($errors)) {
        // Clean up any files that were uploaded successfully during this turn
        foreach ($uploadedFiles as $filePath) {
            @unlink(__DIR__ . '/../' . $filePath);
        }
        $_SESSION['error'] = implode('<br>', $errors);
    } else {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO job_applications (job_id, student_id, cv_path, resume_path, application_letter_path, status)
                VALUES (?, ?, ?, ?, ?, "pending")
            ');
            $stmt->execute([
                $jobId,
                $studentId,
                $uploadedFiles['cv'],
                $uploadedFiles['resume'],
                $uploadedFiles['app_letter']
            ]);

            $supervisorId = findSupervisorForJob($pdo, $jobId);
            if ($supervisorId) {
                createSystemNotification(
                    $pdo,
                    $supervisorId,
                    $studentId,
                    'application',
                    'New Job Application',
                    'A student applied for the job "' . $jobObj['title'] . '".',
                    'applicant.php'
                );
            }

            $_SESSION['success'] = 'Application for "' . htmlspecialchars($jobObj['title']) . '" submitted successfully!';
        } catch (Exception $e) {
            // Clean up files
            foreach ($uploadedFiles as $filePath) {
                @unlink(__DIR__ . '/../' . $filePath);
            }
            $_SESSION['error'] = 'Application submission failed: ' . $e->getMessage();
        }
    }

    header('Location: apply.php');
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
            $stmt->execute([$studentId]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($currentPassword, $hash)) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
                exit;
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$newHash, $studentId]);

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
        $newFileName = 'user_' . $studentId . '_' . time() . '.' . strtolower($ext);
        $destination = $avatarUploadDir . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt->execute([$newFileName, $studentId]);

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
    
    // Notification actions
    if ($_POST['action'] === 'get_notifications') {
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        $notifs = getStudentNotifications($pdo, $studentId, $limit, $offset);
        $unread = getStudentUnreadNotificationCount($pdo, $studentId);
        echo json_encode(['success' => true, 'notifications' => $notifs, 'unread_count' => $unread]);
        exit;
    }
    
    if ($_POST['action'] === 'mark_read') {
        $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        $result = markStudentNotificationRead($pdo, $notificationId, $studentId);
        $unread = getStudentUnreadNotificationCount($pdo, $studentId);
        echo json_encode(['success' => $result, 'unread_count' => $unread]);
        exit;
    }
    
    if ($_POST['action'] === 'mark_all_read') {
        $result = markStudentAllNotificationsRead($pdo, $studentId);
        $unread = getStudentUnreadNotificationCount($pdo, $studentId);
        echo json_encode(['success' => $result, 'unread_count' => $unread]);
        exit;
    }
}

// Fetch all jobs in system
$jobsStmt = $pdo->prepare('
    SELECT j.*, c.company_name, c.industry,
           u.firstname AS supervisor_firstname, u.lastname AS supervisor_lastname
    FROM jobs j
    INNER JOIN companies c ON j.company_id = c.id
    LEFT JOIN users u ON j.created_by = u.id
    ORDER BY j.created_at DESC
');
$jobsStmt->execute();
$jobs = $jobsStmt->fetchAll();

// Fetch student\'s applications to bind statuses and allow management
$appStmt = $pdo->prepare('
    SELECT a.*, j.title, c.company_name
    FROM job_applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN companies c ON j.company_id = c.id
    WHERE a.student_id = ?
    ORDER BY a.application_date DESC
');
$appStmt->execute([$studentId]);
$myApplications = $appStmt->fetchAll();

// Index applications by job_id for quick status checking in listings table
$appliedJobs = [];
foreach ($myApplications as $app) {
    $appliedJobs[$app['job_id']] = $app;
}

// Current profile picture
$profilePicture = getUserProfilePicture($pdo, $studentId);
$profilePictureUrl = $profilePicture ? $avatarPublicPath . $profilePicture : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Internship Roles</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <?php renderStudentNotificationCSS(); ?>
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
            padding: 24px 28px 32px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            flex: 1;
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

        .page-card .sub {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 2px;
        }

        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
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

        /* ---- Table (sharp) ---- */
        .table-container {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
        }

        .job-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

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

        .job-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .job-table tbody tr:last-child td {
            border-bottom: none;
        }

        .job-table tbody tr:hover {
            background: #fafcff;
        }

        /* ---- Buttons ---- */
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

        .btn-apply {
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
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .btn-apply:hover:not(:disabled) {
            background: #bbf7d0;
            transform: scale(1.02);
        }

        .btn-apply:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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

        .btn-prim-blue:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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

        /* ---- Status Badges ---- */
        .badge-status {
            padding: 4px 14px;
            border-radius: 0;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid transparent;
        }

        .badge-pending {
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .badge-reviewed {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .badge-shortlisted {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }

        .badge-accepted {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .badge-committed {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .badge-withdrawn {
            background: #e2e8f0;
            color: #1e293b;
            border-color: #94a3b8;
        }

        /* ---- Document Links ---- */
        .doc-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            font-size: 0.75rem;
            font-weight: 500;
            color: #2563eb;
            cursor: pointer;
            transition: 0.15s;
            border-radius: 0;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .doc-link:hover {
            background: #dbeafe;
            border-color: #93c5fd;
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
            max-width: 640px;
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
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            border-top: 1px solid #edf2f7;
            padding-top: 22px;
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

        .form-group label i {
            margin-right: 6px;
            color: #64748b;
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

        .file-upload-block {
            margin-bottom: 20px;
        }

        .file-upload-block label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .file-upload-block .hint {
            display: block;
            font-size: 0.75rem;
            color: #94a3b8;
            margin-bottom: 6px;
        }

        .file-upload-block input[type="file"] {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.9rem;
            background: #fafcff;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .file-upload-block input[type="file"]:focus {
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

        /* ---- PDF Viewer ---- */
        .pdf-viewer-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .pdf-viewer-overlay.active {
            display: flex;
        }

        .pdf-viewer-container {
            background: #fff;
            border: 1px solid #e2e8f0;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.3);
            animation: slideUp 0.25s ease;
            border-radius: 0;
        }

        .pdf-viewer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid #edf2f7;
        }

        .pdf-viewer-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pdf-viewer-header h3 i {
            color: #dc2626;
        }

        .pdf-viewer-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 8px;
            transition: 0.15s;
            line-height: 1;
        }

        .pdf-viewer-close:hover {
            color: #1e293b;
        }

        .pdf-viewer-body {
            flex: 1;
            padding: 16px;
            min-height: 500px;
            background: #f8fafc;
        }

        .pdf-viewer-body iframe {
            width: 100%;
            height: 100%;
            min-height: 500px;
            border: 1px solid #e2e8f0;
            background: #fff;
        }

        .pdf-viewer-footer {
            display: flex;
            justify-content: flex-end;
            padding: 16px 24px;
            border-top: 1px solid #edf2f7;
            gap: 12px;
        }

        .btn-download {
            background: #0f172a;
            border: 1px solid #0f172a;
            color: #fff;
            padding: 8px 20px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-download:hover {
            background: #1e293b;
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
            .page-head {
                flex-direction: column;
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
                padding: 16px;
            }

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
            .btn-apply {
                font-size: 0.65rem;
                padding: 4px 10px;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .pdf-viewer-body {
                min-height: 300px;
            }

            .pdf-viewer-body iframe {
                min-height: 300px;
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

            .job-table th,
            .job-table td {
                padding: 8px 6px;
                font-size: 0.7rem;
            }

            .btn-view,
            .btn-apply {
                font-size: 0.6rem;
                padding: 3px 6px;
            }

            .badge-status {
                font-size: 0.65rem;
                padding: 2px 8px;
            }

            .doc-link {
                font-size: 0.65rem;
                padding: 2px 8px;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn-prim-blue,
            .modal-footer .btn-sec-outline {
                width: 100%;
                justify-content: center;
            }

            .pdf-viewer-container {
                width: 95%;
            }

            .pdf-viewer-body {
                min-height: 200px;
                padding: 8px;
            }

            .pdf-viewer-body iframe {
                min-height: 200px;
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
                        Internship Opportunities
                        <small>Student</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Header Navigation -->
                    <nav class="header-nav">
                        <a class="nav-item-header" href="dashboard.php"></i> Dashboard</a>
                        <?php if (!$hasCommittedJob): ?>
                            <a class="nav-item-header active" href="apply.php"></i> Apply Job</a>
                        <?php endif; ?>
                        <a class="nav-item-header" href="applications.php"></i> My Applications</a>
                        <?php if ($hasCommittedJob): ?>
                            <a class="nav-item-header" href="dpr.php"></i> Daily Progress Report</a>
                        <?php endif; ?>
                    </nav>

                    <!-- Notification bell -->
                    <?php renderStudentNotificationBell($unreadCount, $notifications); ?>
                </div>
            </div>

            <!-- Available Jobs Card -->
            <div class="page-card">
                <div class="page-head">
                    <div>
                        <h2><i class="fa-regular fa-briefcase"></i> Available Positions</h2>
                        <p class="sub">Explore and apply for internship opportunities created by supervisors.</p>
                    </div>
                    <div style="font-size: 0.85rem; font-weight: 600; color: #475569; padding: 8px 16px; background: #f8fafc; border: 1px solid #e2e8f0;">
                        Total Positions: <span style="color:#2563eb; font-weight: 700;"><?php echo count($jobs); ?></span>
                    </div>
                </div>

                <!-- Instant Filter/Search Box -->
                <div class="search-row">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="jobSearchInput" onkeyup="filterJobsTable()" placeholder="Search by title, company, or industry..." />
                    </div>
                </div>

                <?php if (count($jobs) > 0): ?>
                    <div class="table-container">
                        <table class="job-table" id="jobsTable">
                            <thead>
                                <tr>
                                    <th>Job Title</th>
                                    <th>Company</th>
                                    <th>Industry</th>
                                    <th>Supervisor</th>
                                    <th>Vacancy Slots</th>
                                    <th>Duration</th>
                                    <th style="width: 180px; text-align: center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobs as $job): ?>
                                    <?php 
                                    $jobId = (int)$job['id'];
                                    $hasApplied = isset($appliedJobs[$jobId]);
                                    $supervisor = trim(($job['supervisor_firstname'] ?? '') . ' ' . ($job['supervisor_lastname'] ?? ''));
                                    if (empty($supervisor)) {
                                        $supervisor = 'N/A';
                                    }
                                    
                                    $slotsTotal = (int)$job['slots_available'];
                                    $slotsFilled = (int)$job['slots_filled'];
                                    $slotsLeft = $slotsTotal - $slotsFilled;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong style="color: #0f172a; font-size: 0.95rem;"><?php echo htmlspecialchars($job['title']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($job['company_name']); ?></td>
                                        <td>
                                            <span style="font-size:0.75rem; background:#f1f5f9; padding:2px 8px; border:1px solid #e2e8f0; font-weight: 600; color:#475569;">
                                                <?php echo htmlspecialchars($job['industry']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="fa-regular fa-user" style="color: #94a3b8; margin-right: 4px; font-size: 0.8rem;"></i>
                                            <?php echo htmlspecialchars($supervisor); ?>
                                        </td>
                                        <td>
                                            <?php if ($slotsLeft > 0): ?>
                                                <span style="color: #16a34a; font-weight: 700;">
                                                    <?php echo $slotsLeft; ?> / <?php echo $slotsTotal; ?> slots
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #dc2626; font-weight: 700;">
                                                    Filled
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight: 500;">
                                                <?php echo $job['duration_hours'] > 0 ? (int)$job['duration_hours'] . ' hours' : 'N/A'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; justify-content: center;">
                                                <button class="btn-view" type="button" onclick="openViewModal(<?php echo $jobId; ?>)">
                                                    <i class="fa-solid fa-eye"></i> View
                                                </button>
                                                
                                                <?php if ($hasCommittedJob): ?>
                                                    <button class="btn-apply" style="background:#e2e8f0; color:#64748b; border-color:#cbd5e1;" disabled title="You have already committed to a job">
                                                        <i class="fa-solid fa-lock"></i> 
                                                    </button>
                                                <?php elseif ($hasApplied): ?>
                                                    <button class="btn-apply" style="background:#f1f5f9; color:#94a3b8; border-color:#e2e8f0;" disabled>
                                                        <i class="fa-solid fa-check"></i> Applied
                                                    </button>
                                                <?php elseif ($slotsLeft <= 0): ?>
                                                    <button class="btn-apply" style="background:#fee2e2; color:#ef4444; border-color:#fca5a5;" disabled title="Job is filled">
                                                        <i class="fa-solid fa-ban"></i> Filled
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-apply" type="button" onclick="openApplyModal(<?php echo $jobId; ?>, '<?php echo htmlspecialchars(addslashes($job['title'])); ?>')">
                                                        <i class="fa-solid fa-paper-plane"></i> Apply
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-briefcase"></i>
                        <h3>No Positions Posted Yet</h3>
                        <p>Supervisors haven't posted any internship opportunities yet. Please check back later.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Student Applications History Section -->
            <?php if (count($myApplications) > 0): ?>
                <div class="page-card">
                    <div class="page-head">
                        <div>
                            <h2><i class="fa-regular fa-file-lines"></i> Your Submitted Applications</h2>
                            <p class="sub">Keep track of internship applications you've submitted and check their current status.</p>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="job-table">
                            <thead>
                                <tr>
                                    <th>Applied Position</th>
                                    <th>Company</th>
                                    <th>Submission Date</th>
                                    <th>Status</th>
                                    <th style="padding-left: 20px;">Uploaded Documents</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myApplications as $app): ?>
                                    <tr>
                                        <td>
                                            <strong style="color: #0f172a;"><?php echo htmlspecialchars($app['title']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($app['company_name']); ?></td>
                                        <td>
                                            <i class="fa-regular fa-clock" style="color: #94a3b8; margin-right: 4px; font-size: 0.8rem;"></i>
                                            <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($app['application_date']))); ?>
                                        </td>
                                        <td>
                                            <span class="badge-status badge-<?php echo htmlspecialchars($app['status']); ?>">
                                                <i class="fa-solid fa-circle" style="font-size:0.5rem; margin-right:2px;"></i>
                                                <?php echo htmlspecialchars(ucfirst($app['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <?php if (!empty($app['cv_path'])): ?>
                                                    <span class="doc-link" onclick="viewPDF('<?php echo htmlspecialchars($app['cv_path']); ?>', 'CV')">
                                                        <i class="fa-solid fa-file-pdf"></i> CV
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!empty($app['resume_path'])): ?>
                                                    <span class="doc-link" onclick="viewPDF('<?php echo htmlspecialchars($app['resume_path']); ?>', 'Resume')">
                                                        <i class="fa-solid fa-file-pdf"></i> Resume
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!empty($app['application_letter_path'])): ?>
                                                    <span class="doc-link" onclick="viewPDF('<?php echo htmlspecialchars($app['application_letter_path']); ?>', 'Application Letter')">
                                                        <i class="fa-solid fa-file-pdf"></i> App Letter
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- VIEW DETAILS MODAL -->
    <div id="viewModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-circle-info" style="color: #4338ca;"></i> Position Details</h3>
                <button type="button" class="modal-close-btn" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="details-grid">
                    <div class="detail-card">
                        <div class="lbl">Job Title</div>
                        <div id="v_title" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Company</div>
                        <div id="v_company" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Industry</div>
                        <div id="v_industry" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Supervisor</div>
                        <div id="v_supervisor" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Target Duration</div>
                        <div id="v_duration" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Available Placements</div>
                        <div id="v_slots" class="val">-</div>
                    </div>
                </div>

                <div class="content-section">
                    <h4><i class="fa-regular fa-align-left"></i> Description</h4>
                    <div id="v_description" class="content-body">No description entered.</div>
                </div>

                <div class="content-section">
                    <h4><i class="fa-regular fa-list-check"></i> Responsibilities</h4>
                    <div id="v_responsibility" class="content-body">No responsibilities entered.</div>
                </div>

                <div class="content-section">
                    <h4><i class="fa-regular fa-circle-check"></i> Requirements</h4>
                    <div id="v_requirements" class="content-body">No requirements entered.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-sec-outline" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- APPLY SUBMISSION MODAL -->
    <div id="applyModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-paper-plane" style="color: #2563eb;"></i> Apply for Position</h3>
                <button type="button" class="modal-close-btn" onclick="closeApplyModal()">&times;</button>
            </div>
            
            <form method="POST" action="apply.php" enctype="multipart/form-data" id="applyJobForm">
                <input type="hidden" name="action" value="apply_job">
                <input type="hidden" name="job_id" id="apply_job_id">

                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fa-regular fa-briefcase"></i> Applying to Position:</label>
                        <input type="text" id="apply_job_title_display" class="form-control" readonly>
                    </div>

                    <!-- CV Upload -->
                    <div class="file-upload-block">
                        <label for="cv_file">Curriculum Vitae (CV) <span style="color: #dc2626;">*</span></label>
                        <span class="hint">Upload your latest CV. Accepted formats: PDF, DOC, DOCX (Max size: 5MB)</span>
                        <input type="file" name="cv" id="cv_file" required accept=".pdf,.doc,.docx">
                    </div>

                    <!-- Resume Upload -->
                    <div class="file-upload-block">
                        <label for="resume_file">Resume <span style="color: #dc2626;">*</span></label>
                        <span class="hint">Upload your official resume. Accepted formats: PDF, DOC, DOCX (Max size: 5MB)</span>
                        <input type="file" name="resume" id="resume_file" required accept=".pdf,.doc,.docx">
                    </div>

                    <!-- Application Letter Upload -->
                    <div class="file-upload-block">
                        <label for="letter_file">Application Letter <span style="color: #dc2626;">*</span></label>
                        <span class="hint">Upload your cover / application letter. Accepted formats: PDF, DOC, DOCX (Max size: 5MB)</span>
                        <input type="file" name="app_letter" id="letter_file" required accept=".pdf,.doc,.docx">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-sec-outline" onclick="closeApplyModal()">Cancel</button>
                    <button type="submit" class="btn-prim-blue" id="submitApplicationBtn" <?php echo $hasCommittedJob ? 'disabled title="You have already committed to a job"' : ''; ?>>
                        <i class="fa-solid fa-circle-check"></i> Submit Application
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-key"></i> Change Password</h3>
                <button type="button" class="modal-close-btn" id="closePasswordBtn">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color: #64748b; margin-bottom: 20px;">Enter your current password and choose a new one.</p>
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
                        <button type="button" class="btn-sec-outline" id="closePasswordBtn2">Cancel</button>
                        <button type="submit" class="btn-prim-blue"><i class="fa-solid fa-check"></i> Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- PDF VIEWER MODAL -->
    <div class="pdf-viewer-overlay" id="pdfViewer">
        <div class="pdf-viewer-container">
            <div class="pdf-viewer-header">
                <h3><i class="fa-solid fa-file-pdf"></i> <span id="pdfViewerTitle">Document</span></h3>
                <button type="button" class="pdf-viewer-close" onclick="closePDFViewer()">&times;</button>
            </div>
            <div class="pdf-viewer-body">
                <iframe id="pdfViewerFrame" src=""></iframe>
            </div>
            <div class="pdf-viewer-footer">
                <button class="btn-download" onclick="downloadPDF()">
                    <i class="fa-solid fa-download"></i> Download
                </button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <script>
        // Inject jobs JSON so we don't do unnecessary AJAX calls
        const jobsList = <?php echo json_encode($jobs); ?>;
        let currentPdfPath = '';
        let currentPdfName = '';

        // ===== TOAST =====
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            toast.className = 'toast ' + type + ' show';
            toastMessage.textContent = message;
            
            clearTimeout(toast._timeout);
            toast._timeout = setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

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

        // ===== PDF VIEWER =====
        function viewPDF(filePath, fileName) {
            currentPdfPath = filePath;
            currentPdfName = fileName || 'Document';
            
            // Construct the full URL to the PDF
            const baseUrl = window.location.origin + window.location.pathname.replace('/student/apply.php', '');
            const pdfUrl = baseUrl + '/' + filePath;
            
            document.getElementById('pdfViewerTitle').textContent = fileName || 'Document';
            document.getElementById('pdfViewerFrame').src = pdfUrl;
            document.getElementById('pdfViewer').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePDFViewer() {
            document.getElementById('pdfViewer').classList.remove('active');
            document.getElementById('pdfViewerFrame').src = '';
            document.body.style.overflow = '';
            currentPdfPath = '';
            currentPdfName = '';
        }

        function downloadPDF() {
            if (currentPdfPath) {
                const link = document.createElement('a');
                link.href = currentPdfPath;
                link.download = currentPdfName + '.pdf';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // Close PDF viewer on overlay click
        document.getElementById('pdfViewer').addEventListener('click', function(e) {
            if (e.target === this) {
                closePDFViewer();
            }
        });

        // ===== MODAL FUNCTIONS =====
        function openViewModal(jobId) {
            const job = jobsList.find(j => parseInt(j.id) === parseInt(jobId));
            if (!job) return;

            document.getElementById('v_title').innerText = job.title || 'N/A';
            document.getElementById('v_company').innerText = job.company_name || 'N/A';
            document.getElementById('v_industry').innerText = job.industry || 'N/A';
            
            const supervisorName = ((job.supervisor_firstname || '') + ' ' + (job.supervisor_lastname || '')).trim();
            document.getElementById('v_supervisor').innerText = supervisorName || 'N/A';
            
            document.getElementById('v_duration').innerText = job.duration_hours > 0 ? (job.duration_hours + ' hours') : 'N/A';
            
            const leftSlots = parseInt(job.slots_available) - parseInt(job.slots_filled);
            document.getElementById('v_slots').innerText = `${leftSlots} available (out of ${job.slots_available})`;

            document.getElementById('v_description').innerText = job.description || 'No description entered.';
            document.getElementById('v_responsibility').innerText = job.responsibility || 'No responsibilities entered.';
            document.getElementById('v_requirements').innerText = job.requirements || 'No requirements entered.';

            document.getElementById('viewModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function openApplyModal(jobId, jobTitle) {
            <?php if ($hasCommittedJob): ?>
            return;
            <?php endif; ?>
            document.getElementById('apply_job_id').value = jobId;
            document.getElementById('apply_job_title_display').value = jobTitle;
            document.getElementById('applyModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeApplyModal() {
            document.getElementById('applyModal').style.display = 'none';
            document.body.style.overflow = '';
            // Clear file inputs on close
            document.getElementById('cv_file').value = '';
            document.getElementById('resume_file').value = '';
            document.getElementById('letter_file').value = '';
        }

        // Close on overlay click
        window.onclick = function(event) {
            const viewModal = document.getElementById('viewModal');
            const applyModal = document.getElementById('applyModal');
            if (event.target === viewModal) {
                closeViewModal();
            }
            if (event.target === applyModal) {
                closeApplyModal();
            }
        };

        // Escape key to close modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (document.getElementById('viewModal').style.display === 'flex') {
                    closeViewModal();
                }
                if (document.getElementById('applyModal').style.display === 'flex') {
                    closeApplyModal();
                }
                if (document.getElementById('pdfViewer').classList.contains('active')) {
                    closePDFViewer();
                }
                closePasswordModal();
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
            }
        });

        // Table Instant Search Filtering logic
        function filterJobsTable() {
            const input = document.getElementById('jobSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('jobsTable');
            if (!table) return;
            
            const trs = table.getElementsByTagName('tr');

            for (let i = 1; i < trs.length; i++) {
                const tr = trs[i];
                let display = false;
                
                // Read columns: job title, company name, industry
                const titleCell = tr.cells[0];
                const companyCell = tr.cells[1];
                const industryCell = tr.cells[2];

                if (titleCell || companyCell || industryCell) {
                    const text = (titleCell.textContent + ' ' + companyCell.textContent + ' ' + industryCell.textContent).toLowerCase();
                    if (text.indexOf(filter) > -1) {
                        display = true;
                    }
                }
                tr.style.display = display ? '' : 'none';
            }
        }

        // ===== PREVENT DOUBLE SUBMISSION =====
        const applyJobForm = document.getElementById('applyJobForm');
        const submitApplicationBtn = document.getElementById('submitApplicationBtn');
        let isSubmitting = false;

        if (applyJobForm && submitApplicationBtn) {
            applyJobForm.addEventListener('submit', function(e) {
                // Prevent double submission
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }

                // Mark as submitting
                isSubmitting = true;
                submitApplicationBtn.disabled = true;
                submitApplicationBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
            });
        }
    </script>

    <?php renderStudentNotificationScript($studentId); ?>
</body>
</html>