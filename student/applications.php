<?php
// student/applications.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

checkAccess('student');
ensureInternshipTables($pdo);

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Student';
$role = getUserRole();
$studentId = getUserId();

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

// Check if student has committed to a job
$committedJob = getStudentCommittedJob($pdo, $studentId);
$hasCommittedJob = (bool)$committedJob;

// Handle commit action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'commit_application') {
    $applicationId = (int)($_POST['application_id'] ?? 0);

    $appStmt = $pdo->prepare('
        SELECT a.id, a.status, a.student_id, j.title, c.company_name
        FROM job_applications a
        INNER JOIN jobs j ON a.job_id = j.id
        INNER JOIN companies c ON j.company_id = c.id
        WHERE a.id = ? AND a.student_id = ?
    ');
    $appStmt->execute([$applicationId, $studentId]);
    $application = $appStmt->fetch();

    if (!$application) {
        $_SESSION['error'] = 'Application record not found.';
    } elseif ($application['status'] === 'committed') {
        $_SESSION['success'] = 'This application is already committed.';
    } elseif ($application['status'] !== 'accepted') {
        $_SESSION['error'] = 'Only accepted applications can be committed.';
    } else {
        $existingCommittedStmt = $pdo->prepare('SELECT id FROM job_applications WHERE student_id = ? AND status = "committed" LIMIT 1');
        $existingCommittedStmt->execute([$studentId]);
        $existingCommitted = $existingCommittedStmt->fetch();

        if ($existingCommitted) {
            $_SESSION['error'] = 'You have already committed to one job and cannot commit to another.';
        } else {
            try {
                $pdo->beginTransaction();

                $updateStmt = $pdo->prepare('
                    UPDATE job_applications
                    SET status = "committed", committed_at = NOW(), updated_at = NOW()
                    WHERE id = ? AND student_id = ? AND status = "accepted"
                ');
                $updateStmt->execute([$applicationId, $studentId]);

                if ($updateStmt->rowCount() === 0) {
                    throw new Exception('Unable to commit this application.');
                }

                $supervisorId = findSupervisorForJob($pdo, $application['job_id'] ?? 0);
                if ($supervisorId) {
                    createSystemNotification(
                        $pdo,
                        $supervisorId,
                        $studentId,
                        'commitment',
                        'Student Committed to Job',
                        'A student committed to the job "' . $application['title'] . '" at ' . $application['company_name'] . '.',
                        'myintern.php'
                    );
                }

                $pdo->commit();
                $_SESSION['success'] = 'You have committed to "' . $application['title'] . '" at ' . $application['company_name'] . '.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['error'] = 'Commit failed: ' . $e->getMessage();
            }
        }
    }

    header('Location: applications.php');
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
}

// Fetch applications
$appStmt = $pdo->prepare('
    SELECT
        a.id AS application_id,
        a.status,
        a.application_date,
        a.committed_at,
        a.updated_at,
        j.title AS job_title,
        j.description,
        j.requirements,
        c.company_name,
        c.address AS company_address,
        u.firstname AS supervisor_firstname,
        u.middlename AS supervisor_middlename,
        u.lastname AS supervisor_lastname,
        u.suffix AS supervisor_suffix
    FROM job_applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN companies c ON j.company_id = c.id
    LEFT JOIN users u ON j.created_by = u.id
    WHERE a.student_id = ?
    ORDER BY a.application_date DESC
');
$appStmt->execute([$studentId]);
$myApplications = $appStmt->fetchAll();

$committedApplicationId = null;
$committedStmt = $pdo->prepare('SELECT id FROM job_applications WHERE student_id = ? AND status = "committed" LIMIT 1');
$committedStmt->execute([$studentId]);
$committedRow = $committedStmt->fetch();
if ($committedRow) {
    $committedApplicationId = (int)$committedRow['id'];
}

$applicationData = [];
foreach ($myApplications as $application) {
    $supervisorName = trim(($application['supervisor_firstname'] ?? '') . ' ' . ($application['supervisor_middlename'] ?? '') . ' ' . ($application['supervisor_lastname'] ?? '') . ' ' . ($application['supervisor_suffix'] ?? ''));
    
    // Students can only commit if:
    // 1. Application status is 'accepted'
    // 2. They haven't committed to ANY job yet
    // 3. OR this specific application is already committed (to show as committed)
    $isThisApplicationCommitted = ($application['status'] === 'committed');
    $canCommit = $application['status'] === 'accepted' && $committedApplicationId === null;
    
    // If this application is already committed, keep it as committable (for display purposes)
    if ($isThisApplicationCommitted) {
        $canCommit = true;
    }

    $applicationData[$application['application_id']] = [
        'job_title' => $application['job_title'],
        'company_name' => $application['company_name'],
        'company_address' => $application['company_address'],
        'supervisor_name' => $supervisorName !== '' ? $supervisorName : 'Not assigned',
        'description' => $application['description'] ?: 'No description provided.',
        'requirements' => $application['requirements'] ?: 'No requirements provided.',
        'status' => ucfirst($application['status']),
        'applied_at' => formatDate($application['application_date']),
        'committed_at' => formatDate($application['committed_at'] ?? null),
        'can_commit' => $canCommit,
        'status_raw' => $application['status'],
        'is_already_committed' => $isThisApplicationCommitted,
    ];
}

function getApplicationStatusBadgeClass($status)
{
    $map = [
        'pending' => 'badge-warning',
        'reviewed' => 'badge-info',
        'shortlisted' => 'badge-secondary',
        'accepted' => 'badge-success',
        'committed' => 'badge-success',
        'rejected' => 'badge-danger',
        'withdrawn' => 'badge-dark',
    ];

    return $map[$status] ?? 'badge-secondary';
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
    <title>My Applications</title>
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
            padding: 24px 28px 32px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            flex: 1;
            border-radius: 0;
        }

        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .page-head h2 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0f172a;
        }

        .page-head h2 i {
            color: #3b82f6;
        }

        .page-head p {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 2px;
        }

        .notice-pill {
            background: #dcfce7;
            color: #166534;
            padding: 8px 18px;
            border: 1px solid #86efac;
            font-weight: 600;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border-radius: 0;
            white-space: nowrap;
        }

        .notice-pill i {
            font-size: 1rem;
        }

        .table-header-reminder {
            background: #fef9c3;
            border: 1px solid #facc15;
            padding: 10px 16px;
            margin-bottom: 16px;
            border-radius: 0;
        }

        .reminder-text {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #854d0e;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .reminder-text i {
            font-size: 1rem;
            color: #eab308;
        }

        /* ---- Table (sharp) ---- */
        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
        }

        .app-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .app-table th {
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

        .app-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .app-table tbody tr:last-child td {
            border-bottom: none;
        }

        .app-table tbody tr:hover {
            background: #fafcff;
        }

        .app-table .muted {
            color: #94a3b8;
            font-size: 0.8rem;
            margin-top: 2px;
        }

        .status-badge {
            padding: 4px 14px;
            border-radius: 0;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            border: 1px solid transparent;
        }

        .badge-warning {
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .badge-info {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .badge-secondary {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .badge-dark {
            background: #e2e8f0;
            color: #1e293b;
            border-color: #94a3b8;
        }

        .btn-action {
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
        }

        .btn-view {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .btn-view:hover {
            background: #bfdbfe;
            transform: scale(1.02);
        }

        .btn-commit {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .btn-commit:hover:not(:disabled) {
            background: #bbf7d0;
            transform: scale(1.02);
        }

        .btn-commit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
        }

        .btn-commit:disabled:hover {
            transform: none;
        }

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

        .empty-state p {
            font-size: 1rem;
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
            max-width: 620px;
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

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        @media (max-width: 500px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        .detail-card {
            background: #f8fafc;
            padding: 14px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 0;
        }

        .detail-card label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 4px;
        }

        .detail-card .value {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.95rem;
        }

        .detail-block {
            margin-top: 16px;
            border-top: 1px solid #edf2f7;
            padding-top: 16px;
        }

        .detail-block h4 {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-block h4 i {
            color: #64748b;
        }

        .detail-text {
            background: #f8fafc;
            padding: 14px 18px;
            font-size: 0.9rem;
            line-height: 1.7;
            color: #1e293b;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: 1px solid #e2e8f0;
            border-radius: 0;
            max-height: 200px;
            overflow-y: auto;
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

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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

        /* ---- Responsive ---- */
        @media (max-width: 1024px) {
            .page-head {
                flex-direction: column;
            }
            .notice-pill {
                white-space: normal;
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

            .app-table th,
            .app-table td {
                padding: 10px 12px;
                font-size: 0.8rem;
            }

            .modal-container {
                padding: 24px 18px;
                max-height: 95vh;
                margin: 10px;
            }

            .btn-action {
                font-size: 0.65rem;
                padding: 4px 10px;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .page-head h2 {
                font-size: 1.1rem;
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

            .app-table th,
            .app-table td {
                padding: 8px 6px;
                font-size: 0.7rem;
            }

            .btn-action {
                font-size: 0.6rem;
                padding: 3px 6px;
            }

            .status-badge {
                font-size: 0.65rem;
                padding: 2px 8px;
            }

            .notice-pill {
                font-size: 0.75rem;
                padding: 6px 12px;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn-primary,
            .modal-footer .btn-secondary {
                width: 100%;
                justify-content: center;
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
                        <i class="fa-regular fa-calendar-check"></i>
                        My Applications
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Header Navigation -->
                    <nav class="header-nav">
                        <a class="nav-item-header" href="dashboard.php"></i> Dashboard</a>
                       
                        <?php if (!$hasCommittedJob): ?>
                            <a class="nav-item-header" href="apply.php"></i> Apply Job</a>
                        <?php endif; ?>
                         <a class="nav-item-header active" href="applications.php"></i> My Applications</a>
                        <?php if ($hasCommittedJob): ?>
                            <a class="nav-item-header" href="dpr.php"></i> Daily Progress Report</a>
                        <?php endif; ?>
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
                <div class="page-head">
                    <div>
                        <h2><i class="fa-regular fa-folder-open"></i> Submitted Applications</h2>
                        <p>Review your submitted applications, open the details modal, and commit to one accepted job only.</p>
                    </div>
                    <?php if ($committedApplicationId): ?>
                        <div class="notice-pill">
                            <i class="fa-solid fa-circle-check"></i>
                            You already committed to one job
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($myApplications) > 0): ?>
                    <div class="table-wrap">
                        <div class="table-header-reminder">
                            <div class="reminder-text">
                                <i class="fa-solid fa-lightbulb"></i>
                                Note: You can only commit if the supervisor accepts your application
                            </div>
                        </div>

                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    <th>Company</th>
                                    <th>Supervisor</th>
                                    <th>Status</th>
                                    <th>Applied</th>
                                    <th>Details</th>
                                    <th>Commit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myApplications as $application): ?>
                                    <?php $app = $applicationData[$application['application_id']]; ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($app['job_title']); ?></strong></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($app['company_name']); ?></strong>
                                            <div class="muted"><?php echo htmlspecialchars($app['company_address']); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($app['supervisor_name']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo htmlspecialchars(getApplicationStatusBadgeClass($application['status'])); ?>">
                                                <?php echo htmlspecialchars($app['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($app['applied_at']); ?></div>
                                            <?php if ($application['status'] === 'committed'): ?>
                                                <div class="muted">Committed on <?php echo htmlspecialchars($app['committed_at']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn-action btn-view"
                                                onclick="openApplicationModal(<?php echo (int)$application['application_id']; ?>)"
                                            >
                                                <i class="fa-solid fa-eye"></i> View
                                            </button>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn-action btn-commit"
                                                <?php echo $app['can_commit'] ? '' : 'disabled'; ?>
                                                onclick="openApplicationModal(<?php echo (int)$application['application_id']; ?>)"
                                            >
                                                <i class="fa-solid fa-circle-check"></i>
                                                <?php echo $application['status'] === 'committed' ? 'Committed' : 'Commit'; ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-folder-open"></i>
                        <p>No applications found yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- APPLICATION DETAIL MODAL -->
    <div class="modal-overlay" id="applicationModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fa-regular fa-file-lines"></i> Application Details</h3>
                <button type="button" class="modal-close-btn" onclick="closeApplicationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="detail-grid">
                    <div class="detail-card">
                        <label><i class="fa-regular fa-building"></i> Company</label>
                        <div class="value" id="modalCompany"></div>
                    </div>
                    <div class="detail-card">
                        <label><i class="fa-regular fa-user"></i> Supervisor</label>
                        <div class="value" id="modalSupervisor"></div>
                    </div>
                    <div class="detail-card">
                        <label><i class="fa-regular fa-flag"></i> Status</label>
                        <div class="value" id="modalStatus"></div>
                    </div>
                    <div class="detail-card">
                        <label><i class="fa-regular fa-calendar"></i> Applied / Committed</label>
                        <div class="value" id="modalAppliedAt"></div>
                    </div>
                </div>

                <div class="detail-block">
                    <h4><i class="fa-regular fa-align-left"></i> Description</h4>
                    <div class="detail-text" id="modalDescription"></div>
                </div>

                <div class="detail-block">
                    <h4><i class="fa-regular fa-list-check"></i> Requirements</h4>
                    <div class="detail-text" id="modalRequirements"></div>
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" action="applications.php" id="commitForm" style="display: inline;">
                    <input type="hidden" name="action" value="commit_application" />
                    <input type="hidden" name="application_id" id="commitApplicationId" value="" />
                    <button type="submit" class="btn-primary" id="commitButton">
                        <i class="fa-solid fa-circle-check"></i> Commit
                    </button>
                </form>
                <button type="button" class="btn-secondary" onclick="closeApplicationModal()">Close</button>
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
                        <button type="button" class="btn-secondary" id="closePasswordBtn2">Cancel</button>
                        <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== TOAST ===== -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <script>
        const applicationMap = <?php echo json_encode($applicationData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const modal = document.getElementById('applicationModal');
        const commitButton = document.getElementById('commitButton');
        const commitForm = document.getElementById('commitForm');
        const commitApplicationId = document.getElementById('commitApplicationId');

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

        // ===== APPLICATION MODAL =====
        function openApplicationModal(applicationId) {
            const data = applicationMap[applicationId];
            if (!data) {
                return;
            }

            document.getElementById('modalTitle').textContent = data.job_title;
            document.getElementById('modalCompany').textContent = data.company_name + ' | ' + data.company_address;
            document.getElementById('modalSupervisor').textContent = data.supervisor_name;
            document.getElementById('modalStatus').textContent = data.status;
            document.getElementById('modalAppliedAt').textContent = data.status_raw === 'committed' ? ('Committed on ' + data.committed_at) : data.applied_at;
            document.getElementById('modalDescription').textContent = data.description;
            document.getElementById('modalRequirements').textContent = data.requirements;
            commitApplicationId.value = applicationId;

            const alreadyCommittedElsewhere = <?php echo $committedApplicationId ? 'true' : 'false'; ?>;
            const modalStatus = data.status_raw;
            const isThisApplicationCommitted = data.is_already_committed || false;
            
            const canCommit = (modalStatus === 'accepted' && !alreadyCommittedElsewhere) || isThisApplicationCommitted;

            commitButton.disabled = !canCommit || isThisApplicationCommitted;
            commitButton.innerHTML = modalStatus === 'committed'
                ? '<i class="fa-solid fa-circle-check"></i> Committed'
                : '<i class="fa-solid fa-circle-check"></i> Commit';

            if (modalStatus === 'committed') {
                commitButton.title = 'You have already committed to this job.';
                commitButton.style.cursor = 'not-allowed';
                commitButton.style.opacity = '0.6';
            } else if (modalStatus === 'accepted' && alreadyCommittedElsewhere) {
                commitButton.title = 'You have already committed to another job and cannot commit to additional positions.';
                commitButton.style.cursor = 'not-allowed';
                commitButton.style.opacity = '0.6';
            } else if (modalStatus !== 'accepted') {
                commitButton.title = 'Only accepted applications can be committed.';
                commitButton.style.cursor = 'not-allowed';
                commitButton.style.opacity = '0.6';
            } else {
                commitButton.title = 'Click to commit to this internship position';
                commitButton.style.cursor = 'pointer';
                commitButton.style.opacity = '1';
            }

            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeApplicationModal() {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeApplicationModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeApplicationModal();
                closePasswordModal();
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
            }
        });
    </script>
</body>
</html>