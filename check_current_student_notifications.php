<?php
/**
 * Check notifications for currently logged-in student
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/student_notifications.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    die("❌ You must be logged in as a student to use this tool.\n\n");
}

$studentId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Unknown';

echo "=================================================================\n";
echo "  CHECKING NOTIFICATIONS FOR CURRENT STUDENT\n";
echo "=================================================================\n\n";

echo "👤 Logged in as: {$username}\n";
echo "🆔 Student ID: {$studentId}\n\n";

// Get notifications using the same function the pages use
$notifications = getStudentNotifications($pdo, $studentId, 20, 0);
$unreadCount = getStudentUnreadNotificationCount($pdo, $studentId);

echo "📊 STATS:\n";
echo "  Total notifications: " . count($notifications) . "\n";
echo "  Unread notifications: {$unreadCount}\n\n";

if (count($notifications) > 0) {
    echo "📬 NOTIFICATIONS:\n";
    echo str_repeat("-", 80) . "\n";
    
    foreach ($notifications as $notif) {
        $status = $notif['is_read'] ? '[READ]' : '[UNREAD]';
        echo "\n{$status} {$notif['title']}\n";
        echo "  ID: {$notif['id']}\n";
        echo "  Type: {$notif['type']}\n";
        echo "  Message: {$notif['message']}\n";
        echo "  Link: {$notif['link']}\n";
        echo "  Sender: {$notif['firstname']} {$notif['lastname']}\n";
        echo "  Created: {$notif['created_at']}\n";
    }
    
    echo "\n" . str_repeat("-", 80) . "\n";
} else {
    echo "⚠️  NO NOTIFICATIONS FOUND!\n";
    echo "\nThis means:\n";
    echo "  1. No notifications exist for student ID {$studentId}\n";
    echo "  2. Or there's an issue with the getStudentNotifications() function\n\n";
    
    // Direct database query to verify
    echo "🔍 DIRECT DATABASE CHECK:\n";
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$studentId]);
    $directResults = $stmt->fetchAll();
    
    if (count($directResults) > 0) {
        echo "  ✅ Found " . count($directResults) . " notifications in database!\n";
        echo "  ❌ But getStudentNotifications() returned empty array\n";
        echo "  → There's a bug in the function!\n\n";
        
        foreach ($directResults as $notif) {
            echo "  - [{$notif['id']}] {$notif['title']}\n";
        }
    } else {
        echo "  ❌ No notifications in database for this student\n";
        echo "  → Create notifications for student ID {$studentId}\n";
    }
}

echo "\n=================================================================\n";
echo "💡 TIP: If you see notifications in database but function returns empty,\n";
echo "        check includes/student_notifications.php for issues.\n";
echo "=================================================================\n\n";
?>
