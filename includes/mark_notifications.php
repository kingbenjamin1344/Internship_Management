<?php
// includes/mark_notifications.php
// AJAX endpoint for marking notifications as read
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = getUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

if (isset($_POST['mark_all']) && $_POST['mark_all'] == '1') {
    markAllNotificationsRead($pdo, $userId);
    echo json_encode(['success' => true, 'action' => 'mark_all']);
    exit;
}

if (isset($_POST['notification_id'])) {
    $notifId = (int)$_POST['notification_id'];
    markNotificationRead($pdo, $notifId, $userId);
    echo json_encode(['success' => true, 'action' => 'mark_one', 'id' => $notifId]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'No action specified']);
?>
