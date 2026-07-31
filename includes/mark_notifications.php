<?php
// includes/mark_notifications.php
// AJAX endpoint for marking notifications as read

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$userId = $_SESSION['user_id'];

// Handle AJAX POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit;
}

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_read' && isset($_POST['notification_id'])) {
        // Mark single notification as read
        $notificationId = (int)$_POST['notification_id'];
        
        // Verify ownership before updating
        $verifyStmt = $pdo->prepare("
            SELECT id FROM notifications 
            WHERE id = ? AND user_id = ?
        ");
        $verifyStmt->execute([$notificationId, $userId]);
        
        if (!$verifyStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Notification not found']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = ? AND user_id = ?
        ");
        $success = $stmt->execute([$notificationId, $userId]);
        
        // Get updated unread count
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $countStmt->execute([$userId]);
        $result = $countStmt->fetch();
        $unreadCount = $result['count'] ?? 0;
        
        echo json_encode([
            'success' => $success,
            'unread_count' => $unreadCount
        ]);
        
    } elseif ($action === 'mark_all_read') {
        // Mark all notifications as read for this user
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        $success = $stmt->execute([$userId]);
        
        // Verify the count is actually 0 after update
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $countStmt->execute([$userId]);
        $result = $countStmt->fetch();
        $actualUnreadCount = $result['count'] ?? 0;
        
        echo json_encode([
            'success' => $success,
            'unread_count' => $actualUnreadCount
        ]);
        
    } elseif ($action === 'get_notifications') {
        // Get notifications for this user (ONLY accept/reject notifications)
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        
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
                u.profile_picture
            FROM notifications n
            LEFT JOIN users u ON n.sender_id = u.id
            WHERE n.user_id = ?
            AND n.type IN ('application_accepted', 'application_rejected')
            ORDER BY n.is_read ASC, n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$userId, $limit, $offset]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get unread count (ONLY accept/reject)
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? 
            AND is_read = 0
            AND type IN ('application_accepted', 'application_rejected')
        ");
        $countStmt->execute([$userId]);
        $result = $countStmt->fetch();
        $unreadCount = $result['count'] ?? 0;
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ]);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    error_log("Mark notifications error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}
?>
