<?php
/**
 * Quick Notification Test
 * Run this script to quickly create a test notification for a student
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications.php';

// Get the first student from the database
$stmt = $pdo->query("SELECT id, username, firstname, lastname FROM users WHERE role = 'student' LIMIT 1");
$student = $stmt->fetch();

if (!$student) {
    die("❌ No student found in database. Please create a student account first.");
}

// Get a supervisor (or use admin as fallback)
$supervisorStmt = $pdo->query("SELECT id FROM users WHERE role = 'supervisor' LIMIT 1");
$supervisor = $supervisorStmt->fetch();
$senderId = $supervisor ? $supervisor['id'] : 1;

// Create an "Application Accepted" notification
$success = createSystemNotification(
    $pdo,
    $student['id'],                           // Student ID
    $senderId,                                // Sender ID (supervisor or admin)
    'application_accepted',                   // Type
    'Application Accepted',                   // Title
    'Congratulations! Your application for "Software Developer Intern" has been accepted by the supervisor.',  // Message
    'applications.php'                        // Link
);

if ($success) {
    echo "✅ SUCCESS!\n\n";
    echo "Test notification created for:\n";
    echo "  Student: {$student['firstname']} {$student['lastname']} ({$student['username']})\n";
    echo "  Student ID: {$student['id']}\n\n";
    echo "📍 NEXT STEPS:\n";
    echo "  1. Log in as this student: {$student['username']}\n";
    echo "  2. Go to any student page (dashboard.php, applications.php, etc.)\n";
    echo "  3. Look for the 🔔 bell icon in the top-right corner\n";
    echo "  4. You should see a red badge with '1' on it\n";
    echo "  5. Click the bell to see your notification!\n\n";
    
    // Check current notification count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $countStmt->execute([$student['id']]);
    $unreadCount = $countStmt->fetchColumn();
    
    echo "📊 Current Stats:\n";
    echo "  Unread notifications for this student: {$unreadCount}\n\n";
    
    echo "🌐 Student Pages URLs:\n";
    echo "  Dashboard: http://localhost/internship-rbac/student/dashboard.php\n";
    echo "  Applications: http://localhost/internship-rbac/student/applications.php\n";
    echo "  Apply: http://localhost/internship-rbac/student/apply.php\n";
    echo "  DPR: http://localhost/internship-rbac/student/dpr.php\n\n";
    
} else {
    echo "❌ FAILED to create notification.\n";
    echo "Check your database connection and ensure the notifications table exists.\n";
}

// Display recent notifications for this student
echo "📋 Recent notifications for {$student['username']}:\n";
echo str_repeat("-", 80) . "\n";

$notifStmt = $pdo->prepare("
    SELECT id, type, title, message, is_read, created_at 
    FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$notifStmt->execute([$student['id']]);
$notifications = $notifStmt->fetchAll();

if (count($notifications) > 0) {
    foreach ($notifications as $notif) {
        $status = $notif['is_read'] ? '[READ]' : '[UNREAD]';
        echo "\n{$status} {$notif['title']}\n";
        echo "  Type: {$notif['type']}\n";
        echo "  Message: {$notif['message']}\n";
        echo "  Created: {$notif['created_at']}\n";
    }
} else {
    echo "No notifications found.\n";
}

echo "\n" . str_repeat("-", 80) . "\n";
echo "\n💡 TIP: Run this script again to create another test notification!\n";
echo "💡 TIP: Visit test_notification_system.php for a full-featured web interface.\n\n";
?>
