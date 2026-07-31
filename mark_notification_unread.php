<?php
// mark_notification_unread.php
// Quick script to mark notification as unread for testing

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    die('You must be logged in as a student to use this script.');
}

$studentId = $_SESSION['user_id'];

try {
    // Mark all your notifications as unread
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 0 WHERE user_id = ?");
    $stmt->execute([$studentId]);
    
    $affected = $stmt->rowCount();
    
    echo "<!DOCTYPE html><html><head><title>Mark Unread</title>";
    echo "<style>body { font-family: sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; }";
    echo ".success { background: #d4edda; padding: 20px; border: 1px solid #c3e6cb; border-radius: 4px; }";
    echo "a { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }</style>";
    echo "</head><body>";
    echo "<div class='success'>";
    echo "<h2>✅ Success!</h2>";
    echo "<p>Marked <strong>" . $affected . "</strong> notification(s) as unread for student ID " . $studentId . "</p>";
    echo "</div>";
    echo "<a href='student/dashboard.php'>Go to Dashboard</a>";
    echo "<p style='margin-top: 20px; color: #666;'>Now click the notification bell to see your notification!</p>";
    echo "</body></html>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
