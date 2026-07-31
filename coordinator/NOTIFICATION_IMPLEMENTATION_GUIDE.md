# Coordinator Notification System Implementation Guide

## Overview
This guide explains how to implement the working notification bell system across all coordinator pages.

## Files Already Created
1. `includes/mark_notifications.php` - AJAX endpoint for notification operations
2. `includes/coordinator_notifications.php` - Functions for creating notifications
3. `coordinator/notification_component.php` - Reusable notification HTML/JS
4. `assets/styles.css` - Notification dropdown styles (already added)

## Implementation Steps for Each Coordinator Page

### Step 1: Add notification initialization at the top of PHP section

After the existing code that gets `$userId`, add:

```php
// Initialize notifications and check for new ones
require_once __DIR__ . '/../includes/coordinator_notifications.php';
checkAndCreateCoordinatorNotifications($pdo, $userId);
$unreadCount = getCoordinatorUnreadNotificationCount($pdo, $userId);
$notifications = getCoordinatorNotifications($pdo, $userId, 10, 0);
```

### Step 2: Replace the static notification bell HTML

Find this code:
```html
<button class="notif-bell" onclick="alert('No new notifications')" aria-label="Notifications">
    <i class="fa-regular fa-bell"></i>
    <span class="notif-badge">3</span>
</button>
```

Replace with:
```php
<?php 
require_once __DIR__ . '/notification_component.php';
renderNotificationBell($unreadCount, $notifications);
?>
```

### Step 3: Add notification JavaScript before closing </body> tag

Before the closing `</body>` tag, add:
```php
<?php renderNotificationScript(); ?>
```

### Step 4: Handle AJAX requests (add after existing POST handlers)

Add this code after authentication check but before the main HTML:

```php
// Handle AJAX requests for notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if (in_array($action, ['get_notifications', 'mark_read', 'mark_all_read'])) {
        header('Content-Type: application/json');
        
        if ($action === 'get_notifications') {
            $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
            $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
            $notifications = getCoordinatorNotifications($pdo, $userId, $limit, $offset);
            $unreadCount = getCoordinatorUnreadNotificationCount($pdo, $userId);
            echo json_encode(['success' => true, 'notifications' => $notifications, 'unread_count' => $unreadCount]);
            exit;
        }
        
        if ($action === 'mark_read') {
            $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
            if ($notification_id > 0) {
                $result = markCoordinatorNotificationRead($pdo, $notification_id, $userId);
                $unreadCount = getCoordinatorUnreadNotificationCount($pdo, $userId);
                echo json_encode(['success' => $result, 'unread_count' => $unreadCount]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
            }
            exit;
        }
        
        if ($action === 'mark_all_read') {
            $result = markCoordinatorAllNotificationsRead($pdo, $userId);
            echo json_encode(['success' => $result, 'unread_count' => 0]);
            exit;
        }
    }
}
```

## Complete Example for dashboard.php

Here's what the key sections should look like:

```php
<?php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/coordinator_notifications.php';

checkAccess('coordinator');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Coordinator';
$role = getUserRole();
$userId = getUserId();

// Initialize notifications
checkAndCreateCoordinatorNotifications($pdo, $userId);
$unreadCount = getCoordinatorUnreadNotificationCount($pdo, $userId);
$notifications = getCoordinatorNotifications($pdo, $userId, 10, 0);

// Handle AJAX for notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if (in_array($action, ['get_notifications', 'mark_read', 'mark_all_read'])) {
        header('Content-Type: application/json');
        // ... (handler code from Step 4)
        exit;
    }
    
    // ... other POST handlers ...
}

// Rest of the page logic...
?>
```

In the HTML header section where notification bell appears:
```php
<?php 
require_once __DIR__ . '/notification_component.php';
renderNotificationBell($unreadCount, $notifications);
?>
```

Before closing </body>:
```php
<?php renderNotificationScript(); ?>
</body>
```

## Notification Types

The system automatically creates notifications for:

1. **Student Commitment** (`type: 'student_commitment'`)
   - Triggered when a student commits to a job
   - Link: `intern.php`

2. **High Sentiment Discrepancy** (`type: 'sentiment_discrepancy'`)
   - Triggered when evaluation score doesn't match feedback sentiment
   - Link: `evaluation.php`
   - Created once per week per student

3. **Performance at Risk** (`type: 'performance_risk'`)
   - Triggered when student performance score < 50
   - Link: `dss.php`
   - Created once per week per student

## Testing

1. Create a test notification by committing a student to a job
2. Check that the red badge appears on the notification bell
3. Click the bell - dropdown should appear
4. Latest notifications should be at top
5. Unread notifications should have blue highlight
6. Click a notification - it should:
   - Remove the blue highlight
   - Decrease the badge count
   - Navigate to the linked page
7. Click "Mark all as read" - all notifications should lose highlight and badge should disappear

## Files to Modify

Apply the changes above to these coordinator files:
- [ ] coordinator/dashboard.php
- [ ] coordinator/company.php
- [ ] coordinator/intern.php
- [ ] coordinator/evaluation.php
- [ ] coordinator/dss.php

## Notes

- The notification dropdown styles are already in `assets/styles.css`
- Notifications auto-refresh every 30 seconds
- Clicking outside the dropdown closes it
- ESC key also closes the dropdown
- The system prevents duplicate notifications (checks for existing notifications before creating new ones)
