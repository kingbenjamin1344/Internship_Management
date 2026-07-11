<?php
// student/dpr.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is student
checkAccess('student');

$username = $_SESSION['username'] ?? 'Student';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Student';
$role = getUserRole();
$user_id = $_SESSION['user_id'] ?? 0;

// Define internship timeline
define('INTERNSHIP_START_DATE', '2026-06-01');
define('INTERNSHIP_END_DATE', '2026-08-31');

// Handle form submission
$success_message = '';
$error_message = '';

// Insert DPR into database with automatic timestamp
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dpr'])) {
    $date = $_POST['date'] ?? '';
    $activities = trim($_POST['activities'] ?? '');
    $time_in = $_POST['time_in'] ?? '';
    $time_out = $_POST['time_out'] ?? '';
    $accomplishments = trim($_POST['accomplishments'] ?? '');
    $issues = trim($_POST['issues'] ?? '');
    
    // Validation checks
    $errors = [];
    
    // 1. Required field validation
    if (empty($date)) {
        $errors[] = 'Date is required.';
    }
    if (empty($activities)) {
        $errors[] = 'Activities performed is required.';
    }
    if (empty($time_in)) {
        $errors[] = 'Time in is required.';
    }
    if (empty($time_out)) {
        $errors[] = 'Time out is required.';
    }
    if (empty($accomplishments)) {
        $errors[] = 'Accomplishments is required.';
    }
    
    // 2. Date validation (must be within internship timeline - but future dates allowed)
    if (!empty($date)) {
        $report_date = new DateTime($date);
        $internship_start = new DateTime(INTERNSHIP_START_DATE);
        $internship_end = new DateTime(INTERNSHIP_END_DATE);
        $internship_end->setTime(23, 59, 59);
        
        // Allow dates within internship timeline including future dates
        if ($report_date < $internship_start || $report_date > $internship_end) {
            $errors[] = 'Report date must be within the internship period (' . date('M d, Y', strtotime(INTERNSHIP_START_DATE)) . ' - ' . date('M d, Y', strtotime(INTERNSHIP_END_DATE)) . ').';
        }
    }
    
    // 3. Time format validation
    if (!empty($time_in) && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time_in)) {
        $errors[] = 'Invalid time format for Time In. Please use HH:MM format.';
    }
    if (!empty($time_out) && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time_out)) {
        $errors[] = 'Invalid time format for Time Out. Please use HH:MM format.';
    }
    
    // 4. Time validation (time out must be after time in)
    if (!empty($time_in) && !empty($time_out)) {
        $time_in_obj = DateTime::createFromFormat('H:i', $time_in);
        $time_out_obj = DateTime::createFromFormat('H:i', $time_out);
        
        if ($time_in_obj && $time_out_obj && $time_out_obj <= $time_in_obj) {
            $errors[] = 'Time out must be after time in.';
        }
    }
    
    // If no errors, proceed with submission
    if (empty($errors)) {
        try {
            // Get current timestamp for automatic recording
            $submitted_at = date('Y-m-d H:i:s');
            
            $stmt = $pdo->prepare("INSERT INTO daily_progress_reports (user_id, report_date, activities, time_in, time_out, accomplishments, issues, status, submitted_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', ?, NOW())");
            $stmt->execute([$user_id, $date, $activities, $time_in, $time_out, $accomplishments, $issues, $submitted_at]);
            $success_message = 'Daily Progress Report submitted successfully at ' . date('h:i A', strtotime($submitted_at)) . '!';
            
            // Clear form data after success
            $_POST = array();
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

// Fetch DPR history from database
$dpr_history = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM daily_progress_reports WHERE user_id = ? ORDER BY report_date DESC, created_at DESC");
    $stmt->execute([$user_id]);
    $dpr_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
    $error_message = 'Database table not found. Please run the migration.';
}

// Get internship timeline info
$internship_start = date('M d, Y', strtotime(INTERNSHIP_START_DATE));
$internship_end = date('M d, Y', strtotime(INTERNSHIP_END_DATE));
$today_date = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Progress Report - Student</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        /* ----- Reset / base overrides ----- */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: #f1f5f9;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: #0f172a;
            overflow-x: hidden;
        }

        .app-shell {
            display: flex;
            min-height: 100vh;
        }

        /* ----- SIDEBAR ----- */
        .sidebar {
            width: 250px;
            background: #0f172a;
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            padding: 24px 18px 20px;
            flex-shrink: 0;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
        }

        .sidebar-brand i {
            font-size: 1.6rem;
            color: #38bdf8;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .sidebar-brand h2 span {
            display: block;
            font-weight: 400;
            font-size: 0.65rem;
            color: #94a3b8;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .nav-section {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 12px;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.15s;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }

        .nav-item:hover {
            background: #1e293b;
            color: #f1f5f9;
        }

        .nav-item.active {
            background: #1e293b;
            color: #38bdf8;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid #1e293b;
            padding-top: 18px;
        }

        .logout-btn-side {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 12px;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: 0.15s;
        }

        .logout-btn-side:hover {
            background: #1e293b;
            color: #f1f5f9;
        }

        /* ----- MAIN CONTENT ----- */
        .main-content {
            flex: 1;
            padding: 0 32px 32px 32px;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        /* ----- TOP HEADER ----- */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 32px;
            background: #0f172a;
            border-radius: 0;
            margin: 0 -32px 24px -32px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-left h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #f8fafc;
            letter-spacing: -0.3px;
        }

        .header-left h1 small {
            font-weight: 400;
            font-size: 0.85rem;
            color: #94a3b8;
            margin-left: 8px;
        }

        .header-left h1 i {
            color: #38bdf8;
            margin-right: 8px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .notif-bell {
            position: relative;
            font-size: 1.3rem;
            color: #e2e8f0;
            background: rgba(255,255,255,0.08);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.15s;
            cursor: pointer;
            border: none;
        }

        .notif-bell:hover {
            background: rgba(255,255,255,0.18);
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
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #0f172a;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.08);
            padding: 4px 16px 4px 6px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.12);
            cursor: default;
            backdrop-filter: blur(2px);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3b82f6;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .user-info .name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #f1f5f9;
        }

        .user-info .role-label {
            font-size: 0.7rem;
            color: #94a3b8;
            font-weight: 500;
            text-transform: capitalize;
        }

        /* Timeline Info Banner */
        .timeline-banner {
            background: linear-gradient(135deg, #e0e7ff, #dbeafe);
            border: 1px solid #93c5fd;
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .timeline-banner i {
            color: #3b82f6;
            font-size: 1.3rem;
        }

        .timeline-banner .timeline-text {
            font-size: 0.9rem;
            color: #1e40af;
            font-weight: 500;
        }

        .timeline-banner .timeline-text strong {
            color: #1e3a8a;
        }

        /* ----- PAGE CARD ----- */
        .page-card {
            background: #fff;
            border-radius: 24px;
            padding: 24px 28px 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            border: 1px solid #eef2f7;
            flex: 1;
        }

        .page-card h2 {
            font-size: 1.3rem;
            margin-bottom: 8px;
        }

        .page-card p {
            color: #64748b;
            font-size: 0.95rem;
        }

        /* ----- TABLE CONTROLS ----- */
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0 16px 0;
            flex-wrap: wrap;
            gap: 12px;
        }

        .table-controls h3 {
            font-size: 1.1rem;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-create {
            padding: 10px 24px;
            background: #0f172a;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-create:hover {
            background: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.3);
        }

        .btn-create i {
            font-size: 1rem;
        }

        /* ----- DPR TABLE ----- */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }

        .dpr-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .dpr-table thead {
            background: #f8fafc;
        }

        .dpr-table th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #0f172a;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .dpr-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: top;
        }

        .dpr-table tbody tr:hover {
            background: #f8fafc;
        }

        .dpr-table tbody tr:last-child td {
            border-bottom: none;
        }

        .issue-tag {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .issue-tag.none {
            background: #ecfdf5;
            color: #065f46;
        }

        .issue-tag.has-issue {
            background: #fef2f2;
            color: #991b1b;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.submitted {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-badge.approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.rejected {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        .empty-state p {
            font-size: 1rem;
        }

        .count-badge {
            background: #e2e8f0;
            color: #0f172a;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Submitted timestamp style */
        .timestamp {
            font-size: 0.75rem;
            color: #64748b;
            display: block;
            margin-top: 4px;
        }

        /* ----- RIGHT SIDEBAR (Overlay) ----- */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            animation: fadeIn 0.3s ease;
        }

        .overlay.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }

        .right-sidebar {
            position: fixed;
            top: 0;
            right: -100%;
            width: 50%;
            height: 100%;
            background: #fff;
            z-index: 1000;
            padding: 32px;
            overflow-y: auto;
            transition: right 0.3s ease;
            box-shadow: -4px 0 24px rgba(0, 0, 0, 0.1);
        }

        .right-sidebar.open {
            right: 0;
        }

        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f1f5f9;
        }

        .sidebar-header h2 {
            font-size: 1.3rem;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-header h2 i {
            color: #3b82f6;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #64748b;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.15s;
        }

        .btn-close:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        /* ----- DPR FORM IN SIDEBAR ----- */
        .dpr-form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-group label .required {
            color: #ef4444;
            font-size: 1.1rem;
        }

        .form-group .field-hint {
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 400;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            padding: 10px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.15s;
            font-family: inherit;
            background: #fafbfc;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
            background: #fff;
        }

        .form-group input:invalid,
        .form-group textarea:invalid {
            border-color: #ef4444;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group input[type="time"] {
            padding: 8px 14px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 2px solid #f1f5f9;
        }

        .btn-submit {
            padding: 12px 32px;
            background: #0f172a;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2);
        }

        .btn-cancel {
            padding: 12px 24px;
            background: #f1f5f9;
            color: #0f172a;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-cancel:hover {
            background: #e2e8f0;
        }

        .alert {
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .alert-error ul {
            margin: 4px 0 0 20px;
            padding: 0;
        }

        .alert-error ul li {
            margin-bottom: 2px;
        }

        @media (max-width: 768px) {
            .right-sidebar {
                width: 100%;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .top-header {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 16px;
                margin: 0 -16px 16px -16px;
            }
            .header-right {
                justify-content: flex-start;
            }
            .page-card {
                padding: 16px;
            }
            .timeline-banner {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-graduation-cap"></i>
                <h2>RBAC<span>Student Portal</span></h2>
            </div>
            <nav class="nav-section">
                <a class="nav-item" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item" href="apply.php"><i class="fa-solid fa-paper-plane"></i> Apply Job</a>
                <a class="nav-item" href="applications.php"><i class="fa-solid fa-list-check"></i> My Applications</a>
                <a class="nav-item active" href="dpr.php"><i class="fa-solid fa-clipboard-list"></i> Daily Progress Report</a>
            </nav>
            <div class="sidebar-footer">
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <!-- Main Workspace -->
        <main class="main-content" id="mainContent">
            <!-- TOP HEADER -->
            <div class="top-header">
                <div class="header-left">
                    <h1>
                        <i class="fa-solid fa-clipboard-list"></i>
                        Daily Progress Report
                        <small>Student</small>
                    </h1>
                </div>
                <div class="header-right">
                    <button class="notif-bell" onclick="alert('No new notifications')" aria-label="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <span class="notif-badge">3</span>
                    </button>
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
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-card">
                <h2>Your Daily Progress Reports</h2>
                <p>Track and manage your daily activities, accomplishments, and challenges.</p>

                <!-- Timeline Banner -->
                <div class="timeline-banner">
                    <i class="fa-solid fa-calendar-check"></i>
                    <div class="timeline-text">
                        <strong>Internship Timeline:</strong> 
                        <?php echo $internship_start; ?> - <?php echo $internship_end; ?>
                        <span style="margin-left: 12px; font-weight: 400;">
                            <i class="fa-regular fa-clock"></i> 
                            Today: <?php echo date('M d, Y'); ?>
                        </span>
                        <span style="margin-left: 12px; font-weight: 400; color: #6b7280;">
                            <i class="fa-regular fa-calendar-plus"></i>
                            Future dates are allowed
                        </span>
                    </div>
                </div>

                <!-- Display Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success" style="margin-top: 16px;">
                        <i class="fa-solid fa-check-circle"></i>
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-error" style="margin-top: 16px;">
                        <i class="fa-solid fa-exclamation-circle"></i>
                        <div>
                            <strong>Please fix the following errors:</strong>
                            <ul>
                                <?php 
                                $errors = explode('<br>', $error_message);
                                foreach ($errors as $error): 
                                ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Table Controls -->
                <div class="table-controls">
                    <h3>
                        <i class="fa-regular fa-clock-rotate-left"></i>
                        Report History
                        <span class="count-badge"><?php echo count($dpr_history); ?></span>
                    </h3>
                    <button class="btn-create" onclick="openSidebar()">
                        <i class="fa-solid fa-plus"></i>
                        Create DPR
                    </button>
                </div>

                <!-- DPR Table -->
                <?php if (empty($dpr_history)): ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-folder-open"></i>
                        <p>No reports submitted yet.</p>
                        <p style="font-size: 0.85rem; margin-top: 8px;">Click the <strong>"Create DPR"</strong> button to submit your first report.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="dpr-table">
                            <thead>
                                <tr>
                                    <th><i class="fa-regular fa-calendar"></i> Date</th>
                                    <th><i class="fa-regular fa-file-lines"></i> Activities</th>
                                    <th><i class="fa-regular fa-clock"></i> Time In</th>
                                    <th><i class="fa-regular fa-clock"></i> Time Out</th>
                                    <th><i class="fa-regular fa-circle-check"></i> Accomplishments</th>
                                    <th><i class="fa-regular fa-triangle-exclamation"></i> Issues</th>
                                    <th><i class="fa-regular fa-flag"></i> Status</th>
                                    <th><i class="fa-regular fa-clock"></i> Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dpr_history as $report): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($report['report_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($report['activities']); ?></td>
                                        <td><?php echo date('h:i A', strtotime($report['time_in'])); ?></td>
                                        <td><?php echo date('h:i A', strtotime($report['time_out'])); ?></td>
                                        <td><?php echo htmlspecialchars($report['accomplishments']); ?></td>
                                        <td>
                                            <?php if (empty($report['issues']) || strtolower($report['issues']) === 'none'): ?>
                                                <span class="issue-tag none"><i class="fa-regular fa-check"></i> None</span>
                                            <?php else: ?>
                                                <span class="issue-tag has-issue"><i class="fa-regular fa-circle-exclamation"></i> <?php echo htmlspecialchars($report['issues']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo strtolower($report['status']); ?>">
                                                <i class="fa-regular fa-circle-check"></i>
                                                <?php echo ucfirst($report['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($report['submitted_at'])): ?>
                                                <?php echo date('M d, Y h:i A', strtotime($report['submitted_at'])); ?>
                                                <span class="timestamp">
                                                    <i class="fa-regular fa-clock"></i> 
                                                    <?php 
                                                    $submitted = new DateTime($report['submitted_at']);
                                                    $now = new DateTime();
                                                    $diff = $now->diff($submitted);
                                                    if ($diff->days == 0) {
                                                        if ($diff->h == 0 && $diff->i == 0) {
                                                            echo 'Just now';
                                                        } else {
                                                            echo $diff->h . 'h ' . $diff->i . 'm ago';
                                                        }
                                                    } else {
                                                        echo $diff->days . ' days ago';
                                                    }
                                                    ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Overlay -->
    <div class="overlay" id="overlay" onclick="closeSidebar()"></div>

    <!-- Right Sidebar -->
    <div class="right-sidebar" id="rightSidebar">
        <div class="sidebar-header">
            <h2>
                <i class="fa-solid fa-pen-to-square"></i>
                Create Daily Progress Report
            </h2>
            <button class="btn-close" onclick="closeSidebar()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Display messages in sidebar -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-exclamation-circle"></i>
                <div>
                    <strong>Please fix the following errors:</strong>
                    <ul>
                        <?php 
                        $errors = explode('<br>', $error_message);
                        foreach ($errors as $error): 
                        ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- DPR Form -->
        <form method="POST" action="" class="dpr-form" id="dprForm" novalidate>
            <div class="form-row">
                <div class="form-group">
                    <label for="date">
                        <i class="fa-regular fa-calendar"></i> Date of Report
                        <span class="required">*</span>
                    </label>
                    <input type="date" id="date" name="date" 
                           value="<?php echo isset($_POST['date']) ? htmlspecialchars($_POST['date']) : date('Y-m-d'); ?>" 
                           min="<?php echo INTERNSHIP_START_DATE; ?>" 
                           max="<?php echo INTERNSHIP_END_DATE; ?>" 
                           required>
                    <span class="field-hint">Must be within internship period (future dates allowed)</span>
                </div>

                <div class="form-group">
                    <label><i class="fa-regular fa-circle-check"></i> Status</label>
                    <input type="text" value="Ready to Submit" disabled style="background: #f1f5f9; color: #64748b;">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="time_in">
                        <i class="fa-regular fa-clock"></i> Time In
                        <span class="required">*</span>
                    </label>
                    <input type="time" id="time_in" name="time_in" 
                           value="<?php echo isset($_POST['time_in']) ? htmlspecialchars($_POST['time_in']) : '08:00'; ?>" 
                           required>
                    <span class="field-hint">Use 24-hour format (HH:MM)</span>
                </div>

                <div class="form-group">
                    <label for="time_out">
                        <i class="fa-regular fa-clock"></i> Time Out
                        <span class="required">*</span>
                    </label>
                    <input type="time" id="time_out" name="time_out" 
                           value="<?php echo isset($_POST['time_out']) ? htmlspecialchars($_POST['time_out']) : '17:00'; ?>" 
                           required>
                    <span class="field-hint">Must be after Time In</span>
                </div>
            </div>

            <div class="form-group full-width">
                <label for="activities">
                    <i class="fa-regular fa-file-lines"></i> Activities Performed
                    <span class="required">*</span>
                </label>
                <textarea id="activities" name="activities" 
                          placeholder="Describe what you worked on today..." 
                          required><?php echo isset($_POST['activities']) ? htmlspecialchars($_POST['activities']) : ''; ?></textarea>
            </div>

            <div class="form-group full-width">
                <label for="accomplishments">
                    <i class="fa-regular fa-circle-check"></i> Accomplishments / Tasks Completed
                    <span class="required">*</span>
                </label>
                <textarea id="accomplishments" name="accomplishments" 
                          placeholder="List the tasks you completed today..." 
                          required><?php echo isset($_POST['accomplishments']) ? htmlspecialchars($_POST['accomplishments']) : ''; ?></textarea>
            </div>

            <div class="form-group full-width">
                <label for="issues">
                    <i class="fa-regular fa-triangle-exclamation"></i> Issues or Challenges Encountered
                </label>
                <textarea id="issues" name="issues" 
                          placeholder="Describe any problems or challenges you faced (if none, type 'None')"><?php echo isset($_POST['issues']) ? htmlspecialchars($_POST['issues']) : ''; ?></textarea>
                <span class="field-hint">Optional - leave blank or type 'None' if no issues</span>
            </div>

            <div class="form-actions">
                <button type="submit" name="submit_dpr" class="btn-submit">
                    <i class="fa-regular fa-paper-plane"></i>
                    Submit Report
                </button>
                <button type="button" class="btn-cancel" onclick="closeSidebar()">
                    Cancel
                </button>
            </div>
        </form>
    </div>

    <script>
        // Open the right sidebar
        function openSidebar() {
            document.getElementById('rightSidebar').classList.add('open');
            document.getElementById('overlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // Close the right sidebar
        function closeSidebar() {
            document.getElementById('rightSidebar').classList.remove('open');
            document.getElementById('overlay').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });

        // Auto-close sidebar after successful submission
        <?php if ($success_message): ?>
            setTimeout(function() {
                closeSidebar();
            }, 3000);
        <?php endif; ?>

        // Real-time validation for date and time
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('date');
            const timeInInput = document.getElementById('time_in');
            const timeOutInput = document.getElementById('time_out');
            
            // Date validation - only check if within internship period
            // No future date restriction
            dateInput.addEventListener('change', function() {
                const selectedDate = new Date(this.value);
                const startDate = new Date('<?php echo INTERNSHIP_START_DATE; ?>');
                const endDate = new Date('<?php echo INTERNSHIP_END_DATE; ?>');
                endDate.setHours(23, 59, 59);
                
                if (selectedDate < startDate || selectedDate > endDate) {
                    alert('Date must be within the internship period (<?php echo date('M d, Y', strtotime(INTERNSHIP_START_DATE)); ?> - <?php echo date('M d, Y', strtotime(INTERNSHIP_END_DATE)); ?>).');
                    this.value = '<?php echo date('Y-m-d'); ?>';
                }
            });
            
            // Validate time out is after time in
            timeOutInput.addEventListener('change', function() {
                const timeIn = timeInInput.value;
                const timeOut = this.value;
                
                if (timeIn && timeOut && timeOut <= timeIn) {
                    alert('Time out must be after time in.');
                    this.value = '';
                }
            });
            
            timeInInput.addEventListener('change', function() {
                const timeIn = this.value;
                const timeOut = timeOutInput.value;
                
                if (timeIn && timeOut && timeOut <= timeIn) {
                    alert('Time out must be after time in.');
                    timeOutInput.value = '';
                }
            });
        });
    </script>
</body>
</html>