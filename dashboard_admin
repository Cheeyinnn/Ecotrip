<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard</title>
    <script src="https://res.gemcoder.com/js/reload.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css"  rel="stylesheet"/>
    <script src="https://cdn.bootcdn.net/ajax/libs/echarts/5.4.3/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts/dist/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
      
      <!-- [/MODULE] c3d_侧边导航栏 -- 包含仪表盘、用户管理、课程管理等主要导航项 -->
      <!-- [MODULE] e5f_主内容区域 -->
      <main class="flex-1 overflow-y-auto bg-gray-50 p-6 lg:p-10">
         <div id="dashboard-page" class="max-w-7xl mx-auto space-y-10">

          <!-- [MODULE] i9j_仪表盘页面:页面标题 -->
          <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-6">

            <div>
              <h2 class="text-[clamp(1.5rem,3vw,2rem)] font-bold text-dark">
                Admin Dashboard
              </h2>
              <p class="text-dark-2 mt-1">实时监控系统运行状态和关键指标</p>
            </div>

            <div class="flex flex-wrap gap-3">
              <div class="flex items-center space-x-3">
              <input 
                type="date" 
                class="bg-white border border-light-2 rounded-lg py-2 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                id="startDate"
              >

              <span class="text-dark-2 text-sm"> to </span>

              <input 
                type="date" 
                class="bg-white border border-light-2 rounded-lg py-2 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                id="endDate"
              >
            </div>

              <button class="bg-white border border-light-2 rounded-lg py-2 px-4 text-sm font-medium flex items-center gap-2 hover:bg-gray-50 transition-colors">
                <i class="fas fa-download text-dark-2"> </i>
                <span> Print Report </span>
              </button>
              <button class="bg-primary text-white rounded-lg py-2 px-4 text-sm font-medium flex items-center gap-2 hover:bg-primary/90 transition-colors shadow-sm">
                <i class="fas fa-refresh"> </i>
                <span> Refresh </span>
              </button>
            </div>
          </div>
          
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">

            <!-- 用户总数卡片 -->
            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">

              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">Total User</p>
                  <h3 class="stat-card-value mt-1">12,845</h3>
               
                </div>
                <div class="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                  <i class="fas fa-users text-xl"> </i>
                </div>
              </div>
              
            </div>
            <!-- 课程总数卡片 -->
            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">New User (week) </p>
                  <h3 class="stat-card-value mt-1">342</h3>
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
                  <h3 class="stat-card-value mt-1">2,156</h3>
                  
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
                  <h3 class="stat-card-value mt-1">156</h3>
                  
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

              <!-- 用户详情表格 -->
              <div class="bg-white rounded-xl shadow-card p-5">
                <h3 class="text-lg font-bold text-dark mb-4">User Details</h3>
                <div class="overflow-x-auto">
                  <table class="min-w-full text-sm text-left">
                    <thead class="bg-gray-50">
                      <tr>
                        <th class="px-4 py-2">UserID</th>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Email</th>
                        <th class="px-4 py-2">Role</th>
                        <th class="px-4 py-2">Team</th>
                        <th class="px-4 py-2">Submissions</th>
                        <th class="px-4 py-2">Points Earned</th>
                        <th class="px-4 py-2">Points Burned</th>
                        <th class="px-4 py-2">Last Login</th>
                      </tr>
                    </thead>
                    <tbody id="userTableBody"></tbody>
                  </table>
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

              <!-- 底部表格 -->
              <div class="bg-white shadow-card rounded-xl p-5 mb-6">
                  <h3 class="text-lg font-bold text-dark mb-4">Top Redeemers</h3>
                  <table class="w-full text-sm text-left border">
                      <thead class="bg-gray-100">
                          <tr>
                              <th class="px-4 py-2 border">User</th>
                              <th class="px-4 py-2 border">Redeemed Count</th>
                          </tr>
                      </thead>
                      <tbody id="topRedeemersTable"></tbody>
                  </table>
              </div>

              <div class="bg-white shadow-card rounded-xl p-5 mb-6">
                  <h3 class="text-lg font-bold text-dark mb-4">Low Stock Rewards</h3>
                  <table class="w-full text-sm text-left border">
                      <thead class="bg-gray-100">
                          <tr>
                              <th class="px-4 py-2 border">Reward</th>
                              <th class="px-4 py-2 border">Stock</th>
                          </tr>
                      </thead>
                      <tbody id="lowStockTable"></tbody>
                  </table>
              </div>
          </div>


     </div>

      </main>
    </div>
   

        
<script>
document.addEventListener('DOMContentLoaded', function () {

  // -----------------------------
  // Tab 切换函数
  // -----------------------------
  function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(tc => tc.classList.add('hidden'));
    document.getElementById(tab).classList.remove('hidden');

    document.querySelectorAll('[id^="tab-"]').forEach(btn => {
      btn.classList.remove('border-b-2', 'border-primary', 'text-primary');
      btn.classList.add('text-dark-2');
    });

    const activeBtn = document.getElementById('tab-' + tab);
    activeBtn.classList.add('border-b-2', 'border-primary', 'text-primary');
    activeBtn.classList.remove('text-dark-2');

    // 延迟触发图表 resize 保证自适应
    if (tab === 'charts' && window.dashboardCharts?.growthChart) {
      setTimeout(() => window.dashboardCharts.growthChart.resize(), 50);
    }
    if (tab === 'user') {
      setTimeout(() => {
        Object.values(window.usersCharts || {}).forEach(chart => chart.resize());
      }, 50);
    }
    if (tab === 'tables') {
      setTimeout(() => {
        Object.values(window.submissionCharts || {}).forEach(chart => chart.resize());
      }, 50);
    }
    if (tab === 'reward') {
      setTimeout(() => {
        Object.values(window.rewardCharts || {}).forEach(chart => chart.resize());
      }, 50);
    }
  }
  window.showTab = showTab;

  // -----------------------------
  // Growth Tab - ECharts
  // -----------------------------
  function initGrowthChart() {
    const el = document.getElementById('growthChart');
    el.style.width = '100%';
    el.style.height = '400px';
    const chart = echarts.init(el);

    const today = new Date();
    const dates = [];
    for (let i = 6; i >= 0; i--) {
      const d = new Date(today);
      d.setDate(today.getDate() - i);
      dates.push((d.getMonth() + 1) + '/' + d.getDate());
    }

    const newUsers = [5, 8, 6, 12, 9, 15, 10];
    const newTeams = [1, 2, 0, 3, 2, 3, 1];
    const submissions = [10, 12, 8, 15, 14, 20, 18];

    const option = {
      tooltip: { trigger: 'axis', backgroundColor: '#fff', borderColor: '#ccc', borderWidth: 1, padding: 10, textStyle: { color: '#000' } },
      legend: { data: ['New Users', 'New Teams', 'Submissions'] },
      grid: { left: '5%', right: '5%', bottom: '10%', top: '10%', containLabel: true },
      xAxis: { type: 'category', data: dates, axisLine: { lineStyle: { color: '#ccc' } } },
      yAxis: { type: 'value', axisLine: { show: false }, splitLine: { lineStyle: { color: '#f0f0f0' } } },
      series: [
        { name: 'New Users', type: 'line', smooth: true, data: newUsers, lineStyle: { color: '#165DFF', width: 2 }, areaStyle: { color: 'rgba(22,93,255,0.2)' } },
        { name: 'New Teams', type: 'line', smooth: true, data: newTeams, lineStyle: { color: '#FFB400', width: 2 }, areaStyle: { color: 'rgba(255,180,0,0.2)' } },
        { name: 'Submissions', type: 'line', smooth: true, data: submissions, lineStyle: { color: '#36CBCB', width: 2 }, areaStyle: { color: 'rgba(54,203,203,0.2)' } }
      ]
    };

    chart.setOption(option);
    window.dashboardCharts = { growthChart: chart };
    window.addEventListener('resize', () => chart.resize());
  }
  initGrowthChart();

  // -----------------------------
  // Users Tab - Chart.js & Table
  // -----------------------------
  function initUsersCharts() {
    if (window.usersCharts) return;

    const dashboardData = {
      roleDistribution: { Student: 800, Teacher: 300, Moderator: 134 },
      loginTrend: [
        { date: "2025-11-30", count: 50 },
        { date: "2025-12-01", count: 70 },
        { date: "2025-12-02", count: 65 },
        { date: "2025-12-03", count: 80 },
        { date: "2025-12-04", count: 90 },
        { date: "2025-12-05", count: 100 },
        { date: "2025-12-06", count: 120 }
      ],
      submissionActivity: [
        { name: "User A", submissions: 15 },
        { name: "User B", submissions: 12 },
        { name: "User C", submissions: 10 }
      ],
      pointsActivity: [
        { name: "User A", earned: 120, burned: 80 },
        { name: "User B", earned: 100, burned: 50 },
        { name: "User C", earned: 80, burned: 60 }
      ],
      userDetails: [
        { id: 1, name: "User A", email: "a@mail.com", role: "Student", team: "Team 1", submissions: 15, earned: 120, burned: 80, lastLogin: "2025-12-06" },
        { id: 2, name: "User B", email: "b@mail.com", role: "Teacher", team: "Team 1", submissions: 12, earned: 100, burned: 50, lastLogin: "2025-12-06" },
        { id: 3, name: "User C", email: "c@mail.com", role: "Student", team: "Team 2", submissions: 10, earned: 80, burned: 60, lastLogin: "2025-12-05" }
      ]
    };

    window.usersCharts = {};

    // Role 分布 Donut
    const roleCtx = document.getElementById("roleDistributionChart").getContext("2d");
    window.usersCharts.role = new Chart(roleCtx, {
      type: "doughnut",
      data: { labels: Object.keys(dashboardData.roleDistribution), datasets: [{ data: Object.values(dashboardData.roleDistribution), backgroundColor: ["#4F46E5","#22C55E","#F59E0B"] }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom" } } }
    });

    // Login Trend
    const loginCtx = document.getElementById("loginTrendChart").getContext("2d");
    window.usersCharts.login = new Chart(loginCtx, {
      type: "line",
      data: { labels: dashboardData.loginTrend.map(d => d.date), datasets: [{ label: "Logins", data: dashboardData.loginTrend.map(d => d.count), borderColor: "#4F46E5", fill: false, tension: 0.3 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });

    // Submission Activity
    const submissionCtx = document.getElementById("submissionActivityChart").getContext("2d");
    window.usersCharts.submission = new Chart(submissionCtx, {
      type: "bar",
      data: { labels: dashboardData.submissionActivity.map(u => u.name), datasets: [{ label: "Submissions", data: dashboardData.submissionActivity.map(u => u.submissions), backgroundColor: "#22C55E" }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });

    // Points Earned vs Burned
    const pointsCtx = document.getElementById("pointsActivityChart").getContext("2d");
    window.usersCharts.points = new Chart(pointsCtx, {
      type: "bar",
      data: { labels: dashboardData.pointsActivity.map(u => u.name), datasets: [
        { label: "Earned", data: dashboardData.pointsActivity.map(u => u.earned), backgroundColor: "#4F46E5" },
        { label: "Burned", data: dashboardData.pointsActivity.map(u => u.burned), backgroundColor: "#F59E0B" }
      ] },
      options: { responsive: true, maintainAspectRatio: false }
    });

    // 用户详情表格
    const tbody = document.getElementById("userTableBody");
    dashboardData.userDetails.forEach(user => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td class="px-4 py-2">${user.id}</td>
        <td class="px-4 py-2">${user.name}</td>
        <td class="px-4 py-2">${user.email}</td>
        <td class="px-4 py-2">${user.role}</td>
        <td class="px-4 py-2">${user.team}</td>
        <td class="px-4 py-2">${user.submissions}</td>
        <td class="px-4 py-2">${user.earned}</td>
        <td class="px-4 py-2">${user.burned}</td>
        <td class="px-4 py-2">${user.lastLogin}</td>
      `;
      tbody.appendChild(tr);
    });
  }
  initUsersCharts();

  // -----------------------------
  // Submission Tab - Chart.js & Table
  // -----------------------------
  function initSubmissionCharts() {
    if (window.submissionCharts) return;

    const submissionData = {
      total: 120,
      pending: 15,
      approved: 90,
      denied: 15,
      trend: {
        dates: ['2025-12-01','2025-12-02','2025-12-03','2025-12-04','2025-12-05','2025-12-06','2025-12-07'],
        pending: [2,1,3,2,4,1,2],
        approved: [10,12,15,14,13,16,10],
        denied: [1,0,2,1,1,0,0]
      },
      topCategories: {
        categories: ['Eco Trip', 'Waste Reduction', 'Public Transport', 'Water Saving'],
        counts: [25, 20, 30, 15]
      },
      recent: [
        {user:'Alice', team:'Team A', challenge:'Eco Trip', status:'Approved', submitted_at:'2025-12-06 14:23'},
        {user:'Bob', team:'Team B', challenge:'Waste Reduction', status:'Pending', submitted_at:'2025-12-06 13:50'},
        {user:'Charlie', team:'Team C', challenge:'Public Transport', status:'Denied', submitted_at:'2025-12-05 18:10'}
      ]
    };

    // 填充 KPI
    document.getElementById('totalSubmissions').innerText = submissionData.total;
    document.getElementById('pendingSubmissions').innerText = submissionData.pending;
    document.getElementById('approvedSubmissions').innerText = submissionData.approved;
    document.getElementById('deniedSubmissions').innerText = submissionData.denied;

    // 填充表格
    const tbody = document.getElementById('recentSubmissionsBody');
    submissionData.recent.forEach(item => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="px-4 py-2">${item.user}</td>
        <td class="px-4 py-2">${item.team}</td>
        <td class="px-4 py-2">${item.challenge}</td>
        <td class="px-4 py-2">${item.status}</td>
        <td class="px-4 py-2">${item.submitted_at}</td>
      `;
      tbody.appendChild(tr);
    });

    // Submission Trend Chart
    const ctxTrend = document.getElementById('submissionTrendChart').getContext('2d');
    const trendChart = new Chart(ctxTrend, {
      type: 'line',
      data: {
        labels: submissionData.trend.dates,
        datasets: [
          { label:'Pending', data: submissionData.trend.pending, borderColor:'#FBBF24', backgroundColor:'rgba(251,191,36,0.2)', fill:true },
          { label:'Approved', data: submissionData.trend.approved, borderColor:'#22C55E', backgroundColor:'rgba(34,197,94,0.2)', fill:true },
          { label:'Denied', data: submissionData.trend.denied, borderColor:'#EF4444', backgroundColor:'rgba(239,68,68,0.2)', fill:true }
        ]
      },
      options: { responsive:true, plugins:{legend:{position:'top'}} }
    });

    // Top Categories Chart
    const ctxTop = document.getElementById('topCategoriesChart').getContext('2d');
    const topChart = new Chart(ctxTop, {
      type: 'bar',
      data: {
        labels: submissionData.topCategories.categories,
        datasets: [{ label: 'Submissions', data: submissionData.topCategories.counts, backgroundColor:'#3B82F6' }]
      },
      options: { indexAxis:'y', responsive:true }
    });

    window.submissionCharts = { trendChart, topChart };
    window.addEventListener('resize', () => {
      trendChart.resize();
      topChart.resize();
    });
  }
  initSubmissionCharts();

  // -----------------------------
  // Reward Tab - Chart.js & Table
  // -----------------------------
  function initRewardCharts() {
    if (window.rewardCharts) return;

    const rewardData = {
      totalRedeemed: 125,
      totalStock: 320,
      lowStock: [
        { name: "Eco Bag", stock: 3 },
        { name: "Reusable Bottle", stock: 2 }
      ],
      redeemedByType: {
        labels: ["Eco Bag", "Reusable Bottle", "Voucher", "Sticker Pack"],
        data: [40, 30, 35, 20]
      },
      redemptionsTrend: {
        labels: ["2025-11-28","2025-11-29","2025-11-30","2025-12-01","2025-12-02","2025-12-03","2025-12-04"],
        data: [5, 10, 15, 20, 25, 30, 20]
      },
      topRedeemers: [
        { user: "Alice", count: 12 },
        { user: "Bob", count: 10 },
        { user: "Charlie", count: 8 }
      ]
    };

    document.getElementById('totalRewardsRedeemed').textContent = rewardData.totalRedeemed;
    document.getElementById('totalRewardsStock').textContent = rewardData.totalStock;
    document.getElementById('lowStockCount').textContent = rewardData.lowStock.length;

    const topRedeemersTable = document.getElementById('topRedeemersTable');
    rewardData.topRedeemers.forEach(r => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td class="px-4 py-2 border">${r.user}</td>
                      <td class="px-4 py-2 border">${r.count}</td>`;
      topRedeemersTable.appendChild(tr);
    });

    const lowStockTable = document.getElementById('lowStockTable');
    rewardData.lowStock.forEach(r => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td class="px-4 py-2 border">${r.name}</td>
                      <td class="px-4 py-2 border text-red-500 font-bold">${r.stock}</td>`;
      lowStockTable.appendChild(tr);
    });

    // Redeemed by Type
    const ctx1 = document.getElementById('redeemedByTypeChart').getContext('2d');
    const typeChart = new Chart(ctx1, {
      type: 'doughnut',
      data: { labels: rewardData.redeemedByType.labels, datasets: [{ data: rewardData.redeemedByType.data, backgroundColor: ['#4ade80','#60a5fa','#facc15','#f87171'] }] },
      options: { responsive:true, plugins:{legend:{position:'bottom'}} }
    });

    // Redemptions Trend
    const ctx2 = document.getElementById('redemptionsTrendChart').getContext('2d');
    const trendChart = new Chart(ctx2, {
      type: 'line',
      data: { labels: rewardData.redemptionsTrend.labels, datasets: [{ label:'Redemptions', data: rewardData.redemptionsTrend.data, borderColor:'#4ade80', backgroundColor:'rgba(74,222,128,0.2)', fill:true, tension:0.3 }] },
      options: { responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
    });

    window.rewardCharts = { typeChart, trendChart };
    window.addEventListener('resize', () => {
      typeChart.resize();
      trendChart.resize();
    });
  }
  initRewardCharts();

});
</script>







  </body>
</html>
