<?php
// student/dpr.php
// Full working code with database integration and modal functionality
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';

// Include database configuration from config folder
require_once __DIR__ . '/../config/database.php';

// Check if user is student
checkAccess('student');

$username = $_SESSION['username'] ?? 'Student';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Student';
$role = getUserRole();
$student_id = $_SESSION['user_id'] ?? 0;

// Handle AJAX requests for adding DPR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'add_dpr') {
        $date = $_POST['date'] ?? date('Y-m-d');
        $time_in = $_POST['time_in'] ?? null;
        $time_out = $_POST['time_out'] ?? null;
        $tasks = $_POST['tasks'] ?? '';
        $feedback = $_POST['feedback'] ?? '';
        $status = $_POST['status'] ?? 'In Progress';
        
        // Validate
        if (empty($tasks)) {
            echo json_encode(['success' => false, 'message' => 'Task description is required']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO dpr_entries (student_id, date, time_in, time_out, tasks, feedback, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$student_id, $date, $time_in, $time_out, $tasks, $feedback, $status]);
            
            echo json_encode(['success' => true, 'message' => 'DPR added successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'update_status') {
        $id = $_POST['id'] ?? 0;
        $status = $_POST['status'] ?? '';
        
        try {
            $stmt = $pdo->prepare("UPDATE dpr_entries SET status = ? WHERE id = ? AND student_id = ?");
            $stmt->execute([$status, $id, $student_id]);
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false]);
        }
        exit;
    }
}

// Get filter parameters
$filter_date = $_GET['filter_date'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

// Fetch DPR entries from database with filters
$dprEntries = [];
try {
    if ($student_id > 0) {
        $sql = "SELECT * FROM dpr_entries WHERE student_id = ?";
        $params = [$student_id];
        
        if (!empty($filter_date)) {
            $sql .= " AND date = ?";
            $params[] = $filter_date;
        }
        
        if (!empty($filter_status)) {
            $sql .= " AND status = ?";
            $params[] = $filter_status;
        }
        
        $sql .= " ORDER BY date DESC, id DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $dprEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Table') !== false) {
        $dprEntries = [];
        $tableError = 'DPR table not found. Please run the database setup script.';
    } else {
        $dprEntries = [];
    }
}

// Get distinct dates from the database for the filter dropdown
$availableDates = [];
try {
    if ($student_id > 0) {
        $stmt = $pdo->prepare("SELECT DISTINCT date FROM dpr_entries WHERE student_id = ? ORDER BY date DESC");
        $stmt->execute([$student_id]);
        $availableDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    $availableDates = [];
}

// Format dates for display (d M Y)
function formatDateDisplay($date) {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    return date('d M Y', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Progress Report · DPR</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <!-- Include jsPDF for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
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

        .page-card h2 {
            font-size: 1.3rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-card h2 i {
            color: #2563eb;
        }

        .page-card p.sub {
            color: #64748b;
            font-size: 0.95rem;
            margin-bottom: 20px;
        }

        /* ----- TABLE CONTROLS ----- */
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 16px;
            border: 1px solid #eef2f7;
        }

        .controls-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .controls-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 4px;
        }

        .filter-item {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #fff;
            padding: 4px 12px 4px 16px;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            transition: 0.2s;
        }

        .filter-item:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-item i {
            color: #94a3b8;
            font-size: 0.8rem;
        }

        .filter-item select {
            border: none;
            padding: 8px 4px;
            font-size: 0.85rem;
            background: transparent;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: #0a1628;
            min-width: 130px;
            cursor: pointer;
        }

        .filter-item select:focus {
            outline: none;
        }

        .btn-sm {
            padding: 8px 18px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.82rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-sm-primary {
            background: #0f172a;
            color: #fff;
        }

        .btn-sm-primary:hover {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .btn-sm-success {
            background: #059669;
            color: #fff;
        }

        .btn-sm-success:hover {
            background: #047857;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2);
        }

        .btn-sm-filter {
            background: #2563eb;
            color: #fff;
        }

        .btn-sm-filter:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }

        .btn-sm-reset {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        .btn-sm-reset:hover {
            background: #e9edf4;
            color: #0f172a;
        }

        /* ----- DPR TABLE ----- */
        .dpr-table-wrap {
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid #eef2f7;
            background: #fff;
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

        /* View buttons */
        .view-btn {
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
            border: none;
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

        .empty-row td {
            padding: 32px 16px;
            text-align: center;
            color: #94a3b8;
            font-style: italic;
        }

        .empty-row td i {
            font-size: 2rem;
            display: block;
            margin-bottom: 12px;
            color: #cbd5e1;
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
            font-size: 1.4rem;
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

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 14px;
            font-size: 0.95rem;
            background: #fafcff;
            transition: 0.15s;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .form-row .form-group {
            flex: 1;
            min-width: 120px;
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

        .btn-submit {
            background: #0f172a;
            border: none;
            color: #fff;
            padding: 10px 28px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        /* ----- VIEW CONTENT MODAL ----- */
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

        /* ----- TOAST ----- */
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

        .alert-box {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-box i {
            font-size: 1.2rem;
        }

        /* ----- RESPONSIVE ----- */
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
            .page-card {
                padding: 16px;
            }
            .dpr-table th,
            .dpr-table td {
                padding: 10px 12px;
                font-size: 0.8rem;
            }
            .modal-card {
                padding: 24px 18px;
            }
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            .table-controls {
                flex-direction: column;
                align-items: stretch;
            }
            .controls-left,
            .controls-right {
                justify-content: center;
            }
            .filter-item select {
                min-width: 100px;
            }
            .view-btn {
                font-size: 0.65rem;
                padding: 4px 10px;
            }
        }

        @media (max-width: 600px) {
            .controls-left,
            .controls-right {
                flex-wrap: wrap;
            }
            .filter-item {
                flex: 1;
                min-width: 140px;
            }
            .filter-item select {
                min-width: 80px;
                width: 100%;
            }
            .view-content-display .meta-info {
                flex-direction: column;
                gap: 8px;
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
                <a class="nav-item" href="apply.php"><i class="fa-solid fa-briefcase"></i> Apply Job</a>
                <a class="nav-item" href="applications.php"><i class="fa-solid fa-file-lines"></i> My Applications</a>
                <a class="nav-item active" href="dpr.php"><i class="fa-regular fa-calendar-check"></i> Daily Progress Report</a>
            </nav>
            <div class="sidebar-footer">
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <!-- Main Workspace -->
        <main class="main-content">
            <!-- TOP HEADER -->
            <div class="top-header">
                <div class="header-left">
                    <h1>
                        <i class="fa-regular fa-calendar-check"></i>
                        Daily Progress
                        <small>DPR</small>
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

            <!-- DPR CONTENT -->
            <div class="page-card">
                <h2><i class="fa-regular fa-clock"></i> Progress Reports</h2>
                <p class="sub">Track your daily time, tasks, and feedback</p>

                <?php if (isset($tableError)): ?>
                    <div class="alert-box">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span><?php echo htmlspecialchars($tableError); ?></span>
                    </div>
                <?php endif; ?>

                <!-- TABLE CONTROLS -->
                <div class="table-controls">
                    <div class="controls-left">
                        <span class="filter-label"><i class="fa-solid fa-sliders"></i> Filters</span>
                        
                        <div class="filter-item">
                            <i class="fa-regular fa-calendar"></i>
                            <select id="filterDate">
                                <option value="">All Dates</option>
                                <?php if (!empty($availableDates)): ?>
                                    <?php foreach ($availableDates as $date): ?>
                                        <option value="<?php echo htmlspecialchars($date); ?>" <?php echo $filter_date === $date ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(formatDateDisplay($date)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="filter-item">
                            <i class="fa-regular fa-flag"></i>
                            <select id="filterStatus">
                                <option value="">All Status</option>
                                <option value="In Progress" <?php echo $filter_status === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo $filter_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>

                        <button class="btn-sm btn-sm-filter" onclick="applyFilters()">
                            <i class="fa-solid fa-filter"></i> Apply
                        </button>
                        <button class="btn-sm btn-sm-reset" onclick="resetFilters()">
                            <i class="fa-solid fa-rotate-right"></i> Reset
                        </button>
                    </div>

                    <div class="controls-right">
                        <button class="btn-sm btn-sm-success" onclick="exportPDF()">
                            <i class="fa-regular fa-file-pdf"></i> Export PDF
                        </button>
                        <button class="btn-sm btn-sm-primary" id="openModalBtn">
                            <i class="fa-regular fa-plus"></i> Add Entry
                        </button>
                    </div>
                </div>

                <!-- DPR TABLE -->
                <div class="dpr-table-wrap">
                    <table class="dpr-table" id="dprTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Task Accomplished</th>
                                <th>Student Feedback</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="dprTableBody">
                            <?php if (!empty($dprEntries) && count($dprEntries) > 0): ?>
                                <?php foreach ($dprEntries as $entry): ?>
                                    <?php
                                    $statusClass = strtolower($entry['status']);
                                    $statusClass = str_replace(' ', '-', $statusClass);
                                    $hasTask = !empty($entry['tasks']);
                                    $hasFeedback = !empty($entry['feedback']);
                                    ?>
                                    <tr data-id="<?php echo $entry['id']; ?>">
                                        <td class="date-cell"><?php echo htmlspecialchars(formatDateDisplay($entry['date'])); ?></td>
                                        <td><?php echo htmlspecialchars($entry['time_in'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($entry['time_out'] ?? '—'); ?></td>
                                        <td>
                                            <button class="view-btn view-btn-task <?php echo $hasTask ? '' : 'no-content'; ?>"
                                                    onclick="viewTask(<?php echo $entry['id']; ?>, '<?php echo addslashes($entry['tasks'] ?? ''); ?>', '<?php echo addslashes($entry['date']); ?>', '<?php echo addslashes($entry['time_in'] ?? ''); ?>', '<?php echo addslashes($entry['time_out'] ?? ''); ?>')"
                                                    <?php echo $hasTask ? '' : 'disabled'; ?>>
                                                <i class="fa-regular fa-list-check"></i>
                                                <?php echo $hasTask ? 'View Task' : 'No Task'; ?>
                                            </button>
                                        </td>
                                        <td>
                                            <button class="view-btn view-btn-feedback <?php echo $hasFeedback ? '' : 'no-content'; ?>"
                                                    onclick="viewFeedback(<?php echo $entry['id']; ?>, '<?php echo addslashes($entry['feedback'] ?? ''); ?>', '<?php echo addslashes($entry['date']); ?>', '<?php echo addslashes($entry['tasks'] ?? ''); ?>')"
                                                    <?php echo $hasFeedback ? '' : 'disabled'; ?>>
                                                <i class="fa-regular fa-comment"></i>
                                                <?php echo $hasFeedback ? 'View Feedback' : 'No Feedback'; ?>
                                            </button>
                                        </td>
                                        <td>
                                            <span class="badge-status <?php echo $statusClass; ?>">
                                                <?php echo htmlspecialchars($entry['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="empty-row">
                                    <td colspan="6">
                                        <i class="fa-regular fa-calendar-circle-plus"></i>
                                        No progress reports found.<br>
                                        <span style="font-size:0.85rem; color:#cbd5e1;">Start your first entry today!</span>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- ===== ADD DPR MODAL ===== -->
    <div class="modal-overlay" id="dprModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-regular fa-pen-to-square"></i> Add DPR Entry</h2>
                <button class="modal-close" id="closeModalBtn">&times;</button>
            </div>
            <div class="modal-hint">Fill in your daily progress details below.</div>

            <form id="dprForm">
                <div class="form-group">
                    <label for="entryDate"><i class="fa-regular fa-calendar"></i> Date</label>
                    <input type="date" id="entryDate" required />
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="entryTimeIn"><i class="fa-regular fa-clock"></i> Time In</label>
                        <input type="time" id="entryTimeIn" />
                    </div>
                    <div class="form-group">
                        <label for="entryTimeOut"><i class="fa-regular fa-clock"></i> Time Out</label>
                        <input type="time" id="entryTimeOut" />
                    </div>
                </div>

                <div class="form-group">
                    <label for="entryTasks"><i class="fa-regular fa-list-check"></i> Task Accomplished *</label>
                    <textarea id="entryTasks" placeholder="Describe what you accomplished today..." required></textarea>
                </div>

                <div class="form-group">
                    <label for="entryFeedback"><i class="fa-regular fa-comment"></i> Student Feedback</label>
                    <textarea id="entryFeedback" placeholder="Any feedback or questions?"></textarea>
                </div>

                <div class="form-group">
                    <label for="entryStatus"><i class="fa-regular fa-flag"></i> Status</label>
                    <select id="entryStatus">
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-close-modal" id="closeModalBtn2">Cancel</button>
                    <button type="submit" class="btn-submit"><i class="fa-regular fa-check"></i> Save Entry</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== VIEW TASK MODAL ===== -->
    <div class="modal-overlay" id="taskModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-regular fa-list-check"></i> Task Accomplished</h2>
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
                        <i class="fa-regular fa-clock"></i>
                        <strong>Time:</strong> <span id="taskTime">—</span>
                    </div>
                </div>
                <div class="content-text task-text" id="taskTextDisplay">
                    <span class="empty-text">No task description provided.</span>
                </div>
            </div>

            <div class="modal-actions" style="border-top: none; padding-top: 16px; margin-top: 8px;">
                <button class="btn-close-modal" id="closeTaskBtn2">Close</button>
            </div>
        </div>
    </div>

    <!-- ===== VIEW FEEDBACK MODAL ===== -->
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
                        <i class="fa-regular fa-list-check"></i>
                        <strong>Task:</strong> <span id="feedbackTask">—</span>
                    </div>
                </div>
                <div class="content-text feedback-text" id="feedbackTextDisplay">
                    <span class="empty-text">No feedback provided.</span>
                </div>
            </div>

            <div class="modal-actions" style="border-top: none; padding-top: 16px; margin-top: 8px;">
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
        (function() {
            // DOM elements
            const modal = document.getElementById('dprModal');
            const openBtn = document.getElementById('openModalBtn');
            const closeBtns = document.getElementById('closeModalBtn');
            const closeBtn2 = document.getElementById('closeModalBtn2');
            const form = document.getElementById('dprForm');
            const tbody = document.getElementById('dprTableBody');
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');

            // Task modal elements
            const taskModal = document.getElementById('taskModal');
            const closeTaskBtns = document.getElementById('closeTaskBtn');
            const closeTaskBtn2 = document.getElementById('closeTaskBtn2');
            const taskDate = document.getElementById('taskDate');
            const taskTime = document.getElementById('taskTime');
            const taskTextDisplay = document.getElementById('taskTextDisplay');

            // Feedback modal elements
            const feedbackModal = document.getElementById('feedbackModal');
            const closeFeedbackBtns = document.getElementById('closeFeedbackBtn');
            const closeFeedbackBtn2 = document.getElementById('closeFeedbackBtn2');
            const feedbackDate = document.getElementById('feedbackDate');
            const feedbackTask = document.getElementById('feedbackTask');
            const feedbackTextDisplay = document.getElementById('feedbackTextDisplay');

            // Form fields
            const dateInput = document.getElementById('entryDate');
            const timeInInput = document.getElementById('entryTimeIn');
            const timeOutInput = document.getElementById('entryTimeOut');
            const tasksInput = document.getElementById('entryTasks');
            const feedbackInput = document.getElementById('entryFeedback');
            const statusSelect = document.getElementById('entryStatus');

            // Set default date to today
            const today = new Date().toISOString().slice(0, 10);
            dateInput.value = today;

            // ----- Modal controls -----
            function openModal() {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                dateInput.value = today;
                timeInInput.value = '';
                timeOutInput.value = '';
                tasksInput.value = '';
                feedbackInput.value = '';
                statusSelect.value = 'In Progress';
                setTimeout(() => tasksInput.focus(), 100);
            }

            function closeModal() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }

            openBtn.addEventListener('click', openModal);
            closeBtns.addEventListener('click', closeModal);
            closeBtn2.addEventListener('click', closeModal);

            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    closeModal();
                }
            });

            // ----- Task Modal controls -----
            function openTaskModal(date, time, task) {
                taskDate.textContent = date || '—';
                taskTime.textContent = time || '—';
                
                if (task && task.trim() !== '') {
                    taskTextDisplay.textContent = task;
                    taskTextDisplay.className = 'content-text task-text';
                } else {
                    taskTextDisplay.innerHTML = '<span class="empty-text">No task description provided for this entry.</span>';
                    taskTextDisplay.className = 'content-text task-text';
                }
                
                taskModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function closeTaskModal() {
                taskModal.classList.remove('active');
                document.body.style.overflow = '';
            }

            closeTaskBtns.addEventListener('click', closeTaskModal);
            closeTaskBtn2.addEventListener('click', closeTaskModal);

            taskModal.addEventListener('click', function(e) {
                if (e.target === taskModal) closeTaskModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && taskModal.classList.contains('active')) {
                    closeTaskModal();
                }
            });

            // ----- Feedback Modal controls -----
            function openFeedbackModal(date, task, feedback) {
                feedbackDate.textContent = date || '—';
                feedbackTask.textContent = task || '—';
                
                if (feedback && feedback.trim() !== '') {
                    feedbackTextDisplay.textContent = feedback;
                    feedbackTextDisplay.className = 'content-text feedback-text';
                } else {
                    feedbackTextDisplay.innerHTML = '<span class="empty-text">No feedback provided for this entry.</span>';
                    feedbackTextDisplay.className = 'content-text feedback-text';
                }
                
                feedbackModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function closeFeedbackModal() {
                feedbackModal.classList.remove('active');
                document.body.style.overflow = '';
            }

            closeFeedbackBtns.addEventListener('click', closeFeedbackModal);
            closeFeedbackBtn2.addEventListener('click', closeFeedbackModal);

            feedbackModal.addEventListener('click', function(e) {
                if (e.target === feedbackModal) closeFeedbackModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && feedbackModal.classList.contains('active')) {
                    closeFeedbackModal();
                }
            });

            // ----- Toast notification -----
            function showToast(message, type = 'success') {
                toast.className = 'toast ' + type + ' show';
                toastMessage.textContent = message;
                clearTimeout(toast._timeout);
                toast._timeout = setTimeout(() => {
                    toast.classList.remove('show');
                }, 4000);
            }

            // ----- Format time for display (12-hour) -----
            function formatTimeForDisplay(timeStr) {
                if (!timeStr) return '—';
                const parts = timeStr.split(':');
                if (parts.length < 2) return timeStr;
                let hour = parseInt(parts[0]);
                const minute = parts[1];
                const ampm = hour >= 12 ? 'PM' : 'AM';
                hour = hour % 12 || 12;
                return `${hour}:${minute} ${ampm}`;
            }

            // ----- Add row to table -----
            function addRowToTable(entry) {
                const emptyRow = tbody.querySelector('.empty-row');
                if (emptyRow) emptyRow.remove();

                const statusClass = entry.status.toLowerCase().replace(' ', '-');
                const hasTask = entry.tasks && entry.tasks.trim() !== '';
                const hasFeedback = entry.feedback && entry.feedback.trim() !== '';

                const tr = document.createElement('tr');
                tr.dataset.id = entry.id || 'temp';

                tr.innerHTML = `
                    <td class="date-cell">${escapeHtml(entry.date)}</td>
                    <td>${escapeHtml(entry.timeIn || '—')}</td>
                    <td>${escapeHtml(entry.timeOut || '—')}</td>
                    <td>
                        <button class="view-btn view-btn-task ${hasTask ? '' : 'no-content'}" 
                                onclick="viewTask('${escapeHtml(entry.id)}', '${escapeHtml(entry.tasks || '')}', '${escapeHtml(entry.date)}', '${escapeHtml(entry.timeIn || '')}', '${escapeHtml(entry.timeOut || '')}')"
                                ${hasTask ? '' : 'disabled'}>
                            <i class="fa-regular fa-list-check"></i>
                            ${hasTask ? 'View Task' : 'No Task'}
                        </button>
                    </td>
                    <td>
                        <button class="view-btn view-btn-feedback ${hasFeedback ? '' : 'no-content'}" 
                                onclick="viewFeedback('${escapeHtml(entry.id)}', '${escapeHtml(entry.feedback || '')}', '${escapeHtml(entry.date)}', '${escapeHtml(entry.tasks || '')}')"
                                ${hasFeedback ? '' : 'disabled'}>
                            <i class="fa-regular fa-comment"></i>
                            ${hasFeedback ? 'View Feedback' : 'No Feedback'}
                        </button>
                    </td>
                    <td><span class="badge-status ${statusClass}">${escapeHtml(entry.status)}</span></td>
                `;

                tbody.insertBefore(tr, tbody.firstChild);
            }

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

            // ----- Handle form submission -----
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const date = dateInput.value.trim();
                if (!date) {
                    showToast('Please select a date.', 'error');
                    return;
                }

                const tasks = tasksInput.value.trim();
                if (!tasks) {
                    showToast('Please describe your task accomplished.', 'error');
                    tasksInput.focus();
                    return;
                }

                const timeIn = timeInInput.value;
                const timeOut = timeOutInput.value;
                const feedback = feedbackInput.value.trim();
                const status = statusSelect.value;

                const formData = new FormData();
                formData.append('action', 'add_dpr');
                formData.append('date', date);
                formData.append('time_in', timeIn);
                formData.append('time_out', timeOut);
                formData.append('tasks', tasks);
                formData.append('feedback', feedback);
                formData.append('status', status);

                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

                fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const newEntry = {
                                date: formatDateDisplay(date),
                                timeIn: timeIn ? formatTimeForDisplay(timeIn) : '—',
                                timeOut: timeOut ? formatTimeForDisplay(timeOut) : '—',
                                tasks: tasks,
                                feedback: feedback || '',
                                status: status
                            };
                            addRowToTable(newEntry);
                            showToast('DPR entry added successfully!', 'success');
                            closeModal();
                            // Refresh page to update filter dropdown
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            showToast(data.message || 'Failed to add entry.', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('An error occurred. Please try again.', 'error');
                    })
                    .finally(() => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fa-regular fa-check"></i> Save Entry';
                    });
            });

            toast.addEventListener('click', function() {
                toast.classList.remove('show');
            });

        })();

        // ----- Global functions for viewing content -----
        function viewTask(id, task, date, timeIn, timeOut) {
            const taskModal = document.getElementById('taskModal');
            const taskDate = document.getElementById('taskDate');
            const taskTime = document.getElementById('taskTime');
            const taskTextDisplay = document.getElementById('taskTextDisplay');

            const timeStr = timeIn && timeOut ? `${formatTimeDisplay(timeIn)} - ${formatTimeDisplay(timeOut)}` : (timeIn ? formatTimeDisplay(timeIn) : '—');
            
            taskDate.textContent = date || '—';
            taskTime.textContent = timeStr || '—';
            
            if (task && task.trim() !== '') {
                taskTextDisplay.textContent = task;
                taskTextDisplay.className = 'content-text task-text';
            } else {
                taskTextDisplay.innerHTML = '<span class="empty-text">No task description provided for this entry.</span>';
                taskTextDisplay.className = 'content-text task-text';
            }
            
            taskModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function viewFeedback(id, feedback, date, task) {
            const feedbackModal = document.getElementById('feedbackModal');
            const feedbackDate = document.getElementById('feedbackDate');
            const feedbackTask = document.getElementById('feedbackTask');
            const feedbackTextDisplay = document.getElementById('feedbackTextDisplay');

            feedbackDate.textContent = date || '—';
            feedbackTask.textContent = task || '—';
            
            if (feedback && feedback.trim() !== '') {
                feedbackTextDisplay.textContent = feedback;
                feedbackTextDisplay.className = 'content-text feedback-text';
            } else {
                feedbackTextDisplay.innerHTML = '<span class="empty-text">No feedback provided for this entry.</span>';
                feedbackTextDisplay.className = 'content-text feedback-text';
            }
            
            feedbackModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function formatTimeDisplay(timeStr) {
            if (!timeStr) return '—';
            const parts = timeStr.split(':');
            if (parts.length < 2) return timeStr;
            let hour = parseInt(parts[0]);
            const minute = parts[1];
            const ampm = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12 || 12;
            return `${hour}:${minute} ${ampm}`;
        }

        // ----- Format date for display -----
        function formatDateDisplay(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr + 'T00:00:00');
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            return date.toLocaleDateString('en-US', options);
        }

        // ----- Filter functions -----
        function applyFilters() {
            const date = document.getElementById('filterDate').value;
            const status = document.getElementById('filterStatus').value;

            let url = window.location.pathname + '?';
            if (date) url += 'filter_date=' + date + '&';
            if (status) url += 'filter_status=' + encodeURIComponent(status) + '&';

            window.location.href = url;
        }

        function resetFilters() {
            window.location.href = window.location.pathname;
        }

        // Enter key for filter
        document.addEventListener('DOMContentLoaded', function() {
            const filterDate = document.getElementById('filterDate');
            const filterStatus = document.getElementById('filterStatus');

            filterDate.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') applyFilters();
            });

            filterStatus.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') applyFilters();
            });
        });

        // ----- PDF Export -----
        function exportPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('landscape', 'mm', 'a4');

            // Get table data
            const table = document.getElementById('dprTable');
            const rows = table.querySelectorAll('tbody tr');

            // Skip if no data
            if (rows.length === 0 || rows[0].classList.contains('empty-row')) {
                alert('No data to export!');
                return;
            }

            // Prepare data for PDF
            const tableData = [];
            const headers = ['Date', 'Time In', 'Time Out', 'Task Accomplished', 'Student Feedback', 'Status'];

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    const rowData = [];
                    cells.forEach(cell => {
                        let text = cell.textContent.trim();
                        text = text.replace(/\s+/g, ' ');
                        rowData.push(text);
                    });
                    tableData.push(rowData);
                }
            });

            // Add title
            doc.setFontSize(20);
            doc.setTextColor(15, 23, 42);
            doc.setFont('helvetica', 'bold');
            doc.text('Daily Progress Report', 14, 22);

            // Add student info
            doc.setFontSize(10);
            doc.setTextColor(100, 116, 139);
            doc.setFont('helvetica', 'normal');
            doc.text('Student: <?php echo htmlspecialchars($fullname); ?>', 14, 30);
            doc.text('Generated: ' + new Date().toLocaleString(), 14, 36);

            // Add filter info if applied
            let filterText = '';
            const filterDate = document.getElementById('filterDate').value;
            const filterStatus = document.getElementById('filterStatus').value;
            if (filterDate || filterStatus) {
                filterText = 'Filtered: ';
                if (filterDate) filterText += 'Date: ' + filterDate + ' ';
                if (filterStatus) filterText += 'Status: ' + filterStatus;
                doc.text(filterText, 14, 42);
            }

            // AutoTable
            doc.autoTable({
                head: [headers],
                body: tableData,
                startY: filterText ? 50 : 44,
                theme: 'striped',
                styles: {
                    fontSize: 8,
                    cellPadding: 3,
                    overflow: 'linebreak',
                    lineColor: [226, 232, 240],
                    lineWidth: 0.1,
                },
                headStyles: {
                    fillColor: [15, 23, 42],
                    textColor: [255, 255, 255],
                    fontSize: 8,
                    fontStyle: 'bold',
                },
                alternateRowStyles: {
                    fillColor: [248, 250, 252],
                },
                columnStyles: {
                    0: { cellWidth: 25 },
                    1: { cellWidth: 22 },
                    2: { cellWidth: 22 },
                    3: { cellWidth: 50 },
                    4: { cellWidth: 50 },
                    5: { cellWidth: 25 },
                },
                margin: { left: 14, right: 14 },
                didParseCell: function(data) {
                    if (data.section === 'body' && data.column.index === 5) {
                        const status = data.cell.text[0];
                        if (status === 'Completed') {
                            data.cell.styles.textColor = [22, 101, 52];
                        } else if (status === 'In Progress') {
                            data.cell.styles.textColor = [133, 77, 14];
                        } else if (status === 'Pending') {
                            data.cell.styles.textColor = [71, 85, 105];
                        }
                    }
                }
            });

            // Save PDF
            doc.save('DPR_Report_' + new Date().toISOString().slice(0, 10) + '.pdf');
        }
    </script>
</body>
</html>