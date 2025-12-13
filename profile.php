<?php
// -------------------------------------
// LOAD DB + AUTH FIRST
// -------------------------------------
require "db_connect.php";
require "includes/auth.php";       // session + auth
require "includes/notify.php";     // ⭐ notification system

// -------------------------------------
// FETCH LOGGED-IN USER DATA & SCORE SYNC
// -------------------------------------
$id = $_SESSION['userID'];
$msg = "";
$msgType = "info";

// 1. FETCH CURRENT USER DATA
// Query includes the direct fetch of scorePoint (will be overwritten) AND walletPoint.
$stmt = $conn->prepare("
    SELECT *, COALESCE(scorePoint, 0) AS scorePoint, COALESCE(walletPoint, 0) AS walletPoint
    FROM user 
    WHERE userID=?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 2. SYNC TOTAL SCORE (Point Score) FROM TRANSACTIONS
// Calculate the TRUE lifetime total earned points from the transaction table.
$sync_sql = "
    SELECT 
        COALESCE(SUM(pt.pointsTransaction), 0) AS calculatedTotal
    FROM pointtransaction pt 
    WHERE pt.userID = ? AND pt.transactionType = 'earn'
";
$sync_stmt = $conn->prepare($sync_sql);
$sync_stmt->bind_param("i", $id);
$sync_stmt->execute();
$sync_result = $sync_stmt->get_result()->fetch_assoc();
$sync_stmt->close();

// OVERRIDE the scorePoint with the calculated total
$user['scorepoint'] = $sync_result['calculatedTotal']; 
$user['scorepoint'] = (int)($user['scorepoint'] ?? 0); 

// Ensure walletPoint is cast to integer for clean display
$user['walletPoint'] = (int)($user['walletPoint'] ?? 0); 

// Prevent null fields for other data
$user['phone']      = $user['phone']      ?? '';
$user['address']    = $user['address']    ?? '';

// -------------------------------------
// UPDATE PROFILE INFO
// -------------------------------------
if (isset($_POST['save_info'])) {

    $first   = trim($_POST['firstName']);
    $last    = trim($_POST['lastName']);
    $phone   = trim($_POST['phone']);
    $address = trim($_POST['address']);

    $update = $conn->prepare("
        UPDATE user
        SET firstName=?, lastName=?, phone=?, address=?
        WHERE userID=?
    ");
    $update->bind_param("ssssi", $first, $last, $phone, $address, $id);

    if ($update->execute()) {

        // ⭐ Send Notification
        sendNotification(
            $conn,
            $id,
            "Your profile information has been updated.",
            "profile.php"
        );

        $msg = "Profile updated successfully!";
        $msgType = "success";

        // Update session
        $_SESSION['firstName'] = $first;
        $_SESSION['lastName']  = $last;

        // Update displayed data
        $user['firstName'] = $first;
        $user['lastName']  = $last;
        $user['phone']     = $phone;
        $user['address']   = $address;

    } else {
        $msg = "Failed to update profile.";
        $msgType = "danger";
    }
}

// -------------------------------------
// CHANGE PASSWORD
// -------------------------------------
if (isset($_POST['change_password'])) {

    $old     = $_POST['old_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if (!password_verify($old, $user['password'])) {

        $msg = "Old password incorrect!";
        $msgType = "danger";

    } elseif ($new !== $confirm) {

        $msg = "New passwords do not match!";
        $msgType = "danger";

    } else {

        $hashed = password_hash($new, PASSWORD_DEFAULT);

        $stmtPwd = $conn->prepare("UPDATE user SET password=? WHERE userID=?");
        $stmtPwd->bind_param("si", $hashed, $id);

        if ($stmtPwd->execute()) {

            // ⭐ Send Notification
            sendNotification(
                $conn,
                $id,
                "Your password has been changed successfully.",
                "profile.php"
            );

            $msg = "Password updated successfully!";
            $msgType = "success";

        } else {
            $msg = "Failed to update password.";
            $msgType = "danger";
        }
    }
}

// -------------------------------------
// AVATAR UPLOAD
// -------------------------------------
if (isset($_POST['upload_avatar']) && isset($_FILES['avatar'])) {

    $file = $_FILES['avatar'];

    if ($file['error'] !== 0) {
        $msg = "Upload error.";
        $msgType = "danger";

    } else {

        $maxBytes = 1 * 1024 * 1024; // 1MB limit

        if ($file['size'] > $maxBytes) {

            $msg = "File too large (max 1MB).";
            $msgType = "warning";

        } else {

            // Validate MIME
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $ext = ($mime === "image/jpeg") ? "jpg" :
                   (($mime === "image/png") ? "png" : "");

            if ($ext === "") {

                $msg = "Only JPG or PNG allowed.";
                $msgType = "warning";

            } else {

                $serverDir = __DIR__ . "/uploads";
                if (!is_dir($serverDir)) {
                    mkdir($serverDir, 0775, true);
                }

                $fileName = "avatar_" . $id . "_" . time() . "." . $ext;
                $target   = $serverDir . "/" . $fileName;

                if (move_uploaded_file($file['tmp_name'], $target)) {

                    $avatarPath = "uploads/" . $fileName;

                    $stmtAv = $conn->prepare("UPDATE user SET avatarURL=? WHERE userID=?");
                    $stmtAv->bind_param("si", $avatarPath, $id);

                    if ($stmtAv->execute()) {

                        // ⭐ Send Notification
                        sendNotification(
                            $conn,
                            $id,
                            "Your profile picture has been updated.",
                            "profile.php"
                        );

                        $msg = "Avatar updated successfully!";
                        $msgType = "success";

                        $user['avatarURL']     = $avatarPath;
                        $_SESSION['avatarURL'] = $avatarPath;

                    } else {
                        $msg = "Error saving avatar to database.";
                        $msgType = "danger";
                    }

                } else {
                    $msg = "Failed to save uploaded avatar.";
                    $msgType = "danger";
                }
            }
        }
    }
}

// -------------------------------------
// Avatar display path
// -------------------------------------
$avatarPathPage = (!empty($user['avatarURL']) && file_exists(__DIR__ . '/' . $user['avatarURL']))
    ? $user['avatarURL']
    : 'uploads/default.png';

// -------------------------------------
// PAGE TITLE (TOPBAR)
// -------------------------------------
$pageTitle = "My Profile";

// -------------------------------------
// LOAD LAYOUT
// -------------------------------------
include "includes/layout_start.php";
?>

<style>
    /* Custom styles for cleaner layout */
    .profile-card {
        margin: 20px auto; 
    }
    .avatar-preview {
        width: 100px;
        height: 100px;
        object-fit: cover;
        border-radius: 50%;
        border: 4px solid #f8f9fa;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    /* ⭐ New container for horizontal point boxes */
    .score-summary-container {
        display: flex;
        gap: 15px; 
    }
    .points-summary {
        padding: 10px 15px;
        border-radius: 5px;
        border: 1px solid #c3e6cb;
        transition: background-color 0.2s, box-shadow 0.2s;
        text-align: center;
        flex: 1;
    }
    
    /* Style for Point Score (Total Earned) */
    .points-score-box {
        background-color: #e6ffec; /* Green background */
        border-color: #c3e6cb;
    }
    
    /* Style for Available Point (Wallet Balance) */
    .wallet-point-box {
        background-color: #fff3e0; /* Yellow/Orange background */
        border-color: #ffe0b2;
    }
    
    /* Hover effect to indicate clickability */
    .points-summary:hover {
        background-color: #d8ffdf; 
        box-shadow: 0 0 5px rgba(0, 150, 0, 0.2);
        cursor: pointer;
    }
    .security-section {
        border-left: 1px solid #e9ecef; /* Subtle vertical divider */
        padding-left: 1.5rem; 
    }
    /* Adjusted gutter spacing for aesthetics */
    .row.g-5 > div:nth-child(2) {
        padding-left: calc(var(--bs-gutter-x) * 1.5);
    }
</style>

<div class="card p-4 shadow-lg profile-card">

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show shadow-sm">
            <?= htmlspecialchars($msg); ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start mb-2 border-bottom pb-2">
        
        <div>
            <h3 class="mt-0">
                <iconify-icon icon="material-symbols:account-circle" class="text-primary me-2"></iconify-icon>
                Profile Settings
            </h3>
            <p class="text-muted small mb-0">Update your account details and security settings.</p>
        </div>

        <div class="score-summary-container mt-3 mt-md-0 mx-md-4">
            
            <a href="rewards.php" class="text-decoration-none text-dark">
                <div class="points-summary points-score-box">
                    <span class="text-muted small d-block">Point Score</span>
                    <h4 class="fw-bold text-success mb-0">
                        <?= (int)$user['scorepoint']; ?> pts
                    </h4>
                </div>
            </a>
            
            <a href="rewards.php" class="text-decoration-none text-dark">
                <div class="points-summary wallet-point-box">
                    <span class="text-muted small d-block">Available Point</span>
                    <h4 class="fw-bold text-warning mb-0">
                        <?= (int)$user['walletPoint']; ?> pts
                    </h4>
                </div>
            </a>

        </div>

        <div class="text-center mt-4 mt-md-0">
            <img src="<?= htmlspecialchars($avatarPathPage); ?>" class="avatar-preview">

            <form method="POST" enctype="multipart/form-data" class="mt-2">
                <p class="small text-muted mb-1">JPG/PNG • Max 1MB</p>
                <div class="input-group input-group-sm" style="max-width: 250px;">
                    <input type="file" name="avatar" class="form-control" required>
                    <button name="upload_avatar" class="btn btn-outline-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="row mt-2 g-5">

        <div class="col-md-6">
            <h5 class="mb-3 text-primary border-bottom pb-2">Personal Information</h5>

            <form method="POST">

                <div class="mb-3">
                    <label class="form-label small text-muted">Email (read-only)</label>
                    <input type="email" class="form-control"
                           value="<?= htmlspecialchars($user['email']); ?>" readonly disabled>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small text-muted">First Name</label>
                        <input type="text" name="firstName" class="form-control"
                               value="<?= htmlspecialchars($user['firstName']); ?>">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label small text-muted">Last Name</label>
                        <input type="text" name="lastName" class="form-control"
                               value="<?= htmlspecialchars($user['lastName']); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-muted">Phone Number</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= htmlspecialchars($user['phone']); ?>">
                </div>

                <div class="mb-4">
                    <label class="form-label small text-muted">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($user['address']); ?></textarea>
                </div>

                <button name="save_info" class="btn btn-primary w-100 mt-2">Save Profile</button>
            </form>
        </div>

        <div class="col-md-6 security-section">
            <h5 class="mb-4 text-warning border-bottom pb-2">Change Password</h5>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small text-muted">Old Password</label>
                    <input type="password" name="old_password" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-muted">New Password</label>
                    <input type="password" name="new_password" class="form-control" required>
                </div>

                <div class="mb-4">
                    <label class="form-label small text-muted">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>

                <button name="change_password" class="btn btn-warning w-100">Update Password</button>
            </form>
        </div>
    </div>
</div>

<?php include "includes/layout_end.php"; ?>