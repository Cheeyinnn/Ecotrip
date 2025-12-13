<?php
session_start();
require "db_connect.php";

// 1. Security & Role Check
if (!isset($_SESSION['userID'])) { header("Location: login.php"); exit(); }

// Optional: Restrict to Admin/Moderator only
// if ($_SESSION['role'] !== 'admin') { header("Location: index.php"); exit(); }

$userID = $_SESSION['userID'];
$pageTitle = "Analytics Dashboard";

// --- 2. DATA FETCHING ---

// A. TOP CARDS (Key Performance Indicators)
// Total Points Burned (Excluding cancelled/denied)
$sql = "SELECT SUM(pointSpent) as total FROM redemptionrequest WHERE status NOT IN ('cancelled', 'denied')";
$burnedPoints = $conn->query($sql)->fetch_assoc()['total'] ?? 0;

// Pending Requests (Operational Bottleneck)
$sql = "SELECT COUNT(*) as total FROM redemptionrequest WHERE status = 'pending'";
$pendingCount = $conn->query($sql)->fetch_assoc()['total'] ?? 0;

// Low Stock Items (Inventory Health - using logic from rewardAdmin.php)
$sql = "SELECT COUNT(*) as total FROM reward WHERE stockQuantity < 5 AND is_active = 1";
$lowStockCount = $conn->query($sql)->fetch_assoc()['total'] ?? 0;

// Total Successful Redemptions
$sql = "SELECT COUNT(*) as total FROM redemptionrequest WHERE status IN ('approved', 'outOfDiliver', 'Delivered')";
$successCount = $conn->query($sql)->fetch_assoc()['total'] ?? 0;


// B. CHART 1: REDEMPTION TREND (Last 6 Months)
$months = [];
$trendData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $lbl = date('M', strtotime("-$i months"));
    
    // Check if requested_at exists, otherwise fallback or count ID range (assuming auto-increment correlates with time)
    // Using standard SQL date grouping
    $q = "SELECT COUNT(*) as count FROM redemptionrequest WHERE DATE_FORMAT(requested_at, '%Y-%m') = '$m'";
    $res = $conn->query($q);
    $row = $res->fetch_assoc();
    
    $months[] = $lbl;
    $trendData[] = $row['count'] ?? 0;
}

// C. CHART 2: TOP 5 POPULAR REWARDS
$popLabels = [];
$popData = [];
$popColors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899']; // Blue, Green, Orange, Purple, Pink

$sql = "SELECT r.rewardName, COUNT(rr.redemptionID) as count 
        FROM redemptionrequest rr
        JOIN reward r ON rr.rewardID = r.rewardID
        WHERE rr.status != 'cancelled'
        GROUP BY rr.rewardID 
        ORDER BY count DESC LIMIT 5";
$res = $conn->query($sql);
while($row = $res->fetch_assoc()){
    $popLabels[] = $row['rewardName'];
    $popData[] = $row['count'];
}

// D. RECENT PENDING REQUESTS (Actionable List)
$pendingSql = "SELECT rr.redemptionID, u.firstName, u.lastName, r.rewardName, rr.requested_at, r.imageURL 
               FROM redemptionrequest rr 
               JOIN user u ON rr.userID = u.userID
               JOIN reward r ON rr.rewardID = r.rewardID
               WHERE rr.status = 'pending' 
               ORDER BY rr.requested_at ASC LIMIT 5";
$pendingRes = $conn->query($pendingSql);

// E. INVENTORY ALERTS (Low Stock or Expiring Soon)
// Leveraging the new expiry_date column from rewardAdmin.php
$alertSql = "SELECT rewardName, stockQuantity, expiry_date, imageURL 
             FROM reward 
             WHERE (stockQuantity < 5 OR (expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))) 
             AND is_active = 1 
             LIMIT 5";
$alertRes = $conn->query($alertSql);

include "includes/layout_start.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - EcoTrip</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

    <style>
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
<body>

<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark m-0">Admin Overview</h3>
            <p class="text-muted m-0">Performance analytics and alerts.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="reviewRR.php" class="btn btn-white border fw-bold text-secondary shadow-sm"><iconify-icon icon="solar:clipboard-check-linear"></iconify-icon> Reviews</a>
            <a href="rewardAdmin.php" class="btn btn-dark fw-bold shadow-sm"><iconify-icon icon="solar:settings-bold"></iconify-icon> Manage</a>
        </div>
    </div>

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
                <div class="section-title">Redemption Activity <small class="text-muted fw-normal">Last 6 Months</small></div>
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

<script>
    // 1. Trend Line Chart
    const ctxTrend = document.getElementById('trendChart').getContext('2d');
    new Chart(ctxTrend, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'Redemptions',
                data: <?php echo json_encode($trendData); ?>,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.05)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { borderDash: [5, 5] } },
                x: { grid: { display: false } }
            }
        }
    });

    // 2. Popular Items Doughnut
    const ctxPop = document.getElementById('popChart').getContext('2d');
    new Chart(ctxPop, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($popLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($popData); ?>,
                backgroundColor: <?php echo json_encode($popColors); ?>,
                borderWidth: 0,
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
            }
        }
    });
</script>

<?php include "includes/layout_end.php"; ?>

</body>
</html>