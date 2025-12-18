<?php
session_start();
require_once "db_connect.php";


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}


// -----------------------------
// Time filter: 'all' | '7' | '30' | 'today'
// -----------------------------
$timeFilter = $_GET['time'] ?? 'all';
$timeInterval = null;
$timeConditionToday = false;

switch($timeFilter) {
    case '7':
        $timeInterval = 7;
        break;
    case '30':
        $timeInterval = 30;
        break;
    case 'today':
        $timeConditionToday = true;
        break;
    default:
        // 'all' -> keep both null/false
        break;
}


// -----------------------------
function sql_time_filter($col, $interval = null, $today = false) {
    if ($today) return " AND DATE({$col}) = CURDATE() ";
    if ($interval) return " AND {$col} >= DATE_SUB(CURDATE(), INTERVAL {$interval} DAY) ";
    return ""; 
}

// -----------------------------
// Total Users by role
// -----------------------------
function getTotalByRole($conn, $role, $timeInterval, $timeConditionToday) {
    $sql = "SELECT COUNT(*) AS cnt FROM user WHERE role = ?" . sql_time_filter('created_at', $timeInterval, $timeConditionToday);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return (int)($res['cnt'] ?? 0);
}

$totalUsers = getTotalByRole($conn, 'user', $timeInterval, $timeConditionToday);
$totalAdmin = getTotalByRole($conn, 'admin', $timeInterval, $timeConditionToday);
$totalModerator = getTotalByRole($conn, 'moderator', $timeInterval, $timeConditionToday);




// -----------------------------
// 3. New Users Trend (role = 'user')
// -----------------------------
$newUsersQuery = "
    SELECT COUNT(*) AS cnt, DATE(created_at) AS day
    FROM user
    WHERE role = 'user' " . sql_time_filter('created_at', $timeInterval, $timeConditionToday) . "
    GROUP BY DATE(created_at)
    ORDER BY day ASC
";
$newUsers = $conn->query($newUsersQuery)->fetch_all(MYSQLI_ASSOC);

// -----------------------------
// New Admins Trend
// -----------------------------
$newAdminsQuery = "
    SELECT COUNT(*) AS cnt, DATE(created_at) AS day
    FROM user
    WHERE role = 'admin' " . sql_time_filter('created_at', $timeInterval, $timeConditionToday) . "
    GROUP BY DATE(created_at)
    ORDER BY day ASC
";
$newAdmins = $conn->query($newAdminsQuery)->fetch_all(MYSQLI_ASSOC);

// -----------------------------
// New Moderator Trend
// -----------------------------
$newModeratorsQuery = "
    SELECT COUNT(*) AS cnt, DATE(created_at) AS day
    FROM user
    WHERE role = 'moderator' " . sql_time_filter('created_at', $timeInterval, $timeConditionToday) . "
    GROUP BY DATE(created_at)
    ORDER BY day ASC
";
$newModerators = $conn->query($newModeratorsQuery)->fetch_all(MYSQLI_ASSOC);


// -----------------------------
// 3. Total Teams
// -----------------------------
$activeTeamsQuery = "
    SELECT COUNT(DISTINCT t.teamID) AS cnt
    FROM team t
    JOIN user u ON u.teamID = t.teamID
    WHERE 1 " . sql_time_filter('u.created_at', $timeInterval,$timeConditionToday);
$activeTeams = (int)($conn->query($activeTeamsQuery)->fetch_assoc()['cnt'] ?? 0);

// -----------------------------
// 4. Challenge Analytics
// -----------------------------
$challengeStatusQuery = "
    SELECT 
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS activeCount,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactiveCount
    FROM challenge
    WHERE 1 " . sql_time_filter('start_date', $timeInterval);
$challengeStatus = $conn->query($challengeStatusQuery)->fetch_assoc();
$activeCount = (int)$challengeStatus['activeCount'];
$inactiveCount = (int)$challengeStatus['inactiveCount'];

$cityDataQuery = "
    SELECT city, COUNT(*) AS cnt
    FROM challenge
    WHERE is_active = 1 " . sql_time_filter('start_date', $timeInterval) . "
    GROUP BY city
";
$cityData = $conn->query($cityDataQuery)->fetch_all(MYSQLI_ASSOC);
$cityLabels = array_column($cityData, 'city');
$cityCounts = array_column($cityData, 'cnt');

$catDataQuery = "
    SELECT c.categoryName AS category, COUNT(*) AS cnt
    FROM challenge ch
    JOIN category c ON ch.categoryID = c.categoryID
    WHERE 1 " . sql_time_filter('ch.start_date', $timeInterval) . "
    GROUP BY c.categoryName
";
$catData = $conn->query($catDataQuery)->fetch_all(MYSQLI_ASSOC);
$catLabels = array_column($catData, 'category');
$catCounts = array_column($catData, 'cnt');

$pointsDataQuery = "
    SELECT challengeTitle, pointAward
    FROM challenge
    WHERE is_active = 1 " . sql_time_filter('start_date', $timeInterval) . "
    ORDER BY pointAward DESC LIMIT 5
";
$pointsData = $conn->query($pointsDataQuery)->fetch_all(MYSQLI_ASSOC);
$topPointsLabels = array_column($pointsData, 'challengeTitle');
$topPointsValues = array_column($pointsData, 'pointAward');

$expiringChallengesQuery = "
    SELECT challengeTitle, city, end_date
    FROM challenge
    WHERE is_active = 1 AND end_date >= CURDATE() " . sql_time_filter('start_date', $timeInterval) . "
    ORDER BY end_date ASC LIMIT 5
";
$expiringChallenges = $conn->query($expiringChallengesQuery)->fetch_all(MYSQLI_ASSOC);

// -----------------------------
// 5. Total Submissions
// -----------------------------
$totalSubmissionsQuery = "SELECT COUNT(*) AS cnt FROM sub WHERE 1 " . sql_time_filter('uploaded_at', $timeInterval);
$totalSubmissions = (int)($conn->query($totalSubmissionsQuery)->fetch_assoc()['cnt'] ?? 0);

// Submission counts by status
$submissionStatusQuery = "
    SELECT status, COUNT(*) AS cnt
    FROM sub
    WHERE 1 " . sql_time_filter('uploaded_at', $timeInterval) . "
    GROUP BY status
";
$submissionCounts = ['pending'=>0,'approved'=>0,'denied'=>0];
foreach ($conn->query($submissionStatusQuery)->fetch_all(MYSQLI_ASSOC) as $row) {
    $key = strtolower(trim($row['status']));
    if (isset($submissionCounts[$key])) $submissionCounts[$key] = (int)$row['cnt'];
}

// Recent submissions
$recentLimitWhere = $timeInterval ? " WHERE s.uploaded_at >= DATE_SUB(CURDATE(), INTERVAL {$timeInterval} DAY) " : "";
$recentSubmissionsQuery = "
    SELECT s.*, u.firstName AS username, t.teamName, c.challengeTitle 
    FROM sub s
    LEFT JOIN user u ON s.userID = u.userID
    LEFT JOIN team t ON u.teamID = t.teamID
    LEFT JOIN challenge c ON s.challengeID = c.challengeID
    {$recentLimitWhere}
    ORDER BY s.uploaded_at DESC
    LIMIT 10
";
$recentSubmissions = $conn->query($recentSubmissionsQuery)->fetch_all(MYSQLI_ASSOC);

// Submission trend
$submissionTrendQuery = "
    SELECT DATE(uploaded_at) AS day,
        SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status='Denied' THEN 1 ELSE 0 END) AS denied
    FROM sub
    WHERE 1 " . sql_time_filter('uploaded_at', $timeInterval) . "
    GROUP BY DATE(uploaded_at)
    ORDER BY day ASC
";
$submissionTrend = $conn->query($submissionTrendQuery)->fetch_all(MYSQLI_ASSOC);

// -----------------------------
// Most Participated Challenges (by submission count)
// -----------------------------
$categoryQuery = "
    SELECT c.challengeTitle AS challenge, COUNT(s.submissionID) AS total
    FROM sub s
    JOIN challenge c ON s.challengeID = c.challengeID
    WHERE 1 " . sql_time_filter('s.uploaded_at', $timeInterval) . "
    GROUP BY s.challengeID, c.challengeTitle
    ORDER BY total DESC
";

$submissionCategories = $conn->query($categoryQuery)->fetch_all(MYSQLI_ASSOC);


// -----------------------------
// 7. Team Overview
// -----------------------------

$dateCondition = sql_time_filter('pt.generate_at', $timeInterval, $timeConditionToday);

$team_sql = "
SELECT t.teamID, t.teamName,
       COALESCE(SUM(CASE WHEN pt.transactionType='earn' " . $dateCondition . " THEN pt.pointsTransaction ELSE 0 END),0) AS teamPoints
FROM team t
LEFT JOIN user u ON u.teamID = t.teamID
LEFT JOIN pointtransaction pt ON pt.userID = u.userID
GROUP BY t.teamID
ORDER BY teamPoints DESC
";

$teamRanks = [];
$team_result = $conn->query($team_sql);
if ($team_result) {
    while($row = $team_result->fetch_assoc()){
        $teamRanks[] = [
            'teamName' => $row['teamName'],
            'teamPoints' => (int)$row['teamPoints']
        ];
    }
}


$teamRanksJson = json_encode($teamRanks);

// -----------------------------
// 8. Login Trend 
// -----------------------------
$loginTrendQuery = "
    SELECT DATE(last_online) AS date, COUNT(*) AS count
    FROM user
    WHERE last_online IS NOT NULL
    " . sql_time_filter('last_online', $timeInterval, $timeConditionToday) . "
    GROUP BY DATE(last_online)
    ORDER BY date ASC
";

$loginTrend = $conn->query($loginTrendQuery)->fetch_all(MYSQLI_ASSOC);


// -----------------------------
// 9. Submission Activity Top Users
// -----------------------------
$submissionActivityQuery = "
    SELECT u.firstName AS name, COUNT(s.submissionID) AS submissions
    FROM user u
    LEFT JOIN sub s ON u.userID = s.userID
    WHERE 1 " . sql_time_filter('s.uploaded_at', $timeInterval) . "
    GROUP BY u.userID
    ORDER BY submissions DESC
    LIMIT 5
";
$submissionActivity = $conn->query($submissionActivityQuery)->fetch_all(MYSQLI_ASSOC);

// -----------------------------
// 10. Points Earned vs Burned
// -----------------------------
$pointsOverallSql = "
SELECT 
  SUM(CASE WHEN p.transactionType = 'earn' THEN p.pointsTransaction ELSE 0 END) AS earned,
  SUM(CASE WHEN p.transactionType = 'burn' THEN p.pointsTransaction ELSE 0 END) AS burned
FROM pointtransaction p
WHERE 1=1
" . sql_time_filter('p.generate_at', $timeInterval, $timeConditionToday);

$result = $conn->query($pointsOverallSql);
$pointsOverall = $result->fetch_assoc() ?? ['earned'=>0,'burned'=>0];



// -----------------------------
// 11. User Details
// -----------------------------
$userDetailsQuery = "
    SELECT u.userID as id, u.firstName AS name, u.email, u.role,
           t.teamName,
           (
               SELECT COUNT(*) 
               FROM sub s 
               WHERE s.userID=u.userID " . sql_time_filter('s.uploaded_at', $timeInterval) . "
           ) AS submissions,
           (
               SELECT COALESCE(SUM(pointsTransaction),0) 
               FROM pointtransaction p 
               WHERE p.userID=u.userID AND p.transactionType='earned' " . sql_time_filter('p.generate_at', $timeInterval) . "
           ) AS earned,
           (
               SELECT COALESCE(SUM(pointsTransaction),0) 
               FROM pointtransaction p 
               WHERE p.userID=u.userID AND p.transactionType='burned' " . sql_time_filter('p.generate_at', $timeInterval) . "
           ) AS burned,
           u.last_online AS lastLogin
    FROM user u
    LEFT JOIN team t ON u.teamID = t.teamID
";
$userDetails = $conn->query($userDetailsQuery)->fetch_all(MYSQLI_ASSOC);

// -----------------------------
// 12. Rewards / Redemption
// -----------------------------
$whereReward = "WHERE status NOT IN ('cancelled', 'denied')" . sql_time_filter('requested_at', $timeInterval);
$burnedPoints = $conn->query("SELECT SUM(pointSpent) as total FROM redemptionrequest $whereReward")->fetch_assoc()['total'] ?? 0;

$whereReward = "WHERE status = 'pending'" . sql_time_filter('requested_at', $timeInterval);
$pendingCount = $conn->query("SELECT COUNT(*) as total FROM redemptionrequest $whereReward")->fetch_assoc()['total'] ?? 0;

$whereReward = "WHERE status IN ('approved','outOfDiliver','Delivered')" . sql_time_filter('requested_at', $timeInterval);
$successCount = $conn->query("SELECT COUNT(*) as total FROM redemptionrequest $whereReward")->fetch_assoc()['total'] ?? 0;

$rewardTrendQuery = "
    SELECT DATE(requested_at) AS day, COUNT(*) AS cnt
    FROM redemptionrequest
    WHERE status != 'cancelled' " . sql_time_filter('requested_at', $timeInterval) . "
    GROUP BY DATE(requested_at)
    ORDER BY day ASC
";
$rewardTrend = $conn->query($rewardTrendQuery)->fetch_all(MYSQLI_ASSOC);
$months = array_column($rewardTrend, 'day');
$trendData = array_column($rewardTrend, 'cnt');

$popLabels = [];
$popData = [];
$popColors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899'];
$rewardWindow = $timeInterval ?? 30;

$popQuery = "
    SELECT r.rewardName, COUNT(rr.redemptionID) AS count 
    FROM redemptionrequest rr
    JOIN reward r ON rr.rewardID = r.rewardID
    WHERE rr.status != 'cancelled' " . sql_time_filter('rr.requested_at', $rewardWindow) . "
    GROUP BY rr.rewardID 
    ORDER BY count DESC 
    LIMIT 5
";
$res = $conn->query($popQuery);
while($row = $res->fetch_assoc()){
    $popLabels[] = $row['rewardName'];
    $popData[] = $row['count'];
}

// -----------------------------
// 13. Recent Pending Requests
// -----------------------------
$pendingSql = "
    SELECT rr.redemptionID, u.firstName, u.lastName, r.rewardName, rr.requested_at, r.imageURL 
    FROM redemptionrequest rr 
    JOIN user u ON rr.userID = u.userID
    JOIN reward r ON rr.rewardID = r.rewardID
    WHERE rr.status = 'pending' " . sql_time_filter('rr.requested_at', $rewardWindow) . "
    ORDER BY rr.requested_at ASC 
    LIMIT 5
";
$pendingRes = $conn->query($pendingSql);

// -----------------------------
// 14. Inventory Alerts
// -----------------------------
$alertSql = "
    SELECT rewardName, stockQuantity, expiry_date, imageURL 
    FROM reward 
    WHERE (stockQuantity < 5 OR (expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))) 
    AND is_active = 1 
    LIMIT 5
";
$alertRes = $conn->query($alertSql);

$lowStockCount = $conn->query("SELECT COUNT(*) as cnt FROM reward WHERE stockQuantity < 5 AND is_active = 1")->fetch_assoc()['cnt'] ?? 0;

// -----------------------------
// Reward Redemption Trend (by status)
// -----------------------------
$rewardTrendByStatusQuery = "
    SELECT DATE(requested_at) AS day,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status IN ('approved','outOfDiliver','Delivered') THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'denied' THEN 1 ELSE 0 END) AS denied
    FROM redemptionrequest
    WHERE status != 'cancelled'
    " . sql_time_filter('requested_at', $timeInterval) . "
    GROUP BY DATE(requested_at)
    ORDER BY day ASC
";

$rewardTrendByStatus = $conn->query($rewardTrendByStatusQuery)->fetch_all(MYSQLI_ASSOC);




include "includes/layout_start.php";


?>



<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin Dashboard</title>

<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/echarts/dist/echarts.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


  <style>
    .stat-card-value { font-weight:700; font-size:clamp(1.25rem,2.2vw,2rem); color:#1D2129; }
    .stat-card-label { color:#4E5969; font-size:0.95rem; }
    .shadow-card { box-shadow: 0 4px 20px rgba(0,0,0,0.05); }

     body { background: #f8fafc; font-family: 'Plus Jakarta Sans', sans-serif; }
        .content-wrapper { padding: 25px; }
        
        /* KPI Cards */
        .kpi-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid #f1f5f9;
            height: 100%;
            transition: transform 0.2s;
        }
        .kpi-card:hover { transform: translateY(-3px); border-color: #e2e8f0; }
        .kpi-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        .kpi-label { color: #64748b; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .kpi-value { color: #0f172a; font-size: 32px; font-weight: 800; line-height: 1.2; margin-top: 5px; }
        .kpi-sub { font-size: 12px; color: #94a3b8; margin-top: 5px; }

        /* Chart Section */
        .chart-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            height: 100%;
        }
        .section-title { font-size: 16px; font-weight: 700; color: #334155; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
        
        /* Tables */
        .table-card { background: white; border-radius: 16px; overflow: hidden; border: 1px solid #f1f5f9; height: 100%; }
        .table-header { padding: 20px; background: white; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        .custom-table th { background: #f8fafc; font-size: 11px; text-transform: uppercase; color: #64748b; padding: 12px 20px; border-bottom: 1px solid #e2e8f0; }
        .custom-table td { padding: 15px 20px; vertical-align: middle; font-size: 14px; color: #334155; border-bottom: 1px solid #f1f5f9; }
        .custom-table tr:last-child td { border-bottom: none; }
        .user-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        .item-thumb { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; border: 1px solid #f1f5f9; }
        
        /* Utils */
        .bg-orange-soft { background: #fff7ed; color: #ea580c; }
        .bg-blue-soft { background: #eff6ff; color: #2563eb; }
        .bg-green-soft { background: #f0fdf4; color: #16a34a; }
        .bg-red-soft { background: #fef2f2; color: #dc2626; }
        
        .badge-stock { font-size: 11px; padding: 4px 8px; border-radius: 6px; font-weight: 600; }
        .stock-low { background: #fef2f2; color: #ef4444; border: 1px solid #fee2e2; }
        .stock-expiring { background: #fffbeb; color: #d97706; border: 1px solid #fef3c7; }


  </style>
</head>
<body class="bg-gray-50 font-sans text-dark">

<script>
// server -> client
const dashboardData = {

    // numbers
    totalUsers: <?= json_encode($totalUsers) ?>,
    totalAdmin: <?= json_encode($totalAdmin) ?>,
    totalModerator : <?= json_encode($totalModerator ) ?>,
    activeTeams: <?= json_encode($activeTeams) ?>,
    newAdmins: <?= json_encode($newAdmins) ?>,
    newModerators: <?= json_encode($newModerators) ?>,
    totalSubmissions: <?= json_encode($totalSubmissions) ?>,
    submissionCounts: <?= json_encode($submissionCounts) ?>,

    // trends
    newUsers: <?= json_encode($newUsers) ?>,
    submissionTrend: <?= json_encode($submissionTrend) ?>,
    submissionCategories: <?= json_encode($submissionCategories) ?>,


    // user analytics
    teamScores: <?= json_encode($teamRanks ?? []) ?>,
    loginTrend: <?= json_encode($loginTrend) ?>,
    submissionActivity: <?= json_encode($submissionActivity) ?>,
      pointsOverall: <?= json_encode($pointsOverall) ?>,

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
     rewards: {
        burnedPoints: <?= json_encode($burnedPoints) ?>,
        successCount: <?= json_encode($successCount) ?>,
        pendingCount: <?= json_encode($pendingCount) ?>,
        lowStockCount: <?= json_encode($lowStockCount) ?>,

        rewardTrendStatus: <?= json_encode($rewardTrendByStatus) ?>,

        trendLabels: <?= json_encode($months) ?>,
        trendData: <?= json_encode($trendData) ?>,
        topLabels: <?= json_encode($popLabels) ?>,
        topData: <?= json_encode($popData) ?>
        

    }


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
      <select id="timeFilter" class="border rounded px-2 py-1" onchange="applyTimeFilter()">
        <option value="all" <?= $timeFilter === 'all' ? 'selected' : '' ?>>All</option>
        <option value="today" <?= $timeFilter === 'today' ? 'selected' : '' ?>>Today</option>
        <option value="7" <?= $timeFilter === '7' ? 'selected' : '' ?>>Last 7 Days</option>
        <option value="30" <?= $timeFilter === '30' ? 'selected' : '' ?>>Last 30 Days</option>
      </select>

        <button 
            onclick="window.location = '?time=all'" 
            class="bg-primary hover:bg-blue-700 text-white px-3 py-1.5 rounded shadow-md transition-colors"
        >
            <i class="fas fa-sync-alt"></i> Refresh
        </button>

    </div>
  </div>

<!-- summary cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-2 mb-2">

    <!-- Total Users -->
    <div class="bg-white p-3 rounded-2xl shadow-card flex items-center gap-3">
        <div class="p-2 bg-blue-100 text-blue-600 rounded-xl">
            <!-- Users Icon -->
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m4-4a4 4 0 100-8 4 4 0 000 8zm6 4a4 4 0 10-8 0" />
            </svg>
        </div>
        <div>
            <p class="stat-card-label">Total Users</p>
            <div class="stat-card-value" id="cardTotalUsers">—</div>
        </div>
    </div>

    <!-- Total Admin -->
    <div class="bg-white p-3 rounded-2xl shadow-card flex items-center gap-3">
        <div class="p-2 bg-green-100 text-green-600 rounded-xl">
            <!-- User Plus Icon -->
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M18 9a4 4 0 11-8 0 4 4 0 018 0zM12 14a6 6 0 00-6 6v1m13-7v4m0 0h4m-4 0h-4" />
            </svg>
        </div>
        <div>
            <p class="stat-card-label">Total Admin</p>
            <div class="stat-card-value" id="cardTotalAdmin">—</div>
        </div>
    </div>

    <!-- Total Teams -->
    <div class="bg-white p-3 rounded-2xl shadow-card flex items-center gap-3">
        <div class="p-2 bg-purple-100 text-purple-600 rounded-xl">
            <!-- Team Icon -->
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M17 20h5v-2a4 4 0 00-4-4h-1M7 20H2v-2a4 4 0 014-4h1m5-4a4 4 0 100-8 4 4 0 000 8z" />
            </svg>
        </div>
        <div>
            <p class="stat-card-label">Total Teams</p>
            <div class="stat-card-value" id="cardActiveTeams">—</div>
        </div>
    </div>

    <!-- Total Submission -->
    <div class="bg-white p-3 rounded-2xl shadow-card flex items-center gap-3">
        <div class="p-2 bg-orange-100 text-orange-600 rounded-xl">
            <!-- Document Icon -->
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2z" />
            </svg>
        </div>
        <div>
            <p class="stat-card-label">Total Moderator</p>
            <div class="stat-card-value" id="cardTotalSubmissions">—</div>
        </div>
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
            <h3 class="font-semibold text-lg mb-4">Top Submission Challenge</h3>
            <canvas id="topCategoriesChart" class="flex-1"></canvas>
        </div>

    </div>

</div>

        <div id="user" class="tab-content hidden">

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            
                <div class="bg-gray-50 p-6 rounded-xl shadow-md border border-gray-200 h-64"> 
                    <h3 class="font-bold text-lg mb-4 text-gray-800">Team Overview</h3>
                    <div class="h-4/5"> <canvas id="teamScoreChart" class="w-full h-full"></canvas>
                    </div>
                </div>

                <div class="bg-gray-50 p-6 rounded-xl shadow-md border border-gray-200 h-64">
                    <h3 class="font-bold text-lg mb-4 text-gray-800">Login Trend</h3>
                    <div class="h-4/5">
                        <canvas id="loginTrendChart" class="w-full h-full"></canvas>
                    </div>
                </div>

                <div class="bg-gray-50 p-6 rounded-xl shadow-md border border-gray-200 h-64">
                    <h3 class="font-bold text-lg mb-4 text-gray-800">Submission Activity (Top Users)</h3>
                    <div class="h-4/5">
                        <canvas id="submissionActivityChart" class="w-full h-full"></canvas>
                    </div>
                </div>

                <div class="bg-gray-50 p-6 rounded-xl shadow-md border border-gray-200 h-64">
                    <h3 class="font-bold text-lg mb-4 text-gray-800">Overall Points Earned vs Burned</h3>
                    <div class="h-4/5">
                        <canvas id="pointsActivityChart" class="w-full h-full"></canvas>
                    </div>
                </div>

            </div>

        </div>
  


 <div id="Challenge" class="tab-content hidden bg-white-100"> <div class="p-4">
 
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6"> <div class="bg-white p-4 rounded-xl shadow-lg flex justify-between items-center transform transition duration-300 hover:scale-[1.02]">
                <div>
                    <p class="text-sm text-gray-500">Active Challenges</p>
                    <h2 id="challengeActive" class="text-3xl font-extrabold text-indigo-700">0</h2>
                </div>
                <div class="text-indigo-600 text-4xl"><i class="bi bi-lightning-charge-fill"></i></div>
            </div>

            <div class="bg-white p-4 rounded-xl shadow-lg flex justify-between items-center transform transition duration-300 hover:scale-[1.02]">
                <div>
                    <p class="text-sm text-gray-500">Inactive</p>
                    <h2 id="challengeInactive" class="text-3xl font-extrabold text-pink-700">0</h2>
                </div>
                <div class="text-pink-600 text-4xl"><i class="bi bi-archive-fill"></i></div>
            </div>

            <div class="bg-white p-4 rounded-xl shadow-lg flex justify-between items-center transform transition duration-300 hover:scale-[1.02]">
                <div>
                    <p class="text-sm text-gray-500">Total</p>
                    <h2 id="challengeTotal" class="text-3xl font-extrabold text-teal-700">0</h2>
                </div>
                <div class="text-teal-600 text-4xl"><i class="bi bi-layers-fill"></i></div>
            </div>

        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <div class="col-span-1 lg:col-span-2 bg-white p-6 rounded-2xl shadow-xl h-[450px]">
                <h2 class="font-bold text-lg mb-4 text-gray-800">Geographic Distribution</h2>
                <div class="h-[calc(100%-40px)]"> 
                    <canvas id="challengeCityChart" class="w-full h-full"></canvas>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-xl h-[450px] overflow-auto">
                <h2 class="font-bold text-lg mb-4 text-red-600">Ending Soon</h2>
                <div id="expiringList">
                    </div>
            </div>

        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">

            <div class="bg-white p-6 rounded-2xl shadow-xl h-[350px]">
                <h2 class="font-bold text-lg mb-4 text-gray-800">Category Split</h2>
                 <div class="h-[calc(100%-40px)]"> 
                    <canvas id="challengeCatChart" class="w-full h-full"></canvas>
                 </div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-xl h-[350px]">
                <h2 class="font-bold text-lg mb-4 text-gray-800">Top Rewards</h2>
                <div class="h-[calc(100%-40px)]">
                    <canvas id="challengePointsChart" class="w-full h-full"></canvas>
                </div>
            </div>

        </div>

    </div>

</div>



        <div id="reward" class="tab-content hidden">

            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="kpi-icon bg-orange-soft"><iconify-icon icon="solar:flame-bold-duotone"></iconify-icon></div>
                        <div class="kpi-label">Points Redeemed</div>
                        <div class="kpi-value text-orange"><?php echo number_format($burnedPoints); ?></div>
                        <div class="kpi-sub">Total user points spent</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="kpi-icon bg-green-soft"><iconify-icon icon="solar:bag-check-bold-duotone"></iconify-icon></div>
                        <div class="kpi-label">Items Claimed</div>
                        <div class="kpi-value text-success"><?php echo number_format($successCount); ?></div>
                        <div class="kpi-sub">Approved & Delivered</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="reviewRR.php?status=pending" style="text-decoration:none;">
                        <div class="kpi-card" style="<?php echo $pendingCount > 0 ? 'border-color:#bfdbfe;' : ''; ?>">
                            <div class="kpi-icon bg-blue-soft"><iconify-icon icon="solar:bell-bing-bold-duotone"></iconify-icon></div>
                            <div class="kpi-label">Pending Review</div>
                            <div class="kpi-value text-primary"><?php echo number_format($pendingCount); ?></div>
                            <div class="kpi-sub">Requires attention</div>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="rewardAdmin.php?filter=low_stock" style="text-decoration:none;">
                        <div class="kpi-card" style="<?php echo $lowStockCount > 0 ? 'border-color:#fecaca;' : ''; ?>">
                            <div class="kpi-icon bg-red-soft"><iconify-icon icon="solar:box-minimalistic-bold-duotone"></iconify-icon></div>
                            <div class="kpi-label">Low Stock</div>
                            <div class="kpi-value text-danger"><?php echo number_format($lowStockCount); ?></div>
                            <div class="kpi-sub">Items below 5 units</div>
                        </div>
                    </a>
                </div>
            </div>

            <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="chart-card">
                    <div class="section-title">Redemption Activity</div>
                    <div style="height: 300px;">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="chart-card">
                    <div class="section-title">Top 5 Rewards</div>
                    <div style="height: 250px; position: relative;">
                        <canvas id="popChart"></canvas>
                    </div>
                    <div class="mt-3 text-center text-muted small">Based on quantity redeemed</div>
                </div>
            </div>
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


dashboardData.trendWindow = <?= $timeInterval ?? 30 ?>; // 7 / 30 / all default 30

// fill summary cards

function loadSummaryCards(){
   
    document.getElementById('cardTotalUsers').innerText = dashboardData.totalUsers ?? 0;
    document.getElementById('cardTotalAdmin').innerText = dashboardData.totalAdmin ?? 0;
    document.getElementById('cardActiveTeams').innerText = dashboardData.activeTeams ?? 0;
    document.getElementById('cardTotalSubmissions').innerText = dashboardData.totalModerator ?? 0;
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

function showTab(tabName) {

  // hide all
  document.querySelectorAll('.tab-content').forEach(tab => {
    tab.classList.add('hidden');
  });

  // show current
  document.getElementById(tabName)?.classList.remove('hidden');

  // reset buttons
  document.querySelectorAll('[id^="tab-"]').forEach(btn => {
    btn.classList.remove('text-primary','border-b-2','border-primary');
    btn.classList.add('text-dark-2');
  });

  // active button
  const activeBtn = document.getElementById('tab-' + tabName);
  if (activeBtn) {
    activeBtn.classList.add('text-primary','border-b-2','border-primary');
    activeBtn.classList.remove('text-dark-2');
  }

  // 🚀 INIT CHARTS ONCE
  if (!tabInitialized[tabName]) {
    switch (tabName) {
      case 'charts':
        initGrowthChart(dashboardData);
        break;
      case 'tables':
        initSubmissionTab();
        break;
      case 'user':
        initUsersTab();
        break;
      case 'Challenge':
        initChallengeTab();
        break;
      case 'reward':
        initRewardTab();
        break;
    }
    tabInitialized[tabName] = true;
  }
}


// ensure executed after whole page load
window.onload = () => showTab("charts");




/* Growth (ECharts) */
function initGrowthChart(dashboardData) {
    const el = document.getElementById('growthChart');
    if (!el) return;


    if (el.offsetHeight === 0) {
        setTimeout(() => initGrowthChart(dashboardData), 100);
        return;
    }

    window.dashboardCharts = window.dashboardCharts || {};

 
    if (window.dashboardCharts.growth) {
        try { window.dashboardCharts.growth.dispose(); } catch(e){}
    }

    const chart = echarts.init(el);

    
    
    const newUsers = dashboardData.newUsers || [];
    const newAdmins = dashboardData.newAdmins || [];
    const newModerators = dashboardData.newModerators || [];

    const dateSet = new Set();

    
    newUsers.forEach(r => r.day && dateSet.add(r.day));

    const labels = Array.from(dateSet).sort();

 
    // -----------------------------
    const newUsersMap = {};
    newUsers.forEach(r => newUsersMap[r.day] = Number(r.cnt || 0));
    
    const newAdminsMap = {};
    newAdmins.forEach(r => newAdminsMap[r.day] = Number(r.cnt || 0));


    const newModeratorsMap = {};
    newModerators.forEach(r => newModeratorsMap[r.day] = Number(r.cnt || 0));

 

    const newModeratorsSeries = labels.map(d => newModeratorsMap[d] || 0);
    const newUsersSeries = labels.map(d => newUsersMap[d] || 0);
    const newAdminsSeries = labels.map(d => newAdminsMap[d] || 0);

    // -----------------------------
   
    // -----------------------------
    chart.setOption({
    tooltip: { trigger: 'axis' },
    legend: {
        data: ['New Users', 'New Admins', 'New Moderators'],
        top: 10,
        textStyle: { fontSize: 12 }
    },
        grid: {
            top: 60,
            left: '6%',
            right: '4%',
            bottom: '12%',
            containLabel: true
        },
        xAxis: {
            type: 'category',
            data: labels,
            axisLabel: { rotate: 30 }
        },
        yAxis: {
            type: 'value',
            minInterval: 1
        },
       series: [
            {
                name: 'New Users',
                type: 'line',
                smooth: true,
                data: newUsersSeries,
                symbolSize: 6,
                lineStyle: { width: 3 }
            },
            {
                name: 'New Admins',
                type: 'line',
                smooth: true,
                data: newAdminsSeries,
                symbolSize: 6,
                lineStyle: { width: 3, color:'red' } 
            },
            {
                name: 'New Moderators',
                type: 'line',
                smooth: true,
                data: newModeratorsSeries,
                symbolSize: 6,
                lineStyle: { width: 3, color:'purple' } 
            }
        ]
    });

    window.dashboardCharts.growth = chart;

    if (!window._growthResizeBound) {
        window.addEventListener('resize', () => {
            try { window.dashboardCharts.growth.resize(); } catch(e){}
        });
        window._growthResizeBound = true;
    }
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


const catCtx = document
    .getElementById("challengeCatChart")
    .getContext("2d");

new Chart(catCtx, {
    type: "doughnut",
    data: {
        labels: dashboardData.challenge.catLabels,
        datasets: [{
            data: dashboardData.challenge.catCounts,
            backgroundColor: [
                '#6366f1', // indigo
                '#10b981', // emerald
                '#f59e0b', // amber
                '#ec4899', // pink
                '#06b6d4'  // cyan
            ],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,

        cutout: '55%',

        layout: {
            padding: {
                left: 10,
                right: 10,
                top: 10,
                bottom: 10
            }
        },

        plugins: {
            legend: {
                position: 'left',
                align: 'center',
                labels: {
                    boxWidth: 14,
                    boxHeight: 14,
                    padding: 14,
                    font: {
                        size: 12,
                        weight: '500'
                    },
                    color: '#374151'
                }
            },
            tooltip: {
                callbacks: {
                    label: function (ctx) {
                        const label = ctx.label || '';
                        const value = ctx.parsed;
                        return `${label}: ${value}`;
                    }
                }
            }
        }
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
                grid: { display:false },
                ticks: {
                    maxTicksLimit: 5  
                }
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

const catLabels = dashboardData.submissionCategories.map(r => r.challenge);
const catCounts = dashboardData.submissionCategories.map(r => r.total);


window.submissionCharts.cat = new Chart(ctxCat, {
    type: "bar",
    data: {
        labels: catLabels,
        datasets: [{
            label: "Submissions",
            data: catCounts,
            backgroundColor: "#6366F1",
            borderRadius: 6,
            barThickness: 12,
            maxBarThickness: 14
        }]
    },
    options: {
        indexAxis: "y",
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: {
                beginAtZero: true,
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

  // team doughnut

const teamCtx = document.getElementById('teamScoreChart').getContext('2d');

window.usersCharts.teamScore = new Chart(teamCtx, {
    type: 'bar', 
    data: {
        labels: (dashboardData.teamScores || []).map(t => t.teamName),
        datasets: [{
            label: 'Team Score',
            data: (dashboardData.teamScores || []).map(t => t.teamPoints),
            backgroundColor: '#4F46E5',
            borderRadius: 16, 
            barThickness: 18
        }]
    },
    options: {
        indexAxis: 'y', 
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => `Score: ${ctx.parsed.x}`
                }
            }
        },
        scales: {
            x: {
                beginAtZero: true,
                grid: { color: '#E5E7EB' },
                ticks: { color: '#374151' } 
            },
            y: {
                grid: { display: false },
                ticks: { color: '#374151' }
            }
        }
    }
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
    type: 'bar',
    data: {
        labels: (dashboardData.submissionActivity || []).map(r => r.name),
        datasets: [{
            label: 'Submissions',
            data: (dashboardData.submissionActivity || []).map(r => r.submissions),
            backgroundColor: (ctx) => {
                const colors = ['#22C55E','#10B981','#3B82F6','#F59E0B','#EF4444'];
                return colors[ctx.dataIndex % colors.length];
            },
            borderRadius: 8,       
            borderSkipped: false,  
            maxBarThickness: 50   
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false 
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            x: {
                grid: { display: false }, 
                ticks: { color: '#4B5563', font: { size: 12, weight: '500' } }
            },
            y: {
                beginAtZero: true,
                grid: { color: '#E5E7EB' }, 
                ticks: { color: '#4B5563', font: { size: 12, weight: '500' }, stepSize: 1 }
            }
        }
    }
});


  // points earned vs burned
const pointsCtx = document
  .getElementById('pointsActivityChart')
  .getContext('2d');

window.usersCharts.points = new Chart(pointsCtx, {
  type: 'doughnut',
  data: {
    labels: ['Earned', 'Burned'],
    datasets: [{
      data: [
        dashboardData.pointsOverall.earned,
        dashboardData.pointsOverall.burned
      ],
      backgroundColor: ['#4F46E5', '#F59E0B']
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom' },
      tooltip: {
        callbacks: {
          label: (ctx) => `${ctx.label}: ${ctx.parsed}`
        }
      }
    }
  }
});



}

/* Reward tab */
function initRewardTab() {

    // Redemption Trend
 const trendCtx = document.getElementById('trendChart').getContext('2d');

    const labels = (dashboardData.rewards.rewardTrendStatus || []).map(d => d.day);
    const pending = (dashboardData.rewards.rewardTrendStatus || []).map(d => d.pending || 0);
    const approved = (dashboardData.rewards.rewardTrendStatus || []).map(d => d.approved || 0);
    const denied = (dashboardData.rewards.rewardTrendStatus || []).map(d => d.denied || 0);

    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Pending',
                    data: pending,
                    borderColor: '#FBBF24',
                    backgroundColor: 'rgba(251,191,36,0.15)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Approved',
                    data: approved,
                    borderColor: '#22C55E',
                    backgroundColor: 'rgba(34,197,94,0.15)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Denied',
                    data: denied,
                    borderColor: '#EF4444',
                    backgroundColor: 'rgba(239,68,68,0.15)',
                    fill: true,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grace: 1
                }
            }
        }
    });

    // Top 5 Rewards
        const popColors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899'];

            const ctxPop = document.getElementById('popChart').getContext('2d');
            new Chart(ctxPop, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($popLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($popData); ?>,
                        backgroundColor: popColors,
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: { 
                            position: 'right', 
                            labels: { boxWidth: 12, font: { size: 11 } } 
                        }
                    }
                }
            });
}

</script>

<?php include "includes/layout_end.php"; ?>

</body>
</html>
