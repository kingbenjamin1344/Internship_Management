<?php
/**
 * Identify currently logged-in student and create test notification
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications.php';

echo "=================================================================\n";
echo "  IDENTIFY CURRENT STUDENT & CREATE TEST NOTIFICATION\n";
echo "=================================================================\n\n";

if (!isset($_SESSION['user_id'])) {
    echo "❌ NO USER LOGGED IN\n\n";
    echo "Please log in first, then run this script.\n\n";
    
    // List all students
    echo "📋 Available students in database:\n";
    echo str_repeat("-", 80) . "\n";
    
    $stmt = $pdo->query("SELECT id, username, firstname, lastname, email FROM users WHERE role = 'student' ORDER BY id");
    $students = $stmt->fetchAll();
    
    foreach ($students as $student) {
        $fullName = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
        echo "ID: {$student['id']} | Username: {$student['username']} | Name: {$fullName}\n";
    }
    
    echo "\n💡 Log in as one of these students, then run this script again.\n";
    exit;
}

$studentId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Unknown';
$role = $_SESSION['role'] ?? 'Unknown';

echo "✅ USER LOGGED IN\n";
echo "  Username: {$username}\n";
echo "  User ID: {$studentId}\n";
echo "  Role: {$role}\n\n";

if ($role !== 'student') {
    echo "⚠️  WARNING: You're not logged in as a student!\n";
    echo "  Current role: {$role}\n";
    echo "  This tool is designed for students only.\n\n";
    exit;
}

// Get user details
$stmt = $pdo->prepare("SELECT firstname, lastname, email FROM users WHERE id = ?");
$stmt->execute([$studentId]);
$user = $stmt->fetch();

echo "📋 FULL DETAILS:\n";
echo "  Name: {$user['firstname']} {$user['lastname']}\n";
echo "  Email: {$user['email']}\n\n";

// Check existing notifications
echo "📊 CURRENT NOTIFICATIONS:\n";
$stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread FROM notifications WHERE user_id = ?");
$stmt->execute([$studentId]);
$stats = $stmt->fetch();

echo "  Total: {$stats['total']}\n";
echo "  Unread: {$stats['unread']}\n\n";

// Create a new test notification
echo "🎯 CREATING NEW TEST NOTIFICATION...\n";

// Get a supervisor as sender
$supervisorStmt = $pdo->query("SELECT id FROM users WHERE role = 'supervisor' LIMIT 1");
$supervisor = $supervisorStmt->fetch();
$senderId = $supervisor ? $supervisor['id'] : 1;

$success = createSystemNotification(
    $pdo,
    $studentId,
    $senderId,
    'application_accepted',
    'Test Notification - Application Accepted',
    'This is a test notification created at ' . date('Y-m-d H:i:s') . '. Your application for "Software Developer Intern" has been accepted!',
    'applications.php'
);

if ($success) {
    echo "✅ Test notification created successfully!\n\n";
    
    // Show updated stats
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread FROM notifications WHERE user_id = ?");
    $stmt->execute([$studentId]);
    $newStats = $stmt->fetch();
    
    echo "📊 UPDATED STATS:\n";
    echo "  Total: {$newStats['total']}\n";
    echo "  Unread: {$newStats['unread']}\n\n";
    
    echo "🎉 SUCCESS!\n\n";
    echo "📍 NEXT STEPS:\n";
    echo "  1. Refresh your student page (Ctrl+F5)\n";
    echo "  2. Look at the notification bell in the top-right corner\n";
    echo "  3. You should see a red badge with '{$newStats['unread']}'\n";
    echo "  4. Click the bell to see your notification\n\n";
    
    // Show recent notifications
    echo "📬 RECENT NOTIFICATIONS:\n";
    echo str_repeat("-", 80) . "\n";
    
    $stmt = $pdo->prepare("SELECT id, type, title, LEFT(message, 60) as message_short, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$studentId]);
    $notifications = $stmt->fetchAll();
    
    foreach ($notifications as $notif) {
        $status = $notif['is_read'] ? '[READ]' : '[UNREAD]';
        echo "{$status} {$notif['title']}\n";
        echo "  Message: {$notif['message_short']}...\n";
        echo "  Created: {$notif['created_at']}\n\n";
    }
    
} else {
    echo "❌ Failed to create notification\n";
    echo "Check the error logs for details.\n\n";
}

echo "=================================================================\n";
?>
