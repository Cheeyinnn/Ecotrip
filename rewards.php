<?php    
session_start(); 
include 'db_connect.php';

if (!isset($_SESSION['userID'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['userID'];

$pageTitle = "Rewards Center";

// Auto-DB fix for shipping
$cols = $conn->query("SHOW COLUMNS FROM redemptionrequest LIKE 'receiver_name'");
if ($cols->num_rows == 0) { $conn->query("ALTER TABLE redemptionrequest ADD COLUMN receiver_name VARCHAR(255) NULL, ADD COLUMN phone_number VARCHAR(50) NULL, ADD COLUMN address TEXT NULL"); }

// --- UPDATED: Auto-DB fix for 'prefix' ---
// Checks for 'prefix' column. If missing, adds it with default 'ECO-'
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

    $stmt = $conn->prepare("SELECT stockQuantity, is_active, pointRequired, category FROM reward WHERE rewardID = ?");
    $stmt->bind_param("i", $rewardID);
    $stmt->execute();
    $stmt->bind_result($stockQuantity, $isActive, $singlePointReq, $category);
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

        if ($isVoucher) {
            header("Location: rewards.php?msg=approved&voucher_id=$newRedemptionID");
        } else {
            header("Location: rewards.php?msg=pending");
        }
        exit();
    }
}

// --- FETCH NEW VOUCHER DETAILS WITH SERIAL NUMBER CALCULATION ---
// This subquery counts how many times *this specific reward* has been redeemed up to this ID.
// It creates a sequence (1, 2, 3) ignoring other products.
$newVoucherData = null;
if (isset($_GET['voucher_id'])) {
    $vSql = "SELECT 
                rr.redemptionID, 
                r.rewardName, 
                r.description, 
                r.imageURL, 
                r.barcodeURL, 
                r.prefix,
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

// --- FETCH ALL MY VOUCHERS WITH SERIAL NUMBER ---
$myVouchers = $conn->query("SELECT 
                                rr.redemptionID, 
                                r.rewardName, 
                                r.description, 
                                r.imageURL, 
                                r.barcodeURL, 
                                r.prefix,
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rewards - EcoTrip</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        
        body { margin: 0; background: #f5f7fb; font-family: 'Plus Jakarta Sans', sans-serif; }
    
        .dashboard-row { display: flex; gap: 20px; margin-bottom: 35px; flex-wrap: wrap; }
        .dash-card { flex: 1; min-width: 300px; background: #fff; border-radius: 20px; padding: 15px 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; align-items: center; position: relative; overflow: hidden; }
        .profile-card-content { display: flex; align-items: center; gap: 20px; width: 100%; z-index: 2; }
        .profile-img-container { width: 70px; height: 70px; border-radius: 50%; padding: 3px; border: 2px solid #e2e8f0; }
        .profile-img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .stats-group { text-align: center; }
        .stats-label { font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; }
        .stats-value { font-size: 24px; font-weight: 800; color: #1e293b; }
        .stats-value.points { color: #16a34a; }
        
        .analysis-card { cursor: pointer; transition: 0.2s; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }
        .analysis-card:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(37, 99, 235, 0.1); border-color: #2563eb; }
        .analysis-title { font-size: 16px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 8px; }
        .analysis-desc { font-size: 12px; color: #94a3b8; }
        .analysis-icon { position: absolute; right: 15px; bottom: 10px; font-size: 60px; color: #2563eb; opacity: 0.1; transform: rotate(-15deg); }

        .filter-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .segmented-control { display: inline-flex; gap: 5px; }
        .segment-btn { padding: 8px 24px; border: none; background: #f1f5f9; border-radius: 50px; font-weight: 600; color: #64748b; font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .segment-btn:hover { background: #e2e8f0; color: #334155; }
        .segment-btn.active { background: #ffffff; color: #16a34a; box-shadow: 0 2px 5px rgba(0,0,0,0.08); }

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

        .hidden { display: none !important; }
    </style>
</head>
<body>

        <div class="content-wrapper">
            <?php if(isset($_GET['msg']) && $_GET['msg'] == 'approved'): ?>
            <div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><strong>Voucher Ready!</strong> Your voucher has been approved instantly.</div>
            <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'pending'): ?>
            <div class="alert alert-info border-0 shadow-sm"><i class="fas fa-clock me-2"></i><strong>Request Sent!</strong> Shipping soon.</div>
            <?php endif; ?>

            <!-- Dashboard Row -->
            <div class="dashboard-row">
                <!-- 1. Profile -->
                <div class="dash-card">
                    <div class="profile-card-content">
                        <div class="profile-img-container"><img src="<?php echo htmlspecialchars($avatarPath); ?>" class="profile-img"></div>
                        <div class="stats-group"><div class="stats-label">My Rank</div><div class="stats-value"><?php echo $userRank; ?></div></div>
                        <div class="stats-group"><div class="stats-label">My Points</div><div class="stats-value points"><?php echo number_format($userPoints); ?></div></div>
                    </div>
                </div>
                <!-- 2. History -->
                <div class="dash-card analysis-card" data-bs-toggle="modal" data-bs-target="#historyModal">
                    <div class="analysis-content">
                        <div class="analysis-title">History <iconify-icon icon="solar:history-bold-duotone" class="text-primary"></iconify-icon></div>
                        <div class="analysis-desc">Redemption logs.</div>
                    </div>
                    <iconify-icon icon="solar:clock-circle-bold-duotone" class="analysis-icon"></iconify-icon>
                </div>
                <!-- 3. My Vouchers -->
                <div class="dash-card analysis-card" data-bs-toggle="modal" data-bs-target="#myVouchersModal">
                    <div class="analysis-content">
                        <div class="analysis-title">My Vouchers <iconify-icon icon="solar:ticket-bold-duotone" class="text-success"></iconify-icon></div>
                        <div class="analysis-desc">Active tickets.</div>
                    </div>
                    <iconify-icon icon="solar:ticket-star-bold-duotone" class="analysis-icon text-success" style="color: #16a34a !important;"></iconify-icon>
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
                    
                    // PASS THE PREFIX TO JS HERE (now uses 'prefix')
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

<!-- History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4"><div class="modal-header border-0 bg-light rounded-top-4"><h5 class="modal-title fw-bold">History</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="bg-light"><tr><th class="ps-4">Reward</th><th>Points</th><th>Status</th></tr></thead><tbody><?php if ($requests_result->num_rows > 0) { while ($req = $requests_result->fetch_assoc()) { echo "<tr><td class='ps-4 fw-bold'>".htmlspecialchars($req['rewardName'])."</td><td class='text-danger'>-".number_format($req['pointSpent'])."</td><td>".ucfirst($req['status'])."</td></tr>"; }} ?></tbody></table></div></div></div></div>
</div>

<!-- MY VOUCHERS LIST MODAL -->
<div class="modal fade" id="myVouchersModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 bg-light"><h5 class="modal-title fw-bold">My Vouchers</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body p-0">
          <div class="list-group list-group-flush my-vouchers-list">
              <?php if($myVouchers->num_rows > 0): ?>
                  <?php while($v = $myVouchers->fetch_assoc()): 
                      $vJson = htmlspecialchars(json_encode($v), ENT_QUOTES, 'UTF-8');
                  ?>
                  <div class="list-group-item d-flex align-items-center p-3" onclick="openVoucherFromList(<?php echo $vJson; ?>)">
                      <img src="<?php echo !empty($v['imageURL']) ? $v['imageURL'] : 'upload/reward_placeholder.png'; ?>" style="width:50px; height:50px; object-fit:cover; border-radius:8px; margin-right:15px;">
                      <div class="flex-grow-1">
                          <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($v['rewardName']); ?></h6>
                          <small class="text-muted">ID: #<?php echo str_pad($v['redemptionID'], 8, '0', STR_PAD_LEFT); ?></small>
                      </div>
                      <iconify-icon icon="solar:alt-arrow-right-linear" class="text-muted"></iconify-icon>
                  </div>
                  <?php endwhile; ?>
              <?php else: ?>
                  <div class="p-4 text-center text-muted">No active vouchers found.</div>
              <?php endif; ?>
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
              <p class="fw-bold text-success mb-1">Valid Voucher</p>
              <p class="voucher-desc" id="ticketDesc"><?php echo htmlspecialchars($newVoucherData['description'] ?? ''); ?></p>
              <hr class="border-dashed my-3">
              
              <!-- Barcode Container -->
              <div id="ticketBarcodeContainer" class="text-center">
                  <svg id="barcode"></svg>
              </div>
              
              <span class="d-block mt-2 text-muted small letter-spacing-2" id="ticketID">
                  <!-- JS will fill this -->
              </span>
          </div>
      </div>
      <button type="button" class="btn btn-light w-100 mt-3 fw-bold" data-bs-dismiss="modal">Close</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // UPDATED JS: Uses "serial" for ID generation, NOT redemptionID
    function generateBarcode(serial, prefix, productName) {
        
        let codePrefix = "";

        if (prefix && prefix !== "null" && prefix !== "") {
            codePrefix = prefix;
        } 
        else if (productName) {
            codePrefix = productName.replace(/[^a-zA-Z0-9]/g, '').substring(0, 4).toUpperCase() + '-';
        } 
        else {
            codePrefix = "ECO-";
        }

        if (codePrefix.slice(-1) !== '-') {
             codePrefix += '-';
        }

        // Use the SERIAL number (1, 2, 3) instead of the random RedemptionID (105, 122)
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

    // Show Voucher Modal if param exists
    <?php if ($newVoucherData): ?> 
        generateBarcode(
            '<?php echo $newVoucherData['voucher_serial']; ?>', // Use Serial
            '<?php echo !empty($newVoucherData['prefix']) ? $newVoucherData['prefix'] : ''; ?>', // Use 'prefix'
            '<?php echo addslashes($newVoucherData['rewardName']); ?>'
        );
        new bootstrap.Modal(document.getElementById('voucherModal')).show(); 
    <?php endif; ?>

    const rewardModal = new bootstrap.Modal(document.getElementById('rewardModal'));
    const voucherModal = new bootstrap.Modal(document.getElementById('voucherModal'));
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
        
        // Pass SERIAL instead of RedemptionID, using 'prefix'
        generateBarcode(v.voucher_serial, v.prefix, v.rewardName);
        
        const myVouchersModal = bootstrap.Modal.getInstance(document.getElementById('myVouchersModal'));
        if(myVouchersModal) myVouchersModal.hide();
        voucherModal.show();
    }

    // ... existing filters ...
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

<?php include "includes/layout_end.php"; ?>
</body>
</html>