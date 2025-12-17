<?php
// -------------------------------------
// 1. PAGE CONFIG & AUTH
// -------------------------------------
$pageTitle = "Ecotrip Challenges";
require "db_connect.php";
require "includes/auth.php"; 

// -------------------------------------
// 2. IMAGE LOGIC (Auto-Generator)
// -------------------------------------
function getExactHeroImage($title) {
    $t = strtolower(trim($title));

    switch ($t) {
        // --- EXACT MATCHES ---
        case 'bike to work':            return 'bicycle';
        case 'walk the last mile':      return 'sneakers';
        case 'carpool crew':            return 'automobile'; 
        case 'public bus adventure':    return 'bus';

        // --- CATEGORY MATCHES ---
        case 'say no to straws':        return 'drink'; 
        case 'bring your bottle':       return 'water bottle';
        case 'tote bag shopper':        return 'shopping bag';
        case 'compost your scraps':     return 'compost';

        // --- FOOD ---
        case 'meatless monday':         return 'vegetables';
        case 'support local farmers':   return 'farmers market';
        case 'vegan meal challenge':    return 'fruit';
        case 'love your leftovers':     return 'food container';

        // --- NATURE ---
        case 'plant a tree':            return 'planting tree';
        case 'litter pickup':           return 'garbage pickup';
        case 'clean ocean promise':     return 'beach clean';
        case 'wild bird watch':         return 'bird';

        // --- FALLBACKS ---
        default:
            if (strpos($t, 'transport') !== false) return 'traffic';
            if (strpos($t, 'food') !== false)      return 'fruit';
            if (strpos($t, 'water') !== false)     return 'water';
            if (strpos($t, 'energy') !== false)    return 'electricity';
            return 'nature'; 
    }
}

// -------------------------------------
// 3. FETCH DATA & FILTERS
// -------------------------------------
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter_category = isset($_GET['categoryID']) ? intval($_GET['categoryID']) : 0;
$filter_city = isset($_GET['city']) ? trim($_GET['city']) : '';

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Fetch Categories & Cities
$categories = [];
$cat_res = $conn->query("SELECT categoryID, categoryName FROM category ORDER BY categoryName ASC");
while ($r = $cat_res->fetch_assoc()) $categories[] = $r;

$cities = [];
$city_res = $conn->query("SELECT DISTINCT city FROM challenge WHERE city IS NOT NULL AND city != '' ORDER BY city ASC");
while ($r = $city_res->fetch_assoc()) $cities[] = $r;

// Build Query
$sql_base = "SELECT c.*, cat.categoryName FROM challenge c LEFT JOIN category cat ON c.categoryID = cat.categoryID";

// --- 🔴 FIXED DATE LOGIC (From previous fix) ---
$where_clauses = [
    "c.is_active = 1", 
    "(c.start_date IS NULL OR c.start_date <= CURDATE())", // Allow NULL start dates
    "(c.end_date IS NULL OR c.end_date >= CURDATE())"
]; 

if ($filter_category > 0) $where_clauses[] = "c.categoryID = $filter_category";
if ($filter_city !== '') {
    $safe_city = $conn->real_escape_string($filter_city);
    $where_clauses[] = "c.city = '$safe_city'";
}
if ($q !== '') {
    $safe_q = $conn->real_escape_string($q);
    $where_clauses[] = "(c.challengeTitle LIKE '%$safe_q%' OR cat.categoryName LIKE '%$safe_q%')";
}

$sql = $sql_base . " WHERE " . implode(" AND ", $where_clauses) . " ORDER BY c.start_date DESC, c.challengeID DESC";
$result = $conn->query($sql);

include "includes/layout_start.php";
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Poppins', sans-serif; background-color: #f4f7f6; }

    /* HERO HEADER */
    .hero-section {
        background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
        padding: 80px 0 100px;
        color: white; border-radius: 0 0 50px 50px; margin-bottom: 2rem;
        position: relative; text-align: center;
        box-shadow: 0 10px 30px rgba(39, 174, 96, 0.2);
    }
    .hero-title { font-weight: 700; font-size: 2.5rem; margin-bottom: 0.5rem; }
    .hero-subtitle { font-weight: 300; font-size: 1.1rem; opacity: 0.9; }

    /* FLOATING SEARCH BAR */
    .search-floater {
        margin-top: -70px; background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px); border-radius: 20px; padding: 1.5rem;
        box-shadow: 0 15px 35px rgba(0,0,0,0.1); margin-bottom: 3rem;
    }
    .form-control-modern, .form-select-modern {
        border: 2px solid #e9ecef; border-radius: 12px; padding: 0.7rem 1rem;
        font-size: 0.95rem; transition: all 0.3s;
    }
    .form-control-modern:focus, .form-select-modern:focus {
        border-color: #2ecc71; box-shadow: 0 0 0 4px rgba(46, 204, 113, 0.1);
    }

    /* MODERN CARDS */
    .challenge-card {
        border: none; border-radius: 20px; background: white;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        overflow: hidden; height: 100%; display: flex; flex-direction: column;
        box-shadow: 0 4px 6px rgba(0,0,0,0.02);
    }
    .challenge-card:hover { transform: translateY(-8px); box-shadow: 0 20px 40px rgba(0,0,0,0.08); }

    .card-img-wrapper { position: relative; height: 220px; overflow: hidden; }
    .card-img-top {
        width: 100%; height: 100%; object-fit: cover; transition: transform 0.6s ease;
    }
    .challenge-card:hover .card-img-top { transform: scale(1.08); }
    
    .category-float {
        position: absolute; top: 15px; left: 15px;
        background: rgba(255, 255, 255, 0.95); color: #1e8449;
        font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
        padding: 6px 14px; border-radius: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        letter-spacing: 0.5px; z-index: 2;
    }

    .card-content { padding: 1.5rem; display: flex; flex-direction: column; flex-grow: 1; }
    .card-title { font-weight: 700; color: #2c3e50; margin-bottom: 0.8rem; font-size: 1.25rem; }
    .card-desc {
        color: #7f8c8d; font-size: 0.9rem; line-height: 1.6; margin-bottom: 1.5rem;
        flex-grow: 1; display: -webkit-box; -webkit-line-clamp: 3;
        -webkit-box-orient: vertical; overflow: hidden;
    }

    .card-actions {
        display: flex; justify-content: space-between; align-items: center;
        margin-top: auto; padding-top: 1rem; border-top: 1px solid #f7f9fa;
    }
    .points-pill {
        background-color: #fff3e0; color: #e67e22; font-weight: 700;
        padding: 6px 12px; border-radius: 10px; font-size: 0.85rem;
        display: flex; align-items: center; gap: 5px;
    }
    .btn-arrow {
        width: 40px; height: 40px; border-radius: 50%; background: #f0f2f5;
        color: #2c3e50; display: flex; align-items: center; justify-content: center;
        transition: all 0.3s; text-decoration: none;
    }
    .challenge-card:hover .btn-arrow { background: #27ae60; color: white; }
    .top-nav-btn { position: absolute; top: 20px; right: 20px; z-index: 10; }
</style>

<div class="hero-section">
    <div class="top-nav-btn">
        <a href="index.php" class="btn btn-outline-light btn-sm rounded-pill px-3">Dashboard</a>
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <a href="manage.php" class="btn btn-light text-success btn-sm rounded-pill px-3 ms-2">Manage</a>
        <?php endif; ?>
    </div>
    <div class="container">
        <h1 class="hero-title">Make an Impact</h1>
        <p class="hero-subtitle">Small actions today, big changes for tomorrow.</p>
    </div>
</div>

<div class="container pb-5">
    <div class="search-floater">
        <form class="row g-3" method="get" action="view.php">
            <div class="col-lg-5">
                <input type="text" name="q" class="form-control form-control-modern" 
                       placeholder="Type & Press Enter..." value="<?= htmlspecialchars($q) ?>">
            </div>
            <div class="col-lg-3">
                <select name="categoryID" class="form-select form-select-modern" onchange="this.form.submit()">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['categoryID'] ?>" <?= $filter_category == $cat['categoryID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['categoryName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <select name="city" class="form-select form-select-modern" onchange="this.form.submit()">
                    <option value="">All Cities</option>
                    <?php foreach ($cities as $c): ?>
                        <option value="<?= htmlspecialchars($c['city']) ?>" <?= $filter_city === $c['city'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['city']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-grid">
                <?php if($q || $filter_category || $filter_city): ?>
                    <a href="view.php" class="btn btn-danger fw-bold rounded-3 text-white" style="border-radius: 12px;">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                <?php else: ?>
                     <button type="submit" class="btn btn-primary text-white fw-bold rounded-3" style="border-radius: 12px;">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-success rounded-4 shadow-sm mb-4 border-0 text-center">
            <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): 
                
                // -------------------------------------------------------------
                // 🟢 CUSTOM IMAGE LIST (EDIT HERE)
                // Format: Challenge_ID => 'URL',
                // -------------------------------------------------------------
                $custom_urls = [
                    30 => 'https://cj.my/wp-content/uploads/2023/03/the-iconic-penang-ferry-service-7-1300x500.jpg', 
                    45 => 'https://wallpapers.com/images/hd/food-4k-spdnpz7bhmx4kv2r.jpg',
                    41 => 'https://bangkokattractions.com/wp-content/uploads/2023/04/ipoh.jpg',
                    29 => 'https://ik.imagekit.io/tvlk/blog/2022/10/03-KL-Monorail-1024x683.jpg?tr=dpr-2,w-675',
                    44 => 'https://tse1.mm.bing.net/th/id/OIP.gfHJEWugJxMht6qauXJaRgHaEo?pid=Api&P=0&h=180',
                    42 => 'https://tse4.mm.bing.net/th/id/OIP.Onirx1aPQ5JvZ2QEhlv2hwHaEL?pid=Api&P=0&h=180',
                    37 => 'https://media.tacdn.com/media/attractions-content--1x-1/0b/39/b0/98.jpg',
                    35 => 'https://tse3.mm.bing.net/th/id/OIP.gRliVC74pBhaqeP7DTxj1wHaHa?pid=Api&P=0&h=180',
                    31 => 'https://tse4.mm.bing.net/th/id/OIP.Cts7VCGtR3PqXCt9lUaGCgHaCe?pid=Api&P=0&h=180',
                ];

                $cid = $row['challengeID'];

                if (array_key_exists($cid, $custom_urls)) {
                    // Use your custom link
                    $imgUrl = $custom_urls[$cid];
                } else {
                    // Auto-generate using keyword
                    $searchTerm = getExactHeroImage($row['challengeTitle']);
                    $imgUrl = "https://loremflickr.com/600/400/" . urlencode($searchTerm) . "?lock=" . $cid;
                }
            ?>
                <div class="col">
                    <div class="challenge-card">
                        
                        <div class="card-img-wrapper">
                            <span class="category-float">
                                <?= htmlspecialchars($row['categoryName'] ?? 'General') ?>
                            </span>
                            <img src="<?= $imgUrl ?>" class="card-img-top" alt="Challenge Image">
                        </div>

                        <div class="card-content">
                            <h3 class="card-title"><?= htmlspecialchars($row['challengeTitle']) ?></h3>
                            
                            <p class="card-desc">
                                <?= htmlspecialchars($row['description']) ?>
                            </p>

                            <div class="card-actions">
                                <div class="d-flex gap-2 align-items-center">
                                    <div class="points-pill">
                                        <i class="bi bi-star-fill"></i> <?= $row['pointAward'] ?> pts
                                    </div>

                                    <?php if (!empty($row['city'])): ?>
                                        <div class="points-pill" style="background-color: #e3f2fd; color: #0d47a1;">
                                            <i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($row['city']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <a href="challenge_details.php?id=<?= $row['challengeID'] ?>" class="btn-arrow" title="View Details">
                                    <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5">
                <div class="mb-3 text-muted opacity-25">
                    <i class="bi bi-search display-1"></i>
                </div>
                <h3 class="fw-bold text-muted">No challenges found</h3>
                <p class="text-secondary">Try adjusting your search filters.</p>
                <a href="view.php" class="btn btn-outline-success rounded-pill px-4 mt-2">Clear Filters</a> 
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include "includes/layout_end.php"; ?>