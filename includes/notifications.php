<?php
// includes/notifications.php
// Generic notification handlers - works across all user roles

if (!function_exists('createSystemNotification')) {
    /**
     * Create a system notification with duplicate prevention
     */
    function createSystemNotification($pdo, $userId, $senderId, $type, $title, $message, $link = null) {
        if (empty($userId)) {
            return false;
        }

        try {
            // Check for duplicate notification within the last 5 minutes
            // This prevents duplicate notifications from being created for the same event
            $checkStmt = $pdo->prepare("
                SELECT id FROM notifications 
                WHERE user_id = ? 
                AND sender_id = ? 
                AND type = ? 
                AND title = ? 
                AND message = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                LIMIT 1
            ");
            $checkStmt->execute([$userId, $senderId, $type, $title, $message]);
            
            // If a duplicate exists, don't create another one
            if ($checkStmt->fetch()) {
                return true; // Return true to indicate notification already exists
            }

            // No duplicate found, proceed with insertion
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, sender_id, type, title, message, link, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
            ");
            return $stmt->execute([$userId, $senderId, $type, $title, $message, $link]);
        } catch (PDOException $e) {
            error_log("Error creating notification: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notifyCoordinatorStudentCommitment')) {
    /**
     * Create notification when student commits to a job
     */
    function notifyCoordinatorStudentCommitment($pdo, $studentId, $jobId, $coordinatorId = null) {
        try {
            // Get student and job details
            $stmt = $pdo->prepare("
                SELECT 
                    s.firstname, s.lastname, 
                    j.title, c.company_name
                FROM users s
                INNER JOIN jobs j ON j.id = ?
                INNER JOIN companies c ON j.company_id = c.id
                WHERE s.id = ?
            ");
            $stmt->execute([$jobId, $studentId]);
            $data = $stmt->fetch();
            
            if (!$data) return false;
            
            $studentName = trim($data['firstname'] . ' ' . $data['lastname']);
            $message = $studentName . ' has committed to ' . $data['title'] . ' at ' . $data['company_name'];
            
            // If no specific coordinator, notify all coordinators
            if ($coordinatorId) {
                return createSystemNotification($pdo, $coordinatorId, $studentId, 'student_commitment', 'Student Committed to Job', $message, 'intern.php');
            } else {
                $coordinators = $pdo->prepare("SELECT id FROM users WHERE role = 'coordinator' AND status = 'active'");
                $coordinators->execute();
                $success = true;
                while ($coord = $coordinators->fetch()) {
                    $result = createSystemNotification($pdo, $coord['id'], $studentId, 'student_commitment', 'Student Committed to Job', $message, 'intern.php');
                    $success = $success && $result;
                }
                return $success;
            }
        } catch (PDOException $e) {
            error_log("Error notifying student commitment: " . $e->getMessage());
            return false;
        }
    }
}