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

// PHP - 新增 Average Review Time 查询
$avgReviewTimeSQL = "
    SELECT AVG(TIMESTAMPDIFF(MINUTE, uploaded_at, approved_at)) AS avg_minutes
    FROM sub
    WHERE status IN ('approved', 'denied') $timeCondition
    AND approved_at IS NOT NULL
";
$avgMinutes = $conn->query($avgReviewTimeSQL)->fetch_assoc()['avg_minutes'];

// 计算平均时长（假设 $avgMinutes 是分钟数）
$avgHours = round($avgMinutes / 60, 1);


include "includes/layout_start.php";

?>


<!DOCTYPE html>
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

      /* 容器样式 (Quick Navigation) */
        .reward-summary-card {
            display: flex; 
            align-items: center; 
            padding: 15px 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); 
            background-color: #ffffff;
            /* 保持 flex 布局 */
        }

        /* 1. 图标容器 */
        .icon-container {
            font-size: 28px;
            color: #FFC107; 
            margin-right: 20px;
        }
        .icon-container .fas {
            font-size: 32px;
        }

        /* 2. 文本内容 */
        .text-content {
            flex-grow: 1; 
            margin-right: 20px;
        }

        .summary-title {
            margin: 0 0 5px 0;
            font-size: 16px;
            color: #1D2129; 
            font-weight: bold;
        }

        .summary-description {
            margin: 0;
            font-size: 13px;
            color: #606771; 
        }

        /* 3. 导航链接/按钮 */
        .action-link {
            flex-shrink: 0; 
        }

        .nav-button {
            text-decoration: none;
            padding: 8px 12px;
            background-color: #1677FF; 
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
    display: flex; 
    gap: 10px; 
    margin-top: 15px; 
}

.quick-nav-item {
    flex: 1; 
    display: flex;
    align-items: center;
    justify-content: center; 
    padding: 10px 15px;
    
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    color: #4C4C4C; 
    
    border: 1px solid #D9D9D9;
    border-radius: 4px;
    background-color: #F7F7F7; 
    
    transition: all 0.2s ease;
}

.quick-nav-item:hover {
    background-color: #E6F7FF; 
    border-color: #91D5FF;
    color: #1677FF; 
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
                <i class="fas fa-tachometer-alt text-primary mr-2"></i> Moderator Dashboard
              </h2>
              <p class="text-dark-2 mt-1">Viewing submission statistics for the selected time range.</p>
            </div>
            <div class="flex items-center gap-3">
                <select id="timeFilter" class="border rounded px-2 py-1 shadow-sm focus:ring-primary focus:border-primary" onchange="applyTimeFilter()">
                    <option value="all" <?= $timeFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                    <option value="7" <?= $timeFilter === '7' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30" <?= $timeFilter === '30' ? 'selected' : '' ?>>Last 30 Days</option>
                </select>

                <button onclick="window.location.reload()" class="bg-primary hover:bg-blue-700 text-white px-3 py-1.5 rounded shadow-md transition-colors">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
          </div>
          
<div class="bg-white rounded-2xl shadow-card p-6 hover:shadow-card-hover transition-all">
  <div class="flex justify-between items-start">
    <div>
      <p class="stat-card-label text-info">Average Review Time</p>
      <h3 class="stat-card-value mt-1 text-info"><?= $avgHours ?> hrs</h3>
    </div>
    <div class="w-12 h-12 rounded-lg bg-info/10 flex items-center justify-center text-info">
      <i class="fas fa-clock text-xl"> </i>
    </div>
  </div>
</div>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

            <div class="bg-white rounded-2xl shadow-card p-6 hover:shadow-card-hover transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label"> Total Submission </p>
                  <h3 class="stat-card-value mt-1"><?= $totalSubmission ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                  <i class="fas fa-layer-group text-xl"> </i>
                </div>
              </div>
            </div>

            <div class="bg-white rounded-2xl shadow-card p-6 hover:shadow-card-hover transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">Pending Submission</p>
                  <h3 class="stat-card-value mt-1"><?= $pendingSubmission ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg bg-warning/10 flex items-center justify-center text-warning">
                  <i class="fas fa-hourglass-half text-xl"> </i>
                </div>
              </div>
            </div>

           <div class="bg-white rounded-2xl shadow-card p-6 hover:shadow-card-hover transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label"> Approved Submission </p>
                  <h3 class="stat-card-value mt-1"><?= $approvedSubmission ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg bg-success/10 flex items-center justify-center text-success">
                  <i class="fas fa-check-circle text-xl"> </i>
                </div>
              </div>
            </div>

            <div class="bg-white rounded-2xl shadow-card p-6 hover:shadow-card-hover transition-all">
              <div class="flex justify-between items-start">
                <div>
                  <p class="stat-card-label">Denied Submission</p>
                  <h3 class="stat-card-value mt-1"><?= $deniedSubmission ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg bg-danger/10 flex items-center justify-center text-danger">
                  <i class="fas fa-times-circle text-xl" > </i>
                </div>
              </div>
            </div>
          </div>
          
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
              
              <div class="bg-white rounded-2xl shadow-card p-6 flex-1 flex flex-col items-center">
                  <h4 class="text-lg font-semibold text-dark mb-4 border-b pb-2 w-full text-center">🏆 Reviewed Rate (Approved vs Rejected)</h4>
                  <div id="approvalRateChart" class="w-full h-80"></div>
              </div>

              <div class="bg-white rounded-2xl shadow-card p-6 flex-1 flex flex-col">
                  <h4 class="text-lg font-semibold text-center text-dark mb-4 border-b pb-2 w-full">📈 Most Particaipate Challenge</h4>
                  <div id="challengeTypeChart" class="w-full h-80"></div>
              </div>

          </div>


          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
              
              <div class="bg-white rounded-2xl shadow-card p-6 flex-1">
                  <h4 class="text-lg font-semibold text-dark mb-4 border-b pb-2">📊 Approval Trend</h4>
                  <div id="approvalTrendChart" class="w-full h-80"></div>
              </div>

              <div class="bg-white rounded-2xl shadow-card p-6 flex-1">
                  <h4 class="text-lg font-semibold text-dark mb-4 border-b pb-2">📅 Daily Submission Trend</h4>
                  <div id="dailyReviewChart" class="w-full h-80"></div>
              </div>
              
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


document.addEventListener('DOMContentLoaded', function () {

    // ============================
    // 1. Approval Rate Donut Chart
    // ============================
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
            orient: 'horizontal', 
            top: 'bottom', // 移到底部以节省空间
            left: 'center', 
            textStyle: { color: '#1D2129', fontSize: 14 },
        },
        series: [{
            name: 'Rate',
            type: 'pie',
            radius: ['50%', '75%'], // 调整甜甜圈大小
            avoidLabelOverlap: true,
            itemStyle: { borderRadius: 8, borderColor: '#fff', borderWidth: 2 },
            label: { show: false, position: 'center' }, // 默认不显示 label
            emphasis: {
                scale: true,
                scaleSize: 8,
                label: { show: true, formatter: '{d}%', fontSize: 18, fontWeight: 'bold', color: '#1D2129' } // 悬停时显示百分比
            },
            labelLine: { show: false },
           data: [
                { value: approveCount, name: 'Approved', itemStyle: { color: '#52C41A' } },
                { value: rejectCount, name: 'Rejected', itemStyle: { color: '#FF4D4F' } }
            ]
        }]
    });

    // ============================
    // 2. Challenge Type Bar Chart
    // ============================
    const challengeTypeChart = echarts.init(document.getElementById('challengeTypeChart'));

    challengeTypeChart.setOption({
        tooltip: { 
            trigger: 'axis',
            axisPointer: { type: 'shadow' }
        },
        grid: {
            left: '3%',
            right: '4%',
            bottom: '3%',
            top: '10%',
            containLabel: true
        },
        xAxis: {
            type: 'category',
            data: challengeLabels.length ? challengeLabels : ['No Data'],
            axisLine: { show: false }, 
            axisTick: { show: false } 
        },
        yAxis: { 
            type: 'value',
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: { lineStyle: { type: 'dashed', color: '#ccc' } }
        },
        series: [{
            type: 'bar',
            data: challengeValues.length ? challengeValues : [0],
            barWidth: '50%', 
            label: {
                show: true,
                position: 'top',
                formatter: '{c}',
                color: '#111',
                fontWeight: 'bold'
            },
            itemStyle: {
                borderRadius: [6, 6, 0, 0], 
                color: new echarts.graphic.LinearGradient(
                    0, 0, 0, 1, 
                    [
                        { offset: 0, color: '#165DFF' }, // Primary Blue
                        { offset: 1, color: '#36CBCB' } 
                    ]
                )
            }
        }]
    });

    // ============================
    // 3. Daily Submission Trend (Horizontal Bar)
    // ============================
    const dailyReviewChart = echarts.init(document.getElementById('dailyReviewChart'));

    dailyReviewChart.setOption({
        tooltip: { 
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: '{b}: {c} Submissions'
        },
        grid: {
            left: '3%', right: '10%', bottom: '3%', top: '10%', containLabel: true
        },
        // X 轴：数值轴
        xAxis: {
            type: 'value',
            minInterval: 1, 
            axisLine: { show: false },
            splitLine: { lineStyle: { type: 'solid', color: '#f0f0f0' } }
        },
        // Y 轴：类目轴（日期）
        yAxis: { 
            type: 'category',
            data: dailyDates.length ? dailyDates : ['No Data'],
            axisLine: { show: false }, 
            axisTick: { show: false }
        },
        series: [{
            name: 'Total Submissions',
            type: 'bar',
            data: dailyTotals.length ? dailyTotals : [0],
            barWidth: '60%', 
            itemStyle: {
                borderRadius: 5, 
                color: '#36CBCB' // Secondary Color (Teal)
            },
            label: {
                show: true,
                position: 'right', 
                formatter: '{c}',
                color: '#1D2129',
                fontSize: 12 
            }
        }]
    });

    // ============================
    // 4. Approval Trend (Line Chart)
    // ============================
    const approvalTrendChart = echarts.init(document.getElementById('approvalTrendChart'));

    approvalTrendChart.setOption({
        tooltip: { trigger: 'axis' },
        legend: {
            data: ['Approved', 'Rejected'],
            bottom: 0,
            icon: 'circle' // 使用圆形图标
        },
        xAxis: {
            type: 'category',
            data: trendDates.length ? trendDates : ['No Data'],
            axisLine: { lineStyle: { color: '#ccc' } }
        },
        yAxis: { 
            type: 'value',
            splitLine: { lineStyle: { type: 'dashed' } }
        },
        grid: {
             left: '3%', right: '4%', bottom: '15%', containLabel: true
        },
        series: [
            {
                name: 'Approved',
                type: 'line',
                smooth: true,
                symbol: 'none', // 不显示数据点
                itemStyle: { color: '#52C41A' }, // Success Green
                areaStyle: {
                    opacity: 0.1,
                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: 'rgba(82, 196, 26, 0.4)' },
                        { offset: 1, color: 'rgba(82, 196, 26, 0)' }
                    ])
                },
                data: trendApproved.length ? trendApproved : [0]
            },
            {
                name: 'Rejected',
                type: 'line',
                smooth: true,
                symbol: 'none',
                itemStyle: { color: '#FF4D4F' }, // Danger Red
                areaStyle: {
                    opacity: 0.1,
                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: 'rgba(255, 77, 79, 0.4)' },
                        { offset: 1, color: 'rgba(255, 77, 79, 0)' }
                    ])
                },
                data: trendDenied.length ? trendDenied : [0]
            }
        ]
    });

    // 调整图表大小以适应窗口变化
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

// 移除 showTab 函数，因为不再使用 Tab

</script>

<?php // include "includes/layout_end.php"; ?>

  </body>
</html>