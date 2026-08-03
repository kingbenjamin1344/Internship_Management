<?php
// coordinator/dss.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is coordinator
checkAccess('coordinator');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Coordinator';
$role = getUserRole();
$userId = getUserId();

// Initialize notifications
require_once __DIR__ . '/../includes/coordinator_notifications.php';
checkAndCreateCoordinatorNotifications($pdo, $userId);
$unreadCount = getCoordinatorUnreadNotificationCount($pdo, $userId);
$notificationsList = getCoordinatorNotifications($pdo, $userId, 10, 0);

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

// Handle AJAX requests for password change and avatar update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Handle notification actions first
    if (in_array($_POST['action'], ['get_notifications', 'mark_read', 'mark_all_read'])) {
        $action = $_POST['action'];
        
        if ($action === 'get_notifications') {
            $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
            $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
            $notifs = getCoordinatorNotifications($pdo, $userId, $limit, $offset);
            $count = getCoordinatorUnreadNotificationCount($pdo, $userId);
            echo json_encode(['success' => true, 'notifications' => $notifs, 'unread_count' => $count]);
            exit;
        }
        
        if ($action === 'mark_read') {
            $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
            if ($notification_id > 0) {
                $result = markCoordinatorNotificationRead($pdo, $notification_id, $userId);
                $count = getCoordinatorUnreadNotificationCount($pdo, $userId);
                echo json_encode(['success' => $result, 'unread_count' => $count]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
            }
            exit;
        }
        
        if ($action === 'mark_all_read') {
            $result = markCoordinatorAllNotificationsRead($pdo, $userId);
            echo json_encode(['success' => $result, 'unread_count' => 0]);
            exit;
        }
    }
    
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
}

// Handle AJAX request for DSS data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_dss_data') {
    header('Content-Type: application/json');
    
    try {
        // Get all students with their internship data
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                s.id as student_id,
                s.firstname,
                s.lastname,
                c.company_name,
                j.title as job_title,
                a.status as application_status
            FROM users s
            INNER JOIN job_applications a ON s.id = a.student_id
            INNER JOIN jobs j ON a.job_id = j.id
            INNER JOIN companies c ON j.company_id = c.id
            WHERE s.role = 'student' AND a.status = 'committed'
            ORDER BY s.firstname, s.lastname
        ");
        
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $dssData = [];
        
        foreach ($students as $student) {
            $studentId = $student['student_id'];
            
            // Calculate metrics for each student
            $metrics = calculateStudentMetrics($pdo, $studentId);
            
            $dssData[] = [
                'student_id' => $studentId,
                'student_name' => trim($student['firstname'] . ' ' . $student['lastname']),
                'company_name' => $student['company_name'],
                'job_title' => $student['job_title'],
                'submission_velocity' => $metrics['submission_velocity'],
                'grade_variance' => $metrics['grade_variance'],
                'sentiment_discrepancy' => $metrics['sentiment_discrepancy'],
                'student_sentiment' => $metrics['student_sentiment'],
                'overall_score' => $metrics['overall_score'],
                'risk_classification' => $metrics['risk_classification']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $dssData,
            'count' => count($dssData)
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in dss.php: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred',
            'data' => []
        ]);
    }
    exit;
}

// Function to calculate all metrics for a student
function calculateStudentMetrics($pdo, $studentId) {
    // 1. Submission Velocity Metric (Mv) - from DPR
    $submissionVelocity = calculateSubmissionVelocity($pdo, $studentId);
    
    // 2. Grading Distribution Variance Metric (Mg) - from evaluations
    $gradeVariance = calculateGradeVariance($pdo, $studentId);
    
    // 3. Evaluative Sentiment Discrepancy Metric (Ms) - from evaluation.php logic
    $sentimentDiscrepancy = calculateSentimentDiscrepancy($pdo, $studentId);
    
    // 4. Student Sentiment Analysis - from student feedback in DPR
    $studentSentiment = calculateStudentSentiment($pdo, $studentId);
    
    // 5. Calculate weighted overall score
    $weights = [
        'submission_velocity' => 0.35,  // 35%
        'grade_variance' => 0.25,       // 25%
        'sentiment_discrepancy' => 0.20, // 20%
        'student_sentiment' => 0.20      // 20%
    ];
    
    $overallScore = ($submissionVelocity * $weights['submission_velocity']) +
                    ($gradeVariance * $weights['grade_variance']) +
                    ($sentimentDiscrepancy * $weights['sentiment_discrepancy']) +
                    ($studentSentiment * $weights['student_sentiment']);
    
    // 6. Risk Classification
    $riskClassification = classifyRisk($overallScore);
    
    return [
        'submission_velocity' => round($submissionVelocity, 2),
        'grade_variance' => round($gradeVariance, 2),
        'sentiment_discrepancy' => round($sentimentDiscrepancy, 2),
        'student_sentiment' => round($studentSentiment, 2),
        'overall_score' => round($overallScore, 2),
        'risk_classification' => $riskClassification
    ];
}

// Calculate submission velocity based on DPR submission frequency and consistency
function calculateSubmissionVelocity($pdo, $studentId) {
    try {
        // Get DPR submissions in last 30 days with timestamps
        $stmt = $pdo->prepare("
            SELECT 
                date,
                created_at,
                COUNT(*) as daily_count
            FROM dpr_entries 
            WHERE student_id = ? AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(date)
            ORDER BY date ASC
        ");
        $stmt->execute([$studentId]);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($submissions)) {
            return 0; // No submissions
        }
        
        $totalDays = 30; // Looking at last 30 days
        $daysWithSubmissions = count($submissions);
        $totalSubmissions = 0;
        $bulkDays = 0;
        $regularDays = 0;
        
        foreach ($submissions as $day) {
            $count = $day['daily_count'];
            $totalSubmissions += $count;
            
            // A bulk submission day: more than 2 submissions in a single day
            // (since normally a student submits 1 DPR per day)
            if ($count > 2) {
                $bulkDays++;
            } elseif ($count == 1) {
                $regularDays++;
            }
            // Count == 2 is moderate - neither regular nor bulk
        }
        
        // Calculate metrics
        // 1. Submission rate (how many days out of 30 did they submit)
        $submissionRate = ($daysWithSubmissions / $totalDays) * 100;
        
        // 2. Bulk submission penalty
        // If more than 30% of submissions days are bulk, penalize heavily
        $bulkRatio = $bulkDays / max($daysWithSubmissions, 1);
        $bulkPenalty = min($bulkRatio * 50, 50); // Up to 50% penalty
        
        // 3. Submission frequency score
        // Regular daily submissions = 100%, irregular = lower
        // Only count days with exactly 1 submission as "regular"
        $regularRatio = $regularDays / max($daysWithSubmissions, 1);
        $frequencyScore = $regularRatio * 100;
        
        // 4. Calculate final velocity score
        // Weighted: 60% frequency + 40% rate
        $baseScore = ($frequencyScore * 0.6) + ($submissionRate * 0.4);
        
        // Apply bulk penalty
        $finalScore = max($baseScore - $bulkPenalty, 0);
        
        // Boost for consistent daily submissions (no gaps > 3 days)
        if ($daysWithSubmissions > 0) {
            // Check for gaps
            $gapPenalty = 0;
            for ($i = 1; $i < count($submissions); $i++) {
                $current = strtotime($submissions[$i]['date']);
                $previous = strtotime($submissions[$i-1]['date']);
                $gap = ($current - $previous) / (60 * 60 * 24); // days between submissions
                
                if ($gap > 3) {
                    $gapPenalty += 5; // Penalty for each gap > 3 days
                }
            }
            $finalScore = max($finalScore - $gapPenalty, 0);
        }
        
        // Normalize to 0-100
        return min(round($finalScore, 2), 100);
        
    } catch (Exception $e) {
        return 50; // Default moderate score
    }
}

// Calculate grade variance from supervisor evaluations
function calculateGradeVariance($pdo, $studentId) {
    try {
        $stmt = $pdo->prepare("
            SELECT score 
            FROM dpr_entries 
            WHERE student_id = ? AND score IS NOT NULL AND score > 0
            ORDER BY evaluated_at DESC
        ");
        $stmt->execute([$studentId]);
        $scores = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($scores) < 2) {
            return 75; // Default score if insufficient data
        }
        
        // Calculate variance
        $mean = array_sum($scores) / count($scores);
        $variance = 0;
        foreach ($scores as $score) {
            $variance += pow($score - $mean, 2);
        }
        $variance = $variance / count($scores);
        $stdDev = sqrt($variance);
        
        // Convert to score: Lower variance = higher score
        // Variance of 0-10 = 100 points, 10-20 = 80 points, etc.
        $score = max(100 - ($stdDev * 2), 0);
        
        return $score;
        
    } catch (Exception $e) {
        return 75; // Default moderate score
    }
}

// Calculate sentiment discrepancy metric
function calculateSentimentDiscrepancy($pdo, $studentId) {
    try {
        $stmt = $pdo->prepare("
            SELECT score, supervisor_feedback 
            FROM dpr_entries 
            WHERE student_id = ? 
            AND score IS NOT NULL 
            AND supervisor_feedback IS NOT NULL 
            AND supervisor_feedback != ''
            ORDER BY evaluated_at DESC
            LIMIT 10
        ");
        $stmt->execute([$studentId]);
        $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($evaluations)) {
            return 75; // Default score
        }
        
        $discrepancies = 0;
        $totalEvaluations = count($evaluations);
        
        foreach ($evaluations as $eval) {
            // Simple keyword-based sentiment analysis
            $sentiment = analyzeFeedbackSentiment($eval['supervisor_feedback']);
            $discrepancy = detectDiscrepancyLevel($eval['score'], $sentiment);
            $discrepancies += $discrepancy;
        }
        
        // Convert to score: Lower discrepancy = higher score
        $avgDiscrepancy = $discrepancies / $totalEvaluations;
        $score = max(100 - ($avgDiscrepancy * 25), 0);
        
        return $score;
        
    } catch (Exception $e) {
        return 75; // Default moderate score
    }
}

// Calculate student sentiment from their own feedback
function calculateStudentSentiment($pdo, $studentId) {
    try {
        $stmt = $pdo->prepare("
            SELECT feedback 
            FROM dpr_entries 
            WHERE student_id = ? 
            AND feedback IS NOT NULL 
            AND feedback != ''
            ORDER BY date DESC
            LIMIT 10
        ");
        $stmt->execute([$studentId]);
        $feedbacks = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($feedbacks)) {
            return 75; // Default neutral score
        }
        
        $totalSentiment = 0;
        $count = 0;
        
        foreach ($feedbacks as $feedback) {
            $sentiment = analyzeFeedbackSentiment($feedback);
            $totalSentiment += $sentiment;
            $count++;
        }
        
        $avgSentiment = $totalSentiment / $count;
        
        // Convert sentiment to score (1-100)
        $score = (($avgSentiment + 1) / 2) * 100; // Convert from -1,1 to 0,100
        
        return $score;
        
    } catch (Exception $e) {
        return 75; // Default moderate score
    }
}

// Simple sentiment analysis function
function analyzeFeedbackSentiment($text) {
    $text = strtolower($text);
    
    $positive = ['good', 'great', 'excellent', 'amazing', 'fantastic', 'wonderful', 'happy', 'satisfied', 'productive', 'successful', 'achieved', 'completed', 'learned', 'improved', 'helpful'];
    $negative = ['bad', 'terrible', 'awful', 'difficult', 'challenging', 'struggling', 'confused', 'frustrated', 'disappointed', 'failed', 'missed', 'late', 'problems', 'issues'];
    
    $positiveCount = 0;
    $negativeCount = 0;
    
    foreach ($positive as $word) {
        if (strpos($text, $word) !== false) $positiveCount++;
    }
    
    foreach ($negative as $word) {
        if (strpos($text, $word) !== false) $negativeCount++;
    }
    
    if ($positiveCount > $negativeCount) return 0.8;  // Positive
    if ($negativeCount > $positiveCount) return -0.8; // Negative
    return 0; // Neutral
}

// Detect discrepancy level between score and sentiment
function detectDiscrepancyLevel($score, $sentiment) {
    // Score categories
    $scoreLevel = 0;
    if ($score >= 90) $scoreLevel = 2;      // Very positive
    elseif ($score >= 70) $scoreLevel = 1;  // Positive
    elseif ($score >= 40) $scoreLevel = 0;  // Neutral
    elseif ($score >= 20) $scoreLevel = -1; // Negative
    else $scoreLevel = -2;                   // Very negative
    
    // Sentiment level
    $sentimentLevel = 0;
    if ($sentiment > 0.5) $sentimentLevel = 2;      // Very positive
    elseif ($sentiment > 0) $sentimentLevel = 1;    // Positive
    elseif ($sentiment < -0.5) $sentimentLevel = -2; // Very negative
    elseif ($sentiment < 0) $sentimentLevel = -1;   // Negative
    else $sentimentLevel = 0;                        // Neutral
    
    // Calculate discrepancy (0 = aligned, 4 = maximum discrepancy)
    return abs($scoreLevel - $sentimentLevel);
}

// Risk classification based on overall score
function classifyRisk($overallScore) {
    if ($overallScore >= 80) return 'High Performing';
    if ($overallScore >= 60) return 'Neutral';
    return 'At-Risk';
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
    <title>Coordinator - Decision Support System</title>
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
            width: 20px;
            height: 20px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #003300;
        }

        /* ---- Page card (sharp, bordered) - matches intern.php ---- */
        .page-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 20px 24px 28px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            flex: 1;
            border-radius: 0;
            display: flex;
            flex-direction: column;
            min-height: 500px;
        }

        .page-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-card-header h2 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0f172a;
        }

        .page-card-header h2 i {
            color: #3b82f6;
        }

        .page-card-header p {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 2px;
        }

        .summary-stats {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-size: 0.75rem;
            font-weight: 600;
            color: #475569;
            border-radius: 0;
        }

        .stat-pill i {
            color: #3b82f6;
            font-size: 0.8rem;
        }

        .stat-pill .count {
            color: #0f172a;
            font-size: 0.95rem;
        }

        /* ---- Loading Spinner ---- */
        .loading-spinner {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .loading-spinner i {
            font-size: 2.5rem;
            color: #FFCC33;
            animation: spin 1s linear infinite;
            display: block;
            margin-bottom: 12px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-spinner p {
            font-size: 0.95rem;
        }

        /* ---- Table (compressed) - matches intern.php style ---- */
        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
            border-radius: 0;
            min-height: 320px;
            flex: 1;
        }

        .dss-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .dss-table th {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 600;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .dss-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .dss-table tbody tr:last-child td {
            border-bottom: none;
        }

        .dss-table tbody tr:hover {
            background: #fafcff;
        }

        .student-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.85rem;
        }

        .company-name {
            color: #475569;
            font-size: 0.78rem;
        }

        .job-title {
            color: #475569;
            font-size: 0.78rem;
        }

        /* ---- Metric Scores (compressed) ---- */
        .metric-score {
            display: inline-block;
            padding: 2px 10px;
            font-weight: 600;
            font-size: 0.72rem;
            border: 1px solid transparent;
            border-radius: 0;
            min-width: 44px;
            text-align: center;
        }

        .metric-score.high {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .metric-score.medium {
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .metric-score.low {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        /* ---- Risk Badge (compressed) ---- */
        .risk-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            font-weight: 600;
            font-size: 0.7rem;
            border: 1px solid transparent;
            border-radius: 0;
            white-space: nowrap;
        }

        .risk-badge.high-performing {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .risk-badge.neutral {
            background: #fef9c3;
            color: #854d0e;
            border-color: #facc15;
        }

        .risk-badge.at-risk {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
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
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        .empty-state h3 {
            color: #1e293b;
            margin-bottom: 6px;
            font-size: 1.1rem;
        }

        .empty-state p {
            font-size: 0.9rem;
        }

        .empty-state .btn-retry {
            margin-top: 12px;
            padding: 6px 16px;
            background: #0f172a;
            color: #fff;
            border: 1px solid #0f172a;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.8rem;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            border-radius: 0;
        }

        .empty-state .btn-retry:hover {
            background: #1e293b;
        }

        /* ---- ML Service Required Message ---- */
        .ml-required-message {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 80px 40px;
            flex: 1;
        }

        .ml-required-message i {
            font-size: 4rem;
            color: #f59e0b;
            margin-bottom: 20px;
        }

        .ml-required-message h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
        }

        .ml-required-message p {
            font-size: 0.95rem;
            color: #64748b;
            max-width: 500px;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .ml-required-message .btn-go-evaluation {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            background: #FFCC33;
            color: #003300;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            border: 2px solid #003300;
            border-radius: 0;
            transition: all 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .ml-required-message .btn-go-evaluation:hover {
            background: #003300;
            color: #FFCC33;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 51, 0, 0.2);
        }

        /* ---- Pagination (bottom right - edge of page) - matches intern.php ---- */
        .pagination-wrapper {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-top: 16px;
            gap: 6px;
            flex-wrap: wrap;
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
            width: 100%;
        }

        .pagination-wrapper .page-info {
            font-size: 0.8rem;
            color: #64748b;
            margin-right: auto;
        }

        .pagination-wrapper .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #1e293b;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            transition: 0.15s;
            min-width: 36px;
            border-radius: 0;
        }

        .pagination-wrapper .page-link:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .pagination-wrapper .page-link.active {
            background: #003300;
            color: #FFCC33;
            border-color: #003300;
            pointer-events: none;
        }

        .pagination-wrapper .page-link.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* ---- Toast ---- */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #0f172a;
            color: #f1f5f9;
            padding: 14px 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: none;
            align-items: center;
            gap: 10px;
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
            font-size: 1.1rem;
        }

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

        .modal-container {
            background: #fff;
            border: 1px solid #e2e8f0;
            max-width: 480px;
            width: 100%;
            padding: 28px 26px 24px;
            box-shadow: 0 40px 60px -20px rgba(0,0,0,0.3);
            animation: slideUp 0.25s ease;
            border-radius: 0;
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
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid #edf2f7;
        }

        .modal-header h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 i {
            color: #2563eb;
        }

        .modal-close-btn {
            background: none;
            border: none;
            font-size: 1.6rem;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 6px;
            transition: 0.15s;
            line-height: 1;
        }

        .modal-close-btn:hover {
            color: #1e293b;
        }

        .modal-body {
            padding: 0;
        }

        .modal-body p {
            color: #64748b;
            margin-bottom: 16px;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.82rem;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .form-group label i {
            margin-right: 6px;
            color: #64748b;
        }

        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.9rem;
            background: #fafcff;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .form-group input:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
            border-color: transparent;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            border-top: 1px solid #edf2f7;
            padding-top: 18px;
        }

        .btn-primary {
            background: #0f172a;
            border: 1px solid #0f172a;
            color: #fff;
            padding: 8px 22px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary:hover:not(:disabled) {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .btn-secondary {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            padding: 8px 20px;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .btn-secondary:hover {
            background: #e9edf4;
        }

        /* ===== PASSWORD MODAL ===== */
        #passwordModal .modal-container {
            max-width: 480px;
        }

        #passwordModal .form-group {
            margin-bottom: 14px;
        }

        #passwordModal .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.82rem;
            color: #1e293b;
            margin-bottom: 4px;
        }

        #passwordModal .form-group label i {
            margin-right: 6px;
            color: #64748b;
        }

        #passwordModal .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d9e6;
            border-radius: 0;
            font-size: 0.9rem;
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
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            border-top: 1px solid #edf2f7;
            padding-top: 18px;
        }

        /* ---- Responsive ---- */
        @media (max-width: 1024px) {
            .page-card-header {
                flex-direction: column;
                align-items: stretch;
            }
            .summary-stats {
                justify-content: flex-start;
            }
            .dss-table {
                font-size: 0.75rem;
            }
            .dss-table th,
            .dss-table td {
                padding: 6px 8px;
            }
            .page-card {
                min-height: 400px;
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

            .notif-bell {
                align-self: center;
            }

            .page-card {
                padding: 14px;
                min-height: 350px;
            }

            .page-card-header h2 {
                font-size: 1rem;
            }

            .dss-table th,
            .dss-table td {
                padding: 5px 6px;
                font-size: 0.65rem;
            }

            .metric-score {
                font-size: 0.6rem;
                padding: 1px 6px;
                min-width: 30px;
            }

            .risk-badge {
                font-size: 0.6rem;
                padding: 1px 6px;
            }

            .stat-pill {
                font-size: 0.65rem;
                padding: 4px 10px;
            }

            .summary-stats {
                gap: 6px;
            }

            .modal-container {
                padding: 20px 16px;
                max-height: 95vh;
                margin: 10px;
            }

            .student-name,
            .company-name,
            .job-title {
                font-size: 0.65rem;
            }

            .table-wrap {
                min-height: 250px;
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

            .dss-table th,
            .dss-table td {
                padding: 4px 4px;
                font-size: 0.6rem;
            }

            .stat-pill {
                font-size: 0.6rem;
                padding: 3px 8px;
            }

            .stat-pill .count {
                font-size: 0.8rem;
            }

            .modal-actions {
                flex-direction: column;
            }

            .modal-actions .btn-primary,
            .modal-actions .btn-secondary {
                width: 100%;
                justify-content: center;
            }

            .page-card {
                min-height: 300px;
                padding: 10px;
            }

            .table-wrap {
                min-height: 200px;
            }

            .pagination-wrapper .page-link {
                padding: 2px 8px;
                font-size: 0.7rem;
                min-width: 28px;
            }
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
                <i class="fa-solid fa-users-gear"></i>
                <h2>System<span>Coordinator Desk</span></h2>
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
                        <i class="fa-solid fa-brain"></i>
                        Decision Support System
                        <small>Coordinator</small>
                    </h1>
                </div>
                <div class="header-right">
                    <!-- Header Navigation -->
                    <nav class="header-nav">
                        <a class="nav-item-header" href="dashboard.php"> Dashboard</a>
                        <a class="nav-item-header" href="company.php"> Companies</a>
                        <a class="nav-item-header" href="intern.php"> Internship</a>
                        <a class="nav-item-header" href="evaluation.php"> Evaluation</a>
                        <a class="nav-item-header active" href="dss.php"> Decision Support</a>
                    </nav>

                    <!-- Notification bell -->
                    <?php 
                    require_once __DIR__ . '/notification_component.php';
                    renderNotificationBell($unreadCount, $notificationsList);
                    ?>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-card">
                <div class="page-card-header">
                    <div>
                        <h2><i class="fa-solid fa-chart-line"></i> Student Performance Analytics</h2>
                        <p>Comprehensive metrics for committed students based on DPR submissions, evaluations, and feedback.</p>
                    </div>
                    <div class="summary-stats" id="summaryStats">
                        <!-- Stats will be populated by JavaScript -->
                    </div>
                </div>

                <!-- Loading and Results -->
                <div id="dssContainer" style="flex: 1; display: flex; flex-direction: column;">
                    <div id="loadingSpinner" class="loading-spinner" style="display: flex;">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                        <p>Analyzing student performance metrics...</p>
                    </div>

                    <div id="resultsContainer" style="display: none; flex: 1; display: flex; flex-direction: column;">
                        <div class="table-wrap">
                            <table class="dss-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Company</th>
                                        <th>Job</th>
                                        <th>Velocity</th>
                                        <th>Grade Var.</th>
                                        <th>Sent. Disc.</th>
                                        <th>Sentiment</th>
                                        <th>Risk</th>
                                    </tr>
                                </thead>
                                <tbody id="dssTableBody">
                                    <tr>
                                        <td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">
                                            <i class="fa-regular fa-smile" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                                            Loading student data...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ===== PAGINATION (Always Visible) ===== -->
                <div class="pagination-wrapper" id="paginationWrapper">
                    <span class="page-info" id="pageInfo">Loading...</span>
                    <a href="#" class="page-link disabled" id="prevPage">Prev</a>
                    <span id="pageNumbers"></span>
                    <a href="#" class="page-link disabled" id="nextPage">Next</a>
                </div>
            </div>
        </main>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-key"></i> Change Password</h3>
                <button type="button" class="modal-close-btn" id="closePasswordBtn">&times;</button>
            </div>
            <div class="modal-body">
                <p>Enter your current password and choose a new one.</p>
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
                        <button type="button" class="btn-secondary" id="closePasswordBtn2">Cancel</button>
                        <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast">
        <i class="fa-regular fa-circle-check"></i>
        <span id="toastMessage">Success!</span>
    </div>

    <script>
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
            }
            
            toast.className = 'toast ' + type + ' show';
            toastMessage.textContent = message;
            
            clearTimeout(toast._timeout);
            toast._timeout = setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
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
                    passwordModal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                    if (passwordForm) passwordForm.reset();
                }
            });
        }

        function closePasswordModal() {
            if (passwordModal) {
                passwordModal.style.display = 'none';
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

        // ===== DSS FUNCTIONS =====
        // Pagination variables
        let currentPage = 1;
        const itemsPerPage = 10;
        let allData = [];
        let isSentimentServiceRunning = false;

        // Load DSS data when page loads
        document.addEventListener('DOMContentLoaded', function() {
            checkMLServiceAndLoad();
        });

        // Check if ML service is running before loading data
        async function checkMLServiceAndLoad() {
            const loadingSpinner = document.getElementById('loadingSpinner');
            const resultsContainer = document.getElementById('resultsContainer');
            
            try {
                loadingSpinner.style.display = 'flex';
                loadingSpinner.innerHTML = `
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <p>Checking ML service status...</p>
                `;
                resultsContainer.style.display = 'none';
                
                // Check if sentiment service is available
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 1500);
                
                try {
                    const testResponse = await fetch('http://localhost:8000/health', { 
                        method: 'GET',
                        signal: controller.signal 
                    });
                    clearTimeout(timeoutId);
                    isSentimentServiceRunning = testResponse.ok;
                } catch (error) {
                    console.log('ML service not available');
                    isSentimentServiceRunning = false;
                }
                
                loadingSpinner.style.display = 'none';
                
                // If ML service is NOT running, show requirement message
                if (!isSentimentServiceRunning) {
                    displayMLRequiredMessage();
                    return;
                }
                
                // ML service is running, proceed to load data
                loadDSSData();
                
            } catch (error) {
                console.error('Error checking ML service:', error);
                loadingSpinner.style.display = 'none';
                displayMLRequiredMessage();
            }
        }

        // Display message that ML service is required
        function displayMLRequiredMessage() {
            const resultsContainer = document.getElementById('resultsContainer');
            const tbody = document.getElementById('dssTableBody');
            const summaryStats = document.getElementById('summaryStats');
            const paginationWrapper = document.getElementById('paginationWrapper');
            
            // Clear summary stats
            summaryStats.innerHTML = '';
            
            // Hide pagination
            paginationWrapper.style.display = 'none';
            
            // Show message in table
            if (tbody) {
                tbody.innerHTML = `<tr>
                    <td colspan="8" style="padding:0;border:none;">
                        <div class="ml-required-message">
                            <i class="fa-solid fa-robot"></i>
                            <h3>Machine Learning Service Required</h3>
                            <p>Run the Machine Learning First in Evaluation to Access Accurate Decision Support System</p>
                            <a href="evaluation.php" class="btn-go-evaluation">
                                <i class="fa-solid fa-arrow-right"></i> Go to Evaluation
                            </a>
                        </div>
                    </td>
                </tr>`;
            }
            
            resultsContainer.style.display = 'flex';
        }

        async function loadDSSData() {
            const loadingSpinner = document.getElementById('loadingSpinner');
            const resultsContainer = document.getElementById('resultsContainer');
            
            try {
                loadingSpinner.style.display = 'flex';
                loadingSpinner.innerHTML = `
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <p>Analyzing student performance metrics...</p>
                `;
                resultsContainer.style.display = 'none';
                
                // Show pagination with loading state
                const paginationWrapper = document.getElementById('paginationWrapper');
                paginationWrapper.style.display = 'flex';
                document.getElementById('pageInfo').textContent = 'Loading...';
                document.getElementById('prevPage').className = 'page-link disabled';
                document.getElementById('nextPage').className = 'page-link disabled';
                document.getElementById('pageNumbers').innerHTML = '';
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_dss_data'
                });
                
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    allData = data.data;
                    displayResults(data.data);
                } else {
                    displayEmptyState(data.message || 'No student data available for analysis.');
                }
                
            } catch (error) {
                console.error('Error loading DSS data:', error);
                displayError('Failed to load decision support data. Please try again.');
            } finally {
                loadingSpinner.style.display = 'none';
                resultsContainer.style.display = 'flex';
            }
        }

        function displayResults(data) {
            const resultsContainer = document.getElementById('resultsContainer');
            
            // Calculate summary stats
            const totalStudents = data.length;
            const highPerforming = data.filter(s => s.risk_classification === 'High Performing').length;
            const neutral = data.filter(s => s.risk_classification === 'Neutral').length;
            const atRisk = data.filter(s => s.risk_classification === 'At-Risk').length;
            
            // Update summary stats
            document.getElementById('summaryStats').innerHTML = `
                <span class="stat-pill"><i class="fa-solid fa-users"></i> Total: <span class="count">${totalStudents}</span></span>
                <span class="stat-pill" style="background: #dcfce7; border-color: #86efac;"><i class="fa-solid fa-check-circle" style="color: #16a34a;"></i> High: <span class="count">${highPerforming}</span></span>
                <span class="stat-pill" style="background: #fef9c3; border-color: #facc15;"><i class="fa-solid fa-minus-circle" style="color: #d97706;"></i> Neutral: <span class="count">${neutral}</span></span>
                <span class="stat-pill" style="background: #fee2e2; border-color: #fca5a5;"><i class="fa-solid fa-exclamation-circle" style="color: #dc2626;"></i> At-Risk: <span class="count">${atRisk}</span></span>
            `;
            
            // Show results container
            resultsContainer.style.display = 'flex';
            
            // Store data and render first page
            allData = data;
            renderPage(1);
        }

        // ===== PAGINATION FUNCTIONS =====
        function renderPage(page) {
            currentPage = page;
            const totalItems = allData.length;
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            // Always show pagination wrapper
            const paginationWrapper = document.getElementById('paginationWrapper');
            paginationWrapper.style.display = 'flex';
            
            if (totalItems === 0) {
                // Update pagination for empty state
                document.getElementById('pageInfo').textContent = 'Showing 0–0 of 0';
                document.getElementById('prevPage').className = 'page-link disabled';
                document.getElementById('nextPage').className = 'page-link disabled';
                document.getElementById('pageNumbers').innerHTML = '';
                return;
            }
            
            const start = (page - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, totalItems);
            const pageItems = allData.slice(start, end);
            
            // Update page info
            document.getElementById('pageInfo').textContent = 
                `Showing ${totalItems > 0 ? start + 1 : 0}–${end} of ${totalItems}`;
            
            // Render the table with current page items
            renderTableRows(pageItems);
            
            // Update pagination controls
            const prevLink = document.getElementById('prevPage');
            const nextLink = document.getElementById('nextPage');
            const pageNumbers = document.getElementById('pageNumbers');
            
            prevLink.className = 'page-link' + (page <= 1 ? ' disabled' : '');
            prevLink.href = '#';
            prevLink.onclick = function(e) {
                e.preventDefault();
                if (page > 1) renderPage(page - 1);
            };
            
            nextLink.className = 'page-link' + (page >= totalPages ? ' disabled' : '');
            nextLink.href = '#';
            nextLink.onclick = function(e) {
                e.preventDefault();
                if (page < totalPages) renderPage(page + 1);
            };
            
            // Generate page number links
            let pageHtml = '';
            const maxVisible = 5;
            let startPage = Math.max(1, page - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            
            if (endPage - startPage < maxVisible - 1) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }
            
            if (startPage > 1) {
                pageHtml += `<a href="#" class="page-link" onclick="event.preventDefault(); renderPage(1)">1</a>`;
                if (startPage > 2) {
                    pageHtml += `<span class="page-link disabled">…</span>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                pageHtml += `<a href="#" class="page-link${i === page ? ' active' : ''}" onclick="event.preventDefault(); renderPage(${i})">${i}</a>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    pageHtml += `<span class="page-link disabled">…</span>`;
                }
                pageHtml += `<a href="#" class="page-link" onclick="event.preventDefault(); renderPage(${totalPages})">${totalPages}</a>`;
            }
            
            pageNumbers.innerHTML = pageHtml;
        }

        function renderTableRows(data) {
            const tbody = document.getElementById('dssTableBody');
            if (!tbody) return;
            
            let html = '';
            
            if (data.length === 0) {
                html = `<tr>
                    <td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">
                        <i class="fa-regular fa-smile" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                        No students found.
                    </td>
                </tr>`;
            } else {
                data.forEach(student => {
                    const velocityScore = student.submission_velocity || 0;
                    const gradeScore = student.grade_variance || 0;
                    const sentimentScore = student.sentiment_discrepancy || 0;
                    const studentSentimentScore = student.student_sentiment || 0;
                    
                    const velocityClass = velocityScore >= 75 ? 'high' : (velocityScore >= 50 ? 'medium' : 'low');
                    const gradeClass = gradeScore >= 75 ? 'high' : (gradeScore >= 50 ? 'medium' : 'low');
                    const sentimentClass = sentimentScore >= 75 ? 'high' : (sentimentScore >= 50 ? 'medium' : 'low');
                    const studentSentimentClass = studentSentimentScore >= 75 ? 'high' : (studentSentimentScore >= 50 ? 'medium' : 'low');
                    
                    const riskClass = student.risk_classification === 'High Performing' ? 'high-performing' : 
                                     (student.risk_classification === 'Neutral' ? 'neutral' : 'at-risk');
                    
                    const riskIcon = student.risk_classification === 'High Performing' ? '✅' : 
                                     (student.risk_classification === 'Neutral' ? '⚠️' : '🚨');
                    
                    html += `
                        <tr>
                            <td><span class="student-name">${escapeHtml(student.student_name)}</span></td>
                            <td><span class="company-name">${escapeHtml(student.company_name)}</span></td>
                            <td><span class="job-title">${escapeHtml(student.job_title)}</span></td>
                            <td><span class="metric-score ${velocityClass}">${velocityScore}%</span></td>
                            <td><span class="metric-score ${gradeClass}">${gradeScore}%</span></td>
                            <td><span class="metric-score ${sentimentClass}">${sentimentScore}%</span></td>
                            <td><span class="metric-score ${studentSentimentClass}">${studentSentimentScore}%</span></td>
                            <td><span class="risk-badge ${riskClass}">${riskIcon} ${student.risk_classification}</span></td>
                        </tr>
                    `;
                });
            }
            
            tbody.innerHTML = html;
        }

        function displayEmptyState(message) {
            const resultsContainer = document.getElementById('resultsContainer');
            resultsContainer.style.display = 'flex';
            
            const tbody = document.getElementById('dssTableBody');
            if (tbody) {
                tbody.innerHTML = `<tr>
                    <td colspan="8" style="text-align:center;padding:40px;">
                        <div class="empty-state" style="padding:20px;">
                            <i class="fa-solid fa-users" style="font-size:2rem;"></i>
                            <h3>No Students Found</h3>
                            <p>${message || 'There are no committed students with internship data available for analysis.'}</p>
                        </div>
                    </td>
                </tr>`;
            }
            
            document.getElementById('summaryStats').innerHTML = '';
            
            // Show pagination with empty state
            const paginationWrapper = document.getElementById('paginationWrapper');
            paginationWrapper.style.display = 'flex';
            document.getElementById('pageInfo').textContent = 'Showing 0–0 of 0';
            document.getElementById('prevPage').className = 'page-link disabled';
            document.getElementById('nextPage').className = 'page-link disabled';
            document.getElementById('pageNumbers').innerHTML = '';
        }

        function displayError(message) {
            const resultsContainer = document.getElementById('resultsContainer');
            resultsContainer.style.display = 'flex';
            
            const tbody = document.getElementById('dssTableBody');
            if (tbody) {
                tbody.innerHTML = `<tr>
                    <td colspan="8" style="text-align:center;padding:40px;">
                        <div style="display:flex;flex-direction:column;align-items:center;gap:12px;">
                            <i class="fa-solid fa-exclamation-triangle" style="font-size:2rem;color:#ef4444;"></i>
                            <span style="color:#1e293b;font-weight:600;">Error</span>
                            <span style="color:#64748b;font-size:0.9rem;">${message}</span>
                            <button class="btn-retry" onclick="loadDSSData()" style="margin-top:4px;">
                                <i class="fa-solid fa-rotate"></i> Try Again
                            </button>
                        </div>
                    </td>
                </tr>`;
            }
            
            document.getElementById('summaryStats').innerHTML = '';
            
            // Show pagination with error state
            const paginationWrapper = document.getElementById('paginationWrapper');
            paginationWrapper.style.display = 'flex';
            document.getElementById('pageInfo').textContent = 'Error loading data';
            document.getElementById('prevPage').className = 'page-link disabled';
            document.getElementById('nextPage').className = 'page-link disabled';
            document.getElementById('pageNumbers').innerHTML = '';
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closePasswordModal();
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                }
            }
        });
    </script>

    <?php renderNotificationScript(); ?>
</body>
</html>