<?php
// includes/admin_notification_handler.php
// Shared AJAX handler for admin notification requests

if (!function_exists('handleAdminNotificationRequests')) {
    function handleAdminNotificationRequests($pdo, $userId) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            // Only handle notification-specific actions
            if (!in_array($_POST['action'], ['get_notifications', 'mark_read', 'mark_all_read', 'delete'])) {
                return false; // Not a notification request
            }
            
            header('Content-Type: application/json');
            $action = $_POST['action'];
            
            if ($action === 'get_notifications') {
                $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
                $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
                
                error_log("Admin notification request - User ID: $userId, Limit: $limit, Offset: $offset");
                
                $notifs = getAdminNotifications($pdo, $userId, $limit, $offset);
                $count = getAdminUnreadNotificationCount($pdo, $userId);
                
                error_log("Admin notifications fetched - Count: " . count($notifs) . ", Unread: $count");
                
                echo json_encode(['success' => true, 'notifications' => $notifs, 'unread_count' => $count]);
                exit;
            }
            
            if ($action === 'mark_read') {
                $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
                if ($notification_id > 0) {
                    $result = markAdminNotificationRead($pdo, $notification_id, $userId);
                    $count = getAdminUnreadNotificationCount($pdo, $userId);
                    echo json_encode(['success' => $result, 'unread_count' => $count]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
                }
                exit;
            }
            
            if ($action === 'mark_all_read') {
                $result = markAdminAllNotificationsRead($pdo, $userId);
                echo json_encode(['success' => $result, 'unread_count' => 0]);
                exit;
            }
            
            if ($action === 'delete') {
                $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
                if ($notification_id > 0) {
                    $result = deleteAdminNotification($pdo, $notification_id, $userId);
                    $count = getAdminUnreadNotificationCount($pdo, $userId);
                    echo json_encode(['success' => $result, 'unread_count' => $count]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
                }
                exit;
            }
            
            return true;
        }
        return false;
    }
}
?>
