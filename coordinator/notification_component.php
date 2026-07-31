<?php
// coordinator/notification_component.php
// Shared notification bell component for all coordinator pages

// This file should be included in coordinator pages after checking authentication
// It provides the HTML and JavaScript for the notification bell

// Function to render notification bell HTML
function renderNotificationBell($unreadCount, $notifications) {
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
                            $messageText = !empty($notif['message']) ? $notif['message'] : ($notif['title'] ?? 'Notification');
                            $source = 'System';
                            $initials = 'SY';
                            if (!empty($notif['firstname']) && !empty($notif['lastname'])) {
                                $source = $notif['firstname'] . ' ' . $notif['lastname'];
                                $initials = strtoupper(substr($notif['firstname'], 0, 1) . substr($notif['lastname'], 0, 1));
                            }
                            $createdDate = !empty($notif['created_at']) ? date('M d, Y', strtotime($notif['created_at'])) : '';
                        ?>
                        <div class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>"
                             data-id="<?php echo $notif['id']; ?>"
                             data-link="<?php echo htmlspecialchars($notif['link'] ?? '#', ENT_QUOTES); ?>"
                             onclick="handleNotificationClick(event, <?php echo $notif['id']; ?>, '<?php echo htmlspecialchars($notif['link'] ?? '#', ENT_QUOTES); ?>')">
                            <div class="notif-avatar-circle">
                                <?php if (!empty($notif['profile_picture'])): ?>
                                    <img src="../assets/uploads/avatars/<?php echo htmlspecialchars($notif['profile_picture']); ?>" alt="Avatar">
                                <?php else: ?>
                                    <span class="notif-initials"><?php echo $initials; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="notif-content">
                                <div class="notif-title"><?php echo htmlspecialchars($notif['title'] ?? 'Notification'); ?></div>
                                <div class="notif-description"><?php echo htmlspecialchars($messageText); ?></div>
                                <div class="notif-date"><?php echo htmlspecialchars($createdDate); ?></div>
                                <div class="notif-meta"><?php echo htmlspecialchars($source); ?></div>
                            </div>
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

// Function to format time ago
function timeAgoFormat($dateStr) {
    if (!$dateStr) return '';
    
    try {
        $date = new DateTime($dateStr);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $date->getTimestamp();
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        if ($diff < 2592000) return floor($diff / 604800) . 'w ago';
        return $date->format('M d, Y');
    } catch (Exception $e) {
        return '';
    }
}

// Function to render notification JavaScript
function renderNotificationScript() {
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

        // Check if user has clicked bell recently (within last page load)
        const bellClickedKey = 'notif_bell_clicked_' + <?php echo $userId ?? 0; ?>;
        
        // Hide badge on page load if user clicked bell recently
        if (localStorage.getItem(bellClickedKey) === 'true') {
            if (notifBadge) {
                notifBadge.style.display = 'none';
            }
        }

        // Toggle dropdown
        if (notifBell) {
            notifBell.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = notifDropdown.classList.contains('open');
                
                if (!isOpen) {
                    notifDropdown.classList.add('open');
                    loadNotifications();
                    // Hide badge when dropdown is opened (user has seen notifications)
                    if (notifBadge) {
                        notifBadge.style.display = 'none';
                    }
                    // Store in localStorage that user clicked the bell
                    localStorage.setItem(bellClickedKey, 'true');
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

            fetch('../includes/mark_notifications.php', { method: 'POST', body: formData })
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

        // Render notifications
        function renderNotifications(notifications) {
            if (!notifList) return;
            
            if (notifications.length === 0) {
                notifList.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>No notifications yet</p></div>';
                return;
            }

            let html = '';
            notifications.forEach(function(notif) {
                const messageText = notif.message || notif.title || 'Notification';
                const source = (notif.firstname && notif.lastname) ? (notif.firstname + ' ' + notif.lastname) : 'System';
                const initials = (notif.firstname && notif.lastname) ? 
                    (notif.firstname.charAt(0).toUpperCase() + notif.lastname.charAt(0).toUpperCase()) : 'SY';
                const link = notif.link || '#';
                const readClass = notif.is_read ? '' : 'unread';
                const createdDate = notif.created_at ? formatDate(notif.created_at) : '';

                html += '<div class="notif-item ' + readClass + '" data-id="' + notif.id + '" data-link="' + escapeHtml(link) + '" onclick="handleNotificationClick(event, ' + notif.id + ', \'' + escapeHtml(link) + '\')">' +
                    '<div class="notif-avatar-circle">';
                
                if (notif.profile_picture) {
                    html += '<img src="../assets/uploads/avatars/' + escapeHtml(notif.profile_picture) + '" alt="Avatar">';
                } else {
                    html += '<span class="notif-initials">' + initials + '</span>';
                }
                
                html += '</div>' +
                    '<div class="notif-content">' +
                    '<div class="notif-title">' + escapeHtml(notif.title || 'Notification') + '</div>' +
                    '<div class="notif-description">' + escapeHtml(messageText) + '</div>' +
                    '<div class="notif-date">' + escapeHtml(createdDate) + '</div>' +
                    '<div class="notif-meta">' + escapeHtml(source) + '</div>' +
                    '</div>' +
                    '</div>';
            });

            notifList.innerHTML = html;
        }
        
        // Format date helper
        function formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const options = { month: 'short', day: 'numeric', year: 'numeric' };
            return date.toLocaleDateString('en-US', options);
        }

        // Handle notification click
        function handleNotificationClick(e, notificationId, link) {
            e.preventDefault();
            e.stopPropagation();
            
            var formData = new FormData();
            formData.append('action', 'mark_read');
            formData.append('notification_id', notificationId);

            fetch('../includes/mark_notifications.php', { method: 'POST', body: formData })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        // Update the notification item to remove unread class
                        const notifItem = document.querySelector('.notif-item[data-id="' + notificationId + '"]');
                        if (notifItem) {
                            notifItem.classList.remove('unread');
                        }
                        
                        // Don't show badge again until user opens bell again
                        // Badge was hidden when bell was clicked
                        
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
                
                var formData = new FormData();
                formData.append('action', 'mark_all_read');

                fetch('../includes/mark_notifications.php', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            // Remove unread class from all items
                            document.querySelectorAll('.notif-item.unread').forEach(function(item) {
                                item.classList.remove('unread');
                            });
                            // Clear localStorage flag so badge can reappear for new notifications
                            localStorage.removeItem(bellClickedKey);
                            // Badge stays hidden (was hidden when bell clicked)
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

                fetch('../includes/mark_notifications.php', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            // Only show badge if there are new unread notifications
                            // AND user hasn't clicked the bell yet (localStorage check)
                            if (data.unread_count > 0 && localStorage.getItem(bellClickedKey) !== 'true') {
                                updateBadge(data.unread_count);
                            } else if (data.unread_count === 0) {
                                // Clear the localStorage flag if all notifications are read
                                localStorage.removeItem(bellClickedKey);
                            }
                        }
                    })
                    .catch(function(err) {
                        console.error('Error updating badge:', err);
                    });
            }
        }, 30000); // 30 seconds
        
        // Also check on page load after a short delay
        setTimeout(function() {
            if (notifDropdown && !notifDropdown.classList.contains('open')) {
                var formData = new FormData();
                formData.append('action', 'get_notifications');
                formData.append('limit', 1);
                formData.append('offset', 0);

                fetch('../includes/mark_notifications.php', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success && data.unread_count === 0) {
                            // All notifications are read, clear the localStorage flag
                            localStorage.removeItem(bellClickedKey);
                        }
                    })
                    .catch(function(err) {
                        console.error('Error checking notifications:', err);
                    });
            }
        }, 1000); // Check 1 second after page load
    </script>
    <?php
}
?>
