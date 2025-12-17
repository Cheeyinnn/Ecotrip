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

.preview-content {
    filter: none;
    opacity: 1;
    pointer-events: auto;
    user-select: auto;
}


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

.leaderboard-card {
    position: relative;
}

.crown {
    position: absolute;
    top: -16px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 26px;
    z-index: 5;
}

.crown-gold   { color: #fbbf24; }
.crown-silver { color: #9ca3af; }
.crown-bronze { color: #fb923c; }
</style>
</head>

<body class="bg-green-50">

<!-- ================= HEADER ================= -->
<header class="bg-white px-8 py-4 shadow flex justify-between items-center">
    <h1 class="text-2xl font-bold text-green-700">EcoTrip Challenge</h1>
    <div class="space-x-4">
        <a href="login.php" class="font-semibold text-green-700">Login</a>
        <a href="register.php" class="bg-green-600 text-white px-4 py-2 rounded-lg">
            Register
        </a>
    </div>
</header>

<!-- ================= HERO ================= -->
<section class="bg-gradient-to-r from-green-500 to-emerald-600 text-white text-center py-20">
    <h2 class="text-4xl font-bold mb-4">Travel Smarter. Live Greener.</h2>
    <p class="max-w-3xl mx-auto text-lg">
        EcoTrip Challenge is a gamified sustainability platform that turns real-world
        eco-friendly actions into points, rankings, and rewards.
    </p>
</section>

<!-- ================= SYSTEM INTRO ================= -->
<section class="bg-white py-16">
    <div class="max-w-5xl mx-auto px-6 text-center">
        <h2 class="text-3xl font-bold mb-4 text-green-700">
            What is EcoTrip Challenge?
        </h2>
        <p class="text-gray-600 leading-relaxed">
            EcoTrip Challenge is a web-based platform designed to encourage sustainable
            travel and lifestyle habits through gamification. Users complete real-world
            eco challenges, submit proof for verification, earn points, compete on
            leaderboards, and redeem rewards — all within a secure and transparent system.
        </p>
    </div>
</section>

<!-- ================= HOW IT WORKS ================= -->
<section class="bg-gray-50 py-14">
    <div class="max-w-6xl mx-auto px-6 grid grid-cols-1 md:grid-cols-5 gap-8 text-center">
        <div>
            <div class="text-3xl mb-2">📝</div>
            <h4 class="font-bold">Register</h4>
            <p class="text-sm text-gray-500">Create a verified account</p>
        </div>
        <div>
            <div class="text-3xl mb-2">🌱</div>
            <h4 class="font-bold">Choose Challenges</h4>
            <p class="text-sm text-gray-500">Browse eco activities</p>
        </div>
        <div>
            <div class="text-3xl mb-2">📸</div>
            <h4 class="font-bold">Submit Proof</h4>
            <p class="text-sm text-gray-500">Upload photo evidence</p>
        </div>
        <div>
            <div class="text-3xl mb-2">🏆</div>
            <h4 class="font-bold">Earn Points</h4>
            <p class="text-sm text-gray-500">Verified by moderators</p>
        </div>
        <div>
            <div class="text-3xl mb-2">🎁</div>
            <h4 class="font-bold">Get Rewards</h4>
            <p class="text-sm text-gray-500">Redeem points</p>
        </div>
    </div>
</section>

<!-- ================= CONTENT ================= -->
<div class="max-w-7xl mx-auto px-6 py-20">
    <h1 class="text-4xl font-bold text-center text-green-700 mb-16">
        Platform Preview (Funtion included in our system)
        </h1>

<!-- ================= CHALLENGE PREVIEW MODULE ================= -->
<section class="preview-module bg-gray-50 p-8 mt-16">
    <h3 class="text-2xl font-bold mb-6">🌱 Challenge</h3>

    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl">
    <h4 class="font-semibold text-green-700 mb-1">🌱 Challenge Module</h4>
    <p class="text-sm text-gray-600">
        Browse eco-friendly challenges provided by our system.
        Log in to join challenges and submit proof to earn points.
    </p>
</div>

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


<!-- ================= LEADERBOARD PREVIEW MODULE ================= -->
<section class="preview-module bg-white p-10 mt-24">
    <h3 class="text-2xl font-bold mb-2">🏆 Leaderboard</h3>

    <!-- MODULE EXPLANATION -->
    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-xl">
        <h4 class="font-semibold text-yellow-700 mb-1">🏆 Leaderboard Module</h4>
        <p class="text-sm text-gray-600">
            This module ranks users and teams based on verified points earned
            from completed challenges. Rankings update dynamically to ensure
            fairness and motivation.
        </p>
    </div>

    <div class="preview-content">

        <!-- Header -->
        <div class="text-center mb-10">
            <h2 class="text-3xl font-bold text-green-700 flex justify-center items-center gap-2">
                🏆 Leaderboard
            </h2>
            <p class="text-gray-500">
                Top performers in our eco-challenge community
            </p>
        </div>

        <!-- Tabs -->
        <div class="flex justify-between items-center mb-12">
            <div class="flex gap-2 bg-gray-100 p-2 rounded-full">
                <span class="px-4 py-1 bg-white rounded-full font-semibold text-green-600">
                    Users
                </span>
                <span class="px-4 py-1 text-gray-500">Teams</span>
            </div>

            <div class="flex gap-2 bg-gray-100 p-2 rounded-full">
                <span class="px-4 py-1 bg-white rounded-full font-semibold text-green-600">
                    All-Time
                </span>
                <span class="px-4 py-1 text-gray-500">Weekly</span>
                <span class="px-4 py-1 text-gray-500">Monthly</span>
            </div>
        </div>

        <!-- ===== TOP 3 USERS ===== -->
        <div class="flex justify-center gap-10 mb-14">

            <!-- 🥈 Rank 2 -->
            <div class="leaderboard-card border rounded-2xl w-44 text-center p-6 shadow">
                <div class="crown crown-silver">👑</div>

                <img src="https://i.pravatar.cc/100?img=12"
                     class="mx-auto rounded-full mb-3">

                <div class="font-semibold">yx</div>
                <div class="text-green-600 font-bold">90 pts</div>
                <div class="text-sm text-gray-500">Blue Team</div>
            </div>

            <!-- 🥇 Rank 1 -->
            <div class="leaderboard-card border-2 border-yellow-400 bg-green-50
                        rounded-2xl w-48 text-center p-6 shadow-lg scale-110">
                <div class="crown crown-gold">👑</div>

                <img src="https://i.pravatar.cc/100?img=32"
                     class="mx-auto rounded-full mb-3">

                <div class="font-semibold">xx</div>
                <div class="text-green-600 font-bold text-lg">400 pts</div>
                <div class="text-sm text-gray-500">Blue Team</div>
            </div>

            <!-- 🥉 Rank 3 -->
            <div class="leaderboard-card border rounded-2xl w-44 text-center p-6 shadow">
                <div class="crown crown-bronze">👑</div>

                <img src="https://i.pravatar.cc/100?img=5"
                     class="mx-auto rounded-full mb-3">

                <div class="font-semibold">cy</div>
                <div class="text-green-600 font-bold">80 pts</div>
                <div class="text-sm text-gray-500">Blue Team</div>
            </div>

        </div>

        <!-- ===== CURRENT USER RANK ===== -->
        <div class="bg-green-600 text-white rounded-full px-8 py-4 flex justify-between items-center shadow">
            <span>You Currently Rank</span>
            <span class="font-bold">80 pts • Rank #3</span>
        </div>

    </div>

    <!-- ===== LOGIN CTA (NOT BLURRED) ===== -->
    <a href="login.php" class="preview-login-btn">
        🔒 Login to interact
    </a>

</section>


<!-- ================= REWARDS PREVIEW MODULE ================= -->
<section class="preview-module bg-gray-50 p-10 mt-24">
    <h2 class="text-2xl font-bold mb-2">🎁 Rewards Center</h2>

    <!-- MODULE EXPLANATION -->
    <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-xl">
        <h4 class="font-semibold text-blue-700 mb-1">🎁 Rewards Module</h4>
        <p class="text-sm text-gray-600">
            Users can redeem accumulated points for vouchers or products.
            The system automatically manages stock availability and
            redemption status.
        </p>
    </div>

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

                    <h3 class="font-bold mt-4">Chanel</h3>

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

                    <h3 class="font-bold mt-4">Shirt</h3>

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


<!-- ================= TEAM OVERVIEW PREVIEW MODULE ================= -->
<section class="preview-module bg-gray-50 p-10 mt-24">
    <h2 class="text-2xl font-bold mb-2">👥 Team</h2>

    <!-- MODULE EXPLANATION -->
    <div class="mb-6 p-4 bg-purple-50 border border-purple-200 rounded-xl">
        <h4 class="font-semibold text-purple-700 mb-1">👥 Team Module</h4>
        <p class="text-sm text-gray-600">
            This module allows users to create or join teams.
            Team members collaborate, compete together, and
            contribute to team leaderboard rankings.
        </p>
    </div>

    <div class="preview-content">

        <!-- ================= TEAM HEADER ================= -->
        <div class="bg-slate-800 text-white rounded-2xl p-8 flex justify-between items-center">

            <div>
                <h2 class="text-3xl font-bold">Blue Team</h2>
                <p class="text-gray-300 mt-1 italic">
                    Descriptions: Welcome to join Blue Team!
                </p>

                <div class="flex gap-6 text-sm mt-6 text-gray-300">
                    <span><strong class="text-white">Owner:</strong> cy c</span>
                    <span><strong class="text-white">Members:</strong> 4</span>
                    <span><strong class="text-white">Created:</strong> Dec 17, 2025</span>
                </div>
            </div>

            <!-- Team Logo -->
            <div class="bg-white rounded-xl p-3 shadow">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/8/89/HD_transparent_picture.png/240px-HD_transparent_picture.png"
                     class="w-28 h-28 object-contain">
            </div>
        </div>

        <!-- ================= TEAM NAV TABS ================= -->
        <div class="flex gap-6 mt-8 border-b pb-4 text-blue-600">
            <span>Overview</span>
            <span class="font-semibold border-b-2 border-blue-600 pb-1">
                Members
            </span>
            <span>Settings</span>
            <span>Join Requests</span>
            <span class="flex items-center gap-1">
                Dashboard
            </span>
        </div>

        <!-- ================= MEMBER LIST ================= -->
        <div class="bg-white rounded-2xl shadow mt-8 overflow-hidden">

            <!-- Member Item -->
            <div class="flex justify-between items-center px-6 py-4 border-b">
                <div class="flex items-center gap-4">
                    <img src="https://i.pravatar.cc/60?img=5"
                         class="rounded-full">
                    <div>
                        <div class="font-semibold">
                            cy c <span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-600 text-xs rounded-full">You</span>
                        </div>
                        <span class="text-xs bg-gray-800 text-white px-2 py-0.5 rounded-full">
                            Owner
                        </span>
                        <div class="text-sm text-gray-500 mt-1">
                            Last online: Dec 18, 2025 02:36 AM
                        </div>
                    </div>
                </div>
            </div>

            <!-- Member Item -->
            <div class="flex justify-between items-center px-6 py-4 border-b">
                <div class="flex items-center gap-4">
                    <img src="https://i.pravatar.cc/60?img=15"
                         class="rounded-full">
                    <div>
                        <div class="font-semibold">kj y</div>
                        <span class="text-xs bg-gray-600 text-white px-2 py-0.5 rounded-full">
                            Member
                        </span>
                        <div class="text-sm text-gray-500 mt-1">
                            Last online: Dec 18, 2025 02:03 AM
                        </div>
                    </div>
                </div>

                <button class="text-red-500 border border-red-400 px-4 py-1 rounded">
                    Remove
                </button>
            </div>

            <!-- Member Item -->
            <div class="flex justify-between items-center px-6 py-4 border-b">
                <div class="flex items-center gap-4">
                    <img src="https://i.pravatar.cc/60?img=32"
                         class="rounded-full">
                    <div>
                        <div class="font-semibold">xx a</div>
                        <span class="text-xs bg-gray-600 text-white px-2 py-0.5 rounded-full">
                            Member
                        </span>
                        <div class="text-sm text-gray-500 mt-1">
                            Last online: Dec 18, 2025 01:56 AM
                        </div>
                    </div>
                </div>

                <button class="text-red-500 border border-red-400 px-4 py-1 rounded">
                    Remove
                </button>
            </div>

            <!-- Member Item -->
            <div class="flex justify-between items-center px-6 py-4">
                <div class="flex items-center gap-4">
                    <img src="https://i.pravatar.cc/60?img=12"
                         class="rounded-full">
                    <div>
                        <div class="font-semibold">yx f</div>
                        <span class="text-xs bg-gray-600 text-white px-2 py-0.5 rounded-full">
                            Member
                        </span>
                        <div class="text-sm text-gray-500 mt-1">
                            Last online: Dec 18, 2025 01:58 AM
                        </div>
                    </div>
                </div>

                <button class="text-red-500 border border-red-400 px-4 py-1 rounded">
                    Remove
                </button>
            </div>

        </div>

    </div>

    <!-- ===== LOGIN CTA ===== -->
    <a href="login.php" class="preview-login-btn">
        🔒 Login to interact
    </a>

</section>

<!-- ================= USER DASHBOARD PREVIEW MODULE ================= -->
<section class="preview-module bg-gray-50 p-10 mt-24">
    <h2 class="text-2xl font-bold mb-2">🎓 User Dashboard</h2>

    <!-- MODULE EXPLANATION -->
    <div class="mb-6 p-4 bg-slate-50 border border-slate-200 rounded-xl">
        <h4 class="font-semibold text-slate-700 mb-1">🎓 User Dashboard Module</h4>
        <p class="text-sm text-gray-600">
            This dashboard provides users with an overview of their activity,
            including submission status, earned points, rankings, and
            contribution analytics.
        </p>
    </div>

    <div class="preview-content">

        <!-- Header -->
        <h2 class="text-2xl font-bold mb-8">EcoTrip Dashboard</h2>

        <!-- Contribution Analytics -->
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-semibold">Contribution Analytics</h3>

            <div class="flex gap-3">
                <select class="border rounded-lg px-4 py-2 text-sm">
                    <option>All Time</option>
                    <option>Last 30 Days</option>
                    <option>Last 7 Days</option>
                </select>

                <button class="bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center gap-2">
                    🔄 Refresh
                </button>
            </div>
        </div>

        <!-- Analytics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

            <!-- Left: Bar Chart -->
            <div class="bg-white rounded-2xl shadow p-6">
                <div class="bg-green-50 text-green-700 text-center py-3 rounded-lg font-semibold mb-6">
                    Total Approved Submissions: 6
                </div>

                <h4 class="font-semibold mb-4">Top Challenges: Points Earned</h4>

                <!-- Fake chart bars (preview only) -->
                <div class="space-y-4">

                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Langkawi Geo Park</span>
                            <span>300</span>
                        </div>
                        <div class="h-3 bg-gray-200 rounded-full">
                            <div class="h-3 bg-green-500 rounded-full" style="width:100%"></div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Transport: KL Monorail</span>
                            <span>200</span>
                        </div>
                        <div class="h-3 bg-gray-200 rounded-full">
                            <div class="h-3 bg-green-500 rounded-full" style="width:67%"></div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Ipoh Cave Explore</span>
                            <span>160</span>
                        </div>
                        <div class="h-3 bg-gray-200 rounded-full">
                            <div class="h-3 bg-green-500 rounded-full" style="width:53%"></div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Right: Submission Status Distribution -->
<div class="bg-white rounded-2xl shadow p-6 text-center">

    <h4 class="font-semibold mb-6">
        Submission Status Distribution
    </h4>

    <!-- ===== STATIC RADAR DIAGRAM CONTAINER ===== -->
    <div class="flex justify-center">

        <div class="w-64 h-64">

            <svg viewBox="0 0 200 200" class="w-full h-full">

                <!-- Background triangle -->
                <polygon points="100,20 20,180 180,180"
                         fill="#ecfdf5"
                         stroke="#d1fae5"
                         stroke-width="2" />

                <!-- Data polygon (Approved=4, Pending=1, Denied=1) -->
                <polygon points="100,40 80,150 120,150"
                         fill="rgba(16,185,129,0.35)"
                         stroke="#10b981"
                         stroke-width="2" />

                <!-- Data points -->
                <circle cx="100" cy="40" r="5" fill="#10b981" />
                <circle cx="80" cy="150" r="5" fill="#f59e0b" />
                <circle cx="120" cy="150" r="5" fill="#ef4444" />

                <!-- Labels -->
                <text x="100" y="15" text-anchor="middle"
                      font-size="12" fill="#065f46">
                    Approved (4)
                </text>

                <text x="18" y="195" text-anchor="start"
                      font-size="12" fill="#92400e">
                    Pending (1)
                </text>

                <text x="182" y="195" text-anchor="end"
                      font-size="12" fill="#991b1b">
                    Denied (1)
                </text>

            </svg>

        </div>
    </div>

    <!-- ===== LEGEND ===== -->
    <div class="flex justify-center gap-6 text-sm mt-6">
        <span class="flex items-center gap-2">
            <span class="w-3 h-3 bg-green-500 rounded-full"></span>
            Approved
        </span>
        <span class="flex items-center gap-2">
            <span class="w-3 h-3 bg-yellow-400 rounded-full"></span>
            Pending
        </span>
        <span class="flex items-center gap-2">
            <span class="w-3 h-3 bg-red-400 rounded-full"></span>
            Denied
        </span>
    </div>

</div>


        </div>

    </div>

    <!-- ===== LOGIN CTA ===== -->
    <a href="login.php" class="preview-login-btn">
        🔒 Login to interact
    </a>

</section>

</div>

<!-- ================= FOOTER ================= -->
<footer class="bg-white border-t text-center py-6 text-sm text-gray-500">
    © <?= date('Y') ?> EcoTrip Challenge. All rights reserved.
</footer>

</body>
</html>
