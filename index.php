<?php
session_start();

// If logged in, redirect to dashboard
if (isset($_SESSION['userID'])) {
    header("Location: view.php");
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>EcoTrip Challenge – Public Preview</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<style>
/* ================= PREVIEW MODULE STYLE ================= */
.preview-module {
    position: relative;
    background: #ffffff;
    border-radius: 1.25rem;
    box-shadow: 0 15px 40px rgba(0,0,0,0.08);
    overflow: hidden;
}

/* Blur only the content */
.preview-content {
    filter: blur(2.5px);
    opacity: 0.85;
    pointer-events: none;
    user-select: none;
}

/* Login button INSIDE module */
.preview-login-btn {
    position: absolute;
    bottom: 16px;
    right: 16px;
    background: rgba(0,0,0,0.75);
    color: #fff;
    padding: 8px 14px;
    font-size: 13px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    gap: 6px;
    backdrop-filter: blur(6px);
    box-shadow: 0 6px 18px rgba(0,0,0,0.25);
    z-index: 10;
}

.preview-login-btn:hover {
    background: rgba(0,0,0,0.9);
}

/* ===== LEADERBOARD CARD ===== */
.leaderboard-card {
    position: relative;
}

/* Crown positioning */
.crown {
    position: absolute;
    top: -16px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 26px;
    z-index: 5;
}

/* Crown colors */
.crown-gold   { color: #fbbf24; } /* 🥇 */
.crown-silver { color: #9ca3af; } /* 🥈 */
.crown-bronze { color: #fb923c; } /* 🥉 */

</style>
</head>

<body class="bg-green-50">

<!-- ================= HEADER ================= -->
<header class="bg-white px-8 py-4 shadow flex justify-between items-center">
    <h1 class="text-2xl font-bold text-green-700">EcoTrip Challenge</h1>
    <div class="space-x-4">
        <a href="login.php" class="font-semibold text-green-700">Login</a>
        <a href="register.php"
           class="bg-green-600 text-white px-4 py-2 rounded-lg">
            Register
        </a>
    </div>
</header>

<!-- ================= HERO ================= -->
<section class="bg-gradient-to-r from-green-500 to-emerald-600 text-white text-center py-20">
    <h2 class="text-4xl font-bold mb-4">
        Travel Smarter. Live Greener.
    </h2>
    <p class="max-w-3xl mx-auto text-lg">
        EcoTrip Challenge is a gamified sustainability platform where users complete
        eco-friendly challenges, earn points, compete on leaderboards,
        and redeem rewards.
    </p>
</section>

<!-- ================= CONTENT ================= -->
<div class="max-w-7xl mx-auto px-6 py-20 space-y-24">

<!-- ================= CHALLENGE PREVIEW MODULE ================= -->
<section class="preview-module bg-gray-50 p-8 mt-16">
    <h3 class="text-2xl font-bold mb-6">🎁 Challenge</h3>

    <!-- ===== BLURRED CONTENT ===== -->
    <div class="preview-content">

        <!-- Green Header -->
        <div class="bg-gradient-to-r from-green-500 to-emerald-600
                    rounded-3xl p-16 text-center text-white mb-10">
            <h2 class="text-4xl font-bold mb-2">Make an Impact</h2>
            <p class="text-lg">
                Small actions today, big changes for tomorrow.
            </p>
        </div>

        <!-- Filter Bar -->
        <div class="bg-white rounded-2xl shadow p-6 flex gap-4 mb-10">
            <input type="text"
                   class="flex-1 border rounded-lg px-4 py-3"
                   placeholder="Type & Press Enter...">

            <select class="border rounded-lg px-4 py-3">
                <option>All Categories</option>
            </select>

            <select class="border rounded-lg px-4 py-3">
                <option>All Cities</option>
            </select>

            <button class="bg-blue-600 text-white px-6 rounded-lg flex items-center gap-2">
                🔍 Filter
            </button>
        </div>

        <!-- Challenge Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

            <!-- Card 1 -->
            <div class="bg-white rounded-2xl shadow overflow-hidden">
                <img src="https://images.unsplash.com/photo-1544025162-d76694265947"
                     class="h-48 w-full object-cover">

                <div class="p-6">
                    <span class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full">
                        Public Transport
                    </span>

                    <h3 class="font-bold text-lg mt-4">
                        Penang Ferry Fun
                    </h3>

                    <p class="text-gray-500 text-sm mt-2">
                        Experience the heritage of Penang by crossing the channel on the
                        iconic ferry — a cleaner way to travel.
                    </p>

                    <div class="flex justify-between items-center mt-4">
                        <span class="bg-orange-100 text-orange-600 px-3 py-1 rounded-full text-sm">
                            ⭐ 80 pts
                        </span>
                        <span class="bg-blue-100 text-blue-600 px-3 py-1 rounded-full text-sm">
                            📍 Penang
                        </span>
                    </div>
                </div>
            </div>

            <!-- Card 2 -->
            <div class="bg-white rounded-2xl shadow overflow-hidden">
                <img src="https://images.unsplash.com/photo-1529070538774-1843cb3265df"
                     class="h-48 w-full object-cover">

                <div class="p-6">
                    <span class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full">
                        Green Living
                    </span>

                    <h3 class="font-bold text-lg mt-4">
                        Seremban Local Eat
                    </h3>

                    <p class="text-gray-500 text-sm mt-2">
                        Support small local businesses instead of international fast-food chains.
                    </p>

                    <div class="flex justify-between items-center mt-4">
                        <span class="bg-orange-100 text-orange-600 px-3 py-1 rounded-full text-sm">
                            ⭐ 75 pts
                        </span>
                        <span class="bg-blue-100 text-blue-600 px-3 py-1 rounded-full text-sm">
                            📍 Seremban
                        </span>
                    </div>
                </div>
            </div>

            <!-- Card 3 -->
            <div class="bg-white rounded-2xl shadow overflow-hidden">
                <img src="https://images.unsplash.com/photo-1501004318641-b39e6451bec6"
                     class="h-48 w-full object-cover">

                <div class="p-6">
                    <span class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full">
                        Nature & Conservation
                    </span>

                    <h3 class="font-bold text-lg mt-4">
                        Ipoh Cave Explore
                    </h3>

                    <p class="text-gray-500 text-sm mt-2">
                        Visit limestone caves such as Perak Tong to appreciate natural geology.
                    </p>

                    <div class="flex justify-between items-center mt-4">
                        <span class="bg-orange-100 text-orange-600 px-3 py-1 rounded-full text-sm">
                            ⭐ 80 pts
                        </span>
                        <span class="bg-blue-100 text-blue-600 px-3 py-1 rounded-full text-sm">
                            📍 Ipoh
                        </span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ===== LOGIN CTA (VISIBLE & CLICKABLE) ===== -->
    <a href="login.php" class="preview-login-btn">
        🔒 Login to interact
    </a>

</section>


<!-- ================================================= -->
<!-- LEADERBOARD PREVIEW -->
<!-- ================================================= -->
<section class="preview-module p-10">
    <h3 class="text-2xl font-bold mb-6">🏆 Leaderboard</h3>

    <div class="preview-content">

        <div class="text-center mb-10">
            <p class="text-gray-500">
                Top performers in our eco-challenge community
            </p>
        </div>

        <div class="flex justify-center gap-10 mb-12">
            <div class="border rounded-2xl w-44 text-center p-6 shadow">
                <img src="https://i.pravatar.cc/100?img=12"
                     class="mx-auto rounded-full mb-3">
                <div class="font-semibold">yx</div>
                <div class="text-green-600 font-bold">90 pts</div>
                <div class="text-sm text-gray-500">Blue Team</div>
            </div>

            <div class="border-2 border-yellow-400 bg-green-50 rounded-2xl w-48 text-center p-6 shadow-lg scale-110">
                <img src="https://i.pravatar.cc/100?img=32"
                     class="mx-auto rounded-full mb-3">
                <div class="font-semibold">xx</div>
                <div class="text-green-600 font-bold text-lg">400 pts</div>
                <div class="text-sm text-gray-500">Blue Team</div>
            </div>

            <div class="border rounded-2xl w-44 text-center p-6 shadow">
                <img src="https://i.pravatar.cc/100?img=5"
                     class="mx-auto rounded-full mb-3">
                <div class="font-semibold">cy</div>
                <div class="text-green-600 font-bold">80 pts</div>
                <div class="text-sm text-gray-500">Blue Team</div>
            </div>
        </div>

        <div class="bg-green-600 text-white rounded-full px-8 py-4 flex justify-between items-center">
            <span>You Currently Rank</span>
            <span class="font-bold">80 pts • #3</span>
        </div>

    </div>

    <a href="login.php" class="preview-login-btn">
        <i class="bi bi-lock-fill"></i> Login to interact
    </a>
</section>

<!-- ================= REWARDS PREVIEW MODULE ================= -->
<section class="preview-module bg-gray-50 p-10 mt-24">

    <!-- ===== BLURRED CONTENT ===== -->
    <div class="preview-content">

        <!-- Header -->
        <h2 class="text-2xl font-bold mb-8">Rewards Center</h2>

        <!-- Top Info Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">

            <!-- Wallet -->
            <div class="bg-gradient-to-r from-slate-800 to-slate-700 text-white
                        rounded-2xl p-6 flex items-center gap-4">
                <img src="https://i.pravatar.cc/60?img=5"
                     class="rounded-full border-2 border-white">
                <div>
                    <div class="font-semibold">Hi, cy c</div>
                    <div class="text-sm opacity-80">Your available points</div>
                    <div class="text-3xl font-bold text-green-400 mt-1">0</div>
                </div>
            </div>

            <!-- History -->
            <div class="bg-white rounded-2xl p-6 shadow">
                <div class="font-semibold">History</div>
                <div class="text-sm text-gray-500">Redemption logs</div>
            </div>

            <!-- Vouchers -->
            <div class="bg-white rounded-2xl p-6 shadow">
                <div class="font-semibold">My Vouchers</div>
                <div class="text-sm text-gray-500">Active tickets</div>
            </div>

            <!-- Products -->
            <div class="bg-white rounded-2xl p-6 shadow">
                <div class="font-semibold">My Products</div>
                <div class="text-3xl font-bold text-yellow-500 mt-1">0</div>
                <div class="text-sm text-gray-500">Total products claimed</div>
            </div>

        </div>

        <!-- Filters -->
        <div class="flex justify-between items-center mb-10">

            <div class="flex gap-3">
                <button class="px-6 py-2 rounded-full bg-green-100 text-green-700 font-semibold">
                    Available
                </button>
                <button class="px-6 py-2 rounded-full bg-gray-100 text-gray-500">
                    Unavailable
                </button>
            </div>

            <div class="flex gap-3">
                <button class="px-5 py-2 rounded-full bg-blue-100 text-blue-700 font-semibold">
                    All Items
                </button>
                <button class="px-5 py-2 rounded-full bg-gray-100 text-gray-500">
                    Vouchers
                </button>
                <button class="px-5 py-2 rounded-full bg-gray-100 text-gray-500">
                    Products
                </button>
            </div>

        </div>

        <!-- Rewards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

            <!-- Reward Card -->
            <div class="bg-white rounded-2xl shadow overflow-hidden">
                <img src="https://images.unsplash.com/photo-1585386959984-a4155224a1ad"
                     class="h-48 w-full object-cover">

                <div class="p-6">
                    <span class="text-xs bg-gray-100 px-3 py-1 rounded-full">
                        PRODUCT
                    </span>

                    <h3 class="font-bold mt-4">Pen</h3>

                    <div class="flex justify-between text-sm mt-2">
                        <span>10 pts</span>
                        <span class="text-gray-400">Need 10 pts</span>
                    </div>

                    <div class="h-2 bg-gray-200 rounded-full mt-3"></div>

                    <button class="w-full mt-4 py-2 bg-blue-50 text-blue-600 rounded-lg">
                        View Details
                    </button>
                </div>
            </div>

            <!-- Reward Card -->
            <div class="bg-white rounded-2xl shadow overflow-hidden">
                <img src="https://images.unsplash.com/photo-1512436991641-6745cdb1723f"
                     class="h-48 w-full object-cover">

                <div class="p-6">
                    <span class="text-xs bg-gray-100 px-3 py-1 rounded-full">
                        VOUCHER
                    </span>

                    <h3 class="font-bold mt-4">Nike 20%</h3>

                    <div class="flex justify-between text-sm mt-2">
                        <span>10 pts</span>
                        <span class="text-gray-400">Need 10 pts</span>
                    </div>

                    <div class="h-2 bg-gray-200 rounded-full mt-3"></div>

                    <button class="w-full mt-4 py-2 bg-blue-50 text-blue-600 rounded-lg">
                        View Details
                    </button>
                </div>
            </div>

            <!-- Reward Card -->
            <div class="bg-white rounded-2xl shadow overflow-hidden">
                <img src="https://images.unsplash.com/photo-1522335789203-aabd1fc54bc9"
                     class="h-48 w-full object-cover">

                <div class="p-6">
                    <span class="text-xs bg-gray-100 px-3 py-1 rounded-full">
                        PRODUCT
                    </span>

                    <h3 class="font-bold mt-4">Mirror</h3>

                    <div class="flex justify-between text-sm mt-2">
                        <span>40 pts</span>
                        <span class="text-gray-400">Need 40 pts</span>
                    </div>

                    <div class="h-2 bg-gray-200 rounded-full mt-3"></div>

                    <button class="w-full mt-4 py-2 bg-blue-50 text-blue-600 rounded-lg">
                        View Details
                    </button>
                </div>
            </div>

        </div>

    </div>

    <!-- ===== LOGIN CTA ===== -->
    <a href="login.php" class="preview-login-btn">
        🔒 Login to interact
    </a>

</section>


<!-- ================================================= -->
<!-- TEAM PREVIEW -->
<!-- ================================================= -->
<section class="preview-module p-10">
    <h3 class="text-2xl font-bold mb-6">👥 Teams</h3>

    <div class="preview-content grid grid-cols-1 md:grid-cols-4 gap-6 text-center">
        <div class="bg-white p-5 rounded-xl shadow">Green Warriors</div>
        <div class="bg-white p-5 rounded-xl shadow">Eco Rangers</div>
        <div class="bg-white p-5 rounded-xl shadow">Zero Waste Crew</div>
        <div class="bg-white p-5 rounded-xl shadow">Planet Protectors</div>
    </div>

    <a href="login.php" class="preview-login-btn">
        <i class="bi bi-lock-fill"></i> Login to interact
    </a>
</section>

</div>

<!-- ================= FOOTER ================= -->
<footer class="bg-white border-t text-center py-6 text-sm text-gray-500">
    © <?= date('Y') ?> EcoTrip Challenge. All rights reserved.
</footer>

</body>
</html>
