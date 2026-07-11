<?php
// supervisor/dashboard.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is supervisor
checkAccess('supervisor');
ensureInternshipTables($pdo);

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Supervisor';
$role = getUserRole();
$userId = getUserId();

$acceptedSql = '
    SELECT
        a.id AS application_id,
        COALESCE(a.committed_at, a.updated_at) AS committed_at,
        s.firstname AS student_firstname,
        s.middlename AS student_middlename,
        s.lastname AS student_lastname,
        s.suffix AS student_suffix,
        s.email AS student_email,
        j.title AS job_title,
        c.id AS company_id,
        c.company_name,
        c.address AS company_address,
        sp.firstname AS supervisor_firstname,
        sp.middlename AS supervisor_middlename,
        sp.lastname AS supervisor_lastname,
        sp.suffix AS supervisor_suffix
    FROM job_applications a
    INNER JOIN users s ON a.student_id = s.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN companies c ON j.company_id = c.id
    LEFT JOIN users sp ON sp.id = IFNULL(c.supervisor_id, j.created_by)
    WHERE a.status = "committed" AND (c.supervisor_id = ? OR j.created_by = ?)
    ORDER BY a.updated_at DESC
';

$acceptedParams = [$userId, $userId];
$acceptedStmt = $pdo->prepare($acceptedSql);
$acceptedStmt->execute($acceptedParams);
$acceptedInterns = $acceptedStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor - My Interns</title>
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

        .page-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .page-toolbar h2 {
            margin-bottom: 6px;
        }

        .toolbar-note {
            color: #64748b;
            font-size: 0.92rem;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #fff;
            margin-top: 16px;
        }

        .intern-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1120px;
        }

        .intern-table thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        .intern-table th {
            text-align: left;
            padding: 12px 14px;
            color: #64748b;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .intern-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
            color: #1e293b;
        }

        .intern-table tbody tr:hover {
            background: #f8fafc;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #dcfce7;
            color: #166534;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .muted {
            color: #64748b;
            font-size: 0.86rem;
            line-height: 1.4;
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

        @media (max-width: 720px) {
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
                <a class="nav-item" href="job.php"><i class="fa-solid fa-briefcase"></i> Add Job</a>
                <a class="nav-item" href="applicant.php"><i class="fa-solid fa-briefcase"></i> Applicants</a>
                <a class="nav-item active" href="myintern.php"><i class="fa-solid fa-briefcase"></i> My Interns</a>
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
                        <i class="fa-solid fa-users"></i>
                        My Interns
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

            <div class="page-card">
                <div class="page-toolbar">
                    <div>
                        <h2>My Interns</h2>
                        <div class="toolbar-note">Committed interns under your assigned companies and supervisor account.</div>
                    </div>
                </div>

                <?php if (count($acceptedInterns) > 0): ?>
                    <div class="table-wrap">
                        <table class="intern-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Email</th>
                                    <th>Supervisor</th>
                                    <th>Job</th>
                                    <th>Company</th>
                                    <th>Company Address</th>
                                    <th>Status</th>
                                    <th>Committed At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($acceptedInterns as $intern): ?>
                                    <?php
                                        $studentName = trim(($intern['student_firstname'] ?? '') . ' ' . ($intern['student_middlename'] ?? '') . ' ' . ($intern['student_lastname'] ?? '') . ' ' . ($intern['student_suffix'] ?? ''));
                                        $supervisorName = trim(($intern['supervisor_firstname'] ?? '') . ' ' . ($intern['supervisor_middlename'] ?? '') . ' ' . ($intern['supervisor_lastname'] ?? '') . ' ' . ($intern['supervisor_suffix'] ?? ''));
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($studentName !== '' ? $studentName : 'Unnamed Student'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($intern['student_email'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($supervisorName !== '' ? $supervisorName : 'Not assigned'); ?></td>
                                        <td><?php echo htmlspecialchars($intern['job_title'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($intern['company_name'] ?? 'N/A'); ?></td>
                                        <td><span class="muted"><?php echo htmlspecialchars($intern['company_address'] ?? 'N/A'); ?></span></td>
                                        <td><span class="status-chip"><i class="fa-solid fa-circle-check"></i> Committed</span></td>
                                        <td><?php echo htmlspecialchars(formatDate($intern['committed_at'] ?? null)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-folder-open"></i>
                        <p>No committed interns found under your supervision.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>