<?php
session_start();
require "db_connect.php"; 

if (!isset($_SESSION['userID'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['userID'];

$pageTitle = "Manage Rewards";

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

// 2. ADD COLUMN FOR EXPIRY DATE (New Requirement)
$checkExpiry = $conn->query("SHOW COLUMNS FROM reward LIKE 'expiry_date'");
if ($checkExpiry->num_rows == 0) {
    $conn->query("ALTER TABLE reward ADD COLUMN expiry_date DATE DEFAULT NULL");
}

// 3. AUTO-DEACTIVATE EXPIRED REWARDS (New Requirement)
// If expiry_date is set (not NULL) and is in the past (< CURDATE()), set is_active to 0
$conn->query("UPDATE reward SET is_active = 0 WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND is_active = 1");

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
$limit = 10; // Requirement 2: 10 rewards per page
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

// UPDATED FILTER LOGIC HERE
if (!empty($filterStat)) {
    if ($filterStat == 'low_stock') { 
        $cond = " AND stockQuantity < 5"; 
        $sql .= $cond; 
        $countSql .= $cond; 
    } elseif ($filterStat == 'active') { 
        $cond = " AND is_active = 1"; 
        $sql .= $cond; 
        $countSql .= $cond; 
    } elseif ($filterStat == 'inactive') { 
        $cond = " AND is_active = 0"; 
        $sql .= $cond; 
        $countSql .= $cond; 
    }
}

$sql .= " ORDER BY rewardID DESC LIMIT ?, ?";
$types .= "ii";
$params[] = $offset;
$params[] = $limit;

$stmtCount = $conn->prepare($countSql);
if (!empty($types)) {
    $countTypesLength = strlen($types) - 2;
    if ($countTypesLength > 0) {
        $countTypes = substr($types, 0, $countTypesLength);
        $countParams = array_slice($params, 0, count($params) - 2);
        $stmtCount->bind_param($countTypes, ...$countParams);
    }
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

// 2. UPDATE ADD REWARD LOGIC (Handle Prefix & Expiry Date)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['addReward'])) {
    $img = ""; 
    // No more barcode image upload
    if(!empty($_FILES['rewardImage']['name'])) $img = uploadImage($_FILES['rewardImage']);
    
    // Capture prefix (default to ECO- if empty)
    $prefix = !empty($_POST['prefix']) ? $_POST['prefix'] : 'ECO-';
    
    // Capture expiry date (allow null)
    $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;
    
    // Updated SQL to insert 'prefix' and 'expiry_date'
    $sql = $conn->prepare("INSERT INTO reward (rewardName, description, stockQuantity, pointRequired, is_active, category, imageURL, prefix, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $active = isset($_POST['is_active']) ? 1 : 0;
    
    // Bind parameters
    $sql->bind_param("ssiiissss", $_POST['rewardName'], $_POST['description'], $_POST['stockQuantity'], $_POST['pointRequired'], $active, $_POST['category'], $img, $prefix, $expiryDate);
    
    if ($sql->execute()) { $msg = "Reward Added!"; $msgType = "success"; } else { $msg = "Error: ".$sql->error; $msgType = "danger"; }
    echo "<meta http-equiv='refresh' content='0'>";
}

// 3. UPDATE EDIT REWARD LOGIC (Handle Prefix & Expiry Date)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['editReward'])) {
    // Update prefix field
    $q = "UPDATE reward SET rewardName=?, description=?, stockQuantity=?, pointRequired=?, is_active=?, category=?, prefix=?, expiry_date=?";
    
    $prefix = !empty($_POST['prefix']) ? $_POST['prefix'] : 'ECO-';
    $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;
    
    $p = [$_POST['rewardName'], $_POST['description'], $_POST['stockQuantity'], $_POST['pointRequired'], (isset($_POST['is_active'])?1:0), $_POST['category'], $prefix, $expiryDate];
    $t = "ssiiisss";
    
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

include "includes/layout_start.php";
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
        body { margin: 0; background: #f5f7fb; font-family: 'Plus Jakarta Sans', sans-serif; }

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
        .bg-gray-light { background: #f3f4f6; color: #6b7280; }

        th { font-weight: 600; color: #6b7280; font-size: 13px; text-transform: uppercase; border-bottom: 2px solid #e5e9f2; padding: 12px 8px; white-space: nowrap; }
        td { font-size: 14px; vertical-align: middle; padding: 12px 8px; border-bottom: 1px solid #f8fafc; }
        .reward-img-preview { width: 50px; height: 50px; border-radius: 8px; object-fit: cover; border: 1px solid #e2e8f0; }
        .hidden { display: none !important; }
        .low-stock-row { background-color: #fff1f2; }
        .search-bar { position: relative; max-width: 300px; width: 100%; }
        .search-bar input { padding-left: 35px; border-radius: 20px; font-size: 13px; }
        .search-bar iconify-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .pagination .page-item.active .page-link { background-color: #2563eb; border-color: #2563eb; }
        .pagination .page-link { color: #64748b; font-size: 14px; }
        
        /* New Styling for Add Reward Form */
        .fancy-form-container {
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
            border: 1px solid #e2e8f0;
        }
        .form-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 15px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .fancy-input {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 15px;
            transition: all 0.3s;
        }
        .fancy-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .fancy-label {
            font-weight: 600;
            color: #64748b;
            margin-bottom: 5px;
            display: block;
        }
        .upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            background: #f8fafc;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }
        .upload-box:hover {
            border-color: #2563eb;
            background: #eff6ff;
        }
        .upload-box iconify-icon {
            font-size: 32px;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        .upload-box span {
            font-size: 12px;
            color: #64748b;
            display: block;
        }
        .upload-box input[type="file"] {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #22c55e;
        }
        input:checked + .slider:before {
            transform: translateX(24px);
        }
        .btn-create {
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }
        .btn-create:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }
        /* Add Reward Card */
        .add-reward-card {
            background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
            border: 2px dashed #3b82f6;
            color: #2563eb;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 20px;
        }
        .add-reward-card:hover {
            background: #dbeafe;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
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
                <a href="rewardAdmin.php?filter=inactive" class="stat-card <?php echo ($filterStat == 'inactive') ? 'active' : ''; ?>">
                    <div><div class="stat-label">Inactive Items</div><div class="stat-val text-secondary"><?php echo $stats['inactive']; ?></div></div>
                    <div class="stat-icon bg-gray-light"><iconify-icon icon="solar:forbidden-circle-bold-duotone"></iconify-icon></div>
                </a>
            </div>

            <!-- "Add New Reward" Trigger Card -->
            <div class="add-reward-card" data-bs-toggle="modal" data-bs-target="#addRewardModal">
                <iconify-icon icon="solar:add-circle-bold" style="font-size: 24px;" class="me-2"></iconify-icon> Add New Reward
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
                        <thead><tr><th style="width: 50px;">Img</th><th style="width: 50px;">ID</th><th>Name / Desc</th><th style="width: 70px;">Stock</th><th style="width: 80px;">Points</th><th style="width: 100px;">Cat</th><th style="width: 130px;">Options</th><th style="width: 80px;">Expiry</th><th style="width: 60px;">Status</th><th style="width: 80px;">Action</th></tr></thead>
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
                                        <td><input type="number" name="stockQuantity" class="form-control form-control-sm <?php echo $lowStockClass ? 'border-danger text-danger' : ''; ?>" value="<?php echo $row['stockQuantity']; ?>" required></td>
                                        <td><input type="number" name="pointRequired" class="form-control form-control-sm" value="<?php echo $row['pointRequired']; ?>" required></td>
                                        <td><select name="category" class="form-select form-select-sm" style="width: 110px;" required><option value="product" <?php echo ($row['category'] == 'product') ? 'selected' : ''; ?>>Product</option><option value="voucher" <?php echo ($row['category'] == 'voucher') ? 'selected' : ''; ?>>Voucher</option></select></td>
                                        <td>
                                            <div class="d-flex flex-column gap-1">
                                                <input type="file" name="rewardImage" class="form-control form-control-sm" style="font-size:10px;">
                                                
                                                <?php if($row['category'] == 'voucher'): ?>
                                                    <input type="text" name="prefix" class="form-control form-control-sm border-primary" 
                                                           style="font-size:11px;" 
                                                           value="<?php echo !empty($row['prefix']) ? htmlspecialchars($row['prefix']) : ''; ?>" 
                                                           placeholder="Prefix">
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <!-- Added Expiry Date Input to Edit Form -->
                                        <td>
                                            <input type="date" name="expiry_date" class="form-control form-control-sm" 
                                                   style="width: 110px;" 
                                                   value="<?php echo !empty($row['expiry_date']) ? $row['expiry_date'] : ''; ?>">
                                        </td>
                                        <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>></div></td>
                                        <td>
                                            <div class="d-flex gap-2" style="white-space: nowrap;">
                                                <button type="submit" name="editReward" class="btn btn-sm btn-light border px-3" title="Save"><iconify-icon icon="solar:disk-bold-duotone" class="text-success me-1"></iconify-icon> Save</button> 
                                                <a href="?deleteRewardID=<?php echo $row['rewardID']; ?>" onclick="return confirm('Delete?')" class="btn btn-sm btn-light border text-danger" title="Delete"><iconify-icon icon="solar:trash-bin-trash-bold-duotone"></iconify-icon></a>
                                            </div>
                                        </td>
                                    </form>
                                </tr>
                            <?php }} else { echo "<tr><td colspan='10' class='text-center py-5 text-muted'>No rewards found.</td></tr>"; } ?>
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

<!-- Add Reward Modal -->
<div class="modal fade" id="addRewardModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content fancy-form-container border-0 shadow-lg">
      <div class="modal-header border-0 pb-0">
        <div class="d-flex align-items-center">
            <div class="bg-blue-light p-2 rounded-3 me-3"><iconify-icon icon="solar:add-circle-bold-duotone" style="font-size: 24px;"></iconify-icon></div>
            <h5 class="modal-title fw-bold text-dark">Add New Reward</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-4">
        <form action="" method="POST" enctype="multipart/form-data">
            <div class="row g-4">
                <!-- Left Column: Image Upload -->
                <div class="col-md-4">
                    <label class="fancy-label">Reward Image</label>
                    <div class="upload-box" id="uploadBox">
                        <input type="file" name="rewardImage" accept="image/*" onchange="previewImage(this)">
                        <div id="uploadPlaceholder">
                            <iconify-icon icon="solar:camera-add-linear"></iconify-icon>
                            <span>Click or Drop Image</span>
                        </div>
                        <img id="imagePreview" src="" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px; display: none;">
                    </div>
                </div>

                <!-- Right Column: Details -->
                <div class="col-md-8">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <div class="form-section-title mt-0">Basic Info</div>
                        </div>
                        <div class="col-md-7">
                            <label class="fancy-label">Reward Name</label>
                            <input type="text" name="rewardName" class="form-control fancy-input" placeholder="e.g. Eco-Friendly Water Bottle" required>
                        </div>
                        <div class="col-md-5">
                            <label class="fancy-label">Category</label>
                            <select name="category" class="form-select fancy-input" required onchange="togglePrefixField('add', this.value)">
                                <option value="product">Product</option>
                                <option value="voucher">Voucher</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="fancy-label">Description</label>
                            <textarea name="description" class="form-control fancy-input" rows="2" placeholder="Brief description..." required></textarea>
                        </div>

                        <div class="col-md-12 mt-3">
                            <div class="form-section-title">Inventory & Value</div>
                        </div>
                        <div class="col-md-6">
                            <label class="fancy-label">Stock Quantity</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0" style="border-radius: 10px 0 0 10px; border-color: #cbd5e1;"><iconify-icon icon="solar:box-linear"></iconify-icon></span>
                                <input type="number" name="stockQuantity" class="form-control fancy-input border-start-0" style="border-radius: 0 10px 10px 0;" placeholder="0" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="fancy-label">Points Required</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0" style="border-radius: 10px 0 0 10px; border-color: #cbd5e1;"><iconify-icon icon="solar:star-linear" class="text-warning"></iconify-icon></span>
                                <input type="number" name="pointRequired" class="form-control fancy-input border-start-0" style="border-radius: 0 10px 10px 0;" placeholder="0" required>
                            </div>
                        </div>
                        
                        <!-- Voucher Prefix Field -->
                        <div class="col-md-6 hidden" id="addPrefixField">
                            <label class="fancy-label text-primary">Voucher Prefix</label>
                            <div class="input-group">
                                <span class="input-group-text bg-blue-light border-end-0" style="border-radius: 10px 0 0 10px; border-color: #cbd5e1;">#</span>
                                <input type="text" name="prefix" class="form-control fancy-input border-start-0" style="border-radius: 0 10px 10px 0;" placeholder="e.g. NK-ECO-">
                            </div>
                        </div>

                        <!-- Added Expiry Date to Add Modal -->
                        <div class="col-md-6">
                            <label class="fancy-label">Expiry Date (Optional)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0" style="border-radius: 10px 0 0 10px; border-color: #cbd5e1;"><iconify-icon icon="solar:calendar-linear"></iconify-icon></span>
                                <input type="date" name="expiry_date" class="form-control fancy-input border-start-0" style="border-radius: 0 10px 10px 0;">
                            </div>
                        </div>

                        <div class="col-12 mt-4 d-flex align-items-center justify-content-between border-top pt-3">
                            <div class="d-flex align-items-center gap-3">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="is_active" value="1" checked>
                                    <span class="slider"></span>
                                </label>
                                <span class="text-muted small fw-bold">Active</span>
                            </div>
                            <button type="submit" name="addReward" class="btn-create">
                                <iconify-icon icon="solar:add-circle-bold" class="me-1"></iconify-icon> Create
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
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
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('imagePreview').src = e.target.result;
            document.getElementById('imagePreview').style.display = 'block';
            document.getElementById('uploadPlaceholder').style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php include "includes/layout_end.php"; ?>
</body>
</html>