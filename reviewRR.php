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

// 1. Authentication
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}
$userID = $_SESSION['userID'];
$pageTitle = "Review Redemption Requests";

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
    $trackingNumber = isset($_POST['trackingNumber']) ? trim($_POST['trackingNumber']) : null;
    
    // Get current details AND reward name to handle refunds and notifications
    // UPDATED QUERY: Added JOIN to reward table to get rewardName
    $stmt = $conn->prepare("SELECT rr.userID, rr.pointSpent, rr.quantity, rr.rewardID, rr.status, r.rewardName 
                            FROM redemptionrequest rr 
                            LEFT JOIN reward r ON rr.rewardID = r.rewardID 
                            WHERE rr.redemptionID = ?");
    $stmt->bind_param("i", $rID);
    $stmt->execute();
    $stmt->bind_result($r_userID, $r_points, $r_qty, $r_rewardID, $currentStatus, $r_rewardName);
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

    // Handle Tracking Number Update for 'outOfDiliver'
    if ($newStatus === 'outOfDiliver' && !empty($trackingNumber)) {
        $stmt = $conn->prepare("UPDATE redemptionrequest SET status = ?, tracking_number = ? WHERE redemptionID = ?");
        $stmt->bind_param("ssi", $newStatus, $trackingNumber, $rID);
    } else {
        $stmt = $conn->prepare("UPDATE redemptionrequest SET status = ? WHERE redemptionID = ?");
        $stmt->bind_param("si", $newStatus, $rID);
    }
    
    if ($stmt->execute()) {
        $displayMsgStatus = $newStatus;
        if($newStatus == 'outOfDiliver') $displayMsgStatus = 'Out for Delivery';
        
        $msg = "Request #$rID updated to " . ucfirst($displayMsgStatus);
        $msgType = "success";

        // --- SEND NOTIFICATION TO USER ---
        $notifMessage = "Your redemption request for '" . ($r_rewardName ?? 'Item') . "' is now " . ucfirst($displayMsgStatus) . ".";
        if ($newStatus === 'outOfDiliver' && !empty($trackingNumber)) {
             $notifMessage .= " Tracking #: " . $trackingNumber;
        }
        sendNotification($conn, $r_userID, $notifMessage, "userReward_board.php"); // Updated link

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

include 'includes/layout_start.php';
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

        body { margin: 0; background: #f5f7fb; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        .content-wrapper { padding: 15px 24px; } 
        /* Updated Grid Layout for 4 cards per row on larger screens */
        .stats-row { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); /* Adjusted min-width for 4 columns fit */
            gap: 20px; 
            margin-bottom: 25px; 
        }
        
        @media (min-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card { 
            background: white; 
            padding: 20px; 
            border-radius: 16px; 
            border: 1px solid #e2e8f0; 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); 
            text-decoration: none; 
            transition: all 0.2s ease; 
            cursor: pointer; 
            height: 100%;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border-color: #cbd5e1; }
        .stat-card.active { background-color: #f8fafc; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1); }
        
        .stat-content { display: flex; flex-direction: column; justify-content: center; }
        .stat-title { font-size: 12px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .stat-value { font-size: 28px; font-weight: 800; color: #0f172a; line-height: 1; }
        
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; }
        
        .bg-blue-light { background: #eff6ff; color: #3b82f6; }
        .bg-yellow-light { background: #fefce8; color: #eab308; }
        .bg-green-light { background: #f0fdf4; color: #22c55e; }
        .bg-purple-light { background: #faf5ff; color: #a855f7; }
        .bg-teal-light { background: #f0fdfa; color: #14b8a6; }
        .bg-red-light { background: #fef2f2; color: #ef4444; }
        .bg-gray-light { background: #f8fafc; color: #94a3b8; }
        .bg-indigo-light { background: #eef2ff; color: #6366f1; }

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
        <div class="content-wrapper">
            <div class="stats-row">
                <!-- Dashboard Redirect Card -->
                <a href="dashboard_admin.php" class="stat-card">
                    <div class="stat-content">
                        <div class="stat-title">Back to Dashboard</div>
                        <div class="stat-value" style="font-size: 18px; color: #6366f1;">Go Home</div>
                    </div>
                    <div class="stat-icon bg-indigo-light">
                        <iconify-icon icon="solar:home-angle-bold-duotone"></iconify-icon>
                    </div>
                </a>

                <!-- Total Requests -->
                <a href="reviewRR.php" class="stat-card <?php echo ($filterStatus == '') ? 'active' : ''; ?>">
                    <div class="stat-content">
                        <div class="stat-title">Total Requests</div>
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                    </div>
                    <div class="stat-icon bg-blue-light">
                        <iconify-icon icon="solar:clipboard-list-bold-duotone"></iconify-icon>
                    </div>
                </a>

                <!-- Pending -->
                <a href="reviewRR.php?status=pending" class="stat-card <?php echo ($filterStatus == 'pending') ? 'active' : ''; ?>">
                    <div class="stat-content">
                        <div class="stat-title">Pending</div>
                        <div class="stat-value"><?php echo $stats['pending']; ?></div>
                    </div>
                    <div class="stat-icon bg-yellow-light">
                        <iconify-icon icon="solar:clock-circle-bold-duotone"></iconify-icon>
                    </div>
                </a>

                <!-- Approved -->
                <a href="reviewRR.php?status=approved" class="stat-card <?php echo ($filterStatus == 'approved') ? 'active' : ''; ?>">
                    <div class="stat-content">
                        <div class="stat-title">Approved</div>
                        <div class="stat-value"><?php echo $stats['approved']; ?></div>
                    </div>
                    <div class="stat-icon bg-green-light">
                        <iconify-icon icon="solar:check-circle-bold-duotone"></iconify-icon>
                    </div>
                </a>

                <!-- In Delivery -->
                <a href="reviewRR.php?status=delivery" class="stat-card <?php echo ($filterStatus == 'delivery') ? 'active' : ''; ?>">
                    <div class="stat-content">
                        <div class="stat-title">In Delivery</div>
                        <div class="stat-value"><?php echo $stats['delivery']; ?></div>
                    </div>
                    <div class="stat-icon bg-purple-light">
                        <iconify-icon icon="solar:box-bold-duotone"></iconify-icon>
                    </div>
                </a>

                <!-- Delivered -->
                <a href="reviewRR.php?status=Delivered" class="stat-card <?php echo ($filterStatus == 'Delivered') ? 'active' : ''; ?>">
                    <div class="stat-content">
                        <div class="stat-title">Delivered</div>
                        <div class="stat-value"><?php echo $stats['delivered']; ?></div>
                    </div>
                    <div class="stat-icon bg-teal-light">
                        <iconify-icon icon="solar:box-minimalistic-bold-duotone"></iconify-icon>
                    </div>
                </a>

                <!-- Denied -->
                <a href="reviewRR.php?status=denied" class="stat-card <?php echo ($filterStatus == 'denied') ? 'active' : ''; ?>">
                    <div class="stat-content">
                        <div class="stat-title">Denied</div>
                        <div class="stat-value"><?php echo $stats['denied']; ?></div>
                    </div>
                    <div class="stat-icon bg-red-light">
                        <iconify-icon icon="solar:close-circle-bold-duotone"></iconify-icon>
                    </div>
                </a>

                <!-- Cancelled -->
                <a href="reviewRR.php?status=cancelled" class="stat-card <?php echo ($filterStatus == 'cancelled') ? 'active' : ''; ?>">
                    <div class="stat-content">
                        <div class="stat-title">Cancelled</div>
                        <div class="stat-value"><?php echo $stats['cancelled']; ?></div>
                    </div>
                    <div class="stat-icon bg-gray-light">
                        <iconify-icon icon="solar:trash-bin-trash-bold-duotone"></iconify-icon>
                    </div>
                </a>
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
                        <thead class="table-light"><tr><th>#ID</th><th>User</th><th>Reward</th><th>Pts</th><th>Type</th><th>Shipping Info</th><th>Tracking #</th><th>Status / Action</th></tr></thead>
                        <tbody>
                            <?php if ($requests->num_rows > 0): ?>
                                <?php while ($row = $requests->fetch_assoc()): ?>
                                    <?php 
                                        $category = strtolower($row['category']); 
                                        $status = $row['status'];
                                        $img = !empty($row['imageURL']) ? 'uploads/' . $row['imageURL'] : 'upload/default.png'; // Corrected Path
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
                                        
                                        <!-- Tracking Number Display -->
                                        <td>
                                            <?php if(!empty($row['tracking_number'])): ?>
                                                <small class="text-primary fw-bold"><i class="fas fa-truck me-1"></i><?= htmlspecialchars($row['tracking_number']) ?></small>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <form method="POST" id="form-<?php echo $row['redemptionID']; ?>">
                                                <input type="hidden" name="updateStatusID" value="<?php echo $row['redemptionID']; ?>">
                                                <input type="hidden" name="trackingNumber" id="tracking-input-<?php echo $row['redemptionID']; ?>">
                                                <select name="newStatus" class="status-select <?php echo $status; ?>" onchange="handleStatusChange(this, <?php echo $row['redemptionID']; ?>)">
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
                                <tr><td colspan="8" class="text-center py-5 text-muted">No redemption requests found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Shipping Info Modal -->
<div class="modal fade" id="shippingModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-header border-0 bg-light"><h5 class="modal-title fw-bold" style="font-size: 1rem;">Shipping Information</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-4"><div class="mb-3"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Receiver Name</small><div id="modalShipName" class="fw-bold text-dark"></div></div><div class="mb-3"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Phone Number</small><div id="modalShipPhone" class="text-dark"></div></div><div class="mb-0"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Address</small><div id="modalShipAddr" class="p-3 bg-light rounded border text-break"></div></div></div><div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-sm btn-dark w-100" data-bs-dismiss="modal">Close</button></div></div></div>
</div>

<!-- Tracking Number Modal (Hidden by default) -->
<div class="modal fade" id="trackingModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 bg-light">
                <h5 class="modal-title fw-bold">Enter Tracking Number</h5>
                <button type="button" class="btn-close" onclick="cancelTrackingUpdate()"></button>
            </div>
            <div class="modal-body p-4">
                <label class="form-label fw-bold text-primary">Delivery / Tracking Number</label>
                <input type="text" class="form-control" id="modalTrackingInput" placeholder="Enter tracking number e.g. J&T 123456" required>
                <div class="form-text small">This will be sent to the user.</div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" onclick="cancelTrackingUpdate()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmTrackingUpdate()">Update Status</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const shippingModal = new bootstrap.Modal(document.getElementById('shippingModal'));
    const trackingModal = new bootstrap.Modal(document.getElementById('trackingModal'));
    
    let currentFormId = null;
    let currentSelectElement = null;
    let previousValue = null;

    // Capture the previous value when focusing on the dropdown
    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('focus', function() {
            previousValue = this.value;
        });
    });

    function viewShipping(data) {
        document.getElementById('modalShipName').innerText = data.name || '-';
        document.getElementById('modalShipPhone').innerText = data.phone || '-';
        document.getElementById('modalShipAddr').innerText = data.address || '-';
        shippingModal.show();
    }

    function handleStatusChange(selectElement, rID) {
        if (selectElement.value === 'outOfDiliver') {
            // Show modal to get tracking number
            currentFormId = 'form-' + rID;
            currentSelectElement = selectElement;
            document.getElementById('modalTrackingInput').value = ''; // Clear previous input
            trackingModal.show();
        } else {
            // Submit immediately for other statuses
            document.getElementById('form-' + rID).submit();
        }
    }

    function confirmTrackingUpdate() {
        const trackingNum = document.getElementById('modalTrackingInput').value;
        if (!trackingNum.trim()) {
            alert("Please enter a tracking number.");
            return;
        }

        // Fill the hidden input in the specific row's form
        const hiddenInput = document.querySelector(`#${currentFormId} input[name="trackingNumber"]`);
        if (hiddenInput) {
            hiddenInput.value = trackingNum;
            document.getElementById(currentFormId).submit();
        }
        trackingModal.hide();
    }

    function cancelTrackingUpdate() {
        // Revert select dropdown to previous value if cancelled
        if (currentSelectElement && previousValue) {
            currentSelectElement.value = previousValue;
        }
        trackingModal.hide();
    }
</script>
</body>
</html>