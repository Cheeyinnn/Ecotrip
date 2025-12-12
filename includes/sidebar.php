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
<aside class="sidebar">

    <h5 class="mb-3 sidebar-logo"><strong>EcoTrip Challenge</strong></h5>

    <div class="sidebar-nav">

    <div class="sidebar-nav-title">Profile</div>

        <a href="profile.php" class="sidebar-link <?= isActive('profile.php') ?>">
            <iconify-icon icon="solar:user-circle-line-duotone"></iconify-icon>
            My Profile
        </a>

        <a href="index.php" class="sidebar-link <?= isActive('index.php') ?>">
            <iconify-icon icon="solar:chart-square-line-duotone"></iconify-icon>
            Dashboard
        </a>

        <?php if ($role === 'admin'): ?>

            <div class="sidebar-nav-title">Dashboards</div>

            <a href="dashboard_admin.php" class="sidebar-link <?= isActive('dashboard_admin.php') ?>">
                <iconify-icon icon="solar:bag-4-line-duotone"></iconify-icon>
                Admin Dashboard
            </a>

            <div class="sidebar-nav-title">EcoTrip</div>

            <a href="view.php" class="sidebar-link <?= isActive('view.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                View Challenges
            </a>

            <a href="manage.php" class="sidebar-link <?= isActive('manage.php') ?>">
                <iconify-icon icon="solar:pen-new-round-line-duotone"></iconify-icon>
                Manage Challenges
            </a>

            <div class="sidebar-nav-title">Admin</div>

            <a href="manage_user.php" class="sidebar-link <?= isActive('manage_user.php') ?>">
                <iconify-icon icon="solar:users-group-rounded-line-duotone"></iconify-icon>
                Manage Users
            </a>

            <a href="manage_team.php" class="sidebar-link <?= isActive('manage_team.php') ?>">
                <iconify-icon icon="solar:users-group-two-rounded-line-duotone"></iconify-icon>
                Manage Teams
            </a>

            <a href="leaderboard.php" class="sidebar-link <?= isActive('leaderboard.php') ?>">
                <iconify-icon icon="solar:cup-star-line-duotone"></iconify-icon>
                Leaderboard
            </a>

            <a href="LbDetail.php" class="sidebar-link <?= isActive('LbDetail.php') ?>">
                <iconify-icon icon="solar:users-group-two-rounded-line-duotone"></iconify-icon>
                lbdetail
            </a>

            <a href="rewardAdmin.php" class="sidebar-link <?= isActive('rewardAdmin.php') ?>">
                <iconify-icon icon="solar:settings-minimalistic-line-duotone"></iconify-icon>
                rewardAdmin
            </a>

            <a href="reviewRR.php" class="sidebar-link <?= isActive('reviewRR.php') ?>">
                <iconify-icon icon="solar:clipboard-check-line-duotone"></iconify-icon>
                reviewRR
            </a>

            <a href="rewards.php" class="sidebar-link <?= isActive('rewards.php') ?>">
                <iconify-icon icon="solar:gift-line-duotone"></iconify-icon>
                rewards
            </a>

        <?php endif; ?>

        <?php if ($role === 'moderator'): ?>

            <div class="sidebar-nav-title">Dashboard</div>

            <a href="dashboard_moderator.php" class="sidebar-link <?= isActive('dashboard_moderator.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                Moderator Dashboard
            </a>

            <div class="sidebar-nav-title">Moderator Panel</div>

            <a href="moderator.php" class="sidebar-link <?= isActive('moderator.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                Review Submissions
            </a>
            
            <div class="sidebar-nav-title">EcoTrip</div>

            <a href="view.php" class="sidebar-link <?= isActive('view.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                View Challenges
            </a>

            <?php endif; ?>


        <?php if ($role == 'user'): ?>

            <div class="sidebar-nav-title">Dashboard</div>

            <a href="dashboard_user.php" class="sidebar-link <?= isActive('dashboard_user.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                User Dashboard
            </a>

            <a href="userdashboard.php" class="sidebar-link <?= isActive('userdashboard.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                Submission Dashboard
            </a>

            <div class="sidebar-nav-title">EcoTrip</div>

            <a href="view.php" class="sidebar-link <?= isActive('view.php') ?>">
                <iconify-icon icon="solar:list-check-line-duotone"></iconify-icon>
                View Challenges
            </a>

            <div class="sidebar-nav-title">Team Management</div>

            <a href="join_team.php" class="sidebar-link <?= isActive('join_team.php') ?>">
                <iconify-icon icon="solar:users-group-two-rounded-line-duotone"></iconify-icon>
                Join Team
            </a>

            <a href="create_team.php" class="sidebar-link <?= isActive('create_team.php') ?>">
                <iconify-icon icon="solar:pen-new-round-line-duotone"></iconify-icon>
                Create Team
            </a>

            <a href="team.php" class="sidebar-link <?= isActive('team.php') ?>">
                <iconify-icon icon="solar:users-group-rounded-line-duotone"></iconify-icon>
                My Team

                <?php if ($teamPendingCountSidebar > 0): ?>
                    <span class="badge bg-danger ms-2"
                          style="font-size: 10px; padding: 3px 6px; border-radius: 10px;">
                        <?= $teamPendingCountSidebar ?>
                    </span>
                <?php endif; ?>

            </a>
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