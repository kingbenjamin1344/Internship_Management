<?php
// supervisor/dashboard.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/supervisor_notifications.php';



// Check if user is supervisor
checkAccess('supervisor');
ensureInternshipTables($pdo);

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Supervisor';
$role = getUserRole();
$userId = getUserId();

// ===== PROFILE PICTURE SETTINGS =====
$avatarUploadDir = __DIR__ . '/../assets/uploads/avatars/';
$avatarPublicPath = '../assets/uploads/avatars/';

function getUserProfilePicture($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

// ===== NOTIFICATION FUNCTIONS =====

/**
 * Get unread notifications count for supervisor
 */
function getUnreadNotificationCount($pdo, $supervisor_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$supervisor_id]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Notification count error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get notifications for supervisor with pagination
 */
function getNotifications($pdo, $supervisor_id, $limit = 20, $offset = 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                n.*,
                u.firstname,
                u.lastname,
                u.profile_picture
            FROM notifications n
            LEFT JOIN users u ON n.sender_id = u.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$supervisor_id, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get notifications error: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as read
 */
function markNotificationRead($pdo, $notification_id, $supervisor_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([$notification_id, $supervisor_id]);
    } catch (PDOException $e) {
        error_log("Mark notification read error: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read
 */
function markAllNotificationsRead($pdo, $supervisor_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        return $stmt->execute([$supervisor_id]);
    } catch (PDOException $e) {
        error_log("Mark all notifications read error: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for supervisor
 */
function createNotification($pdo, $user_id, $sender_id, $type, $title, $message, $link = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, sender_id, type, title, message, link, created_at, is_read)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)
        ");
        return $stmt->execute([$user_id, $sender_id, $type, $title, $message, $link]);
    } catch (PDOException $e) {
        error_log("Create notification error: " . $e->getMessage());
        return false;
    }
}

// ===== SCORING ANALYTICS FUNCTIONS =====

/**
 * Calculate cohort average score for a given company/job
 */
function getCohortAverage($pdo, $student_id, $company_id = null) {
    try {
        // Get the student's company if not provided
        if (!$company_id) {
            $stmt = $pdo->prepare("
                SELECT c.id 
                FROM users u
                INNER JOIN job_applications a ON u.id = a.student_id
                INNER JOIN jobs j ON a.job_id = j.id
                INNER JOIN companies c ON j.company_id = c.id
                WHERE u.id = ? AND a.status = 'committed'
                LIMIT 1
            ");
            $stmt->execute([$student_id]);
            $company = $stmt->fetch();
            if (!$company) return null;
            $company_id = $company['id'];
        }
        
        // Get all evaluated DPRs for students in this company
        $stmt = $pdo->prepare("
            SELECT 
                d.score,
                d.student_id,
                d.evaluated_at,
                s.firstname,
                s.lastname
            FROM dpr_entries d
            INNER JOIN users s ON d.student_id = s.id
            INNER JOIN job_applications a ON s.id = a.student_id
            INNER JOIN jobs j ON a.job_id = j.id
            WHERE j.company_id = ? 
            AND d.score IS NOT NULL 
            AND d.score > 0
            AND a.status = 'committed'
            ORDER BY d.evaluated_at DESC
        ");
        $stmt->execute([$company_id]);
        $allScores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($allScores)) return null;
        
        // Calculate cohort statistics
        $scores = array_column($allScores, 'score');
        $avg = array_sum($scores) / count($scores);
        $stddev = calculateStdDev($scores, $avg);
        
        // Get student's own scores for comparison
        $stmt = $pdo->prepare("
            SELECT score, evaluated_at, id
            FROM dpr_entries 
            WHERE student_id = ? AND score IS NOT NULL AND score > 0
            ORDER BY evaluated_at DESC
        ");
        $stmt->execute([$student_id]);
        $studentScores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'cohort_avg' => round($avg, 2),
            'cohort_stddev' => round($stddev, 2),
            'cohort_size' => count($allScores),
            'student_scores' => $studentScores,
            'student_count' => count(array_unique(array_column($allScores, 'student_id'))),
            'all_scores' => $allScores
        ];
    } catch (PDOException $e) {
        error_log("Cohort average calculation error: " . $e->getMessage());
        return null;
    }
}

/**
 * Calculate standard deviation
 */
function calculateStdDev($scores, $mean = null) {
    if (empty($scores)) return 0;
    if ($mean === null) {
        $mean = array_sum($scores) / count($scores);
    }
    $variance = 0;
    foreach ($scores as $score) {
        $variance += pow($score - $mean, 2);
    }
    return sqrt($variance / count($scores));
}

/**
 * Detect extreme score drops
 */
function detectScoreDrops($pdo, $student_id, $company_id = null) {
    $cohortData = getCohortAverage($pdo, $student_id, $company_id);
    if (!$cohortData || empty($cohortData['student_scores'])) {
        return [];
    }
    
    $drops = [];
    $studentScores = $cohortData['student_scores'];
    $cohortAvg = $cohortData['cohort_avg'];
    $cohortStdDev = $cohortData['cohort_stddev'];
    
    // Check for significant drops between consecutive scores
    for ($i = 0; $i < count($studentScores) - 1; $i++) {
        $current = $studentScores[$i]['score'];
        $previous = $studentScores[$i + 1]['score'];
        $drop = $previous - $current;
        
        // Calculate drop significance
        $dropPercentage = ($drop / $previous) * 100;
        $zScore = ($current - $cohortAvg) / ($cohortStdDev > 0 ? $cohortStdDev : 1);
        
        // Flag if:
        // 1. Drop is more than 20% from previous score
        // 2. Score is more than 1.5 standard deviations below cohort average
        // 3. Score is below 70% (failing threshold) while previous was above 85%
        $isSignificant = false;
        $reason = [];
        
        if ($dropPercentage > 20) {
            $isSignificant = true;
            $reason[] = "Score dropped by " . round($dropPercentage, 1) . "% from previous score ({$previous}% → {$current}%)";
        }
        
        if (abs($zScore) > 1.5 && $current < $cohortAvg) {
            $isSignificant = true;
            $reason[] = "Score is " . round(abs($zScore), 2) . " standard deviations below cohort average";
        }
        
        if ($previous >= 85 && $current < 70) {
            $isSignificant = true;
            $reason[] = "Score dropped from excellent ({$previous}%) to failing ({$current}%)";
        }
        
        if ($isSignificant) {
            $drops[] = [
                'dpr_id' => $studentScores[$i]['id'],
                'current_score' => $current,
                'previous_score' => $previous,
                'drop_percentage' => round($dropPercentage, 1),
                'cohort_avg' => $cohortAvg,
                'cohort_stddev' => $cohortStdDev,
                'z_score' => round($zScore, 2),
                'evaluated_at' => $studentScores[$i]['evaluated_at'],
                'severity' => getDropSeverity($dropPercentage, $zScore, $current),
                'reasons' => $reason,
                'is_extreme' => ($dropPercentage > 30 || abs($zScore) > 2.5 || ($previous >= 85 && $current < 65))
            ];
        }
    }
    
    return $drops;
}

/**
 * Determine drop severity
 */
function getDropSeverity($dropPercentage, $zScore, $currentScore) {
    if ($dropPercentage > 40 || abs($zScore) > 3 || $currentScore < 60) {
        return 'critical';
    } elseif ($dropPercentage > 25 || abs($zScore) > 2 || $currentScore < 70) {
        return 'high';
    } elseif ($dropPercentage > 15 || abs($zScore) > 1.5) {
        return 'medium';
    } else {
        return 'low';
    }
}

/**
 * Get cohort performance summary
 */
function getCohortPerformanceSummary($pdo, $student_id) {
    $cohortData = getCohortAverage($pdo, $student_id);
    if (!$cohortData) return null;
    
    $allScores = $cohortData['all_scores'];
    $studentScores = $cohortData['student_scores'];
    
    // Calculate student's average
    $studentAvg = !empty($studentScores) ? array_sum(array_column($studentScores, 'score')) / count($studentScores) : 0;
    
    // Performance categories
    $categories = [
        'excellent' => 0,  // 90-100
        'good' => 0,       // 75-89
        'satisfactory' => 0, // 60-74
        'needs_improvement' => 0, // <60
    ];
    
    foreach ($allScores as $score) {
        $s = $score['score'];
        if ($s >= 90) $categories['excellent']++;
        elseif ($s >= 75) $categories['good']++;
        elseif ($s >= 60) $categories['satisfactory']++;
        else $categories['needs_improvement']++;
    }
    
    return [
        'cohort_avg' => $cohortData['cohort_avg'],
        'cohort_stddev' => $cohortData['cohort_stddev'],
        'student_avg' => round($studentAvg, 2),
        'total_evaluations' => count($allScores),
        'student_count' => $cohortData['student_count'],
        'performance_distribution' => $categories,
        'student_scores' => $studentScores
    ];
}

// ===== END SCORING ANALYTICS FUNCTIONS =====

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
            
            // Get cohort performance data (still calculated but not displayed)
            $cohortData = getCohortAverage($pdo, $student_id);
            $scoreDrops = detectScoreDrops($pdo, $student_id);
            $performanceSummary = getCohortPerformanceSummary($pdo, $student_id);
            
            // Format dates for display
            foreach ($dprEntries as &$entry) {
                $entry['date_formatted'] = date('d M Y', strtotime($entry['date']));
                $entry['time_in_formatted'] = $entry['time_in'] ? date('h:i A', strtotime($entry['time_in'])) : '—';
                $entry['time_out_formatted'] = $entry['time_out'] ? date('h:i A', strtotime($entry['time_out'])) : '—';
            }
            
            echo json_encode([
                'success' => true, 
                'data' => $dprEntries,
                'analytics' => [
                    'cohort' => $cohortData,
                    'score_drops' => $scoreDrops,
                    'performance_summary' => $performanceSummary
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit;
}

// Handle evaluation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'evaluate_dpr') {
    header('Content-Type: application/json');
    
    $dpr_id = $_POST['dpr_id'] ?? 0;
    $score = $_POST['score'] ?? null;
    $supervisor_feedback = $_POST['supervisor_feedback'] ?? '';
    
    if ($dpr_id > 0 && $score !== null && $score >= 1 && $score <= 100) {
        try {
            // Check if this DPR belongs to a student under this supervisor
            $checkStmt = $pdo->prepare("
                SELECT d.id, d.student_id 
                FROM dpr_entries d
                INNER JOIN job_applications a ON d.student_id = a.student_id
                INNER JOIN jobs j ON a.job_id = j.id
                INNER JOIN companies c ON j.company_id = c.id
                WHERE d.id = ? AND (c.supervisor_id = ? OR j.created_by = ?)
                AND a.status = 'committed'
            ");
            $checkStmt->execute([$dpr_id, $userId, $userId]);
            $dprInfo = $checkStmt->fetch();
            
            if (!$dprInfo) {
                echo json_encode(['success' => false, 'message' => 'You are not authorized to evaluate this DPR.']);
                exit;
            }
            
            // Check if already evaluated
            $checkEvalStmt = $pdo->prepare("SELECT evaluated_at FROM dpr_entries WHERE id = ?");
            $checkEvalStmt->execute([$dpr_id]);
            $existing = $checkEvalStmt->fetch();
            
            if ($existing && $existing['evaluated_at']) {
                echo json_encode(['success' => false, 'message' => 'This DPR has already been evaluated and cannot be modified.']);
                exit;
            }
            
            // Update the DPR
            $stmt = $pdo->prepare("
                UPDATE dpr_entries 
                SET score = ?, supervisor_feedback = ?, evaluated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$score, $supervisor_feedback, $dpr_id]);
            
            // Mark notification as read when DPR is evaluated
            $notifStmt = $pdo->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE user_id = ? AND link LIKE ? AND is_read = 0
            ");
            $notifStmt->execute([$userId, '%dpr_id=' . $dpr_id . '%']);
            
            // Check for score drops after evaluation
            $scoreDrops = detectScoreDrops($pdo, $dprInfo['student_id']);
            $hasExtremeDrop = false;
            $dropMessage = '';
            
            foreach ($scoreDrops as $drop) {
                if ($drop['is_extreme'] && $drop['dpr_id'] == $dpr_id) {
                    $hasExtremeDrop = true;
                    $dropMessage = "⚠️ EXTREME SCORE DROP DETECTED! " . implode('; ', $drop['reasons']);
                    break;
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Evaluation submitted successfully!',
                'score_drop_warning' => $hasExtremeDrop ? $dropMessage : null
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
    exit;
}

// Handle AJAX requests for password change and avatar update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Change password
    if ($_POST['action'] === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }
        if ($newPassword !== $confirmPassword) {
            echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
            exit;
        }
        if (strlen($newPassword) < 8) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($currentPassword, $hash)) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
                exit;
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$newHash, $userId]);

            echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Update profile picture
    if ($_POST['action'] === 'update_avatar' && isset($_FILES['avatar'])) {
        $file = $_FILES['avatar'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $maxSize = 2 * 1024 * 1024; // 2MB

        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Upload failed. Please try again.']);
            exit;
        }
        if (!in_array($file['type'], $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, WEBP or GIF images are allowed.']);
            exit;
        }
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'message' => 'Image must be smaller than 2MB.']);
            exit;
        }

        if (!is_dir($avatarUploadDir)) {
            @mkdir($avatarUploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newFileName = 'user_' . $userId . '_' . time() . '.' . strtolower($ext);
        $destination = $avatarUploadDir . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt->execute([$newFileName, $userId]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Profile picture updated.',
                    'path' => $avatarPublicPath . $newFileName
                ]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not save the uploaded file.']);
        }
        exit;
    }

    // Get notifications
    if ($_POST['action'] === 'get_notifications') {
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        
        $notifications = getSupervisorNotifications($pdo, $userId, $limit, $offset);
        $unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'total' => count($notifications)
        ]);
        exit;
    }

    // Mark notification as read
    if ($_POST['action'] === 'mark_read') {
        $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        
        if ($notification_id > 0) {
$result = markSupervisorNotificationRead($pdo, $notification_id, $userId);
        $unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);
            
            echo json_encode([
                'success' => $result,
                'unread_count' => $unreadCount
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        }
        exit;
    }

    // Mark all notifications as read
    if ($_POST['action'] === 'mark_all_read') {
        $result = markSupervisorAllNotificationsRead($pdo, $userId);
        echo json_encode([
            'success' => $result,
            'unread_count' => 0
        ]);
        exit;
    }
}

// Get notifications count for display
$unreadCount = getUnreadNotificationCount($pdo, $userId);
$notifications = getNotifications($pdo, $userId, 10, 0);

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

// Pre-calculate analytics for each intern for dashboard display (keep drop warnings on intern list)
$internAnalytics = [];
foreach ($acceptedInterns as $intern) {
    $scoreDrops = detectScoreDrops($pdo, $intern['student_id']);
    $extremeDrops = array_filter($scoreDrops, function($drop) {
        return $drop['is_extreme'];
    });
    $internAnalytics[$intern['student_id']] = [
        'has_extreme_drop' => !empty($extremeDrops),
        'extreme_drops' => $extremeDrops,
        'total_drops' => count($scoreDrops)
    ];
}

// Current profile picture
$profilePicture = getUserProfilePicture($pdo, $userId);
$profilePictureUrl = $profilePicture ? $avatarPublicPath . $profilePicture : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor - My Interns</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <style>
        /* ============================================================
           Dark Green (#003300) & Golden Yellow (#FFCC33) theme
           Sharp card edges, no rounded corners.
           Header spans full width, flush with top.
           ============================================================ */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: #f0f2f5;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: #0f172a;
        }

        .app-shell {
            display: flex;
            min-height: 100vh;
        }

        /* ---- Dark Green Sidebar (now a profile panel) ---- */
        .sidebar {
            width: 250px;
            background: #003300;
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            padding: 28px 18px 20px;
            flex-shrink: 0;
            border-right: 1px solid #1a4a1a;
            align-items: center;
            text-align: center;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
            padding: 0 6px;
        }

        .sidebar-brand i {
            font-size: 1.6rem;
            color: #FFCC33;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: #FFCC33;
        }

        .sidebar-brand h2 span {
            display: block;
            font-weight: 400;
            font-size: 0.65rem;
            color: #FFCC33;
            opacity: 0.8;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        /* ---- Profile panel (sidebar) ---- */
        .profile-panel {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }

        .avatar-editable {
            position: relative;
            width: 108px;
            height: 108px;
            margin-bottom: 16px;
            cursor: pointer;
        }

        .avatar-editable .avatar-img,
        .avatar-editable .avatar-initials {
            width: 108px;
            height: 108px;
            border: 3px solid #FFCC33;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #FFCC33;
            color: #003300;
            font-weight: 700;
            font-size: 2rem;
            text-transform: uppercase;
        }

        .avatar-editable .avatar-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-editable .avatar-edit-badge {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 32px;
            height: 32px;
            background: #003300;
            border: 2px solid #FFCC33;
            color: #FFCC33;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            transition: 0.15s;
        }

        .avatar-editable:hover .avatar-edit-badge {
            background: #FFCC33;
            color: #003300;
        }

        .avatar-editable input[type="file"] {
            display: none;
        }

        .profile-panel .name {
            font-weight: 700;
            font-size: 1.05rem;
            color: #FFCC33;
            margin-bottom: 4px;
            word-break: break-word;
        }

        .profile-panel .role-label {
            font-size: 0.75rem;
            color: #cbd5e1;
            font-weight: 500;
            text-transform: capitalize;
            margin-bottom: 20px;
        }

        .btn-change-password {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            background: rgba(255, 204, 51, 0.12);
            border: 1px solid rgba(255, 204, 51, 0.35);
            color: #FFCC33;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .btn-change-password:hover {
            background: rgba(255, 204, 51, 0.25);
            color: #fff;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid rgba(255, 204, 51, 0.3);
            padding-top: 18px;
            width: 100%;
        }

        .logout-btn-side {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 0;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: 0.15s;
        }

        .logout-btn-side:hover {
            background: rgba(255, 204, 51, 0.2);
            color: #fff;
        }

        /* ---- Main content ---- */
        .main-content {
            flex: 1;
            padding: 0 32px 32px 32px;
            display: flex;
            flex-direction: column;
        }

        /* ---- Dark Green Top Header (full width, flush) ---- */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 32px;
            background: #003300;
            margin: 0 -32px 24px -32px;
            flex-wrap: wrap;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 200;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 28px;
            flex-wrap: wrap;
        }

        .header-left h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #FFCC33;
            letter-spacing: -0.3px;
            white-space: nowrap;
        }

        .header-left h1 small {
            font-weight: 400;
            font-size: 0.85rem;
            color: #FFCC33;
            opacity: 0.8;
            margin-left: 8px;
        }

        .header-left h1 i {
            color: #FFCC33;
            margin-right: 8px;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: #FFCC33;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 4px 8px;
        }

        /* ---- Header right with navigation ---- */
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
            justify-content: flex-end;
        }

        .header-nav {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }

        .header-nav .nav-item-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 0;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.88rem;
            transition: all 0.15s;
            white-space: nowrap;
        }

        .header-nav .nav-item-header i {
            font-size: 0.9rem;
        }

        .header-nav .nav-item-header:hover {
            background: rgba(255, 204, 51, 0.2);
            color: #fff;
        }

        .header-nav .nav-item-header:hover i {
            color: #FFCC33;
        }

        .header-nav .nav-item-header.active {
            background: #FFCC33;
            color: #003300;
            font-weight: 600;
        }

        .header-nav .nav-item-header.active i {
            color: #003300;
        }

        /* ===== NOTIFICATION BELL & DROPDOWN ===== */
        .notif-wrapper {
            position: relative;
            display: inline-block;
        }

        .notif-bell {
            position: relative;
            font-size: 1.3rem;
            color: #FFCC33;
            background: rgba(255, 204, 51, 0.2);
            width: 44px;
            height: 44px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.15s;
            cursor: pointer;
            border: none;
            flex-shrink: 0;
        }

        .notif-bell:hover {
            background: rgba(255, 204, 51, 0.4);
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
            min-width: 20px;
            height: 20px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #003300;
            padding: 0 4px;
        }

        .notif-badge.hidden {
            display: none;
        }

        /* Notification Dropdown */
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 380px;
            max-height: 420px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            display: none;
            z-index: 1000;
            overflow: hidden;
            border-radius: 0;
        }

        .notif-dropdown.active {
            display: block;
            animation: slideDown 0.2s ease;
        }

        @keyframes slideDown {
            0% {
                opacity: 0;
                transform: translateY(-10px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .notif-dropdown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #edf2f7;
            background: #f8fafc;
        }

        .notif-dropdown-header h3 {
            font-size: 0.85rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .notif-dropdown-header .mark-all-read {
            background: none;
            border: none;
            color: #2563eb;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            padding: 4px 8px;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .notif-dropdown-header .mark-all-read:hover {
            text-decoration: underline;
        }

        .notif-list {
            max-height: 320px;
            overflow-y: auto;
            padding: 0;
        }

        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.15s;
            text-decoration: none;
            color: inherit;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-item:hover {
            background: #f8fafc;
        }

        .notif-item.unread {
            background: #eff6ff;
            border-left: 3px solid #2563eb;
        }

        .notif-item .notif-avatar {
            width: 32px;
            height: 32px;
            flex-shrink: 0;
            background: #e2e8f0;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.7rem;
            color: #475569;
            overflow: hidden;
        }

        .notif-item .notif-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .notif-item .notif-content {
            flex: 1;
            min-width: 0;
        }

        .notif-item .notif-content .notif-title {
            font-weight: 600;
            font-size: 0.82rem;
            color: #0f172a;
            margin-bottom: 2px;
        }

        .notif-item .notif-content .notif-message {
            font-size: 0.78rem;
            color: #64748b;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notif-item .notif-content .notif-time {
            font-size: 0.65rem;
            color: #94a3b8;
            margin-top: 3px;
            display: block;
        }

        .notif-empty {
            padding: 32px 16px;
            text-align: center;
            color: #94a3b8;
        }

        .notif-empty i {
            font-size: 2rem;
            display: block;
            margin-bottom: 8px;
            color: #cbd5e1;
        }

        .notif-empty p {
            font-size: 0.9rem;
        }

        /* ---- Page card (sharp, bordered) ---- */
        .page-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 24px 28px 32px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            flex: 1;
            border-radius: 0;
        }

        .page-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .page-toolbar .left-section h2 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0f172a;
        }

        .page-toolbar .left-section h2 i {
            color: #3b82f6;
        }

        .page-toolbar .left-section .toolbar-note {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 2px;
        }

        .page-toolbar .right-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border-radius: 0;
        }

        .back-btn:hover {
            background: #e9edf4;
        }

        .back-btn.hidden {
            display: none;
        }

        /* ---- Loading Spinner ---- */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }

        .loading-spinner.show {
            display: block;
        }

        .loading-spinner i {
            font-size: 2rem;
            color: #3b82f6;
            animation: spin 1s linear infinite;
            display: block;
            margin-bottom: 12px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ---- Intern List ---- */
        .intern-list.hidden {
            display: none;
        }

        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
        }

        .intern-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .intern-table th {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 600;
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .intern-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .intern-table tbody tr:last-child td {
            border-bottom: none;
        }

        .intern-table tbody tr:hover {
            background: #fafcff;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 14px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 0;
        }

        .status-chip i {
            font-size: 0.7rem;
        }

        .drop-warning {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            border-radius: 0;
            margin-left: 6px;
        }

        .drop-warning.extreme {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .drop-warning.moderate {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #facc15;
        }

        .view-dpr-btn {
            padding: 6px 16px;
            border-radius: 0;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid transparent;
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .view-dpr-btn:hover {
            background: #bfdbfe;
            transform: scale(1.02);
        }

        /* ---- DPR View ---- */
        .dpr-view.hidden {
            display: none;
        }

        .dpr-table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
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
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .dpr-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .dpr-table tbody tr:last-child td {
            border-bottom: none;
        }

        .dpr-table tbody tr:hover {
            background: #fafcff;
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

        .date-cell {
            font-weight: 600;
            color: #0f172a;
        }

        .score-cell {
            font-weight: 600;
        }

        .score-value {
            color: #059669;
        }

        .score-value.dropped {
            color: #dc2626;
        }

        .no-score {
            color: #94a3b8;
        }

        .score-drop-indicator {
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.65rem;
            font-weight: 600;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            border-radius: 0;
            margin-left: 4px;
        }

        .score-drop-indicator.down i {
            color: #dc2626;
        }

        /* ---- Buttons ---- */
        .view-btn {
            padding: 6px 16px;
            border-radius: 0;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border: 1px solid transparent;
        }

        .view-btn-task {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .view-btn-task:hover:not(:disabled) {
            background: #bfdbfe;
            transform: scale(1.02);
        }

        .view-btn-task.no-content {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: default;
            border-color: #e2e8f0;
        }

        .view-btn-task.no-content:hover {
            background: #f1f5f9;
            transform: none;
        }

        .view-btn-feedback {
            background: #eef2ff;
            color: #4338ca;
            border-color: #a5b4fc;
        }

        .view-btn-feedback:hover:not(:disabled) {
            background: #c7d2fe;
            transform: scale(1.02);
        }

        .view-btn-feedback.no-content {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: default;
            border-color: #e2e8f0;
        }

        .view-btn-feedback.no-content:hover {
            background: #f1f5f9;
            transform: none;
        }

        .view-btn-evaluate {
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .view-btn-evaluate:hover:not(:disabled) {
            background: #fef08a;
            transform: scale(1.02);
        }

        .view-btn-evaluate.evaluated {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .view-btn-evaluate.evaluated:hover {
            background: #bbf7d0;
        }

        .badge-status {
            padding: 4px 14px;
            border-radius: 0;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            border: 1px solid transparent;
        }

        .badge-status.in-progress {
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .badge-status.completed {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .badge-status.pending {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }

        /* ===== MODAL STYLES (sharp) ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            max-width: 560px;
            width: 100%;
            padding: 32px 30px 28px;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.3);
            animation: slideUp 0.25s ease;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 0;
        }

        #evaluateModal .modal-card {
            max-width: 620px;
        }

        #passwordModal .modal-card {
            max-width: 480px;
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
            line-height: 1;
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
            border-radius: 0;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .btn-close-modal:hover {
            background: #e9edf4;
        }

        .btn-submit-evaluate {
            background: #0f172a;
            border: 1px solid #0f172a;
            color: #fff;
            padding: 10px 28px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-submit-evaluate:hover:not(:disabled) {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .btn-submit-evaluate:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

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
            font-size: 0.95rem;
            line-height: 1.7;
            color: #1e293b;
            min-height: 60px;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: 1px solid #e2e8f0;
            border-radius: 0;
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

        .evaluate-form .form-group {
            margin-bottom: 18px;
        }

        .evaluate-form .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            color: #1e293b;
            margin-bottom: 5px;
        }

        .evaluate-form .form-group label .required {
            color: #dc2626;
        }

        .score-range {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .score-range .score-input {
            width: 100px;
            padding: 10px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.95rem;
            background: #fafcff;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .score-range .score-input:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .score-range .score-input:read-only {
            background: #f1f5f9;
            color: #475569;
        }

        .evaluate-form .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.95rem;
            background: #fafcff;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .evaluate-form .form-control:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .evaluate-form .form-control:read-only {
            background: #f1f5f9;
            color: #475569;
        }

        .evaluate-form .helper-text {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        /* ---- Toast ---- */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #0f172a;
            color: #f1f5f9;
            padding: 16px 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: none;
            align-items: center;
            gap: 12px;
            z-index: 2000;
            font-weight: 500;
            max-width: 400px;
            animation: slideUp 0.3s ease;
            border: 1px solid #334155;
            border-radius: 0;
        }

        .toast.success {
            background: #059669;
            border-color: #047857;
        }

        .toast.error {
            background: #dc2626;
            border-color: #b91c1c;
        }

        .toast.warning {
            background: #d97706;
            border-color: #b45309;
        }

        .toast.show {
            display: flex;
        }

        .toast i {
            font-size: 1.2rem;
        }

        /* ---- Empty State ---- */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 3rem;
            display: block;
            margin-bottom: 16px;
            color: #cbd5e1;
        }

        .empty-state p {
            font-size: 1rem;
        }

        /* ---- Sidebar Overlay ---- */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* ---- Responsive ---- */
        @media (max-width: 1024px) {
            .page-toolbar {
                flex-direction: column;
            }
            .page-toolbar .right-section {
                width: 100%;
            }
            
            .notif-dropdown {
                width: 320px;
                right: -10px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -280px;
                width: 280px;
                height: 100vh;
                z-index: 1001;
                transition: left 0.3s ease;
                overflow-y: auto;
            }

            .sidebar.open {
                left: 0;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .top-header {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 16px;
                margin: 0 -16px 16px -16px;
            }

            .header-left {
                flex-direction: row;
                align-items: center;
                gap: 12px;
                justify-content: space-between;
                width: 100%;
            }

            .header-left h1 {
                font-size: 1.1rem;
            }

            .header-right {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                justify-content: center;
                width: 100%;
            }

            .header-nav {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .header-nav .nav-item-header {
                padding: 6px 12px;
                font-size: 0.8rem;
            }

            .notif-wrapper {
                align-self: center;
            }

            .notif-dropdown {
                width: 300px;
                right: -10px;
                left: auto;
            }

            .page-card {
                padding: 16px;
            }

            .intern-table th,
            .intern-table td,
            .dpr-table th,
            .dpr-table td {
                padding: 10px 12px;
                font-size: 0.8rem;
            }

            .modal-card {
                padding: 24px 18px;
                max-height: 95vh;
                margin: 10px;
            }

            .view-btn,
            .view-dpr-btn {
                font-size: 0.65rem;
                padding: 4px 10px;
            }

            .view-content-display .meta-info {
                flex-direction: column;
                gap: 8px;
            }

            .score-range {
                flex-direction: column;
                align-items: flex-start;
            }

            .score-range .score-input {
                width: 100%;
            }

            .modal-actions {
                flex-direction: column;
            }

            .modal-actions .btn-close-modal,
            .modal-actions .btn-submit-evaluate {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .header-nav .nav-item-header {
                font-size: 0.7rem;
                padding: 4px 8px;
            }

            .header-nav .nav-item-header i {
                font-size: 0.7rem;
            }

            .intern-table th,
            .intern-table td,
            .dpr-table th,
            .dpr-table td {
                padding: 8px 6px;
                font-size: 0.7rem;
            }

            .view-btn,
            .view-dpr-btn {
                font-size: 0.6rem;
                padding: 3px 6px;
            }

            .status-chip {
                font-size: 0.65rem;
                padding: 2px 8px;
            }

            .badge-status {
                font-size: 0.65rem;
                padding: 2px 8px;
            }

            .drop-warning {
                font-size: 0.6rem;
                padding: 1px 6px;
            }

            .notif-dropdown {
                width: 280px;
                right: -5px;
                left: auto;
            }
        }

        /* ===== PASSWORD MODAL ===== */
        #passwordModal .modal-card {
            max-width: 480px;
        }

        #passwordModal .form-group {
            margin-bottom: 18px;
        }

        #passwordModal .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            color: #1e293b;
            margin-bottom: 5px;
        }

        #passwordModal .form-group label i {
            margin-right: 6px;
            color: #64748b;
        }

        #passwordModal .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.95rem;
            background: #fafcff;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        #passwordModal .form-group input:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        #passwordModal .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            border-top: 1px solid #edf2f7;
            padding-top: 22px;
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <!-- Sidebar Overlay for mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- SIDEBAR: user profile panel -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-clipboard-check"></i>
                <h2>System<span>Supervisor Panel</span></h2>
            </div>

            <div class="profile-panel">
                <div class="avatar-editable" id="avatarEditable" title="Click to change your photo">
                    <?php if (!empty($profilePictureUrl)): ?>
                        <div class="avatar-img" id="avatarImgWrap">
                            <img src="<?php echo htmlspecialchars($profilePictureUrl); ?>" alt="Profile photo" id="avatarImg" />
                        </div>
                    <?php else: ?>
                        <div class="avatar-initials" id="avatarImgWrap">
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
                    <?php endif; ?>
                    <div class="avatar-edit-badge"><i class="fa-solid fa-camera"></i></div>
                    <input type="file" id="avatarInput" accept="image/png, image/jpeg, image/webp, image/gif" />
                </div>

                <div class="name"><?php echo htmlspecialchars($fullname); ?></div>
                <div class="role-label"><?php echo htmlspecialchars(getRoleDisplayName($role)); ?></div>

                <button class="btn-change-password" id="openPasswordModalBtn">
                    <i class="fa-solid fa-key"></i> Change Password
                </button>
            </div>

            <div class="sidebar-footer">
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <!-- HEADER: full width, dark green, flush with top -->
            <div class="top-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle" id="menuToggle" aria-label="Toggle menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <h1>
                        <i class="fa-solid fa-users"></i>
                        My Interns
                        <small>Supervisor</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Header Navigation -->
                    <nav class="header-nav">
                        <a class="nav-item-header" href="dashboard.php"> Dashboard</a>
                        <a class="nav-item-header" href="job.php"> Add Job</a>
                        <a class="nav-item-header" href="applicant.php"> Applicants</a>
                        <a class="nav-item-header active" href="myintern.php"> My Interns</a>
                    </nav>

                    <!-- Notification bell with dropdown -->
                    <div class="notif-wrapper">
                        <button class="notif-bell" id="notifBell" aria-label="Notifications">
                            <i class="fa-regular fa-bell"></i>
                            <span class="notif-badge <?php echo $unreadCount > 0 ? '' : 'hidden'; ?>" id="notifBadge">
                                <?php echo $unreadCount > 0 ? $unreadCount : ''; ?>
                            </span>
                        </button>

                        <!-- Notification Dropdown -->
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-dropdown-header">
                                <h3>Notifications</h3>
                                <button class="mark-all-read" id="markAllRead">Mark all as read</button>
                            </div>
                            <div class="notif-list" id="notifList">
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $notif): ?>
                                        <?php
                                            $messageText = getSupervisorNotificationMessage($notif);
                                            $source = getSupervisorNotificationSource($notif);
                                        ?>
                                        <a href="<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>" 
                                           class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>"
                                           data-id="<?php echo $notif['id']; ?>"
                                           onclick="handleNotificationClick(event, <?php echo $notif['id']; ?>, '<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>')">
                                            <div class="notif-avatar">
                                                <?php if (!empty($notif['profile_picture'])): ?>
                                                    <img src="<?php echo htmlspecialchars($avatarPublicPath . $notif['profile_picture']); ?>" alt="Avatar">
                                                <?php else: ?>
                                                    <?php 
                                                        $initials = strtoupper(substr($notif['firstname'] ?? 'U', 0, 1) . substr($notif['lastname'] ?? 'N', 0, 1));
                                                        echo htmlspecialchars($initials ?: 'UN');
                                                    ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notif-content">
                                                <div class="notif-title"><?php echo htmlspecialchars($notif['title'] ?? 'Notification'); ?></div>
                                                <div class="notif-message"><?php echo htmlspecialchars($messageText); ?></div>
                                                <span class="notif-time"><?php echo htmlspecialchars($source); ?> - <?php echo htmlspecialchars(timeAgo($notif['created_at'] ?? '')); ?></span>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notif-empty">
                                        <i class="fa-regular fa-bell-slash"></i>
                                        <p>No notifications yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
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
                                            $studentId = $intern['student_id'];
                                            $hasDrop = isset($internAnalytics[$studentId]) && $internAnalytics[$studentId]['has_extreme_drop'];
                                            $dropCount = isset($internAnalytics[$studentId]) ? $internAnalytics[$studentId]['total_drops'] : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($studentName !== '' ? $studentName : 'Unnamed Student'); ?></strong>
                                                <?php if ($hasDrop): ?>
                                            
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($supervisorName !== '' ? $supervisorName : 'Not assigned'); ?></td>
                                            <td><?php echo htmlspecialchars($intern['job_title'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($intern['company_name'] ?? 'N/A'); ?></td>
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
                                  
                                    <th>Score</th>
                                    <th>Status</th>
                                    <th>Action</th>
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

    <!-- ===== EVALUATE/VIEW EVALUATION MODAL ===== -->
    <div class="modal-overlay" id="evaluateModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2 id="evaluateModalTitle"><i class="fa-solid fa-star"></i> Evaluate DPR Entry</h2>
                <button class="modal-close" id="closeEvaluateBtn">&times;</button>
            </div>
            <div class="modal-hint" id="evaluateModalHint">Provide a score and feedback for this DPR entry.</div>

            <div class="view-content-display">
                <div class="meta-info">
                    <div class="meta-item">
                        <i class="fa-regular fa-calendar"></i>
                        <strong>Date:</strong> <span id="evaluateDate">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-regular fa-user"></i>
                        <strong>Student:</strong> <span id="evaluateStudent">—</span>
                    </div>
                    <div class="meta-item">
                        <i class="fa-regular fa-clock"></i>
                        <strong>Task:</strong> <span id="evaluateTaskPreview">—</span>
                    </div>
                </div>

                <form class="evaluate-form" id="evaluateForm">
                    <input type="hidden" id="evaluateDprId" name="dpr_id">
                    <input type="hidden" id="isEvaluated" name="is_evaluated" value="0">
                    
                    <div class="form-group">
                        <label for="score">Score <span class="required">*</span></label>
                        <div class="score-range">
                            <input type="number" id="score" name="score" class="score-input" 
                                   min="1" max="100" required placeholder="0-100">
                            <span>out of 100</span>
                            <span id="cohortAvgDisplay" style="font-size:0.75rem; color:#64748b;"></span>
                        </div>
                        <div class="helper-text">Enter a score between 1 and 100.</div>
                    </div>

                    <div class="form-group">
                        <label for="supervisor_feedback">Supervisor Feedback</label>
                        <textarea id="supervisor_feedback" name="supervisor_feedback" class="form-control" 
                                  placeholder="Provide constructive feedback for the student..." rows="3"></textarea>
                        <div class="helper-text">Optional: Provide detailed feedback about the student's performance.</div>
                    </div>
                </form>
            </div>

            <div class="modal-actions">
                <button class="btn-close-modal" id="closeEvaluateBtn2">Close</button>
                <button class="btn-submit-evaluate" id="submitEvaluateBtn">
                    <i class="fa-solid fa-check"></i> Submit Evaluation
                </button>
            </div>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-card">
            <div class="modal-header">
                <h2><i class="fa-solid fa-key"></i> Change Password</h2>
                <button class="modal-close" id="closePasswordBtn">&times;</button>
            </div>
            <div class="modal-hint">Enter your current password and choose a new one.</div>

            <form id="passwordForm">
                <div class="form-group">
                    <label for="currentPassword"><i class="fa-solid fa-lock"></i> Current Password</label>
                    <input type="password" id="currentPassword" autocomplete="current-password" required />
                </div>
                <div class="form-group">
                    <label for="newPassword"><i class="fa-solid fa-lock"></i> New Password</label>
                    <input type="password" id="newPassword" autocomplete="new-password" minlength="8" required />
                </div>
                <div class="form-group">
                    <label for="confirmPassword"><i class="fa-solid fa-lock"></i> Confirm New Password</label>
                    <input type="password" id="confirmPassword" autocomplete="new-password" minlength="8" required />
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-close-modal" id="closePasswordBtn2">Cancel</button>
                    <button type="submit" class="btn-submit-evaluate"><i class="fa-solid fa-check"></i> Update Password</button>
                </div>
            </form>
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

        // ===== NOTIFICATION FUNCTIONS =====

        /**
         * Toggle notification dropdown
         */
        function toggleNotifications() {
            const dropdown = document.getElementById('notifDropdown');
            const bell = document.getElementById('notifBell');
            
            if (dropdown.classList.contains('active')) {
                dropdown.classList.remove('active');
            } else {
                dropdown.classList.add('active');
                // Load fresh notifications when opening
                loadNotifications();
            }
        }

        /**
         * Load notifications via AJAX
         */
        function loadNotifications() {
            const formData = new FormData();
            formData.append('action', 'get_notifications');
            formData.append('limit', '20');
            formData.append('offset', '0');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderNotifications(data.notifications, data.unread_count);
                    updateBadge(data.unread_count);
                }
            })
            .catch(error => {
                console.error('Error loading notifications:', error);
            });
        }

        /**
         * Render notifications in dropdown
         */
        function renderNotifications(notifications, unreadCount) {
            const list = document.getElementById('notifList');
            const badge = document.getElementById('notifBadge');
            
            if (!notifications || notifications.length === 0) {
                list.innerHTML = `
                    <div class="notif-empty">
                        <i class="fa-regular fa-bell-slash"></i>
                        <p>No notifications yet</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            notifications.forEach(notif => {
                const isUnread = notif.is_read == 0;
                const link = notif.link || '#';
                const avatarUrl = notif.profile_picture ? '<?php echo $avatarPublicPath; ?>' + notif.profile_picture : '';
                const initials = notif.firstname && notif.lastname ? 
                    (notif.firstname.charAt(0) + notif.lastname.charAt(0)).toUpperCase() : 'UN';
                
                html += `
                    <a href="${link}" 
                       class="notif-item ${isUnread ? 'unread' : ''}"
                       data-id="${notif.id}"
                       onclick="handleNotificationClick(event, ${notif.id}, '${link}')">
                        <div class="notif-avatar">
                            ${avatarUrl ? `<img src="${avatarUrl}" alt="Avatar">` : initials}
                        </div>
                        <div class="notif-content">
                            <div class="notif-title">${escapeHtml(notif.title || 'Notification')}</div>
                            <div class="notif-message">${escapeHtml(notif.message || '')}</div>
                            <span class="notif-time">${escapeHtml((notif.firstname && notif.lastname) ? `${notif.firstname} ${notif.lastname}` : 'System')} - ${escapeHtml(notif.message || '')} - ${timeAgo(notif.created_at)}</span>
                        </div>
                    </a>
                `;
            });
            
            list.innerHTML = html;
            updateBadge(unreadCount);
        }

        /**
         * Update notification badge count
         */
        function updateBadge(count) {
            const badge = document.getElementById('notifBadge');
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }

        /**
         * Handle notification click
         */
        function handleNotificationClick(event, notificationId, link) {
            event.preventDefault();
            
            const item = event.currentTarget;
            item.classList.remove('unread');
            // Mark as read
            markNotificationRead(notificationId, function() {
                // Navigate to the link
                if (link && link !== '#') {
                    window.location.href = link;
                } else {
                    // Close dropdown
                    document.getElementById('notifDropdown').classList.remove('active');
                }
            });
        }

        /**
         * Mark a single notification as read
         */
        function markNotificationRead(notificationId, callback) {
            const formData = new FormData();
            formData.append('action', 'mark_read');
            formData.append('notification_id', notificationId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateBadge(data.unread_count);
                    // Update the UI immediately
                    const item = document.querySelector(`.notif-item[data-id="${notificationId}"]`);
                    if (item) {
                        item.classList.remove('unread');
                    }
                    if (callback) callback();
                }
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
                if (callback) callback();
            });
        }

        /**
         * Mark all notifications as read
         */
        function markAllNotificationsRead() {
            const formData = new FormData();
            formData.append('action', 'mark_all_read');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateBadge(0);
                    // Update all items in dropdown
                    document.querySelectorAll('.notif-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    showToast('All notifications marked as read', 'success');
                }
            })
            .catch(error => {
                console.error('Error marking all as read:', error);
            });
        }

        /**
         * Get time ago string
         */
        function timeAgo(dateStr) {
            if (!dateStr) return '';
            
            const date = new Date(dateStr);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);
            
            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            if (diff < 2592000) return Math.floor(diff / 604800) + 'w ago';
            return date.toLocaleDateString();
        }

        /**
         * Escape HTML for safe display
         */
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ===== NOTIFICATION EVENT LISTENERS =====
        
        // Notification bell toggle
        document.getElementById('notifBell').addEventListener('click', function(e) {
            e.stopPropagation();
            document.querySelectorAll('.notif-item.unread').forEach(item => item.classList.remove('unread'));
            document.getElementById('notifBadge').classList.add('hidden');
            document.getElementById('notifBadge').textContent = '';
            toggleNotifications();
        });

        // Close dropdown on outside click
        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.notif-wrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                document.getElementById('notifDropdown').classList.remove('active');
            }
        });

        // Mark all as read
        document.getElementById('markAllRead').addEventListener('click', function(e) {
            e.stopPropagation();
            markAllNotificationsRead();
        });

        // Periodically check for new notifications (every 30 seconds)
        setInterval(function() {
            const formData = new FormData();
            formData.append('action', 'get_notifications');
            formData.append('limit', '1');
            formData.append('offset', '0');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateBadge(data.unread_count);
                }
            })
            .catch(error => {
                console.error('Error checking notifications:', error);
            });
        }, 30000);

        // ----- View Student DPR -----
        let currentStudentName = '';
        let currentAnalytics = null;

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
                    // Store analytics data (still available but not displayed)
                    currentAnalytics = data.analytics || null;
                    
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
                        <td colspan="8">
                            <i class="fa-regular fa-calendar-circle-plus"></i>
                            No DPR entries found for this student.<br>
                            <span style="font-size:0.85rem; color:#cbd5e1;">The student hasn't submitted any progress reports yet.</span>
                        </td>
                    </tr>
                `;
                return;
            }

            // Get score drop data for this student (still calculated but displayed in table)
            const dropMap = {};
            if (currentAnalytics && currentAnalytics.score_drops) {
                currentAnalytics.score_drops.forEach(drop => {
                    dropMap[drop.dpr_id] = drop;
                });
            }

            let html = '';
            let previousScore = null;
            
            entries.forEach((entry, index) => {
                const statusClass = entry.status.toLowerCase().replace(' ', '-');
                const hasTask = entry.tasks && entry.tasks.trim() !== '';
                const hasFeedback = entry.feedback && entry.feedback.trim() !== '';
                const hasScore = entry.score !== null && entry.score !== undefined && entry.score > 0;
                const isEvaluated = entry.evaluated_at !== null && entry.evaluated_at !== undefined;
                const hasSupervisorFeedback = entry.supervisor_feedback && entry.supervisor_feedback.trim() !== '';
                const timeStr = entry.time_in_formatted && entry.time_out_formatted ? 
                    entry.time_in_formatted + ' - ' + entry.time_out_formatted : 
                    (entry.time_in_formatted || '—');
                
                // Check if this entry has a score drop
                const dropInfo = dropMap[entry.id];
                const isDropped = dropInfo && dropInfo.is_extreme;
                const dropPercent = dropInfo ? dropInfo.drop_percentage : 0;
                
                // Escape data for JavaScript
                const taskText = entry.tasks || '';
                const dateText = entry.date_formatted || entry.date || '';
                const timeText = timeStr || '';
                const score = entry.score || 0;
                const supervisorFeedback = entry.supervisor_feedback || '';
                const dprId = entry.id || 0;
                
                // Evaluate button text and class
                const evaluateBtnClass = isEvaluated ? 'view-btn-evaluate evaluated' : 'view-btn-evaluate';
                const evaluateBtnText = isEvaluated ? '<i class="fa-solid fa-eye"></i> View Evaluation' : '<i class="fa-solid fa-star"></i> Evaluate';
                const isEvaluatedFlag = isEvaluated ? '1' : '0';
                
                // Score display with drop indicator
                let scoreDisplay = '';
                if (hasScore) {
                    let scoreClass = 'score-value';
                    let dropIndicator = '';
                    if (isDropped) {
                        scoreClass += ' dropped';
                        dropIndicator = `<span class="score-drop-indicator down" title="Score dropped ${dropPercent}%"><i class="fa-solid fa-arrow-down"></i> ${dropPercent}%</span>`;
                    } else if (dropInfo && dropInfo.drop_percentage > 0) {
                        scoreClass += ' dropped';
                        dropIndicator = `<span class="score-drop-indicator down" title="Score dropped ${dropPercent}%"><i class="fa-solid fa-arrow-down"></i> ${dropPercent}%</span>`;
                    }
                    scoreDisplay = `<span class="${scoreClass}">${escapeHtml(score)}%</span> ${dropIndicator}`;
                } else {
                    scoreDisplay = '<span class="no-score">—</span>';
                }
                
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
                        
                        <td class="score-cell">${scoreDisplay}</td>
                        <td>
                            <span class="badge-status ${statusClass}">
                                ${escapeHtml(entry.status)}
                            </span>
                        </td>
                        <td>
                            <button class="view-btn ${evaluateBtnClass}" 
                                    onclick="openEvaluateModal(${dprId}, '${escapeJs(dateText)}', '${escapeJs(studentName)}', '${escapeJs(taskText)}', ${score}, '${escapeJs(supervisorFeedback)}', ${isEvaluatedFlag})">
                                ${evaluateBtnText}
                            </button>
                        </td>
                    </tr>
                `;
                
                previousScore = hasScore ? score : previousScore;
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
            console.log('viewTask called', { task, date, studentName, timeStr });
            
            const modal = document.getElementById('taskModal');
            const taskDate = document.getElementById('taskDate');
            const taskStudent = document.getElementById('taskStudent');
            const taskTime = document.getElementById('taskTime');
            const taskTextDisplay = document.getElementById('taskTextDisplay');
            
            if (!modal) {
                console.error('Task modal not found!');
                return;
            }
            
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
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // ----- Open Evaluate Modal (No Scroll) -----
        function openEvaluateModal(dprId, date, studentName, taskPreview, currentScore, currentFeedback, isEvaluated) {
            console.log('openEvaluateModal called', { dprId, date, studentName, taskPreview, currentScore, currentFeedback, isEvaluated });
            
            const modal = document.getElementById('evaluateModal');
            if (!modal) {
                console.error('Evaluate modal not found!');
                return;
            }
            
            // Set values
            document.getElementById('evaluateDate').textContent = date || '—';
            document.getElementById('evaluateStudent').textContent = studentName || '—';
            document.getElementById('evaluateTaskPreview').textContent = taskPreview ? 
                (taskPreview.length > 50 ? taskPreview.substring(0, 50) + '...' : taskPreview) : 
                'No task provided';
            document.getElementById('evaluateDprId').value = dprId;
            document.getElementById('isEvaluated').value = isEvaluated ? '1' : '0';
            
            const scoreInput = document.getElementById('score');
            const feedbackInput = document.getElementById('supervisor_feedback');
            const submitBtn = document.getElementById('submitEvaluateBtn');
            const modalTitle = document.getElementById('evaluateModalTitle');
            const modalHint = document.getElementById('evaluateModalHint');
            const cohortAvgDisplay = document.getElementById('cohortAvgDisplay');
            
            // Show cohort average if available (still shows in eval modal)
            if (currentAnalytics && currentAnalytics.cohort) {
                cohortAvgDisplay.textContent = 'Cohort avg: ' + currentAnalytics.cohort.cohort_avg + '%';
            } else {
                cohortAvgDisplay.textContent = '';
            }
            
            if (isEvaluated) {
                // View only mode
                modalTitle.innerHTML = '<i class="fa-solid fa-eye"></i> View Evaluation';
                modalHint.textContent = 'View the evaluation details for this DPR entry.';
                scoreInput.value = currentScore > 0 ? currentScore : '';
                scoreInput.readOnly = true;
                feedbackInput.value = currentFeedback || '';
                feedbackInput.readOnly = true;
                submitBtn.style.display = 'none';
                document.querySelector('#evaluateModal .modal-actions .btn-close-modal:first-child').textContent = 'Close';
            } else {
                // Edit mode
                modalTitle.innerHTML = '<i class="fa-solid fa-star"></i> Evaluate DPR Entry';
                modalHint.textContent = 'Provide a score and feedback for this DPR entry.';
                scoreInput.value = '';
                scoreInput.readOnly = false;
                feedbackInput.value = '';
                feedbackInput.readOnly = false;
                submitBtn.style.display = 'inline-flex';
                document.querySelector('#evaluateModal .modal-actions .btn-close-modal:first-child').textContent = 'Cancel';
            }
            
            // Prevent body scroll when modal is open
            document.body.style.overflow = 'hidden';
            
            // Show modal with fade-in
            modal.style.display = 'flex';
            requestAnimationFrame(() => {
                modal.classList.add('active');
            });
        }

        // ----- Close Evaluate Modal -----
        function closeEvaluateModal() {
            const modal = document.getElementById('evaluateModal');
            if (modal) {
                modal.classList.remove('active');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 250);
                document.body.style.overflow = '';
                document.getElementById('evaluateForm').reset();
                document.getElementById('evaluateDprId').value = '';
                document.getElementById('isEvaluated').value = '0';
                
                // Reset readonly states
                const scoreInput = document.getElementById('score');
                const feedbackInput = document.getElementById('supervisor_feedback');
                const submitBtn = document.getElementById('submitEvaluateBtn');
                scoreInput.readOnly = false;
                feedbackInput.readOnly = false;
                submitBtn.style.display = 'inline-flex';
                document.querySelector('#evaluateModal .modal-actions .btn-close-modal:first-child').textContent = 'Cancel';
            }
        }

        // ----- Submit Evaluation -----
        function submitEvaluation() {
            const dprId = document.getElementById('evaluateDprId').value;
            const isEvaluated = document.getElementById('isEvaluated').value;
            
            // If already evaluated, don't allow submission
            if (isEvaluated === '1') {
                showToast('This DPR has already been evaluated.', 'error');
                return;
            }
            
            const score = document.getElementById('score').value;
            const feedback = document.getElementById('supervisor_feedback').value;
            
            // Validate
            if (!dprId || dprId <= 0) {
                showToast('Invalid DPR entry.', 'error');
                return;
            }
            
            if (!score || score < 1 || score > 100) {
                showToast('Please enter a valid score between 1 and 100.', 'error');
                document.getElementById('score').focus();
                return;
            }
            
            // Disable submit button
            const submitBtn = document.getElementById('submitEvaluateBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
            
            // Send data
            const formData = new FormData();
            formData.append('action', 'evaluate_dpr');
            formData.append('dpr_id', dprId);
            formData.append('score', score);
            formData.append('supervisor_feedback', feedback);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> Submit Evaluation';
                
                if (data.success) {
                    let message = data.message;
                    if (data.score_drop_warning) {
                        message += ' ' + data.score_drop_warning;
                        showToast(message, 'warning');
                    } else {
                        showToast(message, 'success');
                    }
                    closeEvaluateModal();
                    
                    // Refresh the DPR table
                    const studentName = document.getElementById('pageTitle').textContent.replace('DPR: ', '');
                    const firstRow = document.querySelector('#dprTableBody tr');
                    if (firstRow && !firstRow.classList.contains('dpr-empty-row')) {
                        showInternList();
                        setTimeout(() => {
                            const viewButtons = document.querySelectorAll('.view-dpr-btn');
                            if (viewButtons.length > 0) {
                                viewButtons[0].click();
                            }
                        }, 500);
                    }
                } else {
                    showToast(data.message || 'Failed to submit evaluation.', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> Submit Evaluation';
                showToast('An error occurred. Please try again.', 'error');
            });
        }

        // ----- Modal Close Functions -----
        function closeTaskModal() {
            const modal = document.getElementById('taskModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // ----- Event Listeners -----
        document.getElementById('closeTaskBtn').addEventListener('click', closeTaskModal);
        document.getElementById('closeTaskBtn2').addEventListener('click', closeTaskModal);

        // Fixed: Evaluate Modal close buttons
        document.getElementById('closeEvaluateBtn').addEventListener('click', closeEvaluateModal);
        document.getElementById('closeEvaluateBtn2').addEventListener('click', closeEvaluateModal);
        
        document.getElementById('submitEvaluateBtn').addEventListener('click', submitEvaluation);

        // Close modals on overlay click
        document.getElementById('taskModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTaskModal();
            }
        });

        // Fixed: Evaluate Modal overlay click
        document.getElementById('evaluateModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEvaluateModal();
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('taskModal').classList.contains('active')) {
                    closeTaskModal();
                }
                // Fixed: Check evaluate modal instead of feedback modal
                if (document.getElementById('evaluateModal').classList.contains('active')) {
                    closeEvaluateModal();
                }
                if (document.getElementById('passwordModal').classList.contains('active')) {
                    closePasswordModal();
                }
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
                if (document.getElementById('notifDropdown').classList.contains('active')) {
                    document.getElementById('notifDropdown').classList.remove('active');
                }
            }
        });

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
            
            // Clear analytics data
            currentAnalytics = null;
        }

        // Toast click to dismiss
        document.getElementById('toast').addEventListener('click', function() {
            this.classList.remove('show');
        });

        // ===== MOBILE MENU TOGGLE =====
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        if (menuToggle) {
            menuToggle.addEventListener('click', toggleSidebar);
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeSidebar);
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });

        // ===== PASSWORD MODAL =====
        const passwordModal = document.getElementById('passwordModal');
        const openPasswordBtn = document.getElementById('openPasswordModalBtn');
        const closePasswordBtn = document.getElementById('closePasswordBtn');
        const closePasswordBtn2 = document.getElementById('closePasswordBtn2');
        const passwordForm = document.getElementById('passwordForm');

        if (openPasswordBtn) {
            openPasswordBtn.addEventListener('click', function() {
                if (passwordModal) {
                    passwordModal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                    if (passwordForm) passwordForm.reset();
                }
            });
        }

        function closePasswordModal() {
            if (passwordModal) {
                passwordModal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        if (closePasswordBtn) closePasswordBtn.addEventListener('click', closePasswordModal);
        if (closePasswordBtn2) closePasswordBtn2.addEventListener('click', closePasswordModal);
        if (passwordModal) {
            passwordModal.addEventListener('click', function(e) {
                if (e.target === passwordModal) closePasswordModal();
            });
        }

        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var currentPassword = document.getElementById('currentPassword').value;
                var newPassword = document.getElementById('newPassword').value;
                var confirmPassword = document.getElementById('confirmPassword').value;

                if (newPassword !== confirmPassword) {
                    showToast('New password and confirmation do not match.', 'error');
                    return;
                }
                if (newPassword.length < 8) {
                    showToast('New password must be at least 8 characters.', 'error');
                    return;
                }

                var submitBtn = passwordForm.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating...';
                }

                var formData = new FormData();
                formData.append('action', 'change_password');
                formData.append('current_password', currentPassword);
                formData.append('new_password', newPassword);
                formData.append('confirm_password', confirmPassword);

                fetch(window.location.href, { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            showToast(data.message || 'Password updated successfully.', 'success');
                            closePasswordModal();
                        } else {
                            showToast(data.message || 'Failed to update password.', 'error');
                        }
                    })
                    .catch(function() {
                        showToast('An error occurred. Please try again.', 'error');
                    })
                    .finally(function() {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> Update Password';
                        }
                    });
            });
        }

        // ===== AVATAR UPLOAD =====
        var avatarEditable = document.getElementById('avatarEditable');
        var avatarInput = document.getElementById('avatarInput');
        var avatarImgWrap = document.getElementById('avatarImgWrap');

        if (avatarEditable && avatarInput) {
            avatarEditable.addEventListener('click', function() {
                avatarInput.click();
            });

            avatarInput.addEventListener('change', function() {
                var file = avatarInput.files[0];
                if (!file) return;

                if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
                    showToast('Only JPG, PNG, WEBP or GIF images are allowed.', 'error');
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    showToast('Image must be smaller than 2MB.', 'error');
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'update_avatar');
                formData.append('avatar', file);

                fetch(window.location.href, { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            showToast('Profile picture updated.', 'success');
                            if (avatarImgWrap && data.path) {
                                avatarImgWrap.className = 'avatar-img';
                                avatarImgWrap.innerHTML = '<img src="' + data.path + '?t=' + Date.now() + '" alt="Profile photo" id="avatarImg" />';
                            }
                        } else {
                            showToast(data.message || 'Failed to update profile picture.', 'error');
                        }
                    })
                    .catch(function() {
                        showToast('An error occurred while uploading. Please try again.', 'error');
                    });
            });
        }

        // ----- Debug helper - log when page loads -----
        console.log('Page loaded with notification system and score drop detection.');
    </script>
</body>
</html>