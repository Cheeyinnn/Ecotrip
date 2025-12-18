<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'moderator') {
    header("Location: index.php");
    exit;
}

$userID = $_SESSION['userID'];
$moderatorCondition = " AND moderatorID = $userID";

$timeFilter = $_GET['time'] ?? 'all';
$days = null;

if ($timeFilter === '7') $days = 7;
elseif ($timeFilter === '30') $days = 30;


$timeCondition = '';
if ($days !== null) $timeCondition = " AND uploaded_at >= NOW() - INTERVAL $days DAY";
elseif ($timeFilter === 'today') $timeCondition = " AND DATE(uploaded_at) = CURDATE()";

// ======================

$totalSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE 1=1 $timeCondition")->fetch_assoc()['c'];

$pendingSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status = 'pending' $timeCondition")->fetch_assoc()['c'];
// ======================
// total approved / denied by current moderator
$approvedSubmission = $conn->query("
    SELECT COUNT(*) AS c 
    FROM sub 
    WHERE status = 'approved' 
      AND moderatorID = $userID
      " . ($days !== null ? " AND approved_at >= NOW() - INTERVAL $days DAY" : "") . "
      " . ($timeFilter === 'today' ? " AND DATE(approved_at) = CURDATE()" : "") . "
")->fetch_assoc()['c'];

$deniedSubmission = $conn->query("
    SELECT COUNT(*) AS c 
    FROM sub 
    WHERE status = 'denied' 
      AND moderatorID = $userID
      " . ($days !== null ? " AND denied_at >= NOW() - INTERVAL $days DAY" : "") . "
      " . ($timeFilter === 'today' ? " AND DATE(denied_at) = CURDATE()" : "") . "
")->fetch_assoc()['c'];

$approveCount = $approvedSubmission;
$rejectCount  = $deniedSubmission;

// total Reviews
$totalReviews = $approveCount + $rejectCount;


// ======================

$challengeTypeSQL = "
    SELECT category.categoryName, COUNT(*) AS total
    FROM sub
    JOIN challenge ON sub.challengeID = challenge.challengeID
    JOIN category ON challenge.categoryID = category.categoryID
    WHERE 1=1
";
if ($days !== null) $challengeTypeSQL .= " AND sub.uploaded_at >= NOW() - INTERVAL $days DAY";
elseif ($timeFilter === 'today') $challengeTypeSQL .= " AND DATE(sub.uploaded_at) = CURDATE()";
$challengeTypeSQL .= " GROUP BY category.categoryName ORDER BY total DESC";
$challengeType = $conn->query($challengeTypeSQL);

$challengeLabels = [];
$challengeValues = [];
while ($c = $challengeType->fetch_assoc()) {
    $challengeLabels[] = $c['categoryName'];
    $challengeValues[] = (int)$c['total'];
}


// Approval Trend
// ----------------------

$endDate = date('Y-m-d'); // today

if ($timeFilter === 'today') {
    $startDate = $endDate;
} elseif ($days !== null) {
    $startDate = date('Y-m-d', strtotime("-$days days"));
} else {
   
    $minDateRow = $conn->query("
        SELECT MIN(uploaded_at) AS minDate
        FROM sub
        WHERE moderatorID = $userID
    ")->fetch_assoc();

    $startDate = $minDateRow['minDate']
        ? date('Y-m-d', strtotime($minDateRow['minDate']))
        : $endDate;
}



$trendDates = [];

$currentDate = $startDate;
while ($currentDate <= $endDate) {
    $trendDates[$currentDate] = [
        'pending'  => 0,
        'approved' => 0,
        'denied'   => 0
    ];
    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
}



$pendingSQL = "
    SELECT DATE(uploaded_at) AS d, COUNT(*) AS c
    FROM sub
    WHERE status = 'pending'
      AND moderatorID = $userID
      " . ($days !== null ? " AND uploaded_at >= NOW() - INTERVAL $days DAY" : "") . "
      " . ($timeFilter === 'today' ? " AND DATE(uploaded_at) = CURDATE()" : "") . "
    GROUP BY DATE(uploaded_at)
";

$res = $conn->query($pendingSQL);
while ($row = $res->fetch_assoc()) {
    $trendDates[$row['d']]['pending'] = (int)$row['c'];
}


$approvedSQL = "
    SELECT DATE(approved_at) AS d, COUNT(*) AS c
    FROM sub
    WHERE status = 'approved'
      AND moderatorID = $userID
      AND approved_at IS NOT NULL
      " . ($days !== null ? " AND approved_at >= NOW() - INTERVAL $days DAY" : "") . "
      " . ($timeFilter === 'today' ? " AND DATE(approved_at) = CURDATE()" : "") . "
    GROUP BY DATE(approved_at)
";

$res = $conn->query($approvedSQL);
while ($row = $res->fetch_assoc()) {
    $trendDates[$row['d']]['approved'] = (int)$row['c'];
}

$deniedSQL = "
    SELECT DATE(denied_at) AS d, COUNT(*) AS c
    FROM sub
    WHERE status = 'denied'
      AND moderatorID = $userID
      AND denied_at IS NOT NULL
      " . ($days !== null ? " AND denied_at >= NOW() - INTERVAL $days DAY" : "") . "
      " . ($timeFilter === 'today' ? " AND DATE(denied_at) = CURDATE()" : "") . "
    GROUP BY DATE(denied_at)
";

$res = $conn->query($deniedSQL);
while ($row = $res->fetch_assoc()) {
    $trendDates[$row['d']]['denied'] = (int)$row['c'];
}


$trendDatesArr  = array_keys($trendDates);
$trendPending   = array_column($trendDates, 'pending');
$trendApproved  = array_column($trendDates, 'approved');
$trendDenied    = array_column($trendDates, 'denied');




$dailySQL = "
    SELECT DATE(uploaded_at) AS d, COUNT(*) AS total
    FROM sub
    WHERE 1=1 $timeCondition
    GROUP BY DATE(uploaded_at)
    ORDER BY d
";
$dailyData = $conn->query($dailySQL);
$dailyDates = $dailyTotals = [];
while ($d = $dailyData->fetch_assoc()) {
    $dailyDates[] = $d['d'];
    $dailyTotals[] = (int)$d['total'];
}

// ======================
// total Review  
$totalReviews = $approvedSubmission + $deniedSubmission;

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

      
        .reward-summary-card {
            display: flex; 
            align-items: center; 
            padding: 15px 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); 
            background-color: #ffffff;
           
        }

     
        .icon-container {
            font-size: 28px;
            color: #FFC107; 
            margin-right: 20px;
        }
        .icon-container .fas {
            font-size: 32px;
        }

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

       
        .nav-button .fas {
            margin-left: 8px;
            font-size: 12px;
        }


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


.quick-nav-item .fas {
    margin-right: 8px;
    font-size: 16px;
}
    </style>
  </head>


<div class="mb-10 flex flex-col md:flex-row md:items-center md:justify-between gap-6 bg-white p-6 rounded-3xl shadow-sm border border-gray-100 transition-all duration-300">
    <div>
        <h2 class="text-2xl font-extrabold text-gray-800 flex items-center gap-3">
            <span class="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl shadow-inner">
                <i class="fas fa-chart-line"></i>
            </span>
            Moderator Dashboard
        </h2>
        <p class="text-gray-500 mt-1 text-sm font-medium">Real-time insights and moderation performance tracking.</p>
    </div>

    <div class="flex items-center gap-4">
        <div class="flex items-center gap-2 bg-gray-50 p-1.5 rounded-2xl border border-gray-100 shadow-inner">
            <div class="relative group">
                <select id="timeFilter" 
                        onchange="applyTimeFilter()" 
                        class="appearance-none bg-white border border-gray-200 text-gray-700 text-sm font-semibold py-2 pl-4 pr-10 rounded-xl focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all cursor-pointer hover:border-indigo-300">
                    <option value="all" <?= $timeFilter==='all'?'selected':'' ?>>All Time</option>
                    <option value="today" <?= $timeFilter==='today'?'selected':'' ?>>Today</option>
                    <option value="7" <?= $timeFilter==='7'?'selected':'' ?>>Last 7 Days</option>
                    <option value="30" <?= $timeFilter==='30'?'selected':'' ?>>Last 30 Days</option>
                </select>
                <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none flex flex-col gap-0.5 text-[8px] text-gray-400 group-hover:text-indigo-500 transition-colors">
                    <i class="fas fa-chevron-up"></i>
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
        </div>

        <button onclick="location.reload()" 
                class="flex items-center gap-2 bg-gray-900 hover:bg-indigo-600 text-white px-5 py-3 rounded-2xl shadow-lg shadow-gray-200 transition-all active:scale-95 group">
            <i class="fas fa-sync-alt text-sm group-hover:rotate-180 transition-transform duration-500"></i>
            <span class="font-bold text-sm tracking-wide">Refresh Data</span>
        </button>
    </div>
</div>

<section class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8 mb-10">
    <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
        <span class="w-1.5 h-6 bg-blue-500 rounded-full"></span> 📊 System Overview
    </h3>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
        <div class="group bg-gray-50 rounded-2xl p-6 hover:bg-white hover:shadow-md border border-transparent hover:border-gray-100 transition-all duration-300">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Total Submissions</p>
                    <h3 class="text-3xl font-bold text-gray-800 mt-2"><?= $totalSubmission ?></h3>
                </div>
                <div class="p-3 bg-blue-100 text-blue-600 rounded-xl transition-colors">
                    <i class="fas fa-file-import fa-lg"></i>
                </div>
            </div>
        </div>

        <div class="group bg-gray-50 rounded-2xl p-6 hover:bg-white hover:shadow-md border border-transparent hover:border-gray-100 transition-all duration-300">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Pending Review</p>
                    <h3 class="text-3xl font-bold text-gray-800 mt-2"><?= $pendingSubmission ?></h3>
                </div>
                <div class="p-3 bg-amber-100 text-amber-600 rounded-xl transition-colors">
                    <i class="fas fa-clock fa-lg"></i>
                </div>
            </div>
        </div>
    </div>

    <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
        <span class="w-1.5 h-6 bg-indigo-500 rounded-full"></span> 📈 Analytics Insights
    </h3>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div class="flex flex-col">
            <div class="flex items-center justify-between mb-4 px-2">
                <h4 class="font-semibold text-gray-700 flex items-center gap-2">
                    <i class="fas fa-calendar-check text-blue-500 text-sm"></i> Daily Submission Trend
                </h4>
                <span class="text-[10px] px-2 py-1 bg-blue-50 text-blue-600 font-bold rounded-lg uppercase">Activity</span>
            </div>
            <div id="dailyReviewChart" class="w-full h-80 bg-gray-50 rounded-2xl border border-gray-100"></div>
        </div>
        
        <div class="flex flex-col">
            <div class="flex items-center justify-between mb-4 px-2">
                <h4 class="font-semibold text-gray-700 flex items-center gap-2">
                    <i class="fas fa-fire text-orange-500 text-sm"></i> Most Participate Challenge Category
                </h4>
                <span class="text-[10px] px-2 py-1 bg-gray-100 text-gray-400 font-bold rounded-lg uppercase">Bar Chart</span>
            </div>
            <div id="challengeTypeChart" class="w-full h-80 bg-gray-50 rounded-2xl border border-gray-100"></div>
        </div>
    </div>
</section>

<section class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8 mb-10">
    <h3 class="text-lg font-bold text-gray-700 mb-6 flex items-center gap-2">
        <span class="w-1.5 h-5 bg-indigo-500 rounded-full"></span> 👤 My Review Performance
    </h3>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        <div class="bg-white rounded-2xl p-6 border-b-4 border-green-500 shadow-sm border-x border-t border-gray-100">
            <p class="text-sm font-semibold text-gray-400">Approved by Me</p>
            <div class="flex items-baseline gap-2">
                <h3 class="text-3xl font-bold text-gray-800 mt-1"><?= $approvedSubmission ?></h3>
                <span class="text-green-500 text-xs font-bold"><i class="fas fa-check"></i> approved</span>
            </div>
        </div>
        
        <div class="bg-white rounded-2xl p-6 border-b-4 border-red-500 shadow-sm border-x border-t border-gray-100">
            <p class="text-sm font-semibold text-gray-400">Rejected by Me</p>
            <div class="flex items-baseline gap-2">
                <h3 class="text-3xl font-bold text-gray-800 mt-1"><?= $deniedSubmission ?></h3>
                <span class="text-red-500 text-xs font-bold"><i class="fas fa-times"></i> denied</span>
            </div>
        </div>
        
        <div class="bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl p-6 shadow-lg shadow-indigo-100">
            <p class="text-sm font-semibold text-indigo-100">Total Reviews</p>
            <h3 class="text-3xl font-bold text-white mt-1"><?= $totalReviews  ?> <span class="text-lg font-normal opacity-80">submission</span></h3>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mt-8">
        <div class="flex flex-col">
            <div class="flex items-center justify-between mb-4 px-2">
                <h4 class="font-semibold text-gray-700 flex items-center gap-2">
                    <i class="fas fa-pie-chart text-indigo-500 text-sm"></i> Reviewed Rate
                </h4>
                <span class="text-[10px] px-2 py-1 bg-gray-100 text-gray-400 font-bold rounded-lg uppercase">Donut Chart</span>
            </div>
            <div id="approvalRateChart" class="w-full h-80 bg-gray-50 rounded-2xl border border-gray-100"></div>
        </div>

        <div class="flex flex-col">
            <div class="flex items-center justify-between mb-4 px-2">
                <h4 class="font-semibold text-gray-700 flex items-center gap-2">
                    <i class="fas fa-chart-area text-green-500 text-sm"></i> Approval Trend
                </h4>
                <span class="text-[10px] px-2 py-1 bg-green-50 text-green-600 font-bold rounded-full border border-green-100 uppercase">Line Chart</span>
            </div>
            <div id="approvalTrendChart" class="w-full h-80 bg-gray-50 rounded-2xl border border-gray-100"></div>
        </div>
    </div>
</section>



    
</div>

</div>

<script>
const approveCount = <?= $approveCount ?>;
const rejectCount = <?= $rejectCount ?>;
const challengeLabels = <?= json_encode($challengeLabels) ?>;
const challengeValues = <?= json_encode($challengeValues) ?>;
const dailyDates = <?= json_encode($dailyDates) ?>;
const dailyTotals = <?= json_encode($dailyTotals) ?>;


const trendDates = <?= json_encode($trendDatesArr) ?>;
const trendApproved = <?= json_encode($trendApproved) ?>;
const trendDenied = <?= json_encode($trendDenied) ?>;
const trendPending = <?= json_encode($trendPending) ?>;
const hasReview = approveCount > 0 || rejectCount > 0;


function applyTimeFilter() {
    const time = document.getElementById('timeFilter').value;
    window.location.href = `?time=${time}`;
}


document.addEventListener('DOMContentLoaded', function () {

   
    const approvalRateChart = echarts.init(document.getElementById('approvalRateChart'));

    if (!hasReview) {
        approvalRateChart.setOption({
            series: [{
                type: 'pie',
                radius: ['50%', '75%'],
                silent: true,
                label: {
                    show: true,
                    position: 'center',
                    formatter: 'No reviews\nyet',
                    color: '#86909C',
                    fontSize: 14,
                    lineHeight: 20,
                    fontWeight: 500
                },
                data: [{ value: 1, name: 'Empty', itemStyle: { color: '#F2F3F5' } }]
            }]
        });
    } else {
        approvalRateChart.setOption({
            tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
            legend: { show: true, orient: 'horizontal', bottom: '0%', left: 'center', textStyle: { color: '#1D2129' } },
            series: [{
                name: 'Rate',
                type: 'pie',
                radius: ['50%', '75%'],
                avoidLabelOverlap: true,
                itemStyle: { borderRadius: 8, borderColor: '#fff', borderWidth: 2 },
                label: { show: false },
                emphasis: { scale: true, scaleSize: 8, label: { show: true, formatter: '{d}%', fontSize: 18, fontWeight: 'bold', color: '#1D2129' } },
                labelLine: { show: false },
                data: [
                    { value: approveCount, name: 'Approved', itemStyle: { color: '#52C41A' } },
                    { value: rejectCount, name: 'Rejected', itemStyle: { color: '#FF4D4F' } }
                ]
            }]
        });
    }

    // ============================
    // 2. Challenge Type Pie Chart
    // ============================
    const challengeTypeChart = echarts.init(document.getElementById('challengeTypeChart'));
    challengeTypeChart.setOption({
        tooltip: { trigger: 'item', formatter: '{b}: <b>{c}</b> ({d}%)' },
        legend: { bottom: '0%', left: 'center', itemWidth: 10, itemHeight: 10, textStyle: { color: '#64748b', fontSize: 11 } },
        series: [{
            name: 'Challenge Type',
            type: 'pie',
            radius: ['20%', '70%'],
            center: ['50%', '45%'],
            roseType: 'area',
            itemStyle: { borderRadius: 8 },
            data: challengeLabels.map((label, idx) => ({ value: challengeValues[idx], name: label })),
            label: { show: true, fontSize: 12, color: '#475569', formatter: '{b}' },
            color: ['#6366f1', '#8b5cf6', '#ec4899', '#f43f5e', '#f59e0b', '#10b981']
        }]
    });

    // ============================
    // 3. Daily Submission Trend (Horizontal Bar)
    // ============================
    const dailyReviewChart = echarts.init(document.getElementById('dailyReviewChart'));
    dailyReviewChart.setOption({
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, formatter: '{b}: {c} Submissions' },
        grid: { left: '3%', right: '10%', bottom: '3%', top: '10%', containLabel: true },
        xAxis: { type: 'value', minInterval: 1, axisLine: { show: false }, splitLine: { lineStyle: { type: 'solid', color: '#f0f0f0' } } },
        yAxis: { type: 'category', data: dailyDates.length ? dailyDates : ['No Data'], axisLine: { show: false }, axisTick: { show: false } },
        series: [{
            name: 'Total Submissions',
            type: 'bar',
            data: dailyTotals.length ? dailyTotals : [0],
            barWidth: '60%',
            itemStyle: { borderRadius: 5, color: '#36CBCB' },
            label: { show: true, position: 'right', formatter: '{c}', color: '#1D2129', fontSize: 12 }
        }]
    });


// ============================
// 4. Approval Trend (NORMAL Line Chart)
// ============================
const approvalTrendChart = echarts.init(
    document.getElementById('approvalTrendChart')
);

approvalTrendChart.setOption({
    tooltip: {
        trigger: 'axis'
    },
    legend: {
        data: ['Pending', 'Approved', 'Rejected'],
        bottom: 0,
        icon: 'circle'
    },
    grid: {
        left: '3%',
        right: '4%',
        bottom: '15%',
        containLabel: true
    },
    xAxis: {
        type: 'category',
        data: trendDates,
        axisLine: { lineStyle: { color: '#ccc' } }
    },
    yAxis: {
        type: 'value',
        minInterval: 1,
        splitLine: { lineStyle: { type: 'dashed' } }
    },
    series: [
       
        {
            name: 'Approved',
            type: 'line',
            smooth: true,
            symbolSize: 8,
            data: trendApproved,
            itemStyle: { color: '#52C41A' },
            label: {
                show: true,
                position: 'top',
                formatter: v => v.value === 0 ? '' : v.value
            }
        },
        {
            name: 'Rejected',
            type: 'line',
            smooth: true,
            symbolSize: 8,
            data: trendDenied,
            itemStyle: { color: '#FF4D4F' },
            label: {
                show: true,
                position: 'top',
                formatter: v => v.value === 0 ? '' : v.value
            }
        }
    ]
});



});

</script>


<?php include "includes/layout_end.php"; ?>

  </body>
</html>