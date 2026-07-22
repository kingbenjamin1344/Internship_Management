<?php
// coordinator/dashboard.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is coordinator
checkAccess('coordinator');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Coordinator';
$role = getUserRole();

global $pdo;
ensureInternshipTables($pdo);

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$companyFilter = isset($_GET['company']) ? trim($_GET['company']) : '';
$supervisorFilter = isset($_GET['supervisor']) ? trim($_GET['supervisor']) : '';

// Build the query with filters
$sql = "SELECT
    a.id AS application_id,
    COALESCE(a.committed_at, a.updated_at) AS committed_at,
    s.firstname AS student_firstname,
    s.middlename AS student_middlename,
    s.lastname AS student_lastname,
    s.suffix AS student_suffix,
    s.email AS student_email,
    j.title AS job_title,
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
WHERE a.status = 'committed'";

$params = [];

// Add search filter for student name
if (!empty($search)) {
    $sql .= " AND (s.firstname LIKE ? OR s.lastname LIKE ? OR s.email LIKE ? OR CONCAT(s.firstname, ' ', s.lastname) LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

// Add company filter
if (!empty($companyFilter)) {
    $sql .= " AND c.company_name = ?";
    $params[] = $companyFilter;
}

// Add supervisor filter
if (!empty($supervisorFilter)) {
    $sql .= " AND CONCAT(sp.firstname, ' ', sp.lastname) = ?";
    $params[] = $supervisorFilter;
}

$sql .= " ORDER BY a.updated_at DESC";

$acceptedStmt = $pdo->prepare($sql);
$acceptedStmt->execute($params);
$acceptedInterns = $acceptedStmt->fetchAll();

// Get unique companies and supervisors for filter dropdowns
$companyStmt = $pdo->query("SELECT DISTINCT company_name FROM companies ORDER BY company_name");
$companies = $companyStmt->fetchAll();

$supervisorStmt = $pdo->query("
    SELECT DISTINCT CONCAT(u.firstname, ' ', u.lastname) AS supervisor_name
    FROM users u
    WHERE u.role = 'supervisor'
    ORDER BY supervisor_name
");
$supervisors = $supervisorStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinator Dashboard</title>
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

    /* ADD THESE */
    position: sticky;
    top: 0;
    z-index: 200;
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

        .intern-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .intern-header h3 {
            margin: 0;
            color: #0f172a;
            font-size: 1.15rem;
        }

        .intern-count {
            background: #e0f2fe;
            color: #075985;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #ffffff;
        }

        .intern-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1060px;
            font-size: 0.9rem;
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
            letter-spacing: 0.3px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .intern-table td {
            padding: 12px 14px;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        .intern-table tbody tr:hover {
            background: #f8fafc;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 4px 10px;
            background: #dcfce7;
            color: #166534;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .muted {
            color: #64748b;
            font-size: 0.84rem;
        }

        .empty-state {
            border: 2px dashed #dbe3ef;
            border-radius: 16px;
            padding: 38px 20px;
            text-align: center;
            color: #64748b;
            background: #f8fafc;
        }

        .empty-state i {
            font-size: 2rem;
            color: #94a3b8;
            margin-bottom: 10px;
        }

        /* Filter styles */
        .filter-section {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 16px 24px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .filter-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
        }

        .filter-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            white-space: nowrap;
        }

        .search-wrapper {
            display: flex;
            align-items: center;
            position: relative;
        }

        .filter-input {
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px 0 0 8px;
            font-size: 0.9rem;
            background: white;
            width: 280px;
            transition: all 0.2s;
        }

        .filter-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn-search {
            padding: 8px 16px;
            background: #3b82f6;
            color: white;
            border: 1px solid #3b82f6;
            border-radius: 0 8px 8px 0;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-search:hover {
            background: #2563eb;
            border-color: #2563eb;
        }

        .filter-select {
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.9rem;
            background: white;
            min-width: 170px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-select:hover {
            border-color: #94a3b8;
        }

        .filter-divider {
            width: 1px;
            height: 35px;
            background: #cbd5e1;
            margin: 0 4px;
        }

        .filter-results {
            font-size: 0.85rem;
            color: #64748b;
            padding: 8px 0;
            margin-bottom: 12px;
        }

        @media (max-width: 1024px) {
            .filter-section {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }
            
            .filter-left {
                flex-direction: column;
                align-items: stretch;
                width: 100%;
            }
            
            .filter-right {
                flex-direction: column;
                align-items: stretch;
                width: 100%;
            }
            
            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-select {
                min-width: 100%;
            }
            
            .filter-divider {
                display: none;
            }
            
            .search-wrapper {
                width: 100%;
            }
            
            .filter-input {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .filter-section {
                padding: 12px 16px;
            }
            
            .filter-left {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn-search {
                border-radius: 0 8px 8px 0;
            }
            
            .filter-right {
                gap: 8px;
            }
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
                <i class="fa-solid fa-users-gear"></i>
                <h2>System<span>Coordinator Desk</span></h2>
            </div>
            <nav class="nav-section">
                <a class="nav-item" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item" href="company.php"><i class="fa-solid fa-building"></i> Company </a>
                <a class="nav-item active" href="intern.php"><i class="fa-solid fa-business-time"></i> Internship </a>
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
                        <i class="fa-solid fa-business-time"></i>
                        Internship Management
                        <small>Coordinator</small>
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
                <div class="intern-header">
                    <div>
                        <h3><i class="fa-solid fa-user-check"></i> Committed Internship Placements</h3>
                        <p style="margin-top: 6px; color: #64748b; font-size: 0.9rem;">
                            Students who committed to a job, including assigned job and company.
                        </p>
                    </div>
                    <div class="intern-count">
                        <?php echo count($acceptedInterns); ?> Committed
                    </div>
                </div>

                <!-- Filter Section -->
                <form method="GET" action="" class="filter-section" id="filterForm">
                    <!-- Left side: Search with magnifying glass button -->
                    <div class="filter-left">
                        <div class="search-wrapper">
                            <input 
                                type="text" 
                                id="search" 
                                name="search" 
                                class="filter-input" 
                                placeholder="Search student by name or email..."
                                value="<?php echo htmlspecialchars($search); ?>"
                            >
                            <button type="submit" class="btn-search">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Divider -->
                    <div class="filter-divider"></div>

                    <!-- Right side: Supervisor filter only -->
                    <div class="filter-right">
                        <div class="filter-group">
                            <select id="supervisor" name="supervisor" class="filter-select" onchange="this.form.submit()">
                                <option value="">All Supervisors</option>
                                <?php foreach ($supervisors as $supervisor): ?>
                                    <option value="<?php echo htmlspecialchars($supervisor['supervisor_name']); ?>"
                                        <?php echo $supervisorFilter === $supervisor['supervisor_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supervisor['supervisor_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>

                <!-- Filter results info -->
                <?php if (!empty($search) || !empty($companyFilter) || !empty($supervisorFilter)): ?>
                    <div class="filter-results">
                        <i class="fa-solid fa-info-circle"></i>
                        Showing filtered results 
                        <?php if (!empty($search)): ?>
                            for "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        <?php endif; ?>
                        <?php if (!empty($companyFilter)): ?>
                            <?php if (!empty($search)): ?>, <?php endif; ?>
                            in company "<strong><?php echo htmlspecialchars($companyFilter); ?></strong>"
                        <?php endif; ?>
                        <?php if (!empty($supervisorFilter)): ?>
                            <?php if (!empty($search) || !empty($companyFilter)): ?>, <?php endif; ?>
                            with supervisor "<strong><?php echo htmlspecialchars($supervisorFilter); ?></strong>"
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

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
                                        <td>
                                            <strong><?php echo htmlspecialchars($studentName !== '' ? $studentName : 'Unnamed Student'); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($intern['student_email'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($supervisorName !== '' ? $supervisorName : 'Not assigned'); ?></td>
                                        <td><?php echo htmlspecialchars($intern['job_title'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($intern['company_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="muted"><?php echo htmlspecialchars($intern['company_address'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <span class="status-chip"><i class="fa-solid fa-circle-check"></i> Committed</span>
                                        </td>
                                        <td><?php echo htmlspecialchars(formatDate($intern['committed_at'] ?? null)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-folder-open"></i>
                        <p>No committed internship placements found matching your criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>