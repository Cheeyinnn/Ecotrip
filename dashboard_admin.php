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
$totalUsers = (int)($conn->query("SELECT COUNT(*) AS cnt FROM user")->fetch_assoc()['cnt'] ?? 0);

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
$activeTeams = (int)($conn->query("SELECT COUNT(*) AS cnt FROM team")->fetch_assoc()['cnt'] ?? 0);

// ----------------------------------
// 4. Total Submissions
// ----------------------------------
$totalSubmissions = (int)($conn->query("SELECT COUNT(*) AS cnt FROM sub")->fetch_assoc()['cnt'] ?? 0);

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
// For better charting we ensure days with zero appear - but here we return DB rows; JS may normalize
$submissionTrend = $conn->query("
    SELECT DATE(uploaded_at) AS day,
        SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status='Denied' THEN 1 ELSE 0 END) AS denied
    FROM sub
    WHERE uploaded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(uploaded_at)
    ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 8. Top Categories (challenge)
// ----------------------------------
$topCategories = $conn->query("
    SELECT COALESCE(c.challengeTitle, 'Unknown') AS category, COUNT(*) AS cnt
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
        COALESCE(SUM(CASE WHEN p.transactionType='earned' THEN p.pointsTransaction ELSE 0 END),0) AS earned,
        COALESCE(SUM(CASE WHEN p.transactionType='burned' THEN p.pointsTransaction ELSE 0 END),0) AS burned
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
    SELECT u.userID as id, u.firstName AS name, u.email, u.role,
           t.teamName,
           (SELECT COUNT(*) FROM sub s WHERE s.userID=u.userID) AS submissions,
           (SELECT COALESCE(SUM(pointsTransaction),0) FROM pointtransaction p WHERE p.userID=u.userID AND p.transactionType='earned') AS earned,
           (SELECT COALESCE(SUM(pointsTransaction),0) FROM pointtransaction p WHERE p.userID=u.userID AND p.transactionType='burned') AS burned,
           u.last_online AS lastLogin
    FROM user u
    LEFT JOIN team t ON u.teamID = t.teamID
")->fetch_all(MYSQLI_ASSOC);

// ----------------------------------
// 14. Reward stock & redeemed statistics
// ----------------------------------
$rewards = $conn->query("SELECT * FROM reward")->fetch_all(MYSQLI_ASSOC);

$totalStock = (int)($conn->query("
    SELECT SUM(stockQuantity) AS stock
    FROM reward
")->fetch_assoc()['stock'] ?? 0);

$lowStockRewards = $conn->query("
    SELECT rewardName, stockQuantity
    FROM reward
    WHERE stockQuantity <= 3
")->fetch_all(MYSQLI_ASSOC);

// Total Redeemed count
$totalRedeemed = (int)($conn->query("
    SELECT COUNT(*) AS cnt
    FROM redemptionrequest
    WHERE status='Approved'
")->fetch_assoc()['cnt'] ?? 0);

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
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin Dashboard</title>

  <!-- Tailwind CDN (quick start) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Font Awesome -->
  <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <!-- Charts -->
  <script src="https://cdn.jsdelivr.net/npm/echarts/dist/echarts.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    /* small helpers - you can move these to dashboard.css */
    .stat-card-value { font-weight:700; font-size:clamp(1.25rem,2.2vw,2rem); color:#1D2129; }
    .stat-card-label { color:#4E5969; font-size:0.95rem; }
    .shadow-card { box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
  </style>
</head>
<body class="bg-gray-50 font-sans text-dark">

<script>
// server -> client data
const dashboardData = {
    totalUsers: <?= json_encode($totalUsers) ?>,
    activeTeams: <?= json_encode($activeTeams) ?>,
    totalSubmissions: <?= json_encode($totalSubmissions) ?>,
    submissionCounts: <?= json_encode($submissionCounts) ?>,
    newUsers: <?= json_encode($newUsers) ?>,
    recentSubmissions: <?= json_encode($recentSubmissions) ?>,
    submissionTrend: <?= json_encode($submissionTrend) ?>,
    topCategories: <?= json_encode($topCategories) ?>,
    roleDistribution: <?= json_encode($roleDistribution) ?>,
    loginTrend: <?= json_encode($loginTrend) ?>,
    submissionActivity: <?= json_encode($submissionActivity) ?>,
    pointsActivity: <?= json_encode($pointsActivity) ?>,
    userDetails: <?= json_encode($userDetails) ?>,
    rewards: <?= json_encode($rewards) ?>,
    totalStock: <?= json_encode($totalStock) ?>,
    totalRedeemed: <?= json_encode($totalRedeemed) ?>,
    lowStockRewards: <?= json_encode($lowStockRewards) ?>,
    topRedeemers: <?= json_encode($topRedeemers) ?>
};
</script>

<div class="max-w-7xl mx-auto p-6">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold">Admin Dashboard</h1>
      <p class="text-sm text-gray-600">实时监控系统运行状态和关键指标</p>
    </div>

    <div class="flex items-center gap-3">
      <label class="text-sm text-gray-700">Filter:</label>
      <select id="statusFilter" class="border rounded px-2 py-1" onchange="applyFilter()">
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="denied" <?= $statusFilter === 'denied' ? 'selected' : '' ?>>Denied</option>
      </select>

      <button onclick="window.location.reload()" class="bg-primary text-white px-3 py-1 rounded shadow">
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
      <p class="stat-card-label">New User (week)</p>
      <div class="stat-card-value" id="cardNewUsers">—</div>
    </div>
    <div class="bg-white p-5 rounded-2xl shadow-card">
      <p class="stat-card-label">Active Teams</p>
      <div class="stat-card-value" id="cardActiveTeams">—</div>
    </div>
    <div class="bg-white p-5 rounded-2xl shadow-card">
      <p class="stat-card-label">Total Submission</p>
      <div class="stat-card-value" id="cardTotalSubmissions">—</div>
    </div>
  </div>

  <!-- tabs -->
  <div class="bg-white rounded-2xl p-6 shadow-card">
    <div class="flex gap-4 border-b pb-4 mb-6">
      <button id="tab-charts" class="pb-3 border-b-2 border-blue-600 text-blue-600" onclick="showTab('charts')">Growth</button>
      <button id="tab-user" class="text-gray-600" onclick="showTab('user')">Users</button>
      <button id="tab-tables" class="text-gray-600" onclick="showTab('tables')">Submission</button>
      <button id="tab-reward" class="text-gray-600" onclick="showTab('reward')">Reward</button>
    </div>

    <!-- charts tab -->
    <div id="charts" class="tab-content">
      <div class="p-4 bg-white rounded-xl shadow-md">
        <div id="growthChart" style="height:380px;"></div>
      </div>
    </div>

    <!-- tables tab -->
    <div id="tables" class="tab-content hidden">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl p-4 shadow-card text-center">
          <p class="text-sm text-gray-600">Total Submissions</p>
          <p class="text-2xl font-bold" id="totalSubmissions">0</p>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-card text-center">
          <p class="text-sm text-gray-600">Pending</p>
          <p class="text-2xl font-bold text-yellow-500" id="pendingSubmissions">0</p>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-card text-center">
          <p class="text-sm text-gray-600">Approved</p>
          <p class="text-2xl font-bold text-green-500" id="approvedSubmissions">0</p>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-card text-center">
          <p class="text-sm text-gray-600">Denied</p>
          <p class="text-2xl font-bold text-red-500" id="deniedSubmissions">0</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-white p-5 rounded-xl shadow-card">
          <h3 class="font-semibold mb-4">Submission Trend (Last 30 Days)</h3>
          <canvas id="submissionTrendChart" class="w-full h-64"></canvas>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-card">
          <h3 class="font-semibold mb-4">Top Submission Categories</h3>
          <canvas id="topCategoriesChart" class="w-full h-64"></canvas>
        </div>
      </div>
>
    </div>

    <!-- users tab -->
    <div id="user" class="tab-content hidden">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white p-5 rounded-xl shadow-card h-56">
          <h3 class="font-semibold mb-4">Users by Role</h3>
          <canvas id="roleDistributionChart" class="w-full h-full"></canvas>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-card h-56">
          <h3 class="font-semibold mb-4">Login Trend (Last 7 Days)</h3>
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

    <!-- reward tab -->
    <div id="reward" class="tab-content hidden">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-5 rounded-xl shadow-card text-center">
          <p class="text-sm text-gray-600">Total Rewards Redeemed</p>
          <p id="totalRewardsRedeemed" class="text-2xl font-bold mt-2">0</p>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-card text-center">
          <p class="text-sm text-gray-600">Total Rewards Stock</p>
          <p id="totalRewardsStock" class="text-2xl font-bold mt-2">0</p>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-card text-center">
          <p class="text-sm text-gray-600">Low Stock Alerts</p>
          <p id="lowStockCount" class="text-2xl font-bold text-red-500 mt-2">0</p>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white p-5 rounded-xl shadow-card h-[350px]">
          <h3 class="font-semibold mb-4">Redeemed by Type</h3>
          <canvas id="redeemedByTypeChart" class="w-full h-full"></canvas>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-card h-[350px]">
          <h3 class="font-semibold mb-4">Redemptions Trend</h3>
          <canvas id="redemptionsTrendChart" class="w-full h-full"></canvas>
        </div>
      </div>


    </div>

  </div>
</div>

<script>
// utility
function applyFilter(){
  const f = document.getElementById('statusFilter').value;
  const url = new URL(window.location.href);
  url.searchParams.set('status', f);
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

// Tab controller & lazy init
const tabInitialized = { charts:false, user:false, tables:false, reward:false };

function showTab(tab){
  document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
  document.getElementById(tab).classList.remove('hidden');

  // header active btn
  ['charts','user','tables','reward'].forEach(t=>{
    const btn = document.getElementById('tab-' + t);
    if(!btn) return;
    if(t===tab){ btn.classList.add('border-b-2','border-blue-600'); btn.classList.remove('text-gray-600'); btn.classList.add('text-blue-600'); }
    else { btn.classList.remove('border-b-2','border-blue-600','text-blue-600'); btn.classList.add('text-gray-600'); }
  });

  if(!tabInitialized[tab]){
    if(tab==='charts') initGrowthChart();
    if(tab==='user') initUsersTab();
    if(tab==='tables') initSubmissionTab();
    if(tab==='reward') initRewardTab();
    tabInitialized[tab] = true;
  }

  // resize charts slightly later
  setTimeout(()=> {
    if(window.dashboardCharts?.growth) window.dashboardCharts.growth.resize?.();
    Object.values(window.usersCharts || {}).forEach(c => c.resize?.());
    Object.values(window.submissionCharts || {}).forEach(c => c.resize?.());
    Object.values(window.rewardCharts || {}).forEach(c => c.resize?.());
  }, 100);
}

// default open
showTab('charts');

/* ===========================
   Growth (ECharts)
   =========================== */
function initGrowthChart(){
  // fill small summary (already done)
  // prepare data
  const el = document.getElementById('growthChart');
  const chart = echarts.init(el);
  // x: last 7 days (if DB newUsers missing some days we fallback to last 7)
  const today = new Date();
  const days = [];
  for(let i=6;i>=0;i--){ const d=new Date(today); d.setDate(today.getDate()-i); days.push(`${d.getMonth()+1}/${d.getDate()}`); }

  // Build newUsers per last7days (map by day string from DB if present)
  const newUsersMap = {};
  (dashboardData.newUsers || []).forEach(r => { newUsersMap[r.day] = parseInt(r.cnt)||0; });
  const newUsersSeries = days.map(dd => {
    // dd is M/D, DB returns yyyy-mm-dd, so try match by date - fallback: sums
    // We will try to map by constructing yyyy-mm-dd for last7days:
    const dt = new Date();
    const parts = dd.split('/');
    // simple approach: find index in dashboardData.newUsers by day suffix match
    const match = (dashboardData.newUsers || []).find(x => x.day && x.day.endsWith('-' + ('0'+parseInt(parts[1])).slice(-2)));
    return match ? parseInt(match.cnt||0) : 0;
  });

  // submissions per day from submissionTrend (DB limited to last 30 days, we map)
  const subsMap = {};
  (dashboardData.submissionTrend || []).forEach(r => { subsMap[r.day] = (parseInt(r.pending)||0) + (parseInt(r.approved)||0) + (parseInt(r.denied)||0); });
  const submissionsSeries = days.map(dd => {
    // try to match by day suffix similar to above
    const parts = dd.split('/');
    const match = (dashboardData.submissionTrend || []).find(x => x.day && x.day.endsWith('-' + ('0'+parseInt(parts[1])).slice(-2)));
    return match ? (parseInt(match.pending||0) + parseInt(match.approved||0) + parseInt(match.denied||0)) : 0;
  });

  const option = {
    tooltip:{ trigger:'axis' },
    legend:{ data:['New Users','Submissions'] },
    xAxis:{ type:'category', data: days },
    yAxis:{ type:'value' },
    series:[
      { name:'New Users', type:'line', smooth:true, data: newUsersSeries },
      { name:'Submissions', type:'line', smooth:true, data: submissionsSeries }
    ]
  };
  chart.setOption(option);
  window.dashboardCharts = { growth: chart };
  window.addEventListener('resize', ()=> chart.resize());
}

/* ===========================
   Submission Tab (Chart.js)
   =========================== */
function initSubmissionTab(){
  // KPI
  document.getElementById('totalSubmissions').innerText = dashboardData.totalSubmissions ?? 0;
  document.getElementById('pendingSubmissions').innerText = dashboardData.submissionCounts?.Pending ?? 0;
  document.getElementById('approvedSubmissions').innerText = dashboardData.submissionCounts?.Approved ?? 0;
  document.getElementById('deniedSubmissions').innerText = dashboardData.submissionCounts?.Denied ?? 0;

  // recent submissions table
  const tbody = document.getElementById('recentSubmissionsBody');
  tbody.innerHTML = '';
  (dashboardData.recentSubmissions || []).forEach(sub => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="px-4 py-2">${sub.username ?? '-'}</td>
      <td class="px-4 py-2">${sub.teamName ?? '-'}</td>
      <td class="px-4 py-2">${sub.challengeTitle ?? '-'}</td>
      <td class="px-4 py-2">${sub.status ?? '-'}</td>
      <td class="px-4 py-2">${sub.uploaded_at ?? sub.uploadedAt ?? '-'}</td>
    `;
    tbody.appendChild(tr);
  });

  // submission trend chart
  const ctxTrend = document.getElementById('submissionTrendChart').getContext('2d');
  const labels = (dashboardData.submissionTrend || []).map(d => d.day);
  const pending = (dashboardData.submissionTrend || []).map(d => d.pending || 0);
  const approved = (dashboardData.submissionTrend || []).map(d => d.approved || 0);
  const denied = (dashboardData.submissionTrend || []).map(d => d.denied || 0);

  window.submissionCharts = {};
  window.submissionCharts.trend = new Chart(ctxTrend, {
    type: 'line',
    data: { labels, datasets:[
      { label:'Pending', data: pending, borderColor:'#FBBF24', fill:true, backgroundColor:'rgba(251,191,36,0.15)' },
      { label:'Approved', data: approved, borderColor:'#22C55E', fill:true, backgroundColor:'rgba(34,197,94,0.12)' },
      { label:'Denied', data: denied, borderColor:'#EF4444', fill:true, backgroundColor:'rgba(239,68,68,0.12)' }
    ]},
    options: { responsive:true }
  });

  // top categories
  const ctxCat = document.getElementById('topCategoriesChart').getContext('2d');
  window.submissionCharts.cat = new Chart(ctxCat, {
    type:'bar',
    data:{
      labels: (dashboardData.topCategories||[]).map(c=>c.category),
      datasets:[{ label:'Submissions', data:(dashboardData.topCategories||[]).map(c=>c.cnt), backgroundColor:'#3B82F6' }]
    },
    options:{ indexAxis:'y', responsive:true }
  });
}

/* ===========================
   Users Tab
   =========================== */
function initUsersTab(){
  window.usersCharts = {};

  // role distribution
  const roleCtx = document.getElementById('roleDistributionChart').getContext('2d');
  window.usersCharts.role = new Chart(roleCtx, {
    type:'doughnut',
    data: {
      labels: Object.keys(dashboardData.roleDistribution || {}),
      datasets: [{ data: Object.values(dashboardData.roleDistribution || {}), backgroundColor:['#4F46E5','#22C55E','#F59E0B','#EF4444'] }]
    },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
  });

  // login trend
  const loginCtx = document.getElementById('loginTrendChart').getContext('2d');
  window.usersCharts.login = new Chart(loginCtx, {
    type:'line',
    data:{ labels:(dashboardData.loginTrend||[]).map(r=>r.date), datasets:[{ label:'Logins', data:(dashboardData.loginTrend||[]).map(r=>r.count), borderColor:'#4F46E5', fill:false }]},
    options:{ responsive:true }
  });

  // submission activity
  const subCtx = document.getElementById('submissionActivityChart').getContext('2d');
  window.usersCharts.sub = new Chart(subCtx, {
    type:'bar',
    data:{ labels:(dashboardData.submissionActivity||[]).map(r=>r.name), datasets:[{ label:'Submissions', data:(dashboardData.submissionActivity||[]).map(r=>r.submissions), backgroundColor:'#22C55E' }]},
    options:{ responsive:true }
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
    options:{ responsive:true }
  });

  // user table
  const tbody = document.getElementById('userTableBody');
  tbody.innerHTML = '';
  (dashboardData.userDetails || []).forEach(u => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="px-4 py-2">${u.id}</td>
      <td class="px-4 py-2">${u.name}</td>
      <td class="px-4 py-2">${u.email}</td>
      <td class="px-4 py-2">${u.role}</td>
      <td class="px-4 py-2">${u.team ?? ''}</td>
      <td class="px-4 py-2">${u.submissions ?? 0}</td>
      <td class="px-4 py-2">${u.earned ?? 0}</td>
      <td class="px-4 py-2">${u.burned ?? 0}</td>
      <td class="px-4 py-2">${u.lastLogin ?? ''}</td>
    `;
    tbody.appendChild(tr);
  });
}

/* ===========================
   Reward Tab
   =========================== */
function initRewardTab(){
  window.rewardCharts = {};

  document.getElementById('totalRewardsRedeemed').innerText = dashboardData.totalRedeemed ?? 0;
  document.getElementById('totalRewardsStock').innerText = dashboardData.totalStock ?? 0;
  document.getElementById('lowStockCount').innerText = (dashboardData.lowStockRewards||[]).length;

  // populate top redeemers
  const topTable = document.getElementById('topRedeemersTable');
  topTable.innerHTML = '';
  (dashboardData.topRedeemers || []).forEach(r => {
    topTable.innerHTML += `<tr><td class="px-4 py-2 border">${r.firstName}</td><td class="px-4 py-2 border">${r.cnt}</td></tr>`;
  });

  // low stock table
  const lowTable = document.getElementById('lowStockTable');
  lowTable.innerHTML = '';
  (dashboardData.lowStockRewards || []).forEach(r => {
    lowTable.innerHTML += `<tr><td class="px-4 py-2 border">${r.rewardName}</td><td class="px-4 py-2 border text-red-500 font-bold">${r.stockQuantity}</td></tr>`;
  });

  // redeemed by type chart
  const doughCtx = document.getElementById('redeemedByTypeChart').getContext('2d');
  window.rewardCharts.type = new Chart(doughCtx, {
    type:'doughnut',
    data:{
      labels:(dashboardData.rewards||[]).map(r=>r.rewardName),
      datasets:[{ data:(dashboardData.rewards||[]).map(r=>r.redeemedQuantity||0) }]
    },
    options:{ responsive:true }
  });

  // redemptions trend (placeholder: reuse submissionTrend.approved)
  const trendCtx = document.getElementById('redemptionsTrendChart').getContext('2d');
  const labels = (dashboardData.submissionTrend || []).map(d=>d.day);
  const redData = (dashboardData.submissionTrend || []).map(d=>d.approved || 0);
  window.rewardCharts.trend = new Chart(trendCtx, {
    type:'line',
    data:{ labels, datasets:[{ label:'Redemptions', data:redData, borderColor:'#16A34A', fill:true }]},
    options:{ responsive:true }
  });
}
</script>

</body>
</html>
