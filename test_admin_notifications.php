<?php
// Test file to check admin notifications
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_notifications.php';

echo "<h1>Admin Notification Debug</h1>";

// Get all admin users
$stmt = $pdo->query("SELECT id, username, role, status FROM users WHERE role = 'admin'");
$admins = $stmt->fetchAll();

echo "<h2>Admin Users:</h2>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Status</th></tr>";
foreach ($admins as $admin) {
    echo "<tr>";
    echo "<td>{$admin['id']}</td>";
    echo "<td>{$admin['username']}</td>";
    echo "<td>{$admin['role']}</td>";
    echo "<td>{$admin['status']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check notifications for each admin
foreach ($admins as $admin) {
    echo "<h3>Notifications for {$admin['username']} (ID: {$admin['id']}):</h3>";
    
    $notifications = getAdminNotifications($pdo, $admin['id'], 50, 0);
    $unreadCount = getAdminUnreadNotificationCount($pdo, $admin['id']);
    
    echo "<p><strong>Unread count:</strong> {$unreadCount}</p>";
    echo "<p><strong>Total notifications:</strong> " . count($notifications) . "</p>";
    
    if (!empty($notifications)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Type</th><th>Title</th><th>Message</th><th>Sender</th><th>Read</th><th>Created</th></tr>";
        foreach ($notifications as $notif) {
            $sender = !empty($notif['firstname']) ? $notif['firstname'] . ' ' . $notif['lastname'] : 'System';
            $isRead = $notif['is_read'] ? 'Yes' : 'No';
            echo "<tr>";
            echo "<td>{$notif['id']}</td>";
            echo "<td>{$notif['type']}</td>";
            echo "<td>{$notif['title']}</td>";
            echo "<td>{$notif['message']}</td>";
            echo "<td>{$sender}</td>";
            echo "<td>{$isRead}</td>";
            echo "<td>{$notif['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>No notifications found for this admin.</p>";
    }
    
    echo "<hr>";
}

// Check all notifications in database
echo "<h2>All Notifications in Database (Last 20):</h2>";
$allNotifs = $pdo->query("
    SELECT 
        n.*, 
        u.username as sender_username,
        recipient.username as recipient_username,
        recipient.role as recipient_role
    FROM notifications n 
    LEFT JOIN users u ON n.sender_id = u.id 
    LEFT JOIN users recipient ON n.user_id = recipient.id
    ORDER BY n.created_at DESC 
    LIMIT 20
")->fetchAll();

if (!empty($allNotifs)) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>User ID</th><th>Recipient</th><th>Type</th><th>Title</th><th>Message</th><th>Sender</th><th>Read</th><th>Created</th></tr>";
    foreach ($allNotifs as $notif) {
        $isRead = $notif['is_read'] ? 'Yes' : 'No';
        echo "<tr>";
        echo "<td>{$notif['id']}</td>";
        echo "<td>{$notif['user_id']}</td>";
        echo "<td>{$notif['recipient_username']} ({$notif['recipient_role']})</td>";
        echo "<td>{$notif['type']}</td>";
        echo "<td>{$notif['title']}</td>";
        echo "<td>{$notif['message']}</td>";
        echo "<td>{$notif['sender_username']}</td>";
        echo "<td>{$isRead}</td>";
        echo "<td>{$notif['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>No notifications found in database at all!</p>";
}

// Test creating a notification
echo "<h2>Test Create Notification:</h2>";
if (count($admins) > 0) {
    $testAdminId = $admins[0]['id'];
    echo "<p>Attempting to create a test notification for admin ID: {$testAdminId}</p>";
    
    require_once __DIR__ . '/includes/notifications.php';
    $result = createSystemNotification(
        $pdo,
        $testAdminId,
        1, // sender ID
        'test',
        'Test Notification',
        'This is a test notification created by the debug script.',
        'dashboard.php'
    );
    
    if ($result) {
        echo "<p style='color: green;'>✓ Test notification created successfully!</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to create test notification.</p>";
    }
} else {
    echo "<p style='color: orange;'>No admin users found to test with.</p>";
}
?>
