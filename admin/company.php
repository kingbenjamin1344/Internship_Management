<?php
// admin/company.php (Companies & Jobs with Pagination)
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_notifications.php';

// Check if user is admin
checkAccess('admin');

// Handle notification AJAX requests
require_once __DIR__ . '/../includes/admin_notification_handler.php';
handleAdminNotificationRequests($pdo, getUserId());

ensureInternshipTables($pdo);

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin';
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

function getTableCountSafe($pdo, $tableName, $where = '') {
    try {
        $sql = 'SELECT COUNT(*) AS total FROM ' . $tableName;
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $stmt = $pdo->query($sql);
        $row = $stmt->fetch();
        return (int)($row['total'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

// ===== TAB AND PAGINATION SETUP =====
$activeTab = isset($_GET['tab']) && in_array($_GET['tab'], ['companies', 'jobs']) ? $_GET['tab'] : 'companies';

// --- Companies Pagination ---
$currentPageC = isset($_GET['page_c']) ? (int)$_GET['page_c'] : 1;
if ($currentPageC < 1) $currentPageC = 1;
$limitC = 10;
$offsetC = ($currentPageC - 1) * $limitC;
$totalCompanies = getTableCountSafe($pdo, 'companies');
$totalPagesC = ceil($totalCompanies / $limitC);

// Fetch companies for current page
$companies = [];
if ($totalCompanies > 0) {
    $queryC = "
        SELECT 
            c.id,
            c.company_name as name,
            c.address,
            c.industry,
            c.contact_person,
            c.contact_email,
            c.contact_number,
            c.created_at,
            u.username as supervisor_username,
            u.email as supervisor_email,
            CONCAT(u.firstname, ' ', u.lastname) as supervisor_name,
            COUNT(DISTINCT j.id) as job_count,
            COUNT(DISTINCT ja.id) as application_count
        FROM companies c
        LEFT JOIN users u ON c.supervisor_id = u.id
        LEFT JOIN jobs j ON c.id = j.company_id
        LEFT JOIN job_applications ja ON j.id = ja.job_id
        GROUP BY c.id
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmtC = $pdo->prepare($queryC);
    $stmtC->bindValue(1, $limitC, PDO::PARAM_INT);
    $stmtC->bindValue(2, $offsetC, PDO::PARAM_INT);
    $stmtC->execute();
    $companies = $stmtC->fetchAll();
}

// --- Jobs Pagination ---
$currentPageJ = isset($_GET['page_j']) ? (int)$_GET['page_j'] : 1;
if ($currentPageJ < 1) $currentPageJ = 1;
$limitJ = 10;
$offsetJ = ($currentPageJ - 1) * $limitJ;
$totalJobs = getTableCountSafe($pdo, 'jobs');
$totalPagesJ = ceil($totalJobs / $limitJ);

// Fetch jobs for current page
$jobs = [];
if ($totalJobs > 0) {
    $queryJ = "
        SELECT 
            j.*,
            c.company_name,
            c.id as company_id,
            COUNT(DISTINCT ja.id) as application_count
        FROM jobs j
        LEFT JOIN companies c ON j.company_id = c.id
        LEFT JOIN job_applications ja ON j.id = ja.job_id
        GROUP BY j.id
        ORDER BY j.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmtJ = $pdo->prepare($queryJ);
    $stmtJ->bindValue(1, $limitJ, PDO::PARAM_INT);
    $stmtJ->bindValue(2, $offsetJ, PDO::PARAM_INT);
    $stmtJ->execute();
    $jobs = $stmtJ->fetchAll();
}

// Add computed status to each job
foreach ($jobs as &$job) {
    if ($job['slots_filled'] >= $job['slots_available']) {
        $job['status'] = 'filled';
    } else {
        $job['status'] = 'active';
    }
}

// Get messages
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// Current profile picture
$profilePicture = getUserProfilePicture($pdo, $userId);
$profilePictureUrl = $profilePicture ? $avatarPublicPath . $profilePicture : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Companies & Jobs - Admin</title>
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

        /* ---- Dark Green Sidebar (nav only) ---- */
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
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
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

        /* ---- Navigation ---- */
        .nav-section {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 0;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.15s;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }

        .nav-item:hover {
            background: rgba(255, 204, 51, 0.15);
            color: #fff;
        }

        .nav-item.active {
            background: #FFCC33;
            color: #003300;
            font-weight: 600;
        }

        .nav-item.active i {
            color: #003300;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid rgba(255, 204, 51, 0.3);
            padding-top: 18px;
        }

        .logout-btn-side {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 0;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: 0.15s;
        }

        .logout-btn-side i {
            width: 20px;
            text-align: center;
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

        /* ---- Header right ---- */
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
            justify-content: flex-end;
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

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: default;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 0;
            background: #FFCC33;
            color: #003300;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            text-transform: uppercase;
            border: 2px solid #FFCC33;
        }

        .user-info .name {
            font-weight: 600;
            color: #FFCC33;
            font-size: 0.9rem;
        }

        .user-info .role-label {
            font-size: 0.7rem;
            color: #cbd5e1;
            font-weight: 500;
        }

        /* ---- Page content (compressed) ---- */
        .container {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .page-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 20px 24px 28px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            border-radius: 0;
        }

        .page-card .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .page-card .section-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-card .section-header h2 i {
            color: #3b82f6;
        }

        .page-card .section-header .badge-count {
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

        /* ---- Tables (compressed) ---- */
        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
            min-height: 320px; /* Added min-height */
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .data-table th {
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

        .data-table td {
            padding: 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: #fafcff;
        }

        .company-name, .job-title {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.85rem;
        }

        .company-info, .job-info {
            font-size: 0.72rem;
            color: #64748b;
            margin-top: 2px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 0;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-badge.open {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .status-badge.closed {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .status-badge.active {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .btn-view {
            background: #eef2ff;
            color: #4338ca;
            border: 1px solid #a5b4fc;
            padding: 5px 12px;
            border-radius: 0;
            font-size: 0.72rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }

        .btn-view:hover {
            background: #c7d2fe;
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

        /* ===== PAGINATION (bottom right) ===== */
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

        /* ---- Detail Sidebar ---- */
        .detail-sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .detail-sidebar-overlay.open {
            display: block;
        }

        #detailSidebar {
            position: fixed;
            top: 0;
            right: -450px;
            width: 450px;
            height: 100vh;
            background: #fff;
            border-left: 1px solid #e2e8f0;
            padding: 24px;
            z-index: 1001;
            transition: right 0.3s ease;
            overflow-y: auto;
            box-shadow: -10px 0 30px rgba(0,0,0,0.05);
        }

        #detailSidebar.open {
            right: 0;
        }

        #detailSidebar .modal-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 8px;
            transition: 0.15s;
            line-height: 1;
        }

        #detailSidebar .modal-close:hover {
            color: #1e293b;
        }

        #detailSidebar h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
        }

        #detailSidebar hr {
            border: none;
            border-top: 1px solid #f1f5f9;
            margin: 16px 0;
        }

        #detailSidebar .detail-section {
            margin-bottom: 20px;
        }

        #detailSidebar .detail-section h3 {
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        #detailSidebar .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 0.85rem;
            border-bottom: 1px solid #f8fafc;
        }

        #detailSidebar .detail-item .label {
            color: #64748b;
            font-weight: 500;
        }

        #detailSidebar .detail-item .value {
            color: #0f172a;
            font-weight: 500;
            text-align: right;
            word-break: break-word;
            max-width: 60%;
        }

        #detailSidebar .detail-text {
            color: #475569;
            font-size: 0.85rem;
            line-height: 1.6;
            margin-bottom: 12px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 20px;
        }

        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: #64748b;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            margin-bottom: -2px;
            text-decoration: none;
            display: inline-block;
        }

        .tab-btn:hover {
            color: #0f172a;
            background: #f8fafc;
        }

        .tab-btn.active {
            color: #0f172a;
            border-bottom-color: #FFCC33;
            font-weight: 600;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
            padding: 28px 26px 24px;
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
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid #edf2f7;
        }

        .modal-header h3 {
            font-size: 1.2rem;
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
            font-size: 1.6rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 6px;
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
            margin-bottom: 16px;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 14px;
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

        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.9rem;
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
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            border-top: 1px solid #edf2f7;
            padding-top: 16px;
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

            .notif-bell {
                align-self: center;
            }

            .user-profile {
                justify-content: center;
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
                padding: 20px 16px;
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

            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }

            .user-info .name {
                font-size: 0.75rem;
            }

            .user-info .role-label {
                font-size: 0.6rem;
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

        <!-- SIDEBAR: Navigation only -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-shield-alt"></i>
                <h2>Admin<span>Panel</span></h2>
            </div>
            <nav class="nav-section">
                 <a class="nav-item " href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item " href="user_management.php"><i class="fa-solid fa-users-gear"></i> User Management</a>
                <a class="nav-item" href="pending.php"><i class="fa-solid fa-clock-rotate-left"></i> Pending</a>
                <a class="nav-item active" href="company.php"><i class="fa-solid fa-building"></i> Company</a>
            </nav>
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
                        Companies & Jobs
                        <small>Admin</small>
                    </h1>
                </div>
                <div class="header-right">
                     <div class="user-profile">
                        <div class="user-avatar">
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
                        <div class="user-info">
                            <div class="name"><?php echo htmlspecialchars($fullname); ?></div>
                            <div class="role-label"><?php echo htmlspecialchars(getRoleDisplayName($role)); ?></div>
                        </div>
                    </div>
                    <!-- Notification bell -->
                    <?php require_once __DIR__ . '/notification_component.php'; renderAdminNotificationBell($userId); ?>

                    <!-- User Profile -->
                   
                </div>
            </div>

            <!-- PAGE CONTENT -->
            <div class="container">

               

                <!-- Companies Table -->
                <div id="companies-tab" class="tab-content <?php echo $activeTab === 'companies' ? 'active' : ''; ?>">
                    <div class="page-card">
                        <div class="section-header">
                            <h2><i class="fa-solid fa-building"></i> All Companies <span class="badge-count"><?php echo $totalCompanies; ?></span></h2>
                        </div>
                         <!-- Tabs -->
                <div class="tabs">
                    <a href="?tab=companies&page_c=1" class="tab-btn <?php echo $activeTab === 'companies' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-building"></i> Companies
                    </a>
                    <a href="?tab=jobs&page_j=1" class="tab-btn <?php echo $activeTab === 'jobs' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-briefcase"></i> Jobs
                    </a>
                </div>

                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                      
                                        <th>Company Name</th>
                              
                                        <th>Industry</th>
                                        <th>Contact Person</th>
                                        <th>Supervisor</th>
                                        <th>Jobs</th>
                                        <th>Applications</th>
                                        <th>Created</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($companies)): ?>
                                        <tr>
                                            <td colspan="10" class="empty-state">
                                                <i class="fa-solid fa-building"></i>
                                                <p>No companies found.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($companies as $company): ?>
                                            <tr>
                                                
                                                <td>
                                                    <div class="company-name"><?php echo htmlspecialchars($company['name']); ?></div>
                                                    <div class="company-info"><?php echo htmlspecialchars($company['contact_email'] ?? ''); ?></div>
                                                </td>
                                              
                                                <td><?php echo htmlspecialchars($company['industry'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <div style="font-size: 0.8rem; font-weight: 500;"><?php echo htmlspecialchars($company['contact_person'] ?? 'N/A'); ?></div>
                                                    <div class="company-info"><?php echo htmlspecialchars($company['contact_number'] ?? ''); ?></div>
                                                </td>
                                                <td>
                                                    <div style="font-size: 0.8rem; font-weight: 500;"><?php echo htmlspecialchars($company['supervisor_name'] ?? 'N/A'); ?></div>
                                                    <div class="company-info"><?php echo htmlspecialchars($company['supervisor_email'] ?? ''); ?></div>
                                                </td>
                                                <td><span class="status-badge active"><?php echo $company['job_count']; ?></span></td>
                                                <td><span class="status-badge active"><?php echo $company['application_count']; ?></span></td>
                                                <td><?php echo date('Y-m-d', strtotime($company['created_at'])); ?></td>
                                                <td>
                                                    <button onclick="viewCompanyDetail(<?php echo htmlspecialchars(json_encode($company)); ?>)" class="btn-view">
                                                        <i class="fa-solid fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- ===== PAGINATION FOR COMPANIES (always visible) ===== -->
                        <div class="pagination-wrapper">
                            <span class="page-info">
                                <?php if ($totalCompanies > 0): ?>
                                    Showing <?php echo $offsetC + 1; ?>–<?php echo min($offsetC + $limitC, $totalCompanies); ?> of <?php echo $totalCompanies; ?>
                                <?php else: ?>
                                    No companies to display
                                <?php endif; ?>
                            </span>
                            <?php
                            // Previous link
                            if ($currentPageC > 1) {
                                echo '<a href="?tab=companies&page_c=' . ($currentPageC - 1) . '" class="page-link">Prev</a>';
                            } else {
                                echo '<span class="page-link disabled">Prev</span>';
                            }

                            // Page numbers (if there are pages)
                            if ($totalPagesC > 0) {
                                $start = max(1, $currentPageC - 2);
                                $end = min($totalPagesC, $currentPageC + 2);
                                if ($start > 1) {
                                    echo '<a href="?tab=companies&page_c=1" class="page-link">1</a>';
                                    if ($start > 2) echo '<span class="page-link disabled">…</span>';
                                }
                                for ($i = $start; $i <= $end; $i++) {
                                    $active = ($i == $currentPageC) ? 'active' : '';
                                    echo '<a href="?tab=companies&page_c=' . $i . '" class="page-link ' . $active . '">' . $i . '</a>';
                                }
                                if ($end < $totalPagesC) {
                                    if ($end < $totalPagesC - 1) echo '<span class="page-link disabled">…</span>';
                                    echo '<a href="?tab=companies&page_c=' . $totalPagesC . '" class="page-link">' . $totalPagesC . '</a>';
                                }
                            }

                            // Next link
                            if ($currentPageC < $totalPagesC) {
                                echo '<a href="?tab=companies&page_c=' . ($currentPageC + 1) . '" class="page-link">Next</a>';
                            } else {
                                echo '<span class="page-link disabled">Next</span>';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Jobs Table -->
                <div id="jobs-tab" class="tab-content <?php echo $activeTab === 'jobs' ? 'active' : ''; ?>">
                    <div class="page-card">
                        <div class="section-header">
                            <h2><i class="fa-solid fa-briefcase"></i> All Jobs <span class="badge-count"><?php echo $totalJobs; ?></span></h2>
                        </div>
                                     <!-- Tabs -->
                <div class="tabs">
                    <a href="?tab=companies&page_c=1" class="tab-btn <?php echo $activeTab === 'companies' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-building"></i> Companies
                    </a>
                    <a href="?tab=jobs&page_j=1" class="tab-btn <?php echo $activeTab === 'jobs' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-briefcase"></i> Jobs
                    </a>
                </div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                      
                                        <th>Job Title</th>
                                        <th>Company</th>
                                        <th>Slots</th>
                                        <th>Applications</th>
                                        <th>Deadline</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($jobs)): ?>
                                        <tr>
                                            <td colspan="9" class="empty-state">
                                                <i class="fa-solid fa-briefcase"></i>
                                                <p>No jobs found.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($jobs as $job): ?>
                                            <tr>
                                         
                                                <td>
                                                    <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                                                    <div class="job-info"><?php echo htmlspecialchars(substr($job['description'] ?? '', 0, 40)); ?><?php echo strlen($job['description'] ?? '') > 40 ? '...' : ''; ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($job['company_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span style="font-size: 0.75rem; font-weight: 500;">
                                                        <?php echo $job['slots_filled']; ?> / <?php echo $job['slots_available']; ?>
                                                    </span>
                                                </td>
                                                <td><span class="status-badge active"><?php echo $job['application_count']; ?></span></td>
                                                <td><?php echo isset($job['application_deadline']) && $job['application_deadline'] ? date('Y-m-d', strtotime($job['application_deadline'])) : (isset($job['end_date']) && $job['end_date'] ? date('Y-m-d', strtotime($job['end_date'])) : 'N/A'); ?></td>
                                                <td>
                                                    <span class="status-badge <?php 
                                                        echo $job['status'] === 'active' ? 'open' : 'closed'; 
                                                    ?>">
                                                        <?php echo htmlspecialchars($job['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('Y-m-d', strtotime($job['created_at'])); ?></td>
                                                <td>
                                                    <button onclick="viewJobDetail(<?php echo htmlspecialchars(json_encode($job)); ?>)" class="btn-view">
                                                        <i class="fa-solid fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- ===== PAGINATION FOR JOBS (always visible) ===== -->
                        <div class="pagination-wrapper">
                            <span class="page-info">
                                <?php if ($totalJobs > 0): ?>
                                    Showing <?php echo $offsetJ + 1; ?>–<?php echo min($offsetJ + $limitJ, $totalJobs); ?> of <?php echo $totalJobs; ?>
                                <?php else: ?>
                                    No jobs to display
                                <?php endif; ?>
                            </span>
                            <?php
                            // Previous link
                            if ($currentPageJ > 1) {
                                echo '<a href="?tab=jobs&page_j=' . ($currentPageJ - 1) . '" class="page-link">Prev</a>';
                            } else {
                                echo '<span class="page-link disabled">Prev</span>';
                            }

                            // Page numbers (if there are pages)
                            if ($totalPagesJ > 0) {
                                $start = max(1, $currentPageJ - 2);
                                $end = min($totalPagesJ, $currentPageJ + 2);
                                if ($start > 1) {
                                    echo '<a href="?tab=jobs&page_j=1" class="page-link">1</a>';
                                    if ($start > 2) echo '<span class="page-link disabled">…</span>';
                                }
                                for ($i = $start; $i <= $end; $i++) {
                                    $active = ($i == $currentPageJ) ? 'active' : '';
                                    echo '<a href="?tab=jobs&page_j=' . $i . '" class="page-link ' . $active . '">' . $i . '</a>';
                                }
                                if ($end < $totalPagesJ) {
                                    if ($end < $totalPagesJ - 1) echo '<span class="page-link disabled">…</span>';
                                    echo '<a href="?tab=jobs&page_j=' . $totalPagesJ . '" class="page-link">' . $totalPagesJ . '</a>';
                                }
                            }

                            // Next link
                            if ($currentPageJ < $totalPagesJ) {
                                echo '<a href="?tab=jobs&page_j=' . ($currentPageJ + 1) . '" class="page-link">Next</a>';
                            } else {
                                echo '<span class="page-link disabled">Next</span>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Detail Sidebar -->
    <div id="detailSidebarOverlay" class="detail-sidebar-overlay"></div>
    <aside id="detailSidebar">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 id="detailTitle"><i class="fa-regular fa-circle"></i> Details</h2>
            <button class="modal-close" onclick="closeDetail()">&times;</button>
        </div>
        <div id="detailContent"></div>
    </aside>

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

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closePasswordModal();
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
            }
        });

        // ===== DETAIL SIDEBAR =====
        function viewCompanyDetail(company) {
            const detailTitle = document.getElementById('detailTitle');
            const detailContent = document.getElementById('detailContent');
            
            detailTitle.innerHTML = '<i class="fa-solid fa-building"></i> Company Details';
            
            detailContent.innerHTML = `
                <div class="detail-section">
                    <h3>Company Information</h3>
                    <div class="detail-item">
                        <span class="label">ID</span>
                        <span class="value">${company.id}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Company Name</span>
                        <span class="value">${escapeHtml(company.name)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Address</span>
                        <span class="value">${escapeHtml(company.address || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Industry</span>
                        <span class="value">${escapeHtml(company.industry || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Created</span>
                        <span class="value">${new Date(company.created_at).toLocaleDateString()}</span>
                    </div>
                </div>

                <hr>

                <div class="detail-section">
                    <h3>Contact Information</h3>
                    <div class="detail-item">
                        <span class="label">Contact Person</span>
                        <span class="value">${escapeHtml(company.contact_person || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Contact Email</span>
                        <span class="value">${escapeHtml(company.contact_email || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Contact Number</span>
                        <span class="value">${escapeHtml(company.contact_number || 'N/A')}</span>
                    </div>
                </div>

                <hr>

                <div class="detail-section">
                    <h3>Supervisor</h3>
                    <div class="detail-item">
                        <span class="label">Name</span>
                        <span class="value">${escapeHtml(company.supervisor_name || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Username</span>
                        <span class="value">${escapeHtml(company.supervisor_username || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Email</span>
                        <span class="value">${escapeHtml(company.supervisor_email || 'N/A')}</span>
                    </div>
                </div>

                <hr>

                <div class="detail-section">
                    <h3>Statistics</h3>
                    <div class="detail-item">
                        <span class="label">Total Jobs</span>
                        <span class="value"><span class="status-badge active">${company.job_count}</span></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Total Applications</span>
                        <span class="value"><span class="status-badge active">${company.application_count}</span></span>
                    </div>
                </div>
            `;
            
            openDetail();
        }

        function viewJobDetail(job) {
            const detailTitle = document.getElementById('detailTitle');
            const detailContent = document.getElementById('detailContent');
            
            detailTitle.innerHTML = '<i class="fa-solid fa-briefcase"></i> Job Details';
            
            detailContent.innerHTML = `
                <div class="detail-section">
                    <h3>Job Information</h3>
                    <div class="detail-item">
                        <span class="label">ID</span>
                        <span class="value">${job.id}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Job Title</span>
                        <span class="value">${escapeHtml(job.title)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Company</span>
                        <span class="value">${escapeHtml(job.company_name || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Slots Available</span>
                        <span class="value">${job.slots_available || 0}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Slots Filled</span>
                        <span class="value">${job.slots_filled || 0}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Status</span>
                        <span class="value"><span class="status-badge ${job.status === 'active' ? 'open' : 'closed'}" style="text-transform: capitalize;">${escapeHtml(job.status)}</span></span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Deadline</span>
                        <span class="value">${job.application_deadline ? new Date(job.application_deadline).toLocaleDateString() : (job.end_date ? new Date(job.end_date).toLocaleDateString() : 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Created</span>
                        <span class="value">${new Date(job.created_at).toLocaleDateString()}</span>
                    </div>
                </div>

                <hr>

                <div class="detail-section">
                    <h3>Description</h3>
                    <p class="detail-text">${escapeHtml(job.description || 'No description provided.')}</p>
                </div>

                <hr>

                <div class="detail-section">
                    <h3>Requirements</h3>
                    <p class="detail-text">${escapeHtml(job.requirements || 'No requirements specified.')}</p>
                </div>

                <hr>

                <div class="detail-section">
                    <h3>Statistics</h3>
                    <div class="detail-item">
                        <span class="label">Total Applications</span>
                        <span class="value"><span class="status-badge active">${job.application_count}</span></span>
                    </div>
                </div>
            `;
            
            openDetail();
        }

        function openDetail() {
            document.getElementById('detailSidebar').classList.add('open');
            document.getElementById('detailSidebarOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeDetail() {
            document.getElementById('detailSidebar').classList.remove('open');
            document.getElementById('detailSidebarOverlay').classList.remove('open');
            document.body.style.overflow = 'auto';
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"'`]/g, function (s) {
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','`':'&#96;'})[s];
            });
        }

        // Click outside to close
        document.getElementById('detailSidebarOverlay').addEventListener('click', closeDetail);

        <?php if ($message): 
            $isError = strpos($message, 'Error:') !== false || strpos($message, 'Failed') !== false;
        ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo htmlspecialchars($message); ?>', '<?php echo $isError ? 'error' : 'success'; ?>');
            });
        <?php endif; ?>
    </script>
</body>
</html>