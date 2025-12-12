<?php
// -------------------------------------
// LOAD DB + AUTH FIRST
// -------------------------------------
require "db_connect.php";
require "includes/auth.php";      // session + auth
require "includes/notify.php";    // ⭐ notification system

// -------------------------------------
// FETCH LOGGED-IN USER DATA
// -------------------------------------
$id = $_SESSION['userID'];
$msg = "";
$msgType = "info";

$stmt = $conn->prepare("SELECT * FROM user WHERE userID=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Prevent null fields
$user['phone']   = $user['phone']   ?? '';
$user['address'] = $user['address'] ?? '';

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

<!-- ==========================
      PAGE CONTENT
========================== -->

<div class="card p-4 shadow-lg profile-card">

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show shadow-sm">
            <?= htmlspecialchars($msg); ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-start mb-4 border-bottom pb-3">
        <div>
            <h3 class="mb-1">
                <iconify-icon icon="material-symbols:account-circle" class="text-primary me-2"></iconify-icon>
                Profile Settings
            </h3>
            <p class="text-muted small">Update your account details and security settings.</p>
        </div>

        <div class="text-center">
            <img src="<?= htmlspecialchars($avatarPathPage); ?>" class="avatar-preview">

            <form method="POST" enctype="multipart/form-data" class="mt-2">
                <p class="small text-muted mb-1">JPG/PNG • Max 1MB</p>
                <div class="input-group">
                    <input type="file" name="avatar" class="form-control form-control-sm" required>
                    <button name="upload_avatar" class="btn btn-outline-primary btn-sm">Upload</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mt-3 g-5">

        <!-- Personal Info -->
        <div class="col-md-6">
            <h5 class="mb-3 text-primary">Personal Information</h5>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small text-muted">Email (read-only)</label>
                    <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']); ?>" readonly>
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

                <div class="mb-3">
                    <label class="form-label small text-muted">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($user['address']); ?></textarea>
                </div>

                <button name="save_info" class="btn btn-primary w-100 mt-2">Save Profile</button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="col-md-6 border-start ps-5">
            <h5 class="mb-3 text-warning">Change Password</h5>

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
