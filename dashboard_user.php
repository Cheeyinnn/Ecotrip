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
if ($timeFilter === '7') {
    $days = 7;
} elseif ($timeFilter === '30') {
    $days = 30;
}

// sub 表的时间条件
$subTimeCondition = '';
if ($days !== null) {
    $subTimeCondition = " AND uploaded_at >= NOW() - INTERVAL $days DAY";
}

// redemptionrequest 表的时间条件
$redeemTimeCondition = '';
if ($days !== null) {
    $redeemTimeCondition = " AND requested_at >= NOW() - INTERVAL $days DAY";
}


// Fetch points
$sql_points = "SELECT walletPoint AS totalPoints FROM user WHERE userID = ?";
$stmt_points = $conn->prepare($sql_points);
$stmt_points->bind_param("i", $userID);
$stmt_points->execute();
$result = $stmt_points->get_result();
$myPoints = ($row = $result->fetch_assoc()) ? (int)$row['totalPoints'] : 0;
$stmt_points->close();


///////////////////////////////////////////////////////////////////////////////////////////////////////
//submission section

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


$submissionDetails = [];

$subTimeCondition = '';
if ($days !== null) {
    $subTimeCondition = " AND s.uploaded_at >= NOW() - INTERVAL $days DAY";
}

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


///////////////////////////////////////////////////////////////////////////////////////////////////////
// challenge section
// 按 filter 取出该用户 Approved 的 submission
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
        'points' => (int)$row['totalPoints'],  // 成功获得的总分
        'times'  => (int)$row['approvedSubmissions'], // 该挑战成功次数
        'status' => 'Completed'
    ];
}
$stmt->close();



///////////////////////////////////////////////////////////////////////////////////////////////////////
//reward section



// 2. Data Fetching for Dashboard

// A. User Summary Stats
$stmt = $conn->prepare("
    SELECT 
        u.walletPoint, 
        (SELECT SUM(pointSpent) FROM redemptionrequest WHERE userID = ?) AS totalSpent,
        (SELECT COUNT(*) FROM redemptionrequest WHERE userID = ?) AS totalRedeemed,
        (SELECT teamName FROM team WHERE userID = ? LIMIT 1) AS teamName
    FROM user u
    WHERE u.userID = ?
");
$stmt->bind_param("iiii", $userID, $userID, $userID, $userID);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

$currentPoints = $summary['walletPoint'];
$totalSpent = $summary['totalSpent'] ?? 0;
$totalRedeemed = $summary['totalRedeemed'] ?? 0;
$teamName = $summary['teamName'] ?? null; // 这里就是团队名字，如果没加入返回 null

$stmt->close();


// B. Chart Data 1: Points Spent Trend (根据 filter)
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

// C. Chart Data 2: Category Distribution (Products vs Vouchers) 根据 filter
$catLabels = [];
$catData = [];

$catSql = "SELECT r.category, COUNT(*) AS count
           FROM redemptionrequest rr
           JOIN reward r ON rr.rewardID = r.rewardID
           WHERE rr.userID = ? $redeemTimeCondition
           GROUP BY r.category";

$stmt = $conn->prepare($catSql);
$stmt->bind_param("i", $userID);
$stmt->execute();
$catRes = $stmt->get_result();

while($row = $catRes->fetch_assoc()){
    $catLabels[] = ucfirst($row['category']);
    $catData[] = (int)$row['count'];
}

$stmt->close();


// D. Recent Activity List 根据 filter
$recentActivity = [];

$timeCondition = '';
if ($days !== null) {
    $timeCondition = " AND rr.requested_at >= NOW() - INTERVAL $days DAY";
}

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
$recentActivity = [];
while($row = $recentRes->fetch_assoc()){
    $recentActivity[] = $row;
}
$stmt->close();


///////////////////////////////////////////////////////////////////////////////////////////////////////
//team rank section
$userHasTeam = false;
$teamRankMessage = '';
$teamRank = [];
$personalRank = null;

// 获取用户所属 teamID
$sql_team_id = "SELECT teamID FROM user WHERE userID = ?";
$stmt = $conn->prepare($sql_team_id);
$stmt->bind_param("i", $userID);
$stmt->execute();
$userTeam = $stmt->get_result()->fetch_assoc();
$stmt->close();

$teamID = $userTeam['teamID'] ?? null;

if ($teamID === null) {
    $teamRankMessage = "You are not part of any team yet. Join a team !";
} else {
    $userHasTeam = true;

    // 时间过滤条件
    $ptTimeCondition = '';
    if ($days !== null) {
        $ptTimeCondition = " AND pt.generate_at >= NOW() - INTERVAL $days DAY";
    }

    // 查询 team leaderboard
$sql_leaderboard = "
    SELECT 
        u.userID,
        u.firstName,
        u.lastName,
        SUM(pt.pointsTransaction) AS totalPoints,
        (SELECT SUM(pt2.pointsTransaction) 
         FROM user u2
         LEFT JOIN pointtransaction pt2 ON u2.userID = pt2.userID $ptTimeCondition
         WHERE u2.teamID = u.teamID) AS teamTotalPoints
    FROM user u
    LEFT JOIN pointtransaction pt ON u.userID = pt.userID $ptTimeCondition
    WHERE u.teamID = ?
    GROUP BY u.userID, u.firstName, u.lastName
    ORDER BY totalPoints DESC
";


    $stmt2 = $conn->prepare($sql_leaderboard);
    $stmt2->bind_param("i", $teamID);
    $stmt2->execute();
    $res = $stmt2->get_result();

$teamTotalPoint = 0; // 初始化

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

    // 拿到团队总积分，只用一次即可
    if ($rankCounter === 1) {
        $teamTotalPoint = (int)($row['teamTotalPoints'] ?? 0);
    }

    $rankCounter++;
}


    $stmt2->close();
}

include "includes/layout_start.php";

?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Member Dashboard</title>
    <script src="https://res.gemcoder.com/js/reload.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <script src="https://cdn.bootcdn.net/ajax/libs/echarts/5.4.3/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

    
    <style>
        body { background: #f5f7fb; font-family: 'Plus Jakarta Sans', sans-serif; color: #334155; }
        
        /* Card Styles */
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            height: 100%;
            transition: transform 0.2s;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover { transform: translateY(-3px); }
        
        /* Decorative Background Circles for cards */
        .bg-circle {
            position: absolute;
            right: -20px;
            bottom: -20px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            opacity: 0.1;
        }

        /* Titles & Typography */
        .card-label { font-size: 13px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .card-value { font-size: 32px; font-weight: 800; color: #0f172a; margin: 5px 0; }
        .card-sub { font-size: 13px; color: #94a3b8; }
        
        /* Chart Containers */
        .chart-container { position: relative; height: 300px; width: 100%; }
        
        /* Table Styles */
        .table-custom th { font-size: 12px; text-transform: uppercase; color: #94a3b8; font-weight: 700; background: #f8fafc; border: none; padding: 15px; }
        .table-custom td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 14px; font-weight: 600; color: #475569; }
        .table-custom tr:last-child td { border-bottom: none; }
        .item-img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; margin-right: 12px; }

        /* Custom Colors */
        .text-purple { color: #8b5cf6; }
        .bg-purple { background-color: #8b5cf6; }
        .text-orange { color: #f97316; }
        .bg-orange { background-color: #f97316; }
    </style>

    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              primary: '#165DFF',
              secondary: '#36CBCB',
              success: '#52C41A',
              warning: '#FAAD14',
              danger: '#FF4D4F',
              info: '#1890FF',
              dark: '#1D2129',
              'dark-2': '#4E5969',
              'light-1': '#F2F3F5',
              'light-2': '#E5E6EB',
              'light-3': '#C9CDD4'
            },
            fontFamily: {
              inter: ['Inter', 'sans-serif']
            },
            spacing: {
              '128': '32rem'
            },
            boxShadow: {
              'card': '0 4px 20px 0 rgba(0, 0, 0, 0.05)',
              'card-hover': '0 8px 30px 0 rgba(0, 0, 0, 0.1)'
            }
          }
        }
      };
    </script>
    <style type="text/tailwindcss">
      @layer utilities {
          .content-auto {
              content-visibility: auto;
          }
          .scrollbar-hide {
              -ms-overflow-style: none;
              scrollbar-width: none;
          }
          .scrollbar-hide::-webkit-scrollbar {
              display: none;
          }
          .card-transition {
              transition: all 0.3s ease;
          }
          .nav-item-active {
              @apply bg-primary/10 text-primary border-l-4 border-primary;
          }
          .stat-card-value {
              @apply text-[clamp(1.5rem,3vw,2.5rem)] font-bold text-dark;
          }
          .stat-card-label {
              @apply text-dark-2 text-sm font-medium;
          }
          .stat-card-change {
              @apply text-xs font-medium;
          }
      }
    </style>
  </head>
<body class="font-inter bg-gray-50 text-dark min-h-screen flex flex-col">
    
    
    
    <div class="flex flex-1 overflow-hidden">
    
        <main class="flex-1 overflow-y-auto bg-gray-50 p-6 lg:p-10">
            <div id="dashboard-page" class="max-w-7xl mx-auto space-y-10">

                <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-6">

                    <div>
                        <h2 class="text-[clamp(1.5rem,3vw,2rem)] font-bold text-dark">
                            Member Dashboard
                        </h2>
                        <p class="text-dark-2 mt-1">View all personal data</p>
                    </div>
                     <div class="flex items-center gap-3">
                        <label class="text-sm text-gray-700">Filter:</label>
                        <select id="timeFilter" class="border rounded px-2 py-1" onchange="applyTimeFilter()">
                            <option value="all" <?= $timeFilter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="7" <?= $timeFilter === '7' ? 'selected' : '' ?>>Last 7 Days</option>
                            <option value="30" <?= $timeFilter === '30' ? 'selected' : '' ?>>Last 30 Days</option>
                        </select>

                        <button onclick="window.location.reload()" class="bg-primary text-white px-3 py-1 rounded shadow">
                            <i class="fas fa-refresh"></i> Refresh
                        </button>
                        </div>
                </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">

                <!-- My Points -->
                <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="stat-card-label">My Points</p>
                            <h3 class="stat-card-value mt-1"><?= number_format($myPoints); ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                            <i class="fas fa-coins text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Personal Rank -->
                <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="stat-card-label">Personal Rank</p>
                            <h3 class="stat-card-value mt-1"><?= number_format($approvedCount); ?></h3>   <!-- Change!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! -->
                        </div>
                        <div class="w-12 h-12 rounded-lg bg-secondary/10 flex items-center justify-center text-secondary">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Team Joined -->
                <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="stat-card-label">Team Joined</p>
                            <?php if ($userHasTeam): ?>
                                <div class="text-lg font-semibold mt-1"><?= htmlspecialchars($teamName) ?></div>

                            <?php else: ?>
                                <p class="text-sm text-gray-500 mt-1">You haven't joined a team yet. <a href="/join-team" class="text-blue-500 underline">Join Now</a></p>
                            <?php endif; ?>
                        </div>
                        <div class="w-12 h-12 rounded-lg <?= $userHasTeam ? 'bg-warning/10 text-warning' : 'bg-gray-200 text-gray-400' ?> flex items-center justify-center">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Total Submission -->
                <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="stat-card-label">Total Submission</p>
                            <h3 class="stat-card-value mt-1"><?= number_format($totalSubmission); ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-lg bg-info/10 flex items-center justify-center text-info">
                            <i class="fas fa-list-alt text-xl"></i>
                        </div>
                    </div>
                </div>

            </div>


                <div class="bg-white rounded-2xl shadow-lg p-6">

                    <div class="flex border-b border-light-2 mb-6 space-x-6">

                        <button class="py-3 px-4 text-sm font-semibold text-primary border-b-2 border-primary transition-all" onclick="showTab('charts')" id="tab-charts"> Team </button>
                        <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('user')" id="tab-user"> My Submissions </button>
                        <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('tables')" id="tab-tables"> My Challenges</button>
                        <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('reward')" id="tab-reward"> Rewards </button>

                    </div>

                <div id="charts" class="tab-content p-4 space-y-6">
                    <div class="bg-white shadow rounded p-4">
                        <?php if ($userHasTeam && !empty($teamRank)) : ?>
                            <!-- Chart -->
                            <div id="teamRankChart" class="h-60 w-full mb-6"></div>

                            <!-- Info Cards -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="flex items-center p-4 bg-blue-50 rounded-lg shadow-sm">
                                    <div class="flex-1">
                                        <p class="text-gray-500 text-sm font-medium">Your Rank in Team</p>
                                        <p class="text-blue-600 font-semibold text-lg"><?= $personalRank ?></p>
                                    </div>
                                    <i class="fas fa-user text-blue-400 text-2xl"></i>
                                </div>

                                <div class="flex items-center p-4 bg-green-50 rounded-lg shadow-sm">
                                    <div class="flex-1">
                                        <p class="text-gray-500 text-sm font-medium">Team Total Points</p>
                                        <p class="text-green-600 font-semibold text-lg"><?= number_format($teamTotalPoint) ?></p>
                                    </div>
                                    <i class="fas fa-trophy text-green-400 text-2xl"></i>
                                </div>
                            </div>

                            <script>
                                const teamRankData = <?= json_encode($teamRank) ?>;

                                if (teamRankData.length > 0) {
                                    let minRankValue = Math.min(...teamRankData.map(item => item.value));
                                    const yAxisMin = Math.max(0, minRankValue - 100);

                                    const chart = echarts.init(document.getElementById('teamRankChart'));
                                    const option = {
                                        title: { text: 'Team & Personal Rank', left: 'center', textStyle:{fontSize:14} },
                                        xAxis: { type: 'category', data: teamRankData.map(t => t.name), axisLabel: { interval:0 } },
                                        yAxis: { 
                                            type: 'value',
                                            min: yAxisMin,
                                            name: 'Total Points'
                                        },
                                        series: [{
                                            type: 'bar',
                                            data: teamRankData.map(t => t.value),
                                            itemStyle: {
                                                color: params => params.name==='You' ? '#3b82f6' : '#9ca3af'
                                            },
                                            label: { show: true, position: 'top' }
                                        }]
                                    };
                                    chart.setOption(option);
                                }
                            </script>

                        <?php else: ?>
                            <div class="text-center py-16 text-gray-400">
                                <i class="fas fa-users-slash text-4xl mb-4"></i>
                                <p class="text-lg font-medium"><?= $teamRankMessage ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>


                <div>
                   <div id="user" class="tab-content hidden p-4 space-y-6">
                    
                        <div class="bg-white shadow rounded pt-6 pb-6">
                            <div id="submissionStatusChart" style="height: 350px;"></div>
                        </div>

                    </div>

                </div>

                <div id="tables" class="tab-content hidden p-4">
                 


                    <div id="challengeChart" style="height: 300px;"></div>

                </div>


                <div id="reward" class="tab-content hidden p-4 space-y-6">

                    <!-- Reward Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                        <div class="stat-card">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="card-label">Current Wallet</div>
                                    <div class="card-value"><?= number_format($currentPoints); ?></div>
                                    <div class="card-sub text-success"><i class="fas fa-arrow-up"></i> Available to spend</div>
                                </div>
                                <div class="icon-box bg-success bg-opacity-10 p-3 rounded-full text-success">
                                    <iconify-icon icon="solar:wallet-money-bold" style="font-size: 24px;"></iconify-icon>
                                </div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="card-label">Total Spent</div>
                                    <div class="card-value text-purple"><?= number_format($totalSpent); ?></div>
                                    <div class="card-sub">Lifetime points burned</div>
                                </div>
                                <div class="icon-box bg-purple bg-opacity-10 p-3 rounded-full text-purple">
                                    <iconify-icon icon="solar:fire-bold" style="font-size: 24px;"></iconify-icon>
                                </div>
                            </div>
                            <div class="bg-circle bg-purple"></div>
                        </div>

                        <div class="stat-card">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="card-label">Total Redemptions</div>
                                    <div class="card-value text-orange"><?= number_format($totalRedeemed); ?></div>
                                    <div class="card-sub">Items & Vouchers claimed</div>
                                </div>
                                <div class="icon-box bg-orange bg-opacity-10 p-3 rounded-full text-orange">
                                    <iconify-icon icon="solar:bag-heart-bold" style="font-size: 24px;"></iconify-icon>
                                </div>
                            </div>
                            <div class="bg-circle bg-orange"></div>
                        </div>
                    </div>

                    <!-- Reward Charts -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-2 stat-card">
                            <h5 class="font-bold mb-4">Spending Analysis</h5>
                            <div class="chart-container" style="height: 300px;">
                                <canvas id="trendChart"></canvas>
                            </div>
                        </div>

                        <div class="stat-card">
                            <h5 class="font-bold mb-4">Reward Categories</h5>
                            <div class="chart-container h-[250px]">
                                <canvas id="categoryChart"></canvas>
                            </div>
                            <div class="text-center mt-3">
                                <small class="text-gray-500">Distribution of your redemptions</small>
                            </div>
                        </div>
                    </div>


                </div>




            </div>
        </main>
    </div>

<script>
    // apply time filter by reloading with ?time=
    function applyTimeFilter(){
        const val = document.getElementById('timeFilter').value;
        const url = new URL(window.location.href);
        url.searchParams.set('time', val);
        window.location.href = url.toString();
    }

    // 全局变量来存储 ECharts 实例，防止重复初始化
    window.chartInstances = {
        teamRankChart: null,
        submissionStatusChart: null,
        challengeChart: null
    };

    document.addEventListener('DOMContentLoaded', function () {
        
        // --- 1. 核心 Tab 切换逻辑 ---
        function showTab(tabId) {

            // hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.add('hidden'));

            // reset all tab buttons
            document.querySelectorAll('[id^="tab-"]').forEach(btn => {
                btn.classList.remove('text-primary','border-b-2','border-primary');
                btn.classList.add('text-dark-2','hover:text-dark','hover:border-dark/20');
            });

            // show current tab
            const tab = document.getElementById(tabId);
            if(tab) tab.classList.remove('hidden');

            // highlight current tab button
            const btn = document.querySelector(`[onclick="showTab('${tabId}')"]`);
            if(btn){
                btn.classList.add('text-primary','border-b-2','border-primary');
                btn.classList.remove('text-dark-2','hover:text-dark','hover:border-dark/20');
            }
            
            // --- 2. 图表初始化/绘制 ---
            if (tabId === 'user') {
                initializeSubmissionStatusChart();
            } else if (tabId === 'tables') {
                initializeChallengeChart();
            }
            
            // 确保显示后图表调整大小
            Object.values(window.chartInstances).forEach(chart => {
                if (chart) chart.resize();
            });
        }
        window.showTab = showTab;
        
        // --- 3. 初始化函数定义 ---

        // A. Submission Status Chart (My Submissions Tab)
function initializeSubmissionStatusChart() {
    if (window.chartInstances.submissionStatusChart) {
        window.chartInstances.submissionStatusChart.resize();
        return;
    }

    const chartEl = document.getElementById('submissionStatusChart');
    const approvedCount = <?= (int)$approvedCount; ?>;
    const pendingCount = <?= (int)$pendingCount; ?>;
    const deniedCount = <?= (int)$deniedCount; ?>;
    const totalSubmissions = approvedCount + pendingCount + deniedCount;

    if(chartEl){
        chartEl.style.height = '300px'; // 雷达图高度
        if (totalSubmissions === 0) {
            chartEl.innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <i class="fas fa-file-upload text-4xl text-info/60 mb-3"></i>
                    <p class="text-lg font-semibold text-dark-2">No Submissions Found</p>
                    <p class="text-sm text-gray-500 mt-1">
                        Start participating in challenges to see your status breakdown here!
                    </p>
                </div>
            `;
        } else {
            const submissionStatusChart = echarts.init(chartEl);

            const option = {
                title: {
                    text: 'Submission Status Overview',
                    top: '5%',  // 保持正值，不要负值
                    left: 'center',
                    textStyle: { fontWeight: 'bold', fontSize: 16 }
                },
                tooltip: {},
                radar: {
                    indicator: [
                        { name: 'Approved', max: Math.max(approvedCount, pendingCount, deniedCount, 1) },
                        { name: 'Pending', max: Math.max(approvedCount, pendingCount, deniedCount, 1) },
                        { name: 'Denied', max: Math.max(approvedCount, pendingCount, deniedCount, 1) }
                    ],
                    shape: 'circle',
                    splitNumber: 4,
                    axisName: { color: '#334155', fontWeight: 600 }
                },
                series: [{
                    name: 'Submission Status',
                    type: 'radar',
                    data: [{
                        value: [approvedCount, pendingCount, deniedCount],
                        name: 'Submissions',
                        areaStyle: { color: 'rgba(34,197,94,0.3)' }, // 绿色半透明
                        lineStyle: { color: '#22c55e', width: 2 },
                        itemStyle: {
                            color: '#22c55e',
                            borderColor: '#fff',
                            borderWidth: 2
                        }
                    }]
                }]
            };

            submissionStatusChart.setOption(option);
            window.chartInstances.submissionStatusChart = submissionStatusChart;
        }
    }
}


        
        // B. Challenge Chart (My Challenges Tab)
function initializeChallengeChart() {
    if (window.chartInstances.challengeChart) {
        window.chartInstances.challengeChart.resize();
        return;
    }

    const challengeEl = document.getElementById('challengeChart');
    const challengeData = <?php echo json_encode($userChallenges); ?>; 

    if (challengeEl) {
        if (!challengeData || challengeData.length === 0) {
            challengeEl.innerHTML = `
                <div class="text-center py-16 text-gray-400">
                    <i class="fas fa-trophy text-4xl mb-4"></i>
                    <p class="text-lg font-medium">No challenges participated yet!</p>
                    <p class="text-sm text-gray-500 mt-1">Start submitting to earn points!</p>
                </div>`;
            return;
        }

        // ✅ 计算总共成功的挑战次数
        const totalCompleted = challengeData.reduce((sum, c) => sum + c.times, 0);

        // 2️⃣ Data mapping
        const names = challengeData.map(c => c.name);
        const points = challengeData.map(c => c.points);
        const times = challengeData.map(c => c.times);

        // 3️⃣ 在图表上方显示总完成次数
        challengeEl.innerHTML = `
            <div class="mb-4 flex items-center justify-between bg-green-50 border border-green-200 rounded-lg px-4 py-2 shadow-sm">
                <div class="flex items-center gap-2">
                    <i class="fas fa-trophy text-green-500 text-lg"></i>
                    <span class="text-green-800 font-semibold text-sm">Total Completed Challenges : </span>
                    <span class="text-green-900 font-bold text-lg">${totalCompleted}</span>
                </div>
                
            </div>

        `;

        const chartContainer = document.createElement('div');
        chartContainer.style.height = '300px';
        challengeEl.appendChild(chartContainer);

        const challengeChart = echarts.init(chartContainer);

        challengeChart.setOption({
            title: {
                text: 'Challenges Completed & Points Earned',
                left: 'center',
                top: '5%',
                textStyle: { fontSize: 16, fontWeight: 'bold' }
            },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' },
                formatter: function (params) {
                    const idx = params[0].dataIndex;
                    const c = challengeData[idx];

                    return `
                        <strong>${c.name}</strong><br/>
                        Points Earned: <strong>${c.points}</strong><br/>
                        Submissions: <strong>${c.times} Approved</strong>
                    `;
                }
            },
            grid: { left: '5%', right: '5%', bottom: '10%', top: '20%', containLabel: true },
            yAxis: { type: 'category', data: names, inverse: true },
            xAxis: { type: 'value', name: 'Points Earned' },
            series: [{
                type: 'bar',
                data: points,
                barWidth: '60%',
                itemStyle: { color: '#22c55e' },
                label: {
                    show: true,
                    position: 'right',
                    color: '#475569',
                    fontWeight: 'bold',
                    fontSize: 10
                }
            }]
        });

        window.chartInstances.challengeChart = challengeChart;
    }
}

        
        // C. Team Rank Chart (Overview Tab, 在 DOMContentLoaded 时立即初始化)
        function initializeTeamRankChart() {
             const teamRankData = <?= json_encode($teamRank); ?>;
             const teamRankEl = document.getElementById('teamRankChart');
             if(teamRankEl && teamRankData.length > 0){
                const teamRankChart = echarts.init(teamRankEl);
                const yMin = Math.max(0, Math.min(...teamRankData.map(t=>t.value))-100);
                teamRankChart.setOption({
                    // ... (保持原先的 Team Rank Chart options)
                    title:{ text:'Team & Personal Rank', left:'center', textStyle:{fontSize:14} },
                    xAxis:{ type:'category', data:teamRankData.map(t=>t.name), axisLabel:{interval:0} },
                    yAxis:{ type:'value', min:yMin, name:'Total Points' },
                    series:[{
                        type:'bar',
                        data:teamRankData.map(t=>t.value),
                        itemStyle:{ color: params => params.name==='You'?'#3b82f6':'#9ca3af' },
                        label:{ show:true, position:'top' }
                    }]
                });
                window.chartInstances.teamRankChart = teamRankChart;
             }
        }
        
        // D. Reward Charts (Chart.js charts, 保持原样)
function initializeRewardCharts() {
    if (window.rewardChartsLoaded) return;

    const initCharts = () => {
        // Spending Trend Line Chart
        const trendEl = document.getElementById('trendChart');
        if (trendEl) {
            const ctx = trendEl.getContext('2d');

            const gradient = ctx.createLinearGradient(0, 0, 0, trendEl.offsetHeight || 300);
            gradient.addColorStop(0, 'rgba(139,92,246,0.5)');
            gradient.addColorStop(1, 'rgba(139,92,246,0.0)');

            window.rewardTrendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($trendLabels ?? []) ?>,
                    datasets: [{
                        label: 'Points Spent',
                        data: <?= json_encode($trendData ?? []) ?>,
                        borderColor: '#8b5cf6',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#8b5cf6',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        pointHoverRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 12,
                            titleFont: { size: 13 },
                            bodyFont: { size: 14, weight: 'bold' }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { borderDash: [5, 5], color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        // Reward Categories Doughnut Chart
        const catEl = document.getElementById('categoryChart');
        if (catEl) {
            const ctx = catEl.getContext('2d');
            window.rewardCategoryChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($catLabels ?? []) ?>,
                    datasets: [{
                        data: <?= json_encode($catData ?? []) ?>,
                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#6366f1'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '75%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, padding: 20 }
                        }
                    }
                }
            });
        }

        window.rewardChartsLoaded = true;
    };

    // 延迟初始化，确保 tab 可见
    requestAnimationFrame(() => {
        // 小延迟，保证 DOM 完全渲染
        setTimeout(initCharts, 50);
    });
}



        // --- 4. 首次加载和调整大小 ---
        
   
        initializeTeamRankChart();
        

       const originalShowTab = window.showTab; 
        window.showTab = function(tabId) {
            originalShowTab(tabId);

            if (tabId === 'reward' && !window.rewardChartsLoaded){
                initializeRewardCharts();
            }

            // 调整所有已有 chart 尺寸
            Object.values(window.chartInstances).forEach(chart => chart?.resize());
            window.rewardTrendChart?.resize();
            window.rewardCategoryChart?.resize();
        };

});
</script>

<?php include "includes/layout_end.php"; ?>

</body>
</html>
