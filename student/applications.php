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
    $canCommit = $application['status'] === 'accepted' && ($committedApplicationId === null || $committedApplicationId === (int)$application['application_id']);
    if ($application['status'] === 'committed') {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Applications</title>
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

        /* ----- SIDEBAR (compact, without user chip) ----- */
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

        /* ----- NEW TOP HEADER (blue theme matching sidebar, full width) ----- */
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

        /* Notification bell - light version for dark header */
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

        /* User profile chip - light for dark header */
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

        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .page-head h2 {
            margin-bottom: 6px;
            font-size: 1.2rem;
        }

        .page-head p {
            color: #64748b;
            font-size: 0.92rem;
        }

        .notice-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #fff;
            margin-top: 16px;
        }

        .table-header-reminder {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #fefce8;
            border-bottom: 2px solid #fef08a;
            border-radius: 16px 16px 0 0;
        }

        .table-header-reminder .reminder-text {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #854d0e;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .table-header-reminder .reminder-text i {
            color: #eab308;
            font-size: 1rem;
        }

        .app-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        .app-table thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        .app-table th {
            text-align: left;
            padding: 12px 14px;
            color: #64748b;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .app-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
            color: #1e293b;
        }

        .app-table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-warning { background: #fef3c7; color: #b45309; }
        .badge-info { background: #dbeafe; color: #1d4ed8; }
        .badge-secondary { background: #ede9fe; color: #6d28d9; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-danger { background: #fee2e2; color: #b91c1c; }
        .badge-dark { background: #e2e8f0; color: #334155; }

        .muted {
            color: #64748b;
            font-size: 0.86rem;
            line-height: 1.4;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .btn-view {
            background: #e0e7ff;
            color: #4338ca;
        }

        .btn-view:hover {
            background: #c7d2fe;
            transform: translateY(-1px);
        }

        .btn-commit {
            background: #d1fae5;
            color: #166534;
        }

        .btn-commit:hover {
            background: #a7f3d0;
            transform: translateY(-1px);
        }

        .btn-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .action-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .empty-state {
            border: 2px dashed #dbe3ef;
            border-radius: 16px;
            padding: 38px 20px;
            text-align: center;
            color: #64748b;
            background: #f8fafc;
            margin-top: 16px;
        }

        .empty-state i {
            font-size: 2rem;
            color: #94a3b8;
            margin-bottom: 10px;
        }

        /* ----- MODAL (unchanged) ----- */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(5px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
            overflow-y: auto;
        }

        .modal-container {
            background: #ffffff;
            width: 100%;
            max-width: 760px;
            border-radius: 22px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: modalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header,
        .modal-footer {
            padding: 18px 22px;
            background: #fafcff;
            border-color: #e2e8f0;
        }

        .modal-header {
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.2rem;
            color: #0f172a;
        }

        .modal-close-btn {
            background: #f1f5f9;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            color: #64748b;
            cursor: pointer;
        }

        .modal-body {
            padding: 22px;
            max-height: calc(85vh - 150px);
            overflow-y: auto;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .detail-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
        }

        .detail-card label {
            display: block;
            color: #64748b;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .detail-card .value {
            color: #0f172a;
            font-weight: 600;
            line-height: 1.5;
        }

        .detail-block {
            margin-top: 16px;
            border-top: 1px solid #eef2f7;
            padding-top: 16px;
        }

        .detail-block h4 {
            margin: 0 0 8px;
            color: #0f172a;
        }

        .detail-text {
            color: #334155;
            line-height: 1.7;
            white-space: pre-wrap;
        }

        .modal-footer {
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-secondary,
        .btn-primary {
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #334155;
        }

        .btn-primary {
            background: #16a34a;
            color: #fff;
        }

        .btn-primary:hover {
            background: #15803d;
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        @media (max-width: 720px) {
            .detail-grid {
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
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <!-- SIDEBAR (without user chip) -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-graduation-cap"></i>
                <h2>RBAC<span>Student Portal</span></h2>
            </div>
            <nav class="nav-section">
                <a class="nav-item " href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item " href="apply.php"><i class="fa-solid fa-gauge-high"></i> Apply Job</a>
                <a class="nav-item active" href="applications.php"><i class="fa-solid fa-gauge-high"></i> My Applications</a>
                <a class="nav-item " href="dpr.php"><i class="fa-solid fa-gauge-high"></i> Daily Progress Report</a>
            </nav>
            <div class="sidebar-footer">
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <!-- NEW HEADER: full width, no rounded corners, matching sidebar -->
            <div class="top-header">
                <div class="header-left">
                    <h1>
                        <i class="fa-solid fa-file-lines"></i>
                        My Applications
                        <small>Student</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Notification bell with badge -->
                    <button class="notif-bell" onclick="alert('No new notifications')" aria-label="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <span class="notif-badge">3</span>
                    </button>

                    <!-- User profile (avatar + name + role) -->
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

            <!-- PAGE CARD (same as before) -->
            <div class="page-card">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success" style="padding: 12px 16px; border-radius: 12px; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; margin-bottom: 16px;">
                        <i class="fa-solid fa-circle-check"></i>
                        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger" style="padding: 12px 16px; border-radius: 12px; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; margin-bottom: 16px;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <div class="page-head">
                    <div>
                        <h2>Submitted Applications</h2>
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
                            <div></div>
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
                                                <i class="fa-solid fa-eye"></i> View Details
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
                        <i class="fa-solid fa-folder-open"></i>
                        <p>No applications found yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- MODAL (unchanged) -->
    <div class="modal-overlay" id="applicationModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3 id="modalTitle">Application Details</h3>
                <button type="button" class="modal-close-btn" onclick="closeApplicationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="detail-grid">
                    <div class="detail-card">
                        <label>Company</label>
                        <div class="value" id="modalCompany"></div>
                    </div>
                    <div class="detail-card">
                        <label>Supervisor</label>
                        <div class="value" id="modalSupervisor"></div>
                    </div>
                    <div class="detail-card">
                        <label>Status</label>
                        <div class="value" id="modalStatus"></div>
                    </div>
                    <div class="detail-card">
                        <label>Applied / Committed</label>
                        <div class="value" id="modalAppliedAt"></div>
                    </div>
                </div>

                <div class="detail-block">
                    <h4>Description</h4>
                    <div class="detail-text" id="modalDescription"></div>
                </div>

                <div class="detail-block">
                    <h4>Requirements</h4>
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

    <script>
        const applicationMap = <?php echo json_encode($applicationData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const modal = document.getElementById('applicationModal');
        const commitButton = document.getElementById('commitButton');
        const commitForm = document.getElementById('commitForm');
        const commitApplicationId = document.getElementById('commitApplicationId');

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
            const canCommit = data.can_commit && (modalStatus === 'accepted' || modalStatus === 'committed');

            commitButton.disabled = !canCommit;
            commitButton.innerHTML = modalStatus === 'committed'
                ? '<i class="fa-solid fa-circle-check"></i> Committed'
                : '<i class="fa-solid fa-circle-check"></i> Commit';

            if (modalStatus === 'accepted' && alreadyCommittedElsewhere && !data.can_commit) {
                commitButton.title = 'You have already committed to another job.';
            } else if (modalStatus !== 'accepted' && modalStatus !== 'committed') {
                commitButton.title = 'Only accepted applications can be committed.';
            } else {
                commitButton.title = '';
            }

            modal.style.display = 'flex';
        }

        function closeApplicationModal() {
            modal.style.display = 'none';
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeApplicationModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeApplicationModal();
            }
        });
    </script>
</body>
</html>