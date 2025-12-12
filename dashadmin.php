<?php
session_start();
require_once "db_connect.php";

// Get filter from URL
$statusFilter = $_GET['status'] ?? 'all';
$whereStatus = ($statusFilter !== 'all') 
    ? " WHERE s.status='" . $conn->real_escape_string($statusFilter) . "' "
    : "";

// ----------------------------------
// 1. Total Users
// ----------------------------------
$totalUsers = $conn->query("SELECT COUNT(*) AS cnt FROM user")->fetch_assoc()['cnt'];

// ----------------------------------
// 2. New Users (Last 7 days)
// ----------------------------------
$newUsers = $conn->query("
    SELECT COUNT(*) AS cnt, DATE(created_at) AS day
    FROM user
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 3. Active Teams
// ----------------------------------
$activeTeams = $conn->query("
    SELECT COUNT(*) AS cnt FROM team
")->fetch_assoc()['cnt'];

// ----------------------------------
// 4. Total Submissions
// ----------------------------------
$totalSubmissions = $conn->query("SELECT COUNT(*) AS cnt FROM sub")->fetch_assoc()['cnt'];

// ----------------------------------
// 5. Submission counts by status
// ----------------------------------
$submissionStatusQuery = $conn->query("
    SELECT status, COUNT(*) AS cnt
    FROM sub
    GROUP BY status
")->fetch_all(MYSQLI_ASSOC);

$submissionCounts = ['Pending'=>0,'Approved'=>0,'Denied'=>0];
foreach ($submissionStatusQuery as $row) {
    $submissionCounts[$row['status']] = (int)$row['cnt'];
}

// ----------------------------------
// 6. Recent Submissions (with filter)
// ----------------------------------
$recentSubmissions = $conn->query("
    SELECT s.*, u.firstName AS username, t.teamName, c.challengeTitle 
    FROM sub s
    LEFT JOIN user u ON s.userID = u.userID
    LEFT JOIN team t ON u.teamID = t.teamID
    LEFT JOIN challenge c ON s.challengeID = c.challengeID
    $whereStatus
    ORDER BY s.uploaded_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 7. Submission Trend (Last 30 days)
// ----------------------------------
$submissionTrend = $conn->query("
    SELECT DATE(uploaded_at) AS day,
        SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status='Denied' THEN 1 ELSE 0 END) AS denied
    FROM sub
    GROUP BY DATE(uploaded_at)
    ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 8. Top Categories (challenge)
// ----------------------------------
$topCategories = $conn->query("
    SELECT c.challengeTitle AS category, COUNT(*) AS cnt
    FROM sub s
    LEFT JOIN challenge c ON s.challengeID = c.challengeID
    GROUP BY s.challengeID
    ORDER BY cnt DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 9. Users by Role distribution
// ----------------------------------
$roleDistribution = [];
$roleData = $conn->query("
    SELECT role, COUNT(*) AS cnt
    FROM user
    GROUP BY role
")->fetch_all(MYSQLI_ASSOC);
foreach ($roleData as $row) {
    $roleDistribution[$row['role']] = (int)$row['cnt'];
}

// ----------------------------------
// 10. Login trend (last 7 days)
// ----------------------------------
$loginTrend = $conn->query("
    SELECT DATE(last_online) AS date, COUNT(*) AS count
    FROM user
    WHERE last_online >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(last_online)
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 11. Submission Activity Top Users
// ----------------------------------
$submissionActivity = $conn->query("
    SELECT u.firstName AS name, COUNT(s.submissionID) AS submissions
    FROM user u
    LEFT JOIN sub s ON u.userID = s.userID
    GROUP BY u.userID
    ORDER BY submissions DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 12. Points Earned vs Burned
// ----------------------------------
$pointsActivity = $conn->query("
    SELECT u.firstName AS name,
        SUM(CASE WHEN p.transactionType='earned' THEN p.pointsTransaction ELSE 0 END) AS earned,
        SUM(CASE WHEN p.transactionType='burned' THEN p.pointsTransaction ELSE 0 END) AS burned
    FROM user u
    LEFT JOIN pointtransaction p ON p.userID = u.userID
    GROUP BY u.userID
    ORDER BY earned DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 13. User Details Table
// ----------------------------------
$userDetails = $conn->query("
    SELECT u.userID, u.firstName AS name, u.email, u.role, 
           t.teamName,
           (SELECT COUNT(*) FROM sub s WHERE s.userID=u.userID) AS submissions,
           (SELECT SUM(pointsTransaction) FROM pointtransaction p WHERE p.userID=u.userID AND p.transactionType='earned') AS earned,
           (SELECT SUM(pointsTransaction) FROM pointtransaction p WHERE p.userID=u.userID AND p.transactionType='burned') AS burned,
           u.last_online AS lastLogin
    FROM user u
    LEFT JOIN team t ON u.teamID = t.teamID
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 14. Reward stock & redeemed statistics
// ----------------------------------
$rewards = $conn->query("SELECT * FROM reward")->fetch_all(MYSQLI_ASSOC);

$totalStock = $conn->query("
    SELECT SUM(stockQuantity) AS stock
    FROM reward
")->fetch_assoc()['stock'] ?? 0;

$lowStockRewards = $conn->query("
    SELECT rewardName, stockQuantity
    FROM reward
    WHERE stockQuantity <= 3
")->fetch_all(MYSQLI_ASSOC);

// Total Redeemed count
$totalRedeemed = $conn->query("
    SELECT COUNT(*) AS cnt
    FROM redemptionrequest
    WHERE status='Approved'
")->fetch_assoc()['cnt'] ?? 0;

// Top Redeemers
$topRedeemers = $conn->query("
    SELECT u.firstName, COUNT(*) AS cnt
    FROM redemptionrequest r
    JOIN user u ON r.userID = u.userID
    WHERE r.status='Approved'
    GROUP BY r.userID
    ORDER BY cnt DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

?>

<body>
<script>
const dashboardData = {

    // Summary
    totalUsers: <?= $totalUsers ?>,
    activeTeams: <?= $activeTeams ?>,
    totalSubmissions: <?= $totalSubmissions ?>,

    // Submission Status Counters
    submissionCounts: <?= json_encode($submissionCounts) ?>,

    // New Users Trend (last 7 days)
    newUsers: <?= json_encode($newUsers) ?>,

    // Recent Submissions Table
    recentSubmissions: <?= json_encode($recentSubmissions) ?>,

    // Submission Trend (last 30 days)
    submissionTrend: <?= json_encode($submissionTrend) ?>,

    // Categories
    topCategories: <?= json_encode($topCategories) ?>,

    // Users – Chart.js Data
    roleDistribution: <?= json_encode($roleDistribution) ?>,
    loginTrend: <?= json_encode($loginTrend) ?>,
    submissionActivity: <?= json_encode($submissionActivity) ?>,
    pointsActivity: <?= json_encode($pointsActivity) ?>,
    userDetails: <?= json_encode($userDetails) ?>,

    // Rewards
    rewards: <?= json_encode($rewards) ?>,
    totalStock: <?= $totalStock ?>,
    totalRedeemed: <?= $totalRedeemed ?>,
    lowStockRewards: <?= json_encode($lowStockRewards) ?>,
    topRedeemers: <?= json_encode($topRedeemers) ?>
};
</script>

<div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">New User (week) </p>
                  <h3 class="stat-card-value" id="cardNewUsers"></h3>

                </div>
                <div class="w-12 h-12 rounded-lg bg-secondary/10 flex items-center justify-center text-secondary">
                  <i class="fas fa-book text-xl"> </i>
                </div>
              </div>
              
            </div>
            <!-- 活跃用户卡片 -->
           <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">Active Teams</p>
                 <h3 class="stat-card-value" id="cardActiveTeams"></h3>

                  
                </div>
                <div
                  class="w-12 h-12 rounded-lg bg-info/10 flex items-center justify-center text-info"
                >
                  <i class="fas fa-chart-line text-xl"> </i>
                </div>
              </div>
            </div>
            <!-- 系统状态卡片 -->
            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">Total Submission</p>
                  <h3 class="stat-card-value" id="cardTotalSubmissions"></h3>

                  
                </div>
                <div
                  class="w-12 h-12 rounded-lg bg-success/10 flex items-center justify-center text-success"
                >
                  <i class="fas fa-server text-xl"> </i>
                </div>
              </div>
              
            </div>
          </div>


          <!-- Tabs 容器 -->
            <div class="bg-white rounded-2xl shadow-lg p-6">

                <!-- Tab Buttons -->
                <div class="flex border-b border-light-2 mb-6 space-x-6">

                    <button class="py-3 px-4 text-sm font-semibold text-primary border-b-2 border-primary transition-all" onclick="showTab('charts')" id="tab-charts"> Growth </button>
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('user')" id="tab-user"> Users</button>
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('tables')" id="tab-tables"> Submission </button>
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('reward')" id="tab-reward"> Reward </button>

                </div>


            <!-- Tab 内容 -->
           <div id="charts" class="tab-content">
            <div class="p-4 bg-white rounded-xl shadow-md">
              <div id="growthChart" class="w-full h-[400px]"></div> <!-- div 容器，不用 canvas -->
            </div>
          </div>

            <div id="tables" class="tab-content hidden">
                <!-- 顶部 KPI 卡片 -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white rounded-xl shadow-card p-5 text-center">
                        <h3 class="text-sm text-dark-2">Total Submissions</h3>
                        <p class="text-2xl font-bold text-dark" id="totalSubmissions">0</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-card p-5 text-center">
                        <h3 class="text-sm text-dark-2">Pending</h3>
                        <p class="text-2xl font-bold text-yellow-500" id="pendingSubmissions">0</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-card p-5 text-center">
                        <h3 class="text-sm text-dark-2">Approved</h3>
                        <p class="text-2xl font-bold text-green-500" id="approvedSubmissions">0</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-card p-5 text-center">
                        <h3 class="text-sm text-dark-2">Denied</h3>
                        <p class="text-2xl font-bold text-red-500" id="deniedSubmissions">0</p>
                    </div>
                </div>

                <!-- 中间图表区域 -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white rounded-xl shadow-card p-5">
                        <h3 class="text-lg font-bold text-dark mb-4">Submission Trend (Last 30 Days)</h3>
                        <canvas id="submissionTrendChart" class="w-full h-64"></canvas>
                    </div>
                    <div class="bg-white rounded-xl shadow-card p-5">
                        <h3 class="text-lg font-bold text-dark mb-4">Top Submission Categories</h3>
                        <canvas id="topCategoriesChart" class="w-full h-64"></canvas>
                    </div>
                </div>

                <!-- 底部最近提交表格 -->
                <div class="bg-white rounded-xl shadow-card p-5">
                    <h3 class="text-lg font-bold text-dark mb-4">Recent Submissions</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm text-left">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-4 py-2">User</th>
                                    <th class="px-4 py-2">Team</th>
                                    <th class="px-4 py-2">Challenge</th>
                                    <th class="px-4 py-2">Status</th>
                                    <th class="px-4 py-2">Submitted At</th>
                                </tr>
                            </thead>
                            <tbody id="recentSubmissionsBody">
                                <!-- JS 填充 -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            
        <div id="user" class="tab-content hidden">

              <!-- 图表区域 -->
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                
                <!-- Role 分布 Donut -->
                <div class="bg-white rounded-xl shadow-card p-5 h-56">
                  <h3 class="text-lg font-bold text-dark mb-4">Users by Role</h3>
                  <canvas id="roleDistributionChart" class="w-full h-full"></canvas>
                </div>

                <!-- 登录趋势 / Submission 活跃度 -->
                <div class="bg-white rounded-xl shadow-card p-5 h-56">
                  <h3 class="text-lg font-bold text-dark mb-4">Login Trend (Last 7 Days)</h3>
                  <canvas id="loginTrendChart" class="w-full h-full"></canvas>
                </div>

                <div class="bg-white rounded-xl shadow-card p-5 h-56">
                  <h3 class="text-lg font-bold text-dark mb-4">Submission Activity (Top Users)</h3>
                  <canvas id="submissionActivityChart" class="w-full h-full"></canvas>
                </div>

                <div class="bg-white rounded-xl shadow-card p-5 h-56">
                  <h3 class="text-lg font-bold text-dark mb-4">Points Earned vs Burned</h3>
                  <canvas id="pointsActivityChart" class="w-full h-full"></canvas>
                </div>

              </div>

            </div>




        <!-- Reward Tab -->
          <div id="reward" class="tab-content hidden">

              <!-- 顶部卡片 -->
              <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                  <div class="bg-white shadow-card rounded-xl p-5 flex flex-col items-center">
                      <h3 class="text-sm text-dark-2">Total Rewards Redeemed</h3>
                      <p id="totalRewardsRedeemed" class="text-2xl font-bold text-dark mt-2">0</p>
                  </div>
                  <div class="bg-white shadow-card rounded-xl p-5 flex flex-col items-center">
                      <h3 class="text-sm text-dark-2">Total Rewards Stock</h3>
                      <p id="totalRewardsStock" class="text-2xl font-bold text-dark mt-2">0</p>
                  </div>
                  <div class="bg-white shadow-card rounded-xl p-5 flex flex-col items-center">
                      <h3 class="text-sm text-dark-2">Low Stock Alerts</h3>
                      <p id="lowStockCount" class="text-2xl font-bold text-red-500 mt-2">0</p>
                  </div>
              </div>

              <!-- 中间图表区域 -->
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                  <!-- Redeemed by Type -->
                  <div class="bg-white shadow-card rounded-xl p-5 h-[350px]">
                      <h3 class="text-lg font-bold text-dark mb-4">Redeemed by Type</h3>
                      <canvas id="redeemedByTypeChart" class="w-full h-full max-h-[300px]"></canvas>
                  </div>

                  <!-- Redemptions Trend -->
                  <div class="bg-white shadow-card rounded-xl p-5 h-[350px]">
                      <h3 class="text-lg font-bold text-dark mb-4">Redemptions Trend</h3>
                      <canvas id="redemptionsTrendChart" class="w-full h-full"></canvas>
                  </div>
              </div>

          </div>
     </div>

      </main>
    </div>
   

    

    <script>

// =======================
// Summary
// =======================
function loadSummaryCards() {
    document.getElementById("cardTotalUsers").innerText = dashboardData.totalUsers;
    document.getElementById("cardNewUsers").innerText = dashboardData.newUsers.reduce((sum, d) => sum + parseInt(d.cnt), 0);
    document.getElementById("cardActiveTeams").innerText = dashboardData.activeTeams;
    document.getElementById("cardTotalSubmissions").innerText = dashboardData.totalSubmissions;
}

// =======================
// Submission KPI
// =======================
function loadSubmissionKPI() {
    document.getElementById("totalSubmissions").innerText = dashboardData.totalSubmissions;
    document.getElementById("pendingSubmissions").innerText = dashboardData.submissionCounts.Pending;
    document.getElementById("approvedSubmissions").innerText = dashboardData.submissionCounts.Approved;
    document.getElementById("deniedSubmissions").innerText = dashboardData.submissionCounts.Denied;
}

// =======================
// Recent Submission Table
// =======================
function loadRecentSubmissions() {
    const tbody = document.getElementById("recentSubmissionsBody");
    tbody.innerHTML = "";

    dashboardData.recentSubmissions.forEach(sub => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td class="px-4 py-2">${sub.username}</td>
            <td class="px-4 py-2">${sub.teamName ?? "-"}</td>
            <td class="px-4 py-2">${sub.challengeTitle ?? "-"}</td>
            <td class="px-4 py-2">${sub.status}</td>
            <td class="px-4 py-2">${sub.uploaded_at}</td>
        `;
        tbody.appendChild(tr);
    });
}

// =======================
// Growth Tab (Charts)
// =======================
function initGrowthTab() {

    loadSummaryCards();

    const el = document.getElementById("growthChart");
    const chart = echarts.init(el);

    const dates = dashboardData.newUsers.map(d => d.day);
    const newUsers = dashboardData.newUsers.map(d => d.cnt);
    const submissions = dashboardData.submissionTrend.map(t => t.pending + t.approved + t.denied);

    chart.setOption({
        tooltip: { trigger: 'axis' },
        legend: { data: ['New Users', 'Submissions'] },
        xAxis: { type: 'category', data: dates },
        yAxis: { type: 'value' },
        series: [
            { name: 'New Users', type: 'line', data: newUsers },
            { name: 'Submissions', type: 'line', data: submissions }
        ]
    });

    window.dashboardCharts = { growth: chart };
}

// =======================
// Submission Tab
// =======================
function initSubmissionTab() {

    loadSubmissionKPI();
    loadRecentSubmissions();

    // Submission Trend Chart
    const ctxTrend = document.getElementById('submissionTrendChart').getContext('2d');
    window.submissionTrendChart = new Chart(ctxTrend, {
        type: 'line',
        data: {
            labels: dashboardData.submissionTrend.map(d => d.day),
            datasets: [
                { label:'Pending', data: dashboardData.submissionTrend.map(d => d.pending), borderColor:'#FBBF24' },
                { label:'Approved', data: dashboardData.submissionTrend.map(d => d.approved), borderColor:'#22C55E' },
                { label:'Denied', data: dashboardData.submissionTrend.map(d => d.denied), borderColor:'#EF4444' }
            ]
        }
    });

    // Top category bar chart
    const ctxCat = document.getElementById('topCategoriesChart').getContext('2d');
    window.topCategoriesChart = new Chart(ctxCat, {
        type: 'bar',
        data: {
            labels: dashboardData.topCategories.map(c => c.category),
            datasets: [{
                label: 'Submissions',
                data: dashboardData.topCategories.map(c => c.cnt),
                backgroundColor: '#3B82F6'
            }]
        },
        options: { indexAxis: 'y' }
    });
}

// =======================
// Users Tab
// =======================
function initUsersTab() {

    window.usersCharts = {};

    // Role donut
    const roleCtx = document.getElementById("roleDistributionChart").getContext("2d");
    usersCharts.roleChart = new Chart(roleCtx, {
        type: "doughnut",
        data: {
            labels: Object.keys(dashboardData.roleDistribution),
            datasets: [{
                data: Object.values(dashboardData.roleDistribution),
                backgroundColor: ["#2563EB", "#16A34A", "#F59E0B", "#EF4444"]
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // Login trend
    const loginCtx = document.getElementById("loginTrendChart").getContext("2d");
    usersCharts.loginChart = new Chart(loginCtx, {
        type: "line",
        data: {
            labels: dashboardData.loginTrend.map(a => a.date),
            datasets: [{
                label: "Logins",
                data: dashboardData.loginTrend.map(a => a.count),
                borderColor: "#2563EB",
                fill: false,
                tension: 0.3
            }]
        }
    });

    // Submission Activity
    const subCtx = document.getElementById("submissionActivityChart").getContext("2d");
    usersCharts.submissionChart = new Chart(subCtx, {
        type: "bar",
        data: {
            labels: dashboardData.submissionActivity.map(a => a.name),
            datasets: [{
                label: "Submissions",
                data: dashboardData.submissionActivity.map(a => a.submissions),
                backgroundColor: "#16A34A"
            }]
        }
    });

    // Points Chart
    const pointsCtx = document.getElementById("pointsActivityChart").getContext("2d");
    usersCharts.pointsChart = new Chart(pointsCtx, {
        type: "bar",
        data: {
            labels: dashboardData.pointsActivity.map(a => a.name),
            datasets: [
                {
                    label: "Earned",
                    data: dashboardData.pointsActivity.map(a => a.earned),
                    backgroundColor: "#2563EB"
                },
                {
                    label: "Burned",
                    data: dashboardData.pointsActivity.map(a => a.burned),
                    backgroundColor: "#F59E0B"
                }
            ]
        }
    });

}

// =======================
// Reward Tab
// =======================
function initRewardTab() {

    window.rewardCharts = {};

    document.getElementById("totalRewardsRedeemed").innerText = dashboardData.totalRedeemed;
    document.getElementById("totalRewardsStock").innerText = dashboardData.totalStock;
    document.getElementById("lowStockCount").innerText = dashboardData.lowStockRewards.length;

    // Redeemed by Type
    const doughnutCtx = document.getElementById("redeemedByTypeChart").getContext("2d");
    rewardCharts.typeChart = new Chart(doughnutCtx, {
        type: "doughnut",
        data: {
            labels: dashboardData.rewards.map(r => r.rewardName),
            datasets: [{
                data: dashboardData.rewards.map(r => r.redeemedQuantity ?? 0),
                backgroundColor: ["#2563EB", "#16A34A", "#F59E0B", "#EF4444", "#8B5CF6"]
            }]
        }
    });

    // Redemption Trend (placeholder)
    const trendCtx = document.getElementById("redemptionsTrendChart").getContext("2d");
    rewardCharts.trendChart = new Chart(trendCtx, {
        type: "line",
        data: {
            labels: dashboardData.submissionTrend.map(a => a.day),
            datasets: [{
                label: "Redemptions",
                data: dashboardData.submissionTrend.map(a => a.approved),
                borderColor: "#16A34A",
                backgroundColor: "rgba(34,197,94,0.2)",
                fill: true,
                tension: 0.3
            }]
        }
    });
}

// =======================
// Tab controller
// =======================
let tabInitialized = {
    charts: false,
    user: false,
    tables: false,
    reward: false
};

function showTab(tab) {

    document.querySelectorAll('.tab-content')
        .forEach(el => el.classList.add('hidden'));
    document.getElementById(tab).classList.remove('hidden');

    if (!tabInitialized[tab]) {

        if (tab === 'charts') initGrowthTab();
        if (tab === 'user') initUsersTab();
        if (tab === 'tables') initSubmissionTab();
        if (tab === 'reward') initRewardTab();

        tabInitialized[tab] = true;
    }
}

showTab('charts');

</script>
