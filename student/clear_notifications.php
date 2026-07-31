<?php
// student/clear_notifications.php
// Quick page to clear all notifications
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';

checkAccess('student');

$studentId = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all_read'])) {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$studentId]);
            $message = '<div style="background: #dcfce7; color: #166534; padding: 12px; margin-bottom: 16px; border: 1px solid #86efac;">All notifications marked as read!</div>';
        } catch (PDOException $e) {
            $message = '<div style="background: #fee2e2; color: #991b1b; padding: 12px; margin-bottom: 16px; border: 1px solid #fca5a5;">Error: ' . $e->getMessage() . '</div>';
        }
    } elseif (isset($_POST['delete_all'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$studentId]);
            $message = '<div style="background: #dcfce7; color: #166534; padding: 12px; margin-bottom: 16px; border: 1px solid #86efac;">All notifications deleted!</div>';
        } catch (PDOException $e) {
            $message = '<div style="background: #fee2e2; color: #991b1b; padding: 12px; margin-bottom: 16px; border: 1px solid #fca5a5;">Error: ' . $e->getMessage() . '</div>';
        }
    }
}

// Get current notifications
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$studentId]);
    $unreadCount = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
    $stmt->execute([$studentId]);
    $totalCount = $stmt->fetchColumn();
} catch (PDOException $e) {
    $unreadCount = 0;
    $totalCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Notifications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f5;
            padding: 40px 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border: 1px solid #e2e8f0;
            padding: 32px;
        }
        h1 {
            font-size: 1.5rem;
            color: #0f172a;
            margin-bottom: 8px;
        }
        p {
            color: #64748b;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .stats {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 16px;
            margin-bottom: 24px;
        }
        .stats .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .stats .stat-row:last-child {
            border-bottom: none;
        }
        .stats .stat-label {
            color: #64748b;
            font-weight: 500;
        }
        .stats .stat-value {
            color: #0f172a;
            font-weight: 700;
        }
        .stats .stat-value.unread {
            color: #dc2626;
        }
        form {
            margin-bottom: 16px;
        }
        button {
            width: 100%;
            padding: 12px 24px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid;
            font-family: 'Inter', sans-serif;
        }
        .btn-primary {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }
        .btn-primary:hover {
            background: #1d4ed8;
        }
        .btn-danger {
            background: #dc2626;
            color: white;
            border-color: #dc2626;
        }
        .btn-danger:hover {
            background: #b91c1c;
        }
        .btn-secondary {
            background: #f1f5f9;
            color: #1e293b;
            border-color: #e2e8f0;
            margin-top: 24px;
        }
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        a {
            text-decoration: none;
            color: inherit;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fa-solid fa-bell"></i> Clear Notifications</h1>
        <p>Use this page to clear your notification badge if it's showing incorrect counts.</p>
        
        <?php echo $message; ?>
        
        <div class="stats">
            <div class="stat-row">
                <span class="stat-label">Total Notifications:</span>
                <span class="stat-value"><?php echo $totalCount; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Unread Notifications:</span>
                <span class="stat-value unread"><?php echo $unreadCount; ?></span>
            </div>
        </div>
        
        <form method="POST" style="margin-bottom: 12px;">
            <button type="submit" name="mark_all_read" class="btn-primary">
                <i class="fa-solid fa-check"></i> Mark All as Read
            </button>
        </form>
        
        <form method="POST" onsubmit="return confirm('Are you sure you want to delete all notifications? This cannot be undone.');">
            <button type="submit" name="delete_all" class="btn-danger">
                <i class="fa-solid fa-trash"></i> Delete All Notifications
            </button>
        </form>
        
        <a href="dashboard.php">
            <button type="button" class="btn-secondary">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </button>
        </a>
    </div>
</body>
</html>
