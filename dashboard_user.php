<?php

session_start();
include("db_connect.php");
require_once "includes/auth.php";  // <--- REQUIRED FIRST

if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}


$timeFilter = $_GET['time'] ?? 'all';
$timeSQL = ""; 

if ($timeFilter === '7') {
    $timeSQL = " AND uploaded_at >= NOW() - INTERVAL 7 DAY ";
}
elseif ($timeFilter === '30') {
    $timeSQL = " AND uploaded_at >= NOW() - INTERVAL 30 DAY ";
}


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

$availableRewards = [];
$sql_rewards = "SELECT rewardName, description, pointRequired FROM reward ORDER BY pointRequired ASC";
$res_rewards = $conn->query($sql_rewards);
while($row = $res_rewards->fetch_assoc()){
    $availableRewards[] = [
        'name' => $row['rewardName'],
        'points' => $row['pointRequired'],
        'status' => ($myPoints >= $row['pointRequired']) ? 'available' : 'locked' 
    ];
}

$sql_used_points = "SELECT SUM(r.pointRequired) AS usedPoints 
                    FROM redemptionrequest rr
                    JOIN reward r ON rr.rewardID = r.rewardID
                    WHERE rr.userID = ? AND rr.status = 'Approved'"; 
$stmt_used = $conn->prepare($sql_used_points);
$stmt_used->bind_param("i", $userID);
$stmt_used->execute();
$res_used = $stmt_used->get_result()->fetch_assoc();
$usedPoints = $res_used['usedPoints'] ?? 0;
$stmt_used->close();

$availablePoints = $myPoints - $usedPoints; 

$claimedRewards = [];
$sql_claimed = "
    SELECT r.rewardName, rr.requested_at
    FROM redemptionrequest rr
    JOIN reward r ON rr.rewardID = r.rewardID
    WHERE rr.userID = ? AND rr.status = 'Approved'
    ORDER BY rr.requested_at DESC
";
$stmt_claimed = $conn->prepare($sql_claimed);
$stmt_claimed->bind_param("i", $userID);
$stmt_claimed->execute();
$res_claimed = $stmt_claimed->get_result();

while($row = $res_claimed->fetch_assoc()){

    $claimedRewards[] = [
        'name' => $row['rewardName'],
        'date' => date('Y-m-d', strtotime($row['requested_at'])) 
    ];
}
$stmt_claimed->close();

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

                                    <div class="bg-white shadow rounded p-4">
                        <div class="text-gray-500 mb-2">Points Overview</div>
                        <div id="rewardChart" style="height: 250px;"></div>
                    </div>

                                    <div class="bg-white shadow rounded p-4">
                        <h3 class="text-lg font-semibold text-dark mb-4">Available Rewards</h3>
                        <div id="rewardBarChart" style="height: 250px;"></div>
                    </div>

                  <div class="bg-white shadow rounded p-6">
                    <h3 class="text-lg font-semibold text-dark mb-4">Claimed Rewards</h3>

                    <?php if (empty($claimedRewards)): ?>
                        <p class="text-center text-gray-400 py-4">No claimed rewards found.</p>
                    <?php else: ?>
                        <div class="relative border-l-2 border-gray-200 ml-4">
                        <?php foreach ($claimedRewards as $reward): ?>
                            <div class="mb-6 ml-4">
                                <div class="absolute w-3 h-3 bg-primary rounded-full -left-[7px] mt-1.5"></div>
                                <p class="text-dark font-medium"><?= htmlspecialchars($reward['name']); ?></p>
                                <p class="text-sm text-dark-2">Claimed on: <?= htmlspecialchars($reward['date']); ?></p>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
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
  // preserve other query params if you want (e.g., status) - currently only time is used
  window.location.href = url.toString();
}



document.addEventListener('DOMContentLoaded', function () {

    function showTab(tabId) {

        document.querySelectorAll('.tab-content').forEach(tc => tc.classList.add('hidden'));

        document.querySelectorAll('[id^="tab-"]').forEach(btn => {
            btn.classList.remove('text-primary','border-b-2','border-primary');
            btn.classList.add('text-dark-2','hover:text-dark','hover:border-dark/20');
        });

        const tab = document.getElementById(tabId);
        if(tab) tab.classList.remove('hidden');

        const btn = document.querySelector(`[onclick="showTab('${tabId}')"]`);
        if(btn){
            btn.classList.add('text-primary','border-b-2','border-primary');
            btn.classList.remove('text-dark-2','hover:text-dark','hover:border-dark/20');
        }

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

        if(tabId === 'reward' && !window.rewardChartInitialized){

            const rewardChartEl = document.getElementById('rewardChart');
            if(rewardChartEl){
                window.rewardChart = echarts.init(rewardChartEl);

const totalUserPoints = <?= (int)($usedPoints + $availablePoints); ?>;

window.rewardChart.setOption({
    title: {
        text: totalUserPoints,
        subtext: 'Total Points',
        left: 'center',
        top: '36%', 
        textStyle: {
            fontSize: 26,
            fontWeight: 'bold',
            color: '#1f2937'
        },
        subtextStyle: {
            fontSize: 12,
            color: '#6b7280'
        }
    },
    tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
    legend: {
        bottom: 0,
        itemWidth: 12,
        itemHeight: 12,
        textStyle: { color: '#374151' }
    },
    series: [{
        type: 'pie',
        radius: ['55%', '75%'],
         center: ['50%', '48%'], 
        avoidLabelOverlap: true,
        label: { show: false },
        labelLine: { show: false },
        itemStyle: {
            borderWidth: 3,
            borderColor: '#fff'
        },
        data: [
            { value: <?= $usedPoints ?>, name: 'Used Points', itemStyle:{color:'#60a5fa'} },
            { value: <?= $availablePoints ?>, name: 'Available Points', itemStyle:{color:'#34d399'} }
        ]
    }]
});

                window.rewardChart.resize();
            }

            const rewardBarEl = document.getElementById('rewardBarChart');
            if(rewardBarEl){
                const rewardsData = <?= json_encode($availableRewards); ?>;
                window.rewardBarChart = echarts.init(rewardBarEl);
                window.rewardBarChart.setOption({
                    title: { text: 'Available Rewards', left: 'center', textStyle:{fontSize:14} },
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                    xAxis: { type:'category', data: rewardsData.map(r=>r.name), axisLabel:{interval:0, rotate:30} },
                    yAxis: { type:'value', name:'Points Required' },
                    series: [{
                        type:'bar',
                        data: rewardsData.map(r=>({ value:r.points, itemStyle:{ color: r.status==='available' ? '#22c55e':'#9ca3af' } })),
                        label: { show:true, position:'top' }
                    }]
                });
                window.rewardBarChart.resize();
            }

            window.rewardChartInitialized = true;
        }
    }

    window.showTab = showTab;

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

    const submissionStatusData = [
    { name:'Approved', value: <?= (int)$approvedCount; ?>, itemStyle:{color:'#22c55e'} },
    { name:'Pending', value: <?= (int)$pendingCount; ?>, itemStyle:{color:'#facc15'} },
    { name:'Denied', value: <?= (int)$deniedCount; ?>, itemStyle:{color:'#ef4444'} }
].filter(item => item.value > 0);

const submissionStatusChart = echarts.init(document.getElementById('submissionStatusChart'));

submissionStatusChart.setOption({
    tooltip: { trigger: 'item' },
    legend: { show: false },
    series: [{
        type: 'pie',
        radius: ['40%', '75%'],
        center: ['50%', '50%'],
        minAngle: 5,
        label: {
            position: 'right',
            alignTo: 'labelLine',
            formatter: '{b}: {c} ({d}%)',
            fontSize: 12
        },
        labelLine: {
            length: 15,
            length2: 5,
            maxSurfaceAngle: 120
        },
        data: [
            {value: <?= $approvedCount ?>, name:'Approved', itemStyle:{color:'#22c55e'}},
            {value: <?= $pendingCount ?>, name:'Pending', itemStyle:{color:'#eab308'}},
            {value: <?= $deniedCount ?>, name:'Denied', itemStyle:{color:'#ef4444'}}
        ]
    }]
});

    window.addEventListener('resize', function(){
        if(teamRankChart) teamRankChart.resize();
        if(submissionStatusChart) submissionStatusChart.resize();
        if(window.userChart) window.userChart.resize();
        if(window.challengeChart) window.challengeChart.resize();
        if(window.rewardChart) window.rewardChart.resize();
        if(window.rewardBarChart) window.rewardBarChart.resize();
    });

    showTab('charts');
});
</script>


<?php include "includes/layout_end.php"; ?>
</body>
</html>
