<?php
session_start();
require "db_connect.php";

// Enable error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}
$userID = $_SESSION['userID'];

// Fetch Current User
$stmt = $conn->prepare("SELECT firstName, lastName, email, role, avatarURL, teamID FROM user WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();
$currentUser = $result->fetch_assoc();
$userTeamID = $currentUser['teamID'] ?? null; 

// Handle Avatar Path
$avatarPath = 'uploads/default.png'; 
if (!empty($currentUser['avatarURL'])) {
    $rawPath = $currentUser['avatarURL'];
    if (file_exists(__DIR__ . '/' . $rawPath)) {
        $avatarPath = $rawPath;
    } elseif (file_exists(__DIR__ . '/uploads/' . $rawPath)) {
        $avatarPath = 'uploads/' . $rawPath;
    }
}

// --- LEADERBOARD LOGIC ---

$scope = isset($_GET['scope']) ? $_GET['scope'] : 'all';
$currentMonth = date('m'); 
$currentYear = date('Y');
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;

$activeView = isset($_GET['view']) && $_GET['view'] === 'teams' ? 'teams' : 'users';

$dateCondition = ""; 

if ($scope == "weekly") {
    $dateCondition = "AND pt.generate_at >= DATE_SUB(NOW(), INTERVAL WEEKDAY(NOW()) DAY)";
}
if ($scope == "monthly") {
    $startDate = date('Y-m-01', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
    $endDate = date('Y-m-t', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
    $dateCondition = "AND pt.generate_at BETWEEN '$startDate 00:00:00' AND '$endDate 23:59:59'";
}

// --- USER LEADERBOARD QUERY ---
if ($scope == "all") {
    // DIRECT PATH: Read from user.scorePoint
    // Removed specific role check strictly to 'member' to ensure data shows up for testing.
    // If you need strictly members, change WHERE clause to: WHERE u.role = 'member' AND u.scorePoint > 0
    $user_sql = "SELECT 
                    u.userID, 
                    u.firstName, 
                    u.avatarURL, 
                    COALESCE(u.scorePoint, 0) AS scorePoint, 
                    team.teamName 
                 FROM user u 
                 LEFT JOIN team team ON u.teamID = team.teamID 
                 WHERE u.scorePoint > 0 
                 ORDER BY scorePoint DESC, u.firstName ASC";
} else {
    // CALCULATED PATH
    $user_sql = "SELECT 
                    u.userID, 
                    u.firstName, 
                    u.avatarURL, 
                    COALESCE(SUM(pt.pointsTransaction), 0) AS scorePoint, 
                    team.teamName 
                 FROM user u 
                 LEFT JOIN pointtransaction pt ON u.userID = pt.userID AND pt.transactionType = 'earn' $dateCondition
                 LEFT JOIN team team ON u.teamID = team.teamID 
                 GROUP BY u.userID, u.firstName, u.avatarURL, team.teamName 
                 HAVING scorePoint > 0
                 ORDER BY scorePoint DESC, u.firstName ASC";
}

$user_result = $conn->query($user_sql);
$top3Users = []; 
$remainingUsers = []; 
$rank = 1;
$myUserRank = 0; 
$myUserPoints = 0;

if ($user_result) {
    while($row = $user_result->fetch_assoc()) { 
        if ($row['userID'] == $userID) {
            $myUserRank = $rank;
            $myUserPoints = $row['scorePoint'];
        }
        if ($rank <= 3) $top3Users[] = $row; 
        else $remainingUsers[] = $row; 
        $rank++; 
    }
}

// --- TEAM LEADERBOARD QUERY ---
if ($scope == "all") {
    // DIRECT PATH: Sum of user.scorePoint
    $team_sql = "SELECT 
                    t.teamID, 
                    t.teamName, 
                    COALESCE(SUM(u.scorePoint), 0) AS scorePoint, 
                    COUNT(DISTINCT u.userID) AS memberCount 
                 FROM team t 
                 LEFT JOIN user u ON u.teamID = t.teamID
                 GROUP BY t.teamID, t.teamName 
                 HAVING scorePoint > 0
                 ORDER BY scorePoint DESC";
} else {
    // CALCULATED PATH
    $team_sql = "SELECT 
                    t.teamID, 
                    t.teamName, 
                    COALESCE(SUM(pt.pointsTransaction), 0) AS scorePoint, 
                    COUNT(DISTINCT u.userID) AS memberCount 
                 FROM team t 
                 LEFT JOIN user u ON u.teamID = t.teamID
                 LEFT JOIN pointtransaction pt ON pt.userID = u.userID AND pt.transactionType = 'earn' $dateCondition
                 GROUP BY t.teamID, t.teamName 
                 HAVING scorePoint > 0
                 ORDER BY scorePoint DESC";
}

$team_result = $conn->query($team_sql);
$top3Teams = []; 
$remainingTeams = []; 
$rank = 1;
$myTeamRank = 0;
$myTeamPoints = 0;
$myTeamName = "";

if ($team_result) {
    while($row = $team_result->fetch_assoc()) { 
        if ($userTeamID && $row['teamID'] == $userTeamID) {
            $myTeamRank = $rank;
            $myTeamPoints = $row['scorePoint'];
            $myTeamName = $row['teamName'];
        }
        if ($rank <= 3) $top3Teams[] = $row; 
        else $remainingTeams[] = $row; 
        $rank++; 
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leaderboard - EcoTrip</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

        body { margin: 0; background: #f5f7fb; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; }
        .layout-wrapper { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: #ffffff; border-right: 1px solid #e5e9f2; padding: 20px 16px; display: flex; flex-direction: column; }
        .sidebar-brand { font-size: 20px; font-weight: 700; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
        .sidebar-brand iconify-icon { font-size: 24px; color: #16a34a; } 
        .sidebar-nav-title { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; margin-top: 10px; margin-bottom: 4px; }
        .sidebar-nav { list-style: none; padding-left: 0; margin: 0; flex-grow: 1; }
        .sidebar-item { margin-bottom: 4px; }
        .sidebar-link { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 999px; text-decoration: none; font-size: 14px; color: #475569; transition: background 0.15s ease, color 0.15s ease; font-weight: 500; }
        .sidebar-link iconify-icon { font-size: 18px; }
        .sidebar-link:hover { background: #f0fdf4; color: #16a34a; } 
        .sidebar-link.active { background: #dcfce7; color: #15803d; font-weight: 600; } 
        .sidebar-footer { font-size: 12px; color: #64748b; border-top: 1px solid #e5e9f2; padding-top: 10px; margin-top: 12px; }
        .main-content { flex: 1; display: flex; flex-direction: column; }
        .topbar { background: #f5f7fb; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e5e9f2; }
        .nav-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .topbar-icon-btn { width: 34px; height: 34px; border-radius: 50%; border: none; background: #ffffff; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12); cursor: pointer; }
        .content-wrapper { padding: 20px 24px 24px; }
        .lb-container { background: #fff; padding: 25px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }

        .controls-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        
        .segmented-control {
            display: inline-flex;
            background: #f1f5f9; 
            padding: 5px;
            border-radius: 50px; 
            gap: 0;
            border: 1px solid #e2e8f0;
        }
        .segmented-btn {
            padding: 8px 24px;
            border: none;
            background: transparent;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-block;
            line-height: 1.5;
            white-space: nowrap;
        }
        .segmented-btn:hover {
            color: #334155;
        }
        .segmented-btn.active {
            background: #ffffff;
            color: #16a34a; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            font-weight: 700;
        }
        
        .title-wrapper {
            text-align: center;
            margin-bottom: 2rem;
        }
        .leaderboard-title {
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #0f172a 0%, #16a34a 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.03em;
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .leaderboard-subtitle {
            font-size: 0.9rem;
            color: #94a3b8;
            margin-top: 5px;
            font-weight: 500;
        }
        
        .date-filters-wrapper {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .podium-container { display: flex; justify-content: center; align-items: flex-end; margin-bottom: 40px; padding-top: 20px; }
        .podium-item { 
            text-align: center; 
            padding: 15px; 
            margin: 0 10px; 
            background: #fff; 
            border-radius: 20px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.03); 
            position: relative; 
            width: 140px; 
            border: 1px solid #f1f5f9; 
            display: flex;
            flex-direction: column;
            justify-content: flex-end; 
            align-items: center; 
            min-height: 220px; 
            cursor: pointer;
            transition: transform 0.2s;
        }
        .podium-item:hover {
            transform: translateY(-5px);
            border-color: #16a34a;
        }
        
        .podium-item.rank-1 { 
            padding-top: 25px; 
            z-index: 2; 
            background: linear-gradient(to bottom, #ffffff, #f0fdf4); 
            border: 2px solid #fbbf24; 
        } 
        .podium-item.rank-2 { 
            order: -1; 
            border: 2px solid #cbd5e1; 
        } 
        .podium-item.rank-3 { 
            border: 2px solid #fdba74; 
        } 

        .podium-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 4px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 10px; }
        .rank-1 .podium-avatar { width: 100px; height: 100px; }
        
        .podium-name {
            font-weight: bold;
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 4px;
        }
        .podium-points {
            color: #16a34a;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .podium-sub {
            color: #6b7280;
            font-size: 12px;
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .rank-badge { 
            position: absolute; 
            bottom: -5px; 
            left: 50%; 
            transform: translateX(-50%); 
            width: 30px; 
            height: 30px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: #fff; 
            font-weight: bold; 
            border: 2px solid #fff; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            font-size: 14px; 
            z-index: 5;
        }
        .rank-1 .rank-badge { background: #fbbf24; font-size: 16px; width: 34px; height: 34px; bottom: -8px; }
        .rank-2 .rank-badge { background: #94a3b8; }
        .rank-3 .rank-badge { background: #f97316; }

        .crown-icon { position: absolute; top: -40px; left: 50%; transform: translateX(-50%); font-size: 30px; z-index: 3; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1)); }
        .rank-1 .crown-icon { color: #fbbf24; font-size: 38px; top: -48px; }
        .rank-2 .crown-icon { color: #94a3b8; }
        .rank-3 .crown-icon { color: #f97316; }

        .hidden { display: none; }
        
        .current-rank-bar {
            background: linear-gradient(90deg, #22c55e, #15803d);
            border-radius: 50px;
            padding: 15px 25px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            box-shadow: 0 8px 20px rgba(22, 163, 74, 0.25);
            transition: transform 0.2s;
            cursor: pointer; 
        }
        .current-rank-bar:hover {
            transform: scale(1.005);
        }
        .cr-left { display: flex; align-items: center; gap: 15px; }
        .cr-text { font-size: 16px; font-weight: 600; letter-spacing: 0.02em; }
        .cr-rank { font-size: 28px; font-weight: 800; }
        .cr-arrow { background: rgba(255,255,255,0.2); width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; margin-left: 10px; }
        .cr-avatar { width: 45px; height: 45px; border-radius: 50%; border: 2px solid white; object-fit: cover; }

        .leaderboard-list { display: flex; flex-direction: column; gap: 10px; }
        
        .leaderboard-header {
            display: flex;
            align-items: center;
            padding: 0 25px 10px 25px; 
            color: #94a3b8;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .leaderboard-card {
            display: flex;
            align-items: center;
            background: #fff;
            border: 1px solid #f1f5f9;
            border-radius: 16px;
            padding: 15px 25px;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .leaderboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
            border-color: #22c55e;
        }

        .lb-col-rank { width: 60px; text-align: center; margin-right: 15px; font-weight: 800; font-style: italic; color: #1e293b; font-size: 24px; font-family: 'Plus Jakarta Sans', sans-serif; }
        .leaderboard-header .lb-col-rank { font-size: 12px; font-style: normal; font-weight: 700; color: #94a3b8; }
        
        .lb-col-avatar { width: 75px; margin-right: 20px; display: flex; justify-content: center; }
        .lb-avatar { width: 65px; height: 65px; border-radius: 50%; object-fit: cover; border: 2px solid #f8fafc; }
        
        .lb-col-name { flex: 2; font-weight: 700; color: #334155; font-size: 16px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .leaderboard-header .lb-col-name { font-size: 12px; color: #94a3b8; }

        .lb-col-team { flex: 1; color: #64748b; font-weight: 500; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .leaderboard-header .lb-col-team { font-size: 12px; color: #94a3b8; }

        .lb-col-points { width: 140px; text-align: right; font-weight: 800; color: #1e293b; font-size: 20px; }
        .leaderboard-header .lb-col-points { font-size: 12px; font-weight: 700; color: #94a3b8; }
        .lb-points-label { font-size: 12px; font-weight: 600; color: #94a3b8; margin-left: 4px; }

        @media (max-width: 992px) {
            .controls-header { flex-direction: column; gap: 15px; }
            .date-filters-wrapper { position: static; transform: none; }
        }

        @media (max-width: 768px) {
            .lb-col-team { display: none; }
            .leaderboard-header .lb-col-team { display: none; }
        }
        @media (max-width: 576px) {
            .leaderboard-header { display: none; } 
            .leaderboard-card { flex-wrap: wrap; justify-content: space-between; }
            .lb-col-avatar { margin-right: 10px; width: 50px; }
            .lb-col-name { min-width: 120px; }
            .lb-col-rank { font-size: 20px; width: 40px; margin-right: 5px; }
        }
    </style>
    <script>
        function showTab(view) {
            document.getElementById("userLeaderboard").classList.add("hidden");
            document.getElementById("teamLeaderboard").classList.add("hidden");
            
            if (view === 'teams') {
                document.getElementById("teamLeaderboard").classList.remove("hidden");
            } else {
                document.getElementById("userLeaderboard").classList.remove("hidden");
            }

            document.getElementById("btnUsers").classList.remove("active");
            document.getElementById("btnTeams").classList.remove("active");
            document.getElementById("btn" + view.charAt(0).toUpperCase() + view.slice(1)).classList.add("active");

            const url = new URL(window.location);
            url.searchParams.set('view', view);
            window.history.replaceState({}, '', url);

            document.querySelectorAll('.date-filter-link').forEach(link => {
                const currentHref = new URL(link.href, window.location.origin);
                currentHref.searchParams.set('view', view);
                link.href = currentHref.toString();
            });

            const viewInputs = document.querySelectorAll('.view-input-hidden');
            viewInputs.forEach(input => input.value = view);
        }

        function scrollToRank(type, id) {
            const selector = `[data-type="${type}"][data-id="${id}"]`;
            const element = document.querySelector(selector);

            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                element.style.transition = 'all 0.5s';
                const originalTransform = element.style.transform;
                element.style.transform = 'scale(1.05)';
                element.style.boxShadow = '0 0 15px rgba(22, 163, 74, 0.5)';
                setTimeout(() => {
                    element.style.transform = originalTransform;
                    element.style.boxShadow = ''; 
                }, 1500);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            let statsChart = null;

            const modalElement = document.getElementById('detailModal');
            const modal = new bootstrap.Modal(modalElement);

            document.querySelectorAll('.podium-item, .leaderboard-card').forEach(item => {
                item.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const type = this.getAttribute('data-type');
                    const name = this.getAttribute('data-name');
                    
                    document.getElementById('modalTitle').innerText = name + "'s Performance";
                    
                    fetch(`lbDetail.php?id=${id}&type=${type}`)
                        .then(response => response.json())
                        .then(data => {
                            const ctx = document.getElementById('statsChart').getContext('2d');
                            if (statsChart) statsChart.destroy();
                            
                            statsChart = new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: data.labels,
                                    datasets: [{
                                        label: 'Cumulative Points',
                                        data: data.data,
                                        borderColor: '#16a34a',
                                        backgroundColor: 'rgba(22, 163, 74, 0.1)',
                                        fill: true,
                                        tension: 0.3
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    scales: { y: { beginAtZero: true } }
                                }
                            });

                            const listBody = document.getElementById('breakdownList');
                            listBody.innerHTML = '';
                            data.breakdown.forEach(item => {
                                const row = `
                                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                        <div>
                                            <div class="fw-bold text-dark">${item.description}</div>
                                            <div class="text-muted small">${item.date}</div>
                                        </div>
                                        <div class="fw-bold text-success">+${item.points} pts</div>
                                    </div>
                                `;
                                listBody.innerHTML += row;
                            });

                            modal.show();
                        });
                });
            });
        });
    </script>
</head>
<body>
<div class="layout-wrapper">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <iconify-icon icon="solar:shop-2-line-duotone"></iconify-icon>
            <span>EcoTrip Dashboard</span>
        </div>
        <div class="sidebar-nav-title">Dashboards</div>
        <ul class="sidebar-nav">
            <li class="sidebar-item"><a href="index.php" class="sidebar-link"><iconify-icon icon="solar:bag-4-line-duotone"></iconify-icon><span>eCommerce</span></a></li>
            <li class="sidebar-item"><a href="#" class="sidebar-link"><iconify-icon icon="solar:chart-square-line-duotone"></iconify-icon><span>Analytics</span></a></li>
        </ul>
        <div class="sidebar-nav-title">EcoTrip</div>
        <ul class="sidebar-nav">
            <?php if ($_SESSION['role'] !== 'admin'): ?>
                <li class="sidebar-item"><a href="team.php" class="sidebar-link"><iconify-icon icon="solar:users-group-two-rounded-line-duotone"></iconify-icon><span>My Team</span></a></li>
                <li class="sidebar-item"><a href="create_team.php" class="sidebar-link"><iconify-icon icon="solar:user-plus-rounded-line-duotone"></iconify-icon><span>Create Team</span></a></li>
                <li class="sidebar-item"><a href="join_team.php" class="sidebar-link"><iconify-icon icon="solar:login-3-line-duotone"></iconify-icon><span>Join Team</span></a></li>
            <?php endif; ?>
            <li class="sidebar-item"><a href="view.php" class="sidebar-link"><iconify-icon icon="solar:list-check-line-duotone"></iconify-icon><span>View Challenges</span></a></li>
            <li class="sidebar-item"><a href="manage.php" class="sidebar-link"><iconify-icon icon="solar:pen-new-round-line-duotone"></iconify-icon><span>Manage Challenges</span></a></li>
            <li class="sidebar-item"><a href="profile.php" class="sidebar-link"><iconify-icon icon="solar:user-circle-line-duotone"></iconify-icon><span>User Profile</span></a></li>
            <li class="sidebar-item"><a href="rewards.php" class="sidebar-link"><iconify-icon icon="solar:gift-line-duotone"></iconify-icon><span>Reward</span></a></li>
            <li class="sidebar-item"><a href="rewardAdmin.php" class="sidebar-link"><iconify-icon icon="solar:settings-minimalistic-line-duotone"></iconify-icon><span>RewardAdmin</span></a></li>
            <li class="sidebar-item"><a href="leaderboard.php" class="sidebar-link active"><iconify-icon icon="solar:cup-star-line-duotone"></iconify-icon><span>Leaderboard</span></a></li>
            <li class="sidebar-item"><a href="reviewRR.php" class="sidebar-link"><iconify-icon icon="solar:clipboard-check-line-duotone"></iconify-icon><span>ReviewRR</span></a></li>
        </ul>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <div class="sidebar-nav-title">Admin</div>
            <ul class="sidebar-nav">
                <li class="sidebar-item"><a href="manage_user.php" class="sidebar-link"><iconify-icon icon="solar:users-group-rounded-line-duotone"></iconify-icon><span>Manage Users</span></a></li>
                <li class="sidebar-item"><a href="manage_team.php" class="sidebar-link"><iconify-icon icon="solar:users-group-two-rounded-line-duotone"></iconify-icon><span>Manage Teams</span></a></li>
            </ul>
        <?php endif; ?>
        <div class="sidebar-footer mt-auto">
            Logged in as:<br>
            <strong><?php echo htmlspecialchars($currentUser['firstName'] . ' ' . $currentUser['lastName']); ?></strong>
            <br>(<?php echo htmlspecialchars($_SESSION['role']); ?>)
        </div>
    </aside>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-title">Leaderboard</div>
            <div>
                <button class="topbar-icon-btn"><iconify-icon icon="solar:moon-line-duotone"></iconify-icon></button>
                <button class="topbar-icon-btn"><iconify-icon icon="solar:bell-bing-line-duotone"></iconify-icon></button>
                <div class="dropdown d-inline-block">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown"><img src="<?php echo htmlspecialchars($avatarPath); ?>" class="nav-avatar me-1"></a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="content-wrapper">
            <div class="lb-container">
                <div class="title-wrapper">
                    <h2 class="leaderboard-title"><span>🏆</span> Leaderboard</h2>
                    <div class="leaderboard-subtitle">Top performers in our eco-challenge community</div>
                </div>
                
                <div class="controls-header">
                    <div class="segmented-control">
                        <button id="btnUsers" class="segmented-btn <?php echo $activeView !== 'teams' ? 'active' : ''; ?>" onclick="showTab('users')">Users</button>
                        <button id="btnTeams" class="segmented-btn <?php echo $activeView === 'teams' ? 'active' : ''; ?>" onclick="showTab('teams')">Teams</button>
                    </div>
                    <div class="date-filters-wrapper">
                        <div class="segmented-control">
                            <a href="leaderboard.php?scope=all&view=<?php echo $activeView; ?>" class="segmented-btn date-filter-link <?= $scope == 'all' ? 'active' : '' ?>">All-Time</a>
                            <a href="leaderboard.php?scope=weekly&view=<?php echo $activeView; ?>" class="segmented-btn date-filter-link <?= $scope == 'weekly' ? 'active' : '' ?>">Weekly</a>
                            <a href="leaderboard.php?scope=monthly&view=<?php echo $activeView; ?>" class="segmented-btn date-filter-link <?= $scope == 'monthly' ? 'active' : '' ?>">Monthly</a>
                        </div>
                        <?php if ($scope == 'monthly'): ?>
                            <form method="get" action="leaderboard.php" class="d-flex align-items-center gap-2">
                                <input type="hidden" name="scope" value="monthly">
                                <input type="hidden" name="view" class="view-input-hidden" value="<?php echo $activeView; ?>">
                                <select name="month" class="form-select form-select-sm" style="width: auto; border-radius: 20px; border-color: #e2e8f0;" onchange="this.form.submit()">
                                    <?php $monthNames = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec']; foreach ($monthNames as $num => $name): ?>
                                        <option value="<?= $num ?>" <?= $selectedMonth == $num ? 'selected' : '' ?>><?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="year" class="form-select form-select-sm" style="width: auto; border-radius: 20px; border-color: #e2e8f0;" onchange="this.form.submit()">
                                    <?php $yearRange = range(date('Y') - 1, date('Y')); foreach ($yearRange as $yearOption): ?>
                                        <option value="<?= $yearOption ?>" <?= $selectedYear == $yearOption ? 'selected' : '' ?>><?= $yearOption ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div style="width: 100px;"></div>
                </div>

                <div id="userLeaderboard" class="<?php echo $activeView === 'teams' ? 'hidden' : ''; ?>">
                    <div class="podium-container">
                        <?php $rank = 1; foreach ($top3Users as $user): ?>
                            <div class="podium-item rank-<?= $rank ?>" data-id="<?= $user['userID'] ?>" data-type="user" data-name="<?= htmlspecialchars($user['firstName']) ?>">
                                <div class="position-relative d-inline-block mb-2">
                                    <i class="fas fa-crown crown-icon"></i>
                                    <img src="<?= $user['avatarURL'] ?? 'uploads/default.png' ?>" class="podium-avatar">
                                    <div class="rank-badge"><?= $rank ?></div>
                                </div>
                                <div class="podium-name"><?= htmlspecialchars($user['firstName']) ?></div>
                                <div class="podium-points"><?= number_format($user['scorePoint']) ?> pts</div>
                                <div class="podium-sub"><?= htmlspecialchars($user['teamName'] ?? '-') ?></div>
                            </div>
                        <?php $rank++; endforeach; ?>
                    </div>

                    <?php if ($myUserRank > 0): ?>
                    <div class="current-rank-bar" onclick="scrollToRank('user', <?= $userID ?>)">
                        <div class="cr-left">
                            <img src="<?php echo htmlspecialchars($avatarPath); ?>" class="cr-avatar">
                            <span class="cr-text">You Currently Rank</span>
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="cr-text me-3" style="opacity:0.9;"><?php echo number_format($myUserPoints); ?> pts</span>
                            <span class="cr-rank"><?php echo $myUserRank; ?></span>
                            <div class="cr-arrow"><i class="fas fa-caret-up"></i></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="leaderboard-header">
                        <div class="lb-col-rank">Rank</div>
                        <div class="lb-col-avatar"></div>
                        <div class="lb-col-name">User</div>
                        <div class="lb-col-team">Team</div>
                        <div class="lb-col-points text-end">Points</div>
                    </div>

                    <div class="leaderboard-list">
                        <?php $rank = 4; foreach ($remainingUsers as $user): ?>
                            <div class="leaderboard-card" data-id="<?= $user['userID'] ?>" data-type="user" data-name="<?= htmlspecialchars($user['firstName']) ?>">
                                <div class="lb-col-rank"><?= $rank ?></div>
                                <div class="lb-col-avatar">
                                    <img src="<?= $user['avatarURL'] ?? 'uploads/default.png' ?>" class="lb-avatar">
                                </div>
                                <div class="lb-col-name"><?= htmlspecialchars($user['firstName']) ?></div>
                                <div class="lb-col-team"><?= htmlspecialchars($user['teamName'] ?? '-') ?></div>
                                <div class="lb-col-points">
                                    <?= number_format($user['scorePoint']) ?><span class="lb-points-label">pts</span>
                                </div>
                            </div>
                        <?php $rank++; endforeach; ?>
                    </div>
                </div>

                <div id="teamLeaderboard" class="<?php echo $activeView !== 'teams' ? 'hidden' : ''; ?>">
                    <div class="podium-container">
                        <?php $rank = 1; foreach ($top3Teams as $team): ?>
                            <div class="podium-item rank-<?= $rank ?>" data-id="<?= $team['teamID'] ?>" data-type="team" data-name="<?= htmlspecialchars($team['teamName']) ?>">
                                <div class="position-relative d-inline-block mb-2">
                                    <i class="fas fa-crown crown-icon"></i>
                                    <div class="podium-avatar d-flex align-items-center justify-content-center bg-light"><i class="fas fa-users fa-2x text-secondary"></i></div>
                                    <div class="rank-badge"><?= $rank ?></div>
                                </div>
                                <div class="podium-name"><?= htmlspecialchars($team['teamName']) ?></div>
                                <div class="podium-points"><?= number_format($team['scorePoint']) ?> pts</div>
                                <div class="podium-sub"><?= $team['memberCount'] ?> Members</div>
                            </div>
                        <?php $rank++; endforeach; ?>
                    </div>

                    <?php if ($myTeamRank > 0): ?>
                    <div class="current-rank-bar" style="background: linear-gradient(90deg, #ff8c00, #ff4500);" onclick="scrollToRank('team', <?= $userTeamID ?>)">
                        <div class="cr-left">
                            <div class="cr-avatar d-flex align-items-center justify-content-center bg-white text-dark"><i class="fas fa-users"></i></div>
                            <span class="cr-text">Your Team Ranks</span>
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="cr-text me-3" style="opacity:0.9;"><?php echo number_format($myTeamPoints); ?> pts</span>
                            <span class="cr-rank"><?php echo $myTeamRank; ?></span>
                            <div class="cr-arrow"><i class="fas fa-caret-up"></i></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="leaderboard-header">
                        <div class="lb-col-rank">Rank</div>
                        <div class="lb-col-avatar"></div>
                        <div class="lb-col-name">Team Name</div>
                        <div class="lb-col-team">Members</div>
                        <div class="lb-col-points text-end">Points</div>
                    </div>

                    <div class="leaderboard-list">
                        <?php $rank = 4; foreach ($remainingTeams as $team): ?>
                            <div class="leaderboard-card" data-id="<?= $team['teamID'] ?>" data-type="team" data-name="<?= htmlspecialchars($team['teamName']) ?>">
                                <div class="lb-col-rank"><?= $rank ?></div>
                                <div class="lb-col-avatar">
                                    <div class="lb-avatar d-flex align-items-center justify-content-center bg-light" style="font-size: 24px;"><i class="fas fa-users text-secondary"></i></div>
                                </div>
                                <div class="lb-col-name"><?= htmlspecialchars($team['teamName']) ?></div>
                                <div class="lb-col-team"><?= $team['memberCount'] ?> Members</div>
                                <div class="lb-col-points">
                                    <?= number_format($team['scorePoint']) ?><span class="lb-points-label">pts</span>
                                </div>
                            </div>
                        <?php $rank++; endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 bg-light rounded-top-4">
        <h5 class="modal-title fw-bold" id="modalTitle">Performance</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <h6 class="text-muted text-uppercase small fw-bold mb-3">Points Earned Over Time</h6>
        <div style="height: 250px;" class="mb-4">
            <canvas id="statsChart"></canvas>
        </div>
        
        <h6 class="text-muted text-uppercase small fw-bold mb-3">Recent Activity Breakdown</h6>
        <div id="breakdownList">
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>