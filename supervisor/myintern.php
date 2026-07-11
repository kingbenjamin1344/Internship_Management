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
                <a class="nav-item " href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item " href="job.php"><i class="fa-solid fa-briefcase"></i> Add Job</a>
                <a class="nav-item " href="applicant.php"><i class="fa-solid fa-briefcase"></i> Applicants</a>
                <a class="nav-item active" href="myintern.php"><i class="fa-solid fa-briefcase"></i> My Interns</a>
            </nav>
            <div class="sidebar-footer">
                <div class="user-chip">
                    <i class="fa-solid fa-user-circle"></i>
                    <div>
                        <div class="name"><?php echo htmlspecialchars($fullname); ?></div>
                        <div class="role-label"><?php echo htmlspecialchars(getRoleDisplayName($role)); ?></div>
                    </div>
                </div>
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <main class="main-content">
            <div class="top-bar">
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