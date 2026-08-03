<?php
// Shared notification helpers for all supervisor pages

function getSupervisorUnreadNotificationCount($pdo, $supervisor_id) {
    if (empty($supervisor_id)) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$supervisor_id]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Supervisor notification count error: " . $e->getMessage());
        return 0;
    }
}

function getSupervisorNotifications($pdo, $supervisor_id, $limit = 20, $offset = 0) {
    if (empty($supervisor_id)) {
        return [];
    }

    try {
        $limit = max(1, (int)$limit);
        $offset = max(0, (int)$offset);
        $stmt = $pdo->prepare(
            "SELECT n.*, u.firstname, u.lastname, u.profile_picture FROM notifications n LEFT JOIN users u ON n.sender_id = u.id WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$supervisor_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Supervisor get notifications error: " . $e->getMessage());
        return [];
    }
}

function markSupervisorNotificationRead($pdo, $notification_id, $supervisor_id) {
    if (empty($supervisor_id) || empty($notification_id)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notification_id, $supervisor_id]);
    } catch (PDOException $e) {
        error_log("Supervisor mark notification read error: " . $e->getMessage());
        return false;
    }
}

function markSupervisorAllNotificationsRead($pdo, $supervisor_id) {
    if (empty($supervisor_id)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        return $stmt->execute([$supervisor_id]);
    } catch (PDOException $e) {
        error_log("Supervisor mark all notifications read error: " . $e->getMessage());
        return false;
    }
}

function deleteSupervisorNotification($pdo, $notification_id, $supervisor_id) {
    if (empty($supervisor_id) || empty($notification_id)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notification_id, $supervisor_id]);
    } catch (PDOException $e) {
        error_log("Supervisor delete notification error: " . $e->getMessage());
        return false;
    }
}

function getSupervisorNotificationSource($notif) {
    $source = trim((($notif['firstname'] ?? '') . ' ' . ($notif['lastname'] ?? '')));
    return $source !== '' ? $source : 'System';
}

function getSupervisorNotificationMessage($notif) {
    $message = trim((string)($notif['message'] ?? ''));
    if ($message !== '') {
        return $message;
    }

    $title = trim((string)($notif['title'] ?? ''));
    return $title !== '' ? $title : 'New notification';
}

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
