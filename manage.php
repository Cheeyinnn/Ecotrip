<?php
// --------------------------------------------------------
// 1. PAGE CONFIG & AUTH
// --------------------------------------------------------
$pageTitle = "Manage Challenges";
require 'db_connect.php';
require 'includes/auth.php';

// ADMIN CHECK
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash'] = "Access denied. Administrators only.";
    header("Location: view.php");
    exit;
}

// --------------------------------------------------------
// 2. LOGIC: AUTO-UPDATE & FETCH
// --------------------------------------------------------
// Auto-deactivate expired challenges
$conn->query("UPDATE challenge SET is_active = 0 WHERE end_date < CURDATE() AND is_active = 1");

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Fetch ALL challenges
$sql = "
    SELECT 
        c.*,
        cat.categoryName,
        CONCAT(u.firstName, ' ', u.lastName) AS creatorName
    FROM challenge c
    LEFT JOIN category cat ON c.categoryID = cat.categoryID
    LEFT JOIN user u ON c.created_by = u.userID
    ORDER BY c.is_active DESC, c.start_date DESC
";

$result = $conn->query($sql);

$activeChallenges = [];
$inactiveChallenges = [];

while ($row = $result->fetch_assoc()) {
    if ($row['is_active'] == 1) {
        $activeChallenges[] = $row;
    } else {
        $inactiveChallenges[] = $row;
    }
}

include "includes/layout_start.php";
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f4f7f6;
    }

    /* --- HERO HEADER --- */
    .hero-section {
        background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
        padding: 60px 0 100px;
        color: white;
        border-radius: 0 0 50px 50px;
        margin-bottom: 2rem;
        position: relative;
        z-index: 1; 
        box-shadow: 0 10px 30px rgba(39, 174, 96, 0.2);
    }
    
    .hero-title { font-weight: 700; margin-bottom: 0.5rem; }

    /* --- STATS CARDS --- */
    .stats-container {
        margin-top: -60px;
        margin-bottom: 3rem;
        display: flex;
        gap: 1.5rem;
        justify-content: center;
        flex-wrap: wrap;
        position: relative;
        z-index: 10;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 1.5rem 2rem;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 1rem;
        min-width: 250px;
        transition: transform 0.3s;
    }
    .stat-card:hover { transform: translateY(-5px); }
    
    .icon-box {
        width: 50px; height: 50px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
    }
    .icon-green { background: #e8f5e9; color: #27ae60; }
    .icon-gray { background: #f3f4f6; color: #6b7280; }

    .stat-info h3 { margin: 0; font-weight: 700; font-size: 1.8rem; color: #2c3e50; }
    .stat-info p { margin: 0; color: #95a5a6; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }

    /* --- TABLE STYLES --- */
    .table-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.03);
        overflow: hidden;
        border: none;
        margin-bottom: 3rem;
    }
    .card-header-custom {
        background: white;
        padding: 1.5rem 2rem;
        border-bottom: 1px solid #f0f2f5;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .header-title {
        font-weight: 700;
        font-size: 1.2rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .custom-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .custom-table th {
        background: #f8f9fa;
        color: #7f8c8d;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
        padding: 1rem;
        text-align: left;
        border-bottom: 2px solid #edf2f7;
        white-space: nowrap; 
    }
    .custom-table td {
        padding: 1rem;
        border-bottom: 1px solid #f0f2f5;
        color: #2c3e50;
        vertical-align: middle;
        font-size: 0.9rem;
        background: white;
        transition: background 0.2s;
        white-space: nowrap; 
    }
    .custom-table tr:hover td { background: #fafbfc; }

    /* Badges */
    .badge-pill {
        padding: 5px 10px;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .badge-active { background: #e8f5e9; color: #27ae60; border: 1px solid #c8e6c9; }
    .badge-inactive { background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; }
    .creator-badge { background: #f8f9fa; padding: 2px 8px; border-radius: 4px; color: #6c757d; border: 1px solid #e9ecef; font-size: 0.75rem; }

    /* Buttons */
    .btn-icon {
        width: 32px; height: 32px;
        border-radius: 8px;
        display: inline-flex; align-items: center; justify-content: center;
        transition: all 0.2s; text-decoration: none; border: none; font-size: 0.9rem; margin-right: 4px;
    }
    .btn-view { background: #f3f4f6; color: #6c757d; }
    .btn-view:hover { background: #6c757d; color: white; }
    
    .btn-edit { background: #e3f2fd; color: #3498db; }
    .btn-edit:hover { background: #3498db; color: white; }
    
    .btn-end { background: #fee2e2; color: #dc2626; }
    .btn-end:hover { background: #dc2626; color: white; }

    /* Header Buttons */
    .btn-create {
        background: white; color: #27ae60; font-weight: 600;
        padding: 0.6rem 1.5rem; border-radius: 50px; text-decoration: none;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1); transition: transform 0.2s;
    }
    .btn-create:hover { transform: translateY(-2px); color: #219150; }

    /* 🟢 NEW EXPORT BUTTON STYLE */
    .btn-export {
        background: rgba(255,255,255,0.2); 
        color: white; 
        font-weight: 600;
        padding: 0.6rem 1.5rem; 
        border-radius: 50px; 
        text-decoration: none;
        backdrop-filter: blur(5px);
        border: 1px solid rgba(255,255,255,0.3);
        transition: background 0.2s;
        margin-right: 10px;
    }
    .btn-export:hover {
        background: rgba(255,255,255,0.3);
        color: white;
    }
</style>

<div class="hero-section">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="hero-title">Admin Dashboard</h1>
                <p class="hero-subtitle mb-0">Manage challenges and track performance.</p>
            </div>
            <div>
                <a href="export_challenges.php" class="btn-export">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
                </a>

                <a href="challenge_create_form.php" class="btn-create">
                    <i class="bi bi-plus-lg me-1"></i> Create New
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">

    <div class="stats-container">
        <div class="stat-card">
            <div class="icon-box icon-green"><i class="bi bi-lightning-charge-fill"></i></div>
            <div class="stat-info">
                <h3><?= count($activeChallenges) ?></h3>
                <p>Active</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="icon-box icon-gray"><i class="bi bi-archive-fill"></i></div>
            <div class="stat-info">
                <h3><?= count($inactiveChallenges) ?></h3>
                <p>Inactive / History</p>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-success rounded-4 shadow-sm mb-4 border-0 text-center">
            <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <div class="table-card">
        <div class="card-header-custom">
            <div class="header-title text-dark">
                <i class="bi bi-activity text-success"></i> Active Challenges
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>City</th>
                        <th>Points</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Created By</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($activeChallenges)): ?>
                    <?php foreach ($activeChallenges as $c): ?>
                        <tr>
                            <td><div class="fw-bold"><?= htmlspecialchars($c['challengeTitle']) ?></div></td>
                            <td><?= htmlspecialchars($c['categoryName'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($c['city'] ?: 'Global') ?></td>
                            <td class="fw-bold text-warning"><?= $c['pointAward'] ?></td>
                            <td><span class="badge-pill badge-active">Active</span></td>
                            <td><?= $c['start_date'] ? date("M d, Y", strtotime($c['start_date'])) : '-' ?></td>
                            <td><?= $c['end_date'] ? date("M d, Y", strtotime($c['end_date'])) : 'Ongoing' ?></td>
                            <td><span class="creator-badge"><?= htmlspecialchars($c['creatorName'] ?? 'Admin') ?></span></td>
                            
                            <td class="text-center">
                                <a href="challenge_details.php?id=<?= $c['challengeID'] ?>" class="btn-icon btn-view"><i class="bi bi-eye"></i></a>
                                <a href="challenge_edit.php?id=<?= $c['challengeID'] ?>" class="btn-icon btn-edit"><i class="bi bi-pencil-fill"></i></a>
                                <a href="challenge_end.php?id=<?= $c['challengeID'] ?>" onclick="return confirm('End this challenge?');" class="btn-icon btn-end"><i class="bi bi-stop-circle-fill"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">No active challenges.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-card" style="opacity: 0.9;">
        <div class="card-header-custom bg-light">
            <div class="header-title text-secondary">
                <i class="bi bi-clock-history"></i> Challenge History
            </div>
        </div>
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>City</th>
                        <th>Points</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Created By</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($inactiveChallenges)): ?>
                    <?php foreach ($inactiveChallenges as $c): ?>
                        <tr>
                            <td class="text-muted"><div class="fw-bold"><?= htmlspecialchars($c['challengeTitle']) ?></div></td>
                            <td><?= htmlspecialchars($c['categoryName'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($c['city'] ?: 'Global') ?></td>
                            <td><?= $c['pointAward'] ?></td>
                            <td><span class="badge-pill badge-inactive">Inactive</span></td>
                            <td><?= $c['start_date'] ? date("M d, Y", strtotime($c['start_date'])) : '-' ?></td>
                            <td class="text-danger"><?= $c['end_date'] ? date("M d, Y", strtotime($c['end_date'])) : '-' ?></td>
                            <td><span class="creator-badge"><?= htmlspecialchars($c['creatorName'] ?? 'Admin') ?></span></td>
                            
                            <td class="text-center">
                                <a href="challenge_edit.php?id=<?= $c['challengeID'] ?>" class="btn-icon btn-edit"><i class="bi bi-pencil-fill"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">No history found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include "includes/layout_end.php"; ?>