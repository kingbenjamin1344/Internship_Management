<?php
// includes/student_notifications.php
// Student notification helper functions

/**
 * Get all notifications for a student
 * Shows ALL notifications for the student
 */
function getStudentNotifications($pdo, $studentId, $limit = 20, $offset = 0) {
    try {
        // Ensure limit and offset are integers for SQL injection prevention
        $limit = (int)$limit;
        $offset = (int)$offset;
        
        // First, check if profile_picture column exists in users table
        $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture'")->fetchAll();
        $hasProfilePicture = count($columns) > 0;
        
        // Build query based on column availability
        // Note: LIMIT and OFFSET must be integers in the SQL string, not bound parameters
        if ($hasProfilePicture) {
            $sql = "
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
                    u.lastname,
                    u.profile_picture
                FROM notifications n
                LEFT JOIN users u ON n.sender_id = u.id
                WHERE n.user_id = ?
                ORDER BY n.is_read ASC, n.created_at DESC, n.id DESC
                LIMIT {$limit} OFFSET {$offset}
            ";
        } else {
            $sql = "
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
                    u.lastname,
                    NULL as profile_picture
                FROM notifications n
                LEFT JOIN users u ON n.sender_id = u.id
                WHERE n.user_id = ?
                ORDER BY n.is_read ASC, n.created_at DESC, n.id DESC
                LIMIT {$limit} OFFSET {$offset}
            ";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$studentId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: log what we found
        error_log("Student notifications query: found " . count($results) . " notifications for student_id=" . $studentId);
        
        return $results;
    } catch (PDOException $e) {
        error_log("Error fetching student notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Get unread notification count for student
 * Counts ALL unread notifications
 */
function getStudentUnreadNotificationCount($pdo, $studentId) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM notifications 
            WHERE user_id = ? 
            AND is_read = 0
        ");
        $stmt->execute([$studentId]);
        $count = (int)$stmt->fetchColumn();
        
        // Debug: log the count
        error_log("Student unread count: found " . $count . " unread notifications for student_id=" . $studentId);
        
        return $count;
    } catch (PDOException $e) {
        error_log("Error counting unread student notifications: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark a notification as read for student
 */
function markStudentNotificationRead($pdo, $notificationId, $studentId) {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([$notificationId, $studentId]);
    } catch (PDOException $e) {
        error_log("Error marking student notification as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read for student
 */
function markStudentAllNotificationsRead($pdo, $studentId) {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        return $stmt->execute([$studentId]);
    } catch (PDOException $e) {
        error_log("Error marking all student notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete a notification for a student
 */
function deleteStudentNotification($pdo, $notificationId, $studentId) {
    try {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $studentId]);
    } catch (PDOException $e) {
        error_log("Error deleting student notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get notification message text
 */
function getStudentNotificationMessage($notification) {
    if (!empty($notification['message'])) {
        return $notification['message'];
    }
    return $notification['title'] ?? 'Notification';
}

/**
 * Get notification source (sender name or system)
 */
function getStudentNotificationSource($notification) {
    if (!empty($notification['firstname']) && !empty($notification['lastname'])) {
        return trim($notification['firstname'] . ' ' . $notification['lastname']);
    }
    return 'System';
}

/**
 * Time ago helper function
 */
function timeAgo($dateString) {
    if (empty($dateString)) {
        return '';
    }
    
    try {
        $date = new DateTime($dateString);
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
?>
