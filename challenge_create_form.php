<?php
// -------------------------------------
// PAGE TITLE & AUTH
// -------------------------------------
$pageTitle = "Create New Challenge";
require "db_connect.php";
require "includes/auth.php";

// ADMIN ONLY
if ($_SESSION['role'] !== 'admin') {
    $_SESSION['flash'] = "Access denied. Administrators only.";
    header("Location: view.php");
    exit;
}

// FETCH CATEGORIES
$categories = [];
$res = $conn->query("SELECT categoryID, categoryName FROM category ORDER BY categoryName ASC");
while ($row = $res->fetch_assoc()) {
    $categories[] = $row;
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

include "includes/layout_start.php";
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f4f7f6;
    }

    /* HEADER */
    .hero-mini {
        background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
        padding: 40px 0 80px;
        color: white;
        border-radius: 0 0 40px 40px;
        margin-bottom: -50px; /* Overlap effect */
        box-shadow: 0 10px 20px rgba(39, 174, 96, 0.2);
    }
    
    /* FORM CARD */
    .form-card {
        background: white;
        border-radius: 20px;
        padding: 2.5rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        border: none;
        position: relative;
    }

    /* INPUT STYLES */
    .form-label {
        font-weight: 600;
        color: #2c3e50;
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }
    
    .form-control, .form-select {
        border-radius: 12px;
        border: 1px solid #e0e0e0;
        padding: 0.8rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s;
        background-color: #fcfcfc;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #27ae60;
        box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.1);
        background-color: white;
    }

    /* SPECIAL INPUTS */
    .input-group-text {
        background: #f8f9fa;
        border: 1px solid #e0e0e0;
        border-radius: 12px 0 0 12px;
        color: #6c757d;
    }
    .form-control-points { border-radius: 0 12px 12px 0; }

    /* BUTTONS */
    .btn-submit {
        background: #27ae60;
        color: white;
        font-weight: 600;
        padding: 1rem 2rem;
        border-radius: 50px;
        border: none;
        box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        transition: transform 0.2s;
        width: 100%;
        font-size: 1.1rem;
    }
    .btn-submit:hover {
        background: #219150;
        transform: translateY(-2px);
    }

    .btn-back {
        background: white;
        color: #6c757d;
        font-weight: 600;
        padding: 0.5rem 1.5rem;
        border-radius: 50px;
        text-decoration: none;
        transition: all 0.2s;
    }
    .btn-back:hover { color: #2c3e50; background: #f8f9fa; }

    /* SECTION DIVIDER */
    .form-section-title {
        color: #27ae60;
        font-weight: 700;
        font-size: 1.1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 10px;
    }
</style>

<div class="hero-mini">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <h2 class="fw-bold m-0">Create Challenge</h2>
            <p class="m-0 opacity-75 small">Add a new eco-task to the platform.</p>
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

            <form method="post" action="challenge_create.php" enctype="multipart/form-data" class="form-card mt-5">

                <div class="form-section-title">
                    <i class="bi bi-card-text"></i> Basic Information
                </div>

                <div class="mb-4">
                    <label class="form-label">Challenge Title *</label>
                    <input type="text" name="challengeTitle" required class="form-control" placeholder="e.g. Plant a Tree Day">
                </div>

                <div class="mb-4">
                    <label class="form-label">Description *</label>
                    <textarea name="description" required class="form-control" rows="4" placeholder="Explain what users need to do..."></textarea>
                </div>

                <div class="form-section-title mt-5">
                    <i class="bi bi-tags"></i> Category & Context
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Choose Category</label>
                        <select name="categoryID" class="form-select">
                            <option value="">— Select Existing —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['categoryID']; ?>">
                                    <?= htmlspecialchars($cat['categoryName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label text-success">Or Create New Category</label>
                        <input type="text" name="newCategory" class="form-control border-success" placeholder="e.g. Solar Energy">
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">City (Optional)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                            <input type="text" name="city" class="form-control form-control-points" placeholder="e.g. New York">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Points Award *</label>
                        <div class="input-group">
                            <span class="input-group-text text-warning"><i class="bi bi-star-fill"></i></span>
                            <input type="number" name="pointAward" min="0" required class="form-control form-control-points" placeholder="50">
                        </div>
                    </div>
                </div>

                <div class="form-section-title mt-5">
                    <i class="bi bi-calendar-event"></i> Settings
                </div>

                <div class="mb-4">
                    <label class="form-label">Initial Status</label>
                    <select name="is_active" class="form-select">
                        <option value="1" selected>Active (Visible immediately)</option>
                        <option value="0">Draft (Hidden)</option>
                    </select>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control">
                    </div>
                </div>

                <input type="hidden" name="created_by" value="<?= $_SESSION['userID']; ?>">

                <hr class="my-4 text-muted opacity-25">

                <button class="btn-submit">
                    <i class="bi bi-plus-circle me-2"></i> Create Challenge
                </button>

            </form>

        </div>
    </div>
</div>

<?php include "includes/layout_end.php"; ?>