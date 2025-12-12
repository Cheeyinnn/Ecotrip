<?php    
session_start();
include 'db_connect.php';

// 1. Authentication
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}
$userID = $_SESSION['userID'];

// Fetch Current User Info
$stmt = $conn->prepare("SELECT firstName, lastName, email, role, avatarURL FROM user WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();

$avatarPath = 'upload/default.png';
if (!empty($currentUser['avatarURL']) && file_exists(__DIR__ . '/' . $currentUser['avatarURL'])) {
    $avatarPath = $currentUser['avatarURL'];
}

// 2. HANDLE STATUS UPDATE (POST)
$msg = "";
$msgType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['updateStatusID'])) {
    $rID = $_POST['updateStatusID'];
    $newStatus = $_POST['newStatus'];
    
    // Get current details to handle refunds if denied
    $stmt = $conn->prepare("SELECT userID, pointSpent, quantity, rewardID, status FROM redemptionrequest WHERE redemptionID = ?");
    $stmt->bind_param("i", $rID);
    $stmt->execute();
    $stmt->bind_result($r_userID, $r_points, $r_qty, $r_rewardID, $currentStatus);
    $stmt->fetch();
    $stmt->close();

    $alreadyRefunded = ($currentStatus === 'denied' || $currentStatus === 'cancelled');
    $willRefund = ($newStatus === 'denied' || $newStatus === 'cancelled');

    if ($willRefund && !$alreadyRefunded) {
        $stmt = $conn->prepare("INSERT INTO pointtransaction (userID, transactionType, pointsTransaction) VALUES (?, 'return', ?)");
        $stmt->bind_param("ii", $r_userID, $r_points);
        $stmt->execute(); $stmt->close();

        $stmt = $conn->prepare("UPDATE reward SET stockQuantity = stockQuantity + ? WHERE rewardID = ?");
        $stmt->bind_param("ii", $r_qty, $r_rewardID);
        $stmt->execute(); $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE redemptionrequest SET status = ? WHERE redemptionID = ?");
    $stmt->bind_param("si", $newStatus, $rID);
    
    if ($stmt->execute()) {
        $displayMsgStatus = $newStatus;
        if($newStatus == 'outOfDiliver') $displayMsgStatus = 'Out for Delivery';
        
        $msg = "Request #$rID updated to " . ucfirst($displayMsgStatus);
        $msgType = "success";
    } else {
        $msg = "Error updating status.";
        $msgType = "danger";
    }
    $stmt->close();
}

// 3. GET ANALYSIS COUNTS
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'delivery' => 0, 'delivered' => 0, 'denied' => 0, 'cancelled' => 0];

$statSql = "SELECT status, COUNT(*) as count FROM redemptionrequest GROUP BY status";
$statResult = $conn->query($statSql);
while ($row = $statResult->fetch_assoc()) {
    $stats['total'] += $row['count'];
    $s = $row['status'];
    if ($s == 'pending') $stats['pending'] += $row['count'];
    elseif ($s == 'approved') $stats['approved'] += $row['count'];
    elseif ($s == 'outOfDiliver') $stats['delivery'] += $row['count'];
    elseif ($s == 'Delivered') $stats['delivered'] += $row['count'];
    elseif ($s == 'denied') $stats['denied'] += $row['count'];
    elseif ($s == 'cancelled') $stats['cancelled'] += $row['count'];
}

// 4. FILTER & SEARCH LOGIC
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterCategory = isset($_GET['category']) ? $_GET['category'] : ''; 
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT rr.*, u.firstName, u.lastName, u.email, u.userID as uID, r.rewardName, r.category, r.imageURL 
        FROM redemptionrequest rr 
        JOIN user u ON rr.userID = u.userID 
        LEFT JOIN reward r ON rr.rewardID = r.rewardID";

$whereClauses = [];
$params = [];
$types = "";

if (!empty($filterStatus)) {
    if ($filterStatus === 'delivery') {
        $whereClauses[] = "rr.status = 'outOfDiliver'";
    } else {
        $whereClauses[] = "rr.status = ?";
        $params[] = $filterStatus;
        $types .= "s";
    }
}

if (!empty($filterCategory)) {
    $whereClauses[] = "r.category = ?";
    $params[] = $filterCategory;
    $types .= "s";
}

if (!empty($searchQuery)) {
    $term = "%" . $searchQuery . "%";
    $whereClauses[] = "(rr.redemptionID LIKE ? OR u.firstName LIKE ? OR u.lastName LIKE ? OR u.email LIKE ? OR r.rewardName LIKE ? OR u.userID LIKE ?)";
    for ($i = 0; $i < 6; $i++) { $params[] = $term; $types .= "s"; }
}

if (count($whereClauses) > 0) $sql .= " WHERE " . implode(" AND ", $whereClauses);
$sql .= " ORDER BY rr.redemptionID DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$requests = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Review Requests - EcoTrip</title>
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
        .content-wrapper { padding: 15px 24px; } 
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 15px; border-radius: 12px; border: 1px solid #e5e9f2; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 4px rgba(0,0,0,0.02); text-decoration: none; transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s; cursor: pointer; }
        .stat-card:hover, .stat-card.active { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(37, 99, 235, 0.1); border-color: #2563eb; }
        .stat-card.active { background-color: #eff6ff; border-color: #2563eb; }
        .stat-title { font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; margin-bottom: 4px; white-space: nowrap; }
        .stat-value { font-size: 24px; font-weight: 800; color: #1e293b; line-height: 1; }
        .stat-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .bg-blue-light { background: #eff6ff; color: #2563eb; }
        .bg-yellow-light { background: #fffbeb; color: #d97706; }
        .bg-green-light { background: #f0fdf4; color: #16a34a; }
        .bg-purple-light { background: #faf5ff; color: #7e22ce; }
        .bg-teal-light { background: #f0fdfa; color: #0d9488; }
        .bg-red-light { background: #fef2f2; color: #dc2626; }
        .bg-gray-light { background: #f1f5f9; color: #64748b; }
        .toolbar { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .search-box { flex-grow: 1; position: relative; min-width: 200px; }
        .search-input { width: 100%; padding: 8px 15px 8px 35px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
        .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .filter-select { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-size: 14px; color: #475569; cursor: pointer; min-width: 130px; }
        .card-box { background: #ffffff; border-radius: 18px; padding: 20px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06); }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th { text-align: left; padding: 10px 8px; border-bottom: 2px solid #e5e9f2; color: #64748b; font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; }
        td { padding: 8px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; font-size: 14px; }
        .table img { width: 36px; height: 36px; border-radius: 8px; object-fit: cover; }
        .status-select { border: 1px solid #e2e8f0; border-radius: 8px; padding: 4px 8px; font-size: 12px; font-weight: 600; cursor: pointer; width: 130px; }
        .status-select.pending { background: #fffbeb; color: #d97706; border-color: #fcd34d; }
        .status-select.approved { background: #f0fdf4; color: #16a34a; border-color: #86efac; }
        .status-select.denied { background: #fef2f2; color: #dc2626; border-color: #fca5a5; }
        .status-select.outOfDiliver { background: #eff6ff; color: #2563eb; border-color: #93c5fd; }
        .status-select.Delivered { background: #f0fdfa; color: #0d9488; border-color: #99f6e4; }
        .status-select.cancelled { background: #f1f5f9; color: #64748b; border-color: #cbd5e1; }
        .shipping-info { font-size: 11px; color: #64748b; line-height: 1.3; }
        .category-badge { padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .cat-product { background: #e0f2fe; color: #0284c7; }
        .cat-voucher { background: #f3e8ff; color: #7e22ce; }
        h4.card-title { margin-bottom: 15px !important; font-size: 1.1rem; }
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
            <li class="sidebar-item"><a href="rewardAdmin.php" class="sidebar-link"><iconify-icon icon="solar:settings-minimalistic-line-duotone"></iconify-icon><span>RewardAdmin</span></a></li>
            <li class="sidebar-item"><a href="leaderboard.php" class="sidebar-link"><iconify-icon icon="solar:cup-star-line-duotone"></iconify-icon><span>Leaderboard</span></a></li>
            <li class="sidebar-item"><a href="reviewRR.php" class="sidebar-link active"><iconify-icon icon="solar:clipboard-check-line-duotone"></iconify-icon><span>ReviewRR</span></a></li>
            <li class="sidebar-item"><a href="manage_user.php" class="sidebar-link"><iconify-icon icon="solar:users-group-rounded-line-duotone"></iconify-icon><span>Manage Users</span></a></li>
            <li class="sidebar-item"><a href="manage_team.php" class="sidebar-link"><iconify-icon icon="solar:users-group-two-rounded-line-duotone"></iconify-icon><span>Manage Teams</span></a></li>
        </ul>
        <div class="sidebar-footer mt-auto">Logged in as:<br><strong><?php echo htmlspecialchars($currentUser['firstName']); ?></strong></div>
    </aside>

    <div class="main-content">
        <div class="topbar">
            <div class="topbar-title">Review Redemption Requests</div>
            <div class="dropdown d-inline-block">
                <a href="#" class="d-flex align-items-center text-decoration-none"><img src="<?php echo htmlspecialchars($avatarPath); ?>" class="nav-avatar me-1"></a>
            </div>
        </div>
        <div class="content-wrapper">
            <div class="stats-row">
                <a href="reviewRR.php" class="stat-card <?php echo ($filterStatus == '') ? 'active' : ''; ?>"><div><div class="stat-title">Total Requests</div><div class="stat-value"><?php echo $stats['total']; ?></div></div><div class="stat-icon bg-blue-light"><iconify-icon icon="solar:clipboard-list-bold-duotone"></iconify-icon></div></a>
                <a href="reviewRR.php?status=pending" class="stat-card <?php echo ($filterStatus == 'pending') ? 'active' : ''; ?>"><div><div class="stat-title">Pending</div><div class="stat-value"><?php echo $stats['pending']; ?></div></div><div class="stat-icon bg-yellow-light"><iconify-icon icon="solar:clock-circle-bold-duotone"></iconify-icon></div></a>
                <a href="reviewRR.php?status=approved" class="stat-card <?php echo ($filterStatus == 'approved') ? 'active' : ''; ?>"><div><div class="stat-title">Approved</div><div class="stat-value"><?php echo $stats['approved']; ?></div></div><div class="stat-icon bg-green-light"><iconify-icon icon="solar:check-circle-bold-duotone"></iconify-icon></div></a>
                <a href="reviewRR.php?status=delivery" class="stat-card <?php echo ($filterStatus == 'delivery') ? 'active' : ''; ?>"><div><div class="stat-title">In Delivery</div><div class="stat-value"><?php echo $stats['delivery']; ?></div></div><div class="stat-icon bg-purple-light"><iconify-icon icon="solar:box-bold-duotone"></iconify-icon></div></a>
                <a href="reviewRR.php?status=Delivered" class="stat-card <?php echo ($filterStatus == 'Delivered') ? 'active' : ''; ?>"><div><div class="stat-title">Delivered</div><div class="stat-value"><?php echo $stats['delivered']; ?></div></div><div class="stat-icon bg-teal-light"><iconify-icon icon="solar:box-minimalistic-bold-duotone"></iconify-icon></div></a>
                <a href="reviewRR.php?status=denied" class="stat-card <?php echo ($filterStatus == 'denied') ? 'active' : ''; ?>"><div><div class="stat-title">Denied</div><div class="stat-value"><?php echo $stats['denied']; ?></div></div><div class="stat-icon bg-red-light"><iconify-icon icon="solar:close-circle-bold-duotone"></iconify-icon></div></a>
                <a href="reviewRR.php?status=cancelled" class="stat-card <?php echo ($filterStatus == 'cancelled') ? 'active' : ''; ?>"><div><div class="stat-title">Cancelled</div><div class="stat-value"><?php echo $stats['cancelled']; ?></div></div><div class="stat-icon bg-gray-light"><iconify-icon icon="solar:trash-bin-trash-bold-duotone"></iconify-icon></div></a>
            </div>

            <?php if ($msg): ?><div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show py-2" style="font-size:14px;"><?php echo $msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 10px;"></button></div><?php endif; ?>

            <div class="card-box">
                <h4 class="card-title mb-3">Redemption Requests</h4>
                <form method="GET" class="toolbar">
                    <div class="search-box"><iconify-icon icon="solar:magnifer-linear" class="search-icon"></iconify-icon><input type="text" name="search" class="search-input" placeholder="Search by ID, User, or Reward..." value="<?php echo htmlspecialchars($searchQuery); ?>"></div>
                    <select name="category" class="filter-select" onchange="this.form.submit()"><option value="">All Types</option><option value="product" <?php echo $filterCategory == 'product' ? 'selected' : ''; ?>>Product</option><option value="voucher" <?php echo $filterCategory == 'voucher' ? 'selected' : ''; ?>>Voucher</option></select>
                    <select name="status" class="filter-select" onchange="this.form.submit()"><option value="">All Statuses</option><option value="pending" <?php echo $filterStatus == 'pending' ? 'selected' : ''; ?>>Pending</option><option value="approved" <?php echo $filterStatus == 'approved' ? 'selected' : ''; ?>>Approved</option><option value="outOfDiliver" <?php echo $filterStatus == 'outOfDiliver' ? 'selected' : ''; ?>>Out for Delivery</option><option value="Delivered" <?php echo $filterStatus == 'Delivered' ? 'selected' : ''; ?>>Delivered</option><option value="denied" <?php echo $filterStatus == 'denied' ? 'selected' : ''; ?>>Denied</option><option value="cancelled" <?php echo $filterStatus == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option></select>
                    <?php if(!empty($searchQuery) || !empty($filterStatus) || !empty($filterCategory)): ?><a href="reviewRR.php" class="btn btn-sm btn-light border d-flex align-items-center gap-1 text-muted" style="font-size: 13px;">Clear</a><?php endif; ?>
                </form>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light"><tr><th>#ID</th><th>User</th><th>Reward</th><th>Pts</th><th>Type</th><th>Shipping Info</th><th>Status / Action</th></tr></thead>
                        <tbody>
                            <?php if ($requests->num_rows > 0): ?>
                                <?php while ($row = $requests->fetch_assoc()): ?>
                                    <?php 
                                        $category = strtolower($row['category']); 
                                        $status = $row['status'];
                                        $img = !empty($row['imageURL']) ? $row['imageURL'] : 'upload/reward_placeholder.png';
                                        $shipName = htmlspecialchars($row['receiver_name'] ?? '-');
                                        $shipPhone = htmlspecialchars($row['phone_number'] ?? '-');
                                        $shipAddr = htmlspecialchars($row['address'] ?? '-');
                                    ?>
                                    <tr>
                                        <td>#<?php echo $row['redemptionID']; ?></td>
                                        <td><div class="d-flex flex-column"><span class="fw-bold"><?php echo htmlspecialchars($row['firstName'] . ' ' . $row['lastName']); ?></span><small class="text-muted" style="font-size:10px;">ID: <?php echo $row['uID']; ?></small></div></td>
                                        <td><div class="d-flex align-items-center gap-2"><img src="<?php echo htmlspecialchars($img); ?>" alt=""><div class="d-flex flex-column"><span class="fw-bold text-dark" style="font-size:13px;"><?php echo htmlspecialchars($row['rewardName']); ?></span><span class="text-muted" style="font-size:11px;">Qty: <?php echo $row['quantity']; ?></span></div></div></td>
                                        <td class="fw-bold text-danger">-<?php echo number_format($row['pointSpent']); ?></td>
                                        <td><?php if ($category == 'product'): ?><span class="category-badge cat-product">Product</span><?php else: ?><span class="category-badge cat-voucher">Voucher</span><?php endif; ?></td>
                                        <td><?php if ($category == 'product' && !empty($row['receiver_name'])): ?><div class="d-flex align-items-center gap-2"><button class="btn btn-sm btn-light border" style="font-size: 11px;" onclick="viewShipping(<?php echo htmlspecialchars(json_encode(['name' => $row['receiver_name'], 'phone' => $row['phone_number'], 'address' => $row['address']]), ENT_QUOTES, 'UTF-8'); ?>)"><iconify-icon icon="solar:eye-bold" style="vertical-align: text-bottom;"></iconify-icon> View Details</button></div><?php else: ?><span class="text-muted small">-</span><?php endif; ?></td>
                                        <td>
                                            <form method="POST" onchange="this.submit()">
                                                <input type="hidden" name="updateStatusID" value="<?php echo $row['redemptionID']; ?>">
                                                <select name="newStatus" class="status-select <?php echo $status; ?>">
                                                    <?php if ($status == 'cancelled'): ?><option value="cancelled" selected disabled>User Cancelled</option><?php else: ?>
                                                        <?php if($status == 'pending') echo '<option value="pending" selected>Pending</option>'; ?>
                                                        <option value="approved" <?php echo ($status == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                                        <?php if ($category === 'product'): ?>
                                                            <option value="outOfDiliver" <?php echo ($status == 'outOfDiliver') ? 'selected' : ''; ?>>Out for Delivery</option>
                                                            <option value="Delivered" <?php echo ($status == 'Delivered') ? 'selected' : ''; ?>>Delivered</option>
                                                        <?php endif; ?>
                                                        <option value="denied" <?php echo ($status == 'denied') ? 'selected' : ''; ?>>Denied</option>
                                                    <?php endif; ?>
                                                </select>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted">No redemption requests found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="shippingModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-header border-0 bg-light"><h5 class="modal-title fw-bold" style="font-size: 1rem;">Shipping Information</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-4"><div class="mb-3"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Receiver Name</small><div id="modalShipName" class="fw-bold text-dark"></div></div><div class="mb-3"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Phone Number</small><div id="modalShipPhone" class="text-dark"></div></div><div class="mb-0"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Address</small><div id="modalShipAddr" class="p-3 bg-light rounded border text-break"></div></div></div><div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-sm btn-dark w-100" data-bs-dismiss="modal">Close</button></div></div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const shippingModal = new bootstrap.Modal(document.getElementById('shippingModal'));
    function viewShipping(data) {
        document.getElementById('modalShipName').innerText = data.name || '-';
        document.getElementById('modalShipPhone').innerText = data.phone || '-';
        document.getElementById('modalShipAddr').innerText = data.address || '-';
        shippingModal.show();
    }
</script>
</body>
</html>