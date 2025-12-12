<?php
require_once "db_connect.php";

// ======================
// 1. Overall Counts
// ======================
$totalSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub")->fetch_assoc()['c'];
$pendingSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='pending'")->fetch_assoc()['c'];
$approvedSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='approved'")->fetch_assoc()['c'];
$deniedSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='denied'")->fetch_assoc()['c'];

// ======================
// 2. Pending Table
// ======================
$pendingTableSQL = "
    SELECT sub.submissionID, sub.uploaded_at, user.firstName, user.lastName, challenge.challengeTitle
    FROM sub 
    JOIN user ON sub.userID = user.userID
    JOIN challenge ON sub.challengeID = challenge.challengeID
    WHERE sub.status='pending'
    ORDER BY sub.uploaded_at DESC
    LIMIT 10
";
$pendingRows = $conn->query($pendingTableSQL);

// ======================
// 3. Recent Reviews
// ======================
$recentSQL = "
    SELECT challenge.challengeTitle, sub.status, sub.approved_at, sub.denied_at
    FROM sub
    JOIN challenge ON sub.challengeID = challenge.challengeID
    WHERE sub.status IN ('approved','denied')
    ORDER BY sub.approved_at DESC, sub.denied_at DESC
    LIMIT 10
";
$recentRows = $conn->query($recentSQL);

// ======================
// 4. Approval Rate Donut
// ======================
$approveCount = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='approved'")->fetch_assoc()['c'];
$rejectCount = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='denied'")->fetch_assoc()['c'];

// ======================
// 5. Challenge Type Count
// ======================
$challengeTypeSQL = "
    SELECT category.categoryName, COUNT(*) AS total
    FROM sub
    JOIN challenge ON sub.challengeID = challenge.challengeID
    JOIN category ON challenge.categoryID = category.categoryID
    GROUP BY category.categoryName
    ORDER BY total DESC
";
$challengeType = $conn->query($challengeTypeSQL);

// ======================
// 6. Approval Trend (7 days)
// ======================
$trendSQL = "
    SELECT DATE(uploaded_at) AS d,
        SUM(status='approved') AS approved,
        SUM(status='denied') AS denied
    FROM sub
    WHERE uploaded_at >= DATE(NOW() - INTERVAL 7 DAY)
    GROUP BY DATE(uploaded_at)
    ORDER BY d
";
$trendData = $conn->query($trendSQL);

// ======================
// 7. Daily Review Trend
// ======================
$dailySQL = "
    SELECT DATE(uploaded_at) AS d, COUNT(*) AS total
    FROM sub
    WHERE uploaded_at >= DATE(NOW() - INTERVAL 7 DAY)
    GROUP BY DATE(uploaded_at)
    ORDER BY d
";
$dailyData = $conn->query($dailySQL);

// ======================
// 8. Participant stats
// ======================
$totalUsers = $conn->query("SELECT COUNT(*) AS c FROM user")->fetch_assoc()['c'];
$activeUsers = $conn->query("SELECT COUNT(*) AS c FROM user WHERE last_online >= NOW() - INTERVAL 7 DAY")->fetch_assoc()['c'];

$topUsersSQL = "
    SELECT user.firstName, user.scorePoint
    FROM user
    ORDER BY scorePoint DESC
    LIMIT 5
";
$topUsers = $conn->query($topUsersSQL);
?>




<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Moderator Dashboard</title>
    <script src="https://res.gemcoder.com/js/reload.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link
      href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css"
      rel="stylesheet"
    />
    <script src="https://cdn.bootcdn.net/ajax/libs/echarts/5.4.3/echarts.min.js"></script>
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
                Moderator Dashboard
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
              <button
                class="bg-white border border-light-2 rounded-lg py-2 px-4 text-sm font-medium flex items-center gap-2 hover:bg-gray-50 transition-colors"
              >
                <i class="fas fa-download text-dark-2"> </i>
                <span> Print Report </span>
              </button>
              <button
                class="bg-primary text-white rounded-lg py-2 px-4 text-sm font-medium flex items-center gap-2 hover:bg-primary/90 transition-colors shadow-sm"
              >
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
                  <p class="stat-card-label"> Total Submission </p>
                  <h3 class="stat-card-value mt-1"><?= $totalSubmission ?></h3>

               
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
                  <p class="stat-card-label">Pending Submission</p>
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
                  <p class="stat-card-label"> Approved Submission </p>
                  <h3 class="stat-card-value mt-1">2,156</h3>
                </div>

                <div class="w-12 h-12 rounded-lg bg-info/10 flex items-center justify-center text-info">
                  <i class="fas fa-chart-line text-xl"> </i>
                </div>
              </div>
            </div>
            <!-- 系统状态卡片 -->
            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">Denied Submission</p>
                  <h3 class="stat-card-value mt-1">156</h3>
                </div>
                <div class="w-12 h-12 rounded-lg bg-success/10 flex items-center justify-center text-success">
                  <i class="fas fa-server text-xl"> </i>
                </div>
              </div>
              
            </div>
          </div>

            <!-- Tabs 容器 -->
            <div class="bg-white rounded-2xl shadow-lg p-6">

                <!-- Tab Buttons -->
                <div class="flex border-b border-light-2 mb-6 space-x-6">

                    <button class="py-3 px-4 text-sm font-semibold text-primary border-b-2 border-primary transition-all" onclick="showTab('charts')" id="tab-charts"> Overview </button>
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('user')" id="tab-user"> Approval Trend</button>
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('tables')" id="tab-tables"> Submission Trend </button>
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('reward')" id="tab-reward"> Participant </button>

                </div>


            <!-- Tab 内容 -->
            <div id="charts" class="tab-content">
                <div class="flex flex-col lg:flex-row gap-6">
                <!-- 左边环形图 -->
                <div class="bg-white rounded-2xl shadow-lg p-6 flex-1 flex flex-col items-center">
                    <h4 class="text-lg font-semibold text-dark mb-4">总审批率 (Approved vs Rejected)</h4>
                    <div id="approvalRateChart" class="w-full h-80"></div>
                </div>

                <!-- 右边挑战类型展示 -->
                <div class="bg-white rounded-2xl shadow-lg p-6 flex-1 flex flex-col">
                    <h4 class="text-lg font-semibold text-dark mb-4">提交最多的挑战类别</h4>
                    <div id="challengeTypeChart" class="w-full h-80"></div>
                </div>
                </div>

                <!-- =======================
                  BOTTOM: TABLES
                ========================= -->

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Pending Submissions TABLE -->
                    <div class="bg-white rounded-xl shadow p-6">
                        <h3 class="font-semibold mb-3 text-gray-700">Pending Submissions</h3>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="p-3 text-left">User</th>
                                        <th class="p-3 text-left">Challenge</th>
                                        <th class="p-3 text-left">Submitted At</th>
                                        <th class="p-3 text-left">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="pending-table-body"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Recent Reviews TABLE -->
                    <div class="bg-white rounded-xl shadow p-6">
                        <h3 class="font-semibold mb-3 text-gray-700">Recent Reviews</h3>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="p-3 text-left">Challenge</th>
                                        <th class="p-3 text-left">Result</th>
                                        <th class="p-3 text-left">Time</th>
                                    </tr>
                                </thead>
                                <tbody id="recent-table-body"></tbody>
                            </table>
                        </div>
                    </div>

                </div>


            </div>

          
              <!-- 最近活动日志 -->
              <div id="tables" class="tab-content hidden">
                <div class="bg-white rounded-2xl shadow-lg p-6 flex-1">
                    <h4 class="text-lg font-semibold text-dark mb-4">Daily Review Trend</h4>
                    <div id="dailyReviewChart" class="w-full h-64"></div>
                </div>
            </div>

      

            
        <div id="user" class="tab-content hidden">
            <!-- 折线图趋势 -->
            <div class="bg-white rounded-2xl shadow-lg p-6 flex-1">
            <h4 class="text-lg font-semibold text-dark mb-4">最近7天审批趋势</h4>
            <div id="approvalTrendChart" class="w-full h-64"></div>
            </div>

        </div>


        <div id="reward" class="tab-content hidden">
            <!-- Participant Tab Content -->
              <div class="space-y-6">

                <!-- 顶部紧凑统计条 -->
                <div class="flex flex-wrap gap-4">
                  <!-- Total Users -->
                  <div class="flex-1 min-w-[120px] bg-gray-100 rounded-lg px-4 py-2 flex flex-col items-center justify-center">
                    <p class="text-xs text-gray-500 font-medium">Total Users</p>
                    <h3 class="text-lg font-bold text-dark">1,245</h3>
                  </div>

                  <!-- Active Users -->
                  <div class="flex-1 min-w-[120px] bg-green-50 rounded-lg px-4 py-2 flex flex-col items-center justify-center">
                    <p class="text-xs text-gray-500 font-medium">Active Users</p>
                    <h3 class="text-lg font-bold text-success">324</h3>
                  </div>

                  <!-- Top 5 Users -->
                  <div class="flex-1 min-w-[120px] bg-yellow-50 rounded-lg px-4 py-2 flex flex-col items-center justify-center">
                    <p class="text-xs text-gray-500 font-medium">Top 5 Users</p>
                    <h3 class="text-lg font-bold text-warning">See List Below</h3>
                  </div>
                </div>

                <!-- 下方排行榜列表 -->
                <div class="bg-white rounded-2xl shadow-lg p-4">
                  <h4 class="text-md font-semibold text-dark mb-4">Top Participants</h4>
                  <div class="divide-y divide-gray-200">
                    <!-- Row Example -->
                    <div class="flex items-center justify-between py-2">
                      <div class="flex items-center gap-3">
                        <span class="font-medium text-sm w-5">1</span>
                        <span class="text-sm font-medium">Alice</span>
                      </div>
                      <div class="flex items-center gap-2 w-1/2">
                        <div class="h-2 w-full rounded-full bg-gradient-to-r from-blue-400 to-blue-600" style="width: 80%;"></div>
                        <span class="text-sm text-gray-500">120</span>
                      </div>
                    </div>


                    <div class="flex items-center justify-between py-2">
                      <div class="flex items-center gap-3">
                        <span class="font-medium text-sm w-5">2</span>
                        <span class="text-sm font-medium">Bob</span>
                      </div>
                      <div class="flex items-center gap-2">
                        <div class="bg-primary/70 h-2 w-20 rounded-full"></div>
                        <span class="text-sm text-gray-500">100</span>
                      </div>
                    </div>

                    <div class="flex items-center justify-between py-2">
                      <div class="flex items-center gap-3">
                        <span class="font-medium text-sm w-5">3</span>
                        <span class="text-sm font-medium">Charlie</span>
                      </div>
                      <div class="flex items-center gap-2">
                        <div class="bg-primary/50 h-2 w-16 rounded-full"></div>
                        <span class="text-sm text-gray-500">80</span>
                      </div>
                    </div>
                  </div>
                </div>

              </div>

        
      </main>
    </div>


<script>
document.addEventListener('DOMContentLoaded', function () {

    // ---------------------------
    // 数据初始化
    // ---------------------------
    const pendingReviews = [
      { id: 1, user: "Alice", submission: "Challenge A", date: "2025-12-06" },
      { id: 2, user: "Bob", submission: "Challenge B", date: "2025-12-05" },
    ];

    const recentReviews = [
      { id: 101, user: "Charlie", submission: "Challenge X", status: "Approved", date: "2025-12-04" },
      { id: 102, user: "Dana", submission: "Challenge Y", status: "Rejected", date: "2025-12-03" },
    ];

    // ---------------------------
    // 初始化图表
    // ---------------------------

    // 总审批率 (Approved vs Rejected) - Donut
    const approvalRateChart = echarts.init(document.getElementById('approvalRateChart'));
    approvalRateChart.setOption({
        tooltip: {
            trigger: 'item',
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#E5E6EB',
            borderWidth: 1,
            textStyle: { color: '#1D2129' },
            padding: 10,
            formatter: '{b}: {c} ({d}%)',
            extraCssText: 'box-shadow:0 4px 12px rgba(0,0,0,0.08); border-radius:8px;'
        },
        legend: { show: false },
        series: [{
            name: '审批率',
            type: 'pie',
            radius: ['70%', '90%'],
            avoidLabelOverlap: false,
            itemStyle: { borderRadius: 10, borderColor: '#fff', borderWidth: 3 },
            label: { show: true, position: 'center', formatter: '审批率', fontSize: 14, color: '#1D2129' },
            emphasis: {
                scale: true,
                scaleSize: 8,
                label: { show: true, formatter: '{d}%', fontSize: 18, fontWeight: 'bold', color: '#1D2129' }
            },
            labelLine: { show: false },
            data: [
                { value: 68, name: '学生', itemStyle: { color: '#165DFF' } },
                { value: 22, name: '教师', itemStyle: { color: '#36CBCB' } },
                { value: 5, name: '管理员', itemStyle: { color: '#1890FF' } },
                { value: 5, name: '其他', itemStyle: { color: '#FAAD14' } }
            ]
        }]
    });

    // 提交最多的挑战类别 - 柱状图
    const challengeTypeChart = echarts.init(document.getElementById('challengeTypeChart'));
    challengeTypeChart.setOption({
      tooltip: { trigger: 'axis' },
      legend: { show: false },
      xAxis: {
        type: 'category',
        data: ['环保', '步行', '低碳出行', '垃圾分类', '节能'],
        axisLine: { lineStyle: { color: '#E5E6EB' } }
      },
      yAxis: { type: 'value', axisLine: { show: false }, splitLine: { lineStyle: { color: '#F2F3F5' } } },
      series: [{
        name: '挑战提交数量',
        type: 'bar',
        data: [120, 90, 80, 60, 40],
        itemStyle: {
          color: function(params) {
            const colorList = ['#165DFF', '#36CBCB', '#52C41A', '#FAAD14', '#FF4D4F'];
            return colorList[params.dataIndex];
          }
        },
        barWidth: 40
      }]
    });

    // Daily Review Trend - 柱状图
    const dailyReviewChart = echarts.init(document.getElementById('dailyReviewChart'));
    const dates = [], dailyTotal = [];
    const today = new Date();
    for (let i = 6; i >= 0; i--) {
        const date = new Date(today);
        date.setDate(today.getDate() - i);
        dates.push(`${date.getMonth() + 1}/${date.getDate()}`);
        dailyTotal.push(Math.floor(Math.random() * 50) + 20);
    }
    dailyReviewChart.setOption({
        tooltip: { trigger: 'axis' },
        grid: { left: 10, right: 10, top: 20, bottom: 10, containLabel: true },
        xAxis: { type: 'category', data: dates, axisLine: { show: true }, axisTick: { show: true }, axisLabel: { color: '#4E5969', fontSize: 12 } },
        yAxis: { show: true },
        series: [{
            name: 'Total Submissions',
            type: 'bar',
            data: dailyTotal,
            barWidth: 26,
            itemStyle: {
                borderRadius: [12, 12, 12, 12],
                color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: '#4A8CFF' },
                    { offset: 1, color: '#165DFF' }
                ])
            },
            emphasis: { itemStyle: { opacity: 0.9 } }
        }]
    });

    // Approval Trend - 折线图
    const approvalTrendChart = echarts.init(document.getElementById('approvalTrendChart'));
    const dates2 = [], approved = [], rejected = [];
    for (let i = 6; i >= 0; i--) {
        const date = new Date(today);
        date.setDate(today.getDate() - i);
        dates2.push(`${date.getMonth() + 1}/${date.getDate()}`);
        approved.push(Math.floor(Math.random() * 20) + 5);
        rejected.push(Math.floor(Math.random() * 5));
    }
    approvalTrendChart.setOption({
        tooltip: { trigger: 'axis' },
        legend: { data: ['Approved', 'Rejected'] },
        grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
        xAxis: { type: 'category', boundaryGap: false, data: dates2 },
        yAxis: { type: 'value' },
        series: [
            { name: 'Approved', type: 'line', data: approved, smooth: true, lineStyle: { color: '#52C41A', width: 2 }, areaStyle: { color: 'rgba(82,196,26,0.2)' } },
            { name: 'Rejected', type: 'line', data: rejected, smooth: true, lineStyle: { color: '#FF4D4F', width: 2 }, areaStyle: { color: 'rgba(255,77,79,0.2)' } }
        ]
    });

    // ---------------------------
    // 渲染表格
    // ---------------------------
    function renderPendingTable() {
        const tbody = document.getElementById("pending-table-body");
        let html = '';
        pendingReviews.forEach(row => {
            html += `
                <tr class="border-b">
                    <td class="p-3">${row.user}</td>
                    <td class="p-3">${row.submission}</td>
                    <td class="p-3">${row.date}</td>
                    <td class="p-3">
                        <button class="px-3 py-1 bg-primary text-white rounded text-xs review-btn" data-id="${row.id}">Review</button>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;

        // 绑定 Review 按钮
        tbody.querySelectorAll('.review-btn').forEach(btn => {
            btn.addEventListener('click', () => handleReview(btn.dataset.id));
        });
    }

    function renderRecentTable() {
        const tbody = document.getElementById("recent-table-body");
        let html = '';
        recentReviews.forEach(row => {
            const color = row.status === "Approved" ? "text-green-600" : "text-red-500";
            html += `
                <tr class="border-b">
                    <td class="p-3">${row.submission}</td>
                    <td class="p-3 ${color} font-semibold">${row.status}</td>
                    <td class="p-3">${row.date}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    // Review 按钮逻辑
    function handleReview(id) {
        const index = pendingReviews.findIndex(r => r.id == id);
        if (index === -1) return;
        const item = pendingReviews.splice(index, 1)[0];
        item.status = "Approved"; // 简单示例，默认 Approve
        recentReviews.unshift({ ...item }); // 添加到 Recent 表格
        renderPendingTable();
        renderRecentTable();
    }

    // 初始渲染表格
    renderPendingTable();
    renderRecentTable();

    // ---------------------------
    // 全局图表对象，用于 resize
    // ---------------------------
    window.dashboardCharts = {
        approvalRateChart,
        challengeTypeChart,
        dailyReviewChart,
        approvalTrendChart
    };
    window.addEventListener('resize', () => {
        Object.values(window.dashboardCharts).forEach(chart => chart.resize());
    });

});

// ---------------------------
// Tab 切换
// ---------------------------
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(tc => tc.classList.add('hidden'));
    document.getElementById(tab).classList.remove('hidden');

    document.querySelectorAll('[id^="tab-"]').forEach(btn => {
        btn.classList.remove('border-b-2', 'border-primary', 'text-primary');
        btn.classList.add('text-dark-2');
    });

    const tabBtn = document.getElementById('tab-' + tab);
    if(tabBtn){
        tabBtn.classList.add('border-b-2', 'border-primary', 'text-primary');
        tabBtn.classList.remove('text-dark-2');
    }

    // 切换 tab 时刷新对应图表
    if (window.dashboardCharts) {
        if (tab === 'charts') {
            window.dashboardCharts.approvalRateChart.resize();
            window.dashboardCharts.challengeTypeChart.resize();
        } else if (tab === 'user') {
            window.dashboardCharts.approvalTrendChart.resize();
        } else if (tab === 'tables') {
            window.dashboardCharts.dailyReviewChart.resize();
        }
    }
}
</script>




  </body>
</html>
