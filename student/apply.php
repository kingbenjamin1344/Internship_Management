<?php
// student/apply.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is student
checkAccess('student');
ensureInternshipTables($pdo);

$username = $_SESSION['username'] ?? 'Student';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Student';
$studentId = getUserId();

$committedStmt = $pdo->prepare('SELECT id FROM job_applications WHERE student_id = ? AND status = "committed" LIMIT 1');
$committedStmt->execute([$studentId]);
$hasCommittedJob = (bool)$committedStmt->fetch();

// Handle Job Application Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_job') {
    $jobId = (int)($_POST['job_id'] ?? 0);

    if ($hasCommittedJob) {
        $_SESSION['error'] = 'You have already committed to a job and can no longer apply to other internships.';
        header('Location: apply.php');
        exit;
    }

    // 1. Check if already applied
    $checkStmt = $pdo->prepare('SELECT id FROM job_applications WHERE job_id = ? AND student_id = ?');
    $checkStmt->execute([$jobId, $studentId]);
    if ($checkStmt->fetch()) {
        $_SESSION['error'] = 'You have already applied for this internship position.';
        header('Location: apply.php');
        exit;
    }

    // 2. Verify job exists and has open slots
    $jobCheckStmt = $pdo->prepare('SELECT title, slots_available, slots_filled FROM jobs WHERE id = ?');
    $jobCheckStmt->execute([$jobId]);
    $jobObj = $jobCheckStmt->fetch();
    if (!$jobObj) {
        $_SESSION['error'] = 'Invalid job position.';
        header('Location: apply.php');
        exit;
    }

    $availableSlots = (int)$jobObj['slots_available'] - (int)$jobObj['slots_filled'];
    if ($availableSlots <= 0) {
        $_SESSION['error'] = 'Sorry, this internship position is already filled.';
        header('Location: apply.php');
        exit;
    }

    // 3. Validate file uploads
    $allowedExtensions = ['pdf', 'doc', 'docx'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB

    $uploadedFiles = [];
    $fileFields = ['cv' => 'CV', 'resume' => 'Resume', 'app_letter' => 'Application letter'];
    $errors = [];

    // Ensure uploads directory exists
    $uploadDir = __DIR__ . '/../uploads/applications/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    foreach ($fileFields as $field => $label) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = "$label file is required.";
            continue;
        }

        if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading $label file.";
            continue;
        }

        $fileSize = $_FILES[$field]['size'];
        $fileName = $_FILES[$field]['name'];
        $fileTmpPath = $_FILES[$field]['tmp_name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileSize > $maxFileSize) {
            $errors[] = "$label file size must be less than 5MB.";
            continue;
        }

        if (!in_array($ext, $allowedExtensions)) {
            $errors[] = "$label must be a PDF, DOC, or DOCX document.";
            continue;
        }

        // Generate unique filename to avoid overwrites
        $newFileName = $studentId . '_' . $jobId . '_' . $field . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $uploadedFiles[$field] = 'uploads/applications/' . $newFileName;
        } else {
            $errors[] = "Failed to save $label file locally.";
        }
    }

    // 4. Final Insert or Cleanup
    if (!empty($errors)) {
        // Clean up any files that were uploaded successfully during this turn
        foreach ($uploadedFiles as $filePath) {
            @unlink(__DIR__ . '/../' . $filePath);
        }
        $_SESSION['error'] = implode('<br>', $errors);
    } else {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO job_applications (job_id, student_id, cv_path, resume_path, application_letter_path, status)
                VALUES (?, ?, ?, ?, ?, "pending")
            ');
            $stmt->execute([
                $jobId,
                $studentId,
                $uploadedFiles['cv'],
                $uploadedFiles['resume'],
                $uploadedFiles['app_letter']
            ]);
            $_SESSION['success'] = 'Application for "' . htmlspecialchars($jobObj['title']) . '" submitted successfully!';
        } catch (Exception $e) {
            // Clean up files
            foreach ($uploadedFiles as $filePath) {
                @unlink(__DIR__ . '/../' . $filePath);
            }
            $_SESSION['error'] = 'Application submission failed: ' . $e->getMessage();
        }
    }

    header('Location: apply.php');
    exit;
}

// Fetch all jobs in system
$jobsStmt = $pdo->prepare('
    SELECT j.*, c.company_name, c.industry,
           u.firstname AS supervisor_firstname, u.lastname AS supervisor_lastname
    FROM jobs j
    INNER JOIN companies c ON j.company_id = c.id
    LEFT JOIN users u ON j.created_by = u.id
    ORDER BY j.created_at DESC
');
$jobsStmt->execute();
$jobs = $jobsStmt->fetchAll();

// Fetch student\'s applications to bind statuses and allow management
$appStmt = $pdo->prepare('
    SELECT a.*, j.title, c.company_name
    FROM job_applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN companies c ON j.company_id = c.id
    WHERE a.student_id = ?
    ORDER BY a.application_date DESC
');
$appStmt->execute([$studentId]);
$myApplications = $appStmt->fetchAll();

// Index applications by job_id for quick status checking in listings table
$appliedJobs = [];
foreach ($myApplications as $app) {
    $appliedJobs[$app['job_id']] = $app;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Internship Roles</title>
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

        /* Modals and Overlays */
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
            max-width: 680px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: modalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.97);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafcff;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
        }
        
        .modal-close-btn {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-size: 18px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close-btn:hover {
            background: #fee2e2;
            color: #dc2626;
            transform: rotate(90deg);
        }
        
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            max-height: calc(85vh - 120px);
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #fafcff;
        }

        /* Layout & Table styles */
        .table-container {
            overflow-x: auto;
            margin-top: 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        
        .job-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            min-width: 900px;
        }
        
        .job-table thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .job-table thead th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            white-space: nowrap;
        }
        
        .job-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s ease;
        }
        
        .job-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .job-table tbody td {
            padding: 12px 16px;
            vertical-align: middle;
            color: #1e293b;
        }

        /* Search and Filter Area */
        .search-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            max-width: 380px;
            width: 100%;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .search-box input {
            padding: 10px 14px 10px 38px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.9rem;
            background: #fff;
            width: 100%;
        }

        /* Buttons & Badges */
        .btn-view, .btn-apply {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
        }
        
        .btn-view {
            background: #e0e7ff;
            color: #4338ca;
        }
        
        .btn-view:hover {
            background: #c7d2fe;
            transform: translateY(-1px);
        }
        
        .btn-apply {
            background: #dbeafe;
            color: #1d4ed8;
        }
        
        .btn-apply:hover {
            background: #bfdbfe;
            transform: translateY(-1px);
        }

        .btn-apply:disabled, .btn-view:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none !important;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-reviewed { background: #e0f2fe; color: #0369a1; }
        .badge-shortlisted { background: #ece9ff; color: #6d28d9; }
        .badge-accepted { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-withdrawn { background: #f1f5f9; color: #475569; }

        .btn-sec-outline {
            padding: 10px 16px;
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-sec-outline:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .btn-prim-blue {
            padding: 10px 20px;
            border-radius: 10px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-prim-blue:hover {
            background: #1d4ed8;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        /* Detail Modal Cards */
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        .detail-card {
            background: #f8fafc;
            padding: 14px 18px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .detail-card .lbl {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 4px;
        }

        .detail-card .val {
            font-size: 0.95rem;
            font-weight: 600;
            color: #0f172a;
        }

        .content-section {
            margin-bottom: 16px;
        }

        .content-section h4 {
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            margin: 16px 0 6px 0;
            font-weight: 700;
        }

        .content-body {
            background: #f8fafc;
            padding: 14px 18px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            font-size: 0.9rem;
            line-height: 1.6;
            color: #334155;
            white-space: pre-line;
        }

        /* File Upload Inputs styled */
        .file-upload-block {
            margin-bottom: 16px;
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            padding: 16px;
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .file-upload-block:hover {
            border-color: #2563eb;
            background: #f0f6ff;
        }

        .file-upload-block label {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .file-upload-block span.hint {
            display: block;
            font-size: 0.72rem;
            color: #64748b;
            margin-bottom: 10px;
        }

        .file-upload-block input[type="file"] {
            width: 100%;
            font-size: 0.85rem;
            color: #475569;
            cursor: pointer;
        }

        .file-upload-block input[type="file"]::file-selector-button {
            border: none;
            background: #2563eb;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            margin-right: 12px;
            transition: all 0.2s ease;
        }

        .file-upload-block input[type="file"]::file-selector-button:hover {
            background: #1d4ed8;
        }

        /* Document Tag / Action Details */
        .doc-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #334155;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.155s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            text-decoration: none;
            cursor: pointer;
        }

        .doc-link:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .doc-link i {
            color: #2563eb;
        }

        .empty-state {
            padding: 48px 16px;
            text-align: center;
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
        }

        .empty-state i {
            font-size: 40px;
            color: #94a3b8;
            margin-bottom: 12px;
            opacity: 0.55;
        }

        .empty-state h3 {
            margin: 0 0 6px;
            font-size: 1.1rem;
            color: #1e293b;
        }

        .empty-state p {
            margin: 0;
            font-size: 0.85rem;
            color: #64748b;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            font-weight: 700;
            color: #475569;
            font-size: 0.85rem;
            display: block;
            margin-bottom: 4px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.9rem;
            background: #f1f5f9;
            font-weight: 600;
            color: #0f172a;
        }

        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* ===== TOAST ===== */
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

        /* ===== PDF VIEWER MODAL ===== */
        .pdf-viewer-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(8px);
            z-index: 3000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .pdf-viewer-overlay.active {
            display: flex;
        }

        .pdf-viewer-container {
            background: #ffffff;
            width: 100%;
            max-width: 900px;
            height: 90vh;
            border-radius: 20px;
            box-shadow: 0 40px 80px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            animation: modalSlideUp 0.3s ease;
            overflow: hidden;
        }

        .pdf-viewer-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafcff;
            flex-shrink: 0;
        }

        .pdf-viewer-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pdf-viewer-header h3 i {
            color: #dc2626;
        }

        .pdf-viewer-close {
            background: #f1f5f9;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            font-size: 20px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pdf-viewer-close:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        .pdf-viewer-body {
            flex: 1;
            padding: 16px;
            overflow: hidden;
            background: #f1f5f9;
        }

        .pdf-viewer-body iframe {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 12px;
            background: #ffffff;
        }

        .pdf-viewer-footer {
            padding: 12px 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            background: #fafcff;
            flex-shrink: 0;
        }

        .btn-download {
            padding: 8px 20px;
            border-radius: 10px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-download:hover {
            background: #1d4ed8;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        @media (max-width: 720px) {
            .details-grid {
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
            .toast {
                bottom: 20px;
                right: 20px;
                left: 20px;
                padding: 14px 18px;
                font-size: 0.9rem;
                max-width: none;
            }
            .pdf-viewer-container {
                height: 95vh;
                max-height: 600px;
            }
            .pdf-viewer-header h3 {
                font-size: 0.95rem;
            }
        }

        @media (max-width: 480px) {
            .toast {
                bottom: 12px;
                right: 12px;
                left: 12px;
                padding: 12px 16px;
                font-size: 0.85rem;
                border-radius: 12px;
            }
            .pdf-viewer-container {
                height: 95vh;
                max-height: 500px;
                border-radius: 12px;
            }
            .pdf-viewer-header {
                padding: 12px 16px;
            }
            .pdf-viewer-body {
                padding: 8px;
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
                <a class="nav-item active" href="apply.php"><i class="fa-solid fa-briefcase"></i> Apply Job</a>
                <a class="nav-item" href="applications.php"><i class="fa-solid fa-file-lines"></i> My Applications</a>
                <a class="nav-item" href="dpr.php"><i class="fa-regular fa-calendar-check"></i> Daily Progress Report</a>
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
                        <i class="fa-solid fa-briefcase"></i>
                        Internship Opportunities
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
                            <div class="role-label">Student</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Available Jobs Card -->
            <div class="page-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h2 style="margin: 0; font-size: 1.3rem;">Available Positions</h2>
                        <p style="margin: 4px 0 0; font-size: 0.85rem; color:#64748b;">Explore and apply for internship opportunities created by supervisors.</p>
                    </div>
                    <div style="font-size: 0.85rem; font-weight: 600; color: #475569;">
                        Total Positions: <span style="color:#2563eb; font-weight: 700;"><?php echo count($jobs); ?></span>
                    </div>
                </div>

                <!-- Instant Filter/Search Box -->
                <div class="search-row">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="jobSearchInput" onkeyup="filterJobsTable()" placeholder="Search by title, company, or industry..." />
                    </div>
                </div>

                <?php if (count($jobs) > 0): ?>
                    <div class="table-container">
                        <table class="job-table" id="jobsTable">
                            <thead>
                                <tr>
                                    <th>Job Title</th>
                                    <th>Company</th>
                                    <th>Industry</th>
                                    <th>Supervisor</th>
                                    <th>Vacancy Slots</th>
                                    <th>Duration</th>
                                    <th style="width: 180px; text-align: center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobs as $job): ?>
                                    <?php 
                                    $jobId = (int)$job['id'];
                                    $hasApplied = isset($appliedJobs[$jobId]);
                                    $supervisor = trim(($job['supervisor_firstname'] ?? '') . ' ' . ($job['supervisor_lastname'] ?? ''));
                                    if (empty($supervisor)) {
                                        $supervisor = 'N/A';
                                    }
                                    
                                    $slotsTotal = (int)$job['slots_available'];
                                    $slotsFilled = (int)$job['slots_filled'];
                                    $slotsLeft = $slotsTotal - $slotsFilled;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong style="color: #0f172a; font-size: 0.95rem;"><?php echo htmlspecialchars($job['title']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($job['company_name']); ?></td>
                                        <td>
                                            <span style="font-size:0.75rem; background:#f1f5f9; padding:2px 8px; border-radius:999px; font-weight: 600; color:#475569;">
                                                <?php echo htmlspecialchars($job['industry']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="fa-regular fa-user" style="color: #94a3b8; margin-right: 4px; font-size: 0.8rem;"></i>
                                            <?php echo htmlspecialchars($supervisor); ?>
                                        </td>
                                        <td>
                                            <?php if ($slotsLeft > 0): ?>
                                                <span style="color: #16a34a; font-weight: 700;">
                                                    <?php echo $slotsLeft; ?> / <?php echo $slotsTotal; ?> slots
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #dc2626; font-weight: 700;">
                                                    Filled
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight: 500;">
                                                <?php echo $job['duration_hours'] > 0 ? (int)$job['duration_hours'] . ' hours' : 'N/A'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; justify-content: center;">
                                                <button class="btn-view" type="button" onclick="openViewModal(<?php echo $jobId; ?>)">
                                                    <i class="fa-solid fa-eye"></i> View
                                                </button>
                                                
                                                <?php if ($hasCommittedJob): ?>
                                                    <button class="btn-apply" style="background:#e2e8f0; color:#64748b;" disabled title="You have already committed to a job">
                                                        <i class="fa-solid fa-lock"></i> 
                                                    </button>
                                                <?php elseif ($hasApplied): ?>
                                                    <button class="btn-apply" style="background:#f1f5f9; color:#94a3b8;" disabled>
                                                        <i class="fa-solid fa-check"></i> Applied
                                                    </button>
                                                <?php elseif ($slotsLeft <= 0): ?>
                                                    <button class="btn-apply" style="background:#fee2e2; color:#ef4444;" disabled title="Job is filled">
                                                        <i class="fa-solid fa-ban"></i> Filled
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-apply" type="button" onclick="openApplyModal(<?php echo $jobId; ?>, '<?php echo htmlspecialchars(addslashes($job['title'])); ?>')">
                                                        <i class="fa-solid fa-paper-plane"></i> Apply
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="margin-top: 16px;">
                        <i class="fa-solid fa-briefcase"></i>
                        <h3>No Positions Posted Yet</h3>
                        <p>Supervisors haven't posted any internship opportunities yet. Please check back later.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Student Applications History Section -->
            <?php if (count($myApplications) > 0): ?>
                <div class="page-card" style="margin-top: 24px;">
                    <div style="margin-bottom: 12px;">
                        <h2 style="margin: 0; font-size: 1.3rem;">Your Submitted Applications</h2>
                        <p style="margin: 4px 0 0; font-size: 0.85rem; color:#64748b;">Keep track of internship applications you've submitted and check their current status.</p>
                    </div>

                    <div class="table-container">
                        <table class="job-table">
                            <thead>
                                <tr>
                                    <th>Applied Position</th>
                                    <th>Company</th>
                                    <th>Submission Date</th>
                                    <th>Status</th>
                                    <th style="padding-left: 20px;">Uploaded Documents</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myApplications as $app): ?>
                                    <tr>
                                        <td>
                                            <strong style="color: #0f172a;"><?php echo htmlspecialchars($app['title']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($app['company_name']); ?></td>
                                        <td>
                                            <i class="fa-regular fa-clock" style="color: #94a3b8; margin-right: 4px; font-size: 0.8rem;"></i>
                                            <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($app['application_date']))); ?>
                                        </td>
                                        <td>
                                            <span class="badge-status badge-<?php echo htmlspecialchars($app['status']); ?>">
                                                <i class="fa-solid fa-circle" style="font-size:0.5rem; margin-right:2px;"></i>
                                                <?php echo htmlspecialchars(ucfirst($app['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <?php if (!empty($app['cv_path'])): ?>
                                                    <span class="doc-link" onclick="viewPDF('<?php echo htmlspecialchars($app['cv_path']); ?>', 'CV')">
                                                        <i class="fa-solid fa-file-pdf"></i> CV
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!empty($app['resume_path'])): ?>
                                                    <span class="doc-link" onclick="viewPDF('<?php echo htmlspecialchars($app['resume_path']); ?>', 'Resume')">
                                                        <i class="fa-solid fa-file-pdf"></i> Resume
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!empty($app['application_letter_path'])): ?>
                                                    <span class="doc-link" onclick="viewPDF('<?php echo htmlspecialchars($app['application_letter_path']); ?>', 'Application Letter')">
                                                        <i class="fa-solid fa-file-pdf"></i> App Letter
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- VIEW DETAILS MODAL -->
    <div id="viewModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-circle-info" style="color: #4338ca;"></i> Position Details</h3>
                <button type="button" class="modal-close-btn" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="details-grid">
                    <div class="detail-card">
                        <div class="lbl">Job Title</div>
                        <div id="v_title" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Company</div>
                        <div id="v_company" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Industry</div>
                        <div id="v_industry" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Supervisor</div>
                        <div id="v_supervisor" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Target Duration</div>
                        <div id="v_duration" class="val">-</div>
                    </div>
                    <div class="detail-card">
                        <div class="lbl">Available Placements</div>
                        <div id="v_slots" class="val">-</div>
                    </div>
                </div>

                <div class="content-section">
                    <h4>Description</h4>
                    <div id="v_description" class="content-body">No description entered.</div>
                </div>

                <div class="content-section">
                    <h4>Responsibilities</h4>
                    <div id="v_responsibility" class="content-body">No responsibilities entered.</div>
                </div>

                <div class="content-section">
                    <h4>Requirements</h4>
                    <div id="v_requirements" class="content-body">No requirements entered.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-sec-outline" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- APPLY SUBMISSION MODAL -->
    <div id="applyModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-paper-plane" style="color: #2563eb;"></i> Apply for Position</h3>
                <button type="button" class="modal-close-btn" onclick="closeApplyModal()">&times;</button>
            </div>
            
            <form method="POST" action="apply.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="apply_job">
                <input type="hidden" name="job_id" id="apply_job_id">

                <div class="modal-body">
                    <div class="form-group">
                        <label>Applying to Position:</label>
                        <input type="text" id="apply_job_title_display" class="form-control" readonly>
                    </div>

                    <!-- CV Upload -->
                    <div class="file-upload-block">
                        <label for="cv_file">Curriculum Vitae (CV) <span style="color: #dc2626;">*</span></label>
                        <span class="hint">Upload your latest CV. Accepted formats: PDF, DOC, DOCX (Max size: 5MB)</span>
                        <input type="file" name="cv" id="cv_file" required accept=".pdf,.doc,.docx">
                    </div>

                    <!-- Resume Upload -->
                    <div class="file-upload-block">
                        <label for="resume_file">Resume <span style="color: #dc2626;">*</span></label>
                        <span class="hint">Upload your official resume. Accepted formats: PDF, DOC, DOCX (Max size: 5MB)</span>
                        <input type="file" name="resume" id="resume_file" required accept=".pdf,.doc,.docx">
                    </div>

                    <!-- Application Letter Upload -->
                    <div class="file-upload-block">
                        <label for="letter_file">Application Letter <span style="color: #dc2626;">*</span></label>
                        <span class="hint">Upload your cover / application letter. Accepted formats: PDF, DOC, DOCX (Max size: 5MB)</span>
                        <input type="file" name="app_letter" id="letter_file" required accept=".pdf,.doc,.docx">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-sec-outline" onclick="closeApplyModal()">Cancel</button>
                    <button type="submit" class="btn-prim-blue" <?php echo $hasCommittedJob ? 'disabled title="You have already committed to a job"' : ''; ?>>
                        <i class="fa-solid fa-circle-check"></i> Submit Application
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== PDF VIEWER MODAL ===== -->
    <div class="pdf-viewer-overlay" id="pdfViewer">
        <div class="pdf-viewer-container">
            <div class="pdf-viewer-header">
                <h3><i class="fa-solid fa-file-pdf"></i> <span id="pdfViewerTitle">Document</span></h3>
                <button type="button" class="pdf-viewer-close" onclick="closePDFViewer()">&times;</button>
            </div>
            <div class="pdf-viewer-body">
                <iframe id="pdfViewerFrame" src=""></iframe>
            </div>
            <div class="pdf-viewer-footer">
                <button class="btn-download" onclick="downloadPDF()">
                    <i class="fa-solid fa-download"></i> Download
                </button>
            </div>
        </div>
    </div>

    <!-- ===== TOAST ===== -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <!-- JavaScript Handling -->
    <script>
        // Inject jobs JSON so we don't do unnecessary AJAX calls
        const jobsList = <?php echo json_encode($jobs); ?>;
        let currentPdfPath = '';
        let currentPdfName = '';

        // ===== PDF VIEWER =====
        function viewPDF(filePath, fileName) {
            currentPdfPath = filePath;
            currentPdfName = fileName || 'Document';
            
            // Construct the full URL to the PDF
            const baseUrl = window.location.origin + window.location.pathname.replace('/student/apply.php', '');
            const pdfUrl = baseUrl + '/' + filePath;
            
            document.getElementById('pdfViewerTitle').textContent = fileName || 'Document';
            document.getElementById('pdfViewerFrame').src = pdfUrl;
            document.getElementById('pdfViewer').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePDFViewer() {
            document.getElementById('pdfViewer').classList.remove('active');
            document.getElementById('pdfViewerFrame').src = '';
            document.body.style.overflow = '';
            currentPdfPath = '';
            currentPdfName = '';
        }

        function downloadPDF() {
            if (currentPdfPath) {
                const link = document.createElement('a');
                link.href = currentPdfPath;
                link.download = currentPdfName + '.pdf';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // Close PDF viewer on overlay click
        document.getElementById('pdfViewer').addEventListener('click', function(e) {
            if (e.target === this) {
                closePDFViewer();
            }
        });

        // Close PDF viewer on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('pdfViewer').classList.contains('active')) {
                    closePDFViewer();
                }
            }
        });

        // ===== TOAST =====
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

        // Check for session messages and show as toast
        <?php if (isset($_SESSION['success'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo htmlspecialchars($_SESSION['success']); ?>', 'success');
            });
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo htmlspecialchars($_SESSION['error']); ?>', 'error');
            });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        // Toast click to dismiss
        document.getElementById('toast').addEventListener('click', function() {
            this.classList.remove('show');
        });

        function openViewModal(jobId) {
            const job = jobsList.find(j => parseInt(j.id) === parseInt(jobId));
            if (!job) return;

            document.getElementById('v_title').innerText = job.title || 'N/A';
            document.getElementById('v_company').innerText = job.company_name || 'N/A';
            document.getElementById('v_industry').innerText = job.industry || 'N/A';
            
            const supervisorName = ((job.supervisor_firstname || '') + ' ' + (job.supervisor_lastname || '')).trim();
            document.getElementById('v_supervisor').innerText = supervisorName || 'N/A';
            
            document.getElementById('v_duration').innerText = job.duration_hours > 0 ? (job.duration_hours + ' hours') : 'N/A';
            
            const leftSlots = parseInt(job.slots_available) - parseInt(job.slots_filled);
            document.getElementById('v_slots').innerText = `${leftSlots} available (out of ${job.slots_available})`;

            document.getElementById('v_description').innerText = job.description || 'No description entered.';
            document.getElementById('v_responsibility').innerText = job.responsibility || 'No responsibilities entered.';
            document.getElementById('v_requirements').innerText = job.requirements || 'No requirements entered.';

            document.getElementById('viewModal').style.display = 'flex';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        function openApplyModal(jobId, jobTitle) {
            <?php if ($hasCommittedJob): ?>
            return;
            <?php endif; ?>
            document.getElementById('apply_job_id').value = jobId;
            document.getElementById('apply_job_title_display').value = jobTitle;
            document.getElementById('applyModal').style.display = 'flex';
        }

        function closeApplyModal() {
            document.getElementById('applyModal').style.display = 'none';
            // Clear file inputs on close
            document.getElementById('cv_file').value = '';
            document.getElementById('resume_file').value = '';
            document.getElementById('letter_file').value = '';
        }

        // Close on overlay click
        window.onclick = function(event) {
            const viewModal = document.getElementById('viewModal');
            const applyModal = document.getElementById('applyModal');
            if (event.target === viewModal) {
                closeViewModal();
            }
            if (event.target === applyModal) {
                closeApplyModal();
            }
        };

        // Table Instant Search Filtering logic
        function filterJobsTable() {
            const input = document.getElementById('jobSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('jobsTable');
            if (!table) return;
            
            const trs = table.getElementsByTagName('tr');

            for (let i = 1; i < trs.length; i++) {
                const tr = trs[i];
                let display = false;
                
                // Read columns: job title, company name, industry
                const titleCell = tr.cells[0];
                const companyCell = tr.cells[1];
                const industryCell = tr.cells[2];

                if (titleCell || companyCell || industryCell) {
                    const text = (titleCell.textContent + ' ' + companyCell.textContent + ' ' + industryCell.textContent).toLowerCase();
                    if (text.indexOf(filter) > -1) {
                        display = true;
                    }
                }
                tr.style.display = display ? '' : 'none';
            }
        }
    </script>
</body>
</html>