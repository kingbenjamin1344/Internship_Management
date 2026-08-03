<?php
// admin/notification_component.php
// Reusable notification bell for admin pages

require_once __DIR__ . '/../includes/admin_notifications.php';

function renderAdminNotificationBell($userId) {
    global $pdo;

    $unreadCount = (int)getAdminUnreadNotificationCount($pdo, $userId);

    ?>
    <div class="notif-wrapper">
        <button type="button" class="notif-bell" id="notifBell" aria-label="Notifications">
            <i class="fa-regular fa-bell"></i>
            <!-- Badge: only visible when count > 0, circular shape, neutral color -->
            <span class="notif-badge" id="notifBadge" 
                  style="display: <?php echo $unreadCount > 0 ? 'inline-flex' : 'none'; ?>; 
                         align-items: center; 
                         justify-content: center; 
                         background-color: #e72727 !important; 
                         color: #fff !important; 
                         border-radius: 50% !important; 
                         padding: 0 6px !important; 
                         min-width: 20px; 
                         height: 20px; 
                         font-size: 0.7rem; 
                         font-weight: bold; 
                         margin-left: 2px; 
                         line-height: 1;">
                <?php echo $unreadCount > 0 ? $unreadCount : ''; ?>
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
        (function() {
            const notifBell = document.getElementById('notifBell');
            const notifDropdown = document.getElementById('notifDropdown');
            const notifBadge = document.getElementById('notifBadge');
            const notifList = document.getElementById('notifList');
            const markAllReadBtn = document.getElementById('markAllRead');
            const bellClickedKey = 'notif_bell_clicked_admin_' + <?php echo (int)$userId; ?>;
            const endpoint = window.location.pathname;

            function escapeHtml(text) {
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return String(text || '').replace(/[&<>"']/g, m => map[m]);
            }

            // Update badge – always uses !important to override any CSS
            function updateBadge(count) {
                if (!notifBadge) return;
                const safeCount = Number(count) || 0;
                if (safeCount > 0) {
                    notifBadge.textContent = safeCount;
                    notifBadge.style.setProperty('display', 'inline-flex', 'important');
                } else {
                    notifBadge.textContent = '';
                    notifBadge.style.setProperty('display', 'none', 'important');
                }
            }

            function renderNotifications(notifications) {
                if (!notifList) return;
                if (!notifications || notifications.length === 0) {
                    notifList.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>No notifications yet</p></div>';
                    return;
                }

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
                    const roleBadge = '<span class="notif-role-badge ' + role.toLowerCase() + '">' + role + '</span>';
                    const avatarFile = notif.profile_picture ? String(notif.profile_picture).trim() : '';
                    const avatarPath = avatarFile ? '../assets/uploads/avatars/' + avatarFile.replace(/^\.+\/+|^\/+/, '') : '';
                    
                    let avatarHtml = '';
                    if (avatarPath) {
                        avatarHtml = '<img src="' + escapeHtml(avatarPath) + '" alt="' + escapeHtml(source) + '" title="' + escapeHtml(source) + '">';
                    } else {
                        avatarHtml = '<span class="notif-initials" title="' + escapeHtml(source) + '">' + initials + '</span>';
                    }
                    
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

                document.querySelectorAll('.notif-item').forEach(function(item) {
                    item.addEventListener('click', function(e) {
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

                fetch(endpoint, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            renderNotifications(data.notifications || []);
                            updateBadge(data.unread_count || 0);
                        } else {
                            notifList.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>Unable to load notifications.</p></div>';
                        }
                    }).catch(() => {
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
                    }).catch(() => { if (link && link !== '#') window.location.href = link; });
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
                            const fd2 = new FormData(); fd2.append('action', 'get_notifications'); fd2.append('limit',1); fd2.append('offset',0);
                            return fetch(endpoint, { method: 'POST', body: fd2 });
                        }
                    })
                    .then(resp => resp ? resp.json() : null)
                    .then(j => { if (j && j.unread_count !== undefined) updateBadge(j.unread_count); })
                    .catch(() => {});
            }

            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', function(e) {
                    e.preventDefault(); e.stopPropagation();
                    const fd = new FormData(); fd.append('action','mark_all_read');
                    fetch(endpoint, { method:'POST', body:fd })
                        .then(r=>r.json()).then(data=>{ if (data.success) { document.querySelectorAll('.notif-item.unread').forEach(i=>i.classList.remove('unread')); updateBadge(data.unread_count||0); } });
                });
            }

            if (notifBell) {
                notifBell.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = notifDropdown.classList.contains('open');
                    if (!isOpen) {
                        notifDropdown.classList.add('open');
                        loadNotifications();
                        // Removed the line that hides the badge – it stays consistent
                        localStorage.setItem(bellClickedKey, 'true');
                    } else {
                        notifDropdown.classList.remove('open');
                    }
                });
            }

            // Initial load
            loadNotifications();

            // Click outside to close
            document.addEventListener('click', function(e) { 
                if (notifDropdown && !notifDropdown.contains(e.target) && e.target !== notifBell && !notifBell.contains(e.target)) {
                    notifDropdown.classList.remove('open');
                }
            });

            // Periodic badge refresh (every 30s)
            setInterval(function() {
                if (notifDropdown && !notifDropdown.classList.contains('open')) {
                    const fd = new FormData(); 
                    fd.append('action','get_notifications'); 
                    fd.append('limit',1); 
                    fd.append('offset',0);
                    fetch(endpoint, { method:'POST', body:fd })
                        .then(r=>r.json())
                        .then(d=>{ 
                            if (d && d.unread_count !== undefined) { 
                                if (localStorage.getItem(bellClickedKey) !== 'true' && d.unread_count>0) {
                                    updateBadge(d.unread_count);
                                }
                                if (d.unread_count===0) {
                                    localStorage.removeItem(bellClickedKey);
                                }
                            } 
                        }).catch(()=>{});
                }
            }, 30000);

        })();
    </script>
    <?php
}