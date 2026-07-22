<?php
// supervisor/applicant.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

ensureNotificationsTable($pdo);

// Check if user is supervisor
checkAccess('supervisor');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Supervisor';
$role = getUserRole();
$userId = getUserId();

// Handle Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $applicationId = (int)($_POST['application_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');

    // Get current status and job info to properly manage vacancy slots
    $checkStmt = $pdo->prepare('
        SELECT a.id, a.status AS current_status, a.job_id, j.slots_available, j.slots_filled,
               u.firstname AS student_firstname, u.lastname AS student_lastname
        FROM job_applications a
        INNER JOIN jobs j ON a.job_id = j.id
        INNER JOIN users u ON a.student_id = u.id
        LEFT JOIN companies c ON j.company_id = c.id
        WHERE a.id = ? AND (c.supervisor_id = ? OR j.created_by = ?)
    ');
    $checkStmt->execute([$applicationId, $userId, $userId]);
    $appInfo = $checkStmt->fetch();

    if ($appInfo) {
        $oldStatus = $appInfo['current_status'];
        $jobId = $appInfo['job_id'];
        $studentName = trim(($appInfo['student_firstname'] ?? '') . ' ' . ($appInfo['student_lastname'] ?? ''));

        if ($newStatus !== $oldStatus) {
            $pdo->beginTransaction();
            try {
                // Update application status
                $updateStmt = $pdo->prepare('UPDATE job_applications SET status = ?, updated_at = NOW() WHERE id = ?');
                $updateStmt->execute([$newStatus, $applicationId]);

                // Manage vacancy counters: increment if accepted, decrement if changed from accepted
                if ($newStatus === 'accepted' && $oldStatus !== 'accepted') {
                    $pdo->prepare('UPDATE jobs SET slots_filled = slots_filled + 1 WHERE id = ?')->execute([$jobId]);
                } elseif ($oldStatus === 'accepted' && $newStatus !== 'accepted') {
                    $pdo->prepare('UPDATE jobs SET slots_filled = GREATEST(0, slots_filled - 1) WHERE id = ?')->execute([$jobId]);
                }

                // --- Create notification for the student ---
                $notifJobStmt = $pdo->prepare('SELECT j.title, c.company_name, a.student_id FROM job_applications a INNER JOIN jobs j ON a.job_id = j.id INNER JOIN companies c ON j.company_id = c.id WHERE a.id = ?');
                $notifJobStmt->execute([$applicationId]);
                $notifJobInfo = $notifJobStmt->fetch();
                if ($notifJobInfo) {
                    $studentIdForNotif = (int)$notifJobInfo['student_id'];
                    if ($newStatus === 'accepted') {
                        createNotification($pdo, $studentIdForNotif, 'application_accepted',
                            'Your application for "' . $notifJobInfo['title'] . '" at ' . $notifJobInfo['company_name'] . ' has been accepted!',
                            'applications.php', $applicationId);
                    } elseif ($newStatus === 'rejected') {
                        createNotification($pdo, $studentIdForNotif, 'application_rejected',
                            'Your application for "' . $notifJobInfo['title'] . '" at ' . $notifJobInfo['company_name'] . ' has been rejected.',
                            'applications.php', $applicationId);
                    }
                }

                $pdo->commit();
                
                // User-friendly messages
                $statusDisplay = ucfirst($newStatus);
                if (!empty($studentName)) {
                    $_SESSION['toast_message'] = $studentName . ' has been ' . strtolower($statusDisplay) . ' successfully!';
                } else {
                    $_SESSION['toast_message'] = 'Applicant has been ' . strtolower($statusDisplay) . ' successfully!';
                }
                $_SESSION['toast_type'] = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['toast_message'] = 'Unable to update applicant status. Please try again.';
                $_SESSION['toast_type'] = 'error';
            }
        } else {
            $_SESSION['toast_message'] = 'No changes made to applicant status.';
            $_SESSION['toast_type'] = 'info';
        }
    } else {
        $_SESSION['toast_message'] = 'Application record not found. Please refresh and try again.';
        $_SESSION['toast_type'] = 'error';
    }

    header('Location: applicant.php');
    exit;
}

// Handle Notes / Interview details save (Removed interview fields)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_notes') {
    $applicationId = (int)($_POST['application_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');

    $pdo->beginTransaction();
    try {
        // Update only the status if provided
        if (!empty($newStatus)) {
            // Get current status and job info to manage vacancy slots
            $checkStmt = $pdo->prepare('
                SELECT a.status AS current_status, a.job_id
                FROM job_applications a
                INNER JOIN jobs j ON a.job_id = j.id
                LEFT JOIN companies c ON j.company_id = c.id
                WHERE a.id = ? AND (c.supervisor_id = ? OR j.created_by = ?)
            ');
            $checkStmt->execute([$applicationId, $userId, $userId]);
            $appInfo = $checkStmt->fetch();

            if ($appInfo) {
                $oldStatus = $appInfo['current_status'];
                $jobId = $appInfo['job_id'];

                if ($newStatus !== $oldStatus) {
                    $updateStmt = $pdo->prepare('UPDATE job_applications SET status = ?, updated_at = NOW() WHERE id = ?');
                    $updateStmt->execute([$newStatus, $applicationId]);

                    // Manage vacancy counters: increment if accepted, decrement if changed from accepted
                    if ($newStatus === 'accepted' && $oldStatus !== 'accepted') {
                        $pdo->prepare('UPDATE jobs SET slots_filled = slots_filled + 1 WHERE id = ?')->execute([$jobId]);
                    } elseif ($oldStatus === 'accepted' && $newStatus !== 'accepted') {
                        $pdo->prepare('UPDATE jobs SET slots_filled = GREATEST(0, slots_filled - 1) WHERE id = ?')->execute([$jobId]);
                    }

                    // --- Create notification for the student ---
                    $notifJobStmt2 = $pdo->prepare('SELECT j.title, c.company_name, a.student_id FROM job_applications a INNER JOIN jobs j ON a.job_id = j.id INNER JOIN companies c ON j.company_id = c.id WHERE a.id = ?');
                    $notifJobStmt2->execute([$applicationId]);
                    $notifJobInfo2 = $notifJobStmt2->fetch();
                    if ($notifJobInfo2) {
                        $studentIdForNotif2 = (int)$notifJobInfo2['student_id'];
                        if ($newStatus === 'accepted') {
                            createNotification($pdo, $studentIdForNotif2, 'application_accepted',
                                'Your application for "' . $notifJobInfo2['title'] . '" at ' . $notifJobInfo2['company_name'] . ' has been accepted!',
                                'applications.php', $applicationId);
                        } elseif ($newStatus === 'rejected') {
                            createNotification($pdo, $studentIdForNotif2, 'application_rejected',
                                'Your application for "' . $notifJobInfo2['title'] . '" at ' . $notifJobInfo2['company_name'] . ' has been rejected.',
                                'applications.php', $applicationId);
                        }
                    }
                }
            }
        }

        $pdo->commit();
        $_SESSION['toast_message'] = 'Applicant status updated successfully.';
        $_SESSION['toast_type'] = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['toast_message'] = 'Failed to update applicant. Please try again.';
        $_SESSION['toast_type'] = 'error';
    }

    header('Location: applicant.php');
    exit;
}

// Fetch all applicants for jobs created/managed by this supervisor
$applicantsStmt = $pdo->prepare('
    SELECT a.*, 
           j.title AS job_title, j.slots_available, j.slots_filled,
           c.company_name,
           u.username AS student_username, u.email AS student_email,
           u.firstname AS student_firstname, u.middlename AS student_middlename,
           u.lastname AS student_lastname, u.suffix AS student_suffix,
           u.phone AS student_phone, u.address AS student_address, u.birthdate AS student_birthdate
    FROM job_applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN companies c ON j.company_id = c.id
    INNER JOIN users u ON a.student_id = u.id
    WHERE c.supervisor_id = ? OR j.created_by = ?
    ORDER BY a.application_date DESC
');
$applicantsStmt->execute([$userId, $userId]);
$applicants = $applicantsStmt->fetchAll();

// Fetch notifications for bell
$notifUnreadCount = getUnreadCount($pdo, $userId);
$notifRecent = getRecentNotifications($pdo, $userId);

$activeApplicants = array_values(array_filter($applicants, function ($app) {
    return !in_array($app['status'], ['accepted', 'rejected'], true);
}));

$decisionApplicants = array_values(array_filter($applicants, function ($app) {
    return in_array($app['status'], ['accepted', 'rejected'], true);
}));

function getFileIconClass($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return 'fa-solid fa-file-pdf';
    } elseif (in_array($ext, ['doc', 'docx'])) {
        return 'fa-solid fa-file-word';
    }
    return 'fa-solid fa-file-lines';
}

// Get toast message and type
$toastMessage = $_SESSION['toast_message'] ?? '';
$toastType = $_SESSION['toast_type'] ?? 'success';
unset($_SESSION['toast_message'], $_SESSION['toast_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor - Manage Applicants</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        /* ----- Reset / base overrides ----- */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
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
            max-width: 100vw;
            overflow-x: hidden;
        }

        /* ----- SIDEBAR - FIXED/STICKY ----- */
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
            overflow-y: auto;
            z-index: 100;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
            flex-shrink: 0;
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
            flex-shrink: 0;
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

        /* ----- MAIN CONTENT - SCROLLABLE ----- */
        .main-content {
            flex: 1;
            padding: 0 32px 32px 32px;
            display: flex;
            flex-direction: column;
            min-width: 0;
            width: 100%;
            overflow-y: auto;
            height: 100vh;
        }

        /* ----- TOP HEADER (blue theme matching sidebar) ----- */
        .top-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 32px;
    background: #0f172a;
    margin: 0 -32px 24px -32px;
    flex-wrap: wrap;
    gap: 12px;
    flex-shrink: 0;

    /* ADD THESE */
    position: sticky;
    top: 0;
    z-index: 200; /* higher than .sidebar's z-index: 100 */
}

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
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
            flex-wrap: wrap;
        }

        /* Notification bell */
        .notif-bell-wrapper {
            position: relative;
            display: inline-block;
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
            width: 100%;
            overflow: hidden;
            flex-shrink: 0;
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
        }
        
        .modal-container {
            background: #ffffff;
            width: 100%;
            max-width: 720px;
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
            flex-shrink: 0;
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
            flex-wrap: wrap;
        }

        /* Layout & Table styles */
        .table-wrapper {
            overflow-x: auto;
            margin-top: 16px;
            -webkit-overflow-scrolling: touch;
            scroll-behavior: smooth;
        }

        .table-wrapper::-webkit-scrollbar {
            height: 6px;
        }

        .table-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }

        .table-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        .table-container {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            overflow: hidden;
            min-width: 0;
            width: 100%;
        }
        
        .applicant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        .applicant-table thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .applicant-table thead th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            white-space: nowrap;
        }
        
        .applicant-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s ease;
        }
        
        .applicant-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .applicant-table tbody td {
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
            box-sizing: border-box;
        }

        /* Buttons & Badges */
        .btn-view {
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
            background: #e0e7ff;
            color: #4338ca;
        }
        
        .btn-view:hover {
            background: #c7d2fe;
            transform: translateY(-1px);
        }

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

        /* Styled Status Select Dropdown */
        .status-select {
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1.5px solid #cbd5e1;
            cursor: pointer;
            outline: none;
            transition: all 0.15s ease;
            width: 130px;
        }

        .status-select.select-pending {
            background: #fef3c7;
            color: #d97706;
            border-color: #fcd34d;
        }

        .status-select.select-reviewed {
            background: #e0f2fe;
            color: #0369a1;
            border-color: #7dd3fc;
        }

        .status-select.select-shortlisted {
            background: #ece9ff;
            color: #6d28d9;
            border-color: #c084fc;
        }

        .status-select.select-accepted {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        }

        .status-select.select-rejected {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .status-select.select-withdrawn {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }

        /* ===== ACTION BUTTONS INLINE FIX ===== */
        .action-buttons-inline {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: nowrap;
        }

        .btn-status-accept {
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1.5px solid #10b981;
            background: transparent;
            color: #10b981;
            white-space: nowrap;
        }

        .btn-status-accept:hover {
            background: #d1fae5;
            color: #065f46;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
        }

        .btn-status-reject {
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1.5px solid #ef4444;
            background: transparent;
            color: #ef4444;
            white-space: nowrap;
        }

        .btn-status-reject:hover {
            background: #fee2e2;
            color: #991b1b;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
        }

        /* Action Column */
        .action-column {
            text-align: left;
            white-space: nowrap;
            min-width: 180px;
        }

        .decision-toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
            margin: 12px 0 16px;
        }

        .decision-filter {
            padding: 10px 14px;
            border-radius: 10px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            font-size: 0.9rem;
            color: #0f172a;
            min-width: 180px;
            outline: none;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .status-chip.accepted {
            background: #d1fae5;
            color: #065f46;
        }

        .status-chip.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-chip.pending {
            background: #fef3c7;
            color: #b45309;
        }

        .status-chip.reviewed {
            background: #e0f2fe;
            color: #075985;
        }

        .status-chip.shortlisted {
            background: #ede9fe;
            color: #6d28d9;
        }

        .status-chip.withdrawn {
            background: #f1f5f9;
            color: #475569;
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
            padding: 12px 16px;
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
            word-break: break-word;
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
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            font-size: 0.9rem;
            color: #334155;
            word-break: break-word;
        }

        /* Document Links */
        .doc-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            background: #f1f5f9;
            color: #334155;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            text-decoration: none;
        }

        .doc-link:hover {
            background: #e2e8f0;
            color: #1e293b;
            transform: translateY(-1px);
        }

        .doc-link i {
            color: #ef4444;
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
            z-index: 9999;
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

        .toast.info {
            background: #2563eb;
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

        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
                padding: 20px 14px;
            }
            
            .main-content {
                padding: 0 20px 20px 20px;
            }
            
            .top-header {
                margin: 0 -20px 20px -20px;
                padding: 14px 20px;
            }
        }

        @media (max-width: 768px) {
            .app-shell {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                top: 0;
                padding: 16px;
                flex-direction: row;
                flex-wrap: wrap;
                align-items: center;
                gap: 12px;
                overflow-y: visible;
            }
            
            .sidebar-brand {
                margin-bottom: 0;
                flex: 1;
            }
            
            .nav-section {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 4px;
                flex: 1 1 100%;
                order: 3;
            }
            
            .nav-item {
                padding: 8px 12px;
                font-size: 0.85rem;
                flex: 1 1 auto;
                min-width: 100px;
                justify-content: center;
            }
            
            .sidebar-footer {
                margin-top: 0;
                border-top: none;
                padding-top: 0;
                order: 2;
            }
            
            .logout-btn-side {
                padding: 8px 12px;
                font-size: 0.85rem;
            }
            
            .main-content {
                padding: 0 16px 16px 16px;
                height: auto;
                overflow-y: visible;
            }
            
            .top-header {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 16px;
                margin: 0 -16px 16px -16px;
            }
            
            .header-left h1 {
                font-size: 1.2rem;
            }
            
            .header-left h1 small {
                display: block;
                margin-left: 0;
                font-size: 0.75rem;
            }
            
            .header-right {
                justify-content: flex-start;
                gap: 12px;
            }
            
            .page-card {
                padding: 16px;
                border-radius: 16px;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons-inline {
                flex-wrap: wrap;
            }
            
            .action-column {
                min-width: auto;
            }
            
            .toast {
                bottom: 20px;
                right: 20px;
                left: 20px;
                padding: 14px 18px;
                font-size: 0.9rem;
                max-width: none;
            }
            
            .modal-container {
                max-width: 100%;
                border-radius: 20px;
                margin: 10px;
            }
            
            .modal-header {
                padding: 16px 20px;
            }
            
            .modal-body {
                padding: 16px 20px;
            }
            
            .modal-footer {
                padding: 14px 20px;
                flex-direction: column;
                gap: 8px;
            }
            
            .modal-footer .btn-sec-outline,
            .modal-footer .btn-prim-blue {
                width: 100%;
                justify-content: center;
            }
            
            .search-box {
                max-width: 100%;
            }
            
            .decision-toolbar {
                justify-content: stretch;
            }
            
            .decision-filter {
                width: 100%;
                min-width: auto;
            }
            
            .applicant-table {
                font-size: 0.8rem;
            }
            
            .applicant-table thead th,
            .applicant-table tbody td {
                padding: 10px 12px;
            }
            
            .table-wrapper {
                margin-top: 12px;
                margin-left: -16px;
                margin-right: -16px;
                padding: 0 16px;
                width: calc(100% + 32px);
            }
        }

        @media (max-width: 480px) {
            .sidebar {
                padding: 12px;
            }
            
            .nav-item {
                font-size: 0.75rem;
                padding: 6px 10px;
                min-width: 70px;
            }
            
            .sidebar-brand h2 {
                font-size: 1rem;
            }
            
            .top-header {
                padding: 10px 12px;
                margin: 0 -12px 12px -12px;
            }
            
            .main-content {
                padding: 0 12px 12px 12px;
            }
            
            .page-card {
                padding: 12px;
            }
            
            .applicant-table thead th,
            .applicant-table tbody td {
                padding: 8px 10px;
                font-size: 0.75rem;
            }
            
            .toast {
                bottom: 12px;
                right: 12px;
                left: 12px;
                padding: 12px 16px;
                font-size: 0.85rem;
                border-radius: 12px;
            }
            
            .btn-view {
                padding: 4px 8px;
                font-size: 0.7rem;
            }
            
            .btn-status-accept,
            .btn-status-reject {
                padding: 4px 8px;
                font-size: 10px;
            }
            
            .status-chip {
                padding: 4px 8px;
                font-size: 0.65rem;
            }
            
            .modal-header h3 {
                font-size: 1.1rem;
            }
            
            .detail-card {
                padding: 10px 12px;
            }
            
            .detail-card .val {
                font-size: 0.85rem;
            }
        }

        <?php renderNotifStyles(); ?>
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
                <a class="nav-item active" href="applicant.php"><i class="fa-solid fa-users"></i> Applicants</a>
                <a class="nav-item" href="myintern.php"><i class="fa-solid fa-user-graduate"></i> My Interns</a>
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
                        Manage Applicants
                        <small>Supervisor</small>
                    </h1>
                </div>
                <div class="header-right">
                    <div class="notif-bell-wrapper">
                        <?php renderNotifBell($notifUnreadCount); ?>
                        <?php renderNotifDropdown($notifRecent); ?>
                    </div>
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

            <!-- Table Card -->
            <div class="page-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h2 style="margin: 0; font-size: 1.3rem;">Applied Candidates</h2>
                        <p style="margin: 4px 0 0; font-size: 0.85rem; color:#64748b;">Review qualification documents and update statuses of student applicants.</p>
                    </div>
                    <div style="font-size: 0.85rem; font-weight: 600; color: #475569;">
                        Active Applicants: <span style="color:#2563eb; font-weight: 700;"><?php echo count($activeApplicants); ?></span>
                    </div>
                </div>

                <!-- Live Search Box -->
                <div class="search-row">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="applicantSearchInput" onkeyup="filterApplicantsTable()" placeholder="Search by student name, company, or job title..." />
                    </div>
                </div>

                <?php if (count($activeApplicants) > 0): ?>
                    <div class="table-wrapper">
                        <div class="table-container">
                            <table class="applicant-table" id="applicantsTable">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Applied Position</th>
                                        <th>Company</th>
                                        <th>Applied Date</th>
                                        <th>Documents</th>
                                        <th class="action-column">Action</th>
                                        <th style="text-align: center;">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeApplicants as $app): ?>
                                        <?php 
                                        $appId = (int)$app['id'];
                                        
                                        // Build student fullname
                                        $studentName = $app['student_firstname'] ?? '';
                                        if (!empty($app['student_middlename'])) {
                                            $studentName .= ' ' . $app['student_middlename'];
                                        }
                                        $studentName .= ' ' . ($app['student_lastname'] ?? '');
                                        if (!empty($app['student_suffix'])) {
                                            $studentName .= ' ' . $app['student_suffix'];
                                        }
                                        $studentName = trim($studentName);
                                        if (empty($studentName)) {
                                            $studentName = $app['student_username'] ?? 'Student';
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong style="color: #0f172a; font-size: 0.95rem;"><?php echo htmlspecialchars($studentName); ?></strong>
                                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                                    <i class="fa-regular fa-envelope" style="margin-right: 2px;"></i> <?php echo htmlspecialchars($app['student_email']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($app['job_title']); ?></td>
                                            <td>
                                                <span style="font-size:0.75rem; background:#f1f5f9; padding:2px 8px; border-radius:999px; font-weight: 600; color:#475569;">
                                                    <?php echo htmlspecialchars($app['company_name']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <i class="fa-regular fa-clock" style="color: #94a3b8; margin-right: 4px; font-size: 0.8rem;"></i>
                                                <?php echo htmlspecialchars(date('M d, Y', strtotime($app['application_date']))); ?>
                                            </td>
                                            <td>
                                                 <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                                     <?php if (!empty($app['cv_path'])): ?>
                                                         <a href="../<?php echo htmlspecialchars($app['cv_path']); ?>" class="doc-link" title="View CV" onclick="event.preventDefault(); openDocViewer(this.href, 'CV - <?php echo htmlspecialchars(addslashes($studentName)); ?>');">
                                                             <i class="<?php echo getFileIconClass($app['cv_path']); ?>"></i> CV
                                                         </a>
                                                     <?php endif; ?>

                                                     <?php if (!empty($app['resume_path'])): ?>
                                                         <a href="../<?php echo htmlspecialchars($app['resume_path']); ?>" class="doc-link" title="View Resume" onclick="event.preventDefault(); openDocViewer(this.href, 'Resume - <?php echo htmlspecialchars(addslashes($studentName)); ?>');">
                                                             <i class="<?php echo getFileIconClass($app['resume_path']); ?>"></i> Res
                                                         </a>
                                                     <?php endif; ?>

                                                     <?php if (!empty($app['application_letter_path'])): ?>
                                                         <a href="../<?php echo htmlspecialchars($app['application_letter_path']); ?>" class="doc-link" title="View Application Letter" onclick="event.preventDefault(); openDocViewer(this.href, 'App Letter - <?php echo htmlspecialchars(addslashes($studentName)); ?>');">
                                                             <i class="<?php echo getFileIconClass($app['application_letter_path']); ?>"></i> Let
                                                         </a>
                                                     <?php endif; ?>
                                                 </div>
                                            </td>
                                            <td class="action-column">
                                                <?php 
                                                $status = htmlspecialchars($app['status']);
                                                if ($status === 'withdrawn'): 
                                                ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: #f1f5f9; color: #475569;">
                                                        Withdrawn
                                                    </span>
                                                <?php else: ?>
                                                    <div class="action-buttons-inline">
                                                        <form method="POST" action="applicant.php" style="margin: 0; display: inline;">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                                                            <input type="hidden" name="status" value="accepted">
                                                            <button type="submit" class="btn-status-accept" title="Accept Candidate">
                                                                <i class="fa-solid fa-circle-check"></i> Accept
                                                            </button>
                                                        </form>
                                                        <form method="POST" action="applicant.php" style="margin: 0; display: inline;" onsubmit="return confirm('Are you sure you want to reject this applicant?')">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                                                            <input type="hidden" name="status" value="rejected">
                                                            <button type="submit" class="btn-status-reject" title="Reject Candidate">
                                                                <i class="fa-solid fa-circle-xmark"></i> Reject
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <button type="button" class="btn-view" onclick="openDetailsModal(<?php echo $appId; ?>)">
                                                    <i class="fa-solid fa-folder-open"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="margin-top: 16px;">
                        <i class="fa-solid fa-users"></i>
                        <h3>No Student Applications</h3>
                        <p>No undecided student applications are available right now.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="page-card" style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h2 style="margin: 0; font-size: 1.3rem;">Applied Candidates Decisions</h2>
                        <p style="margin: 4px 0 0; font-size: 0.85rem; color:#64748b;">Accepted and rejected applicants recorded by the supervisor.</p>
                    </div>
                    <div style="font-size: 0.85rem; font-weight: 600; color: #475569;">
                        Decision Records: <span style="color:#2563eb; font-weight: 700;"><?php echo count($decisionApplicants); ?></span>
                    </div>
                </div>

                <div class="decision-toolbar">
                    <select id="decisionStatusFilter" class="decision-filter" onchange="filterDecisionTable()">
                        <option value="all">All Decisions</option>
                        <option value="accepted">Accepted</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>

                <?php if (count($decisionApplicants) > 0): ?>
                    <div class="table-wrapper">
                        <div class="table-container">
                            <table class="applicant-table" id="decisionTable">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Applied Position</th>
                                        <th>Company</th>
                                        <th>Decision Date</th>
                                        <th>Status</th>
                                        <th style="text-align: center;">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($decisionApplicants as $app): ?>
                                        <?php
                                        $appId = (int)$app['id'];

                                        $studentName = $app['student_firstname'] ?? '';
                                        if (!empty($app['student_middlename'])) {
                                            $studentName .= ' ' . $app['student_middlename'];
                                        }
                                        $studentName .= ' ' . ($app['student_lastname'] ?? '');
                                        if (!empty($app['student_suffix'])) {
                                            $studentName .= ' ' . $app['student_suffix'];
                                        }
                                        $studentName = trim($studentName);
                                        if (empty($studentName)) {
                                            $studentName = $app['student_username'] ?? 'Student';
                                        }
                                        $status = strtolower($app['status'] ?? '');
                                        ?>
                                        <tr data-status="<?php echo htmlspecialchars($status); ?>">
                                            <td>
                                                <strong style="color: #0f172a; font-size: 0.95rem;"><?php echo htmlspecialchars($studentName); ?></strong>
                                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                                    <i class="fa-regular fa-envelope" style="margin-right: 2px;"></i> <?php echo htmlspecialchars($app['student_email']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($app['job_title']); ?></td>
                                            <td>
                                                <span style="font-size:0.75rem; background:#f1f5f9; padding:2px 8px; border-radius:999px; font-weight: 600; color:#475569;">
                                                    <?php echo htmlspecialchars($app['company_name']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <i class="fa-regular fa-clock" style="color: #94a3b8; margin-right: 4px; font-size: 0.8rem;"></i>
                                                <?php echo htmlspecialchars(date('M d, Y', strtotime($app['updated_at'] ?? $app['application_date']))); ?>
                                            </td>
                                            <td>
                                                <span class="status-chip <?php echo htmlspecialchars($status); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($status)); ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <button type="button" class="btn-view" onclick="openDetailsModal(<?php echo $appId; ?>)">
                                                    <i class="fa-solid fa-folder-open"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="margin-top: 16px;">
                        <i class="fa-solid fa-badge-check"></i>
                        <h3>No Decision Records</h3>
                        <p>Accepted and rejected candidates will appear here after a supervisor makes a decision.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- DETAILS / VIEW MODAL -->
    <div id="detailsModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-address-card" style="color: #2563eb;"></i> Candidate Profile</h3>
                <button type="button" class="modal-close-btn" onclick="closeDetailsModal()">&times;</button>
            </div>
            
            <form method="POST" action="applicant.php">
                <input type="hidden" name="action" value="save_notes">
                <input type="hidden" name="application_id" id="modal_app_id">
                <input type="hidden" name="status" id="modal_status" value="">

                <div class="modal-body">
                    <h4 style="margin: 0 0 10px 0; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 6px;">Student Demographics</h4>
                    
                    <div class="details-grid">
                        <div class="detail-card">
                            <div class="lbl">Full Name</div>
                            <div id="m_fullname" class="val">-</div>
                        </div>
                        <div class="detail-card">
                            <div class="lbl">Email Address</div>
                            <div id="m_email" class="val">-</div>
                        </div>
                        <div class="detail-card">
                            <div class="lbl">Contact Phone</div>
                            <div id="m_phone" class="val">-</div>
                        </div>
                        <div class="detail-card">
                            <div class="lbl">Birth Date</div>
                            <div id="m_birthdate" class="val">-</div>
                        </div>
                    </div>

                    <div class="content-section">
                        <h4>Mailing Address</h4>
                        <div id="m_address" class="content-body" style="background:#fafcff;">-</div>
                    </div>

                    <h4 style="margin: 24px 0 10px 0; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 6px;">Internship Selection</h4>
                    
                    <div class="details-grid">
                        <div class="detail-card">
                            <div class="lbl">Target Internship Position</div>
                            <div id="m_position" class="val">-</div>
                        </div>
                        <div class="detail-card">
                            <div class="lbl">Host Company</div>
                            <div id="m_company" class="val">-</div>
                        </div>
                    </div>

                    <div class="content-section">
                        <h4>Submitted Qualification Documents</h4>
                        <div style="display: flex; gap: 12px; margin-top: 4px; flex-wrap: wrap;" id="m_docs_container">
                            <!-- Populated in Javascript -->
                        </div>
                    </div>
                </div>

                <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">
                    <div id="modal_action_buttons" style="display: flex; gap: 8px;"></div>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <button type="button" class="btn-sec-outline" onclick="closeDetailsModal()">Close</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- DOCUMENT VIEWER MODAL -->
    <div id="docViewerModal" class="modal-overlay" style="z-index: 1100;">
        <div class="modal-container" style="max-width: 900px; height: 90vh;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-file-lines" style="color: #2563eb;"></i> <span id="docViewerTitle">Document Viewer</span></h3>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a id="docViewerDownloadBtn" href="#" class="btn-view" style="background: #e2e8f0; color: #1e293b;" download>
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                    <button type="button" class="modal-close-btn" onclick="closeDocViewerModal()">&times;</button>
                </div>
            </div>
            <div class="modal-body" style="padding: 0; flex-grow: 1; display: flex; flex-direction: column; overflow: hidden; max-height: none; height: calc(100% - 60px);">
                <div id="docViewerLoading" style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex-grow: 1; padding: 40px; color: #64748b;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 32px; margin-bottom: 12px; color: #2563eb;"></i>
                    <span>Loading document preview...</span>
                </div>
                <iframe id="docViewerIframe" src="" style="width: 100%; height: 100%; border: none; display: none;" onload="onDocViewerFrameLoaded()"></iframe>
                
                <div id="docViewerFallback" style="display: none; flex-direction: column; align-items: center; justify-content: center; flex-grow: 1; padding: 40px; text-align: center;">
                    <i class="fa-regular fa-file-word" style="font-size: 64px; color: #2b579a; margin-bottom: 20px;"></i>
                    <h4 style="margin: 0 0 10px; color: #0f172a; font-size: 1.2rem;">Office Document (.doc/.docx)</h4>
                    <p style="margin: 0 0 24px; color: #64748b; max-width: 400px; font-size: 0.9rem;">
                        This document type cannot be directly previewed in the browser. You can download the file to view its contents on your device.
                    </p>
                    <a id="docViewerFallbackBtn" href="#" class="btn-prim-blue" download>
                        <i class="fa-solid fa-download"></i> Download Document
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== TOAST ===== -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <!-- Javascript Actions -->
    <script>
        const applicantsList = <?php echo json_encode($applicants); ?>;

        // ===== TOAST =====
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            // Set icon based on type
            const icon = toast.querySelector('i');
            if (type === 'success') {
                icon.className = 'fa-regular fa-circle-check';
            } else if (type === 'error') {
                icon.className = 'fa-regular fa-circle-xmark';
            } else if (type === 'info') {
                icon.className = 'fa-regular fa-circle-info';
            }
            
            toast.className = 'toast ' + type + ' show';
            toastMessage.textContent = message;
            
            clearTimeout(toast._timeout);
            toast._timeout = setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        // Check for session toast messages
        <?php if (!empty($toastMessage)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo htmlspecialchars($toastMessage); ?>', '<?php echo $toastType; ?>');
            });
        <?php endif; ?>

        // Toast click to dismiss
        document.getElementById('toast').addEventListener('click', function() {
            this.classList.remove('show');
        });

        function openDetailsModal(appId) {
            const app = applicantsList.find(a => parseInt(a.id) === parseInt(appId));
            if (!app) return;

            document.getElementById('modal_app_id').value = app.id;

            // Student profile
            let fullname = app.student_firstname || '';
            if (app.student_middlename) fullname += ' ' + app.student_middlename;
            fullname += ' ' + (app.student_lastname || '');
            if (app.student_suffix) fullname += ' ' + app.student_suffix;
            fullname = fullname.trim();

            document.getElementById('m_fullname').innerText = fullname || app.student_username || '-';
            document.getElementById('m_email').innerText = app.student_email || '-';
            document.getElementById('m_phone').innerText = app.student_phone || 'None provided';
            document.getElementById('m_birthdate').innerText = app.student_birthdate || 'Not entered';
            document.getElementById('m_address').innerText = app.student_address || 'No address provided';

            // Job Profile
            document.getElementById('m_position').innerText = app.job_title || '-';
            document.getElementById('m_company').innerText = app.company_name || '-';

            // Document Links
            const docContainer = document.getElementById('m_docs_container');
            docContainer.innerHTML = '';

            const escapeQuote = (str) => (str || '').replace(/'/g, "\\'");
            const escapedName = escapeQuote(fullname || app.student_username || 'Student');

            if (app.cv_path) {
                const ext = app.cv_path.split('.').pop().toLowerCase();
                const iconClass = ext === 'pdf' ? 'fa-solid fa-file-pdf' : (['doc', 'docx'].includes(ext) ? 'fa-solid fa-file-word' : 'fa-solid fa-file-lines');
                docContainer.innerHTML += `
                    <a href="../${app.cv_path}" class="doc-link" title="View CV" onclick="event.preventDefault(); openDocViewer(this.href, 'CV - ${escapedName}');">
                        <i class="${iconClass}"></i> View CV
                    </a>
                `;
            } else {
                docContainer.innerHTML += `<span style="font-size:0.85rem; color:#94a3b8;"><i class="fa-solid fa-ban"></i> CV not submitted</span>`;
            }

            if (app.resume_path) {
                const ext = app.resume_path.split('.').pop().toLowerCase();
                const iconClass = ext === 'pdf' ? 'fa-solid fa-file-pdf' : (['doc', 'docx'].includes(ext) ? 'fa-solid fa-file-word' : 'fa-solid fa-file-lines');
                docContainer.innerHTML += `
                    <a href="../${app.resume_path}" class="doc-link" title="View Resume" onclick="event.preventDefault(); openDocViewer(this.href, 'Resume - ${escapedName}');">
                        <i class="${iconClass}"></i> View Resume
                    </a>
                `;
            } else {
                docContainer.innerHTML += `<span style="font-size:0.85rem; color:#94a3b8;"><i class="fa-solid fa-ban"></i> Resume not submitted</span>`;
            }

            if (app.application_letter_path) {
                const ext = app.application_letter_path.split('.').pop().toLowerCase();
                const iconClass = ext === 'pdf' ? 'fa-solid fa-file-pdf' : (['doc', 'docx'].includes(ext) ? 'fa-solid fa-file-word' : 'fa-solid fa-file-lines');
                docContainer.innerHTML += `
                    <a href="../${app.application_letter_path}" class="doc-link" title="View App Letter" onclick="event.preventDefault(); openDocViewer(this.href, 'App Letter - ${escapedName}');">
                        <i class="${iconClass}"></i> View Letter
                    </a>
                `;
            } else {
                docContainer.innerHTML += `<span style="font-size:0.85rem; color:#94a3b8;"><i class="fa-solid fa-ban"></i> Letter not submitted</span>`;
            }

            document.getElementById('modal_status').value = '';
            const actionButtons = document.getElementById('modal_action_buttons');

            if (app.status === 'withdrawn') {
                actionButtons.style.display = 'none';
            } else {
                actionButtons.style.display = 'flex';
            }

            document.getElementById('detailsModal').style.display = 'flex';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        function openDocViewer(filePath, docTitle) {
            const modal = document.getElementById('docViewerModal');
            const iframe = document.getElementById('docViewerIframe');
            const fallback = document.getElementById('docViewerFallback');
            const loading = document.getElementById('docViewerLoading');
            const downloadBtn = document.getElementById('docViewerDownloadBtn');
            const titleSpan = document.getElementById('docViewerTitle');
            const fallbackBtn = document.getElementById('docViewerFallbackBtn');

            titleSpan.innerText = docTitle;
            downloadBtn.href = filePath;
            fallbackBtn.href = filePath;

            const ext = filePath.split('.').pop().toLowerCase();

            if (ext === 'pdf') {
                loading.style.display = 'flex';
                iframe.style.display = 'none';
                fallback.style.display = 'none';
                iframe.src = filePath;
            } else {
                loading.style.display = 'none';
                iframe.style.display = 'none';
                iframe.src = '';
                fallback.style.display = 'flex';
            }

            modal.style.display = 'flex';
        }

        function onDocViewerFrameLoaded() {
            const iframe = document.getElementById('docViewerIframe');
            const loading = document.getElementById('docViewerLoading');
            if (iframe.src && !iframe.src.endsWith('#') && iframe.src !== window.location.href) {
                loading.style.display = 'none';
                iframe.style.display = 'block';
            }
        }

        function closeDocViewerModal() {
            const modal = document.getElementById('docViewerModal');
            const iframe = document.getElementById('docViewerIframe');
            iframe.src = ''; 
            modal.style.display = 'none';
        }

        // Close on overlay Click
        window.onclick = function(event) {
            const detailsModal = document.getElementById('detailsModal');
            const docViewerModal = document.getElementById('docViewerModal');
            if (event.target === detailsModal) {
                closeDetailsModal();
            } else if (event.target === docViewerModal) {
                closeDocViewerModal();
            }
        };

        // Table Instant Search Filtering logic
        function filterApplicantsTable() {
            const input = document.getElementById('applicantSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('applicantsTable');
            if (!table) return;
            
            const trs = table.getElementsByTagName('tr');

            for (let i = 1; i < trs.length; i++) {
                const tr = trs[i];
                let display = false;
                
                const nameCell = tr.cells[0];
                const titleCell = tr.cells[1];
                const companyCell = tr.cells[2];

                if (nameCell || titleCell || companyCell) {
                    const text = (nameCell.textContent + ' ' + titleCell.textContent + ' ' + companyCell.textContent).toLowerCase();
                    if (text.indexOf(filter) > -1) {
                        display = true;
                    }
                }
                tr.style.display = display ? '' : 'none';
            }
        }

        function filterDecisionTable() {
            const filterSelect = document.getElementById('decisionStatusFilter');
            const filterValue = filterSelect ? filterSelect.value : 'all';
            const table = document.getElementById('decisionTable');
            if (!table) return;

            const rows = table.querySelectorAll('tbody tr');
            rows.forEach((row) => {
                const rowStatus = (row.dataset.status || '').toLowerCase();
                const shouldShow = filterValue === 'all' || rowStatus === filterValue;
                row.style.display = shouldShow ? '' : 'none';
            });
        }
    </script>
    <?php renderNotifScript('../includes/'); ?>
</body>
</html>