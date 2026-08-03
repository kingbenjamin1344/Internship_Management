<?php
// student/notification_component.php
// Shared notification bell component for all student pages

// This file should be included in student pages after checking authentication
// It provides the HTML and JavaScript for the notification bell

// Function to render notification bell HTML
function renderStudentNotificationBell($unreadCount, $notifications) {
    ?>
    <!-- Notification bell with dropdown -->
    <div class="notif-wrapper">
        <button class="notif-bell" id="notifBell" aria-label="Notifications">
            <i class="fa-regular fa-bell"></i>
            <?php if ($unreadCount > 0): ?>
                <span class="notif-badge" id="notifBadge"><?php echo $unreadCount; ?></span>
            <?php endif; ?>
        </button>

        <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-dropdown-header">
                <h3>Notifications</h3>
                <button class="mark-all-read" id="markAllRead">Mark all as read</button>
            </div>
            <div class="notif-list" id="notifList">
                <?php if (!empty($notifications)): ?>
                    <?php foreach ($notifications as $notif): ?>
                        <?php
                            // Use message field if available and not empty, otherwise fall back to title
                            $titleText = $notif['title'] && trim($notif['title']) !== '' ? $notif['title'] : 'Notification';
                            $messageText = (!empty($notif['message']) && trim($notif['message']) !== '') ? $notif['message'] : $titleText;
                            $source = 'System';
                            $initials = 'SY';
                            if (!empty($notif['firstname']) && !empty($notif['lastname'])) {
                                $source = $notif['firstname'] . ' ' . $notif['lastname'];
                                $initials = strtoupper(substr($notif['firstname'], 0, 1) . substr($notif['lastname'], 0, 1));
                            }
                            $role = $notif['role'] ?? 'system';
                            $roleBadge = '<span class="notif-role-badge ' . strtolower($role) . '">' . strtoupper($role) . '</span>';
                            $timeAgoStr = timeAgo($notif['created_at'] ?? '');
                        ?>
                        <div class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>"
                             data-id="<?php echo $notif['id']; ?>"
                             data-link="<?php echo htmlspecialchars($notif['link'] ?? '#', ENT_QUOTES); ?>"
                             onclick="handleNotificationClick(event, <?php echo $notif['id']; ?>, '<?php echo htmlspecialchars($notif['link'] ?? '#', ENT_QUOTES); ?>')">
                            <div class="notif-avatar-circle">
                                <?php if (!empty($notif['profile_picture'])): ?>
                                    <img src="../assets/uploads/avatars/<?php echo htmlspecialchars($notif['profile_picture']); ?>" alt="<?php echo htmlspecialchars($source); ?>" title="<?php echo htmlspecialchars($source); ?>">
                                <?php else: ?>
                                    <span class="notif-initials" title="<?php echo htmlspecialchars($source); ?>"><?php echo $initials; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="notif-content">
                                <div class="notif-title"><?php echo htmlspecialchars($titleText); ?></div>
                                <div class="notif-description"><?php echo htmlspecialchars($messageText); ?></div>
                                <div class="notif-meta">
                                    <span class="notif-sender-name">
                                        <i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($source); ?>
                                    </span>
                                    <?php echo $roleBadge; ?>
                                    <span style="margin: 0 4px;">•</span>
                                    <span><i class="fa-regular fa-clock"></i> <?php echo htmlspecialchars($timeAgoStr); ?></span>
                                </div>
                            </div>
                            <button type="button" class="notif-dismiss" data-id="<?php echo $notif['id']; ?>" title="Dismiss" onclick="event.stopPropagation(); dismissNotification(<?php echo $notif['id']; ?>, this.closest('.notif-item'))">×</button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notif-empty">
                        <i class="fa-regular fa-bell-slash"></i>
                        <p>No notifications yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// Function to render notification JavaScript
function renderStudentNotificationScript($userId) {
    ?>
    <script>
        // ===== NOTIFICATION BELL JAVASCRIPT =====
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text || '').replace(/[&<>"']/g, m => map[m]);
        }

        function timeAgo(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);
            
            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            if (diff < 2592000) return Math.floor(diff / 604800) + 'w ago';
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        const notifBell = document.getElementById('notifBell');
        const notifDropdown = document.getElementById('notifDropdown');
        const notifBadge = document.getElementById('notifBadge');
        const notifList = document.getElementById('notifList');
        const markAllReadBtn = document.getElementById('markAllRead');
        const endpoint = '../includes/mark_notifications.php';

        // Toggle dropdown
        if (notifBell) {
            notifBell.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = notifDropdown.classList.contains('open');
                
                if (!isOpen) {
                    notifDropdown.classList.add('open');
                    loadNotifications();
                    
                    // Auto-mark all as read when opening the dropdown
                    setTimeout(function() {
                        var formData = new FormData();
                        formData.append('action', 'mark_all_read');
                        
                        fetch(endpoint, { method: 'POST', body: formData })
                            .then(function(response) { return response.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    // Remove unread class from all items
                                    document.querySelectorAll('.notif-item.unread').forEach(function(item) {
                                        item.classList.remove('unread');
                                    });
                                    // Hide badge immediately
                                    updateBadge(0);
                                }
                            })
                            .catch(function(err) {
                                console.error('Error auto-marking as read:', err);
                            });
                    }, 500); // Small delay to let dropdown open first
                } else {
                    notifDropdown.classList.remove('open');
                }
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (notifDropdown && !notifDropdown.contains(e.target) && e.target !== notifBell && !notifBell.contains(e.target)) {
                notifDropdown.classList.remove('open');
            }
        });

        // Close on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && notifDropdown && notifDropdown.classList.contains('open')) {
                notifDropdown.classList.remove('open');
            }
        });

        // Load notifications via AJAX
        function loadNotifications() {
            var formData = new FormData();
            formData.append('action', 'get_notifications');
            formData.append('limit', 20);
            formData.append('offset', 0);

            fetch(endpoint, { method: 'POST', body: formData })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success && data.notifications) {
                        renderNotifications(data.notifications);
                        updateBadge(data.unread_count);
                    }
                })
                .catch(function(err) {
                    console.error('Error loading notifications:', err);
                });
        }

        // Render notifications (sorted with unread first)
        function renderNotifications(notifications) {
            if (!notifList) return;
            
            if (notifications.length === 0) {
                notifList.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>No notifications yet</p></div>';
                return;
            }

            // Sort notifications: unread first, then by date
            const sortedNotifications = notifications.sort(function(a, b) {
                // First, prioritize unread notifications
                if (a.is_read !== b.is_read) {
                    return a.is_read ? 1 : -1; // unread (0) comes before read (1)
                }
                // Then sort by created_at (newest first)
                return new Date(b.created_at) - new Date(a.created_at);
            });

            let html = '';
            sortedNotifications.forEach(function(notif) {
                // Use message field if available, otherwise fall back to title
                const titleText = notif.title && String(notif.title).trim() !== '' ? notif.title : 'Notification';
                const messageText = (notif.message && notif.message.trim() !== '') ? notif.message : titleText;
                const source = (notif.firstname && notif.lastname) ? (notif.firstname + ' ' + notif.lastname) : 'System';
                const initials = (notif.firstname && notif.lastname) ? 
                    (notif.firstname.charAt(0).toUpperCase() + notif.lastname.charAt(0).toUpperCase()) : 'SY';
                const link = notif.link || '#';
                const readClass = notif.is_read ? '' : 'unread';
                const role = notif.role || 'system';
                const roleBadge = '<span class="notif-role-badge ' + role.toLowerCase() + '">' + role.toUpperCase() + '</span>';
                const timeAgoStr = timeAgo(notif.created_at);

                html += '<div class="notif-item ' + readClass + '" data-id="' + notif.id + '" data-link="' + escapeHtml(link) + '" onclick="handleNotificationClick(event, ' + notif.id + ', \'' + escapeHtml(link) + '\')">' +
                    '<div class="notif-avatar-circle">';
                
                if (notif.profile_picture) {
                    html += '<img src="../assets/uploads/avatars/' + escapeHtml(notif.profile_picture) + '" alt="' + escapeHtml(source) + '" title="' + escapeHtml(source) + '">';
                } else {
                    html += '<span class="notif-initials" title="' + escapeHtml(source) + '">' + initials + '</span>';
                }
                
                html += '</div>' +
                    '<div class="notif-content">' +
                    '<div class="notif-title">' + escapeHtml(titleText) + '</div>' +
                    '<div class="notif-description">' + escapeHtml(messageText) + '</div>' +
                    '<div class="notif-meta">' +
                    '<span class="notif-sender-name">' +
                    '<i class="fa-solid fa-user"></i> ' + escapeHtml(source) +
                    '</span>' +
                    roleBadge +
                    '<span style="margin: 0 4px;">•</span>' +
                    '<span><i class="fa-regular fa-clock"></i> ' + escapeHtml(timeAgoStr) + '</span>' +
                    '</div>' +
                    '</div>' +
                    '<button type="button" class="notif-dismiss" data-id="' + notif.id + '" title="Dismiss" onclick="event.stopPropagation(); dismissNotification(' + notif.id + ', this.closest(\'.notif-item\'))">×</button>' +
                    '</div>';
            });

            notifList.innerHTML = html;
        }

        // Dismiss notification
        function dismissNotification(notificationId, element) {
            var formData = new FormData();
            formData.append('action', 'delete');
            formData.append('notification_id', notificationId);

            fetch(endpoint, { method: 'POST', body: formData })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        if (element && element.parentNode) {
                            element.parentNode.removeChild(element);
                        }
                        // Reload notifications to update count
                        setTimeout(function() {
                            loadNotifications();
                        }, 200);
                    }
                })
                .catch(function(err) {
                    console.error('Error dismissing notification:', err);
                });
        }

        // Handle notification click
        function handleNotificationClick(e, notificationId, link) {
            e.preventDefault();
            e.stopPropagation();
            
            // Mark as read
            var formData = new FormData();
            formData.append('action', 'mark_read');
            formData.append('notification_id', notificationId);

            fetch(endpoint, { method: 'POST', body: formData })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        // Update the notification item to remove unread class
                        const notifItem = document.querySelector('.notif-item[data-id="' + notificationId + '"]');
                        if (notifItem) {
                            notifItem.classList.remove('unread');
                        }
                        
                        // Update badge count
                        updateBadge(data.unread_count || 0);
                        
                        // Navigate to link if not #
                        if (link && link !== '#') {
                            setTimeout(function() {
                                window.location.href = link;
                            }, 200);
                        }
                    }
                })
                .catch(function(err) {
                    console.error('Error marking notification as read:', err);
                    // Still navigate even if marking fails
                    if (link && link !== '#') {
                        window.location.href = link;
                    }
                });
        }

        // Mark all as read
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                console.log('Marking all as read...'); // Debug log
                
                var formData = new FormData();
                formData.append('action', 'mark_all_read');

                fetch(endpoint, { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        console.log('Mark all read response:', data); // Debug log
                        if (data.success) {
                            // Remove unread class from all items
                            document.querySelectorAll('.notif-item.unread').forEach(function(item) {
                                item.classList.remove('unread');
                            });
                            // Update badge to the actual count from server
                            updateBadge(data.unread_count || 0);
                            
                            // If badge is now 0, reload page to ensure consistency
                            if ((data.unread_count || 0) === 0) {
                                setTimeout(function() {
                                    window.location.reload();
                                }, 500);
                            }
                        }
                    })
                    .catch(function(err) {
                        console.error('Error marking all as read:', err);
                    });
            });
        }

        // Update badge
        function updateBadge(count) {
            if (notifBadge) {
                if (count > 0) {
                    notifBadge.textContent = count;
                    notifBadge.style.display = 'flex';
                } else {
                    notifBadge.style.display = 'none';
                }
            }
        }

        // Auto-refresh notifications every 30 seconds
        setInterval(function() {
            // Only update badge count when dropdown is closed
            if (notifDropdown && !notifDropdown.classList.contains('open')) {
                var formData = new FormData();
                formData.append('action', 'get_notifications');
                formData.append('limit', 1);
                formData.append('offset', 0);

                fetch(endpoint, { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            // Update badge with current unread count
                            updateBadge(data.unread_count || 0);
                        }
                    })
                    .catch(function(err) {
                        console.error('Error updating badge:', err);
                    });
            }
        }, 30000); // 30 seconds
    </script>
    <?php
}

// CSS for notification bell (to be included in pages)
function renderStudentNotificationCSS() {
    ?>
    <style>
        /* ===== NOTIFICATION BELL & DROPDOWN ===== */
        .notif-wrapper {
            position: relative;
            display: inline-block;
        }

        .notif-bell {
            position: relative;
            font-size: 1.3rem;
            color: #FFCC33;
            background: rgba(255, 204, 51, 0.2);
            width: 44px;
            height: 44px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.15s;
            cursor: pointer;
            border: none;
            flex-shrink: 0;
        }

        .notif-bell:hover {
            background: rgba(255, 204, 51, 0.4);
            color: #fff;
        }

        .notif-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #ef4444;
            color: #fff;
            font-size: 0.6rem;
            font-weight: 700;
            min-width: 20px;
            height: 20px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #003300;
            padding: 0 4px;
        }

        /* Notification Dropdown */
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 380px;
            max-height: 480px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            display: none;
            z-index: 1000;
            overflow: hidden;
            border-radius: 0;
        }

        .notif-dropdown.open {
            display: block;
            animation: slideDown 0.2s ease;
        }

        @keyframes slideDown {
            0% {
                opacity: 0;
                transform: translateY(-10px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .notif-dropdown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #edf2f7;
            background: #f8fafc;
        }

        .notif-dropdown-header h3 {
            font-size: 0.9rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .notif-dropdown-header .mark-all-read {
            background: none;
            border: none;
            color: #2563eb;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            padding: 4px 8px;
            transition: 0.15s;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        .notif-dropdown-header .mark-all-read:hover {
            text-decoration: underline;
        }

        .notif-list {
            max-height: 400px;
            overflow-y: auto;
            padding: 0;
        }

        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.15s;
            position: relative;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-item:hover {
            background: #f8fafc;
        }

        .notif-item.unread {
            background: #eff6ff;
            border-left: 3px solid #2563eb;
        }

        .notif-item .notif-avatar-circle {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.75rem;
            color: #475569;
            overflow: hidden;
        }

        .notif-item .notif-avatar-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .notif-item .notif-initials {
            font-weight: 700;
            color: #0f172a;
        }

        .notif-item .notif-content {
            flex: 1;
            min-width: 0;
        }

        .notif-item .notif-content .notif-title {
            font-weight: 600;
            font-size: 0.85rem;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .notif-item .notif-content .notif-description {
            font-size: 0.8rem;
            color: #64748b;
            line-height: 1.4;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notif-item .notif-content .notif-meta {
            font-size: 0.7rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .notif-item .notif-content .notif-meta i {
            font-size: 0.65rem;
        }

        .notif-item .notif-content .notif-meta .notif-sender-name {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .notif-item .notif-content .notif-meta .notif-role-badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            border-radius: 2px;
        }

        .notif-item .notif-content .notif-meta .notif-role-badge.supervisor {
            background: #fef3c7;
            color: #92400e;
        }

        .notif-item .notif-content .notif-meta .notif-role-badge.coordinator {
            background: #dbeafe;
            color: #1e40af;
        }

        .notif-item .notif-content .notif-meta .notif-role-badge.admin {
            background: #fecaca;
            color: #991b1b;
        }

        .notif-item .notif-content .notif-meta .notif-role-badge.system {
            background: #e5e7eb;
            color: #374151;
        }

        .notif-item .notif-dismiss {
            position: absolute;
            top: 8px;
            right: 8px;
            background: none;
            border: none;
            color: #cbd5e1;
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
            padding: 4px 8px;
            transition: 0.15s;
            font-weight: 300;
        }

        .notif-item .notif-dismiss:hover {
            color: #ef4444;
            transform: scale(1.1);
        }

        .notif-empty {
            padding: 48px 16px;
            text-align: center;
            color: #94a3b8;
        }

        .notif-empty i {
            font-size: 2.5rem;
            display: block;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        .notif-empty p {
            font-size: 0.9rem;
            margin: 0;
        }
    </style>
    <?php
}
?>
