<?php
// includes/notifications.php
// Shared notification system for students and supervisors

/**
 * Ensure the notifications table exists (auto-migration).
 */
function ensureNotificationsTable($pdo) {
    // Create the table with a broad VARCHAR type to avoid ALTER issues
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(64) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            related_application_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_user (user_id),
            INDEX idx_notif_user_read (user_id, is_read),
            INDEX idx_notif_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    // If the table already existed with an ENUM column, migrate it to VARCHAR
    try {
        $col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos($col['Type'], 'enum') !== false) {
            $pdo->exec("ALTER TABLE notifications MODIFY type VARCHAR(64) NOT NULL");
        }
    } catch (Exception $e) { /* ignore */ }
}

/**
 * Create a new notification.
 */
function createNotification($pdo, $userId, $type, $message, $link = null, $relatedAppId = null) {
    $stmt = $pdo->prepare('
        INSERT INTO notifications (user_id, type, message, link, related_application_id)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $type, $message, $link, $relatedAppId]);
}

/**
 * Get all unread notifications for a user (most recent first, max 20).
 */
function getUnreadNotifications($pdo, $userId) {
    $stmt = $pdo->prepare('
        SELECT * FROM notifications
        WHERE user_id = ? AND is_read = 0
        ORDER BY created_at DESC
        LIMIT 20
    ');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Get recent notifications (read + unread, max 30) for the dropdown.
 */
function getRecentNotifications($pdo, $userId) {
    $stmt = $pdo->prepare('
        SELECT * FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 30
    ');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Get unread notification count.
 */
function getUnreadCount($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Mark a single notification as read.
 */
function markNotificationRead($pdo, $notifId, $userId) {
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([$notifId, $userId]);
}

/**
 * Mark all notifications as read for a user.
 */
function markAllNotificationsRead($pdo, $userId) {
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
}

/**
 * Render the notification bell button HTML (replaces the hardcoded bell).
 */
function renderNotifBell($unreadCount) {
    $badgeHtml = '';
    if ($unreadCount > 0) {
        $display = $unreadCount > 99 ? '99+' : $unreadCount;
        $badgeHtml = '<span class="notif-badge" id="notifBadge">' . $display . '</span>';
    } else {
        $badgeHtml = '<span class="notif-badge" id="notifBadge" style="display:none;">0</span>';
    }
    echo <<<HTML
    <button class="notif-bell" id="notifBellBtn" onclick="toggleNotifDropdown(event)" aria-label="Notifications">
        <i class="fa-regular fa-bell"></i>
        {$badgeHtml}
    </button>
HTML;
}

/**
 * Render the notification dropdown modal HTML.
 */
function renderNotifDropdown($notifications) {
    $notifJson = htmlspecialchars(json_encode($notifications), ENT_QUOTES, 'UTF-8');
    echo '<div id="notifDropdown" class="notif-dropdown" style="display:none;" data-notifications="' . $notifJson . '">';
    echo '  <div class="notif-dropdown-header">';
    echo '    <span class="notif-dropdown-title"><i class="fa-solid fa-bell" style="color:#3b82f6;margin-right:6px;"></i>Notifications</span>';
    echo '    <button class="notif-mark-all" onclick="markAllNotifsRead(event)">Mark all read</button>';
    echo '  </div>';
    echo '  <div class="notif-dropdown-body" id="notifDropdownBody">';
    
    if (empty($notifications)) {
        echo '    <div class="notif-empty">';
        echo '      <i class="fa-regular fa-bell-slash"></i>';
        echo '      <p>No notifications yet</p>';
        echo '    </div>';
    } else {
        foreach ($notifications as $notif) {
            $isUnread = !$notif['is_read'];
            $unreadClass = $isUnread ? ' notif-unread' : '';
            $id = (int)$notif['id'];
            $message = htmlspecialchars($notif['message']);
            $link = htmlspecialchars($notif['link'] ?? '#');
            $time = notifTimeAgo($notif['created_at']);
            $icon = notifIcon($notif['type']);
            $iconColor = notifIconColor($notif['type']);

            echo "<a href=\"{$link}\" class=\"notif-item{$unreadClass}\" data-notif-id=\"{$id}\" onclick=\"onNotifClick(event, {$id})\">";
            echo "  <div class=\"notif-item-icon\" style=\"background:{$iconColor}20;color:{$iconColor};\"><i class=\"{$icon}\"></i></div>";
            echo "  <div class=\"notif-item-content\">";
            echo "    <div class=\"notif-item-msg\">{$message}</div>";
            echo "    <div class=\"notif-item-time\"><i class=\"fa-regular fa-clock\"></i> {$time}</div>";
            echo "  </div>";
            if ($isUnread) {
                echo "  <div class=\"notif-unread-dot\"></div>";
            }
            echo "</a>";
        }
    }
    
    echo '  </div>';
    echo '</div>';
}

/**
 * Get icon class by notification type.
 */
function notifIcon($type) {
    $icons = [
        'application_accepted'     => 'fa-solid fa-circle-check',
        'application_rejected'     => 'fa-solid fa-circle-xmark',
        'new_application'          => 'fa-solid fa-file-circle-plus',
        'student_committed'        => 'fa-solid fa-handshake',
        'dpr_submitted'            => 'fa-solid fa-clipboard-check',
        'late_submission'          => 'fa-solid fa-clock-rotate-left',
        'bulk_submission_anomaly'  => 'fa-solid fa-triangle-exclamation',
        'at_risk'                  => 'fa-solid fa-circle-exclamation',
        'top_performer'            => 'fa-solid fa-star',
        'dpr_deadline'             => 'fa-solid fa-calendar-xmark',
        'dss_update'               => 'fa-solid fa-chart-line',
    ];
    return $icons[$type] ?? 'fa-solid fa-bell';
}

/**
 * Get icon color by notification type.
 */
function notifIconColor($type) {
    $colors = [
        'application_accepted'     => '#16a34a',
        'application_rejected'     => '#dc2626',
        'new_application'          => '#2563eb',
        'student_committed'        => '#7c3aed',
        'dpr_submitted'            => '#0891b2',
        'late_submission'          => '#d97706',
        'bulk_submission_anomaly'  => '#dc2626',
        'at_risk'                  => '#ea580c',
        'top_performer'            => '#ca8a04',
        'dpr_deadline'             => '#9333ea',
        'dss_update'               => '#0284c7',
    ];
    return $colors[$type] ?? '#64748b';
}

/**
 * Notify all coordinators about an event.
 * Returns the number of notifications created.
 */
function notifyCoordinators($pdo, $type, $message, $link = null) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'coordinator' AND status = 'active'");
    $stmt->execute();
    $coordinators = $stmt->fetchAll();
    foreach ($coordinators as $c) {
        createNotification($pdo, (int)$c['id'], $type, $message, $link);
    }
    return count($coordinators);
}

/**
 * Notify all admins about an event.
 */
function notifyAdmins($pdo, $type, $message, $link = null) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
    $stmt->execute();
    $admins = $stmt->fetchAll();
    foreach ($admins as $a) {
        createNotification($pdo, (int)$a['id'], $type, $message, $link);
    }
    return count($admins);
}

/**
 * Run Submission Velocity Metric (Mv) anomaly detection for a student.
 *
 * Detection window  : 1 hour  (short_window_minutes)
 * Velocity threshold: 3 DPRs submitted within the window
 * Span threshold    : span of report_date values must cover >= 3 days
 *
 * If both conditions are met, record a BULK_SUBMISSION_ANOMALY flag and send
 * notifications to coordinators and admins.
 *
 * @return bool  true if anomaly was newly flagged
 */
function checkMvAnomaly($pdo, $studentId, $studentName) {
    $shortWindowMinutes = 60;
    $velocityThreshold  = 3;
    $spanDaysThreshold  = 3;

    // Count DPRs submitted within the last $shortWindowMinutes
    $stmt = $pdo->prepare("
        SELECT report_date
        FROM daily_progress_reports
        WHERE user_id = ?
          AND submitted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ORDER BY report_date ASC
    ");
    $stmt->execute([$studentId, $shortWindowMinutes]);
    $recent = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($recent) < $velocityThreshold) {
        return false;
    }

    // Calculate span of report dates covered by the burst
    $dates  = array_unique($recent);
    sort($dates);
    $first  = new DateTime($dates[0]);
    $last   = new DateTime(end($dates));
    $span   = (int)$first->diff($last)->days;

    if ($span < $spanDaysThreshold) {
        return false;
    }

    // Check we haven't already flagged this burst (no unresolved flag in last 2 hours)
    $dupStmt = $pdo->prepare("
        SELECT id FROM dpr_anomaly_flags
        WHERE student_id = ?
          AND flag_type   = 'BULK_SUBMISSION_ANOMALY'
          AND is_resolved = 0
          AND flagged_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
        LIMIT 1
    ");
    $dupStmt->execute([$studentId]);
    if ($dupStmt->fetch()) {
        return false; // Already flagged recently
    }

    $details = sprintf(
        '%d DPRs submitted within %d minutes covering %d days (%s → %s)',
        count($recent),
        $shortWindowMinutes,
        $span,
        $dates[0],
        end($dates)
    );

    $pdo->prepare("
        INSERT INTO dpr_anomaly_flags (student_id, flag_type, details)
        VALUES (?, 'BULK_SUBMISSION_ANOMALY', ?)
    ")->execute([$studentId, $details]);

    $msg  = "Bulk submission anomaly detected for student {$studentName}: {$details}";
    notifyCoordinators($pdo, 'bulk_submission_anomaly', $msg, '../coordinator/dpr_monitor.php');
    notifyAdmins($pdo,       'bulk_submission_anomaly', $msg, null);

    return true;
}

/**
 * Check whether the DPR being submitted is late.
 * "Late" means: the report_date is in the past AND submitted_at is after
 * 23:59:59 of that report_date.
 *
 * @param string $reportDate  e.g. '2026-07-10'
 * @param string $submittedAt e.g. '2026-07-12 14:30:00'
 * @return bool
 */
function isLateSubmission($reportDate, $submittedAt) {
    $deadline = new DateTime($reportDate . ' 23:59:59');
    $submitted = new DateTime($submittedAt);
    return $submitted > $deadline;
}

/**
 * Record a LATE_SUBMISSION anomaly flag and notify student + coordinator.
 */
function flagLateSubmission($pdo, $studentId, $studentName, $reportDate, $submittedAt) {
    $details = "DPR for {$reportDate} submitted at {$submittedAt} (after day deadline)";

    // Avoid duplicate flags for the exact same report date
    $dupStmt = $pdo->prepare("
        SELECT id FROM dpr_anomaly_flags
        WHERE student_id = ?
          AND flag_type   = 'LATE_SUBMISSION'
          AND details     LIKE ?
        LIMIT 1
    ");
    $dupStmt->execute([$studentId, "%DPR for {$reportDate}%"]);
    if ($dupStmt->fetch()) {
        return; // Already flagged
    }

    $pdo->prepare("
        INSERT INTO dpr_anomaly_flags (student_id, flag_type, details)
        VALUES (?, 'LATE_SUBMISSION', ?)
    ")->execute([$studentId, $details]);

    // Notify the student
    createNotification($pdo, $studentId, 'late_submission',
        "Your DPR for {$reportDate} was recorded as a late submission.",
        'dpr.php');

    // Notify coordinators
    notifyCoordinators($pdo, 'late_submission',
        "Late DPR submission by {$studentName} for date {$reportDate}.",
        '../coordinator/dpr_monitor.php');
}

/**
 * Run DSS classification for a student and update dss_classifications table.
 * Notifies student and coordinators when classification changes.
 *
 * Thresholds (configurable):
 *  - AT_RISK      : late_count > 3  OR  anomaly_count > 0
 *  - TOP_PERFORMER: late_count == 0 AND anomaly_count == 0 AND total_dprs >= 5
 *  - NORMAL       : everything else
 */
function runDssClassification($pdo, $studentId, $studentName) {
    // Count totals
    $dprStmt = $pdo->prepare("SELECT COUNT(*) FROM daily_progress_reports WHERE user_id = ?");
    $dprStmt->execute([$studentId]);
    $totalDprs = (int)$dprStmt->fetchColumn();

    $lateStmt = $pdo->prepare("
        SELECT COUNT(*) FROM dpr_anomaly_flags
        WHERE student_id = ? AND flag_type = 'LATE_SUBMISSION'
    ");
    $lateStmt->execute([$studentId]);
    $lateCount = (int)$lateStmt->fetchColumn();

    $anomalyStmt = $pdo->prepare("
        SELECT COUNT(*) FROM dpr_anomaly_flags
        WHERE student_id = ? AND flag_type = 'BULK_SUBMISSION_ANOMALY'
    ");
    $anomalyStmt->execute([$studentId]);
    $anomalyCount = (int)$anomalyStmt->fetchColumn();

    // Apply classification rules
    if ($lateCount > 3 || $anomalyCount > 0) {
        $classification = 'AT_RISK';
    } elseif ($lateCount === 0 && $anomalyCount === 0 && $totalDprs >= 5) {
        $classification = 'TOP_PERFORMER';
    } else {
        $classification = 'NORMAL';
    }

    // Fetch existing classification
    $existingStmt = $pdo->prepare("SELECT classification FROM dss_classifications WHERE student_id = ?");
    $existingStmt->execute([$studentId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    $oldClass = $existing ? $existing['classification'] : null;

    // Upsert
    $pdo->prepare("
        INSERT INTO dss_classifications (student_id, classification, late_count, total_dprs, anomaly_count, classified_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            classification = VALUES(classification),
            late_count     = VALUES(late_count),
            total_dprs     = VALUES(total_dprs),
            anomaly_count  = VALUES(anomaly_count),
            classified_at  = NOW()
    ")->execute([$studentId, $classification, $lateCount, $totalDprs, $anomalyCount]);

    // Only send notifications when classification changes
    if ($oldClass !== $classification) {
        if ($classification === 'AT_RISK') {
            createNotification($pdo, $studentId, 'at_risk',
                'You have been classified as At-Risk based on your DPR submission record. Please contact your coordinator.',
                'dpr.php');
            notifyCoordinators($pdo, 'at_risk',
                "{$studentName} has been classified as AT_RISK.",
                '../coordinator/dpr_monitor.php');
        } elseif ($classification === 'TOP_PERFORMER') {
            createNotification($pdo, $studentId, 'top_performer',
                'Congratulations! You have been recognized as a Top Performer based on your consistent DPR submissions.',
                'dpr.php');
            notifyCoordinators($pdo, 'dss_update',
                "{$studentName} has been classified as TOP_PERFORMER.",
                '../coordinator/dpr_monitor.php');
        }
    }

    return $classification;
}
 
function notifTimeAgo($datetime) {
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

/**
 * Render notification dropdown CSS (call once in <style> or <head>).
 */
function renderNotifStyles() {
    echo <<<'CSS'
    /* ---- Notification Dropdown ---- */
    .notif-dropdown {
        position: absolute;
        top: calc(100% + 10px);
        right: 0;
        width: 380px;
        max-height: 480px;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.18), 0 0 0 1px rgba(0,0,0,0.04);
        z-index: 2000;
        overflow: hidden;
        animation: notifSlideDown 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes notifSlideDown {
        from { opacity: 0; transform: translateY(-8px) scale(0.96); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .notif-dropdown-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px 12px;
        border-bottom: 1px solid #f1f5f9;
    }

    .notif-dropdown-title {
        font-weight: 700;
        font-size: 1rem;
        color: #0f172a;
        display: flex;
        align-items: center;
    }

    .notif-mark-all {
        background: none;
        border: none;
        color: #3b82f6;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 6px;
        transition: all 0.15s;
    }

    .notif-mark-all:hover {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .notif-dropdown-body {
        overflow-y: auto;
        max-height: 400px;
        padding: 8px 0;
    }

    .notif-item {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px 20px;
        text-decoration: none;
        color: inherit;
        transition: background 0.12s;
        position: relative;
        cursor: pointer;
    }

    .notif-item:hover {
        background: #f8fafc;
    }

    .notif-item.notif-unread {
        background: #eff6ff;
    }

    .notif-item.notif-unread:hover {
        background: #dbeafe;
    }

    .notif-item-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        flex-shrink: 0;
    }

    .notif-item-content {
        flex: 1;
        min-width: 0;
    }

    .notif-item-msg {
        font-size: 0.88rem;
        color: #1e293b;
        line-height: 1.45;
        font-weight: 500;
    }

    .notif-unread .notif-item-msg {
        font-weight: 600;
        color: #0f172a;
    }

    .notif-item-time {
        font-size: 0.75rem;
        color: #94a3b8;
        margin-top: 4px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .notif-unread-dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        background: #3b82f6;
        flex-shrink: 0;
        margin-top: 6px;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
    }

    .notif-empty {
        padding: 40px 20px;
        text-align: center;
        color: #94a3b8;
    }

    .notif-empty i {
        font-size: 2rem;
        margin-bottom: 10px;
        display: block;
        opacity: 0.5;
    }

    .notif-empty p {
        margin: 0;
        font-size: 0.9rem;
        font-weight: 500;
    }

    /* Position wrapper for the dropdown */
    .notif-bell-wrapper {
        position: relative;
    }

    @media (max-width: 480px) {
        .notif-dropdown {
            width: calc(100vw - 32px);
            right: -60px;
        }
    }
CSS;
}

/**
 * Render notification JavaScript (call once before </body>).
 * $basePath is the relative path to the includes directory (e.g., '../includes/')
 */
function renderNotifScript($basePath = '../includes/') {
    $markUrl = htmlspecialchars($basePath . 'mark_notifications.php');
    echo <<<SCRIPT
    <script>
    /* ---- Notification Dropdown Logic ---- */
    let notifDropdownOpen = false;

    function toggleNotifDropdown(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('notifDropdown');
        if (!dropdown) return;

        notifDropdownOpen = !notifDropdownOpen;
        dropdown.style.display = notifDropdownOpen ? 'block' : 'none';
    }

    function closeNotifDropdown() {
        const dropdown = document.getElementById('notifDropdown');
        if (dropdown) dropdown.style.display = 'none';
        notifDropdownOpen = false;
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.querySelector('.notif-bell-wrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            closeNotifDropdown();
        }
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeNotifDropdown();
    });

    function onNotifClick(e, notifId) {
        // Mark as read via AJAX, then let the link navigate
        const item = e.currentTarget;
        const link = item.getAttribute('href');

        // Fire-and-forget AJAX to mark as read
        fetch('{$markUrl}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'notification_id=' + notifId
        }).catch(function() {});

        // Remove unread styling instantly
        item.classList.remove('notif-unread');
        const dot = item.querySelector('.notif-unread-dot');
        if (dot) dot.remove();

        // Update badge count
        updateBadgeCount(-1);

        // Navigate if link is valid
        if (link && link !== '#') {
            // Let default link behavior handle navigation
            return;
        }
        e.preventDefault();
    }

    function markAllNotifsRead(e) {
        e.preventDefault();
        e.stopPropagation();

        fetch('{$markUrl}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'mark_all=1'
        }).then(function(res) { return res.json(); })
          .then(function(data) {
            if (data.success) {
                // Remove all unread styling
                document.querySelectorAll('.notif-item.notif-unread').forEach(function(item) {
                    item.classList.remove('notif-unread');
                    const dot = item.querySelector('.notif-unread-dot');
                    if (dot) dot.remove();
                });
                // Reset badge
                const badge = document.getElementById('notifBadge');
                if (badge) {
                    badge.textContent = '0';
                    badge.style.display = 'none';
                }
            }
          }).catch(function() {});
    }

    function updateBadgeCount(delta) {
        const badge = document.getElementById('notifBadge');
        if (!badge) return;
        let count = parseInt(badge.textContent) || 0;
        count = Math.max(0, count + delta);
        badge.textContent = count;
        badge.style.display = count > 0 ? 'flex' : 'none';
    }
    </script>
SCRIPT;
}
?>
