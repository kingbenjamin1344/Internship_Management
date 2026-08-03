<?php
// includes/admin_notifications.php
// Admin-specific notification functions

/**
 * Get admin notifications with sender details
 */
function getAdminNotifications($pdo, $adminId, $limit = 20, $offset = 0) {
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
                u.lastname,
                u.profile_picture,
                u.role
            FROM notifications n
            LEFT JOIN users u ON n.sender_id = u.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC, n.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $adminId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching admin notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Get count of unread admin notifications
 */
function getAdminUnreadNotificationCount($pdo, $adminId) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['cnt'] ?? 0);
    } catch (PDOException $e) {
        error_log("Error counting admin notifications: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark admin notification as read
 */
function markAdminNotificationRead($pdo, $notificationId, $adminId) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $adminId]);
    } catch (PDOException $e) {
        error_log("Error marking admin notification as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all admin notifications as read
 */
function markAdminAllNotificationsRead($pdo, $adminId) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        return $stmt->execute([$adminId]);
    } catch (PDOException $e) {
        error_log("Error marking all admin notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete admin notification
 */
function deleteAdminNotification($pdo, $notificationId, $adminId) {
    try {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $adminId]);
    } catch (PDOException $e) {
        error_log("Error deleting admin notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Format admin notification message
 */
function getAdminNotificationMessage($notification) {
    if (!empty($notification['message'])) {
        return $notification['message'];
    }
    if (!empty($notification['title'])) {
        return $notification['title'];
    }
    return 'New notification';
}

/**
 * Get notification source name
 */
function getAdminNotificationSource($notification) {
    if (!empty($notification['firstname']) && !empty($notification['lastname'])) {
        return trim($notification['firstname'] . ' ' . $notification['lastname']);
    }
    if (!empty($notification['role'])) {
        return ucfirst($notification['role']);
    }
    return 'System';
}

/**
 * Time ago helper function
 */
function timeAgo($datetime) {
    if (empty($datetime)) {
        return 'Unknown';
    }
    
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return 'Unknown';
    }
    
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $timestamp);
    }
}
?>
