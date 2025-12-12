<?php
// --------------------------------------------------
// 1. SETUP & AUTH
// --------------------------------------------------
require "db_connect.php";
require "includes/auth.php";

// Validate ID
if (!isset($_GET['id']) || intval($_GET['id']) <= 0) {
    $_SESSION['flash'] = "Invalid challenge ID.";
    header("Location: view.php");
    exit;
}
$challengeID = intval($_GET['id']);

// Fetch Data
$stmt = $conn->prepare("
    SELECT c.*, cat.categoryName, CONCAT(u.firstName, ' ', u.lastName) AS creatorName
    FROM challenge c
    LEFT JOIN category cat ON c.categoryID = cat.categoryID
    LEFT JOIN user u ON c.created_by = u.userID
    WHERE c.challengeID = ? LIMIT 1
");
$stmt->bind_param("i", $challengeID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['flash'] = "Challenge not found.";
    header("Location: view.php");
    exit;
}
$challenge = $result->fetch_assoc();
$stmt->close();

$_SESSION['challengeID'] = $challenge['challengeID'];

// --------------------------------------------------
// 2. EXACT IMAGE LOGIC
// --------------------------------------------------
function getExactHeroImage($title) {
    // 1. Clean the title
    $t = strtolower(trim($title));

    switch ($t) {
        // --- 🔴 EXACT MATCHES ---
       // --- CATEGORY: TRANSPORT ---
        case 'bike to work':            return 'bicycle';
        case 'walk the last mile':      return 'sneakers';
        case 'carpool crew':            return 'automobile'; 
        case 'public bus adventure':    return 'bus vehicle';

        // --- CATEGORY: WASTE ---
        case 'say no to straws':        return 'iced drinks '; 
        case 'bring your bottle':       return 'reusable water bottle';
        case 'tote bag shopper':        return 'reuseable bag';
        case 'compost your scraps':     return 'compost bin';

        // --- CATEGORY: FOOD ---
        case 'meatless monday':         return 'vegetables';
        case 'support local farmers':   return 'farmers market';
        case 'vegan meal challenge':    return 'vegetables';
        case 'love your leftovers':     return 'food container';

        // --- CATEGORY: ENERGY & WATER ---
        case 'unplug the vampires':     return 'electrical socket';
        case 'cold wash cycle':         return 'washing machine';
        case 'air dry laundry':         return 'clothesline';
        case '5-minute shower':         return 'shower head';

        // --- CATEGORY: NATURE ---
        case 'plant a tree':            return 'planting tree';
        case 'litter pickup':           return 'garbage';
        case 'clean ocean promise':     return 'clean beach';
        case 'wild bird watch':         return 'bird';

        // --- FALLBACKS ---
        default:
            if (strpos($t, 'transport') !== false) return 'traffic';
            if (strpos($t, 'food') !== false)      return 'fruit';
            if (strpos($t, 'water') !== false)     return 'water drop';
            if (strpos($t, 'energy') !== false)    return 'electricity';
            return 'nature'; 
    }
}

// Generate Hero URL
$searchTerm = getExactHeroImage($challenge['challengeTitle']);
$heroImage = "https://loremflickr.com/1200/500/" . urlencode($searchTerm) . "?lock=" . $challenge['challengeID'];
$pageTitle = $challenge['challengeTitle'];

include "includes/layout_start.php";
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">

<style>
    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f4f7f6;
    }

    /* --- HERO SECTION --- */
    .hero-wrapper {
        position: relative;
        height: 400px;
        margin-bottom: 5rem;
    }
    .hero-bg {
        background: url('<?= $heroImage ?>') no-repeat center center;
        background-size: cover;
        height: 100%;
        width: 100%;
        position: relative;
    }
    .hero-overlay {
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background: linear-gradient(to bottom, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.8) 100%);
        display: flex;
        align-items: flex-end;
        padding-bottom: 4rem;
    }
    .hero-title {
        color: white;
        font-weight: 800;
        font-size: 3.5rem;
        text-shadow: 0 4px 15px rgba(0,0,0,0.3);
        margin: 0;
    }

    /* --- FLOATING STATS BAR --- */
    .stats-floater {
        position: absolute;
        bottom: -40px;
        left: 50%;
        transform: translateX(-50%);
        width: 90%;
        max-width: 1000px;
        background: white;
        border-radius: 20px;
        padding: 1.5rem 2rem;
        box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-around;
        align-items: center;
        z-index: 10;
    }
    .stat-item {
        text-align: center;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    .stat-icon {
        width: 50px; height: 50px;
        border-radius: 12px;
        background: #e8f5e9; color: #27ae60;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
    }
    .stat-text h6 {
        margin: 0; color: #7f8c8d; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;
    }
    .stat-text span {
        font-size: 1.2rem; font-weight: 700; color: #2c3e50;
    }

    /* --- CONTENT AREA --- */
    .content-card {
        background: white;
        border-radius: 20px;
        padding: 2.5rem;
        box-shadow: 0 5px 20px rgba(0,0,0,0.03);
        margin-bottom: 2rem;
    }
    .section-title {
        font-weight: 700; color: #27ae60; margin-bottom: 1.5rem;
        display: flex; align-items: center; gap: 10px;
    }
    .desc-text {
        font-size: 1.1rem; line-height: 1.8; color: #555;
    }

    /* --- ACTION SIDEBAR --- */
    .action-card {
        background: white;
        border-radius: 20px;
        padding: 2rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        text-align: center;
        position: sticky;
        top: 2rem;
    }
    .points-circle {
        width: 120px; height: 120px;
        background: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
        color: white; border-radius: 50%;
        display: flex; flex-direction: column; justify-content: center; align-items: center;
        margin: 0 auto 1.5rem;
        box-shadow: 0 10px 20px rgba(253, 160, 133, 0.4);
    }
    .points-val { font-size: 2.5rem; font-weight: 800; line-height: 1; }
    .points-lbl { font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }

    /* Button Styles */
    .btn-submit {
        background: #27ae60; color: white; padding: 1rem; width: 100%;
        border-radius: 15px; font-weight: 700; font-size: 1.1rem;
        border: none; transition: transform 0.2s, box-shadow 0.2s;
        display: block; text-decoration: none;
    }
    .btn-submit:hover {
        background: #219150; transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(39, 174, 96, 0.3); color: white;
    }

    .btn-back {
        margin-top: 1rem; color: #95a5a6; font-weight: 600;
        text-decoration: none; display: inline-block; transition: color 0.2s;
    }
    .btn-back:hover { color: #7f8c8d; }

    /* Map Frame */
    .map-frame {
        border-radius: 15px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    }
</style>

<div class="hero-wrapper">
    <div class="hero-bg">
        <div class="hero-overlay">
            <div class="container">
                <h1 class="hero-title"><?= htmlspecialchars($challenge['challengeTitle']) ?></h1>
            </div>
        </div>
    </div>
    
    <div class="stats-floater">
        
        <div class="stat-item">
            <div class="stat-icon"><i class="bi bi-tag"></i></div>
            <div class="stat-text">
                <h6>Category</h6>
                <span><?= htmlspecialchars($challenge['categoryName'] ?? 'General') ?></span>
            </div>
        </div>

        <div style="width:1px; height:40px; background:#eee;" class="d-none d-md-block"></div>

        <div class="stat-item">
            <div class="stat-icon" style="background:#e3f2fd; color:#2196f3;"><i class="bi bi-geo-alt"></i></div>
            <div class="stat-text">
                <h6>Location</h6>
                <span><?= htmlspecialchars($challenge['city'] ?: 'Anywhere') ?></span>
            </div>
        </div>

        <div style="width:1px; height:40px; background:#eee;" class="d-none d-md-block"></div>

        <div class="stat-item d-none d-md-flex">
            <div class="stat-icon" style="background:#fff3e0; color:#ff9800;"><i class="bi bi-person"></i></div>
            <div class="stat-text">
                <h6>Creator</h6>
                <span><?= htmlspecialchars($challenge['creatorName'] ?: 'Admin') ?></span>
            </div>
        </div>

    </div>
</div>

<div class="container">
    <div class="row">

        <div class="col-lg-8">
            
            <div class="content-card">
                <h3 class="section-title"><i class="bi bi-info-circle-fill"></i> About this Challenge</h3>
                <p class="desc-text">
                    <?= nl2br(htmlspecialchars($challenge['description'])) ?>
                </p>
            </div>

            <?php if (!empty($challenge['city'])): ?>
            <div class="content-card">
                <h3 class="section-title"><i class="bi bi-map-fill"></i> Location Map</h3>
                <div class="ratio ratio-21x9 map-frame">
                    <iframe
                        src="https://maps.google.com/maps?q=<?= urlencode($challenge['city']) ?>&output=embed"
                        loading="lazy"
                        style="border:0;">
                    </iframe>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <div class="col-lg-4">
            <div class="action-card">
                
                <div class="points-circle">
                    <div class="points-val"><?= $challenge['pointAward'] ?></div>
                    <div class="points-lbl">Points</div>
                </div>

                <h4 class="fw-bold mb-3 text-dark">Join the Movement</h4>
                <p class="text-muted small mb-4">Complete this challenge, upload your proof, and earn points towards your eco-goal.</p>

                <a href="submissionform.php" class="btn-submit">
                    <i class="bi bi-camera me-2"></i> Submit Proof
                </a>

                <div class="mt-4 pt-4 border-top">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Start Date</span>
                        <span class="fw-bold text-dark small">
                            <?= $challenge['start_date'] ? date("M d, Y", strtotime($challenge['start_date'])) : "Anytime" ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted small">End Date</span>
                        <span class="fw-bold text-danger small">
                            <?= $challenge['end_date'] ? date("M d, Y", strtotime($challenge['end_date'])) : "Ongoing" ?>
                        </span>
                    </div>
                </div>

                <a href="view.php" class="btn-back">
                    <i class="bi bi-arrow-left"></i> Back to Challenges
                </a>

            </div>
        </div>

    </div>
</div>

<?php include "includes/layout_end.php"; ?>