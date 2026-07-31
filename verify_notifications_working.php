<?php
/**
 * Verify notification system is working after profile_picture fix
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/student_notifications.php';

$studentId = 25; // rene.jeans

echo "=================================================================\n";
echo "  VERIFYING NOTIFICATION SYSTEM\n";
echo "=================================================================\n\n";

echo "Testing for student ID: {$studentId}\n\n";

// Test 1: Get notifications
echo "TEST 1: Fetching notifications...\n";
$notifications = getStudentNotifications($pdo, $studentId, 10, 0);
$unreadCount = getStudentUnreadNotificationCount($pdo, $studentId);

echo "  Notifications found: " . count($notifications) . "\n";
echo "  Unread count: {$unreadCount}\n";

if (count($notifications) > 0) {
    echo "  ✅ PASS - Notifications retrieved successfully!\n\n";
    
    echo "  Sample notification:\n";
    $first = $notifications[0];
    echo "    Title: {$first['title']}\n";
    echo "    Message: " . substr($first['message'], 0, 50) . "...\n";
    echo "    From: {$first['firstname']} {$first['lastname']}\n";
    echo "    Profile Picture: " . ($first['profile_picture'] ?? 'NULL') . "\n";
    echo "    Is Read: {$first['is_read']}\n";
} else {
    echo "  ❌ FAIL - No notifications found!\n";
}

echo "\n";

// Test 2: JSON encoding (for AJAX)
echo "TEST 2: JSON encoding...\n";
$json = json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unreadCount
]);

if ($json !== false) {
    echo "  ✅ PASS - JSON encoding successful!\n";
    echo "  JSON length: " . strlen($json) . " bytes\n";
} else {
    echo "  ❌ FAIL - JSON encoding failed!\n";
}

echo "\n";

// Test 3: Check profile_picture column
echo "TEST 3: Check profile_picture column...\n";
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
$column = $stmt->fetch();

if ($column) {
    echo "  ✅ PASS - Column exists!\n";
    echo "    Type: {$column['Type']}\n";
    echo "    Nullable: {$column['Null']}\n";
} else {
    echo "  ❌ FAIL - Column does not exist!\n";
}

echo "\n=================================================================\n";

if (count($notifications) > 0 && $json !== false && $column) {
    echo "✅ ALL TESTS PASSED!\n";
    echo "\nThe notification system is working correctly.\n";
    echo "You should now:\n";
    echo "  1. Log in as student (username: rene.jeans)\n";
    echo "  2. Go to any student page\n";
    echo "  3. Look for the bell icon with badge\n";
    echo "  4. Click it to see {$unreadCount} unread notification(s)\n";
} else {
    echo "❌ SOME TESTS FAILED!\n";
    echo "Please check the errors above.\n";
}

echo "=================================================================\n\n";
?>
