<?php
// includes/coordinator_notifications.php
// Shared notification helpers for all coordinator pages

/**
 * Get unread notification count for coordinator
 */
function getCoordinatorUnreadNotificationCount($pdo, $coordinator_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$coordinator_id]);
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get notifications for coordinator
 */
function getCoordinatorNotifications($pdo, $coordinator_id, $limit = 20, $offset = 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                n.id,
                n.user_id,
                n.sender_id,
                n.type,
                n.title,
                n.message,
                n.link,
                n.is_read,
                n.created_at,
                u.firstname,
                u.lastname
            FROM notifications n
            LEFT JOIN users u ON n.sender_id = u.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        // Use string interpolation for LIMIT/OFFSET (MariaDB compatibility)
        $query = "
            SELECT 
                n.id,
                n.user_id,
                n.sender_id,
                n.type,
                n.title,
                n.message,
                n.link,
                n.is_read,
                n.created_at,
                u.firstname,
                u.lastname
            FROM notifications n
            LEFT JOIN users u ON n.sender_id = u.id
            WHERE n.user_id = $coordinator_id
            ORDER BY n.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $results ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Mark single notification as read
 */
function markCoordinatorNotificationRead($pdo, $notification_id, $coordinator_id) {
    try {
        // Verify ownership before updating
        $verifyStmt = $pdo->prepare("
            SELECT id FROM notifications 
            WHERE id = ? AND user_id = ?
        ");
        $verifyStmt->execute([$notification_id, $coordinator_id]);
        
        if (!$verifyStmt->fetch()) {
            return false;
        }
        
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notification_id, $coordinator_id]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Mark all notifications as read for coordinator
 */
function markCoordinatorAllNotificationsRead($pdo, $coordinator_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$coordinator_id]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get notification source (sender name or System)
 */
function getCoordinatorNotificationSource($notif) {
    if (!empty($notif['firstname']) && !empty($notif['lastname'])) {
        return $notif['firstname'] . ' ' . $notif['lastname'];
    }
    return 'System';
}

/**
 * Get notification message (from message field or title as fallback)
 */
function getCoordinatorNotificationMessage($notif) {
    if (!empty($notif['message'])) {
        return $notif['message'];
    }
    return $notif['title'] ?? 'Notification';
}

/**
 * Format time as "time ago" (PHP version for server-side rendering)
 */
function timeAgo($dateStr) {
    if (!$dateStr) {
        return '';
    }
    
    try {
        $date = new DateTime($dateStr);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $date->getTimestamp();
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        if ($diff < 2592000) return floor($diff / 604800) . 'w ago';
        return $date->format('M d, Y');
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Check for students with high sentiment discrepancy and create notifications
 * This should be called periodically to monitor student performance
 */
function checkAndNotifyHighSentimentDiscrepancy($pdo, $coordinatorId) {
    try {
        // Get all committed students with their evaluations
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                s.id as student_id,
                s.firstname,
                s.lastname
            FROM users s
            INNER JOIN job_applications a ON s.id = a.student_id
            WHERE s.role = 'student' AND a.status = 'committed'
        ");
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($students as $student) {
            // Get evaluations with scores and feedback
            $evalStmt = $pdo->prepare("
                SELECT score, supervisor_feedback
                FROM dpr_entries
                WHERE student_id = ?
                AND score IS NOT NULL
                AND supervisor_feedback IS NOT NULL
                AND supervisor_feedback != ''
                AND date >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                ORDER BY date DESC
            ");
            $evalStmt->execute([$student['student_id']]);
            $evaluations = $evalStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($evaluations) > 0) {
                $discrepancyCount = 0;
                $totalEvals = count($evaluations);
                
                foreach ($evaluations as $eval) {
                    // Simple sentiment analysis
                    $feedback = strtolower($eval['supervisor_feedback']);
                    $score = (float)$eval['score'];
                    
                    // Positive keywords
                    $positiveWords = ['excellent', 'great', 'good', 'amazing', 'outstanding', 'wonderful', 'impressive', 'superb', 'fantastic'];
                    $negativeWords = ['poor', 'bad', 'terrible', 'awful', 'disappointing', 'inadequate', 'unacceptable', 'lacking', 'below'];
                    
                    $positiveCount = 0;
                    $negativeCount = 0;
                    
                    foreach ($positiveWords as $word) {
                        if (strpos($feedback, $word) !== false) $positiveCount++;
                    }
                    
                    foreach ($negativeWords as $word) {
                        if (strpos($feedback, $word) !== false) $negativeCount++;
                    }
                    
                    // Determine sentiment
                    $sentiment = 'neutral';
                    if ($positiveCount > $negativeCount) $sentiment = 'positive';
                    if ($negativeCount > $positiveCount) $sentiment = 'negative';
                    
                    // Check for discrepancy
                    // High score + negative sentiment OR Low score + positive sentiment
                    if (($score >= 80 && $sentiment === 'negative') || ($score < 60 && $sentiment === 'positive')) {
                        $discrepancyCount++;
                    }
                }
                
                // If more than 30% of evaluations show discrepancy
                $discrepancyRate = ($discrepancyCount / $totalEvals) * 100;
                
                if ($discrepancyRate > 30) {
                    // Check if notification already exists in last 7 days
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM notifications
                        WHERE user_id = ? 
                        AND sender_id = ?
                        AND type = 'sentiment_discrepancy'
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ");
                    $checkStmt->execute([$coordinatorId, $student['student_id']]);
                    
                    if (!$checkStmt->fetch()) {
                        $studentName = trim($student['firstname'] . ' ' . $student['lastname']);
                        createSentimentDiscrepancyNotification($pdo, $coordinatorId, $student['student_id'], $studentName, $discrepancyRate);
                    }
                }
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log("Error checking sentiment discrepancy: " . $e->getMessage());
        return false;
    }
}

/**
 * Check for students at performance risk and create notifications
 */
function checkAndNotifyPerformanceAtRisk($pdo, $coordinatorId) {
    try {
        // Get all committed students with their performance metrics
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                s.id as student_id,
                s.firstname,
                s.lastname
            FROM users s
            INNER JOIN job_applications a ON s.id = a.student_id
            WHERE s.role = 'student' AND a.status = 'committed'
        ");
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($students as $student) {
            // Calculate overall performance score
            $performanceStmt = $pdo->prepare("
                SELECT 
                    AVG(CAST(score AS DECIMAL(5,2))) as avg_score,
                    COUNT(*) as dpr_count,
                    MAX(date) as last_dpr
                FROM dpr_entries
                WHERE student_id = ?
                AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $performanceStmt->execute([$student['student_id']]);
            $performance = $performanceStmt->fetch();
            
            if ($performance && $performance['dpr_count'] > 0) {
                // Expected: ~20-22 DPRs in 30 days (working days)
                $expectedDPR = 20;
                $dprCountScore = min(($performance['dpr_count'] / $expectedDPR) * 100, 100);
                
                // Rating score (out of 100)
                $ratingScore = ($performance['avg_score'] ?? 0);
                
                // Overall performance: 40% submission rate + 60% rating
                $overallPerformance = ($dprCountScore * 0.4) + ($ratingScore * 0.6);
                
                // If overall performance < 50, student is at risk
                if ($overallPerformance < 50) {
                    // Check if notification already exists in last 7 days
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM notifications
                        WHERE user_id = ? 
                        AND sender_id = ?
                        AND type = 'performance_risk'
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ");
                    $checkStmt->execute([$coordinatorId, $student['student_id']]);
                    
                    if (!$checkStmt->fetch()) {
                        $studentName = trim($student['firstname'] . ' ' . $student['lastname']);
                        createPerformanceRiskNotification($pdo, $coordinatorId, $student['student_id'], $studentName, $overallPerformance);
                    }
                }
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log("Error checking performance risk: " . $e->getMessage());
        return false;
    }
}

/**
 * Get students committed to jobs for coordinator notifications
 */
function getCoordinatorCommittedStudents($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.id as student_id,
                s.firstname,
                s.lastname,
                c.company_name,
                j.title as job_title,
                a.created_at as commitment_date
            FROM users s
            INNER JOIN job_applications a ON s.id = a.student_id
            INNER JOIN jobs j ON a.job_id = j.id
            INNER JOIN companies c ON j.company_id = c.id
            WHERE s.role = 'student' AND a.status = 'committed'
            AND a.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ORDER BY a.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Create notification for student commitment to job
 */
function createStudentCommitmentNotification($pdo, $coordinatorId, $studentId, $studentName, $jobTitle, $companyName) {
    try {
        // Check if notification already exists in last 1 hour
        $checkStmt = $pdo->prepare("
            SELECT id FROM notifications
            WHERE user_id = ? 
            AND sender_id = ?
            AND type = 'student_commitment'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $checkStmt->execute([$coordinatorId, $studentId]);
        
        if ($checkStmt->fetch()) {
            return false; // Notification already exists
        }
        
        $title = 'Student Committed to Job';
        $message = $studentName . ' has committed to ' . $jobTitle . ' at ' . $companyName . '.';
        $link = 'intern.php';
        
        $insertStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, sender_id, type, title, message, link, is_read, created_at)
            VALUES (?, ?, 'student_commitment', ?, ?, ?, 0, NOW())
        ");
        return $insertStmt->execute([$coordinatorId, $studentId, $title, $message, $link]);
    } catch (PDOException $e) {
        error_log("Error creating student commitment notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for high sentiment discrepancy
 */
function createSentimentDiscrepancyNotification($pdo, $coordinatorId, $studentId, $studentName, $discrepancy) {
    try {
        // Check if notification already exists in last 7 days
        $checkStmt = $pdo->prepare("
            SELECT id FROM notifications
            WHERE user_id = ? 
            AND sender_id = ?
            AND type = 'sentiment_discrepancy'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $checkStmt->execute([$coordinatorId, $studentId]);
        
        if ($checkStmt->fetch()) {
            return false; // Notification already exists
        }
        
        $title = 'High Sentiment Discrepancy Alert';
        $message = $studentName . ' shows a high evaluative sentiment discrepancy (' . round($discrepancy, 1) . ' points). Review needed.';
        $link = 'evaluation.php';
        
        $insertStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, sender_id, type, title, message, link, is_read, created_at)
            VALUES (?, ?, 'sentiment_discrepancy', ?, ?, ?, 0, NOW())
        ");
        return $insertStmt->execute([$coordinatorId, $studentId, $title, $message, $link]);
    } catch (PDOException $e) {
        error_log("Error creating sentiment discrepancy notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for performance at risk
 */
function createPerformanceRiskNotification($pdo, $coordinatorId, $studentId, $studentName, $performanceScore) {
    try {
        // Check if notification already exists in last 7 days
        $checkStmt = $pdo->prepare("
            SELECT id FROM notifications
            WHERE user_id = ? 
            AND sender_id = ?
            AND type = 'performance_risk'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $checkStmt->execute([$coordinatorId, $studentId]);
        
        if ($checkStmt->fetch()) {
            return false; // Notification already exists
        }
        
        $title = 'Student Performance at Risk';
        $message = $studentName . ' has performance analytics at risk (Score: ' . round($performanceScore, 1) . '/100). Intervention recommended.';
        $link = 'dss.php';
        
        $insertStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, sender_id, type, title, message, link, is_read, created_at)
            VALUES (?, ?, 'performance_risk', ?, ?, ?, 0, NOW())
        ");
        return $insertStmt->execute([$coordinatorId, $studentId, $title, $message, $link]);
    } catch (PDOException $e) {
        error_log("Error creating performance risk notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Check and create all automatic notifications for coordinator
 * This should be called on dashboard load or via cron job
 */
function checkAndCreateCoordinatorNotifications($pdo, $coordinatorId) {
    // Check for new student commitments
    $commitments = getCoordinatorCommittedStudents($pdo);
    foreach ($commitments as $commitment) {
        $studentName = trim($commitment['firstname'] . ' ' . $commitment['lastname']);
        createStudentCommitmentNotification(
            $pdo,
            $coordinatorId,
            $commitment['student_id'],
            $studentName,
            $commitment['job_title'],
            $commitment['company_name']
        );
    }
    
    // Check for high sentiment discrepancy
    checkAndNotifyHighSentimentDiscrepancy($pdo, $coordinatorId);
    
    // Check for performance at risk
    checkAndNotifyPerformanceAtRisk($pdo, $coordinatorId);
    
    return true;
}

?>
