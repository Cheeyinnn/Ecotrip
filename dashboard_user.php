<?php

session_start();

if (!isset($_SESSION['userID'])) { 
    header("Location: login.php"); 
    exit(); 
}
$userID = $_SESSION['userID'];

include("db_connect.php");

// Time filter
$timeFilter = $_GET['time'] ?? 'all';


$days = null;
$subTimeCondition = '';
$redeemTimeCondition = '';


if ($timeFilter === '7') {
    $days = 7;
    $subTimeCondition = " AND uploaded_at >= NOW() - INTERVAL 7 DAY";
    $redeemTimeCondition = " AND requested_at >= NOW() - INTERVAL 7 DAY";
} elseif ($timeFilter === '30') {
    $days = 30;
    $subTimeCondition = " AND uploaded_at >= NOW() - INTERVAL 30 DAY";
    $redeemTimeCondition = " AND requested_at >= NOW() - INTERVAL 30 DAY";
} elseif ($timeFilter === 'today') {
    $subTimeCondition = " AND DATE(uploaded_at) = CURDATE()";
    $redeemTimeCondition = " AND DATE(requested_at) = CURDATE()";
} else {
    // all time
    $subTimeCondition = '';
    $redeemTimeCondition = '';
}

// ============================

// User participated challenge count
$sql_challenge_count = "
    SELECT COUNT(DISTINCT challengeID) AS challengeCount
    FROM sub
    WHERE userID = ? $subTimeCondition
";
$stmt = $conn->prepare($sql_challenge_count);
$stmt->bind_param("i", $userID);
$stmt->execute();
$challengeCount = $stmt->get_result()->fetch_assoc()['challengeCount'] ?? 0;
$stmt->close();

// ============================
// Fetch points (used for myPoints card)
$sql_points = "SELECT walletPoint AS totalPoints FROM user WHERE userID = ?";
$stmt_points = $conn->prepare($sql_points);
$stmt_points->bind_param("i", $userID);
$stmt_points->execute();
$result = $stmt_points->get_result();
$myPoints = ($row = $result->fetch_assoc()) ? (int)$row['totalPoints'] : 0;
$stmt_points->close();


// ============================

// User Rank
$rankStmt = $conn->prepare("
    SELECT COUNT(*) + 1 AS rank 
    FROM user 
    WHERE walletPoint > ?
");
$rankStmt->bind_param("i", $currentPoints); 
$rankStmt->execute();
$userRank = $rankStmt->get_result()->fetch_assoc()['rank'];
$rankStmt->close();



// ============================
// Submission counts
$sql_subs = "SELECT 
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approvedCount,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pendingCount,
        SUM(CASE WHEN status = 'Denied' THEN 1 ELSE 0 END) AS deniedCount,
        COUNT(submissionID) AS totalSubmission
    FROM sub
    WHERE userID = ? $subTimeCondition";

$stmt_subs = $conn->prepare($sql_subs);
$stmt_subs->bind_param("i", $userID);
$stmt_subs->execute();
$res_subs = $stmt_subs->get_result()->fetch_assoc();

$approvedCount = $res_subs['approvedCount'] ?? 0;
$pendingCount = $res_subs['pendingCount'] ?? 0;
$deniedCount = $res_subs['deniedCount'] ?? 0;
$totalSubmission = $res_subs['totalSubmission'] ?? 0;
$stmt_subs->close();

// ============================
// Submission details
$submissionDetails = [];

$sql_sub_details = "
    SELECT s.status, s.pointEarned, s.reviewNote, s.uploaded_at, c.challengeTitle
    FROM sub s
    JOIN challenge c ON s.challengeID = c.challengeID
    WHERE s.userID = ? $subTimeCondition
    ORDER BY s.uploaded_at DESC
    LIMIT 20
";

$stmt_sub_details = $conn->prepare($sql_sub_details);
$stmt_sub_details->bind_param("i", $userID);
$stmt_sub_details->execute();
$res_sub_details = $stmt_sub_details->get_result();

while($row = $res_sub_details->fetch_assoc()){
    $submissionDetails[] = $row;
}

$stmt_sub_details->close();

// ============================
// Challenge section
$sql_challenges = "
    SELECT 
        c.challengeID,
        c.challengeTitle,
        SUM(s.pointEarned) AS totalPoints,
        COUNT(s.submissionID) AS approvedSubmissions
    FROM sub s
    JOIN challenge c ON s.challengeID = c.challengeID
    WHERE s.userID = ? 
      AND s.status = 'Approved'
      $subTimeCondition
    GROUP BY c.challengeID, c.challengeTitle
    ORDER BY SUM(s.pointEarned) DESC
";

$stmt = $conn->prepare($sql_challenges);
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();

$userChallenges = [];
while ($row = $result->fetch_assoc()) {
    $userChallenges[] = [
        'name'   => $row['challengeTitle'],
        'points' => (int)$row['totalPoints'],
        'times'  => (int)$row['approvedSubmissions'],
        'status' => 'Completed'
    ];
}
$stmt->close();

// ============================
// Reward section
// User team
$sql_user_team = "
    SELECT u.teamID, t.teamName 
    FROM user u 
    LEFT JOIN team t ON u.teamID = t.teamID 
    WHERE u.userID = ?
";
$stmt = $conn->prepare($sql_user_team);
$stmt->bind_param("i", $userID);
$stmt->execute();
$userTeamInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();

$teamID = $userTeamInfo['teamID'] ?? null;
$teamName = $userTeamInfo['teamName'] ?? null;

// ============================
// User summary stats 
$sql_current_points = "
    SELECT 
        COALESCE((SELECT SUM(pointEarned) FROM sub WHERE userID = ? $subTimeCondition),0) AS earnedPoints,
        COALESCE((SELECT SUM(pointSpent) FROM redemptionrequest WHERE userID = ? $redeemTimeCondition),0) AS spentPoints,
        COALESCE((SELECT COUNT(*) FROM redemptionrequest WHERE userID = ? $redeemTimeCondition),0) AS totalRedeemed
";
$stmt = $conn->prepare($sql_current_points);
$stmt->bind_param("iii", $userID, $userID, $userID);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$currentPoints = (int)$summary['earnedPoints'] - (int)$summary['spentPoints'];
$totalSpent = (int)$summary['spentPoints'];
$totalRedeemed = (int)$summary['totalRedeemed'];

// ============================
// Points Spent Trend
$trendSql = "SELECT DATE(requested_at) AS date, SUM(pointSpent) AS total 
             FROM redemptionrequest 
             WHERE userID = ? $redeemTimeCondition
             GROUP BY DATE(requested_at)
             ORDER BY DATE(requested_at) ASC";

$stmt = $conn->prepare($trendSql);
$stmt->bind_param("i", $userID);
$stmt->execute();
$trendRes = $stmt->get_result();

$trendLabels = [];
$trendData = [];
while($row = $trendRes->fetch_assoc()){
    $trendLabels[] = $row['date'];
    $trendData[] = (int)$row['total'];
}
$stmt->close();

// ============================
// Category distribution
$catLabels = [];
$catData = [];
$catSql = "
    SELECT r.rewardName, r.imageURL, COUNT(*) AS count
    FROM redemptionrequest rr
    JOIN reward r ON rr.rewardID = r.rewardID
    WHERE rr.userID = ? 
      AND rr.status = 'Approved'
      $redeemTimeCondition
    GROUP BY r.rewardName, r.imageURL
    ORDER BY count DESC
";
$stmt = $conn->prepare($catSql);
$stmt->bind_param("i", $userID);
$stmt->execute();
$catRes = $stmt->get_result();
while($row = $catRes->fetch_assoc()){
    $catLabels[] = $row['rewardName'];
    $catData[] = (int)$row['count'];
}
$stmt->close();

// ============================
// Recent activity
$recentActivity = [];
$timeCondition = $redeemTimeCondition; 
$recentSql = "
    SELECT rr.*, r.rewardName, r.imageURL, r.category
    FROM redemptionrequest rr
    JOIN reward r ON rr.rewardID = r.rewardID
    WHERE rr.userID = ? $timeCondition
    ORDER BY rr.requested_at DESC
    LIMIT 5
";
$stmt = $conn->prepare($recentSql);
$stmt->bind_param("i", $userID);
$stmt->execute();
$recentRes = $stmt->get_result();
while($row = $recentRes->fetch_assoc()){
    $recentActivity[] = $row;
}
$stmt->close();

// ============================
// Personal Team rank section
$userHasTeam = false;
$teamRankMessage = '';
$teamRank = [];
$personalRank = null;
$teamTotalPoint = 0;

if ($teamID === null) {
    $teamRankMessage = "You are not part of any team yet. Join a team !";
} else {
    $userHasTeam = true;

    $sql_leaderboard_wallet = "
        SELECT 
            u.userID,
            u.firstName,
            u.lastName,
            u.walletPoint AS totalPoints,
            (SELECT SUM(walletPoint) FROM user WHERE teamID = ?) AS teamTotalPoints
        FROM user u
        WHERE u.teamID = ?
        ORDER BY u.walletPoint DESC
    ";

    $stmt2 = $conn->prepare($sql_leaderboard_wallet);
    $stmt2->bind_param("ii", $teamID, $teamID);
    $stmt2->execute();
    $res = $stmt2->get_result();

    $rankCounter = 1;
    while ($row = $res->fetch_assoc()) {
        $fullName = $row['firstName'] . ' ' . substr($row['lastName'],0,1) . '.';
        $name = ($row['userID'] == $userID) ? 'You' : $fullName;

        $teamRank[] = [
            'name' => $name,
            'value' => (int)($row['totalPoints'] ?? 0)
        ];

        if ($row['userID'] == $userID) {
            $personalRank = $rankCounter;
        }

        if ($rankCounter === 1) {
            $teamTotalPoint = (int)($row['teamTotalPoints'] ?? 0);
        }

        $rankCounter++;
    }

    $stmt2->close();
}

// ============================
// Team Overall Rank
$myTeamRank = 0; 
$myTeamPoints = 0;
$myTeamName = $teamName ?? '';

if ($teamID !== null) {

    $dateCondition = '';
    if ($timeFilter === '7') {
        $dateCondition = " AND pt.generate_at >= NOW() - INTERVAL 7 DAY";
    } elseif ($timeFilter === '30') {
        $dateCondition = " AND pt.generate_at >= NOW() - INTERVAL 30 DAY";
    } elseif ($timeFilter === 'today') {
        $dateCondition = " AND DATE(pt.generate_at) = CURDATE()";
    }

    
    $team_sql = "
        SELECT t.teamID, COALESCE(SUM(pt.pointsTransaction),0) AS teamPoints
        FROM team t
        LEFT JOIN user u ON u.teamID = t.teamID
        LEFT JOIN pointtransaction pt ON pt.userID = u.userID AND pt.transactionType = 'earn' $dateCondition
        GROUP BY t.teamID
        ORDER BY teamPoints DESC
    ";

    $team_result = $conn->query($team_sql);
    $rankCounter = 1;

    if ($team_result) {
        while ($row = $team_result->fetch_assoc()) {
            if ($row['teamID'] == $teamID) {
                $myTeamRank = $rankCounter; 
                $myTeamPoints = $row['teamPoints'];
                break; 
            }
            $rankCounter++;
        }
    }
}


$submissionDetailsJson = json_encode($submissionDetails);
$trendLabelsJson = json_encode($trendLabels ?? []);
$trendDataJson = json_encode($trendData ?? []);
$catLabelsJson = json_encode($catLabels ?? []);
$catDataJson = json_encode($catData ?? []);

include "includes/layout_start.php";

?>





    <script src="https://res.gemcoder.com/js/reload.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <script src="https://cdn.bootcdn.net/ajax/libs/echarts/5.4.3/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

<style>
    /* Base styles */
    body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
    
    /* Eco-Themed Colors */
    .eco-primary { color: #10b981; } /* Emerald Green */
    .eco-bg-primary { background-color: #10b981; }
    .eco-secondary { color: #06b6d4; } /* Cyan */
    .eco-text-point { color: #f59e0b; } /* Amber/Gold for Points */

    /* Card Styles */
    .card-eco {
        background: white;
        border-radius: 16px;
        padding: 24px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
        height: 100%;
    }
    .card-eco:hover { 
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        transform: translateY(-2px);
    }
    
    /* Stats Styling */
    .stat-value-large { font-size: 2.25rem; font-weight: 800; line-height: 1; }
    .stat-label-small { font-size: 0.875rem; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 8px; }

    /* Chart specific styling adjustments */
    .chart-section { min-height: 350px; }

    /* Utility for status badges (Tailwind apply usage requires Tailwind to be running or processed) */
    .status-badge {
        /* If using unprocessed CSS, replace @apply with explicit styles */
        display: inline-flex;
        padding-left: 0.75rem;
        padding-right: 0.75rem;
        padding-top: 0.25rem;
        padding-bottom: 0.25rem;
        font-size: 0.75rem;
        line-height: 1rem;
        font-weight: 600;
        border-radius: 9999px;
    }
    .status-Approved { background-color: #d1fae5; color: #047857; }
    .status-Pending { background-color: #fef3c7; color: #b45309; }
    .status-Denied { background-color: #fee2e2; color: #b91c1c; }

    /* Table Style */
    .table-header { 
        padding-left: 1.5rem;
        padding-right: 1.5rem;
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
        background-color: #f9fafb; /* Gray-50 */
        text-align: left;
        font-size: 0.75rem;
        line-height: 1rem;
        font-weight: 500;
        color: #6b7280; /* Gray-500 */
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* --- START: Quick Navigation Custom Styles (ECO-THEME ADAPTED) --- */

/* (reward-summary-card) */
.reward-summary-card {
    display: flex; 
    align-items: center; 
    padding: 15px 20px;
    border: 1px solid #e2e8f0; 
    border-radius: 8px; 
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); 
    background-color: #ffffff;
}

.icon-container {
    font-size: 28px;
    color: #f59e0b; 
    margin-right: 20px;
}
.icon-container .fas {
    font-size: 32px;
}

.text-content {
    flex-grow: 1; 
    margin-right: 20px;
}

.summary-title {
    margin: 0 0 5px 0;
    font-size: 16px;
    color: #1e293b; /* Base dark color */
    font-weight: bold;
}

.summary-description {
    margin: 0;
    font-size: 13px;
    color: #64748b; /* Base secondary color */
}


.action-link {
    flex-shrink: 0; 
}

.nav-button {
    text-decoration: none;
    padding: 8px 12px;
    background-color: #10b981; 
    color: white;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
    transition: background-color 0.3s;
    display: flex;
    align-items: center;
}

.nav-button:hover {
    background-color: #059669; 
}


.nav-button .fas {
    margin-left: 8px;
    font-size: 12px;
}


.reward-nav-links {
    display: flex; 
    gap: 10px; 
}

.quick-nav-item {
    flex: 1; 
    display: flex;
    align-items: center;
    justify-content: center; 
    padding: 10px 15px;
    
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    color: #475569; /* dark-2 */
    
    border: 1px solid #d1d5db; /* Gray-300 */
    border-radius: 4px;
    background-color: #f9fafb; /* Light Gray */
    
    transition: all 0.2s ease;
}

.quick-nav-item:hover {
    background-color: #ecfdf5; /* Green-50 */
    border-color: #34d399; /* Green-400 */
    color: #10b981; /* eco-primary */
}


.quick-nav-item .fas {
    margin-right: 8px;
    font-size: 16px;
}
/* --- END: Quick Navigation Custom Styles --- */

</style>

    <script>
    tailwind.config = {
        theme: {
          extend: {
            colors: {
              primary: '#10b981', // Emerald Green for eco theme
              secondary: '#06b6d4', // Cyan
              'dark-2': '#475569',
              'light-2': '#e2e8f0'
            },
            fontFamily: {
              inter: ['Inter', 'sans-serif']
            },
            boxShadow: {
              'lg': '0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.05)',
            }
          }
        }
    };
    </script>
  </head> 
    <div class="flex-1 overflow-y-auto p-6 lg:p-10">
        <div id="dashboard-page" class="max-w-7xl mx-auto space-y-10">

            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6 pb-4 border-b border-gray-200">
                <div>
                    <h2 class="text-3xl font-bold text-gray-900 flex items-center gap-2">
                             <i class="fas fa-seedling eco-primary"></i> Member Eco-Dashboard Overview
                    </h2>
                    <p class="text-gray-500 mt-1">Comprehensive view of your eco-contribution, team status, and rewards.</p>
                </div>
                  
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">

                <div class="card-eco border-l-4 border-eco-primary">
                    <div class="stat-label-small">Personal Rank</div>
                    <div class="stat-value-large eco-text-point mt-2 flex items-center gap-2">
                        <i class="fas fa-trophy text-2xl"></i> <?= number_format($userRank); ?> 
                    </div>
                    <div class="text-xs text-gray-500 mt-2">My Points: <span class="font-semibold text-yellow-600">#<?= $myPoints ?> </span></div>
                </div>


                <div class="card-eco border-l-4 border-eco-primary">
                    <div class="stat-label-small"> Challenge Paticipated </div>
                    <div class="stat-value-large eco-text-point mt-2 flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane text-2xl"></i> <?= number_format($challengeCount); ?>
                    </div>
                </div>

                <div class="card-eco border-l-4 border-yellow-500">
                    <div class="stat-label-small">Team Joined</div>
                    <?php if ($userHasTeam): ?>
                        <div class="text-xl font-bold text-gray-900 mt-2 flex items-center gap-2">
                             <i class="fas fa-users text-yellow-600"></i> <?= htmlspecialchars($teamName) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-2">Team Rank: <span class="font-semibold text-yellow-600">#<?= $myTeamRank ?> </span></div>
                    <?php else: ?>
                        <div class="text-lg font-bold text-gray-700 mt-2">No Team Joined</div>
                        <p class="text-xs text-gray-500 mt-2">Join a team !</p>
                    <?php endif; ?>
                </div>

                <div class="card-eco border-l-4 border-sky-500">
                    <div class="stat-label-small">Total Submissions</div>
                    <div class="stat-value-large text-sky-600 mt-2 flex items-center gap-2">
                        <i class="fas fa-list-alt text-2xl"></i> <?= number_format($totalSubmission); ?>
                    </div>
                    <div class="text-xs text-gray-500 mt-2">Overall challenge attempts</div>
                </div>

            </div>
            
            
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pt-4 border-t border-gray-200">
                <h3 class="text-2xl font-bold text-gray-800">Contribution Analytics</h3>

                <div class="flex items-center gap-3">
                    <select id="timeFilter" class="border rounded-lg px-3 py-2 bg-white shadow-sm focus:ring-primary focus:border-primary" onchange="applyTimeFilter()">
                        <option value="all" <?= $timeFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                        <option value="today" <?= $timeFilter === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="7" <?= $timeFilter === '7' ? 'selected' : '' ?>>Last 7 Days</option>
                        <option value="30" <?= $timeFilter === '30' ? 'selected' : '' ?>>Last 30 Days</option>
                    </select>
                    
                    <button 
                        onclick="window.location = '?time=all'" 
                        class="bg-primary hover:bg-blue-700 text-white px-3 py-1.5 rounded shadow-md transition-colors flex items-center gap-2"
                    >
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div class="card-eco">
                    <div id="challengeChartContainer" class="chart-section">
                        </div>
                </div>
                
                <div class="card-eco">
                    <div id="submissionStatusChart" class="chart-section">
                        </div>
                </div>

            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="lg:col-span-2 card-eco">
                    <h4 class="text-lg font-semibold text-gray-700 mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-bar text-blue-500"></i> Team Leaderboard (Current Points)
                    </h4>
                    <?php if ($userHasTeam && !empty($teamRank)) : ?>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
                            <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm">
                                Your Rank: <strong class="text-blue-600">#<?= $personalRank ?></strong>
                            </div>
                            <div class="p-3 bg-green-50 border border-green-200 rounded-lg text-sm lg:col-span-2">
                                Team Total Points: <strong class="text-green-600"><?= number_format($teamTotalPoint) ?></strong>
                            </div>
                        </div>
                        <div id="teamRankChart" class="h-80 w-full"></div>
                    <?php else: ?>
                        <div class="text-center py-10 text-gray-400 bg-gray-50 rounded-lg">
                            <i class="fas fa-users-slash text-4xl mb-3 text-gray-300"></i>
                            <p class="font-medium text-gray-600"><?= $teamRankMessage ?></p>
                            <a href="/join-team" class="text-blue-500 hover:text-blue-700 underline mt-2 inline-block">Join a team now</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-eco space-y-4">
                    <h4 class="text-lg font-semibold text-gray-700">Reward Summary</h4>

                    <div class="p-4 bg-indigo-50 border-l-4 border-indigo-500 rounded-lg">
                        <p class="stat-label-small">Total Points Spent</p>
                        <p class="text-2xl font-bold text-indigo-600 mt-1"><?= number_format($totalSpent); ?></p>
                    </div>

                    <div class="p-4 bg-orange-50 border-l-4 border-orange-500 rounded-lg">
                        <p class="stat-label-small">Total Redemptions</p>
                        <p class="text-2xl font-bold text-orange-600 mt-1"><?= number_format($totalRedeemed); ?></p>
                    </div>

                    <p class="text-xs text-gray-500 pt-2">Redemptions may be pending. Please be patient.</p>
                </div>

            </div>
            
            <h3 class="text-2xl font-bold text-gray-800 pt-4 border-t border-gray-200">Reward & Spending Details</h3>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <div class="lg:col-span-2 card-eco">
                    <h4 class="font-semibold mb-4 text-gray-700 flex items-center gap-2">
                        <i class="fas fa-chart-area text-purple-500"></i> Points Spending Trend
                    </h4>
                    <div id="trendChartContainer" class="chart-section w-full"> 
                        <canvas id="trendChart" class="w-full"></canvas>
                    </div>
                </div>

                <div class="card-eco">
                    <h4 class="font-semibold mb-4 text-gray-700 flex items-center gap-2">
                        <i class="fas fa-chart-pie text-cyan-500"></i> Reward Categories
                    </h4>
                    <div id="categoryChartContainer" class="h-80 w-full flex items-center justify-center">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-card p-6 space-y-4">
              <h4 class="text-lg font-semibold text-dark mb-4 border-b pb-2"><i class="fas fa-compass mr-1 text-secondary"></i> Quick Navigation</h4>

              <div class="reward-summary-card">
                  <div class="icon-container"><i class="fas fa-trophy"></i></div>
                  <div class="text-content">
                      <h3 class="summary-title">My Rewards and Achievements</h3>
                      <p class="summary-description">View all badges, points, and exclusive benefits unlocked this week.</p>
                  </div>
                  <div class="action-link">
                      <a href="rewards.php" class="nav-button">View Details<i class="fas fa-arrow-right"></i></a>
                  </div>
              </div>

              <div class="reward-nav-links">
                  <a href="view.php" class="quick-nav-item">
                      <i class="fas fa-coins text-warning"></i> View Challenge
                  </a>
                  <a href="userdashboard.php" class="quick-nav-item">
                      <i class="fas fa-gift text-danger"></i> My Submission 
                  </a>
                  <a href="leaderboard.php" class="quick-nav-item">
                      <i class="fas fa-medal text-primary"></i> Leaderboards
                  </a>
              </div>
          </div>

           
        
        </div>
</div>

<script>
    // apply time filter by reloading with ?time=
    function applyTimeFilter(){
        const val = document.getElementById('timeFilter').value;
        const url = new URL(window.location.href);
        url.searchParams.set('time', val);
        window.location.href = url.toString();
    }

    window.chartInstances = {
        teamRankChart: null,
        submissionStatusChart: null,
        challengeChart: null,
    };

    const submissionDetails = <?= $submissionDetailsJson ?>;
    
    
    const trendLabels = <?= $trendLabelsJson ?>;
    const trendData = <?= $trendDataJson ?>;
    const catLabels = <?= $catLabelsJson ?>;
    const catData = <?= $catDataJson ?>;

    
  
    const noDataHtml = (iconClass, title, message) => `
        <div class="text-center py-20 text-gray-400 bg-gray-50 rounded-lg h-full flex flex-col items-center justify-center">
            <i class="${iconClass} text-4xl text-gray-300 mb-3"></i>
            <p class="text-lg font-semibold text-gray-700">${title}</p>
            <p class="text-sm text-gray-500 mt-1">${message}</p>
        </div>
    `;

    document.addEventListener('DOMContentLoaded', function () {
        
        
        // A. Team Rank Chart (Bar Chart)
        function initializeTeamRankChart() {
            const teamRankData = <?= json_encode($teamRank); ?>;
            const teamRankEl = document.getElementById('teamRankChart');
            
            if(window.chartInstances.teamRankChart) { window.chartInstances.teamRankChart.resize(); return; }

            if(teamRankEl && teamRankData.length > 0){
                const teamRankChart = echarts.init(teamRankEl);
                const yMin = Math.max(0, Math.min(...teamRankData.map(t=>t.value))-100);
                
                teamRankChart.setOption({
                    title:{ text: 'Member Performance in Team (Total Points)', left: 'center', top: '0%', textStyle:{fontSize:14} },
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                    grid: { left: '3%', right: '4%', bottom: '3%', top: '15%', containLabel: true },
                    xAxis: { type: 'category', data: teamRankData.map(t=>t.name), axisLabel:{interval:0} },
                    yAxis: { type: 'value', min: yMin, name: 'Total Points' },
                    series:[{
                        type:'bar',
                        data:teamRankData.map(t=>t.value),
                        itemStyle:{ 
                            color: params => params.name==='You' ? '#3b82f6' : '#9ca3af' // Highlight 'You' in blue
                        },
                        label:{ show:true, position:'top' }
                    }]
                });
                window.chartInstances.teamRankChart = teamRankChart;
            }
        }


        // B. Submission Status Chart (Radar Chart)
        function initializeSubmissionStatusChart() {
            const chartEl = document.getElementById('submissionStatusChart');
            const approvedCount = <?= (int)$approvedCount; ?>;
            const pendingCount = <?= (int)$pendingCount; ?>;
            const deniedCount = <?= (int)$deniedCount; ?>;
            const totalSubmissions = approvedCount + pendingCount + deniedCount;
            
            if (window.chartInstances.submissionStatusChart) { window.chartInstances.submissionStatusChart.resize(); return; }

            if(chartEl && totalSubmissions > 0){
                const submissionStatusChart = echarts.init(chartEl);

                const option = {
                    title: { text: 'Submission Status Distribution', left: 'center', top: '5%', textStyle: { fontWeight: 'bold', fontSize: 14, color:'#334155' } },
                    tooltip: {},
                    legend: { data: ['Submissions'], bottom: '0%' },
                    radar: {
                        indicator: [
                            { name: 'Approved', max: Math.max(approvedCount, pendingCount, deniedCount, 1) },
                            { name: 'Pending', max: Math.max(approvedCount, pendingCount, deniedCount, 1) },
                            { name: 'Denied', max: Math.max(approvedCount, pendingCount, deniedCount, 1) }
                        ],
                        center: ['50%', '55%'],
                        radius: '65%',
                        name: { color: '#334155', fontWeight: 600 }
                    },
                    series: [{
                        name: 'Submission Status',
                        type: 'radar',
                        data: [{
                            value: [approvedCount, pendingCount, deniedCount],
                            name: 'Submissions',
                            areaStyle: { color: 'rgba(16, 185, 129, 0.4)' }, 
                            lineStyle: { color: '#10b981', width: 2 },
                            itemStyle: { color: '#10b981' }
                        }]
                    }]
                };

                submissionStatusChart.setOption(option);
                window.chartInstances.submissionStatusChart = submissionStatusChart;
            } else if(chartEl) {
                chartEl.innerHTML = noDataHtml('fas fa-file-upload', 'No Submissions Found', 'Start participating in challenges!');
            }
        }


        // C. Challenge Chart (Horizontal Bar Chart)
     function initializeChallengeChart() {
        const chartContainerEl = document.getElementById('challengeChartContainer');
        const challengeData = <?php echo json_encode($userChallenges); ?>; 
        
        if (window.chartInstances.challengeChart) { 
            window.chartInstances.challengeChart.resize(); 
            return; 
        }

        if (chartContainerEl && challengeData.length > 0) {
            const totalCompleted = challengeData.reduce((sum, c) => sum + c.times, 0);

            chartContainerEl.innerHTML = `
                <div class="mb-4 flex items-center justify-center bg-primary/10 border border-primary/20 rounded-lg px-4 py-3 shadow-sm">
                    <span class="text-green-800 font-semibold text-base">Total Approved Submissions: 
                    <span class="text-green-900 font-bold text-xl ml-2">${totalCompleted}</span>
                    </span>
                </div>
            `;

            const chartEl = document.createElement('div');
            chartEl.style.height = '300px';
            chartContainerEl.appendChild(chartEl);

            const challengeChart = echarts.init(chartEl);

            challengeChart.setOption({
                title: { 
                    text: 'Top Challenges: Points Earned', 
                    left: 'center', 
                    top: '0%', 
                    textStyle: { fontSize: 14, fontWeight: 'bold' } 
                },
                tooltip: { 
                    trigger: 'axis', 
                    axisPointer: { type: 'shadow' },
                    formatter: function(params) {
                        const c = params[0].data;
                        return `${c.name}<br/>Points: ${c.points}<br/>Times Completed: ${c.times}`;
                    }
                },
                grid: { left: '3%', right: '10%', bottom: '3%', top: '15%', containLabel: true },
                yAxis: { 
                    type: 'category', 
                    data: challengeData.map(c => c.name), 
                    inverse: true,
                    axisLabel: { 
                        formatter: function (value) { 
                            return value.length > 20 ? value.substring(0, 17) + '...' : value; 
                        } 
                    }
                },
                xAxis: { type: 'value', name: 'Points Earned' },
                series: [{
                    type: 'bar',
                    data: challengeData.map(c => ({
                        value: c.points,
                        name: c.name,
                        points: c.points,
                        times: c.times
                    })),
                    barWidth: '70%',
                    itemStyle: { color: '#10b981' },
                    label: { show: true, position: 'right', color: '#475569', fontWeight: 'bold', fontSize: 10 }
                }]
            });

        window.chartInstances.challengeChart = challengeChart;
    } else if(chartContainerEl) {
         chartContainerEl.innerHTML = noDataHtml('fas fa-trophy', 'No approved challenges yet!', 'Start submitting to earn points!');
    }
}


        // --- 2. Chart.js 
        function initializeRewardCharts() {
            // Trend Chart
            const trendEl = document.getElementById('trendChart');
            const trendContainer = document.getElementById('trendChartContainer');

            if (trendData.length > 0) {
                trendEl.style.height = '300px'; // Set height explicitly for Chart.js in flex container
                const ctx = trendEl.getContext('2d');
                
                if (trendContainer.querySelector('.text-center.py-20')) {
                    trendContainer.innerHTML = '';
                    trendContainer.appendChild(trendEl);
                }
                trendContainer.classList.add('chart-section'); 
                trendContainer.classList.remove('h-96'); 
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: [{
                            label: 'Points Spent',
                            data: trendData,
                            borderColor: '#4f46e5',
                            backgroundColor: 'rgba(79, 70, 229, 0.2)',
                            fill: true,
                            tension: 0.3
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } }, 
                        scales: { y: { beginAtZero: true } }
                    }
                });
            } else if (trendContainer) {
                // Clear canvas and insert no data message
                trendContainer.innerHTML = noDataHtml('fas fa-hourglass-half', 'No Spending Trend', 'No redemptions found in this period.');
                // Ensure the container maintains height for good layout
                trendContainer.classList.remove('chart-section'); 
                trendContainer.classList.add('h-96'); 
            }

            // Category Chart
            const catEl = document.getElementById('categoryChart');
            const categoryContainer = document.getElementById('categoryChartContainer');

            if (catData.length > 0) {
                catEl.style.height = '280px'; // Set height for Doughnut chart
                const ctx = catEl.getContext('2d');
                
                if (categoryContainer.querySelector('.text-center.py-20')) {
                    categoryContainer.innerHTML = '';
                    categoryContainer.appendChild(catEl);
                }
                categoryContainer.classList.remove('h-96'); 
                categoryContainer.classList.add('h-80'); 

                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        
                        labels: catLabels, 
                        datasets: [{ 
                            data: catData,
                            backgroundColor: ['#10b981', '#06b6d4', '#f59e0b', '#ef4444'],
                            hoverOffset: 4
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        cutout: '75%',
                        plugins: { 
                            
                            legend: { 
                                position: 'bottom' 
                            } 
                        }
                    }
                });
            } else if (categoryContainer) {
                 // Clear canvas and insert no data message
                categoryContainer.innerHTML = noDataHtml('fas fa-gift', 'No Rewards Redeemed', 'Redeem points for rewards to see the breakdown.');
                // Ensure the container maintains height
                categoryContainer.classList.remove('h-80'); 
                categoryContainer.classList.add('h-96'); 
            }
        }


        
        initializeTeamRankChart();
        initializeSubmissionStatusChart();
        initializeChallengeChart();
        initializeRewardCharts();
        
        // Ensure all charts resize correctly on window resize
        window.addEventListener('resize', () => {
             // Resize ECharts instances
             Object.values(window.chartInstances).forEach(chart => chart?.resize());
             // Chart.js charts handle resize automatically due to responsive: true
        });

    });
</script>

<?php include "includes/layout_end.php"; ?>

</body>
</html>