<?php

session_start();
include("db_connect.php");


$timeFilter = $_GET['time'] ?? 'all';
$timeSQL = ""; 

if ($timeFilter === '7') {
    $timeSQL = " AND uploaded_at >= NOW() - INTERVAL 7 DAY ";
}
elseif ($timeFilter === '30') {
    $timeSQL = " AND uploaded_at >= NOW() - INTERVAL 30 DAY ";
}

///////////////////////////////////////////////////////////////////////////////////////////////////////

// --- Fetch User Points Safely ---
$sql_points = "SELECT walletPoint AS totalPoints FROM user WHERE userID = ?";
$stmt_points = $conn->prepare($sql_points);

if ($stmt_points) {
    $stmt_points->bind_param("i", $userID);
    $stmt_points->execute();
    $result = $stmt_points->get_result();

    $myPoints = 0; // default

    if ($result && $row = $result->fetch_assoc()) {
        $myPoints = (int)$row['totalPoints'];
    }

    $stmt_points->close();
} else {
    // fallback if prepare fails
    $myPoints = 0;
}

///////////////////////////////////////////////////////////////////////////////////////////////////////

$sql_subs = "
    SELECT 
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approvedCount,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pendingCount,
        SUM(CASE WHEN status = 'Denied' THEN 1 ELSE 0 END) AS deniedCount,
        COUNT(submissionID) AS totalSubmission
    FROM sub
    WHERE userID = ? $timeSQL
";

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
$sql_sub_details = "
    SELECT s.status, s.pointEarned, s.reviewNote, s.uploaded_at, c.challengeTitle
    FROM sub s
    JOIN challenge c ON s.challengeID = c.challengeID
    WHERE s.userID = ? $timeSQL
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


$userChallenges = [];
$sql_challenges = "
    SELECT 
        c.challengeID, c.challengeTitle, c.description, c.pointAward,
        MAX(s.uploaded_at) AS lastSubmitted,
        GROUP_CONCAT(s.status) AS all_statuses,
        COUNT(s.submissionID) AS submissionCount
    FROM challenge c
    INNER JOIN sub s ON c.challengeID = s.challengeID AND s.userID = ?
    GROUP BY c.challengeID, c.challengeTitle, c.description, c.pointAward
    ORDER BY lastSubmitted DESC
";
$stmt_challenges = $conn->prepare($sql_challenges);
$stmt_challenges->bind_param("i", $userID);
$stmt_challenges->execute();
$res_challenges = $stmt_challenges->get_result();

while($row = $res_challenges->fetch_assoc()){
    $status = 'Not Started'; 
    $allStatuses = (string)($row['all_statuses'] ?? '');

    if ($row['lastSubmitted'] !== null) {
        if (strpos($allStatuses, 'Pending') !== false) {
            $status = 'In Review';
        } elseif (strpos($allStatuses, 'Approved') !== false) {
            $status = 'Completed';
        } elseif (strpos($allStatuses, 'Denied') !== false) {
             $status = 'Denied/Try Again';
        }
    }
    
    $userChallenges[] = [
        'id' => $row['challengeID'],
        'name' => $row['challengeTitle'],
        'description' => $row['description'],
        'points' => $row['pointAward'],
        'times' => $row['submissionCount'],
        'status' => $status,
        'last' => $row['lastSubmitted'] ? date('Y-m-d', strtotime($row['lastSubmitted'])) : null
    ];
}
$stmt_challenges->close();

///////////////////////////////////////////////////////////////////////////////////////////////////////
//reward section

// 1. Security Check
if (!isset($_SESSION['userID'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['userID'];

// 2. Data Fetching for Dashboard

// A. User Summary Stats
$stmt = $conn->prepare("SELECT walletPoint, 
    (SELECT SUM(pointSpent) FROM redemptionrequest WHERE userID = ?) as totalSpent,
    (SELECT COUNT(*) FROM redemptionrequest WHERE userID = ?) as totalRedeemed
    FROM user WHERE userID = ?");
$stmt->bind_param("iii", $userID, $userID, $userID);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$currentPoints = $summary['walletPoint'];
$totalSpent = $summary['totalSpent'] ?? 0;
$totalRedeemed = $summary['totalRedeemed'] ?? 0;
$stmt->close();

// B. Chart Data 1: Points Spent Trend (根据 filter)
$trendLabels = [];
$trendData = [];

$trendSql = "SELECT DATE(requested_at) AS date, SUM(pointSpent) AS total 
             FROM redemptionrequest 
             WHERE userID = $userID $timeSQL
             GROUP BY DATE(requested_at)
             ORDER BY DATE(requested_at) ASC";
$trendRes = $conn->query($trendSql);

while($row = $trendRes->fetch_assoc()){
    $trendLabels[] = $row['date'];
    $trendData[] = (int)($row['total'] ?? 0);
}

// C. Chart Data 2: Category Distribution (Products vs Vouchers) 根据 filter
$catLabels = [];
$catData = [];

$catSql = "SELECT r.category, COUNT(*) AS count
           FROM redemptionrequest rr
           JOIN reward r ON rr.rewardID = r.rewardID
           WHERE rr.userID = $userID $timeSQL
           GROUP BY r.category";
$catRes = $conn->query($catSql);

while($row = $catRes->fetch_assoc()){
    $catLabels[] = ucfirst($row['category']);
    $catData[] = (int)$row['count'];
}

// D. Recent Activity List 根据 filter
$recentSql = "SELECT rr.*, r.rewardName, r.imageURL, r.category
              FROM redemptionrequest rr
              JOIN reward r ON rr.rewardID = r.rewardID
              WHERE rr.userID = $userID $timeSQL
              ORDER BY rr.requested_at DESC LIMIT 5";
$recentRes = $conn->query($recentSql);


///////////////////////////////////////////////////////////////////////////////////////////////////////
//team rank section
$userHasTeam = false;
$teamRankMessage = '';
$teamRank = [];
$personalRank = null;

$sql_team_id = "SELECT teamID FROM user WHERE userID = ?";
$stmt = $conn->prepare($sql_team_id);
$stmt->bind_param("i", $userID);
$stmt->execute();
$userTeam = $stmt->get_result()->fetch_assoc();
$stmt->close();

$teamID = $userTeam['teamID'] ?? null;

if ($teamID === null) {

    $teamRankMessage = "You are not part of any team yet. Join a team to see your rank!";
} else {
    $userHasTeam = true;

    $sql_leaderboard = "
        SELECT 
            u.userID,
            u.firstName,
            u.lastName,
            SUM(pt.pointsTransaction) AS totalPoints
        FROM user u
        LEFT JOIN pointtransaction pt ON u.userID = pt.userID
        WHERE u.teamID = ?
        GROUP BY u.userID, u.firstName, u.lastName
        ORDER BY totalPoints DESC
    ";

    $stmt2 = $conn->prepare($sql_leaderboard);
    $stmt2->bind_param("i", $teamID);
    $stmt2->execute();
    $res = $stmt2->get_result();

    $rankCounter = 1;
    while($row = $res->fetch_assoc()){
        $fullName = $row['firstName'] . ' ' . substr($row['lastName'], 0, 1) . '.';
        $name = ($row['userID'] == $userID) ? 'You' : $fullName;

        $teamRank[] = [
            'name' => $name,
            'value' => (int)($row['totalPoints'] ?? 0)
        ];

        if ($row['userID'] == $userID) {
            $personalRank = $rankCounter;
        }
        $rankCounter++;
    }

    $stmt2->close();
}

include "includes/layout_start.php";    

if (isset($conn) && $conn->ping()) {
    $conn->close();
}

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
                        <p class="text-dark-2 mt-1">View all current data</p>
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

                                            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="stat-card-label"> My Points</p>
                                <h3 class="stat-card-value mt-1"><?= number_format($myPoints); ?></h3>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                                <i class="fas fa-coins text-xl"> </i> 
                            </div>
                        </div>
                    </div>
                                            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="stat-card-label">Approved Submission</p>
                                <h3 class="stat-card-value mt-1"><?= number_format($approvedCount); ?></h3>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-secondary/10 flex items-center justify-center text-secondary">
                                <i class="fas fa-check-circle text-xl"> </i>
                            </div>
                        </div>
                    </div>
                                            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="stat-card-label"> Pending Submission </p>
                                <h3 class="stat-card-value mt-1"><?= number_format($pendingCount); ?></h3>
                            </div>

                            <div class="w-12 h-12 rounded-lg bg-warning/10 flex items-center justify-center text-warning">
                                <i class="fas fa-hourglass-half text-xl"> </i>
                            </div>
                        </div>
                    </div>
                                            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="stat-card-label">Total Submission</p>
                                <h3 class="stat-card-value mt-1"><?= number_format($totalSubmission); ?></h3>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-info/10 flex items-center justify-center text-info">
                                <i class="fas fa-list-alt text-xl"> </i> 
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-lg p-6">

                    <div class="flex border-b border-light-2 mb-6 space-x-6">

                        <button class="py-3 px-4 text-sm font-semibold text-primary border-b-2 border-primary transition-all" onclick="showTab('charts')" id="tab-charts"> Overview </button>
                        <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('user')" id="tab-user"> My Submissions </button>
                        <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('tables')" id="tab-tables"> My Challenges</button>
                        <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('reward')" id="tab-reward"> Rewards </button>

                    </div>

                    <div id="charts" class="tab-content p-4 space-y-6">                            
                    <div class="bg-white shadow rounded p-4">
                        
                    <div class="text-gray-500 mb-2">Team & Personal Rank</div>
                    
                    <?php if ($userHasTeam && !empty($teamRank)) : ?>
                        <div id="teamRankChart" style="height: 200px;"></div>
                        <p class="text-sm mt-2 text-gray-500">Your Personal Rank: <?= $personalRank ?></p>

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


                      <div class="bg-white shadow rounded p-4">
                        <div class="text-gray-500 mb-2">Recent Submissions</div>
                        <div id="submissionStatusChart" style="height: 250px;"></div>
                    </div>
                </div>

                <div>
                   <div id="user" class="tab-content hidden p-4 space-y-6">
                    
                        <div class="bg-white shadow rounded p-4">
                        <div class="text-gray-500 mb-2">Submission Status Overview</div>
                        <div id="submissionStatusBarChart" style="height: 250px;"></div>
                    </div>

                </div>

                <div id="tables" class="tab-content hidden p-4">
                    <h3 class="text-lg font-semibold text-dark mb-4">My Challenges</h3>

                    <div id="challengeChart" style="height: 300px;"></div>

                </div>


                <div id="reward" class="tab-content hidden p-4 space-y-6">
                    <div class="row g-4 mb-4">
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="card-label">Current Wallet</div>
                                        <div class="card-value"><?php echo number_format($currentPoints); ?></div>
                                        <div class="card-sub text-success"><i class="fas fa-arrow-up"></i> Available to spend</div>
                                    </div>
                                    <div class="icon-box bg-success bg-opacity-10 p-3 rounded-circle text-success">
                                        <iconify-icon icon="solar:wallet-money-bold" style="font-size: 24px;"></iconify-icon>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="card-label">Total Spent</div>
                                        <div class="card-value text-purple"><?php echo number_format($totalSpent); ?></div>
                                        <div class="card-sub">Lifetime points burned</div>
                                    </div>
                                    <div class="icon-box bg-purple bg-opacity-10 p-3 rounded-circle text-purple">
                                        <iconify-icon icon="solar:fire-bold" style="font-size: 24px;"></iconify-icon>
                                    </div>
                                </div>
                                <div class="bg-circle bg-purple"></div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="card-label">Total Redemptions</div>
                                        <div class="card-value text-orange"><?php echo number_format($totalRedeemed); ?></div>
                                        <div class="card-sub">Items & Vouchers claimed</div>
                                    </div>
                                    <div class="icon-box bg-orange bg-opacity-10 p-3 rounded-circle text-orange">
                                        <iconify-icon icon="solar:bag-heart-bold" style="font-size: 24px;"></iconify-icon>
                                    </div>
                                </div>
                                <div class="bg-circle bg-orange"></div>
                            </div>
                        </div>
                    </div>

                     <div class="row g-4 mb-4">
                        <div class="col-lg-8">
                            <div class="stat-card">
                                <h5 class="fw-bold mb-4">Spending Analysis</span></h5>
                                <div class="chart-container">
                                    <canvas id="trendChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="stat-card">
                                <h5 class="fw-bold mb-4">Reward Categories</h5>
                                <div class="chart-container" style="height: 250px;">
                                    <canvas id="categoryChart"></canvas>
                                </div>
                                <div class="text-center mt-3">
                                    <small class="text-muted">Distribution of your redemptions</small>
                                </div>
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

    document.addEventListener('DOMContentLoaded', function () {

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

            // --- User Tab Chart ---
            if(tabId === 'user' && !window.userChartInitialized){
                const userChartEl = document.getElementById('submissionStatusBarChart');
                if(userChartEl){
                    window.userChart = echarts.init(userChartEl);

                    const statusCounts = {
                        'Approved': <?= (int)$approvedCount; ?>,
                        'Pending': <?= (int)$pendingCount; ?>,
                        'Denied': <?= (int)$deniedCount; ?>
                    };

                    window.userChart.setOption({
                        title: { text: 'Submission Status Overview', left: 'center', textStyle:{fontSize:14} },
                        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                        xAxis: { type:'category', data: Object.keys(statusCounts) },
                        yAxis: { type:'value' },
                        series: [{
                            type:'bar',
                            data: Object.values(statusCounts),
                            itemStyle: {
                                color: params => {
                                    const name = Object.keys(statusCounts)[params.dataIndex];
                                    return name==='Approved' ? '#22c55e' : name==='Pending' ? '#facc15' : '#ef4444';
                                }
                            },
                            label: { show: true, position: 'top' }
                        }]
                    });

                    window.userChartInitialized = true;
                    window.userChart.resize(); 
                }
            }

            // --- Challenge Tab Chart ---
            if(tabId === 'tables' && !window.challengeChartInitialized){
                const challengeEl = document.getElementById('challengeChart');
                if(challengeEl){
                    const challengeData = <?php echo json_encode($userChallenges); ?>;
                    const names = challengeData.map(c => c.name);
                    const points = challengeData.map(c => c.points);
                    const times = challengeData.map(c => c.times);
                    const statusColors = challengeData.map(c => 
                        c.status === 'Completed' ? '#22c55e' :
                        c.status === 'In Review' ? '#facc15' :
                        c.status === 'Denied/Try Again' ? '#ef4444' : '#9ca3af'
                    );

                    window.challengeChart = echarts.init(challengeEl);
                    window.challengeChart.setOption({
                        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                        xAxis: { type: 'category', data: names, axisLabel:{interval:0, rotate:30} },
                        yAxis: { type: 'value', name: 'Points / Times' },
                        series: [{
                            type: 'bar',
                            data: points.map((p,i)=>({
                                value: p,
                                itemStyle: { color: statusColors[i] },
                                label: { show: true, position: 'top', formatter: times[i] + ' times' }
                            }))
                        }]
                    });

                    window.challengeChartInitialized = true;
                    window.challengeChart.resize();
                }
            }

            // --- Reward Tab Charts ---
            if(tabId === 'reward' && !window.rewardChartsLoaded){
                // Line Chart (Points Trend)
                const ctxTrendEl = document.getElementById('trendChart');
                if(ctxTrendEl){
                    const ctxTrend = ctxTrendEl.getContext('2d');
                    const gradient = ctxTrend.createLinearGradient(0,0,0,400);
                    gradient.addColorStop(0,'rgba(139,92,246,0.5)');
                    gradient.addColorStop(1,'rgba(139,92,246,0.0)');

                    window.rewardTrendChart = new Chart(ctxTrend,{
                        type:'line',
                        data:{
                            labels: <?= json_encode($trendLabels ?? []) ?>,
                            datasets:[{
                                label:'Points Spent',
                                data: <?= json_encode($trendData ?? []) ?>,
                                borderColor:'#8b5cf6',
                                backgroundColor: gradient,
                                borderWidth:3,
                                tension:0.4,
                                fill:true,
                                pointBackgroundColor:'#ffffff',
                                pointBorderColor:'#8b5cf6',
                                pointBorderWidth:2,
                                pointRadius:6,
                                pointHoverRadius:8
                            }]
                        },
                        options:{
                            responsive:true,
                            maintainAspectRatio:false,
                            plugins:{
                                legend:{ display:false },
                                tooltip:{
                                    backgroundColor:'#1e293b',
                                    padding:12,
                                    titleFont:{ size:13 },
                                    bodyFont:{ size:14, weight:'bold' }
                                }
                            },
                            scales:{
                                y:{
                                    beginAtZero:true,
                                    grid:{ borderDash:[5,5], color:'#f1f5f9' },
                                    ticks:{ font:{ family:"'Plus Jakarta Sans', sans-serif" } }
                                },
                                x:{
                                    grid:{ display:false },
                                    ticks:{ font:{ family:"'Plus Jakarta Sans', sans-serif" } }
                                }
                            }
                        }
                    });
                }

                // Doughnut Chart (Category)
                const ctxCatEl = document.getElementById('categoryChart');
                if(ctxCatEl){
                    const ctxCat = ctxCatEl.getContext('2d');
                    window.rewardCategoryChart = new Chart(ctxCat,{
                        type:'doughnut',
                        data:{
                            labels: <?= json_encode($catLabels ?? []) ?>,
                            datasets:[{
                                data: <?= json_encode($catData ?? []) ?>,
                                backgroundColor:['#3b82f6','#10b981','#f59e0b','#6366f1'],
                                borderWidth:0,
                                hoverOffset:4
                            }]
                        },
                        options:{
                            responsive:true,
                            maintainAspectRatio:false,
                            cutout:'75%',
                            plugins:{
                                legend:{
                                    position:'bottom',
                                    labels:{ usePointStyle:true, padding:20, font:{ family:"'Plus Jakarta Sans'" } }
                                }
                            }
                        }
                    });
                }

                window.rewardChartsLoaded = true;
            }
        }

        // expose showTab globally
        window.showTab = showTab;

        // --- Team & Personal Rank Chart ---
        const teamRankData = <?= json_encode($teamRank); ?>;
        if(teamRankData.length>0){
            const teamRankChart = echarts.init(document.getElementById('teamRankChart'));
            const yMin = Math.max(0, Math.min(...teamRankData.map(t=>t.value))-100);
            teamRankChart.setOption({
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
        }

        // --- Submission Status Pie Chart ---
        const submissionStatusChart = echarts.init(document.getElementById('submissionStatusChart'));
        submissionStatusChart.setOption({
            tooltip: { trigger: 'item' },
            legend: { show: false },
            series: [{
                type:'pie',
                radius:['40%','75%'],
                center:['50%','50%'],
                minAngle:5,
                label:{
                    position:'right',
                    alignTo:'labelLine',
                    formatter:'{b}: {c} ({d}%)',
                    fontSize:12
                },
                labelLine:{
                    length:15,
                    length2:5,
                    maxSurfaceAngle:120
                },
                data:[
                    {value: <?= $approvedCount ?>, name:'Approved', itemStyle:{color:'#22c55e'}},
                    {value: <?= $pendingCount ?>, name:'Pending', itemStyle:{color:'#eab308'}},
                    {value: <?= $deniedCount ?>, name:'Denied', itemStyle:{color:'#ef4444'}}
                ]
            }]
        });

        // --- Resize charts on window resize ---
        window.addEventListener('resize', function(){
            if(typeof teamRankChart !== 'undefined') teamRankChart.resize();
            if(typeof submissionStatusChart !== 'undefined') submissionStatusChart.resize();
            if(window.userChart) window.userChart.resize();
            if(window.challengeChart) window.challengeChart.resize();
            if(window.rewardTrendChart) window.rewardTrendChart.resize();
            if(window.rewardCategoryChart) window.rewardCategoryChart.resize();
        });

        // Show default tab
        showTab('charts');
    });
</script>

<?php include "includes/layout_end.php"; ?>

</body>
</html>
