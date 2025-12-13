<?php
session_start();
require_once "db_connect.php";

// -----------------------------
// Time filter: 'all' | '7' | '30'
// -----------------------------
$timeFilter = $_GET['time'] ?? 'all';
$timeInterval = null;
if ($timeFilter === '7') $timeInterval = 7;
elseif ($timeFilter === '30') $timeInterval = 30;

// helper to return SQL condition (string or empty)
function time_sql_condition($col, $interval) {
    if (!$interval) return "";
    return " AND {$col} >= DATE_SUB(CURDATE(), INTERVAL {$interval} DAY) ";
}

// -----------------------------
// 1. Total Users (respect time filter: count created within range when filtered)
// -----------------------------
if ($timeInterval) {
    $totalUsers = (int)($conn->query("SELECT COUNT(*) AS cnt FROM user WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL {$timeInterval} DAY)")->fetch_assoc()['cnt'] ?? 0);
} else {
    $totalUsers = (int)($conn->query("SELECT COUNT(*) AS cnt FROM user")->fetch_assoc()['cnt'] ?? 0);
}

// ----------------------------------
// 2. New Users (Last 7 days OR last interval)
// ----------------------------------
// For display we always return last 7 days series for charting; but counts reflect chosen time scope when applicable.
// We'll return DB rows within last (30 days) if timeInterval==30, else last 7 days if 7, else last 7 days by default.
$trendDaysBack = $timeInterval ?? 7;
$newUsers = $conn->query("
    SELECT COUNT(*) AS cnt, DATE(created_at) AS day
    FROM user
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL {$trendDaysBack} DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 3. Active Teams
//   We'll count teams that have had user activity in the timeframe if filtered, else total teams
// ----------------------------------
if ($timeInterval) {
    // count distinct teams of users created in timeframe
    $activeTeams = (int)($conn->query("
        SELECT COUNT(DISTINCT t.teamID) AS cnt
        FROM team t
        JOIN user u ON u.teamID = t.teamID
        WHERE u.created_at >= DATE_SUB(CURDATE(), INTERVAL {$timeInterval} DAY)
    ")->fetch_assoc()['cnt'] ?? 0);
} else {
    $activeTeams = (int)($conn->query("SELECT COUNT(*) AS cnt FROM team")->fetch_assoc()['cnt'] ?? 0);
}


$challengeStatus = $conn->query("
    SELECT 
        SUM(CASE WHEN is_active = '1' THEN 1 ELSE 0 END) AS activeCount,
        SUM(CASE WHEN is_active = '0' THEN 1 ELSE 0 END) AS inactiveCount
    FROM challenge
")->fetch_assoc();

$activeCount = (int)$challengeStatus['activeCount'];
$inactiveCount = (int)$challengeStatus['inactiveCount'];


$cityData = $conn->query("
    SELECT city, COUNT(*) AS cnt
    FROM challenge
    WHERE is_active = 1
    GROUP BY city
")->fetch_all(MYSQLI_ASSOC);


$cityLabels = array_column($cityData, 'city');
$cityCounts = array_column($cityData, 'cnt');


$catData = $conn->query("
    SELECT c.categoryName AS category, COUNT(*) AS cnt
    FROM challenge ch
    JOIN category c ON ch.categoryID = c.categoryID
    GROUP BY c.categoryName
")->fetch_all(MYSQLI_ASSOC);

$catLabels = array_column($catData, 'category');
$catCounts = array_column($catData, 'cnt');


$pointsData = $conn->query("
    SELECT challengeTitle, pointAward
    FROM challenge
    WHERE is_active = 1
    ORDER BY pointAward DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);


$topPointsLabels = array_column($pointsData, 'challengeTitle');
$topPointsValues = array_column($pointsData, 'pointAward');

$expiringChallenges = $conn->query("
    SELECT challengeTitle, city, end_date
    FROM challenge
    WHERE is_active = 1
      AND end_date >= CURDATE()
    ORDER BY end_date ASC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);



// ----------------------------------
// 4. Total Submissions (respect time filter)
// ----------------------------------
if ($timeInterval) {
    $totalSubmissions = (int)($conn->query("SELECT COUNT(*) AS cnt FROM sub WHERE uploaded_at >= DATE_SUB(CURDATE(), INTERVAL {$timeInterval} DAY)")->fetch_assoc()['cnt'] ?? 0);
} else {
    $totalSubmissions = (int)($conn->query("SELECT COUNT(*) AS cnt FROM sub")->fetch_assoc()['cnt'] ?? 0);
}

// ----------------------------------
// 5. Submission counts by status (respect time filter)
// ----------------------------------
$submissionStatusQuery = $conn->query("
    SELECT status, COUNT(*) AS cnt
    FROM sub
    WHERE 1 " . ($timeInterval ? " AND uploaded_at >= DATE_SUB(CURDATE(), INTERVAL {$timeInterval} DAY) " : "") . "
    GROUP BY status
")->fetch_all(MYSQLI_ASSOC);

$submissionCounts = ['pending'=>0,'approved'=>0,'denied'=>0];

foreach ($submissionStatusQuery as $row) {
    $key = strtolower(trim($row['status']));
    if (isset($submissionCounts[$key])) {
        $submissionCounts[$key] = (int)$row['cnt'];
    }
}

// ----------------------------------
// 6. Recent Submissions (most recent 10 within timeframe if filtered else overall)
// ----------------------------------
$recentLimitWhere = $timeInterval ? " WHERE s.uploaded_at >= DATE_SUB(CURDATE(), INTERVAL {$timeInterval} DAY) " : "";
$recentSubmissions = $conn->query("
    SELECT s.*, u.firstName AS username, t.teamName, c.challengeTitle 
    FROM sub s
    LEFT JOIN user u ON s.userID = u.userID
    LEFT JOIN team t ON u.teamID = t.teamID
    LEFT JOIN challenge c ON s.challengeID = c.challengeID
    {$recentLimitWhere}
    ORDER BY s.uploaded_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 7. Submission Trend (Last 30 days, but we apply time filter interval if set)
// ----------------------------------
$trendWindow = $timeInterval ?? 30; // if user picked 7 use 7, if 30 use 30, else default 30
$submissionTrend = $conn->query("
    SELECT DATE(uploaded_at) AS day,
        SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status='Denied' THEN 1 ELSE 0 END) AS denied
    FROM sub
    WHERE uploaded_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)
    GROUP BY DATE(uploaded_at)
    ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);



// ----------------------------------
// 8. Top Categories (challenge) within timeframe
// ----------------------------------
$topCategories = $conn->query("
    SELECT COALESCE(c.challengeTitle, 'Unknown') AS category, COUNT(*) AS cnt
    FROM sub s
    LEFT JOIN challenge c ON s.challengeID = c.challengeID
    WHERE s.uploaded_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)
    GROUP BY s.challengeID
    ORDER BY cnt DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 9. Users by Role distribution (not strictly time bound — show current distribution)
//    If you prefer to make this time-bound, change accordingly.
// ----------------------------------
$roleDistribution = [];
$roleData = $conn->query("SELECT role, COUNT(*) AS cnt FROM user GROUP BY role")->fetch_all(MYSQLI_ASSOC);
foreach ($roleData as $row) $roleDistribution[$row['role']] = (int)$row['cnt'];

// ----------------------------------
// 10. Login trend (last 7 or last interval)
// ----------------------------------
$loginWindow = $timeInterval ?? 7;
$loginTrend = $conn->query("
    SELECT DATE(last_online) AS date, COUNT(*) AS count
    FROM user
    WHERE last_online >= DATE_SUB(CURDATE(), INTERVAL {$loginWindow} DAY)
    GROUP BY DATE(last_online)
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 11. Submission Activity Top Users (count submissions within timeframe)
// ----------------------------------
$submissionActivity = $conn->query("
    SELECT u.firstName AS name, COUNT(s.submissionID) AS submissions
    FROM user u
    LEFT JOIN sub s ON u.userID = s.userID AND s.uploaded_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)
    GROUP BY u.userID
    ORDER BY submissions DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 12. Points Earned vs Burned (transactions within timeframe)
//    assumes pointtransaction.created_at exists
// ----------------------------------
$pointsActivity = $conn->query("
    SELECT u.firstName AS name,
        COALESCE(SUM(CASE WHEN p.transactionType='earned' THEN p.pointsTransaction ELSE 0 END),0) AS earned,
        COALESCE(SUM(CASE WHEN p.transactionType='burned' THEN p.pointsTransaction ELSE 0 END),0) AS burned
    FROM user u
    LEFT JOIN pointtransaction p ON p.userID = u.userID AND p.generate_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)
    GROUP BY u.userID
    ORDER BY earned DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 13. User Details (not needed if you removed tables; return minimal if wanted)
// ----------------------------------
$userDetails = $conn->query("
    SELECT u.userID as id, u.firstName AS name, u.email, u.role,
           t.teamName,
           (SELECT COUNT(*) FROM sub s WHERE s.userID=u.userID AND s.uploaded_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)) AS submissions,
           (SELECT COALESCE(SUM(pointsTransaction),0) FROM pointtransaction p WHERE p.userID=u.userID AND p.transactionType='earned' AND p.generate_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)) AS earned,
           (SELECT COALESCE(SUM(pointsTransaction),0) FROM pointtransaction p WHERE p.userID=u.userID AND p.transactionType='burned' AND p.generate_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)) AS burned,
           u.last_online AS lastLogin
    FROM user u
    LEFT JOIN team t ON u.teamID = t.teamID
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 14. Rewards: use redemptionrequest as data source (Option A)
//    count redemptions per reward within timeframe
// ----------------------------------
$rewards = $conn->query("
    SELECT r.rewardID, r.rewardName, COALESCE(rr.cnt,0) AS redeemedQuantity
    FROM reward r
    LEFT JOIN (
        SELECT rewardID, COUNT(*) AS cnt
        FROM redemptionrequest
        WHERE status='Approved' AND fulfilled_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)
        GROUP BY rewardID
    ) rr ON rr.rewardID = r.rewardID
")->fetch_all(MYSQLI_ASSOC);

$totalStock = (int)($conn->query("SELECT COALESCE(SUM(stockQuantity),0) AS stock FROM reward")->fetch_assoc()['stock'] ?? 0);

// low stock - not time dependent
$lowStockRewards = $conn->query("SELECT rewardName, stockQuantity FROM reward WHERE stockQuantity <= 3")->fetch_all(MYSQLI_ASSOC);

// total redeemed (within timeframe)
$totalRedeemed = (int)($conn->query("
    SELECT COUNT(*) AS cnt
    FROM redemptionrequest
    WHERE status='Approved' AND fulfilled_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)
")->fetch_assoc()['cnt'] ?? 0);

// top redeemers (within timeframe)
$topRedeemers = $conn->query("
    SELECT u.firstName, COUNT(*) AS cnt
    FROM redemptionrequest r
    JOIN user u ON r.userID = u.userID
    WHERE r.status='Approved' AND r.fulfilled_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)
    GROUP BY r.userID
    ORDER BY cnt DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

$redeemTrend = $conn->query("
    SELECT DATE(fulfilled_at) AS day, COUNT(*) AS cnt
    FROM redemptionrequest
    WHERE status='Approved'
    AND fulfilled_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)
    GROUP BY DATE(fulfilled_at)
    ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);


$topCategories = $conn->query("
    SELECT c.categoryName AS category, COUNT(*) AS cnt
    FROM sub s
    LEFT JOIN challenge ch ON s.challengeID = ch.challengeID
    LEFT JOIN category c ON ch.categoryID = c.categoryID
    WHERE s.uploaded_at >= DATE_SUB(CURDATE(), INTERVAL {$trendWindow} DAY)
    GROUP BY c.categoryID
    ORDER BY cnt DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

include "includes/layout_start.php";

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin Dashboard</title>

<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<script src="https://cdn.jsdelivr.net/npm/echarts/dist/echarts.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


  <style>
    .stat-card-value { font-weight:700; font-size:clamp(1.25rem,2.2vw,2rem); color:#1D2129; }
    .stat-card-label { color:#4E5969; font-size:0.95rem; }
    .shadow-card { box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
  </style>
</head>
<body class="bg-gray-50 font-sans text-dark">

<script>
// server -> client
const dashboardData = {

    // numbers
    totalUsers: <?= json_encode($totalUsers) ?>,
    activeTeams: <?= json_encode($activeTeams) ?>,
    totalSubmissions: <?= json_encode($totalSubmissions) ?>,
    submissionCounts: <?= json_encode($submissionCounts) ?>,

    // trends
    newUsers: <?= json_encode($newUsers) ?>,
    submissionTrend: <?= json_encode($submissionTrend) ?>,
    topCategories: <?= json_encode($topCategories) ?>,

    // user analytics
    roleDistribution: <?= json_encode($roleDistribution) ?>,
    loginTrend: <?= json_encode($loginTrend) ?>,
    submissionActivity: <?= json_encode($submissionActivity) ?>,
    pointsActivity: <?= json_encode($pointsActivity) ?>,

    // challenge analytics
    challenge: {
        active: <?= json_encode($activeCount) ?>,
        inactive: <?= json_encode($inactiveCount) ?>,
        total: <?= json_encode($activeCount + $inactiveCount) ?>,

        cityLabels: <?= json_encode($cityLabels) ?>,
        cityCounts: <?= json_encode($cityCounts) ?>,

        catLabels: <?= json_encode($catLabels) ?>,
        catCounts: <?= json_encode($catCounts) ?>,

        pointsLabels: <?= json_encode($topPointsLabels) ?>,
        pointsValues: <?= json_encode($topPointsValues) ?>,

        expiring: <?= json_encode($expiringChallenges) ?>
    },

    // rewards analytics
    rewards: <?= json_encode($rewards) ?>,
    totalStock: <?= json_encode($totalStock) ?>,
    totalRedeemed: <?= json_encode($totalRedeemed) ?>,
    lowStockRewards: <?= json_encode($lowStockRewards) ?>,
    topRedeemers: <?= json_encode($topRedeemers) ?>,
    redeemTrend: <?= json_encode($redeemTrend) ?>


};

function formatDate(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString("en-US", {
        month: "short",
        day: "2-digit",
        year: "numeric"
    });
}


</script>

<div class="max-w-7xl mx-auto p-6">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold">Admin Dashboard</h1>
      <p class="text-sm text-gray-600">Time filter affects all time-based cards & charts</p>
    </div>

    <div class="flex items-center gap-3">
      <label class="text-sm text-gray-700">Time Filter:</label>
      <select id="timeFilter" class="border rounded px-2 py-1" onchange="applyTimeFilter()">
        <option value="all" <?= $timeFilter === 'all' ? 'selected' : '' ?>>All</option>
        <option value="7" <?= $timeFilter === '7' ? 'selected' : '' ?>>Last 7 Days</option>
        <option value="30" <?= $timeFilter === '30' ? 'selected' : '' ?>>Last 30 Days</option>
      </select>

      <button onclick="window.location.reload()" class="bg-blue-600 text-white px-3 py-1 rounded shadow">
        <i class="fas fa-refresh"></i> Refresh
      </button>
    </div>
  </div>

  <!-- summary cards -->
  <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-2xl shadow-card">
      <p class="stat-card-label">Total Users</p>
      <div class="stat-card-value" id="cardTotalUsers">—</div>
    </div>
    <div class="bg-white p-5 rounded-2xl shadow-card">
      <p class="stat-card-label">New User (period)</p>
      <div class="stat-card-value" id="cardNewUsers">—</div>
    </div>
    <div class="bg-white p-5 rounded-2xl shadow-card">
      <p class="stat-card-label">Active Teams (period)</p>
      <div class="stat-card-value" id="cardActiveTeams">—</div>
    </div>
    <div class="bg-white p-5 rounded-2xl shadow-card">
      <p class="stat-card-label">Total Submission (period)</p>
      
      <div class="stat-card-value" id="cardTotalSubmissions">—</div>
    </div>
  </div>

  <!-- tabs -->
  <div class="bg-white rounded-2xl shadow-lg p-6">

    <div class="flex border-b border-light-2 mb-6 space-x-6">
      <button id="tab-charts" class=" py-3 px-4 text-sm font-semibold text-primary border-b-2 border-primary transition-all" onclick="showTab('charts')">Growth</button>
      <button id="tab-user" class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('user')">Users</button>
      <button id="tab-Challenge" class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('Challenge')">Challenge</button>
      <button id="tab-tables" class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('tables')">Submission</button>
      <button id="tab-reward" class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('reward')">Reward</button>

    </div>


    <div id="charts" class="tab-content">
      <div class="p-4 bg-white rounded-xl shadow-md">
        <div id="growthChart" style="height:380px;"></div>
      </div>
    </div>
<div id="tables" class="tab-content hidden">

    <!-- KPI Cards Row -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">

        <div class="bg-white p-6 rounded-2xl shadow-card flex items-center gap-4 h-32">
            <div class="text-blue-600 text-4xl">
                <i class="fa-solid fa-upload"></i>
            </div>
            <div>
                <p class="text-sm text-gray-600">Total Submissions</p>
                <p id="kpiTotalSubmissions" class="text-3xl font-bold">0</p>
            </div>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-card flex items-center gap-4 h-32">
            <div class="text-yellow-500 text-4xl">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <div>
                <p class="text-sm text-gray-600">Pending</p>
                <p id="kpiPending" class="text-3xl font-bold">0</p>
            </div>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-card flex items-center gap-4 h-32">
            <div class="text-green-600 text-4xl">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <p class="text-sm text-gray-600">Approved</p>
                <p id="kpiApproved" class="text-3xl font-bold">0</p>
            </div>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-card flex items-center gap-4 h-32">
            <div class="text-red-500 text-4xl">
                <i class="fa-solid fa-xmark-circle"></i>
            </div>
            <div>
                <p class="text-sm text-gray-600">Denied</p>
                <p id="kpiDenied" class="text-3xl font-bold">0</p>
            </div>
        </div>

    </div>


    <!-- Graphs -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- Trend -->
        <div class="bg-white p-6 rounded-2xl shadow-card h-[380px] flex flex-col">
            <h3 class="font-semibold text-lg mb-4">Submission Trend</h3>
            <canvas id="submissionTrendChart" class="w-full" style="height: 260px;"></canvas>

        </div>

        <!-- Categories -->
        <div class="bg-white p-6 rounded-2xl shadow-card h-[380px] flex flex-col">
            <h3 class="font-semibold text-lg mb-4">Top Submission Categories</h3>
            <canvas id="topCategoriesChart" class="flex-1"></canvas>
        </div>

    </div>

</div>


    <div id="user" class="tab-content hidden">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white p-5 rounded-xl shadow-card h-56">
          <h3 class="font-semibold mb-4">Users by Role</h3>
          <canvas id="roleDistributionChart" class="w-full h-full"></canvas>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-card h-56">
          <h3 class="font-semibold mb-4">Login Trend</h3>
          <canvas id="loginTrendChart" class="w-full h-full"></canvas>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-card h-56">
          <h3 class="font-semibold mb-4">Submission Activity (Top Users)</h3>
          <canvas id="submissionActivityChart" class="w-full h-full"></canvas>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-card h-56">
          <h3 class="font-semibold mb-4">Points Earned vs Burned</h3>
          <canvas id="pointsActivityChart" class="w-full h-full"></canvas>
        </div>
      </div>
    </div>
  


  <div id="Challenge" class="tab-content hidden">

    <div class="p-4">
  
        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">

            <div class="bg-white p-6 rounded-2xl shadow-card flex justify-between items-center">
                <div>
                    <p class="text-sm text-gray-500">Active Challenges</p>
                    <h2 id="challengeActive" class="text-3xl font-bold">0</h2>
                </div>
                <div class="text-indigo-600 text-4xl"><i class="bi bi-lightning-charge-fill"></i></div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-card flex justify-between items-center">
                <div>
                    <p class="text-sm text-gray-500">Inactive</p>
                    <h2 id="challengeInactive" class="text-3xl font-bold">0</h2>
                </div>
                <div class="text-pink-600 text-4xl"><i class="bi bi-archive-fill"></i></div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-card flex justify-between items-center">
                <div>
                    <p class="text-sm text-gray-500">Total</p>
                    <h2 id="challengeTotal" class="text-3xl font-bold">0</h2>
                </div>
                <div class="text-teal-600 text-4xl"><i class="bi bi-layers-fill"></i></div>
            </div>

        </div>

        <!-- Row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- City Chart -->
            <div class="col-span-2 bg-white p-6 rounded-2xl shadow-card h-[350px]">
                <h3 class="font-semibold mb-4">Geographic Distribution</h3>
                <canvas id="challengeCityChart"></canvas>
            </div>

            <!-- Expiring Soon -->
            <div class="bg-white p-6 rounded-2xl shadow-card h-[350px] overflow-auto">
                <h3 class="font-semibold mb-4 text-red-500">Ending Soon</h3>
                <div id="expiringList"></div>
            </div>

        </div>

        <!-- Row 2 -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">

            <!-- Category Split -->
            <div class="bg-white p-6 rounded-2xl shadow-card h-[330px]">
                <h3 class="font-semibold mb-4">Category Split</h3>
                <canvas id="challengeCatChart"></canvas>
            </div>

            <!-- Top Points -->
            <div class="bg-white p-6 rounded-2xl shadow-card h-[330px]">
                <h3 class="font-semibold mb-4">Top Rewards</h3>
                <canvas id="challengePointsChart"></canvas>
            </div>

        </div>

    </div>

</div>

</div>



<div id="reward" class="tab-content hidden">

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">

        <div class="bg-white p-6 rounded-2xl shadow-card flex flex-col items-center justify-center h-32">
            <p class="text-sm text-gray-600">Total Rewards Redeemed</p>
            <p id="totalRewardsRedeemed" class="text-3xl font-bold mt-2">0</p>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-card flex flex-col items-center justify-center h-32">
            <p class="text-sm text-gray-600">Total Rewards Stock</p>
            <p id="totalRewardsStock" class="text-3xl font-bold mt-2">0</p>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-card flex flex-col items-center justify-center h-32">
            <p class="text-sm text-gray-600">Low Stock Alerts</p>
            <p id="lowStockCount" class="text-3xl font-bold text-red-500 mt-2">0</p>
        </div>

    </div>

    <!-- Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <div class="bg-white p-6 rounded-2xl shadow-card h-[360px] flex flex-col">
            <h3 class="font-semibold text-lg mb-4">Redeemed by Type</h3>
            <canvas id="redeemedByTypeChart" class="flex-1"></canvas>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-card h-[360px] flex flex-col">
            <h3 class="font-semibold text-lg mb-4">Redemptions Trend</h3>
            <canvas id="redemptionsTrendChart" class="w-full" style="height: 260px;"></canvas>

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
  // preserve other query params if you want (e.g., status) - currently only time is used
  window.location.href = url.toString();
}

// fill summary cards
function loadSummaryCards(){
  document.getElementById('cardTotalUsers').innerText = dashboardData.totalUsers ?? 0;
  const newUsersCount = (dashboardData.newUsers || []).reduce((s, d) => s + (parseInt(d.cnt)||0), 0);
  document.getElementById('cardNewUsers').innerText = newUsersCount;
  document.getElementById('cardActiveTeams').innerText = dashboardData.activeTeams ?? 0;
  document.getElementById('cardTotalSubmissions').innerText = dashboardData.totalSubmissions ?? 0;
}
loadSummaryCards();

// tab controller
const tabInitialized = {
    charts: false,
    user: false,
    tables: false,
    reward: false,
    Challenge: false
};

function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.getElementById(tab).classList.remove('hidden');

    if (!tabInitialized[tab]) {
        if (tab === 'charts') initGrowthChart();
        if (tab === 'user') initUsersTab();
        if (tab === 'tables') initSubmissionTab();
        if (tab === 'reward') initRewardTab();
        if (tab === 'Challenge') initChallengeTab();
        tabInitialized[tab] = true;
    }

    setTimeout(() => {
        if (tab === 'tables') {
            if (window.submissionCharts?.trend) window.submissionCharts.trend.resize();
            if (window.submissionCharts?.cat) window.submissionCharts.cat.resize();
        }
        if (tab === 'user') Object.values(window.usersCharts || {}).forEach(c=>c.resize && c.resize());
        if (tab === 'reward') Object.values(window.rewardCharts || {}).forEach(c=>c.resize && c.resize());
    }, 200);
}

// ensure executed after whole page load
window.onload = () => showTab("charts");



/* Growth (ECharts) */
function initGrowthChart() {
    const el = document.getElementById('growthChart');

    // 如果已经有实例，先 dispose（避免重复实例）
    if (window.dashboardCharts && window.dashboardCharts.growth) {
        try { window.dashboardCharts.growth.dispose(); } catch(e){/* ignore */ }
        window.dashboardCharts = {};
    }
    // 如果 echarts 先前绑定在 el 上也 dispose
    try {
        const prev = echarts.getInstanceByDom(el);
        if (prev) echarts.dispose(prev);
    } catch(e){ /* ignore */ }

    const chart = echarts.init(el);

    // 后端回来的 submissionTrend（格式：[{day: "YYYY-MM-DD", pending:.., approved:.., denied:..}, ...]）
    const trend = dashboardData.submissionTrend || [];

    // 如果后端给了日期就用它们做 labels；如果没有或天数 < 7，补齐为最近 7 天（保证稳定的 UX）
    let labels = trend.map(d => d.day);
    if (!labels.length || labels.length < 7) {
        const days = [];
        const today = new Date();
        // 生成最近 7 天的日期字符串 yyyy-mm-dd
        for (let i = 6; i >= 0; i--) {
            const dt = new Date(today);
            dt.setDate(today.getDate() - i);
            const yyyy = dt.getFullYear();
            const mm = ('0' + (dt.getMonth() + 1)).slice(-2);
            const dd = ('0' + dt.getDate()).slice(-2);
            days.push(`${yyyy}-${mm}-${dd}`);
        }
        // 如果后端返回的日期是子集，则用后端数据覆盖对应日期的值（保证 labels 包含最近 7 天）
        labels = days;
    }

    // 构建 newUsers map（后端 newUsers 中的 day 也应为 YYYY-MM-DD）
    const newUsersMap = {};
    (dashboardData.newUsers || []).forEach(r => {
        if (r.day) newUsersMap[r.day] = parseInt(r.cnt || 0, 10);
    });

    // newUsers series 对齐 labels（labels 中某日后端无值则补 0）
    const newUsersSeries = labels.map(day => newUsersMap[day] || 0);

    // submissions series：先把 submissionTrend 转为 map 便于根据 labels 对齐
    const subsMap = {};
    (trend || []).forEach(r => {
        const key = r.day;
        subsMap[key] = (parseInt(r.pending || 0, 10) || 0) + (parseInt(r.approved || 0, 10) || 0) + (parseInt(r.denied || 0, 10) || 0);
    });
    const submissionsSeries = labels.map(day => subsMap[day] || 0);

    chart.setOption({
    tooltip: { trigger: 'axis' },

    legend: {
        data: ['New Users', 'Submissions'],
        top: 10,           // ⭐ 放上面，不会挡住
        textStyle: { fontSize: 12 }
    },

    grid: {
        top: 60,           // ⭐ 给 legend 足够空间
        left: '8%',
        right: '6%',
        bottom: '15%'      // ⭐ 也给 X轴空间
    },

    xAxis: {
        type: 'category',
        data: labels
    },

    yAxis: {
        type: 'value'
    },

    series: [
        {
            name: 'New Users',
            type: 'line',
            smooth: true,
            data: newUsersSeries
        },
        {
            name: 'Submissions',
            type: 'line',
            smooth: true,
            data: submissionsSeries
        }
    ]
});


    window.dashboardCharts = window.dashboardCharts || {};
    window.dashboardCharts.growth = chart;

    // resize 支持
    window.addEventListener('resize', () => {
        try { chart.resize(); } catch(e) {}
    });
}


function initChallengeTab() {

    // KPIs
    document.getElementById("challengeActive").innerText = dashboardData.challenge.active;
    document.getElementById("challengeInactive").innerText = dashboardData.challenge.inactive;
    document.getElementById("challengeTotal").innerText = dashboardData.challenge.total;

    /* ----------------------------------------------------
       NEW EXPIRING LIST (Better UI + formatted date)
    ---------------------------------------------------- */
    let listHTML = "";

    (dashboardData.challenge.expiring || []).forEach(item => {
        
        const niceDate = formatDate(item.end_date);

        listHTML += `
            <div class="flex items-center gap-4 p-3 mb-2 rounded-xl border border-gray-100 hover:bg-gray-50 transition">
                
                <!-- Date Badge -->
                <div class="bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg text-center w-24">
                    ${niceDate}
                </div>

                <div class="flex-1">
                    <p class="font-semibold text-gray-800">${item.challengeTitle}</p>
                    <p class="text-gray-500 text-sm flex items-center gap-1">
                        <i class="bi bi-geo-alt text-red-400"></i>
                        ${item.city || "Global"}
                    </p>
                </div>
            </div>
        `;
    });

    document.getElementById("expiringList").innerHTML =
        listHTML.trim() !== ""
        ? listHTML
        : "<p class='text-gray-400 text-sm'>No expiring challenges.</p>";


    /* ----------------------------------------------------
       Charts (unchanged)
    ---------------------------------------------------- */

    const activeCityLabels = [];
const activeCityCounts = [];

dashboardData.challenge.cityLabels.forEach((label, index) => {
    const cnt = dashboardData.challenge.cityCounts[index];
    if (cnt > 0) {  // keep only cities that have active challenges
        activeCityLabels.push(label);
        activeCityCounts.push(cnt);
    }
});


    const cityCtx = document.getElementById("challengeCityChart").getContext("2d");
    new Chart(cityCtx, {
        type: "bar",
        data: {
            labels: activeCityLabels,
            datasets: [{
                label: "Active Challenges",
                data: activeCityCounts,
                backgroundColor: "#6366f1"
            }]
        }
    });


    const catCtx = document.getElementById("challengeCatChart").getContext("2d");
    new Chart(catCtx, {
        type: "doughnut",
        data: {
            labels: dashboardData.challenge.catLabels,
            datasets: [{
                data: dashboardData.challenge.catCounts,
                backgroundColor: ['#6366f1','#ec4899','#06b6d4','#f59e0b','#10b981']
            }]
        }
    });

    const pointsCtx = document.getElementById("challengePointsChart").getContext("2d");
    new Chart(pointsCtx, {
        type: "bar",
        data: {
            labels: dashboardData.challenge.pointsLabels,
            datasets: [{
                label: "Points",
                data: dashboardData.challenge.pointsValues,
                backgroundColor: "#f59e0b"
            }]
        },
        options: { indexAxis: "y" }
    });
}






/* Submission tab charts (Chart.js) */
function initSubmissionTab(){
  // KPI
document.getElementById('kpiTotalSubmissions').innerText = dashboardData.totalSubmissions;
document.getElementById('kpiPending').innerText = dashboardData.submissionCounts.pending;
document.getElementById('kpiApproved').innerText = dashboardData.submissionCounts.approved;
document.getElementById('kpiDenied').innerText = dashboardData.submissionCounts.denied;


  // trend
  const elTotal = document.getElementById('totalSubmissions');
  if (elTotal) elTotal.innerText = dashboardData.totalSubmissions ?? 0;

  const elPending = document.getElementById('pendingSubmissions');
  if (elPending) elPending.innerText = dashboardData.submissionCounts?.Pending ?? 0;

  const elApproved = document.getElementById('approvedSubmissions');
  if (elApproved) elApproved.innerText = dashboardData.submissionCounts?.Approved ?? 0;

  const elDenied = document.getElementById('deniedSubmissions');
  if (elDenied) elDenied.innerText = dashboardData.submissionCounts?.Denied ?? 0;

  // ---- Submission Trend Chart ----
  const ctxTrend = document.getElementById('submissionTrendChart').getContext('2d');
  const labels = (dashboardData.submissionTrend || []).map(d => d.day);
  const pending = (dashboardData.submissionTrend || []).map(d => d.pending || 0);
  const approved = (dashboardData.submissionTrend || []).map(d => d.approved || 0);
  const denied = (dashboardData.submissionTrend || []).map(d => d.denied || 0);

  window.submissionCharts = {};
  window.submissionCharts.trend = new Chart(ctxTrend, {
    type: 'line',
    data: {
        labels,
        datasets:[
            { label:'Pending', data: pending, borderColor:'#FBBF24', fill:true, backgroundColor:'rgba(251,191,36,0.12)', tension:0.3 },
            { label:'Approved', data: approved, borderColor:'#22C55E', fill:true, backgroundColor:'rgba(34,197,94,0.12)', tension:0.3 },
            { label:'Denied', data: denied, borderColor:'#EF4444', fill:true, backgroundColor:'rgba(239,68,68,0.12)', tension:0.3 }
        ]
    },
    options: { 
        responsive:true, 
        maintainAspectRatio:false,
        layout: {
            padding: {
                left: 20,
                right: 20,
                top: 10,
                bottom: 20
            }
        },
        scales: {
            x: {
                offset: true,  
                grid: { display:false }
            },
            y: {
                beginAtZero: true,
                grace: 1       
            }
        }
    }
});

// ---- Top Categories Doughnut Chart ----
const ctxCat = document.getElementById("topCategoriesChart").getContext("2d");

const catLabels = (dashboardData.topCategories || []).map(r => r.category);
const catCounts = (dashboardData.topCategories || []).map(r => r.cnt);

window.submissionCharts.cat = new Chart(ctxCat, {
    type: "bar",
    data: {
        labels: catLabels,
        datasets: [{
            label: "Submissions",
            data: catCounts,
            backgroundColor: "#6366F1",
            borderRadius: 6,
            barThickness: 12,       // ⭐ 控制每条的高度（变细）
            maxBarThickness: 14     // ⭐ 避免太粗
        }]
    },
    options: {
        indexAxis: "y",
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { 
                beginAtZero: false, 
                grid: { display: false }
            },
            y: { 
                grid: { display: false },
                ticks: { padding: 6 }
            }
        },
        plugins: {
            legend: { display: false }
        }
    }
});





}

/* Users charts */
function initUsersTab(){
  window.usersCharts = {};

  // role doughnut
  const roleCtx = document.getElementById('roleDistributionChart').getContext('2d');
  window.usersCharts.role = new Chart(roleCtx, {
    type:'doughnut',
    data:{
      labels: Object.keys(dashboardData.roleDistribution || {}),
      datasets:[{ data: Object.values(dashboardData.roleDistribution || {}), backgroundColor:['#4F46E5','#22C55E','#F59E0B','#EF4444'] }]
    },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
  });

  // login trend
  const loginCtx = document.getElementById('loginTrendChart').getContext('2d');
  window.usersCharts.login = new Chart(loginCtx, {
    type:'line',
    data:{ labels:(dashboardData.loginTrend||[]).map(r=>r.date), datasets:[{ label:'Logins', data:(dashboardData.loginTrend||[]).map(r=>r.count), borderColor:'#4F46E5', fill:false }]},
    options:{ responsive:true, maintainAspectRatio:false }
  });

  // submission activity
  const subCtx = document.getElementById('submissionActivityChart').getContext('2d');
  window.usersCharts.sub = new Chart(subCtx, {
    type:'bar',
    data:{ labels:(dashboardData.submissionActivity||[]).map(r=>r.name), datasets:[{ label:'Submissions', data:(dashboardData.submissionActivity||[]).map(r=>r.submissions), backgroundColor:'#22C55E' }]},
    options:{ responsive:true, maintainAspectRatio:false }
  });

  // points earned vs burned
  const pointsCtx = document.getElementById('pointsActivityChart').getContext('2d');
  window.usersCharts.points = new Chart(pointsCtx, {
    type:'bar',
    data:{
      labels:(dashboardData.pointsActivity||[]).map(r=>r.name),
      datasets:[
        { label:'Earned', data:(dashboardData.pointsActivity||[]).map(r=>r.earned), backgroundColor:'#4F46E5' },
        { label:'Burned', data:(dashboardData.pointsActivity||[]).map(r=>r.burned), backgroundColor:'#F59E0B' }
      ]
    },
    options:{ responsive:true, maintainAspectRatio:false }
  });
}

/* Reward tab */
function initRewardTab(){
  window.rewardCharts = {};

  document.getElementById('totalRewardsRedeemed').innerText = dashboardData.totalRedeemed ?? 0;
  document.getElementById('totalRewardsStock').innerText = dashboardData.totalStock ?? 0;
  document.getElementById('lowStockCount').innerText = (dashboardData.lowStockRewards||[]).length ?? 0;

  // redeemed by type doughnut
  const doughCtx = document.getElementById('redeemedByTypeChart').getContext('2d');
  window.rewardCharts.type = new Chart(doughCtx, {
    type:'doughnut',
    data:{
      labels:(dashboardData.rewards||[]).map(r=>r.rewardName),
      datasets:[{ data:(dashboardData.rewards||[]).map(r=>r.redeemedQuantity||0), backgroundColor:['#60a5fa','#34d399','#f59e0b','#f87171','#a78bfa','#f472b6'] }]
    },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
  });

  // redemptions trend (reuse submissionTrend.approved as approximation)
// ------ REAL REDEMPTIONS TREND ------
const redeemTrend = dashboardData.redeemTrend || [];

const redeemLabels = redeemTrend.map(r => r.day);
const redeemValues = redeemTrend.map(r => r.cnt || 0);

const trendCtx = document.getElementById("redemptionsTrendChart").getContext("2d");

window.rewardCharts.trend = new Chart(trendCtx, {
    type: "line",
    data: {
        labels: redeemLabels,
        datasets: [{
            label: "Redeemed Items",
            data: redeemValues,
            borderColor: "#0EA5E9",
            backgroundColor: "rgba(14,165,233,0.15)",
            fill: true,
            tension: 0.35,
            borderWidth: 2,
            pointRadius: 3,
            pointBackgroundColor: "#0284C7"
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,

        // Better spacing
        layout: { padding: { left: 15, right: 15, top: 10, bottom: 10 }},

        scales: {
            x: { 
                offset: true,
                grid: { display: false }
            },
            y: {
                beginAtZero: true,
                grace: 1
            }
        }
    }
});

}
</script>

<?php include "includes/layout_end.php"; ?>

</body>
</html>
