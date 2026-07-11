<?php
// supervisor/job.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

checkAccess('supervisor');
ensureInternshipTables($pdo);

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Supervisor';
$role = getUserRole();
$userId = getUserId();

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
        $_SESSION['error'] = 'Please choose a valid assigned company and enter a job title.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO jobs (company_id, title, description, responsibility, requirements, slots_available, duration_hours, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $title, $description, $responsibility, $requirements, $slotsAvailable, $durationHours, $userId, $userId]);
        $_SESSION['success'] = 'Internship position created successfully.';
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
        $_SESSION['success'] = 'Job updated successfully.';
    } else {
        $_SESSION['error'] = 'You do not have permission to update this job or title is empty.';
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
        $_SESSION['success'] = 'Job deleted successfully.';
    } else {
        $_SESSION['error'] = 'You do not have permission to delete this job.';
    }
    
    header('Location: job.php');
    exit;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor - Assigned Companies</title>
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
        }

        /* ----- TOP HEADER (blue theme matching sidebar) ----- */
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

        /* Notification bell */
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

        /* User profile chip */
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

        /* ----- PAGE CARD ----- */
        .page-card {
            background: #fff;
            border-radius: 24px;
            padding: 24px 28px 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            border: 1px solid #eef2f7;
            flex: 1;
        }

        /* Alert messages */
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .table-container { overflow-x: auto; margin-top: 20px; border-radius: 16px; border: 1px solid #e2e8f0; background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 8px 24px rgba(0,0,0,0.04); }
        .company-table, .job-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 1000px; }
        .company-table thead, .job-table thead { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
        .company-table thead th, .job-table thead th { padding: 14px 16px; text-align: left; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; white-space: nowrap; }
        .company-table tbody tr, .job-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.2s ease; }
        .company-table tbody tr:last-child, .job-table tbody tr:last-child { border-bottom: none; }
        .company-table tbody tr:hover, .job-table tbody tr:hover { background: #f8fafc; }
        .company-table tbody td, .job-table tbody td { padding: 14px 16px; vertical-align: middle; color: #1e293b; }
        .company-name-cell { font-weight: 600; color: #0f172a; }
        .badge-cell { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; background: linear-gradient(135deg, #dbeafe, #eff6ff); color: #1d4ed8; font-weight: 600; font-size: 0.7rem; letter-spacing: 0.3px; white-space: nowrap; }
        .badge-cell i { font-size: 0.65rem; }
        .industry-tag { display: inline-block; padding: 3px 12px; background: #f1f5f9; border-radius: 999px; font-size: 0.75rem; font-weight: 600; color: #475569; }
        .email-link { color: #2563eb; text-decoration: none; font-weight: 400; transition: color 0.2s ease; }
        .email-link:hover { color: #1d4ed8; text-decoration: underline; }
        .address-text { font-size: 0.85rem; color: #475569; line-height: 1.4; max-width: 200px; }
        .empty-state { padding: 60px 20px; text-align: center; background: #fafcff; border: 2px dashed #e2e8f0; border-radius: 24px; margin-top: 20px; }
        .empty-state i { font-size: 48px; color: #94a3b8; margin-bottom: 16px; opacity: 0.5; }
        .empty-state h3 { color: #1e293b; margin: 0 0 8px; font-size: 1.2rem; }
        .empty-state p { color: #94a3b8; margin: 0; }
        .page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 4px; }
        .company-count { font-size: 0.9rem; color: #94a3b8; font-weight: 500; }
        .company-count span { color: #1e293b; font-weight: 700; }
        .action-btn { padding: 10px 20px; border-radius: 999px; border: none; background: #2563eb; color: #fff; font-weight: 600; cursor: pointer; transition: background 0.2s ease; }
        .action-btn:hover { background: #1d4ed8; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-edit, .btn-delete {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-edit {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .btn-edit:hover {
            background: #bfdbfe;
            transform: translateY(-1px);
        }
        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }
        .btn-delete:hover {
            background: #fecaca;
            transform: translateY(-1px);
        }
        .btn-view {
            padding: 4px 12px;
            border-radius: 999px;
            border: none;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #e0e7ff;
            color: #4338ca;
            white-space: nowrap;
        }
        .btn-view:hover {
            background: #c7d2fe;
            transform: scale(1.05);
        }
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .modal-container {
            background: #ffffff;
            width: 100%;
            max-width: 820px;
            max-height: 90vh;
            border-radius: 28px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: modalSlideUp 0.3s ease;
        }
        
        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.96);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-header {
            padding: 28px 32px 20px 32px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            background: #fafcff;
        }
        
        .modal-header-left h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 700;
            color: #0f172a;
        }
        
        .modal-header-left p {
            margin: 6px 0 0;
            color: #94a3b8;
            font-size: 0.9rem;
        }
        
        .modal-close-btn {
            background: #f1f5f9;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 24px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        
        .modal-close-btn:hover {
            background: #fee2e2;
            color: #dc2626;
            transform: rotate(90deg);
        }
        
        .modal-body {
            padding: 28px 32px 20px 32px;
            overflow-y: auto;
            max-height: calc(90vh - 200px);
        }
        
        .modal-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            color: #334155;
            margin-bottom: 6px;
        }
        
        .form-group label .required {
            color: #dc2626;
            margin-left: 2px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.2s ease;
            background: #fafcff;
            box-sizing: border-box;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
            background: #ffffff;
        }
        
        .form-control::placeholder {
            color: #94a3b8;
        }
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            cursor: pointer;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .modal-footer {
            padding: 16px 32px 28px 32px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #fafcff;
        }
        
        .btn-secondary {
            padding: 12px 24px;
            border-radius: 999px;
            border: 1.5px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-secondary:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        
        .btn-primary {
            padding: 12px 28px;
            border-radius: 999px;
            border: none;
            background: #2563eb;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        /* Delete Confirmation Modal */
        .delete-modal .modal-container {
            max-width: 450px;
        }
        .delete-modal .modal-body {
            text-align: center;
            padding: 40px 32px 30px;
        }
        .delete-modal .modal-body i {
            font-size: 48px;
            color: #dc2626;
            margin-bottom: 16px;
        }
        .delete-modal .modal-body h4 {
            margin: 0 0 8px;
            color: #0f172a;
        }
        .delete-modal .modal-body p {
            color: #64748b;
            margin: 0 0 24px;
        }
        .delete-modal .modal-footer {
            justify-content: center;
        }
        .btn-danger {
            padding: 12px 28px;
            border-radius: 999px;
            border: none;
            background: #dc2626;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-1px);
        }
        
        /* View Modal */
        .view-modal .modal-container {
            max-width: 600px;
        }
        .view-modal .modal-body {
            padding: 32px;
        }
        .view-modal .modal-body .content {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 400px;
            overflow-y: auto;
            font-size: 0.95rem;
            line-height: 1.6;
            color: #1e293b;
        }
        .view-modal .modal-body .content-label {
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        @media (max-width: 768px) {
            .table-container { border-radius: 12px; }
            .company-table, .job-table { font-size: 0.8rem; min-width: 800px; }
            .company-table thead th, .company-table tbody td, .job-table thead th, .job-table tbody td { padding: 10px 12px; }
            
            .modal-container { max-width: 100%; border-radius: 20px; }
            .modal-header { padding: 20px 20px 16px 20px; }
            .modal-body { padding: 20px 20px 16px 20px; }
            .modal-footer { padding: 16px 20px 20px 20px; flex-direction: column-reverse; }
            .modal-footer button { width: 100%; justify-content: center; }
            .form-row { grid-template-columns: 1fr; gap: 0; }
            .top-header {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 16px;
                margin: 0 -16px 16px -16px;
            }
            .header-right {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-clipboard-check"></i>
                <h2>System<span>Supervisor Panel</span></h2>
            </div>
            <nav class="nav-section">
                <a class="nav-item" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item active" href="job.php"><i class="fa-solid fa-briefcase"></i> Add Job</a>
                <a class="nav-item" href="applicant.php"><i class="fa-solid fa-briefcase"></i> Applicants</a>
                <a class="nav-item" href="myintern.php"><i class="fa-solid fa-briefcase"></i> My Interns</a>
            </nav>
            <div class="sidebar-footer">
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <main class="main-content">
            <!-- TOP HEADER -->
            <div class="top-header">
                <div class="header-left">
                    <h1>
                        <i class="fa-solid fa-briefcase"></i>
                        Manage Jobs
                        <small>Supervisor</small>
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

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert success">
                    <i class="fa-solid fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert error">
                    <i class="fa-solid fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="page-card">
                <div class="page-header">
                    <div>
                        <h2 style="margin:0 0 4px; font-size: 1.5rem;">Your Assigned Companies</h2>
                        <p style="margin:0; color:#64748b;">Companies assigned to your supervisor account</p>
                    </div>
                    <div class="company-count">
                        <i class="fa-regular fa-building"></i>
                        <span><?php echo count($assignedCompanies); ?></span> 
                        <?php echo count($assignedCompanies) === 1 ? 'company' : 'companies'; ?>
                    </div>
                </div>

                <?php if (count($assignedCompanies) > 0): ?>
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
                                    <th>Status</th>
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
                                        <td><span class="badge-cell"><i class="fa-solid fa-circle-check"></i> Assigned</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-briefcase"></i>
                        <h3>No Companies Assigned</h3>
                        <p>You haven't been assigned to any companies yet. Please contact your coordinator.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="page-card" style="margin-top: 24px;">
                <div class="page-header" style="margin-bottom: 12px;">
                    <div>
                        <h2 style="margin:0 0 4px; font-size: 1.3rem;">Internship Positions</h2>
                        <p style="margin:0; color:#64748b;">Create and manage internship roles for your assigned companies.</p>
                    </div>
                    <?php if (count($assignedCompanies) > 0): ?>
                        <button class="action-btn" type="button" onclick="openJobModal()"><i class="fa-solid fa-plus"></i> Add Job</button>
                    <?php endif; ?>
                </div>

                <?php if (count($jobs) > 0): ?>
                    <div class="table-container">
                        <table class="job-table">
                           <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Company</th>
                                    <th>Available Slots</th>
                                    <th>Filled Slots</th>
                                    <th>Duration</th>
                                    <th>Created</th>
                                    <th>Description</th>
                                    <th>Responsibilities</th>
                                    <th>Requirements</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobs as $job): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($job['title']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($job['company_name']); ?></td>
                                        <td>
                                            <?php 
                                            $available = (int)$job['slots_available'] - (int)$job['slots_filled'];
                                            $color = $available > 0 ? '#16a34a' : '#dc2626';
                                            ?>
                                            <span style="font-weight: 600; color: <?php echo $color; ?>;">
                                                <?php echo $available; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: #2563eb;">
                                                <?php echo (int)$job['slots_filled']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo (int)$job['duration_hours'] > 0 ? (int)$job['duration_hours'] . ' hrs' : '-'; ?></td>
                                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime($job['created_at']))); ?></td>
                                        <td>
                                            <button class="btn-view" onclick="viewContent('description', <?php echo (int)$job['id']; ?>)">
                                                <i class="fa-solid fa-file-lines"></i> View
                                            </button>
                                        </td>
                                        <td>
                                            <button class="btn-view" onclick="viewContent('responsibility', <?php echo (int)$job['id']; ?>)">
                                                <i class="fa-solid fa-tasks"></i> View
                                            </button>
                                        </td>
                                        <td>
                                            <button class="btn-view" onclick="viewContent('requirements', <?php echo (int)$job['id']; ?>)">
                                                <i class="fa-solid fa-list-check"></i> View
                                            </button>
                                        </td>
                                        <td>
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

    <script>
        // Store job data for view modal
        const jobData = <?php echo json_encode($jobs); ?>;
        
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
                alert('Job not found');
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
                alert('Job not found');
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
            }
        });
    </script>
</body>
</html>