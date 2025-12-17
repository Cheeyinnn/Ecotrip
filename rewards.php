<?php
session_start(); 
include 'db_connect.php';

// Notification Function
function sendNotification($conn, $userID, $message, $link = null) {
    $stmt = $conn->prepare("INSERT INTO notifications (userID, message, link) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userID, $message, $link);
    $stmt->execute();
    $stmt->close();
}

if (!isset($_SESSION['userID'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['userID'];

$pageTitle = "Rewards Center";

// Auto-DB fix for shipping
$cols = $conn->query("SHOW COLUMNS FROM redemptionrequest LIKE 'receiver_name'");
if ($cols->num_rows == 0) { $conn->query("ALTER TABLE redemptionrequest ADD COLUMN receiver_name VARCHAR(255) NULL, ADD COLUMN phone_number VARCHAR(50) NULL, ADD COLUMN address TEXT NULL"); }

// Auto-DB fix for 'prefix'
$cols = $conn->query("SHOW COLUMNS FROM reward LIKE 'prefix'");
if ($cols->num_rows == 0) { 
    $conn->query("ALTER TABLE reward ADD COLUMN prefix VARCHAR(50) DEFAULT 'ECO-'"); 
}

// Fetch User
$stmt = $conn->prepare("SELECT * FROM user WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$_SESSION['role'] = $currentUser['role'];

$userPhone = $currentUser['phoneNumber'] ?? $currentUser['phone_number'] ?? $currentUser['phone'] ?? '';
$userAddress = $currentUser['address'] ?? '';
$userName = $currentUser['firstName'] . ' ' . $currentUser['lastName'];
$avatarPath = !empty($currentUser['avatarURL']) && file_exists(__DIR__.'/'.$currentUser['avatarURL']) ? $currentUser['avatarURL'] : 'upload/default.png';

// User Rank
$rankStmt = $conn->prepare("SELECT COUNT(*) + 1 AS rank FROM user WHERE walletPoint > ?");
$rankStmt->bind_param("i", $currentUser['walletPoint']);
$rankStmt->execute();
$userRank = $rankStmt->get_result()->fetch_assoc()['rank'];
$rankStmt->close();

// Count Redeemed Products
$prodCountStmt = $conn->prepare("SELECT COUNT(*) as count FROM redemptionrequest rr JOIN reward r ON rr.rewardID = r.rewardID WHERE rr.userID = ? AND r.category = 'product'");
$prodCountStmt->bind_param("i", $userID);
$prodCountStmt->execute();
$redeemedProductCount = $prodCountStmt->get_result()->fetch_assoc()['count'];
$prodCountStmt->close();

// Fetch Redeemed Products for Modal
$myProducts = $conn->query("SELECT 
                                rr.redemptionID, 
                                r.rewardName, 
                                r.description, 
                                r.imageURL, 
                                rr.status,
                                rr.requested_at
                            FROM redemptionrequest rr 
                            JOIN reward r ON rr.rewardID = r.rewardID 
                            WHERE rr.userID = $userID AND r.category = 'product' 
                            ORDER BY rr.redemptionID DESC");


// --- HANDLE USER CANCELLATION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancelRedemptionID'])) {
    $cancelID = $_POST['cancelRedemptionID'];
    
    // Check if pending to allow cancel
    $checkStmt = $conn->prepare("SELECT status, pointSpent, quantity, rewardID, u.firstName, u.lastName FROM redemptionrequest rr JOIN user u ON rr.userID = u.userID WHERE redemptionID = ? AND rr.userID = ?");
    $checkStmt->bind_param("ii", $cancelID, $userID);
    $checkStmt->execute();
    $checkStmt->bind_result($cStatus, $cPoints, $cQty, $cRewardID, $uFName, $uLName);
    
    if($checkStmt->fetch() && $cStatus == 'pending') {
        $checkStmt->close();
        
        // 1. Refund Points
        $refundStmt = $conn->prepare("INSERT INTO pointtransaction (userID, transactionType, pointsTransaction) VALUES (?, 'return', ?)");
        $refundStmt->bind_param("ii", $userID, $cPoints);
        $refundStmt->execute(); $refundStmt->close();
        
        // 2. Return Stock
        $stockStmt = $conn->prepare("UPDATE reward SET stockQuantity = stockQuantity + ? WHERE rewardID = ?");
        $stockStmt->bind_param("ii", $cQty, $cRewardID);
        $stockStmt->execute(); $stockStmt->close();
        
        // 3. Update Status
        $updateStmt = $conn->prepare("UPDATE redemptionrequest SET status = 'cancelled' WHERE redemptionID = ?");
        $updateStmt->bind_param("i", $cancelID);
        $updateStmt->execute(); $updateStmt->close();

        // 4. Notify Admins
        $msgAdmin = "User " . $uFName . " " . $uLName . " has cancelled their redemption request #" . $cancelID;
        $adminQuery = "SELECT userID FROM user WHERE role IN ('admin', 'moderator')";
        $adminResult = $conn->query($adminQuery);
        while($admin = $adminResult->fetch_assoc()) {
            sendNotification($conn, $admin['userID'], $msgAdmin, "reviewRR.php");
        }
        
        header("Location: rewards.php?msg=cancelled");
        exit();
    }
    $checkStmt->close();
}


// --- HANDLE REDEMPTION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['rewardID']) && !isset($_POST['cancelRedemptionID'])) {
    $rewardID = $_POST['rewardID'];
    $quantity = $_POST['quantity'];
    $receiverName = $_POST['receiverName'] ?? $userName;
    $phoneNumber = $_POST['phoneNumber'] ?? $userPhone;
    $address = $_POST['address'] ?? $userAddress;

    $stmt = $conn->prepare("SELECT walletPoint FROM user WHERE userID = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $stmt->bind_result($currentUserPoints);
    $stmt->fetch(); $stmt->close();

    $stmt = $conn->prepare("SELECT stockQuantity, is_active, pointRequired, category, rewardName FROM reward WHERE rewardID = ?");
    $stmt->bind_param("i", $rewardID);
    $stmt->execute();
    $stmt->bind_result($stockQuantity, $isActive, $singlePointReq, $category, $rewardName);
    $stmt->fetch(); $stmt->close();

    $pointsRequired = $singlePointReq * $quantity;

    if ($isActive != 1 || $quantity > $stockQuantity || $currentUserPoints < $pointsRequired) {
        echo "<script>alert('Error: Unavailable or insufficient points.');</script>";
    } else {
        $isVoucher = (strtolower($category) === 'voucher');
        $status = $isVoucher ? 'approved' : 'pending';

        $sql = "INSERT INTO redemptionrequest (userID, rewardID, quantity, pointSpent, status, receiver_name, phone_number, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiiissss", $userID, $rewardID, $quantity, $pointsRequired, $status, $receiverName, $phoneNumber, $address);
        $stmt->execute(); 
        $newRedemptionID = $stmt->insert_id; 
        $stmt->close();

        $sql = "INSERT INTO pointtransaction (userID, transactionType, pointsTransaction) VALUES (?, 'burn', ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $userID, $pointsRequired);
        $stmt->execute(); $stmt->close();

        $sql = "UPDATE reward SET stockQuantity = stockQuantity - ? WHERE rewardID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $quantity, $rewardID);
        $stmt->execute(); $stmt->close();

        if ($status === 'pending') {
            $msgNewReq = "New redemption request #" . $newRedemptionID . " from " . $userName . " for " . $rewardName;
            $adminQuery = "SELECT userID FROM user WHERE role IN ('admin', 'moderator')";
            $adminResult = $conn->query($adminQuery);
            while($admin = $adminResult->fetch_assoc()) {
                sendNotification($conn, $admin['userID'], $msgNewReq, "reviewRR.php");
            }
        }
        
        $notifMsg = "You have successfully redeemed a " . $rewardName . " " . ucfirst($category);
        sendNotification($conn, $userID, $notifMsg, "rewards.php");

        if ($isVoucher) {
            header("Location: rewards.php?msg=approved&voucher_id=$newRedemptionID");
        } else {
            header("Location: rewards.php?msg=pending");
        }
        exit();
    }
}

// --- FETCH NEW VOUCHER DETAILS ---
$newVoucherData = null;
if (isset($_GET['voucher_id'])) {
    $vSql = "SELECT 
                rr.redemptionID, 
                r.rewardName, 
                r.description, 
                r.imageURL, 
                r.barcodeURL, 
                r.prefix,
                r.expiry_date,
                (
                    SELECT COUNT(*) 
                    FROM redemptionrequest rr2 
                    WHERE rr2.rewardID = rr.rewardID 
                    AND rr2.redemptionID <= rr.redemptionID
                ) as voucher_serial
             FROM redemptionrequest rr 
             JOIN reward r ON rr.rewardID = r.rewardID 
             WHERE rr.redemptionID = ? AND rr.userID = ?";
             
    $vStmt = $conn->prepare($vSql);
    $vStmt->bind_param("ii", $_GET['voucher_id'], $userID);
    $vStmt->execute();
    $newVoucherData = $vStmt->get_result()->fetch_assoc();
    $vStmt->close();
}

// --- FETCH ALL MY VOUCHERS ---
$myVouchers = $conn->query("SELECT 
                                rr.redemptionID, 
                                r.rewardName, 
                                r.description, 
                                r.imageURL, 
                                r.barcodeURL, 
                                r.prefix,
                                r.expiry_date,
                                (
                                    SELECT COUNT(*) 
                                    FROM redemptionrequest rr2 
                                    WHERE rr2.rewardID = rr.rewardID 
                                    AND rr2.redemptionID <= rr.redemptionID
                                ) as voucher_serial
                            FROM redemptionrequest rr 
                            JOIN reward r ON rr.rewardID = r.rewardID 
                            WHERE rr.userID = $userID AND r.category = 'voucher' AND rr.status = 'approved' 
                            ORDER BY rr.redemptionID DESC");

$userPoints = $currentUser['walletPoint'];

// --- SORTING LOGIC ---
$rewards_result = $conn->query("SELECT * FROM reward");
$rewardsArray = [];
while ($row = $rewards_result->fetch_assoc()) {
    $row['can_afford'] = ($userPoints >= $row['pointRequired']);
    $rewardsArray[] = $row;
}
usort($rewardsArray, function($a, $b) {
    if ($a['can_afford'] === $b['can_afford']) { return $a['pointRequired'] - $b['pointRequired']; }
    return $b['can_afford'] ? 1 : -1; 
});

$requests_result = $conn->query("SELECT rr.redemptionID, rw.rewardName, rr.quantity, rr.pointSpent, rr.status, rr.rewardID, rr.requested_at FROM redemptionrequest rr LEFT JOIN reward rw ON rr.rewardID = rw.rewardID WHERE rr.userID = $userID ORDER BY rr.redemptionID DESC");

include "includes/layout_start.php";
?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

    <style>
        body { margin: 0; background: #f5f7fb; font-family: 'Plus Jakarta Sans', sans-serif; }
    
        .dashboard-row { display: flex; gap: 20px; margin-bottom: 35px; flex-wrap: wrap; }
        .dash-card { flex: 1; min-width: 250px; background: #fff; border-radius: 20px; padding: 15px 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; align-items: center; position: relative; overflow: hidden; height: 120px; }
        
        .profile-card-content { display: flex; align-items: center; gap: 15px; width: 100%; z-index: 2; }
        .profile-card-bg { 
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%); 
            color: white;
            border: none;
            box-shadow: 0 10px 25px rgba(30, 41, 59, 0.15);
        }
        .profile-img-container { width: 60px; height: 60px; border-radius: 50%; padding: 2px; border: 2px solid rgba(255,255,255,0.2); flex-shrink: 0; }
        .profile-img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .profile-text { display: flex; flex-direction: column; justify-content: center; }
        .profile-greeting { font-size: 16px; font-weight: 700; color: #fff; margin: 0; line-height: 1.2; }
        .profile-points-label { font-size: 11px; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
        .profile-points-val { font-size: 28px; font-weight: 800; color: #4ade80; line-height: 1.1; margin-top: 2px; } 
        
        .analysis-card { cursor: pointer; transition: 0.2s; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }
        .analysis-card:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(37, 99, 235, 0.1); border-color: #2563eb; }
        .analysis-title { font-size: 16px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 8px; }
        .analysis-desc { font-size: 12px; color: #94a3b8; }
        .analysis-icon { position: absolute; right: 15px; bottom: 10px; font-size: 60px; color: #2563eb; opacity: 0.1; transform: rotate(-15deg); }
        .analysis-number { font-size: 28px; font-weight: 800; color: #1e293b; margin-top: 5px; }

        .filter-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .segmented-control { display: inline-flex; gap: 5px; }
        .segment-btn { padding: 8px 24px; border: none; background: #f1f5f9; border-radius: 50px; font-weight: 600; color: #64748b; font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .segment-btn:hover { background: #e2e8f0; color: #334155; }
        .segment-btn.active { background: #ffffff; color: #16a34a; box-shadow: 0 2px 5px rgba(0,0,0,0.08); }

        .modal-filter-btn { padding: 6px 14px; border: 1px solid #e2e8f0; background: white; border-radius: 20px; font-size: 12px; font-weight: 600; color: #64748b; cursor: pointer; transition: all 0.2s; }
        .modal-filter-btn:hover { background: #f8fafc; color: #334155; }
        .modal-filter-btn.active { background: #eff6ff; color: #2563eb; border-color: #2563eb; }
        .modal-filter-group { display: flex; gap: 5px; }

        .fixed-height-list {
            height: 400px; 
            overflow-y: auto; 
            display: flex;
            flex-direction: column;
        }
        .empty-state-msg {
            flex-grow: 1; 
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #94a3b8;
            font-weight: 500;
        }

        .category-pills .pill { padding: 6px 16px; border: 1px solid #e2e8f0; border-radius: 20px; background: white; color: #64748b; font-size: 13px; font-weight: 600; cursor: pointer; margin-left: 8px; transition: all 0.2s; }
        .category-pills .pill:hover { background: #f8fafc; }
        .category-pills .pill.active { background: #eff6ff; color: #2563eb; border-color: #2563eb; }

        .rewards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 25px; margin-bottom: 50px; }
        .reward-card { background: white; border-radius: 20px; overflow: hidden; border: 1px solid #f1f5f9; transition: 0.2s; cursor: pointer; display: flex; flex-direction: column; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .reward-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); border-color: #2563eb; }
        
        .card-img-top { height: 200px; background: #f8fafc; display: flex; align-items: center; justify-content: center; position: relative; padding: 0; }
        .card-img-top img { width: 100%; height: 100%; object-fit: cover; }
        
        .category-badge { position: absolute; top: 15px; right: 15px; background: rgba(255,255,255,0.9); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; color: #475569; box-shadow: 0 2px 4px rgba(0,0,0,0.05); text-transform: uppercase; }
        
        .card-body { padding: 20px; flex-grow: 1; display: flex; flex-direction: column; }
        .card-title { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 10px; }
        
        .card-info-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 13px; font-weight: 600; color: #64748b; }
        .card-points { color: #1e293b; font-size: 14px; }
        .card-status-text { color: #64748b; }
        .card-status-text.redeemable { color: #16a34a; }

        .progress-bar-bg { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-bottom: 15px; }
        .progress-fill { height: 100%; background: #2563eb; border-radius: 3px; }
        .progress-fill.green { background: #16a34a; }
        
        .redeem-btn-sm { font-size: 13px; font-weight: 700; color: #2563eb; background: #eff6ff; padding: 10px; border-radius: 8px; text-align: center; margin-top: auto; transition: 0.2s; }
        .reward-card:hover .redeem-btn-sm { background: #2563eb; color: white; }

        /* Modal Styles */
        .modal-reward-img { width: 100%; height: 250px; object-fit: contain; background: #f8fafc; border-radius: 12px; margin-bottom: 20px; }
        
        /* Voucher Ticket */
        .voucher-ticket { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); margin: 10px auto; max-width: 360px; position: relative; }
        .voucher-header { background: #d32f2f; color: white; padding: 20px; text-align: center; }
        .voucher-body { background: white; padding: 25px 20px; text-align: center; }
        .voucher-img { width: 100px; height: 100px; object-fit: contain; margin-bottom: 15px; }
        
        #barcode { width: 100%; max-width: 250px; height: auto; margin-top: 15px; }
        
        .my-vouchers-list .list-group-item { cursor: pointer; transition: 0.2s; }
        .my-vouchers-list .list-group-item:hover { background: #f8fafc; }

        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .status-pending { background: #fff7ed; color: #ea580c; }
        .status-approved { background: #f0fdf4; color: #16a34a; }
        .status-rejected { background: #fef2f2; color: #dc2626; }
        .status-delivery { background: #eff6ff; color: #2563eb; }
        .status-delivered { background: #f0fdfa; color: #0d9488; }

        .barcode-blur {
            filter: blur(5px);
            opacity: 0.5;
            pointer-events: none;
            user-select: none;
        }
        .expired-badge {
            background-color: #fee2e2;
            color: #ef4444;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            margin-left: auto;
        }

        .hidden { display: none !important; }
    </style>
<body>

        <div class="content-wrapper">
            <?php if(isset($_GET['msg']) && $_GET['msg'] == 'approved'): ?>
            <div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><strong>Redemption Approved!</strong> Your item has been approved.</div>
            <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'pending'): ?>
            <div class="alert alert-info border-0 shadow-sm"><i class="fas fa-clock me-2"></i><strong>Request Sent!</strong> Shipping soon.</div>
            <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'cancelled'): ?>
            <div class="alert alert-warning border-0 shadow-sm"><i class="fas fa-info-circle me-2"></i><strong>Cancelled!</strong> Request cancelled and points refunded.</div>
            <?php endif; ?>

            <!-- Dashboard Row -->
            <div class="dashboard-row">
                <!-- 1. Profile (Points Available) -->
                <div class="dash-card profile-card-bg">
                    <div class="profile-card-content">
                        <div class="profile-img-container"><img src="<?php echo htmlspecialchars($avatarPath); ?>" class="profile-img"></div>
                        <div class="profile-text">
                            <h4 class="profile-greeting">Hi, <?php echo htmlspecialchars($userName); ?></h4>
                            <div class="profile-points-label">Your Available Points</div>
                            <div class="profile-points-val"><?php echo number_format($userPoints); ?></div>
                        </div>
                    </div>
                    <iconify-icon icon="solar:wallet-money-bold-duotone" style="position:absolute; right:-10px; bottom:-20px; font-size:100px; color:rgba(255,255,255,0.1); transform:rotate(15deg);"></iconify-icon>
                </div>
                <!-- 2. My Products -->
                <div class="dash-card analysis-card" data-bs-toggle="modal" data-bs-target="#myProductsModal">
                    <div class="analysis-content">
                        <div class="analysis-title">My Products <iconify-icon icon="solar:box-bold-duotone" class="text-warning"></iconify-icon></div>
                        <div class="analysis-number text-warning"><?php echo number_format($redeemedProductCount); ?></div>
                        <div class="analysis-desc">Total products claimed.</div>
                    </div>
                    <iconify-icon icon="solar:bag-heart-bold-duotone" class="analysis-icon text-warning" style="color: #f59e0b !important;"></iconify-icon>
                </div>
                <!-- 3. My Vouchers -->
                <div class="dash-card analysis-card" data-bs-toggle="modal" data-bs-target="#myVouchersModal">
                    <div class="analysis-content">
                        <div class="analysis-title">My Vouchers <iconify-icon icon="solar:ticket-bold-duotone" class="text-success"></iconify-icon></div>
                        <div class="analysis-desc">Active tickets.</div>
                    </div>
                    <iconify-icon icon="solar:ticket-star-bold-duotone" class="analysis-icon text-success" style="color: #16a34a !important;"></iconify-icon>
                </div>
                <!-- 4. History -->
                <div class="dash-card analysis-card" data-bs-toggle="modal" data-bs-target="#historyModal">
                    <div class="analysis-content">
                        <div class="analysis-title">History <iconify-icon icon="solar:history-bold-duotone" class="text-primary"></iconify-icon></div>
                        <div class="analysis-desc">Redemption logs.</div>
                    </div>
                    <iconify-icon icon="solar:clock-circle-bold-duotone" class="analysis-icon"></iconify-icon>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-container d-flex justify-content-between mb-3">
                <div class="segmented-control">
                    <button class="segment-btn active" onclick="filterRewards('available', this)">Available</button>
                    <button class="segment-btn" onclick="filterRewards('unavailable', this)">Unavailable</button>
                </div>
                <div class="category-pills">
                    <span class="pill active" onclick="filterCategory('all', this)">All Items</span>
                    <span class="pill" onclick="filterCategory('voucher', this)">Vouchers</span>
                    <span class="pill" onclick="filterCategory('product', this)">Products</span>
                </div>
            </div>

            <!-- Grid -->
            <div class="rewards-grid" id="rewardsGrid">
                <?php if (count($rewardsArray) > 0) { foreach ($rewardsArray as $row) {
                    $isActive = $row['is_active'] == 1;
                    $inStock = $row['stockQuantity'] > 0;
                    $canAfford = $row['can_afford'];
                    $canRedeem = $isActive && $inStock && $canAfford;
                    $affordability = ($userPoints > 0 && $row['pointRequired'] > 0) ? min(100, ($userPoints / $row['pointRequired']) * 100) : 0;
                    $category = isset($row['category']) ? strtolower($row['category']) : 'product'; 
                    $img = !empty($row['imageURL']) ? $row['imageURL'] : 'upload/reward_placeholder.png';
                    
                    $rewardData = htmlspecialchars(json_encode([
                        'id' => $row['rewardID'],
                        'name' => $row['rewardName'],
                        'desc' => $row['description'],
                        'points' => $row['pointRequired'],
                        'stock' => $row['stockQuantity'],
                        'img' => $img,
                        'canRedeem' => $canRedeem,
                        'active' => $isActive,
                        'inStock' => $inStock,
                        'category' => $category,
                        'prefix' => $row['prefix'] ?? null 
                    ]), ENT_QUOTES, 'UTF-8');

                    $statusText = $canAfford ? 'Redeemable' : 'Need ' . number_format($row['pointRequired'] - $userPoints) . ' pts';
                    $statusClass = $canAfford ? 'redeemable' : '';
                ?>
                <div class="reward-card" data-status="<?php echo ($isActive && $inStock) ? 'available' : 'unavailable'; ?>" data-category="<?php echo $category; ?>" onclick="openRewardModal(<?php echo $rewardData; ?>)">
                    <div class="card-img-top"><img src="<?php echo htmlspecialchars($img); ?>" alt="Reward"><div class="category-badge"><?php echo ucfirst($category); ?></div></div>
                    <div class="card-body">
                        <h5 class="card-title"><?php echo htmlspecialchars($row['rewardName']); ?></h5>
                        
                        <div class="card-info-row">
                            <span class="card-points"><?php echo number_format($row['pointRequired']); ?> pts</span>
                            <span class="card-status-text <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                        </div>
                        
                        <div class="progress-bar-bg mb-2"><div class="progress-fill <?php echo $affordability >= 100 ? 'green' : 'bg-primary'; ?>" style="width: <?php echo $affordability; ?>%;"></div></div>
                        <div class="redeem-btn-sm">View Details</div>
                    </div>
                </div>
                <?php }} ?>
            </div>
        </div>
    </div>
</div>

<!-- History Modal (Redesigned) -->
<div class="modal fade" id="historyModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 bg-white pb-0">
        <div>
            <h5 class="modal-title fw-bold text-dark">Redemption History</h5>
            <p class="text-muted small mb-0">Track your past rewards and status.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-3">
        <div class="history-list-container" style="max-height: 450px; overflow-y: auto;">
            <?php if ($requests_result->num_rows > 0): ?>
                <div class="list-group list-group-flush">
                    <?php while ($req = $requests_result->fetch_assoc()) { 
                        $canCancel = ($req['status'] == 'pending');
                        $statusClass = 'bg-secondary';
                        $statusText = ucfirst($req['status']);
                        
                        if($req['status'] == 'approved') $statusClass = 'bg-success';
                        elseif($req['status'] == 'pending') $statusClass = 'bg-warning text-dark';
                        elseif($req['status'] == 'cancelled') $statusClass = 'bg-light text-muted border';
                        elseif($req['status'] == 'denied') $statusClass = 'bg-danger';
                        elseif($req['status'] == 'outOfDiliver') { $statusClass = 'bg-primary'; $statusText = 'Out for Delivery'; }
                        elseif($req['status'] == 'Delivered') $statusClass = 'bg-info text-white';
                    ?>
                    <div class="list-group-item border-0 p-3 mb-2 rounded-3 bg-light d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div class="history-icon bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 45px; height: 45px;">
                                <iconify-icon icon="solar:gift-bold-duotone" class="text-primary fs-4"></iconify-icon>
                            </div>
                            <div>
                                <h6 class="mb-1 fw-bold text-dark"><?php echo htmlspecialchars($req['rewardName']); ?></h6>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge rounded-pill <?php echo $statusClass; ?> fw-normal px-2 py-1" style="font-size: 10px;">
                                        <?php echo $statusText; ?>
                                    </span>
                                    <small class="text-muted" style="font-size: 11px;">
                                        <?php echo date('M d, Y h:i A', strtotime($req['requested_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-danger mb-1">-<?php echo number_format($req['pointSpent']); ?> pts</div>
                            <?php if($canCancel): ?>
                                <form method="POST" onsubmit="return confirm('Cancel this request?');">
                                    <input type="hidden" name="cancelRedemptionID" value="<?php echo $req['redemptionID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 11px;">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <iconify-icon icon="solar:history-bold-duotone" class="fs-1 mb-2 opacity-25"></iconify-icon>
                    <p>No history found.</p>
                </div>
            <?php endif; ?>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <a href="dashboard_user.php" class="btn btn-dark w-100 fw-bold">Go to Dashboard</a>
      </div>
    </div>
  </div>
</div>

<!-- MY VOUCHERS LIST MODAL -->
<div class="modal fade" id="myVouchersModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 bg-light"><h5 class="modal-title fw-bold">My Vouchers</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body p-0">
          <div class="list-group list-group-flush my-vouchers-list fixed-height-list">
              <?php if($myVouchers->num_rows > 0): ?>
                  <?php while($v = $myVouchers->fetch_assoc()): 
                      $isExpired = false;
                      if (!empty($v['expiry_date']) && strtotime($v['expiry_date']) < time()) {
                          $isExpired = true;
                      }
                      $v['isExpired'] = $isExpired;
                      $vJson = htmlspecialchars(json_encode($v), ENT_QUOTES, 'UTF-8');
                  ?>
                  <div class="list-group-item d-flex align-items-center p-3" onclick="openVoucherFromList(<?php echo $vJson; ?>)">
                      <img src="<?php echo !empty($v['imageURL']) ? $v['imageURL'] : 'upload/reward_placeholder.png'; ?>" style="width:50px; height:50px; object-fit:cover; border-radius:8px; margin-right:15px;">
                      <div class="flex-grow-1">
                          <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($v['rewardName']); ?></h6>
                          <small class="text-muted">ID: #<?php echo str_pad($v['redemptionID'], 8, '0', STR_PAD_LEFT); ?></small>
                      </div>
                      <?php if($isExpired): ?>
                        <span class="expired-badge">Expired</span>
                      <?php else: ?>
                        <iconify-icon icon="solar:alt-arrow-right-linear" class="text-muted"></iconify-icon>
                      <?php endif; ?>
                  </div>
                  <?php endwhile; ?>
              <?php else: ?>
                  <div class="empty-state-msg">No active vouchers found.</div>
              <?php endif; ?>
          </div>
      </div>
    </div>
  </div>
</div>

<!-- MY PRODUCT LIST MODAL (Updated Title & Filter Buttons) -->
<div class="modal fade" id="myProductsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 bg-light d-flex align-items-center justify-content-between flex-wrap gap-2">
          <h5 class="modal-title fw-bold m-0">My Products</h5>
          <div class="d-flex align-items-center gap-3">
              <div class="modal-filter-group">
                  <button class="modal-filter-btn active" onclick="filterMyProducts('all', this)">All</button>
                  <button class="modal-filter-btn" onclick="filterMyProducts('pending', this)">Pending</button>
                  <button class="modal-filter-btn" onclick="filterMyProducts('approved', this)">Approved</button>
                  <button class="modal-filter-btn" onclick="filterMyProducts('outOfDiliver', this)">Out for Delivery</button>
                  <button class="modal-filter-btn" onclick="filterMyProducts('Delivered', this)">Delivered</button>
                  <button class="modal-filter-btn" onclick="filterMyProducts('denied', this)">Denied</button>
              </div>
              <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button>
          </div>
      </div>
      <div class="modal-body p-0">
          <div class="list-group list-group-flush fixed-height-list" id="myProductsList">
              <?php if($myProducts->num_rows > 0): ?>
                  <?php while($p = $myProducts->fetch_assoc()): 
                      $statusColor = 'status-pending';
                      $statusVal = strtolower($p['status'] ?? 'pending');
                      if($p['status'] == 'outOfDiliver') $statusVal = 'outOfDiliver';
                      if($p['status'] == 'Delivered') $statusVal = 'Delivered';

                      $canCancel = ($statusVal == 'pending');
                      
                      if($statusVal == 'approved') $statusColor = 'status-approved';
                      elseif($statusVal == 'outOfDiliver') $statusColor = 'status-delivery';
                      elseif($statusVal == 'Delivered') $statusColor = 'status-delivered';
                      elseif($statusVal == 'rejected' || $statusVal == 'denied') { 
                          $statusColor = 'status-rejected'; 
                          $statusVal = 'denied'; 
                      }
                      
                      $displayText = ucfirst($p['status'] == 'rejected' ? 'denied' : $p['status']);
                      if($p['status'] == 'outOfDiliver') $displayText = 'Out for Delivery';
                  ?>
                  <div class="list-group-item d-flex align-items-center p-3 product-item" data-status="<?php echo $statusVal; ?>">
                      <img src="<?php echo !empty($p['imageURL']) ? $p['imageURL'] : 'upload/reward_placeholder.png'; ?>" style="width:50px; height:50px; object-fit:cover; border-radius:8px; margin-right:15px;">
                      <div class="flex-grow-1">
                          <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($p['rewardName']); ?></h6>
                          <div class="d-flex align-items-center gap-2">
                              <span class="status-badge <?php echo $statusColor; ?>"><?php echo $displayText; ?></span>
                              <small class="text-muted"><?php echo date('M d, Y', strtotime($p['requested_at'])); ?></small>
                          </div>
                      </div>
                      <?php if($canCancel): ?>
                        <form method="POST" onsubmit="return confirm('Cancel this redemption?');" class="ms-3">
                            <input type="hidden" name="cancelRedemptionID" value="<?php echo $p['redemptionID']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 11px; padding: 4px 10px;">Cancel</button>
                        </form>
                      <?php endif; ?>
                  </div>
                  <?php endwhile; ?>
              <?php else: ?>
                  <div class="empty-state-msg">No products redeemed yet.</div>
              <?php endif; ?>
              <div class="empty-state-msg dynamic-empty-msg hidden">No products found for this status.</div>
          </div>
      </div>
    </div>
  </div>
</div>

<!-- REWARD DETAIL MODAL -->
<div class="modal fade" id="rewardModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4"><div class="modal-body p-4"><h4 class="fw-bold mb-2" id="modalRewardName">Reward</h4><img id="modalRewardImg" src="" class="modal-reward-img"><p class="text-muted" id="modalRewardDesc"></p><div class="d-flex justify-content-between mb-4"><span class="fw-bold text-primary" id="modalRewardPoints"></span><span class="fw-bold" id="modalRewardStock"></span></div><form action="rewards.php" method="POST" id="modalRedeemForm" onsubmit="return confirm('Confirm redemption?');"><input type="hidden" name="rewardID" id="modalFormRewardID"><input type="hidden" name="quantity" value="1"><div id="shippingSection" class="hidden mb-3 p-3 bg-light rounded-3 border"><input type="text" name="receiverName" id="shipName" class="form-control form-control-sm mb-2" placeholder="Name" required><input type="text" name="phoneNumber" id="shipPhone" class="form-control form-control-sm mb-2" placeholder="Phone" required><textarea name="address" id="shipAddress" class="form-control form-control-sm" placeholder="Address" rows="2" required></textarea></div><button type="submit" id="modalRedeemBtn" class="btn btn-dark w-100 py-3 fw-bold rounded-3">Redeem Now</button></form><div id="modalUnavailableMsg" class="text-center text-danger fw-bold mt-2 hidden"></div></div></div></div></div>

<!-- VOUCHER TICKET MODAL -->
<div class="modal fade" id="voucherModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 bg-transparent">
      <div class="voucher-ticket">
          <div class="voucher-header">
              <h3 id="ticketTitle"><?php echo htmlspecialchars($newVoucherData['rewardName'] ?? 'VOUCHER'); ?></h3>
          </div>
          <div class="voucher-body">
              <img id="ticketImg" src="<?php echo htmlspecialchars(!empty($newVoucherData['imageURL']) ? $newVoucherData['imageURL'] : 'upload/reward_placeholder.png'); ?>" class="voucher-img" style="width:100px; height:100px; object-fit:contain;">
              <p class="fw-bold mb-1" id="voucherStatusText">Valid Voucher</p>
              <p class="voucher-desc" id="ticketDesc"><?php echo htmlspecialchars($newVoucherData['description'] ?? ''); ?></p>
              <hr class="border-dashed my-3">
              <div id="ticketBarcodeContainer" class="text-center">
                  <svg id="barcode"></svg>
              </div>
              <span class="d-block mt-2 text-muted small letter-spacing-2" id="ticketID"></span>
          </div>
      </div>
      <button type="button" class="btn btn-light w-100 mt-3 fw-bold" onclick="voucherModal.hide()">Close</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function generateBarcode(serial, prefix, productName) {
        let codePrefix = "";
        if (prefix && prefix !== "null" && prefix !== "") {
            codePrefix = prefix;
        } else if (productName) {
            codePrefix = productName.replace(/[^a-zA-Z0-9]/g, '').substring(0, 4).toUpperCase() + '-';
        } else {
            codePrefix = "ECO-";
        }
        if (codePrefix.slice(-1) !== '-') { codePrefix += '-'; }
        let stringValue = serial.toString().padStart(6, '0');
        let fullCode = codePrefix + stringValue;
        JsBarcode("#barcode", fullCode, {
            format: "CODE128",
            lineColor: "#334155",
            width: 2,
            height: 50,
            displayValue: true, 
            fontSize: 14,
            textMargin: 5
        });
        document.getElementById('ticketID').innerText = fullCode;
    }

    const rewardModalEl = document.getElementById('rewardModal');
    const voucherModalEl = document.getElementById('voucherModal');
    const myVouchersModalEl = document.getElementById('myVouchersModal');
    const rewardModal = new bootstrap.Modal(rewardModalEl);
    let voucherModal = bootstrap.Modal.getInstance(voucherModalEl);
    if(!voucherModal) { voucherModal = new bootstrap.Modal(voucherModalEl); }
    
    <?php if ($newVoucherData): ?> 
        generateBarcode(
            '<?php echo $newVoucherData['voucher_serial']; ?>', 
            '<?php echo !empty($newVoucherData['prefix']) ? $newVoucherData['prefix'] : ''; ?>', 
            '<?php echo addslashes($newVoucherData['rewardName']); ?>'
        );
        voucherModal.show();
    <?php endif; ?>
    
    const userData = { name: "<?php echo addslashes($userName); ?>", phone: "<?php echo addslashes($userPhone); ?>", address: "<?php echo addslashes(str_replace(["\r", "\n"], ' ', $userAddress)); ?>" };

    function openRewardModal(data) {
        document.getElementById('modalRewardName').innerText = data.name;
        document.getElementById('modalRewardImg').src = data.img;
        document.getElementById('modalRewardPoints').innerText = Number(data.points).toLocaleString() + ' pts';
        document.getElementById('modalRewardStock').innerText = data.stock + ' Left';
        document.getElementById('modalRewardDesc').innerText = data.desc;
        document.getElementById('modalFormRewardID').value = data.id;
        const shipSection = document.getElementById('shippingSection');
        const inputs = shipSection.querySelectorAll('input, textarea');
        if (data.category.toLowerCase() === 'product') {
            shipSection.classList.remove('hidden');
            if(!document.getElementById('shipName').value) document.getElementById('shipName').value = userData.name;
            if(!document.getElementById('shipPhone').value) document.getElementById('shipPhone').value = userData.phone;
            if(!document.getElementById('shipAddress').value) document.getElementById('shipAddress').value = userData.address;
            inputs.forEach(i => i.required = true);
        } else {
            shipSection.classList.add('hidden');
            inputs.forEach(i => i.required = false);
        }
        const btn = document.getElementById('modalRedeemBtn');
        const msg = document.getElementById('modalUnavailableMsg');
        if (data.canRedeem) { btn.disabled = false; btn.classList.remove('hidden'); msg.classList.add('hidden'); } 
        else { btn.disabled = true; btn.classList.add('hidden'); msg.classList.remove('hidden'); msg.innerText = !data.active ? "Unavailable" : (!data.inStock ? "Out of Stock" : "Not Enough Points"); }
        rewardModal.show();
    }

    function openVoucherFromList(v) {
        document.getElementById('ticketTitle').innerText = v.rewardName;
        document.getElementById('ticketDesc').innerText = v.description;
        document.getElementById('ticketImg').src = v.imageURL || 'upload/reward_placeholder.png';
        const statusText = document.getElementById('voucherStatusText');
        const barcodeContainer = document.getElementById('ticketBarcodeContainer');
        const ticketID = document.getElementById('ticketID');
        if (v.isExpired) {
            statusText.innerText = "Expired Voucher";
            statusText.classList.remove('text-success');
            statusText.classList.add('text-danger');
            barcodeContainer.classList.add('barcode-blur');
            ticketID.classList.add('barcode-blur');
        } else {
            statusText.innerText = "Valid Voucher";
            statusText.classList.remove('text-danger');
            statusText.classList.add('text-success');
            barcodeContainer.classList.remove('barcode-blur');
            ticketID.classList.remove('barcode-blur');
        }
        generateBarcode(v.voucher_serial, v.prefix, v.rewardName);
        const listModal = bootstrap.Modal.getInstance(myVouchersModalEl);
        if(listModal) listModal.hide();
        voucherModal.show();
    }

    function filterMyProducts(status, btn) {
        if (btn) {
            document.querySelectorAll('.modal-filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }
        const items = document.querySelectorAll('#myProductsList .product-item');
        let visibleCount = 0;
        const listContainer = document.getElementById('myProductsList');
        const existingMsg = listContainer.querySelector('.dynamic-empty-msg');
        if(existingMsg) existingMsg.remove();

        items.forEach(item => {
            if (status === 'all' || item.getAttribute('data-status') === status) {
                item.classList.remove('hidden');
                visibleCount++;
            } else {
                item.classList.add('hidden');
            }
        });

        if(visibleCount === 0) {
            const msg = document.createElement('div');
            msg.className = 'empty-state-msg dynamic-empty-msg';
            msg.innerText = 'No ' + status + ' products found.';
            listContainer.appendChild(msg);
        }
    }

    function filterRewards(status, btn) { if(btn){document.querySelectorAll('.segment-btn').forEach(b => b.classList.remove('active')); btn.classList.add('active');} applyFilters('status', status); }
    function filterCategory(category, btn) { if(btn){document.querySelectorAll('.category-pills .pill').forEach(b => b.classList.remove('active')); btn.classList.add('active');} applyFilters('category', category); }
    let filters = { status: 'available', category: 'all' };
    function applyFilters(type, val) {
        if(type) filters[type] = val;
        document.querySelectorAll('.reward-card').forEach(card => {
            const statusMatch = (filters.status === 'all') || (card.getAttribute('data-status') === filters.status);
            const categoryMatch = (filters.category === 'all') || (card.getAttribute('data-category') === filters.category);
            if (statusMatch && categoryMatch) card.classList.remove('hidden'); else card.classList.add('hidden');
        });
    }
    applyFilters(); 
</script>
</body>
</html>