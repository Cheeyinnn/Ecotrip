<?php
// ------------------------------------------------------
// 1. SETUP & AUTH
// ------------------------------------------------------
require "db_connect.php";
require "includes/auth.php";

// ADMIN ONLY CHECK
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash'] = "Access denied.";
    header("Location: view.php");
    exit;
}

// ------------------------------------------------------
// 2. FETCH DATA
// ------------------------------------------------------
$challengeID = intval($_GET['id'] ?? 0);

if ($challengeID <= 0) {
    $_SESSION['flash'] = "Invalid Challenge ID.";
    header("Location: manage.php");
    exit;
}

// Execute Query
$stmt = $conn->prepare("SELECT * FROM challenge WHERE challengeID = ? LIMIT 1");
$stmt->bind_param("i", $challengeID);
$stmt->execute();
$result = $stmt->get_result();

// --- FIX: RENAME $row TO $challengeData TO PREVENT CONFLICTS ---
$challengeData = $result->fetch_assoc(); 
$stmt->close();

// Check if Challenge Exists
if (!$challengeData) {
    $_SESSION['flash'] = "Error: Challenge not found.";
    header("Location: manage.php");
    exit;
}

// ------------------------------------------------------
// 3. PREPARE VARIABLES
// ------------------------------------------------------
// We extract data using the unique variable name
$dbtitle       = $challengeData['challengeTitle']; 
$description = $challengeData['description'];
$city        = $challengeData['city'];
$points      = $challengeData['pointAward'];
$status      = $challengeData['is_active'];
$currentCat  = $challengeData['categoryID'];
$creatorID   = $challengeData['created_by'];

// Fix Date Format
$startDate   = !empty($challengeData['start_date']) ? date('Y-m-d', strtotime($challengeData['start_date'])) : '';
$endDate     = !empty($challengeData['end_date'])   ? date('Y-m-d', strtotime($challengeData['end_date']))   : '';

// Fetch Categories for Dropdown
$categories = [];
$resCat = $conn->query("SELECT categoryID, categoryName FROM category ORDER BY categoryName ASC");
// Note: We use $cat here, which is safe.
while ($cat = $resCat->fetch_assoc()) {
    $categories[] = $cat;
}

$pageTitle = "Edit Challenge";
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

include "includes/layout_start.php"; 
// ^^^ If this file used $row, it won't break our $challengeData anymore.
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Poppins', sans-serif; background-color: #f4f7f6; }
    
    .hero-mini {
        background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        padding: 40px 0 80px; color: white;
        border-radius: 0 0 40px 40px; margin-bottom: -50px;
        box-shadow: 0 10px 20px rgba(52, 152, 219, 0.2);
    }
    .form-card {
        background: white; border-radius: 20px; padding: 2.5rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: none; position: relative;
    }
    .form-label { font-weight: 600; color: #2c3e50; font-size: 0.9rem; margin-bottom: 0.5rem; }
    .form-control, .form-select {
        border-radius: 12px; border: 1px solid #e0e0e0; padding: 0.8rem 1rem;
        font-size: 0.95rem; transition: all 0.3s; background-color: #fcfcfc;
    }
    .form-control:focus, .form-select:focus {
        border-color: #3498db; box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.1); background-color: white;
    }
    .input-group-text {
        background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 12px 0 0 12px; color: #6c757d;
    }
    .form-control-points { border-radius: 0 12px 12px 0; }
    .btn-submit {
        background: #3498db; color: white; font-weight: 600; padding: 1rem 2rem;
        border-radius: 50px; border: none; box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
        transition: transform 0.2s; width: 100%; font-size: 1.1rem;
    }
    .btn-submit:hover { background: #2980b9; transform: translateY(-2px); }
    .btn-back {
        background: rgba(255,255,255,0.2); color: white; font-weight: 600;
        padding: 0.5rem 1.5rem; border-radius: 50px; text-decoration: none;
        transition: background 0.2s; backdrop-filter: blur(5px);
    }
    .btn-back:hover { background: rgba(255,255,255,0.3); color: white; }
    .form-section-title {
        color: #3498db; font-weight: 700; font-size: 1.1rem; margin-bottom: 1.5rem;
        padding-bottom: 0.5rem; border-bottom: 2px solid #f0f2f5; display: flex; align-items: center; gap: 10px;
    }
</style>

<div class="hero-mini">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <h2 class="fw-bold m-0"> <?= htmlspecialchars($dbtitle) ?></h2>
            <p class="m-0 opacity-75 small">Update details for challenge #<?= $challengeID ?></p>
        </div>
        <a href="manage.php" class="btn-back">
            <i class="bi bi-arrow-left"></i> Cancel
        </a>
    </div>
</div>

<div class="container pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <?php if ($flash): ?>
                <div class="alert alert-info rounded-3 shadow-sm mb-4 border-0 mt-4">
                    <i class="bi bi-info-circle-fill me-2"></i> <?= htmlspecialchars($flash) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="challenge_update.php" class="form-card mt-5">

                <input type="hidden" name="challengeID" value="<?= $challengeID ?>">

                <div class="form-section-title">
                    <i class="bi bi-pencil-square"></i> Basic Details
                </div>

                <div class="mb-4">
                    <label class="form-label">Challenge Title *</label>
                    <input type="text" name="challengeTitle" required class="form-control" 
                           value="<?= htmlspecialchars($dbtitle) ?>">
                </div>

                <div class="mb-4">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($description) ?></textarea>
                </div>

                <div class="form-section-title mt-5">
                    <i class="bi bi-sliders"></i> Configuration
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Category</label>
                        <select name="categoryID" class="form-select">
                            <option value="">-- Select Existing --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['categoryID'] ?>"
                                    <?= $currentCat == $cat['categoryID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['categoryName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Create New Category</label>
                        <input type="text" name="newCategory" class="form-control" 
                               placeholder="e.g. Solar Energy">
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">City</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                            <input type="text" name="city" class="form-control form-control-points"
                                   value="<?= htmlspecialchars($city) ?>">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Points Award *</label>
                        <div class="input-group">
                            <span class="input-group-text text-warning"><i class="bi bi-star-fill"></i></span>
                            <input type="number" name="pointAward" min="0" required class="form-control form-control-points"
                                   value="<?= htmlspecialchars($points) ?>">
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Status *</label>
                    <select name="is_active" class="form-select" required>
                        <option value="1" <?= $status == 1 ? 'selected' : '' ?>>Active (Visible)</option>
                        <option value="0" <?= $status == 0 ? 'selected' : '' ?>>Inactive (Hidden)</option>
                    </select>
                </div>

                <div class="form-section-title mt-5">
                    <i class="bi bi-clock"></i> Timeline
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control"
                               value="<?= $startDate ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control"
                               value="<?= $endDate ?>">
                    </div>
                </div>

                <input type="hidden" name="created_by" value="<?= $creatorID ?>">

                <hr class="my-4 text-muted opacity-25">

                <button class="btn-submit">
                    <i class="bi bi-check-lg me-2"></i> Save Changes
                </button>

            </form>

        </div>
    </div>
</div>

<?php include "includes/layout_end.php"; ?>