<?php
require_once "db_connect.php";

$timeFilter = $_GET['time'] ?? 'all';  

// ======================
// 1. Overall Counts
// ======================
$totalSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub")->fetch_assoc()['c'];
$pendingSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='pending'")->fetch_assoc()['c'];
$approvedSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='approved'")->fetch_assoc()['c'];
$deniedSubmission = $conn->query("SELECT COUNT(*) AS c FROM sub WHERE status='denied'")->fetch_assoc()['c'];



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
    GROUP BY category.categoryName
    ORDER BY total DESC
";
$challengeType = $conn->query($challengeTypeSQL);



$challengeLabels = [];
$challengeValues = [];
mysqli_data_seek($challengeType, 0);
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
    WHERE uploaded_at >= DATE(NOW() - INTERVAL 7 DAY)
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
    WHERE uploaded_at >= DATE(NOW() - INTERVAL 7 DAY)
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
                  <i class="fas fa-server text-xl"> </i>
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
                    <button class="py-3 px-4 text-sm font-semibold text-dark-2 hover:text-dark hover:border-dark/20 transition-all" onclick="showTab('reward')" id="tab-reward"> Participant </button>

                </div>


            <div id="charts" class="tab-content">
                <div class="flex flex-col lg:flex-row gap-6">

                <div class="bg-white rounded-2xl shadow-lg p-6 flex-1 flex flex-col items-center">
                    <h4 class="text-lg font-semibold text-dark mb-4">总审批率 (Approved vs Rejected)</h4>
                    <div id="approvalRateChart" class="w-full h-80"></div>
                </div>

  
                <div class="bg-white rounded-2xl shadow-lg p-6 flex-1 flex flex-col">
                    <h4 class="text-lg font-semibold text-dark mb-4">提交最多的挑战类别</h4>
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
            <h4 class="text-lg font-semibold text-dark mb-4">最近7天审批趋势</h4>
            <div id="approvalTrendChart" class="w-full h-64"></div>
            </div>

        </div>


        <div id="reward" class="tab-content hidden">
            <!-- Participant Tab Content -->
              <div class="space-y-6">

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

const challengeLabels = <?= json_encode($challengeLabels) ?>;
const challengeValues = <?= json_encode($challengeValues) ?>;

const trendDates = <?= json_encode($trendDates) ?>;
const trendApproved = <?= json_encode($trendApproved) ?>;
const trendDenied = <?= json_encode($trendDenied) ?>;

const dailyDates = <?= json_encode($dailyDates) ?>;
const dailyTotals = <?= json_encode($dailyTotals) ?>;

document.addEventListener('DOMContentLoaded', function () {

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
                { value: <?= $approveCount ?>, name: 'Approved', itemStyle: { color: '#52C41A' } },
                { value: <?= $rejectCount ?>, name: 'Rejected', itemStyle: { color: '#FF4D4F' } }
            ]

        }]
    });

        const challengeTypeChart = echarts.init(document.getElementById('challengeTypeChart'));

        challengeTypeChart.setOption({
            tooltip: { trigger: 'axis' },
            xAxis: {
                type: 'category',
                data: challengeLabels.length ? challengeLabels : ['No Data']
            },
            yAxis: { type: 'value' },
            series: [{
                type: 'bar',
                data: challengeValues.length ? challengeValues : [0],
                barWidth: 28,
                itemStyle: {
                    borderRadius: [6, 6, 6, 6]
                }
            }]
        });


  // ============================
// Daily Review Trend (from DB)
// ============================
const dailyDates = [
<?php mysqli_data_seek($dailyData, 0); while($d = $dailyData->fetch_assoc()): ?>
    "<?= $d['d'] ?>",
<?php endwhile; ?>
];

const dailyTotal = [
<?php mysqli_data_seek($dailyData, 0); while($d = $dailyData->fetch_assoc()): ?>
    <?= $d['total'] ?>,
<?php endwhile; ?>
];

const dailyReviewChart = echarts.init(document.getElementById('dailyReviewChart'));

dailyReviewChart.setOption({
    tooltip: { trigger: 'axis' },
    xAxis: {
        type: 'category',
        data: dailyDates.length ? dailyDates : ['No Data']
    },
    yAxis: { type: 'value' },
    series: [{
        type: 'bar',
        data: dailyTotals.length ? dailyTotals : [0],
        barWidth: 28
    }]
});

// ============================
// Approval Trend (from DB)
// ============================
const trendDates = [
<?php mysqli_data_seek($trendData, 0); while($t = $trendData->fetch_assoc()): ?>
    "<?= $t['d'] ?>",
<?php endwhile; ?>
];

const approved = [
<?php mysqli_data_seek($trendData, 0); while($t = $trendData->fetch_assoc()): ?>
    <?= $t['approved'] ?>,
<?php endwhile; ?>
];

const rejected = [
<?php mysqli_data_seek($trendData, 0); while($t = $trendData->fetch_assoc()): ?>
    <?= $t['denied'] ?>,
<?php endwhile; ?>
];


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




  </body>
</html>
