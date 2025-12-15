<?php
require_once "db_connect.php";


$timeFilter = $_GET['time'] ?? 'all';

$days = null;
if ($timeFilter === '7') {
    $days = 7;
} elseif ($timeFilter === '30') {
    $days = 30;
}

$timeCondition = '';
if ($days !== null) {
    $timeCondition = " AND uploaded_at >= NOW() - INTERVAL $days DAY";
}



// ======================
// 1. Overall Counts
// ======================
$totalSubmission = $conn->query("
    SELECT COUNT(*) AS c
    FROM sub
    WHERE 1=1 $timeCondition
")->fetch_assoc()['c'];

$pendingSubmission = $conn->query("
    SELECT COUNT(*) AS c
    FROM sub
    WHERE status='pending' $timeCondition
")->fetch_assoc()['c'];

$approvedSubmission = $conn->query("
    SELECT COUNT(*) AS c
    FROM sub
    WHERE status='approved' $timeCondition
")->fetch_assoc()['c'];

$deniedSubmission = $conn->query("
    SELECT COUNT(*) AS c
    FROM sub
    WHERE status='denied' $timeCondition
")->fetch_assoc()['c'];




// ======================
// 4. Approval Rate Donut
// ======================
$approveCount = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='approved'")->fetch_assoc()['c'];
$rejectCount = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='denied'")->fetch_assoc()['c'];

// 5. Challenge Type Count - FIXED

// Challenge Type SQL
$challengeTypeSQL = "
    SELECT category.categoryName, COUNT(*) AS total
    FROM sub
    JOIN challenge ON sub.challengeID = challenge.challengeID
    JOIN category ON challenge.categoryID = category.categoryID
    WHERE 1=1
";

if ($days !== null) {
    $challengeTypeSQL .= " AND sub.uploaded_at >= NOW() - INTERVAL $days DAY";
}

$challengeTypeSQL .= "
    GROUP BY category.categoryName
    ORDER BY total DESC
";

// --- 执行查询 ---
$challengeType = $conn->query($challengeTypeSQL);

// --- 准备数组给 JS ---
$challengeLabels = [];
$challengeValues = [];
while ($c = $challengeType->fetch_assoc()) {
    $challengeLabels[] = $c['categoryName'];
    $challengeValues[] = (int)$c['total'];
}


// 6. Approval Trend - FIXED

$trendSQL = "
    SELECT DATE(uploaded_at) AS d,
           SUM(status='approved') AS approved,
           SUM(status='denied') AS denied
    FROM sub
    WHERE 1=1
";

if ($days !== null) {
    $trendSQL .= " AND uploaded_at >= NOW() - INTERVAL $days DAY";
}

$trendSQL .= "
    GROUP BY DATE(uploaded_at)
    ORDER BY d
";

$trendData = $conn->query($trendSQL);

// Convert to arrays
$trendDates = [];
$trendApproved = [];
$trendDenied = [];

while ($t = $trendData->fetch_assoc()) {
    $trendDates[] = $t['d'];
    $trendApproved[] = (int)$t['approved'];
    $trendDenied[] = (int)$t['denied'];
}


$trendDates = [];
$trendApproved = [];
$trendDenied = [];
mysqli_data_seek($trendData, 0);
while ($t = $trendData->fetch_assoc()) {
    $trendDates[] = $t['d'];
    $trendApproved[] = (int)$t['approved'];
    $trendDenied[] = (int)$t['denied'];
}

// 7. Daily Trend - FIXED
$dailySQL = "
    SELECT DATE(uploaded_at) AS d, COUNT(*) AS total
    FROM sub
    WHERE 1=1 $timeCondition
    GROUP BY DATE(uploaded_at)
    ORDER BY d
";

$dailyData = $conn->query($dailySQL);

// Convert to arrays
$dailyDates = [];
$dailyTotals = [];

while ($d = $dailyData->fetch_assoc()) {
    $dailyDates[] = $d['d'];
    $dailyTotals[] = (int)$d['total'];
}


// ======================
// 8. Participant stats
// ======================
$totalUsers = $conn->query("SELECT COUNT(*) AS c FROM user")->fetch_assoc()['c'];
$activeUsersSQL = "
    SELECT COUNT(*) AS c 
    FROM user
    WHERE 1=1
";

if ($timeFilter !== 'all') {
    $activeUsersSQL .= " AND last_online >= NOW() - INTERVAL $days DAY";
}


$activeUsers = $conn->query($activeUsersSQL)->fetch_assoc()['c'];

$topUsersSQL = "
    SELECT user.firstName, user.scorePoint
    FROM user
    ORDER BY scorePoint DESC
    LIMIT 5
";
$topUsers = $conn->query($topUsersSQL);



include "includes/layout_start.php";

?>




<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Moderator Dashboard</title>

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

      /* 容器样式 */
        .reward-summary-card {
            display: flex; /* 启用 Flexbox 进行横向布局 */
            align-items: center; /* 垂直居中对齐所有项目 */
            padding: 15px 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px; /* 轻微圆角 */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); /* 轻微阴影 */
            background-color: #ffffff;
            margin-bottom: 20px; /* 如果卡片下面还有其他内容 */
        }

        /* 1. 图标容器 */
        .icon-container {
            font-size: 28px;
            color: #FFC107; /* 使用金色或您主题的强调色 */
            margin-right: 20px;
        }
        /* 假设 Font Awesome 的类名 */
        .icon-container .fas {
            /* 增加图标大小，使其更突出 */
            font-size: 32px;
        }

        /* 2. 文本内容 */
        .text-content {
            flex-grow: 1; /* 占据中间所有可用空间 */
            margin-right: 20px;
        }

        .summary-title {
            margin: 0 0 5px 0;
            font-size: 16px;
            color: #1D2129; /* 深色文字 */
            font-weight: bold;
        }

        .summary-description {
            margin: 0;
            font-size: 13px;
            color: #606771; /* 较浅的辅助文字 */
        }

        /* 3. 导航链接/按钮 */
        .action-link {
            flex-shrink: 0; /* 防止按钮被压缩 */
        }

        .nav-button {
            text-decoration: none;
            padding: 8px 12px;
            background-color: #1677FF; /* 主题蓝色 */
            color: white;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
        }

        .nav-button:hover {
            background-color: #0958d9;
        }

        /* 按钮内的箭头图标 */
        .nav-button .fas {
            margin-left: 8px;
            font-size: 12px;
        }

/* ---------------------------------- */
/* 2. 次要快速导航样式 (新增) */
/* ---------------------------------- */
.reward-nav-links {
    /* 使用 Flexbox 或 Grid 进行横向布局 */
    display: flex; 
    gap: 10px; /* 导航项之间的间距 */
    margin-top: 15px; /* 与上方摘要卡片的距离 */
}

.quick-nav-item {
    flex: 1; /* 每个项目平均占据空间 */
    
    /* 样式 */
    display: flex;
    align-items: center;
    justify-content: center; /* 文字居中 */
    padding: 10px 15px;
    
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    color: #4C4C4C; /* 略深的颜色 */
    
    border: 1px solid #D9D9D9;
    border-radius: 4px;
    background-color: #F7F7F7; /* 浅灰色背景 */
    
    transition: all 0.2s ease;
}

.quick-nav-item:hover {
    background-color: #E6F7FF; /* 鼠标悬停变蓝 */
    border-color: #91D5FF;
    color: #1677FF; /* 悬停颜色加深 */
}

/* 导航项内部图标 */
.quick-nav-item .fas {
    margin-right: 8px;
    font-size: 16px;
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
                Moderator Dashboard
              </h2>
              <p class="text-dark-2 mt-1">Viewing the submission from user</p>
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
          
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">

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

            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">Pending Submission</p>
                  <h3 class="stat-card-value mt-1"><?= $pendingSubmission ?></h3>

                </div>
                <div class="w-12 h-12 rounded-lg bg-secondary/10 flex items-center justify-center text-secondary">
                  <i class="fas fa-book text-xl"> </i>
                </div>
              </div>
              
            </div>

           <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label"> Approved Submission </p>
                  <h3 class="stat-card-value mt-1"><?= $approvedSubmission ?></h3>

                </div>

                <div class="w-12 h-12 rounded-lg bg-info/10 flex items-center justify-center text-info">
                  <i class="fas fa-chart-line text-xl"> </i>
                </div>
              </div>
            </div>

            <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">Denied Submission</p>
                  <h3 class="stat-card-value mt-1"><?= $deniedSubmission ?></h3>

                </div>
                <div class="w-12 h-12 rounded-lg bg-success/10 flex items-center justify-center text-success">
                  <i class="fas fa-server text-xl" > </i>
                </div>
              </div>
              
            </div>
          </div>

            <div class="bg-white rounded-2xl shadow-lg p-6">

                <!-- Tab Buttons -->
                <div class="flex border-b border-light-2 mb-6 space-x-6">

                    <button class="py-3 px-4 text-sm font-semibold text-primary border-b-2 border-primary transition-all" onclick="showTab('charts')" id="tab-charts"> Overview </button>
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('user')" id="tab-user"> Approval Trend</button>
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('tables')" id="tab-tables"> Submission Trend </button>
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('reward')" id="tab-reward"> Quick Navigation </button>

                </div>


            <div id="charts" class="tab-content">
                <div class="flex flex-col lg:flex-row gap-6">

                <div class="bg-white rounded-2xl shadow-lg p-6 flex-1 flex flex-col items-center">
                    <h4 class="text-lg font-semibold text-dark mb-4">Reviewed Rate (Approved vs Rejected)</h4>
                    <div id="approvalRateChart" class="w-full h-80"></div>
                </div>

  
                <div class="bg-white rounded-2xl shadow-lg p-6 flex-1 flex flex-col">
                    <h4 class="text-lg font-semibold text-center text-dark mb-4">Most Particaipate Challenge</h4>
                    <div id="challengeTypeChart" class="w-full h-80"></div>
                </div>
                </div>



            </div>

              <div id="tables" class="tab-content hidden">
                <div class="bg-white rounded-2xl shadow-lg p-6 flex-1">
                    <h4 class="text-lg font-semibold text-dark mb-4">Daily Review Trend</h4>
                    <div id="dailyReviewChart" class="w-full h-64"></div>
                </div>
            </div>

      

            
        <div id="user" class="tab-content hidden">

            <div class="bg-white rounded-2xl shadow-lg p-6 flex-1">
            <h4 class="text-lg font-semibold text-dark mb-4">Approval Trend</h4>
            <div id="approvalTrendChart" class="w-full h-64"></div>
            </div>

        </div>


        <div id="reward" class="tab-content">
            <div class="reward-summary-card">
                <div class="icon-container"><i class="fas fa-trophy"></i></div>
                <div class="text-content">
                    <h3 class="summary-title">我的奖励与成就</h3>
                    <p class="summary-description">查看您在本周内解锁的所有徽章、积分和专属福利。</p>
                </div>
                <div class="action-link">
                    <a href="/rewards-page" class="nav-button">查看详情<i class="fas fa-arrow-right"></i></a>
                </div>
            </div>

            <div class="reward-nav-links">
                <a href="/rewards/points" class="quick-nav-item">
                    <i class="fas fa-coins"></i> 积分记录
                </a>
                <a href="/rewards/redeem" class="quick-nav-item">
                    <i class="fas fa-gift"></i> 兑换中心
                </a>
                <a href="/rewards/badges" class="quick-nav-item">
                    <i class="fas fa-medal"></i> 徽章列表
                </a>
            </div>
        </div>
        
      </main>
    </div>


<script>

const approveCount = <?= $approveCount ?>;
const rejectCount = <?= $rejectCount ?>;
const totalCount = approveCount + rejectCount;

const challengeLabels = <?= json_encode($challengeLabels) ?>;
const challengeValues = <?= json_encode($challengeValues) ?>;

// 统一变量名：使用顶部的 trendApproved/trendDenied
const trendDates = <?= json_encode($trendDates) ?>;
const trendApproved = <?= json_encode($trendApproved) ?>;
const trendDenied = <?= json_encode($trendDenied) ?>;

const dailyDates = <?= json_encode($dailyDates) ?>;
const dailyTotals = <?= json_encode($dailyTotals) ?>; 
// 确保这里是 dailyTotals 而非 dailyTotal

document.addEventListener('DOMContentLoaded', function () {

  showTab('charts');

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
        legend: {
            show: true,
            orient: 'horizontal',  // 水平显示
            top: -8,               // 距离容器顶部
            left: 'center',        // 居中
            textStyle: { color: '#1D2129', fontSize: 14 },
        },
        series: [{
            name: 'Rate',
            type: 'pie',
            radius: ['70%', '90%'],
            avoidLabelOverlap: true,
            itemStyle: { borderRadius: 10, borderColor: '#fff', borderWidth: 3 },
            label: { show: true, position: 'center', formatter: 'Rate', fontSize: 14, color: '#1D2129' },
            emphasis: {
                scale: true,
                scaleSize: 8,
                label: { show: true, formatter: '{d}%', fontSize: 18, fontWeight: 'bold', color: '#1D2129' }
            },
            labelLine: { show: false },
           data: [
                { value: <?= $approveCount ?>, name: 'Approved', itemStyle: { color: '#52C41A' } },
                { value: <?= $rejectCount ?>, name: 'Rejected', itemStyle: { color: '#FF4D4F' } }
            ]

        }]
    });

        const challengeTypeChart = echarts.init(document.getElementById('challengeTypeChart'));

      challengeTypeChart.setOption({

          // 工具提示：优化为悬停时显示阴影
          tooltip: { 
              trigger: 'axis',
              axisPointer: { type: 'shadow' }
          },
          // 坐标轴区域优化：增加边距，确保标签不被截断
          grid: {
              left: '3%',
              right: '4%',
              bottom: '3%',
              containLabel: true
          },
          // X 轴：隐藏轴线和刻度，更简洁
          xAxis: {
              type: 'category',
              // 如果数据为空，显示'No Data'
              data: challengeLabels.length ? challengeLabels : ['No Data'],
              axisLine: { show: false }, 
              axisTick: { show: false } 
          },
          // Y 轴：隐藏轴线和刻度，网格线使用虚线
          yAxis: { 
              type: 'value',
              axisLine: { show: false },
              axisTick: { show: false },
              splitLine: { lineStyle: { type: 'dashed', color: '#ccc' } }
          },
          series: [{
              type: 'bar',
              // 如果数据为空，显示 [0]
              data: challengeValues.length ? challengeValues : [0],
              barWidth: '40%', // 优化为更通用的百分比宽度
              // 数据标签：在柱子上方显示具体数值
              label: {
                  show: true,
                  position: 'top',
                  formatter: '{c}',
                  color: '#111',
                  fontWeight: 'bold'
              },
              // 样式：圆角和渐变色
              itemStyle: {
                  borderRadius: [6, 6, 0, 0], // 仅顶部圆角
                  color: new echarts.graphic.LinearGradient(
                      0, 0, 0, 1, 
                      [
                          { offset: 0, color: '#4facfe' }, // 浅蓝
                          { offset: 1, color: '#00f2fe' }  // 青色
                      ]
                  )
              }
          }]
      });


  // ============================
// Daily Review Trend (from DB)
// ============================


const dailyReviewChart = echarts.init(document.getElementById('dailyReviewChart'));

dailyReviewChart.setOption({
    title: {
        text: '每日提交总量统计',
        left: 'center',
        textStyle: { fontSize: 16, fontWeight: 'bold', color: '#1D2129' }
    },
    tooltip: { 
        trigger: 'axis',
        axisPointer: { type: 'shadow' }
    },
    grid: {
        left: '3%', right: '4%', bottom: '3%', containLabel: true
    },
    // *** 关键修改 1: xAxis 变为数值轴 (value) ***
    xAxis: {
        type: 'value',
        minInterval: 1, 
        axisLine: { show: false },
        splitLine: { lineStyle: { type: 'solid', color: '#f0f0f0' } } // 使用实线网格，与虚线区分
    },
    // *** 关键修改 2: yAxis 变为类目轴 (category) ***
    yAxis: { 
        type: 'category',
        data: dailyDates.length ? dailyDates : ['No Data'],
        axisLine: { show: false }, 
        axisTick: { show: false }
    },
    series: [{
        name: '提交总量',
        type: 'bar',
        data: dailyTotals.length ? dailyTotals : [0],
        barWidth: '60%', // 相对宽一点
        // 样式：柔和的圆角和纯色
        itemStyle: {
            borderRadius: 5, // 轻微圆角
            color: '#36CBCB' // 使用您的 Secondary (青色/蓝绿色)
        },
        // 数据标签：在条形图右侧显示数值
        label: {
            show: true,
            // *** 关键修改 3: 标签位置改为 'right' ***
            position: 'right', 
            formatter: '{c}',
            color: '#1D2129',
            fontSize: 10 // 字体小一些，避免拥挤
        }
    }]
});

// ============================
// Approval Trend (from DB)
// ============================


const approvalTrendChart = echarts.init(document.getElementById('approvalTrendChart'));

approvalTrendChart.setOption({
    tooltip: { trigger: 'axis' },
    xAxis: {
        type: 'category',
        data: trendDates.length ? trendDates : ['No Data']
    },
    yAxis: { type: 'value' },
    series: [
        {
            name: 'Approved',
            type: 'line',
            smooth: true,
            data: trendApproved.length ? trendApproved : [0]
        },
        {
            name: 'Rejected',
            type: 'line',
            smooth: true,
            data: trendDenied.length ? trendDenied : [0]
        }
    ]
});

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


function applyTimeFilter() {
    const val = document.getElementById("timeFilter").value;
    window.location = "?time=" + val;
}

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

<?php include "includes/layout_end.php"; ?>


  </body>
</html>
