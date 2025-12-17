<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$first = $_SESSION['firstName'] ?? '';
$last  = $_SESSION['lastName'] ?? '';
$role  = $_SESSION['role'] ?? 'user';

// Strict file detection
$current = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

function isActive($file)
{
    global $current;
    $highlight = $_GET['highlight'] ?? null;

    // 1. Check if the current file matches
    if ($current === $file) {
        return 'active';
    }

    // 2. Check if the URL flag matches the desired file (e.g., 'dashboard' for userDashboard.php)
    if ($file === 'userDashboard.php' && $highlight === 'dashboard') {
        return 'active';
    }

    return '';
}

// Team count must be initialized here, assuming it's loaded in view.php
$teamPendingCountSidebar = $teamPendingCountSidebar ?? 0;
?>

<style>
    .sidebar-dropdown-content {
        display: none;
        background-color: rgba(0, 0, 0, 0.03);
        padding-left: 15px;
        transition: all 0.3s ease;
    }
    .sidebar-dropdown-btn {
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        border: none;
        background: none;
        text-align: left;
        font-family: inherit;
    }
    .dropdown-arrow {
        font-size: 12px;
        transition: transform 0.3s;
    }
    .sidebar-dropdown-content.show {
        display: block;
    }
</style>

<aside class="sidebar">

    <h5 class="mb-3 sidebar-logo"><strong>EcoTrip Challenge</strong></h5>

    <div class="sidebar-nav">

        <div class="sidebar-nav-title">Profile</div>
        <a href="profile.php" class="sidebar-link <?= isActive('profile.php') ?>">
            <iconify-icon icon="solar:user-circle-line-duotone"></iconify-icon>
            My Profile
        </a>

        <?php if ($role === 'admin'): ?>
            <div class="sidebar-nav-title">Admin Management</div>

            <a href="dashboard_admin.php" class="sidebar-link <?= isActive('dashboard_admin.php') ?>">
                <iconify-icon icon="solar:bag-4-line-duotone"></iconify-icon>
                Admin Dashboard
            </a>

            <div class="sidebar-dropdown">
                <div class="sidebar-link sidebar-dropdown-btn" onclick="toggleSidebarGroup('admin-challenge')">
                    <span><iconify-icon icon="solar:list-check-line-duotone"></iconify-icon> Challenges</span>
                    <iconify-icon icon="solar:alt-arrow-down-linear" class="dropdown-arrow"></iconify-icon>
                </div>
                <div id="admin-challenge" class="sidebar-dropdown-content">
                    <a href="view.php" class="sidebar-link <?= isActive('view.php') ?>">View Challenges</a>
                    <a href="manage.php" class="sidebar-link <?= isActive('manage.php') ?>">Manage Challenges</a>
                </div>
            </div>

            <div class="sidebar-dropdown">
                <div class="sidebar-link sidebar-dropdown-btn" onclick="toggleSidebarGroup('admin-users')">
                    <span><iconify-icon icon="solar:users-group-rounded-line-duotone"></iconify-icon> Users & Teams</span>
                    <iconify-icon icon="solar:alt-arrow-down-linear" class="dropdown-arrow"></iconify-icon>
                </div>
                <div id="admin-users" class="sidebar-dropdown-content">
                    <a href="manage_user.php" class="sidebar-link <?= isActive('manage_user.php') ?>">Manage Users</a>
                    <a href="manage_team.php" class="sidebar-link <?= isActive('manage_team.php') ?>">Manage Teams</a>
                    <a href="leaderboard.php" class="sidebar-link <?= isActive('leaderboard.php') ?>">Leaderboard</a>
                </div>
            </div>

            <div class="sidebar-dropdown">
                <div class="sidebar-link sidebar-dropdown-btn" onclick="toggleSidebarGroup('admin-rewards')">
                    <span><iconify-icon icon="solar:gift-line-duotone"></iconify-icon> Rewards Admin</span>
                    <iconify-icon icon="solar:alt-arrow-down-linear" class="dropdown-arrow"></iconify-icon>
                </div>
                <div id="admin-rewards" class="sidebar-dropdown-content">
                    <a href="rewardAdmin.php" class="sidebar-link <?= isActive('rewardAdmin.php') ?>">Reward Settings</a>
                    <a href="reviewRR.php" class="sidebar-link <?= isActive('reviewRR.php') ?>">Review Requests</a>
                    <a href="rewards.php" class="sidebar-link <?= isActive('rewards.php') ?>">View Rewards</a>
                    <a href="adminReward_board.php" class="sidebar-link <?= isActive('adminReward_board.php') ?>">Reward Analysis</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($role === 'moderator'): ?>
            <div class="sidebar-nav-title">Moderator Panel</div>
            <a href="dashboard_moderator.php" class="sidebar-link <?= isActive('dashboard_moderator.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                Moderator Dashboard
            </a>
            <a href="moderator.php" class="sidebar-link <?= isActive('moderator.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                Review Submissions
            </a>
            <a href="view.php" class="sidebar-link <?= isActive('view.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                View Challenges
            </a>
        <?php endif; ?>

        <?php if ($role == 'user'): ?>
            <div class="sidebar-nav-title">User Menu</div>

            <a href="dashboard_user.php" class="sidebar-link <?= isActive('dashboard_user.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                User Dashboard
            </a>

            <div class="sidebar-dropdown">
                <div class="sidebar-link sidebar-dropdown-btn" onclick="toggleSidebarGroup('user-activity')">
                    <span><iconify-icon icon="solar:chart-square-line-duotone"></iconify-icon> My Activity</span>
                    <iconify-icon icon="solar:alt-arrow-down-linear" class="dropdown-arrow"></iconify-icon>
                </div>
                <div id="user-activity" class="sidebar-dropdown-content">
                    <a href="userdashboard.php" class="sidebar-link <?= isActive('userdashboard.php') ?>">Submissions</a>
                    <a href="view.php" class="sidebar-link <?= isActive('view.php') ?>">View Challenges</a>
                    <a href="leaderboard.php" class="sidebar-link <?= isActive('leaderboard.php') ?>">Leaderboard</a>
                </div>
            </div>

            <div class="sidebar-dropdown">
                <div class="sidebar-link sidebar-dropdown-btn" onclick="toggleSidebarGroup('user-rewards')">
                    <span><iconify-icon icon="solar:gift-line-duotone"></iconify-icon> Redemption</span>
                    <iconify-icon icon="solar:alt-arrow-down-linear" class="dropdown-arrow"></iconify-icon>
                </div>
                <div id="user-rewards" class="sidebar-dropdown-content">
                    <a href="rewards.php" class="sidebar-link <?= isActive('rewards.php') ?>">Browse Rewards</a>
                    <a href="userReward_board.php" class="sidebar-link <?= isActive('userReward_board.php') ?>">Reward History</a>
                </div>
            </div>

            <div class="sidebar-dropdown">
                <div class="sidebar-link sidebar-dropdown-btn" onclick="toggleSidebarGroup('user-team')">
                    <span>
                        <iconify-icon icon="solar:users-group-two-rounded-line-duotone"></iconify-icon> Team
                        <?php if ($teamPendingCountSidebar > 0): ?>
                            <span class="badge bg-danger ms-1" style="font-size: 10px; padding: 2px 5px; border-radius: 50%;"><?= $teamPendingCountSidebar ?></span>
                        <?php endif; ?>
                    </span>
                    <iconify-icon icon="solar:alt-arrow-down-linear" class="dropdown-arrow"></iconify-icon>
                </div>
                <div id="user-team" class="sidebar-dropdown-content">
                    <a href="team.php" class="sidebar-link <?= isActive('team.php') ?>">My Team</a>
                    <a href="join_team.php" class="sidebar-link <?= isActive('join_team.php') ?>">Join Team</a>
                    <a href="create_team.php" class="sidebar-link <?= isActive('create_team.php') ?>">Create Team</a>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <div class="sidebar-footer">
        <div class="logged-as-label">Logged in as:</div>
        <div class="logged-as-name">
            <strong><?= htmlspecialchars($first . ' ' . $last) ?></strong>
            <span class="logged-as-role">(<?= htmlspecialchars($role) ?>)</span>
        </div>
    </div>

</aside>

<script>
function toggleSidebarGroup(id) {
    const element = document.getElementById(id);
    const arrow = element.previousElementSibling.querySelector('.dropdown-arrow');
    
    if (element.classList.contains('show')) {
        element.classList.remove('show');
        arrow.style.transform = 'rotate(0deg)';
    } else {
        element.classList.add('show');
        arrow.style.transform = 'rotate(180deg)';
    }
}

// Auto-open dropdown if a child link is active
document.addEventListener("DOMContentLoaded", function() {
    const activeLink = document.querySelector('.sidebar-dropdown-content .active');
    if (activeLink) {
        const parent = activeLink.closest('.sidebar-dropdown-content');
        parent.classList.add('show');
        const arrow = parent.previousElementSibling.querySelector('.dropdown-arrow');
        if(arrow) arrow.style.transform = 'rotate(180deg)';
    }
});
</script>