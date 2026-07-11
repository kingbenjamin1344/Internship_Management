<?php
// admin/dashboard.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is admin
checkAccess('admin');

// Get user data
$userId = getUserId();
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin';
$role = getUserRole();

// Get statistics
$stats = [];
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
$stats['total'] = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
$roleStats = $stmt->fetchAll();

$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM users GROUP BY status");
$statusStats = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard</title>
    <!-- Font Awesome 6 (free) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        /* ----- reset & base ----- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f4f8;
            min-height: 100vh;
            display: flex;
        }

        /* ----- SIDEBAR (modern) ----- */
        .sidebar {
            width: 280px;
            background: #0b1a2e;
            color: #e2e8f0;
            padding: 28px 20px;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            flex-shrink: 0;
            border-right: 1px solid rgba(255, 255, 255, 0.04);
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.03);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 28px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            margin-bottom: 26px;
        }

        .sidebar-brand i {
            font-size: 26px;
            color: #60a5fa;
            background: rgba(96, 165, 250, 0.12);
            padding: 10px;
            border-radius: 14px;
        }

        .sidebar-brand h2 {
            font-weight: 600;
            font-size: 22px;
            letter-spacing: -0.3px;
            color: #ffffff;
        }

        .sidebar-brand h2 span {
            color: #94a3b8;
            font-weight: 400;
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
            gap: 14px;
            padding: 12px 16px;
            border-radius: 14px;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 15px;
            transition: all 0.15s ease;
        }

        .nav-item i {
            width: 22px;
            font-size: 17px;
            text-align: center;
            color: #64748b;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }

        .nav-item:hover i {
            color: #93bbfc;
        }

        .nav-item.active {
            background: rgba(96, 165, 250, 0.13);
            color: #ffffff;
            box-shadow: inset 3px 0 0 #60a5fa;
        }

        .nav-item.active i {
            color: #60a5fa;
        }

        .nav-item .badge-pill {
            margin-left: auto;
            background: #ef4444;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 30px;
        }

        .sidebar-footer {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.03);
            padding: 12px 14px;
            border-radius: 40px;
        }

        .user-chip i {
            font-size: 20px;
            color: #94a3b8;
        }

        .user-chip .name {
            font-weight: 500;
            color: #ffffff;
            font-size: 14px;
        }

        .user-chip .role-label {
            color: #94a3b8;
            font-size: 12px;
        }

        .logout-btn-side {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 40px;
            color: #f87171;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: 0.15s;
            border: 1px solid transparent;
        }

        .logout-btn-side:hover {
            background: rgba(248, 113, 113, 0.08);
            border-color: rgba(248, 113, 113, 0.2);
            color: #fca5a5;
        }

        /* ----- MAIN CONTENT ----- */
        .main-content {
            flex: 1;
            padding: 28px 36px 40px;
            background: #f0f4f8;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .top-bar h1 {
            font-size: 28px;
            font-weight: 600;
            color: #0f172a;
            letter-spacing: -0.4px;
        }

        .top-bar h1 i {
            color: #2563eb;
            margin-right: 10px;
        }

        .date-badge {
            background: #ffffff;
            padding: 8px 20px;
            border-radius: 60px;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
        }

        /* stats grid - enhanced modern cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: #ffffff;
            padding: 24px 22px;
            border-radius: 20px;
            border: 1px solid rgba(226, 232, 240, 0.6);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02), 0 8px 24px rgba(0, 0, 0, 0.03);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #2563eb, #60a5fa);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            border-color: #cbd5e1;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.06);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-card .stat-label {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-card .stat-label i {
            font-size: 16px;
            width: 20px;
            text-align: center;
        }

        .stat-card .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 8px;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }

        .stat-card .stat-sub {
            font-size: 13px;
            color: #64748b;
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px 10px;
            border-top: 1px solid #f1f5f9;
            padding-top: 10px;
        }

        .stat-card .stat-sub span {
            background: #f8fafc;
            padding: 3px 14px;
            border-radius: 40px;
            font-weight: 500;
            font-size: 12px;
            color: #475569;
        }

        .stat-card .stat-sub .role-badge {
            background: #dbeafe;
            color: #1e40af;
        }

        .stat-card .stat-sub .status-badge {
            background: #f1f5f9;
        }

        .stat-card .stat-sub .status-badge.pending {
            background: #fef3c7;
            color: #b45309;
        }

        .stat-card .stat-sub .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }

        .stat-card .stat-sub .status-badge.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        /* stat card color accents */
        .stat-card.accent-blue .stat-label i { color: #2563eb; }
        .stat-card.accent-blue .stat-number { color: #1e40af; }
        .stat-card.accent-purple .stat-label i { color: #7c3aed; }
        .stat-card.accent-purple .stat-number { color: #5b21b6; }
        .stat-card.accent-green .stat-label i { color: #16a34a; }
        .stat-card.accent-green .stat-number { color: #065f46; }
        .stat-card.accent-orange .stat-label i { color: #d97706; }
        .stat-card.accent-orange .stat-number { color: #92400e; }
        .stat-card.accent-rose .stat-label i { color: #dc2626; }
        .stat-card.accent-rose .stat-number { color: #991b1b; }
        .stat-card.accent-teal .stat-label i { color: #0d9488; }
        .stat-card.accent-teal .stat-number { color: #115e59; }

        /* section titles */
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #0f172a;
            margin: 32px 0 18px 0;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #2563eb;
        }

        /* quick actions - enhanced cards */
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-top: 6px;
        }

        .action-card {
            background: #ffffff;
            padding: 32px 24px 28px;
            border-radius: 20px;
            border: 1px solid rgba(226, 232, 240, 0.6);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02), 0 8px 24px rgba(0, 0, 0, 0.03);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .action-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 3px;
            background: linear-gradient(90deg, #2563eb, #7c3aed);
            transition: width 0.4s ease;
            border-radius: 2px;
        }

        .action-card:hover {
            transform: translateY(-6px);
            border-color: #cbd5e1;
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.07);
        }

        .action-card:hover::after {
            width: 60%;
        }

        .action-card i {
            font-size: 34px;
            color: #2563eb;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            padding: 18px;
            border-radius: 16px;
            margin-bottom: 18px;
            transition: all 0.3s ease;
            display: inline-block;
        }

        .action-card:hover i {
            transform: scale(1.05) rotate(-2deg);
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        }

        .action-card h3 {
            font-weight: 600;
            font-size: 18px;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .action-card p {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .action-card .btn-outline {
            display: inline-block;
            padding: 10px 28px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 14px;
            background: transparent;
            border: 1.5px solid #2563eb;
            color: #2563eb;
            text-decoration: none;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .action-card .btn-outline::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: #2563eb;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: all 0.4s ease;
            z-index: -1;
        }

        .action-card .btn-outline:hover {
            color: #ffffff;
            border-color: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.25);
        }

        .action-card .btn-outline:hover::before {
            width: 300px;
            height: 300px;
        }

        .action-card .btn-outline i {
            background: transparent;
            padding: 0;
            margin: 0 6px 0 0;
            font-size: 14px;
            color: inherit;
            transform: none;
        }

        .action-card .btn-outline:hover i {
            transform: none;
            background: transparent;
        }

        /* ----- USER MANAGEMENT TABLE (enhanced) ----- */
        .user-table-wrap {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid rgba(226, 232, 240, 0.6);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02), 0 8px 24px rgba(0, 0, 0, 0.03);
            margin-top: 8px;
            transition: all 0.3s ease;
        }

        .user-table-wrap:hover {
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 22px 28px;
            border-bottom: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 12px;
        }

        .table-header h3 {
            font-weight: 600;
            color: #0f172a;
            font-size: 18px;
        }

        .table-header .search-box {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border-radius: 60px;
            padding: 4px 6px 4px 18px;
            border: 1px solid #e2e8f0;
            transition: all 0.25s ease;
        }

        .table-header .search-box:focus-within {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
        }

        .table-header .search-box input {
            border: none;
            background: transparent;
            padding: 8px 0;
            font-size: 14px;
            outline: none;
            width: 190px;
            color: #0f172a;
        }

        .table-header .search-box input::placeholder {
            color: #94a3b8;
        }

        .table-header .search-box i {
            color: #94a3b8;
            padding: 8px 12px;
        }

        .table-scroll {
            overflow-x: auto;
            padding: 0 8px 8px 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th {
            text-align: left;
            padding: 16px 20px;
            color: #64748b;
            font-weight: 600;
            background: #fafcff;
            border-bottom: 1px solid #e9edf2;
            letter-spacing: 0.3px;
            font-size: 12px;
            text-transform: uppercase;
        }

        td {
            padding: 14px 20px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr {
            transition: background 0.15s ease;
        }

        tr:hover td {
            background: #fafcff;
        }

        .user-avatar {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar-icon {
            width: 38px;
            height: 38px;
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            background: #dbeafe;
            color: #1e40af;
            flex-shrink: 0;
        }

        .badge-status {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 60px;
            font-weight: 500;
            font-size: 12px;
            text-transform: capitalize;
        }

        .badge-status.active {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-status.pending {
            background: #fef3c7;
            color: #b45309;
        }

        .badge-status.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-role {
            background: #f1f5f9;
            padding: 4px 14px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 500;
            color: #334155;
        }

        .action-icons a {
            color: #94a3b8;
            margin: 0 6px;
            transition: all 0.2s ease;
            font-size: 15px;
            display: inline-block;
            padding: 4px;
            border-radius: 8px;
        }

        .action-icons a:hover {
            color: #2563eb;
            background: #eff6ff;
            transform: scale(1.1);
        }

        .action-icons a.danger:hover {
            color: #dc2626;
            background: #fee2e2;
        }

        .footer-note {
            margin-top: 28px;
            font-size: 14px;
            color: #94a3b8;
            text-align: right;
            border-top: 1px solid #e2e8f0;
            padding-top: 18px;
        }

        /* ----- RESPONSIVE ----- */
        @media (max-width: 900px) {
            .sidebar {
                width: 220px;
                padding: 20px 14px;
            }
            .main-content {
                padding: 24px 20px;
            }
        }

        @media (max-width: 720px) {
            body {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding: 16px 20px;
                flex-direction: row;
                flex-wrap: wrap;
                align-items: center;
                gap: 12px 20px;
                border-right: none;
                border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            }
            .sidebar-brand {
                padding-bottom: 0;
                border-bottom: none;
                margin-bottom: 0;
                flex: 1;
            }
            .nav-section {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 4px;
                flex: 1 1 100%;
            }
            .nav-item {
                padding: 8px 14px;
                font-size: 14px;
                border-radius: 30px;
            }
            .sidebar-footer {
                flex-direction: row;
                border-top: none;
                padding-top: 0;
                margin-top: 0;
                flex-wrap: wrap;
                gap: 8px;
            }
            .logout-btn-side {
                padding: 8px 16px;
            }
            .user-chip {
                padding: 6px 14px;
            }
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            .quick-grid {
                grid-template-columns: 1fr;
            }
            .top-bar h1 {
                font-size: 22px;
            }
            .table-header {
                flex-direction: column;
                align-items: stretch;
            }
            .table-header .search-box input {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .main-content {
                padding: 16px 12px;
            }
        }
    </style>
</head>
<body>

  <aside class="sidebar">
           <div class="sidebar-brand">
            <i class="fas fa-shield-alt"></i>
         
        </div>
            <nav class="nav-section">
                <a class="nav-item active" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item " href="user_management.php"><i class="fa-solid fa-users-gear"></i> User Management</a>
            </nav>
            <div class="sidebar-footer">
                <div class="user-chip">
                    <i class="fa-solid fa-user-circle"></i>
                    <div>
                        <div class="name"><?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username']); ?></div>
                        <div class="role-label">Administrator</div>
                    </div>
                </div>
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <!-- top bar -->
        <div class="top-bar">
        </div>

        <!-- stats grid with enhanced cards -->
        <div class="stats-grid">
            <div class="stat-card accent-blue">
                <div class="stat-label"><i class="fas fa-users"></i> Total Users</div>
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-sub"></div>
            </div>

            <?php 
            $accentClasses = ['accent-purple', 'accent-teal', 'accent-orange'];
            $idx = 0;
            foreach ($roleStats as $roleStat): 
                $accent = $accentClasses[$idx % count($accentClasses)];
                $icons = ['fas fa-user-tie', 'fas fa-user-edit', 'fas fa-user', 'fas fa-user-graduate'];
                $icon = $icons[$idx % count($icons)];
            ?>
                <div class="stat-card <?php echo $accent; ?>">
                    <div class="stat-label"><i class="<?php echo $icon; ?>"></i> <?php echo ucfirst($roleStat['role']); ?>s</div>
                    <div class="stat-number"><?php echo $roleStat['count']; ?></div>
                    <div class="stat-sub"><span class="role-badge"><?php echo ucfirst($roleStat['role']); ?></span></div>
                </div>
            <?php 
                $idx++;
            endforeach; 
            ?>

            <?php foreach ($statusStats as $statusStat): 
                $statusClass = $statusStat['status'];
                $colorMap = [
                    'active' => 'accent-green',
                    'pending' => 'accent-orange',
                    'inactive' => 'accent-rose'
                ];
                $accent = $colorMap[$statusClass] ?? 'accent-blue';
                $iconMap = [
                    'active' => 'fa-check-circle',
                    'pending' => 'fa-hourglass-half',
                    'inactive' => 'fa-times-circle'
                ];
                $icon = $iconMap[$statusClass] ?? 'fa-circle';
            ?>
                <div class="stat-card <?php echo $accent; ?>">
                    <div class="stat-label"><i class="fas <?php echo $icon; ?>"></i> <?php echo ucfirst($statusStat['status']); ?></div>
                    <div class="stat-number"><?php echo $statusStat['count']; ?></div>
                    <div class="stat-sub">
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo $statusStat['status']; ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

       

    </main>

</body>
</html>