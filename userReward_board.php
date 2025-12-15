<?php 
session_start();
include 'db_connect.php';

// 1. Security Check
if (!isset($_SESSION['userID'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['userID'];

// 2. Data Fetching for Dashboard

// A. User Summary Stats
$stmt = $conn->prepare("SELECT walletPoint, 
    (SELECT SUM(pointSpent) FROM redemptionrequest WHERE userID = ?) as totalSpent,
    (SELECT COUNT(*) FROM redemptionrequest WHERE userID = ?) as totalRedeemed
    FROM user WHERE userID = ?");
$stmt->bind_param("iii", $userID, $userID, $userID);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$currentPoints = $summary['walletPoint'];
$totalSpent = $summary['totalSpent'] ?? 0;
$totalRedeemed = $summary['totalRedeemed'] ?? 0;
$stmt->close();

// B. Chart Data 1: Points Spent Trend (Last 6 Months)
$trendLabels = [];
$trendData = [];

// Get last 6 months logic
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthName = date('M', strtotime("-$i months"));
    
    // Query for that specific month
    $sql = "SELECT SUM(pointSpent) as total FROM redemptionrequest 
            WHERE userID = $userID AND DATE_FORMAT(requested_at, '%Y-%m') = '$month'";
    $res = $conn->query($sql);
    $row = $res->fetch_assoc();
    
    $trendLabels[] = $monthName;
    $trendData[] = $row['total'] ?? 0;
}

// C. Chart Data 2: Category Distribution (Products vs Vouchers)
$catLabels = [];
$catData = [];
$catSql = "SELECT r.category, COUNT(*) as count 
           FROM redemptionrequest rr 
           JOIN reward r ON rr.rewardID = r.rewardID 
           WHERE rr.userID = $userID 
           GROUP BY r.category";
$catRes = $conn->query($catSql);
while($row = $catRes->fetch_assoc()){
    $catLabels[] = ucfirst($row['category']);
    $catData[] = $row['count'];
}

// D. Recent Activity List
$recentSql = "SELECT rr.*, r.rewardName, r.imageURL, r.category 
              FROM redemptionrequest rr 
              JOIN reward r ON rr.rewardID = r.rewardID 
              WHERE rr.userID = $userID 
              ORDER BY rr.requested_at DESC LIMIT 5";
$recentRes = $conn->query($recentSql);

include "includes/layout_start.php"; // Assuming you have this
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Analytics Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

    <style>
        body { background: #f5f7fb; font-family: 'Plus Jakarta Sans', sans-serif; color: #334155; }
        
        /* Card Styles */
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            height: 100%;
            transition: transform 0.2s;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover { transform: translateY(-3px); }
        
        /* Decorative Background Circles for cards */
        .bg-circle {
            position: absolute;
            right: -20px;
            bottom: -20px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            opacity: 0.1;
        }

        /* Titles & Typography */
        .card-label { font-size: 13px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .card-value { font-size: 32px; font-weight: 800; color: #0f172a; margin: 5px 0; }
        .card-sub { font-size: 13px; color: #94a3b8; }
        
        /* Chart Containers */
        .chart-container { position: relative; height: 300px; width: 100%; }
        
        /* Table Styles */
        .table-custom th { font-size: 12px; text-transform: uppercase; color: #94a3b8; font-weight: 700; background: #f8fafc; border: none; padding: 15px; }
        .table-custom td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 14px; font-weight: 600; color: #475569; }
        .table-custom tr:last-child td { border-bottom: none; }
        .item-img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; margin-right: 12px; }

        /* Custom Colors */
        .text-purple { color: #8b5cf6; }
        .bg-purple { background-color: #8b5cf6; }
        .text-orange { color: #f97316; }
        .bg-orange { background-color: #f97316; }
    </style>
</head>
<body>

<div class="container-fluid p-4">
    
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="card-label">Current Wallet</div>
                        <div class="card-value"><?php echo number_format($currentPoints); ?></div>
                        <div class="card-sub text-success"><i class="fas fa-arrow-up"></i> Available to spend</div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-10 p-3 rounded-circle text-success">
                        <iconify-icon icon="solar:wallet-money-bold" style="font-size: 24px;"></iconify-icon>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="card-label">Total Spent</div>
                        <div class="card-value text-purple"><?php echo number_format($totalSpent); ?></div>
                        <div class="card-sub">Lifetime points burned</div>
                    </div>
                    <div class="icon-box bg-purple bg-opacity-10 p-3 rounded-circle text-purple">
                        <iconify-icon icon="solar:fire-bold" style="font-size: 24px;"></iconify-icon>
                    </div>
                </div>
                <div class="bg-circle bg-purple"></div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="card-label">Total Redemptions</div>
                        <div class="card-value text-orange"><?php echo number_format($totalRedeemed); ?></div>
                        <div class="card-sub">Items & Vouchers claimed</div>
                    </div>
                    <div class="icon-box bg-orange bg-opacity-10 p-3 rounded-circle text-orange">
                        <iconify-icon icon="solar:bag-heart-bold" style="font-size: 24px;"></iconify-icon>
                    </div>
                </div>
                <div class="bg-circle bg-orange"></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="stat-card">
                <h5 class="fw-bold mb-4">Spending Analysis <span class="text-muted fs-6 fw-normal ms-2">(Last 6 Months)</span></h5>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="stat-card">
                <h5 class="fw-bold mb-4">Reward Categories</h5>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="categoryChart"></canvas>
                </div>
                <div class="text-center mt-3">
                    <small class="text-muted">Distribution of your redemptions</small>
                </div>
            </div>
        </div>
    </div>

    

</div>

<script>
    // 1. Line Chart Configuration (Spending Trend)
    const ctxTrend = document.getElementById('trendChart').getContext('2d');
    
    // Gradient Fill Effect
    let gradient = ctxTrend.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(139, 92, 246, 0.5)'); // Purple Top
    gradient.addColorStop(1, 'rgba(139, 92, 246, 0.0)'); // Transparent Bottom

    new Chart(ctxTrend, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($trendLabels); ?>,
            datasets: [{
                label: 'Points Spent',
                data: <?php echo json_encode($trendData); ?>,
                borderColor: '#8b5cf6', // Purple Line
                backgroundColor: gradient,
                borderWidth: 3,
                tension: 0.4, // Smooth curves
                fill: true,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#8b5cf6',
                pointBorderWidth: 2,
                pointRadius: 6,
                pointHoverRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    padding: 12,
                    titleFont: { size: 13 },
                    bodyFont: { size: 14, weight: 'bold' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { borderDash: [5, 5], color: '#f1f5f9' },
                    ticks: { font: { family: "'Plus Jakarta Sans', sans-serif" } }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { family: "'Plus Jakarta Sans', sans-serif" } }
                }
            }
        }
    });

    // 2. Doughnut Chart Configuration (Category Mix)
    const ctxCat = document.getElementById('categoryChart').getContext('2d');
    new Chart(ctxCat, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($catLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($catData); ?>,
                backgroundColor: [
                    '#3b82f6', // Blue for Product
                    '#10b981', // Green for Voucher
                    '#f59e0b', // Orange
                    '#6366f1'  // Indigo
                ],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%', // Thinner ring
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { usePointStyle: true, padding: 20, font: { family: "'Plus Jakarta Sans'" } }
                }
            }
        }
    });
</script>


</body>
</html>