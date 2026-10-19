<?php
// notification_bell.php – Renders the notification bell and dropdown
include_once 'config.php';

if (!isset($pdo) || !isset($_SESSION['user_id'])) {
    return; // nothing to show
}

$userId = (int)$_SESSION['user_id'];
$unreadCount = getUnreadNotificationCount($pdo, $userId);
?>
<div class="notification-bell" id="notificationBell">
    <button class="bell-btn" onclick="toggleNotifications()" aria-label="Notifications">
        <span class="bell-icon">🔔</span>
        <?php if ($unreadCount > 0): ?>
            <span class="badge" id="notifBadge"><?= $unreadCount ?></span>
        <?php endif; ?>
    </button>
    <div class="notif-dropdown" id="notifDropdown" style="display:none;">
        <div class="notif-header">
            <span>Notifications</span>
            <button class="mark-all-read" onclick="markAllRead()">Mark all as read</button>
        </div>
        <div class="notif-list" id="notifList">
            <div class="notif-loading">Loading...</div>
        </div>
    </div>
</div>

<style>
/* Notification Bell - Header */
.notification-bell {
    position: relative;
    display: inline-block;
    margin-left: 15px; /* space from the date */
    vertical-align: middle;
}

.bell-btn {
    background: none;
    border: none;
    color: #555;          /* adjust to match header text */
    font-size: 22px;
    cursor: pointer;
    position: relative;
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 6px;
    transition: color 0.2s;
}

.bell-btn:hover {
    color: #000;
}

.badge {
    background: #c00;
    color: #fff;
    font-size: 11px;
    font-weight: bold;
    border-radius: 50%;
    padding: 1px 7px;
    margin-left: 2px;
    line-height: 1.4;
}

/* Dropdown - aligned to the right */
.notif-dropdown {
    position: absolute;
    top: 40px;
    right: 0;            /* align to the right edge of the bell */
    left: auto;          /* override previous left */
    background: #fff;
    color: #222;
    border-radius: 8px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    z-index: 999;
    min-width: 280px;
    max-width: 400px;
    max-height: 400px;
    overflow: hidden;
    display: none;
}

.notif-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid #eee;
    font-weight: bold;
    background: #f9f9f9;
}

.mark-all-read {
    background: none;
    border: none;
    color: #c00;
    font-size: 12px;
    cursor: pointer;
    text-decoration: underline;
}
.mark-all-read:hover {
    color: #a00;
}

.notif-list {
    overflow-y: auto;
    max-height: 300px;
}

.notif-item {
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
}
.notif-item.unread {
    background: #f8f8ff;
    border-left: 3px solid #c00;
}
.notif-item .notif-text {
    flex: 1;
    font-size: 13px;
    line-height: 1.4;
}
.notif-item .notif-time {
    font-size: 11px;
    color: #999;
    white-space: nowrap;
}
.mark-read-btn {
    background: none;
    border: none;
    color: #007bff;
    font-size: 12px;
    cursor: pointer;
    padding: 0 4px;
}
.mark-read-btn:hover {
    text-decoration: underline;
}
.no-notif {
    padding: 20px;
    text-align: center;
    color: #888;
}
.notif-loading {
    padding: 20px;
    text-align: center;
    color: #888;
}

/* Responsive: dropdown width on small screens */
@media (max-width: 768px) {
    .notif-dropdown {
        right: -10px;
        min-width: auto;
        width: calc(100vw - 40px);
        max-width: 400px;
    }
}
</style>

<script>
// Notification JavaScript (unchanged)
let dropdownOpen = false;

function toggleNotifications() {
    if (dropdownOpen) {
        closeDropdown();
    } else {
        openDropdown();
    }
}

function openDropdown() {
    const dropdown = document.getElementById('notifDropdown');
    dropdown.style.display = 'block';
    dropdownOpen = true;
    fetchNotifications();
    document.addEventListener('click', outsideClickListener);
}

function closeDropdown() {
    document.getElementById('notifDropdown').style.display = 'none';
    dropdownOpen = false;
    document.removeEventListener('click', outsideClickListener);
}

function outsideClickListener(e) {
    const bell = document.getElementById('notificationBell');
    if (!bell.contains(e.target)) {
        closeDropdown();
    }
}

function fetchNotifications() {
    const list = document.getElementById('notifList');
    list.innerHTML = '<div class="notif-loading">Loading...</div>';

    fetch('notification_ajax.php?action=get&t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderNotifications(data.notifications, data.unread_count);
            } else {
                list.innerHTML = '<div class="no-notif">Could not load notifications.</div>';
            }
        })
        .catch(() => {
            list.innerHTML = '<div class="no-notif">Error loading notifications.</div>';
        });
}

function renderNotifications(notifications, unreadCount) {
    const list = document.getElementById('notifList');
    const badge = document.getElementById('notifBadge');

    if (unreadCount > 0) {
        if (!badge) {
            const btn = document.querySelector('.bell-btn');
            const span = document.createElement('span');
            span.className = 'badge';
            span.id = 'notifBadge';
            span.textContent = unreadCount;
            btn.appendChild(span);
        } else {
            badge.textContent = unreadCount;
        }
    } else {
        const b = document.getElementById('notifBadge');
        if (b) b.remove();
    }

    if (!notifications || notifications.length === 0) {
        list.innerHTML = '<div class="no-notif">No notifications.</div>';
        return;
    }

    let html = '';
    notifications.forEach(n => {
        const isUnread = !n.is_read;
        const time = new Date(n.created_at).toLocaleString();
        html += `
            <div class="notif-item ${isUnread ? 'unread' : ''}" data-id="${n.id}">
                <div class="notif-text">${escapeHtml(n.message)}</div>
                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
                    <span class="notif-time">${time}</span>
                    ${isUnread ? `<button class="mark-read-btn" onclick="markRead(${n.id})">✔ Mark read</button>` : ''}
                </div>
            </div>
        `;
    });
    list.innerHTML = html;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function markRead(notificationId) {
    fetch('notification_ajax.php?action=mark_read', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + notificationId
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            fetchNotifications();
        }
    });
}

function markAllRead() {
    if (!confirm('Mark all notifications as read?')) return;
    fetch('notification_ajax.php?action=mark_all_read', { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                fetchNotifications();
            }
        });
}
</script>