<?php
// -------------------------------------
// PAGE TITLE FOR TOPBAR
// -------------------------------------
$pageTitle = "EcoTrip Dashboard";

// -------------------------------------
// LOAD DB + AUTH FIRST
// -------------------------------------
// auth.php ALREADY runs session_start() and checks login,
// so we DO NOT call session_start() again here.

require "db_connect.php";
require "includes/auth.php";    // <-- MUST come BEFORE layout_start.php

// After auth.php, we safely have:
// $_SESSION['userID'], $_SESSION['firstName'], $_SESSION['role'], browser token, etc.

$userID = $_SESSION['userID'];

// -------------------------------------
// FETCH USER DATA FOR PAGE (OPTIONAL)
// layout_start.php ONLY prepares sidebar info
// but index.php needs full user details
// -------------------------------------
$stmt = $conn->prepare("
    SELECT firstName, lastName, email, role, avatarURL
    FROM user
    WHERE userID = ?
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Update role for sidebar
$_SESSION['role'] = $user['role'];

// -------------------------------------
// LAYOUT START (SIDEBAR + TOPBAR)
// -------------------------------------
include "includes/layout_start.php";
?>

<style>
/* ------------------------
   DASHBOARD VISUAL DESIGN
------------------------ */
.hero-card {
    background: linear-gradient(135deg, #008cff, #0056d6);
    border-radius: 20px;
    padding: 24px;
    color: white;
    box-shadow: 0 18px 45px rgba(37, 99, 235, 0.45);
}

.stat-card {
    border-radius: 18px;
    padding: 18px;
    background: #ffffff;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    margin-bottom: 18px;
}
.stat-card.pink   { background: #ffe4ef; }
.stat-card.purple { background: #e9ddff; }
.stat-card.mint   { background: #dff7f1; }

.stat-title { font-size: 13px; color: #6b7280; }
.stat-value { font-size: 24px; font-weight: 700; }

.chart-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 18px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
}

.chart-card-title {
    font-weight: 600;
    font-size: 16px;
}

.chart-subtitle {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 10px;
}
</style>


<!-- PAGE CONTENT STARTS HERE -->
<div>

    <!-- HERO WELCOME BANNER -->
    <div class="hero-card mb-4">
        <h4>Welcome, <?= htmlspecialchars($user['firstName']) ?>!</h4>
        <p>Track your EcoTrip performance, activities and rewards.</p>
    </div>

    <!-- DASHBOARD STAT BOXES -->
    <div class="row mb-4">

        <div class="col-xl-4 col-lg-6">
            <div class="stat-card pink">
                <div class="stat-title">Total Sales</div>
                <div class="stat-value">RM 24,500</div>
                <span class="text-muted small">+18% vs last month</span>
            </div>
        </div>

        <div class="col-xl-4 col-lg-6">
            <div class="stat-card purple">
                <div class="stat-title">Refunds</div>
                <div class="stat-value">RM 1,230</div>
                <span class="text-muted small">-5% vs last month</span>
            </div>
        </div>

        <div class="col-xl-4 col-lg-6">
            <div class="stat-card mint">
                <div class="stat-title">Total Earnings</div>
                <div class="stat-value">RM 76,300</div>
                <span class="text-muted small">Semester overview</span>
            </div>
        </div>

    </div>

    <!-- CHART AREA -->
    <div class="row">

        <!-- SALES PROFIT CHART -->
        <div class="col-xl-8 mb-3">
            <div class="chart-card">
                <div class="chart-card-title">Sales Profit</div>
                <div class="chart-subtitle">Last 7 months</div>
                <div id="salesChart" style="height:260px;"></div>
            </div>
        </div>

        <!-- DONUT CHART -->
        <div class="col-xl-4 mb-3">
            <div class="chart-card">
                <div class="chart-card-title">Product Sales</div>
                <div class="chart-subtitle">Top categories</div>
                <div id="productChart" style="height:260px;"></div>
            </div>
        </div>

    </div>

</div>

<!-- PAGE CONTENT ENDS HERE -->

<?php include "includes/layout_end.php"; ?>

<!-- CHARTS -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="autologout.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // AREA CHART
    new ApexCharts(document.querySelector("#salesChart"), {
        chart: { type: 'area', height: 260, toolbar: { show: false }},
        series: [{ name: 'Profit', data: [25,32,28,40,55,52,65] }],
        xaxis: { categories: ['Aug','Sep','Oct','Nov','Dec','Jan','Feb'] },
        stroke: { curve: 'smooth', width: 3 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.5, opacityTo: 0.05 }},
        colors: ['#008cff']
    }).render();

    // DONUT CHART
    new ApexCharts(document.querySelector("#productChart"), {
        chart: { type: 'donut', height: 260 },
        series: [36,22,17,25],
        labels: ['Modernize','Ample','Spike','MaterialM'],
        colors: ['#1d4ed8','#a855f7','#22c55e','#f97316'],
        legend: { position: 'bottom' }
    }).render();

});
</script>
