<?php
// Use $pageTitle from the page, fallback
$title = $pageTitle ?? "EcoTrip Dashboard";

// $user, $avatarPath, $notiCount are already loaded in layout_start.php

// Load latest 10 notifications
$notifications = [];

$stmtNoti = $conn->prepare("
    SELECT id, message, link, created_at, is_read
    FROM notifications
    WHERE userID = ?
    ORDER BY id DESC
    LIMIT 10
");
$stmtNoti->bind_param("i", $_SESSION['userID']);
$stmtNoti->execute();
$resultNoti = $stmtNoti->get_result();

while ($row = $resultNoti->fetch_assoc()) {
    $notifications[] = $row;
}

$stmtNoti->close();
?>

<div class="topbar d-flex justify-content-between align-items-center">

    <div class="topbar-title">
        <?= htmlspecialchars($title) ?>
    </div>

    <div class="d-flex align-items-center gap-4">

        <!-- ====================== NOTIFICATION BELL ====================== -->
        <div class="dropdown">
            <button class="btn position-relative"
                    id="notiBell"
                    type="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">

                <!-- Bell Icon -->
                <i class="bi bi-bell-fill fs-4"></i>

                <!-- Unread Badge -->
                <?php if (!empty($notiCount) && $notiCount > 0): ?>
                    <span id="notiBadge"
                          class="badge bg-danger position-absolute top-0 end-0">
                        <?= $notiCount ?>
                    </span>
                <?php endif; ?>
            </button>

            <!-- ⭐ Add unique ID so JS updates the correct dropdown -->
            <ul id="notificationList"
                class="dropdown-menu dropdown-menu-end p-2 shadow"
                style="width: 320px; max-height: 400px; overflow-y: auto;">

                <li class="dropdown-header fw-bold d-flex justify-content-between align-items-center">
                    <span>Notifications</span>
                </li>
                <li><hr class="dropdown-divider"></li>

                <?php if (empty($notifications)): ?>
                    <li class="text-center text-muted small p-2">
                        No notifications
                    </li>

                <?php else: ?>
                    <?php foreach ($notifications as $n): ?>
                        <li>
                            <a class="dropdown-item small d-block"
                               href="<?= htmlspecialchars($n['link'] ?: 'index.php') ?>">

                                <?= htmlspecialchars($n['message']) ?><br>

                                <span class="text-muted small">
                                    <?= date("M d, h:i A", strtotime($n['created_at'])) ?>
                                </span>

                                <?php if ($n['is_read'] == 0): ?>
                                    <span class="badge bg-danger ms-2 noti-new-badge">New</span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                    <?php endforeach; ?>
                <?php endif; ?>

            </ul>
        </div>

        <!-- ====================== USER AVATAR DROPDOWN ====================== -->
        <div class="dropdown">
            <a href="#" data-bs-toggle="dropdown">
                <img src="<?= htmlspecialchars($avatarPath) ?>" class="nav-avatar">
            </a>

            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-header">
                    <?= htmlspecialchars($user['firstName'] ?? '') ?>
                </li>
                <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="logout.php">Logout</a></li>
            </ul>
        </div>

    </div>
</div>


<!-- JS: Mark all notifications as read when clicking the bell -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    const bell  = document.getElementById("notiBell");
    const badge = document.getElementById("notiBadge");

    if (!bell) return;

    bell.addEventListener("click", function () {

        fetch("includes/read_all.php")
            .then(res => res.ok ? res.json() : null)
            .then(data => {
                if (badge) {
                    badge.style.display = "none";
                }
                document.querySelectorAll(".noti-new-badge").forEach(el => {
                    el.style.display = "none";
                });
            })
            .catch(err => {
                console.error("Notification mark read error:", err);
            });
    });
});
</script>


<!-- ⭐ REAL-TIME NOTIFICATION REFRESH -->
<script>
function refreshNotifications() {
    fetch("includes/fetch_notifications.php")
    .then(res => res.json())
    .then(data => {
        if (!data.ok) return;

        const badge = document.getElementById("notiBadge");
        const menu  = document.getElementById("notificationList");

        if (!menu) return;

        // Update unread badge
        if (data.unread > 0) {
            if (badge) {
                badge.innerText = data.unread;
                badge.style.display = "inline-block";
            }
        } else if (badge) {
            badge.style.display = "none";
        }

        // Render notifications into dropdown
        let html = `
            <li class="dropdown-header fw-bold d-flex justify-content-between align-items-center">
                <span>Notifications</span>
            </li>
            <li><hr class="dropdown-divider"></li>
        `;

        if (data.list.length === 0) {
            html += `<li class="text-center text-muted small p-2">No notifications</li>`;
        } else {
            data.list.forEach(n => {
                html += `
                    <li>
                        <a class="dropdown-item small d-block"
                           href="${n.link ?? 'index.php'}">

                            ${n.message}<br>

                            <span class="text-muted small">
                                ${new Date(n.created_at).toLocaleString()}
                            </span>

                            ${n.is_read == 0 
                                ? '<span class="badge bg-danger ms-2 noti-new-badge">New</span>' 
                                : ''}
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                `;
            });
        }

        menu.innerHTML = html;
    })
    .catch(err => console.error("Notification refresh error:", err));
}

// Refresh every 5 seconds (real-time)
setInterval(refreshNotifications, 5000);
</script>
