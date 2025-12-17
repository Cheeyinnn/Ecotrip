<?php
session_start();

/* Redirect logged-in users (same as login.php) */
if (isset($_SESSION['userID'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: manage.php');
    } elseif ($_SESSION['role'] === 'moderator') {
        header('Location: moderator.php');
    } else {
        header('Location: view.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>EcoTrip Challenge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

<style>
body {
    background: linear-gradient(135deg, #e6f7f1, #f9fff9);
    font-family: Inter, Arial, sans-serif;
}

/* ================= HERO ================= */
.hero {
    padding: 90px 20px 40px;
    text-align: center;
}

/* ================= LEADERBOARD PREVIEW ================= */
.preview-wrapper {
    max-width: 1100px;
    margin: auto;
    position: relative;
    cursor: pointer;
}

.preview-card {
    background: #ffffff;
    border-radius: 26px;
    padding: 40px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.12);
    transition: all 0.25s ease;
}

/* SOFT BLUR (VISIBLE) */
.preview-locked {
    filter: blur(3px);
    opacity: 0.9;
}

/* Hover hint */
.preview-wrapper:hover .preview-card {
    box-shadow: 0 20px 50px rgba(0,0,0,0.18);
}

/* Lock hint */
.lock-hint {
    position: absolute;
    bottom: 18px;
    right: 22px;
    background: rgba(0,0,0,0.65);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* ================= RANK CARDS ================= */
.rank-card {
    background: #fff;
    border-radius: 22px;
    padding: 30px 20px;
    position: relative;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}
.rank-card.gold   { border: 2px solid #facc15; }
.rank-card.silver { border: 2px solid #cbd5e1; }
.rank-card.bronze { border: 2px solid #fb923c; }

.avatar {
    width: 90px;
    height: 90px;
    border-radius: 50%;
}

.rank-badge {
    position: absolute;
    top: 78px;
    right: calc(50% - 15px);
    width: 30px;
    height: 30px;
    border-radius: 50%;
    color: white;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
}
.rank-badge.gold { background:#facc15; }
.rank-badge.silver { background:#94a3b8; }
.rank-badge.bronze { background:#fb923c; }

.crown {
    position:absolute;
    top:-18px;
    left:calc(50% - 17px);
    font-size:34px;
}
.crown.gold { color:#facc15; }
.crown.silver { color:#94a3b8; }
.crown.bronze { color:#fb923c; }

.points {
    color:#16a34a;
    font-weight:700;
}

.current-rank-bar {
    background: linear-gradient(90deg, #22c55e, #16a34a);
    border-radius: 50px;
    padding: 18px 26px;
    color: white;
    display: flex;
    align-items: center;
}

.rank-circle {
    width:36px;
    height:36px;
    border-radius:50%;
    background:rgba(255,255,255,0.25);
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
}
</style>
</head>

<body>

<!-- ================= HERO ================= -->
<section class="hero container">
    <iconify-icon icon="solar:leaf-bold-duotone" width="70" class="text-success"></iconify-icon>
    <h1 class="fw-bold mt-3">EcoTrip Challenge</h1>
    <p class="lead text-muted">
        Compete, collaborate, and earn rewards for sustainable actions.
    </p>
</section>

<!-- ================= LEADERBOARD PREVIEW ================= -->
<section class="container mb-5">

<div class="preview-wrapper" data-bs-toggle="modal" data-bs-target="#loginModal">

    <div class="preview-card preview-locked">

        <div class="text-center mb-4">
            <iconify-icon icon="solar:cup-star-bold-duotone" width="48" class="text-success"></iconify-icon>
            <h2 class="fw-bold mt-2">Leaderboard</h2>
            <p class="text-muted">Top performers in our eco-challenge community</p>
        </div>

        <div class="row justify-content-center align-items-end g-4 mb-5">
            <div class="col-md-3 text-center">
                <div class="rank-card silver">
                    <iconify-icon icon="solar:crown-bold-duotone" class="crown silver"></iconify-icon>
                    <img src="https://i.pravatar.cc/100?img=12" class="avatar">
                    <div class="rank-badge silver">2</div>
                    <h5 class="mt-3">yx</h5>
                    <div class="points">90 pts</div>
                    <small>Blue Team</small>
                </div>
            </div>

            <div class="col-md-3 text-center">
                <div class="rank-card gold">
                    <iconify-icon icon="solar:crown-bold-duotone" class="crown gold"></iconify-icon>
                    <img src="https://i.pravatar.cc/100?img=32" class="avatar">
                    <div class="rank-badge gold">1</div>
                    <h5 class="mt-3">xx</h5>
                    <div class="points">400 pts</div>
                    <small>Blue Team</small>
                </div>
            </div>

            <div class="col-md-3 text-center">
                <div class="rank-card bronze">
                    <iconify-icon icon="solar:crown-bold-duotone" class="crown bronze"></iconify-icon>
                    <img src="https://i.pravatar.cc/100?img=56" class="avatar">
                    <div class="rank-badge bronze">3</div>
                    <h5 class="mt-3">cy</h5>
                    <div class="points">80 pts</div>
                    <small>Blue Team</small>
                </div>
            </div>
        </div>

        <div class="current-rank-bar">
            <div class="d-flex align-items-center gap-3">
                <img src="https://i.pravatar.cc/50?img=56" class="rounded-circle">
                <strong>You Currently Rank</strong>
            </div>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span>80 pts</span>
                <span class="rank-circle">3</span>
            </div>
        </div>
    </div>

    <div class="lock-hint">
        <iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon>
        Login to interact
    </div>

</div>
</section>

<!-- ================= LOGIN MODAL ================= -->
<div class="modal fade" id="loginModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-body p-4 text-center">
        <iconify-icon icon="solar:lock-keyhole-bold-duotone"
                      width="48" class="text-success mb-3"></iconify-icon>
        <h4 class="fw-bold">Login Required</h4>
        <p class="text-muted mb-4">
            Sign in to view full leaderboard, join teams, and earn rewards.
        </p>

        <a href="login.php" class="btn btn-success w-100 mb-2">Login</a>
        <a href="register.php" class="btn btn-outline-success w-100">Create Account</a>
      </div>
    </div>
  </div>
</div>

<footer class="text-center text-muted py-4 border-top">
    © <?= date('Y') ?> EcoTrip Challenge
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
