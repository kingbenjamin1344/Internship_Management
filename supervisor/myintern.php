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

// Handle AJAX request for fetching student DPR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_student_dpr') {
    header('Content-Type: application/json');
    $student_id = $_POST['student_id'] ?? 0;
    
    if ($student_id > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM dpr_entries 
                WHERE student_id = ? 
                ORDER BY date DESC, id DESC
            ");
            $stmt->execute([$student_id]);
            $dprEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format dates for display
            foreach ($dprEntries as &$entry) {
                $entry['date_formatted'] = date('d M Y', strtotime($entry['date']));
                $entry['time_in_formatted'] = $entry['time_in'] ? date('h:i A', strtotime($entry['time_in'])) : '—';
                $entry['time_out_formatted'] = $entry['time_out'] ? date('h:i A', strtotime($entry['time_out'])) : '—';
            }
            
            echo json_encode(['success' => true, 'data' => $dprEntries]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit;
}

$acceptedSql = '
    SELECT
        a.id AS application_id,
        a.student_id,
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

        .page-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .page-toolbar .left-section {
            flex: 1;
        }

        .page-toolbar .right-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-toolbar h2 {
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-toolbar h2 .back-btn {
            font-size: 0.8rem;
            font-weight: 500;
            color: #2563eb;
            cursor: pointer;
            background: #eef2ff;
            padding: 4px 14px;
            border-radius: 999px;
            border: none;
            transition: 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .page-toolbar h2 .back-btn:hover {
            background: #c7d2fe;
        }

        .page-toolbar h2 .back-btn.hidden {
            display: none;
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
            min-width: 950px;
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

        .status-chip.in-progress {
            background: #fef9c3;
            color: #854d0e;
        }

        .status-chip.completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-chip.pending {
            background: #f1f5f9;
            color: #475569;
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

        /* ----- View DPR Button ----- */
        .view-dpr-btn {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 6px 16px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
            white-space: nowrap;
        }

        .view-dpr-btn:hover {
            background: #1d4ed8;
            transform: scale(1.02);
        }

        /* ----- DPR Table (inside card) ----- */
        .dpr-table-wrap {
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid #eef2f7;
            background: #fff;
            margin-top: 16px;
        }

        .dpr-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .dpr-table th {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 600;
            padding: 14px 16px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .dpr-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
        }

        .dpr-table tbody tr:last-child td {
            border-bottom: none;
        }

        .dpr-table tbody tr:hover {
            background: #fafcff;
        }

        .dpr-table .date-cell {
            font-weight: 600;
            color: #0f172a;
        }

        .badge-status {
            padding: 4px 14px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-status.in-progress {
            background: #fef9c3;
            color: #854d0e;
        }

        .badge-status.completed {
            background: #dcfce7;
            color: #166534;
        }

        .badge-status.pending {
            background: #f1f5f9;
            color: #475569;
        }

        /* View buttons in DPR table */
        .view-btn {
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-family: inherit;
            border: none;
            white-space: nowrap;
        }

        .view-btn-task {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .view-btn-task:hover {
            background: #bfdbfe;
            transform: scale(1.02);
        }

        .view-btn-feedback {
            background: #eef2ff;
            color: #4338ca;
        }

        .view-btn-feedback:hover {
            background: #c7d2fe;
            transform: scale(1.02);
        }

        .view-btn.no-content {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: default;
        }

        .view-btn.no-content:hover {
            background: #f1f5f9;
            transform: none;
        }

        .dpr-empty-row td {
            padding: 32px 16px;
            text-align: center;
            color: #94a3b8;
            font-style: italic;
        }

        .dpr-empty-row td i {
            font-size: 2rem;
            display: block;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        /* ----- Loading Spinner ----- */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }

        .loading-spinner i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
            color: #2563eb;
            display: block;
            margin-bottom: 12px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-spinner.show {
            display: block;
        }

        /* Hide intern list when showing DPR */
        .intern-list.hidden {
            display: none;
        }

        .dpr-view.hidden {
            display: none;
        }

        .dpr-view {
            display: block;
        }

        /* ----- MODAL ----- */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: #fff;
            border-radius: 24px;
            max-width: 560px;
            width: 94%;
            padding: 32px 30px 28px;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.4);
            animation: slideUp 0.25s ease;
            max-height: 90vh;
            overflow-y: auto;
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
            margin-bottom: 6px;
        }

        .modal-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h2 i {
            color: #2563eb;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 8px;
            transition: 0.15s;
        }

        .modal-close:hover {
            color: #1e293b;
        }

        .modal-hint {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 22px;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            border-top: 1px solid #edf2f7;
            padding-top: 22px;
        }

        .btn-close-modal {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            padding: 10px 24px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: inherit;
        }

        .btn-close-modal:hover {
            background: #e9edf4;
        }

        /* View Content Display */
        .view-content-display {
            padding: 8px 0 4px;
        }

        .view-content-display .meta-info {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #edf2f7;
        }

        .view-content-display .meta-info .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #64748b;
        }

        .view-content-display .meta-info .meta-item strong {
            color: #0f172a;
            font-weight: 600;
        }

        .view-content-display .content-text {
            background: #f8fafc;
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 0.95rem;
            line-height: 1.7;
            color: #1e293b;
            min-height: 60px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .view-content-display .content-text.task-text {
            border-left: 4px solid #2563eb;
        }

        .view-content-display .content-text.feedback-text {
            border-left: 4px solid #7c3aed;
        }

        .view-content-display .content-text .empty-text {
            color: #94a3b8;
            font-style: italic;
        }

        /* ----- Toast ----- */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #0f172a;
            color: #f1f5f9;
            padding: 16px 24px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: none;
            align-items: center;
            gap: 12px;
            z-index: 2000;
            font-weight: 500;
            max-width: 400px;
            animation: slideUp 0.3s ease;
        }

        .toast.success {
            background: #059669;
        }

        .toast.error {
            background: #dc2626;
        }

        .toast.show {
            display: flex;
        }

        .toast i {
            font-size: 1.2rem;
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
            .page-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .page-toolbar .right-section {
                justify-content: flex-start;
            }
            .dpr-table th,
            .dpr-table td {
                padding: 10px 12px;
                font-size: 0.8rem;
            }
            .modal-card {
                padding: 24px 18px;
            }
            .view-content-display .meta-info {
                flex-direction: column;
                gap: 8px;
            }
            .view-btn {
                font-size: 0.65rem;
                padding: 4px 10px;
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
                <a class="nav-item" href="applicant.php"><i class="fa-solid fa-users"></i> Applicants</a>
                <a class="nav-item active" href="myintern.php"><i class="fa-solid fa-user-graduate"></i> My Interns</a>
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
                <!-- Toolbar -->
                <div class="page-toolbar">
                    <div class="left-section">
                        <h2>
                            <span id="pageTitle">My Interns</span>
                        </h2>
                        <div class="toolbar-note" id="pageNote">Committed interns under your assigned companies and supervisor account.</div>
                    </div>
                    <div class="right-section">
                        <button class="back-btn hidden" id="backBtn" onclick="showInternList()">
                            <i class="fa-solid fa-arrow-left"></i> Back to Interns
                        </button>
                    </div>
                </div>

                <!-- Loading Spinner -->
                <div class="loading-spinner" id="loadingSpinner">
                    <i class="fa-solid fa-spinner"></i>
                    <span>Loading DPR entries...</span>
                </div>

                <!-- Intern List View -->
                <div class="intern-list" id="internListView">
                    <?php if (count($acceptedInterns) > 0): ?>
                        <div class="table-wrap">
                            <table class="intern-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Supervisor</th>
                                        <th>Job</th>
                                        <th>Company</th>
                                        <th>Company Address</th>
                                        <th>Status</th>
                                        <th>Committed At</th>
                                        <th>Action</th>
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
                                            <td><?php echo htmlspecialchars($supervisorName !== '' ? $supervisorName : 'Not assigned'); ?></td>
                                            <td><?php echo htmlspecialchars($intern['job_title'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($intern['company_name'] ?? 'N/A'); ?></td>
                                            <td><span class="muted"><?php echo htmlspecialchars($intern['company_address'] ?? 'N/A'); ?></span></td>
                                            <td><span class="status-chip"><i class="fa-solid fa-circle-check"></i> Committed</span></td>
                                            <td><?php echo htmlspecialchars(formatDate($intern['committed_at'] ?? null)); ?></td>
                                            <td>
                                                <button class="view-dpr-btn" onclick="viewStudentDPR(<?php echo $intern['student_id']; ?>, '<?php echo addslashes($studentName); ?>')">
                                                    <i class="fa-regular fa-calendar-check"></i> View DPR
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
                            <p>No committed interns found under your supervision.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- DPR View (hidden by default) -->
                <div class="dpr-view hidden" id="dprView">
                    <div class="dpr-table-wrap">
                        <table class="dpr-table" id="dprTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th>Task Done</th>
                                    <th>Student Feedback</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="dprTableBody">
                                <!-- DPR entries will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ===== TASK DONE MODAL ===== -->
    <div class="modal-overlay" id="taskModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-regular fa-list-check"></i> Task Done</h2>
                <button class="modal-close" id="closeTaskBtn">&times;</button>
            </div>
            <div class="modal-hint">View the task details for this entry.</div>

            <div class="view-content-display">
                <div class="meta-info">
                    <div class="meta-item">
                        <i class="fa-regular fa-calendar"></i>
                        <strong>Date:</strong> <span id="taskDate">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-regular fa-user"></i>
                        <strong>Student:</strong> <span id="taskStudent">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-regular fa-clock"></i>
                        <strong>Time:</strong> <span id="taskTime">—</span>
                    </div>
                </div>
                <div class="content-text task-text" id="taskTextDisplay">
                    <span class="empty-text">No task description provided.</span>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn-close-modal" id="closeTaskBtn2">Close</button>
            </div>
        </div>
    </div>

    <!-- ===== STUDENT FEEDBACK MODAL ===== -->
    <div class="modal-overlay" id="feedbackModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-regular fa-comment"></i> Student Feedback</h2>
                <button class="modal-close" id="closeFeedbackBtn">&times;</button>
            </div>
            <div class="modal-hint">View the feedback details for this entry.</div>

            <div class="view-content-display">
                <div class="meta-info">
                    <div class="meta-item">
                        <i class="fa-regular fa-calendar"></i>
                        <strong>Date:</strong> <span id="feedbackDate">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-regular fa-user"></i>
                        <strong>Student:</strong> <span id="feedbackStudent">—</span>
                    </div>
                </div>
                <div class="content-text feedback-text" id="feedbackTextDisplay">
                    <span class="empty-text">No feedback provided.</span>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn-close-modal" id="closeFeedbackBtn2">Close</button>
            </div>
        </div>
    </div>

    <!-- ===== TOAST ===== -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <script>
        // ----- Toast notification -----
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

        // ----- View Student DPR -----
        let currentStudentName = '';

        function viewStudentDPR(studentId, studentName) {
            currentStudentName = studentName;
            
            // Show loading
            document.getElementById('loadingSpinner').classList.add('show');
            document.getElementById('dprView').classList.add('hidden');
            
            // Hide intern list
            document.getElementById('internListView').classList.add('hidden');
            
            // Update page title and note
            document.getElementById('pageTitle').textContent = 'DPR: ' + studentName;
            document.getElementById('pageNote').textContent = 'Daily Progress Reports for ' + studentName;
            
            // Show back button
            document.getElementById('backBtn').classList.remove('hidden');

            // Fetch DPR data
            const formData = new FormData();
            formData.append('action', 'get_student_dpr');
            formData.append('student_id', studentId);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('loadingSpinner').classList.remove('show');
                
                if (data.success) {
                    renderDPRTable(data.data, studentName);
                    document.getElementById('dprView').classList.remove('hidden');
                } else {
                    showToast(data.message || 'Failed to load DPR entries.', 'error');
                    renderDPRTable([], studentName);
                    document.getElementById('dprView').classList.remove('hidden');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('loadingSpinner').classList.remove('show');
                showToast('An error occurred. Please try again.', 'error');
                renderDPRTable([], studentName);
                document.getElementById('dprView').classList.remove('hidden');
            });
        }

        // ----- Render DPR Table -----
        function renderDPRTable(entries, studentName) {
            const tbody = document.getElementById('dprTableBody');
            
            if (!entries || entries.length === 0) {
                tbody.innerHTML = `
                    <tr class="dpr-empty-row">
                        <td colspan="6">
                            <i class="fa-regular fa-calendar-circle-plus"></i>
                            No DPR entries found for this student.<br>
                            <span style="font-size:0.85rem; color:#cbd5e1;">The student hasn't submitted any progress reports yet.</span>
                        </td>
                    </tr>
                `;
                return;
            }

            let html = '';
            entries.forEach(entry => {
                const statusClass = entry.status.toLowerCase().replace(' ', '-');
                const hasTask = entry.tasks && entry.tasks.trim() !== '';
                const hasFeedback = entry.feedback && entry.feedback.trim() !== '';
                const timeStr = entry.time_in_formatted && entry.time_out_formatted ? 
                    entry.time_in_formatted + ' - ' + entry.time_out_formatted : 
                    (entry.time_in_formatted || '—');
                
                // Escape data for JavaScript
                const taskText = entry.tasks || '';
                const dateText = entry.date_formatted || entry.date || '';
                const timeText = timeStr || '';
                
                html += `
                    <tr>
                        <td class="date-cell">${escapeHtml(entry.date_formatted || entry.date)}</td>
                        <td>${escapeHtml(entry.time_in_formatted || entry.time_in || '—')}</td>
                        <td>${escapeHtml(entry.time_out_formatted || entry.time_out || '—')}</td>
                        <td>
                            <button class="view-btn view-btn-task ${hasTask ? '' : 'no-content'}" 
                                    onclick="viewTask('${escapeJs(taskText)}', '${escapeJs(dateText)}', '${escapeJs(studentName)}', '${escapeJs(timeText)}')"
                                    ${hasTask ? '' : 'disabled'}>
                                <i class="fa-regular fa-list-check"></i>
                                ${hasTask ? 'View Task' : 'No Task'}
                            </button>
                        </td>
                        <td>
                            <button class="view-btn view-btn-feedback ${hasFeedback ? '' : 'no-content'}" 
                                    onclick="viewFeedback('${escapeJs(entry.feedback || '')}', '${escapeJs(dateText)}', '${escapeJs(studentName)}')"
                                    ${hasFeedback ? '' : 'disabled'}>
                                <i class="fa-regular fa-comment"></i>
                                ${hasFeedback ? 'View Feedback' : 'No Feedback'}
                            </button>
                        </td>
                        <td>
                            <span class="badge-status ${statusClass}">
                                ${escapeHtml(entry.status)}
                            </span>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        // ----- Escape for JavaScript (to prevent breaking quotes) -----
        function escapeJs(text) {
            if (!text) return '';
            return String(text).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\r/g, '\\r');
        }

        // ----- View Task Modal -----
        function viewTask(task, date, studentName, timeStr) {
            // Debug - log to console to check if function is called
            console.log('viewTask called', { task, date, studentName, timeStr });
            
            // Get modal elements
            const modal = document.getElementById('taskModal');
            const taskDate = document.getElementById('taskDate');
            const taskStudent = document.getElementById('taskStudent');
            const taskTime = document.getElementById('taskTime');
            const taskTextDisplay = document.getElementById('taskTextDisplay');
            
            // Check if modal exists
            if (!modal) {
                console.error('Task modal not found!');
                return;
            }
            
            // Set values
            taskDate.textContent = date || '—';
            taskStudent.textContent = studentName || '—';
            taskTime.textContent = timeStr || '—';
            
            if (task && task.trim() !== '') {
                taskTextDisplay.textContent = task;
                taskTextDisplay.className = 'content-text task-text';
            } else {
                taskTextDisplay.innerHTML = '<span class="empty-text">No task description provided.</span>';
                taskTextDisplay.className = 'content-text task-text';
            }
            
            // Show modal
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // ----- View Feedback Modal -----
        function viewFeedback(feedback, date, studentName) {
            console.log('viewFeedback called', { feedback, date, studentName });
            
            const modal = document.getElementById('feedbackModal');
            if (!modal) {
                console.error('Feedback modal not found!');
                return;
            }
            
            document.getElementById('feedbackDate').textContent = date || '—';
            document.getElementById('feedbackStudent').textContent = studentName || '—';
            
            if (feedback && feedback.trim() !== '') {
                document.getElementById('feedbackTextDisplay').textContent = feedback;
                document.getElementById('feedbackTextDisplay').className = 'content-text feedback-text';
            } else {
                document.getElementById('feedbackTextDisplay').innerHTML = '<span class="empty-text">No feedback provided.</span>';
                document.getElementById('feedbackTextDisplay').className = 'content-text feedback-text';
            }
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // ----- Close modals -----
        function closeTaskModal() {
            const modal = document.getElementById('taskModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function closeFeedbackModal() {
            const modal = document.getElementById('feedbackModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        document.getElementById('closeTaskBtn').addEventListener('click', closeTaskModal);
        document.getElementById('closeTaskBtn2').addEventListener('click', closeTaskModal);

        document.getElementById('closeFeedbackBtn').addEventListener('click', closeFeedbackModal);
        document.getElementById('closeFeedbackBtn2').addEventListener('click', closeFeedbackModal);

        // Close modals on overlay click
        document.getElementById('taskModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTaskModal();
            }
        });

        document.getElementById('feedbackModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeFeedbackModal();
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('taskModal').classList.contains('active')) {
                    closeTaskModal();
                }
                if (document.getElementById('feedbackModal').classList.contains('active')) {
                    closeFeedbackModal();
                }
            }
        });

        // ----- Escape HTML -----
        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/[&<>"]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                if (m === '"') return '&quot;';
                return m;
            });
        }

        // ----- Show Intern List (Back) -----
        function showInternList() {
            document.getElementById('internListView').classList.remove('hidden');
            document.getElementById('dprView').classList.add('hidden');
            document.getElementById('loadingSpinner').classList.remove('show');
            
            // Reset page title and note
            document.getElementById('pageTitle').textContent = 'My Interns';
            document.getElementById('pageNote').textContent = 'Committed interns under your assigned companies and supervisor account.';
            
            // Hide back button
            document.getElementById('backBtn').classList.add('hidden');
        }

        // Toast click to dismiss
        document.getElementById('toast').addEventListener('click', function() {
            this.classList.remove('show');
        });

        // ----- Debug helper - log when page loads -----
        console.log('Page loaded. Task modal ID exists:', !!document.getElementById('taskModal'));
        console.log('Feedback modal ID exists:', !!document.getElementById('feedbackModal'));
    </script>
</body>
</html>