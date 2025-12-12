<?php
require "db.php"; // Include the database connection file

/* -----------------------------
   1. GET SCOPE (all, weekly, monthly)
------------------------------ */
$scope = isset($_GET['scope']) ? $_GET['scope'] : 'all'; // Default to 'all' if no scope is selected

// New parameters for monthly filter
$currentMonth = date('m');
$currentYear = date('Y');
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;

// Date Filters initialization
$dateFilterUser = "";
$dateFilterTeam = ""; 
$scopeDescription = "";

// weekly = last 7 days
if ($scope == "weekly") {
    $dateFilterUser = "AND t.generate_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $dateFilterTeam = "AND t2.generate_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $scopeDescription = "Week";
}

// monthly = specific month chosen by user
if ($scope == "monthly") {
    // Calculate the start and end timestamps for the selected month/year
    $startDate = date('Y-m-01', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
    $endDate = date('Y-m-t', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)); // 't' gives number of days in the month

    // Adjust SQL filters to use the specific month range
    $dateFilterUser = "AND t.generate_at BETWEEN '$startDate 00:00:00' AND '$endDate 23:59:59'";
    $dateFilterTeam = "AND t2.generate_at BETWEEN '$startDate 00:00:00' AND '$endDate 23:59:59'";
    
    // Set description for the header
    $scopeDescription = date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
}

/* -----------------------------
   2. USER LEADERBOARD (SUM BY TRANSACTIONS OR TOTAL POINTS)
------------------------------ */
if ($scope == "all") {
    // Overall Leaderboard based on totalPoints in the user table
    $user_sql = "
        SELECT 
            u.userID,
            u.firstName,
            u.avatarURL,
            u.totalPoints,
            team.teamName
        FROM user u
        LEFT JOIN team team ON u.teamID = team.teamID
        WHERE u.role = 'member'
        ORDER BY u.totalPoints DESC -- Order by totalPoints from the user table
    ";
} else {
    // Weekly and Monthly Leaderboards based on points earned in pointtransaction table
    $user_sql = "
        SELECT 
            u.userID,
            u.firstName,
            u.avatarURL,
            COALESCE(SUM(t.pointsTransaction), 0) AS totalPoints,
            team.teamName
        FROM user u
        LEFT JOIN pointtransaction t ON u.userID = t.userID AND t.transactionType = 'earn'
        LEFT JOIN team team ON u.teamID = team.teamID
        WHERE u.role = 'member'
            $dateFilterUser
        GROUP BY u.userID, u.firstName, u.avatarURL, team.teamName
        ORDER BY totalPoints DESC -- Order by totalPoints from the pointtransaction table
    ";
}
$user_result = $conn->query($user_sql);

// Separate top 3 users from the rest
$top3Users = [];
$remainingUsers = [];
$rank = 1;
while($row = $user_result->fetch_assoc()) {
    if ($rank <= 3) {
        $top3Users[] = $row;
    } else {
        $remainingUsers[] = $row;
    }
    $rank++;
}


/* -----------------------------
   3. TEAM LEADERBOARD (SUM OF MEMBERS’ POINTS)
------------------------------ */
if ($scope == "all") {
    // Overall Team Leaderboard based on totalPoints in the user table
    $team_sql = "
        SELECT 
            t.teamID,
            t.teamName,
            COALESCE(SUM(u.totalPoints), 0) AS teamTotalPoints,
            COUNT(DISTINCT u.userID) AS memberCount -- Total number of members in the team
        FROM team t
        LEFT JOIN user u ON u.teamID = t.teamID
        WHERE u.role = 'member'
        GROUP BY t.teamID, t.teamName
        ORDER BY teamTotalPoints DESC -- Order by totalPoints of all members in the team
    ";
} else {
    // Weekly or Monthly Team Leaderboard
    $team_sql = "
        SELECT 
            t.teamID,
            t.teamName,
            COALESCE(SUM(t2.pointsTransaction), 0) AS teamTotalPoints,
            
            -- FIX 1: Use a subquery to get the TOTAL member count (unfiltered by transaction date)
            (
                SELECT COUNT(u_sub.userID) 
                FROM user u_sub 
                WHERE u_sub.teamID = t.teamID AND u_sub.role = 'member'
            ) AS memberCount, 
            
            -- FIX 2: Active members are those who appear in the main filtered query
            COUNT(DISTINCT u.userID) AS activeMemberCount 
            
        FROM team t
        LEFT JOIN user u ON u.teamID = t.teamID
        LEFT JOIN pointtransaction t2 ON t2.userID = u.userID AND t2.transactionType = 'earn'
        
        -- The WHERE clause filters transactions for the scope (weekly/monthly).
        -- Because this WHERE clause filters on t2, it effectively makes the join for active members
        -- and points calculation an INNER JOIN.
        WHERE u.role = 'member'
            $dateFilterTeam
        GROUP BY t.teamID, t.teamName
        ORDER BY teamTotalPoints DESC -- Order by total team points
    ";
}

$team_result = $conn->query($team_sql);

// Separate top 3 teams from the rest
$top3Teams = [];
$remainingTeams = [];
$rank = 1;
while($row = $team_result->fetch_assoc()) {
    if ($rank <= 3) {
        $top3Teams[] = $row;
    } else {
        $remainingTeams[] = $row;
    }
    $rank++;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Leaderboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; color: #333; }
        .container {
            width: 90%;
            max-width: 1000px;
            background: #fff;
            margin: 30px auto;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        h2 { text-align: center; margin-bottom: 25px; color: #2c3e50; }

        /* --- Filter & Tabs --- */
        .filter-container { text-align: center; margin-bottom: 25px; }
        .tab-btn, .filter-btn {
            padding: 10px 20px;
            cursor: pointer;
            background: #e9ecef;
            border: none;
            margin: 0 5px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #555;
        }
        .tab-btn:hover, .filter-btn:hover { background: #dbe2e8; }
        .tab-btn.active, .filter-btn.active {
            background: #3498db;
            color: white;
            box-shadow: 0 2px 5px rgba(52, 152, 219, 0.3);
        }
        .month-selector {
            padding: 8px 12px;
            border-radius: 20px;
            border: 2px solid #e9ecef;
            margin-left: 5px;
            font-family: inherit;
            color: #555;
        }

        /* --- Top 3 Podium Design --- */
        .podium-container {
            display: flex;
            justify-content: center;
            align-items: flex-end;
            margin-bottom: 40px;
            padding-top: 20px;
        }
        .podium-item {
            text-align: center;
            padding: 15px;
            margin: 0 10px;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            position: relative;
            width: 130px;
        }
        .podium-item.rank-1 {
            width: 150px;
            padding-top: 25px;
            z-index: 2;
            background: linear-gradient(to bottom, #fff, #f9f9f9);
            border: 2px solid #f1c40f; /* Gold border */
        }
        .podium-item.rank-2 { order: -1; border: 2px solid #bdc3c7; /* Silver border */ }
        .podium-item.rank-3 { border: 2px solid #e67e22; /* Bronze border */ }

        .podium-avatar-container {
            position: relative;
            width: 80px;
            height: 80px;
            margin: 0 auto 10px;
        }
        .rank-1 .podium-avatar-container { width: 100px; height: 100px; }
        .podium-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .rank-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: bold;
            font-size: 14px;
            border: 2px solid #fff;
        }
        .rank-1 .rank-badge { background: #f1c40f; width: 35px; height: 35px; font-size: 16px; top: -8px; right: -8px; }
        .rank-2 .rank-badge { background: #bdc3c7; }
        .rank-3 .rank-badge { background: #e67e22; }

        .podium-name { font-weight: bold; margin: 5px 0; font-size: 1.1em; }
        .podium-points { color: #3498db; font-weight: bold; font-size: 1.2em; }
        .podium-team { font-size: 0.9em; color: #7f8c8d; }

        /* --- Leaderboard Table --- */
        table { width: 100%; border-collapse: separate; border-spacing: 0 8px; margin-top: 10px; }
        th {
            text-align: left;
            padding: 15px;
            color: #7f8c8d;
            font-weight: 600;
            border-bottom: 2px solid #e9ecef;
        }
        td {
            padding: 15px;
            background: #fff;
            vertical-align: middle;
        }
        tr { box-shadow: 0 2px 5px rgba(0,0,0,0.02); transition: transform 0.2s; }
        tr:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
        td:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
        td:last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }

        .table-rank { font-weight: bold; color: #7f8c8d; text-align: center; }
        .table-avatar { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; margin-right: 15px; vertical-align: middle; }
        .table-name { font-weight: 600; }
        .table-points { font-weight: bold; color: #3498db; }
        .table-team { color: #7f8c8d; }

        .hidden { display: none; }
    </style>

    <script>
        function showTab(tab) {
            document.getElementById("userLeaderboard").classList.add("hidden");
            document.getElementById("teamLeaderboard").classList.add("hidden");
            document.getElementById(tab).classList.remove("hidden");

            document.getElementById("btnUsers").classList.remove("active");
            document.getElementById("btnTeams").classList.remove("active");

            document.getElementById("btn" + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add("active");
        }
    </script>
</head>
<body>

<div class="container">
    <h2>🏆 Leaderboard</h2>

    <!-- FILTER BUTTONS -->
    <div class="filter-container">
        <a href="leaderboard.php?scope=all"><button class="filter-btn <?= $scope == 'all' ? 'active' : '' ?>">All-Time</button></a>
        <a href="leaderboard.php?scope=weekly"><button class="filter-btn <?= $scope == 'weekly' ? 'active' : '' ?>">Weekly</button></a>
        
        <form method="get" action="leaderboard.php" style="display:inline-block;">
            <input type="hidden" name="scope" value="monthly">
            <button type="submit" class="filter-btn <?= $scope == 'monthly' ? 'active' : '' ?>">Monthly</button>
            <?php if ($scope == 'monthly'): ?>
                <select name="month" class="month-selector" onchange="this.form.submit()">
                    <?php 
                    $monthNames = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];
                    foreach ($monthNames as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $selectedMonth == $num ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="year" class="month-selector" onchange="this.form.submit()">
                    <?php 
                    $yearRange = range(date('Y') - 1, date('Y')); // Last year and current year
                    foreach ($yearRange as $yearOption): ?>
                        <option value="<?= $yearOption ?>" <?= $selectedYear == $yearOption ? 'selected' : '' ?>><?= $yearOption ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabs -->
    <div style="text-align: center; margin-bottom: 30px;">
        <button id="btnUsers" class="tab-btn active" onclick="showTab('userLeaderboard')">Users</button>
        <button id="btnTeams" class="tab-btn" onclick="showTab('teamLeaderboard')">Teams</button>
    </div>

    <!-- USER LEADERBOARD -->
    <div id="userLeaderboard">
        <!-- Top 3 Podium -->
        <div class="podium-container">
            <?php 
            // Display top 3 users (assuming they are sorted correctly by rank in $top3Users)
            $rank = 1;
            foreach ($top3Users as $user): ?>
                <div class="podium-item rank-<?= $rank ?>">
                    <div class="podium-avatar-container">
                        <img src="<?= $user['avatarURL'] ?? 'default.jpg' ?>" class="podium-avatar">
                        <div class="rank-badge"><?= $rank ?></div>
                    </div>
                    <div class="podium-name"><?= htmlspecialchars($user['firstName']) ?></div>
                    <div class="podium-points"><?= number_format($user['totalPoints']) ?> pts</div>
                    <div class="podium-team"><?= htmlspecialchars($user['teamName'] ?? 'No Team') ?></div>
                </div>
            <?php $rank++; endforeach; ?>
        </div>

        <!-- Remaining Users Table -->
        <table>
            <tr>
                <th style="width: 60px; text-align: center;">Rank</th>
                <th>User</th>
                <th>Team</th>
                <th style="text-align: right;">Points</th>
            </tr>
            <?php $rank = 4; ?>
            <?php foreach ($remainingUsers as $user): ?>
            <tr>
                <td class="table-rank"><?= $rank ?></td>
                <td>
                    <img src="<?= $user['avatarURL'] ?? 'default.jpg' ?>" class="table-avatar">
                    <span class="table-name"><?= htmlspecialchars($user['firstName']) ?></span>
                </td>
                <td class="table-team"><?= htmlspecialchars($user['teamName'] ?? 'No Team') ?></td>
                <td class="table-points" style="text-align: right;"><?= number_format($user['totalPoints']) ?></td>
            </tr>
            <?php $rank++; endforeach; ?>
        </table>
    </div>

    <!-- TEAM LEADERBOARD -->
    <div id="teamLeaderboard" class="hidden">
        <!-- Top 3 Podium for Teams -->
        <div class="podium-container">
            <?php 
            $rank = 1;
            foreach ($top3Teams as $team): ?>
                <div class="podium-item rank-<?= $rank ?>">
                    <div class="podium-avatar-container">
                        <!-- You might want a team avatar here. Using a default icon for now. -->
                        <img src="team_default.png" class="podium-avatar" style="background: #e9ecef; padding: 10px;">
                        <div class="rank-badge"><?= $rank ?></div>
                    </div>
                    <div class="podium-name"><?= htmlspecialchars($team['teamName']) ?></div>
                    <div class="podium-points"><?= number_format($team['teamTotalPoints']) ?> pts</div>
                    <div class="podium-team" style="font-size: 0.85em;">
                        <i class="fas fa-users"></i> <?= $team['memberCount'] ?> Members
                        <?php if ($scope != 'all'): ?>
                            <br><span style="color: #27ae60;">(<?= $team['activeMemberCount'] ?> Active)</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php $rank++; endforeach; ?>
        </div>

        <!-- Remaining Teams Table -->
        <table>
            <tr>
                <th style="width: 60px; text-align: center;">Rank</th>
                <th>Team</th>
                <th>Members</th>
                <th style="text-align: right;">Total Points</th>
            </tr>
            <?php $rank = 4; ?>
            <?php foreach ($remainingTeams as $team): ?>
            <tr>
                <td class="table-rank"><?= $rank ?></td>
                <td><span class="table-name"><?= htmlspecialchars($team['teamName']) ?></span></td>
                <td class="table-team">
                    <i class="fas fa-users"></i> <?= $team['memberCount'] ?>
                    <?php if ($scope != 'all'): ?>
                        <span style="color: #27ae60; font-size: 0.9em;">(<?= $team['activeMemberCount'] ?> Active)</span>
                    <?php endif; ?>
                </td>
                <td class="table-points" style="text-align: right;"><?= number_format($team['teamTotalPoints']) ?></td>
            </tr>
            <?php $rank++; endforeach; ?>
        </table>
    </div>

</div>

</body>
</html>