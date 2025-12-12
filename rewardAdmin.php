<?php
session_start();
require "db.php"; 

if (!isset($_SESSION['userID'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['userID'];

// --- AUTOMATIC DATABASE FIX ---
$checkCol = $conn->query("SHOW COLUMNS FROM reward LIKE 'imageURL'");
if ($checkCol->num_rows == 0) {
    $checkPhoto = $conn->query("SHOW COLUMNS FROM reward LIKE 'photo'");
    if ($checkPhoto->num_rows > 0) $conn->query("ALTER TABLE reward CHANGE photo imageURL VARCHAR(500)");
    else $conn->query("ALTER TABLE reward ADD COLUMN imageURL VARCHAR(500) DEFAULT NULL");
}

// 1. ADD COLUMN FOR PREFIX (Auto-fix)
$checkPrefix = $conn->query("SHOW COLUMNS FROM reward LIKE 'prefix'");
if ($checkPrefix->num_rows == 0) {
    $conn->query("ALTER TABLE reward ADD COLUMN prefix VARCHAR(50) DEFAULT 'ECO-'"); 
}

// Fetch User
$stmt = $conn->prepare("SELECT firstName, lastName, email, role, avatarURL FROM user WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();
$currentUser = $result->fetch_assoc();
$_SESSION['role'] = $currentUser['role'];

$avatarPath = 'upload/default.png';
if (!file_exists(__DIR__ . '/' . $avatarPath)) { if (file_exists(__DIR__ . '/uploads/default.png')) $avatarPath = 'uploads/default.png'; }
if (!empty($currentUser['avatarURL']) && file_exists(__DIR__ . '/' . $currentUser['avatarURL'])) $avatarPath = $currentUser['avatarURL'];

// --- STATISTICS CALCULATIONS ---
$stats = ['total' => 0, 'low_stock' => 0, 'active' => 0, 'inactive' => 0];
$statQuery = $conn->query("SELECT stockQuantity, is_active FROM reward");
while($row = $statQuery->fetch_assoc()){
    $stats['total']++;
    if($row['stockQuantity'] < 5) $stats['low_stock']++;
    if($row['is_active']) $stats['active']++; else $stats['inactive']++;
}

// --- PAGINATION & FILTER LOGIC ---
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterCat = isset($_GET['category']) ? $_GET['category'] : '';
$filterStat = isset($_GET['filter']) ? $_GET['filter'] : ''; 

$sql = "SELECT * FROM reward WHERE 1=1";
$countSql = "SELECT COUNT(*) as total FROM reward WHERE 1=1";
$types = "";
$params = [];

if (!empty($search)) {
    $term = "%$search%";
    $cond = " AND (rewardID LIKE ? OR rewardName LIKE ? OR description LIKE ?)";
    $sql .= $cond;
    $countSql .= $cond;
    $types .= "sss";
    $params[] = $term; $params[] = $term; $params[] = $term;
}

if (!empty($filterCat)) {
    $cond = " AND category = ?";
    $sql .= $cond;
    $countSql .= $cond;
    $types .= "s";
    $params[] = $filterCat;
}

if (!empty($filterStat)) {
    if ($filterStat == 'low_stock') { $cond = " AND stockQuantity < 5"; $sql .= $cond; $countSql .= $cond; } 
    elseif ($filterStat == 'active') { $cond = " AND is_active = 1"; $sql .= $cond; $countSql .= $cond; } 
    elseif ($filterStat == 'inactive') { $cond = " AND is_active = 0"; $sql .= $cond; $countSql .= $cond; }
}

$sql .= " ORDER BY rewardID DESC LIMIT ?, ?";
$types .= "ii";
$params[] = $offset;
$params[] = $limit;

$stmtCount = $conn->prepare($countSql);
if (!empty($types)) {
    $countTypes = substr($types, 0, -2);
    $countParams = array_slice($params, 0, -2);
    if (!empty($countTypes)) $stmtCount->bind_param($countTypes, ...$countParams);
}
$stmtCount->execute();
$totalRecords = $stmtCount->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $limit);

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rewards = $stmt->get_result();

function uploadImage($file) {
    $targetDir = "uploads/";
    if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);
    $fileName = uniqid() . "_" . basename($file["name"]); 
    $targetFile = $targetDir . $fileName;
    $imageFileType = strtolower(pathinfo($targetFile,PATHINFO_EXTENSION));
    $valid = ["jpg","jpeg","png","gif","webp"];
    if(in_array($imageFileType, $valid) && move_uploaded_file($file["tmp_name"], $targetFile)){ return $targetFile; }
    return null;
}

$msg = ""; $msgType = "";

// 2. UPDATE ADD REWARD LOGIC (Handle Prefix)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['addReward'])) {
    $img = ""; 
    // No more barcode image upload
    if(!empty($_FILES['rewardImage']['name'])) $img = uploadImage($_FILES['rewardImage']);
    
    // Capture prefix (default to ECO- if empty)
    $prefix = !empty($_POST['prefix']) ? $_POST['prefix'] : 'ECO-';
    
    // Updated SQL to insert 'prefix' instead of 'barcodeURL'
    $sql = $conn->prepare("INSERT INTO reward (rewardName, description, stockQuantity, pointRequired, is_active, category, imageURL, prefix) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $active = isset($_POST['is_active']) ? 1 : 0;
    
    // Bind parameters
    $sql->bind_param("ssiiisss", $_POST['rewardName'], $_POST['description'], $_POST['stockQuantity'], $_POST['pointRequired'], $active, $_POST['category'], $img, $prefix);
    
    if ($sql->execute()) { $msg = "Reward Added!"; $msgType = "success"; } else { $msg = "Error: ".$sql->error; $msgType = "danger"; }
    echo "<meta http-equiv='refresh' content='0'>";
}

// 3. UPDATE EDIT REWARD LOGIC (Handle Prefix)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['editReward'])) {
    // Update prefix field
    $q = "UPDATE reward SET rewardName=?, description=?, stockQuantity=?, pointRequired=?, is_active=?, category=?, prefix=?";
    
    $prefix = !empty($_POST['prefix']) ? $_POST['prefix'] : 'ECO-';
    
    $p = [$_POST['rewardName'], $_POST['description'], $_POST['stockQuantity'], $_POST['pointRequired'], (isset($_POST['is_active'])?1:0), $_POST['category'], $prefix];
    $t = "ssiiiss";
    
    if(!empty($_FILES['rewardImage']['name']) && $u=uploadImage($_FILES['rewardImage'])){ $q.=", imageURL=?"; $p[]=$u; $t.="s"; }
    // Removed barcode image upload logic here
    
    $q .= " WHERE rewardID=?"; $p[]=$_POST['rewardID']; $t.="i";
    $sql = $conn->prepare($q);
    $sql->bind_param($t, ...$p);
    if ($sql->execute()) { $msg = "Updated!"; $msgType = "success"; } else { $msg = "Error"; $msgType = "danger"; }
    echo "<meta http-equiv='refresh' content='0'>";
}

if (isset($_GET['deleteRewardID'])) {
    $id = $_GET['deleteRewardID'];
    $pReq = $conn->query("SELECT userID, pointSpent FROM redemptionrequest WHERE rewardID=$id AND status='pending'");
    while($r = $pReq->fetch_assoc()) if($r['pointSpent']>0) $conn->query("INSERT INTO pointtransaction (userID, transactionType, pointsTransaction) VALUES ({$r['userID']}, 'return', {$r['pointSpent']})");
    $conn->query("UPDATE redemptionrequest SET status='deleted', rewardID=NULL WHERE rewardID=$id AND status='pending'");
    $conn->query("UPDATE redemptionrequest SET rewardID=NULL WHERE rewardID=$id");
    $conn->query("DELETE FROM reward WHERE rewardID=$id");
    echo "<script>window.location.href='rewardAdmin.php';</script>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Rewards - EcoTrip</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { margin: 0; background: #f5f7fb; font-family: 'Plus Jakarta Sans', sans-serif; }
        .layout-wrapper { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: #ffffff; border-right: 1px solid #e5e9f2; padding: 20px 16px; display: flex; flex-direction: column; }
        .sidebar-brand { font-size: 20px; font-weight: 700; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
        .sidebar-brand iconify-icon { font-size: 24px; color: #2563eb; }
        .sidebar-nav-title { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.08em; margin-top: 10px; margin-bottom: 4px; }
        .sidebar-nav { list-style: none; padding-left: 0; margin: 0; flex-grow: 1; }
        .sidebar-item { margin-bottom: 4px; }
        .sidebar-link { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 999px; text-decoration: none; font-size: 14px; color: #4b5563; transition: background 0.15s ease, color 0.15s ease; font-weight: 500; }
        .sidebar-link:hover { background: #eef2ff; color: #2563eb; }
        .sidebar-link.active { background: #e0ecff; color: #1d4ed8; font-weight: 600; }
        .sidebar-footer { font-size: 12px; color: #6b7280; border-top: 1px solid #e5e9f2; padding-top: 10px; margin-top: 12px; }
        .main-content { flex: 1; display: flex; flex-direction: column; }
        .topbar { background: #f5f7fb; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e5e9f2; }
        .nav-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .topbar-icon-btn { width: 34px; height: 34px; border-radius: 50%; border: none; background: #ffffff; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12); cursor: pointer; }
        .content-wrapper { padding: 20px 24px 24px; }
        .card-box { background: #ffffff; border-radius: 18px; padding: 25px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06); margin-bottom: 24px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 16px; box-shadow: 0 5px 15px rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; text-decoration: none; transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s; border: 1px solid transparent; cursor: pointer; }
        .stat-card:hover, .stat-card.active { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(37, 99, 235, 0.1); border-color: #2563eb; }
        .stat-card.active { background-color: #eff6ff; }
        .stat-label { font-size: 12px; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 5px; }
        .stat-val { font-size: 28px; font-weight: 800; color: #1e293b; line-height: 1; }
        .stat-icon { width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .bg-blue-light { background: #eff6ff; color: #2563eb; }
        .bg-red-light { background: #fef2f2; color: #ef4444; }
        .bg-green-light { background: #f0fdf4; color: #16a34a; }

        th { font-weight: 600; color: #6b7280; font-size: 13px; text-transform: uppercase; border-bottom: 2px solid #e5e9f2; padding: 12px 8px; }
        td { font-size: 14px; vertical-align: middle; padding: 8px; border-bottom: 1px solid #f8fafc; }
        .reward-img-preview { width: 50px; height: 50px; border-radius: 8px; object-fit: cover; border: 1px solid #e2e8f0; }
        .hidden { display: none !important; }
        .low-stock-row { background-color: #fff1f2; }
        .search-bar { position: relative; max-width: 300px; width: 100%; }
        .search-bar input { padding-left: 35px; border-radius: 20px; font-size: 13px; }
        .search-bar iconify-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .pagination .page-item.active .page-link { background-color: #2563eb; border-color: #2563eb; }
        .pagination .page-link { color: #64748b; font-size: 14px; }
    </style>
</head>
<body>
<div class="layout-wrapper">
    <aside class="sidebar">
        <div class="sidebar-brand"><iconify-icon icon="solar:shop-2-line-duotone"></iconify-icon><span>EcoTrip Dashboard</span></div>
        <div class="sidebar-nav-title">Dashboards</div>
        <ul class="sidebar-nav">
            <li class="sidebar-item"><a href="index.php" class="sidebar-link"><iconify-icon icon="solar:bag-4-line-duotone"></iconify-icon><span>eCommerce</span></a></li>
            <li class="sidebar-item"><a href="#" class="sidebar-link"><iconify-icon icon="solar:chart-square-line-duotone"></iconify-icon><span>Analytics</span></a></li>
        </ul>
        <div class="sidebar-nav-title">EcoTrip</div>
        <ul class="sidebar-nav">
            <li class="sidebar-item"><a href="team.php" class="sidebar-link"><iconify-icon icon="solar:users-group-two-rounded-line-duotone"></iconify-icon><span>My Team</span></a></li>
            <li class="sidebar-item"><a href="create_team.php" class="sidebar-link"><iconify-icon icon="solar:user-plus-rounded-line-duotone"></iconify-icon><span>Create Team</span></a></li>
            <li class="sidebar-item"><a href="join_team.php" class="sidebar-link"><iconify-icon icon="solar:login-3-line-duotone"></iconify-icon><span>Join Team</span></a></li>
            <li class="sidebar-item"><a href="view.php" class="sidebar-link"><iconify-icon icon="solar:list-check-line-duotone"></iconify-icon><span>View Challenges</span></a></li>
            <li class="sidebar-item"><a href="manage.php" class="sidebar-link"><iconify-icon icon="solar:pen-new-round-line-duotone"></iconify-icon><span>Manage Challenges</span></a></li>
            <li class="sidebar-item"><a href="profile.php" class="sidebar-link"><iconify-icon icon="solar:user-circle-line-duotone"></iconify-icon><span>User Profile</span></a></li>
            <li class="sidebar-item"><a href="rewards.php" class="sidebar-link"><iconify-icon icon="solar:gift-line-duotone"></iconify-icon><span>Reward</span></a></li>
            <li class="sidebar-item"><a href="rewardAdmin.php" class="sidebar-link active"><iconify-icon icon="solar:settings-minimalistic-line-duotone"></iconify-icon><span>RewardAdmin</span></a></li>
            <li class="sidebar-item"><a href="leaderboard.php" class="sidebar-link"><iconify-icon icon="solar:cup-star-line-duotone"></iconify-icon><span>Leaderboard</span></a></li>
            <li class="sidebar-item"><a href="reviewRR.php" class="sidebar-link"><iconify-icon icon="solar:clipboard-check-line-duotone"></iconify-icon><span>ReviewRR</span></a></li>
            <li class="sidebar-item"><a href="manage_user.php" class="sidebar-link"><iconify-icon icon="solar:users-group-rounded-line-duotone"></iconify-icon><span>Manage Users</span></a></li>
            <li class="sidebar-item"><a href="manage_team.php" class="sidebar-link"><iconify-icon icon="solar:users-group-two-rounded-line-duotone"></iconify-icon><span>Manage Teams</span></a></li>
        </ul>
        <div class="sidebar-footer mt-auto">Logged in as:<br><strong><?php echo htmlspecialchars($currentUser['firstName']); ?></strong></div>
    </aside>

    <div class="main-content">
        <div class="topbar">
            <div class="topbar-title">Manage Rewards</div>
            <div class="dropdown d-inline-block">
                <a href="#" class="d-flex align-items-center text-decoration-none"><img src="<?php echo htmlspecialchars($avatarPath); ?>" class="nav-avatar me-1"></a>
            </div>
        </div>
        <div class="content-wrapper">
            <?php if ($msg): ?><div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show"><?php echo $msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            
            <div class="stats-grid">
                <a href="rewardAdmin.php" class="stat-card <?php echo ($filterStat == '') ? 'active' : ''; ?>">
                    <div><div class="stat-label">Total Rewards</div><div class="stat-val"><?php echo $stats['total']; ?></div></div>
                    <div class="stat-icon bg-blue-light"><iconify-icon icon="solar:box-bold-duotone"></iconify-icon></div>
                </a>
                <a href="rewardAdmin.php?filter=low_stock" class="stat-card <?php echo ($filterStat == 'low_stock') ? 'active' : ''; ?>">
                    <div><div class="stat-label">Low Stock (< 5)</div><div class="stat-val text-danger"><?php echo $stats['low_stock']; ?></div></div>
                    <div class="stat-icon bg-red-light"><iconify-icon icon="solar:danger-circle-bold-duotone"></iconify-icon></div>
                </a>
                <a href="rewardAdmin.php?filter=active" class="stat-card <?php echo ($filterStat == 'active') ? 'active' : ''; ?>">
                    <div><div class="stat-label">Active Items</div><div class="stat-val text-success"><?php echo $stats['active']; ?></div></div>
                    <div class="stat-icon bg-green-light"><iconify-icon icon="solar:check-circle-bold-duotone"></iconify-icon></div>
                </a>
            </div>

            <!-- 4. UPDATED "ADD REWARD" FORM -->
            <div class="card-box">
                <h4 class="mb-3">Add New Reward</h4>
                <form action="" method="POST" enctype="multipart/form-data" class="row g-3">
                    <div class="col-md-5"><label class="form-label small">Reward Name</label><input type="text" name="rewardName" class="form-control form-control-sm" required></div>
                    <div class="col-md-7"><label class="form-label small">Description</label><input type="text" name="description" class="form-control form-control-sm" required></div>
                    <div class="col-md-2"><label class="form-label small">Stock</label><input type="number" name="stockQuantity" class="form-control form-control-sm" required></div>
                    <div class="col-md-2"><label class="form-label small">Points</label><input type="number" name="pointRequired" class="form-control form-control-sm" required></div>
                    
                    <!-- Updated Category Logic -->
                    <div class="col-md-2">
                        <label class="form-label small">Category</label>
                        <select name="category" class="form-select form-select-sm" required onchange="togglePrefixField('add', this.value)">
                            <option value="product">Product</option>
                            <option value="voucher">Voucher</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3"><label class="form-label small">Image</label><input type="file" name="rewardImage" class="form-control form-control-sm" accept="image/*"></div>
                    
                    <!-- 5. NEW PREFIX FIELD (Replaces Barcode Upload) -->
                    <div class="col-md-3 hidden" id="addPrefixField">
                        <label class="form-label small text-primary">Voucher Prefix</label>
                        <input type="text" name="prefix" class="form-control form-control-sm border-primary" placeholder="e.g. NK-ECO-" title="Sets the barcode prefix for this voucher">
                    </div>
                    
                    <div class="col-12 d-flex align-items-center gap-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" checked><label class="form-check-label small">Active</label></div><button type="submit" name="addReward" class="btn btn-primary btn-sm ms-auto px-4">Create Reward</button></div>
                </form>
            </div>

            <div class="card-box">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0">All Rewards</h4>
                    <form method="GET" class="d-flex gap-2 align-items-center">
                        <div class="search-bar"><iconify-icon icon="solar:magnifer-linear"></iconify-icon><input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>"></div>
                        <select name="category" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()"><option value="">All Types</option><option value="product" <?php echo $filterCat == 'product' ? 'selected' : ''; ?>>Product</option><option value="voucher" <?php echo $filterCat == 'voucher' ? 'selected' : ''; ?>>Voucher</option></select>
                        <?php if(!empty($filterStat)): ?><input type="hidden" name="filter" value="<?php echo htmlspecialchars($filterStat); ?>"><?php endif; ?>
                        <?php if($search || $filterCat || $filterStat): ?><a href="rewardAdmin.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th style="width: 60px;">Img</th><th>ID</th><th>Name / Desc</th><th>Stock</th><th>Points</th><th>Cat</th><th>Options</th><th>Status</th><th style="min-width: 140px;">Action</th></tr></thead>
                        <tbody>
                            <?php if ($rewards->num_rows > 0) { while ($row = $rewards->fetch_assoc()) { 
                                $imgSrc = !empty($row['imageURL']) ? $row['imageURL'] : 'upload/reward_placeholder.png';
                                $lowStockClass = ($row['stockQuantity'] < 5) ? 'low-stock-row' : '';
                            ?>
                                <tr class="<?php echo $lowStockClass; ?>">
                                    <td><img src="<?php echo htmlspecialchars($imgSrc); ?>" class="reward-img-preview" alt="Img"></td>
                                    <td><small class="text-muted">#<?php echo $row['rewardID']; ?></small></td>
                                    <form action="" method="POST" enctype="multipart/form-data">
                                        <td>
                                            <input type="hidden" name="rewardID" value="<?php echo $row['rewardID']; ?>">
                                            <input type="text" name="rewardName" class="form-control form-control-sm mb-1 fw-bold" value="<?php echo htmlspecialchars($row['rewardName']); ?>" required>
                                            <input type="text" name="description" class="form-control form-control-sm text-muted" value="<?php echo htmlspecialchars($row['description']); ?>" required>
                                        </td>
                                        <td><input type="number" name="stockQuantity" class="form-control form-control-sm <?php echo $lowStockClass ? 'border-danger text-danger' : ''; ?>" value="<?php echo $row['stockQuantity']; ?>" style="width: 60px;" required></td>
                                        <td><input type="number" name="pointRequired" class="form-control form-control-sm" value="<?php echo $row['pointRequired']; ?>" style="width: 60px;" required></td>
                                        <td><select name="category" class="form-select form-select-sm" style="width: 85px;" required><option value="product" <?php echo ($row['category'] == 'product') ? 'selected' : ''; ?>>Prod</option><option value="voucher" <?php echo ($row['category'] == 'voucher') ? 'selected' : ''; ?>>Vouch</option></select></td>
                                        <td>
                                            <div class="d-flex flex-column gap-1">
                                                <input type="file" name="rewardImage" class="form-control form-control-sm" style="width: 130px; font-size:10px;">
                                                
                                                <!-- 6. EDIT PREFIX (Replaces Barcode Upload) -->
                                                <?php if($row['category'] == 'voucher'): ?>
                                                    <input type="text" name="prefix" class="form-control form-control-sm border-primary" 
                                                           style="width: 130px; font-size:11px;" 
                                                           value="<?php echo !empty($row['prefix']) ? htmlspecialchars($row['prefix']) : ''; ?>" 
                                                           placeholder="Prefix (e.g. NK-)">
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>></div></td>
                                        <td>
                                            <div class="d-flex gap-2" style="white-space: nowrap;">
                                                <button type="submit" name="editReward" class="btn btn-sm btn-light border" title="Save"><iconify-icon icon="solar:disk-bold-duotone" class="text-success"></iconify-icon> Save</button> 
                                                <a href="?deleteRewardID=<?php echo $row['rewardID']; ?>" onclick="return confirm('Delete?')" class="btn btn-sm btn-light border text-danger" title="Delete"><iconify-icon icon="solar:trash-bin-trash-bold-duotone"></iconify-icon></a>
                                            </div>
                                        </td>
                                    </form>
                                </tr>
                            <?php }} else { echo "<tr><td colspan='9' class='text-center py-5 text-muted'>No rewards found.</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($filterCat); ?>&filter=<?php echo urlencode($filterStat); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- 7. Updated JS to toggle Prefix field instead of Barcode -->
<script>
function togglePrefixField(t,v){
    if(t==='add'){
        const f=document.getElementById('addPrefixField');
        if(v.toLowerCase()==='voucher') f.classList.remove('hidden');
        else f.classList.add('hidden');
    }
}
</script>
</body>
</html>