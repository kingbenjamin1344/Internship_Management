<?php
// supervisor/notification_component.php
// Reusable notification bell for supervisor pages

require_once __DIR__ . '/../includes/supervisor_notifications.php';

function renderSupervisorNotificationBell($userId) {
    // Use the global PDO connection created by including config/database.php earlier
    global $pdo;

    $unreadCount = getSupervisorUnreadNotificationCount($pdo, $userId);

    ?>
    <div class="notif-wrapper">
        <button type="button" class="notif-bell" id="notifBell" aria-label="Notifications">
            <i class="fa-regular fa-bell"></i>
            <span class="notif-badge" id="notifBadge" style="display: <?php echo $unreadCount > 0 ? 'flex' : 'none'; ?>;">
                <?php echo $unreadCount; ?>
            </span>
        </button>

        <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-dropdown-header">
                <h3>Notifications</h3>
                <button type="button" class="mark-all-read" id="markAllRead">Mark all as read</button>
            </div>
            <div class="notif-list" id="notifList">
                <div class="notif-empty">
                    <i class="fa-regular fa-bell-slash"></i>
                    <p>Loading notifications…</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Supervisor notification script — uses the current page as endpoint
        (function() {
            const notifBell = document.getElementById('notifBell');
            const notifDropdown = document.getElementById('notifDropdown');
            const notifBadge = document.getElementById('notifBadge');
            const notifList = document.getElementById('notifList');
            const markAllReadBtn = document.getElementById('markAllRead');
            const bellClickedKey = 'notif_bell_clicked_supervisor_' + <?php echo (int)$userId; ?>;
            const endpoint = '../includes/mark_notifications.php';

            function escapeHtml(text) {
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return String(text || '').replace(/[&<>"']/g, m => map[m]);
            }

            function formatDate(dateStr) {
                if (!dateStr) return '';
                const d = new Date(dateStr);
                return d.toLocaleString();
            }

            function updateBadge(count) {
                if (!notifBadge) return;
                if (count > 0) {
                    notifBadge.textContent = count;
                    notifBadge.style.display = 'flex';
                } else {
                    notifBadge.style.display = 'none';
                }
            }

            function renderNotifications(notifications) {
                if (!notifList) return;
                if (!notifications || notifications.length === 0) {
                    notifList.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>No notifications yet</p></div>';
                    return;
                }

                // sort: unread first, then newest
                notifications.sort(function(a,b) {
                    if (a.is_read !== b.is_read) return a.is_read ? 1 : -1;
                    return new Date(b.created_at) - new Date(a.created_at);
                });

                let html = '';
                notifications.forEach(function(notif) {
                    const titleText = notif.title && String(notif.title).trim() !== '' ? notif.title : 'Notification';
                    const messageText = notif.message && String(notif.message).trim() !== '' ? notif.message : titleText;
                    const source = notif.firstname && notif.lastname ? (notif.firstname + ' ' + notif.lastname) : 'System';
                    const initials = notif.firstname && notif.lastname ? (notif.firstname.charAt(0).toUpperCase() + notif.lastname.charAt(0).toUpperCase()) : 'SY';
                    const readClass = notif.is_read ? '' : 'unread';
                    const link = notif.link || '#';
                    const role = notif.role || 'system';
                    const roleBadge = '<span class="notif-role-badge ' + role.toLowerCase() + '">' + role.toUpperCase() + '</span>';
                    
                    // Profile picture handling
                    let avatarHtml = '';
                    if (notif.profile_picture) {
                        avatarHtml = '<img src="../assets/uploads/avatars/' + escapeHtml(notif.profile_picture) + '" alt="' + escapeHtml(source) + '" title="' + escapeHtml(source) + '">';
                    } else {
                        avatarHtml = '<span class="notif-initials" title="' + escapeHtml(source) + '">' + initials + '</span>';
                    }
                    
                    // Format time ago
                    const timeAgo = formatTimeAgo(notif.created_at);

                    html += '<div class="notif-item ' + readClass + '" data-id="' + notif.id + '" data-link="' + escapeHtml(link) + '">'
                         + '<div class="notif-avatar-circle">' + avatarHtml + '</div>'
                         + '<div class="notif-content">'
                         + '<div class="notif-title">' + escapeHtml(titleText) + '</div>'
                         + '<div class="notif-description">' + escapeHtml(messageText) + '</div>'
                         + '<div class="notif-meta">'
                         + '<span class="notif-sender-name">'
                         + '<i class="fa-solid fa-user"></i> ' + escapeHtml(source)
                         + '</span>'
                         + roleBadge
                         + '<span style="margin: 0 4px;">•</span>'
                         + '<span><i class="fa-regular fa-clock"></i> ' + escapeHtml(timeAgo) + '</span>'
                         + '</div>'
                         + '</div>'
                         + '<button type="button" class="notif-dismiss" data-id="' + notif.id + '" title="Dismiss">×</button>'
                         + '</div>';
                });

                notifList.innerHTML = html;

                // Attach click handlers
                document.querySelectorAll('.notif-item').forEach(function(item) {
                    item.addEventListener('click', function(e) {
                        // ignore clicks on dismiss button
                        if (e.target && e.target.classList.contains('notif-dismiss')) return;
                        const id = this.getAttribute('data-id');
                        const link = this.getAttribute('data-link') || '#';
                        markReadAndNavigate(id, link);
                    });
                });

                document.querySelectorAll('.notif-dismiss').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const id = this.getAttribute('data-id');
                        dismissNotification(id, this.closest('.notif-item'));
                    });
                });
            }
            
            function formatTimeAgo(dateStr) {
                if (!dateStr) return 'Unknown';
                const date = new Date(dateStr);
                const now = new Date();
                const diff = Math.floor((now - date) / 1000);
                
                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
                return date.toLocaleDateString();
            }

            function loadNotifications() {
                if (!notifList) return;
                notifList.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>Loading notifications…</p></div>';
                const fd = new FormData();
                fd.append('action', 'get_notifications');
                fd.append('limit', 50);
                fd.append('offset', 0);

                console.log('Loading notifications from:', endpoint);
                
                fetch(endpoint, { method: 'POST', body: fd })
                    .then(r => {
                        console.log('Response status:', r.status);
                        return r.json();
                    })
                    .then(data => {
                        console.log('Notification data received:', data);
                        if (data.success) {
                            console.log('Number of notifications:', (data.notifications || []).length);
                            renderNotifications(data.notifications || []);
                            updateBadge(data.unread_count || 0);
                        } else {
                            console.error('Failed to load notifications:', data);
                            notifList.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>Unable to load notifications.</p></div>';
                        }
                    }).catch(err => {
                        console.error('Fetch error:', err);
                        notifList.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>Unable to load notifications.</p></div>';
                    });
            }

            function markReadAndNavigate(id, link) {
                const fd = new FormData();
                fd.append('action', 'mark_read');
                fd.append('notification_id', id);

                fetch(endpoint, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            const el = document.querySelector('.notif-item[data-id="' + id + '"]');
                            if (el) el.classList.remove('unread');
                            updateBadge(data.unread_count || 0);
                        }
                        if (link && link !== '#') window.location.href = link;
                    }).catch(err => { console.error(err); if (link && link !== '#') window.location.href = link; });
            }

            function dismissNotification(id, el) {
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('notification_id', id);

                fetch(endpoint, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            if (el && el.parentNode) el.parentNode.removeChild(el);
                            // update badge count after deletion
                            const fd2 = new FormData(); fd2.append('action', 'get_notifications'); fd2.append('limit',1); fd2.append('offset',0);
                            return fetch(endpoint, { method: 'POST', body: fd2 });
                        }
                    })
                    .then(resp => resp ? resp.json() : null)
                    .then(j => { if (j && j.unread_count !== undefined) updateBadge(j.unread_count); })
                    .catch(err => console.error(err));
            }

            // Mark all as read
            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', function(e) {
                    e.preventDefault(); e.stopPropagation();
                    const fd = new FormData(); fd.append('action','mark_all_read');
                    fetch(endpoint, { method:'POST', body:fd })
                        .then(r=>r.json()).then(data=>{ if (data.success) { document.querySelectorAll('.notif-item.unread').forEach(i=>i.classList.remove('unread')); updateBadge(data.unread_count||0); } });
                });
            }

            // Bell toggle
            if (notifBell) {
                notifBell.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = notifDropdown.classList.contains('open');
                    if (!isOpen) {
                        notifDropdown.classList.add('open');
                        loadNotifications();
                        if (notifBadge) notifBadge.style.display = 'none';
                        localStorage.setItem(bellClickedKey, 'true');
                    } else {
                        notifDropdown.classList.remove('open');
                    }
                });
            }

            // Load notifications immediately so the card has fresh content
            loadNotifications();

            // Close when clicking outside
            document.addEventListener('click', function(e) { if (notifDropdown && !notifDropdown.contains(e.target) && e.target !== notifBell && !notifBell.contains(e.target)) notifDropdown.classList.remove('open'); });

            // Periodic badge refresh
            setInterval(function() {
                if (notifDropdown && !notifDropdown.classList.contains('open')) {
                    const fd = new FormData(); fd.append('action','get_notifications'); fd.append('limit',1); fd.append('offset',0);
                    fetch(endpoint, { method:'POST', body:fd }).then(r=>r.json()).then(d=>{ if (d && d.unread_count !== undefined) { if (localStorage.getItem(bellClickedKey) !== 'true' && d.unread_count>0) updateBadge(d.unread_count); if (d.unread_count===0) localStorage.removeItem(bellClickedKey); } }).catch(()=>{});
                }
            }, 30000);

        })();
    </script>
    <?php
}
?>
