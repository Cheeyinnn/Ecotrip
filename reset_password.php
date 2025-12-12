<?php
require 'db_connect.php';

$token = $_GET['token'] ?? null;

$message = "";
$showForm = false;

// ================================
// VALIDATE TOKEN
// ================================
$stmt = $conn->prepare("
    SELECT vt.tokenID, vt.expires_at, vt.used_at, u.userID
    FROM verificationtoken vt
    JOIN user u ON vt.userID = u.userID
    WHERE vt.otpCode = ?
");
$stmt->bind_param("s", $token);
$stmt->execute();

$result = $stmt->get_result();

// Token not found
if ($result->num_rows !== 1) {
    $error = "Invalid or expired reset link.";
} else {
    $data = $result->fetch_assoc();

    // Check expiry
    if (strtotime($data['expires_at']) < time()) {
        $error = "This reset link has expired.";
    }
    // Check if already used
    elseif (!is_null($data['used_at'])) {
        $error = "This reset link was already used.";
    }
    else {
        // Valid token → allow form
        $showForm = true;
        $userID = $data['userID'];
    }
}

// ================================
// UPDATE PASSWORD
// ================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showForm) {

    $newpass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

    // Update password
    $update = $conn->prepare("UPDATE user SET password=? WHERE userID=?");
    $update->bind_param("si", $newpass, $userID);
    $update->execute();

    // Mark token used
    $mark = $conn->prepare("UPDATE verificationtoken SET used_at=NOW() WHERE otpCode=?");
    $mark->bind_param("s", $token);
    $mark->execute();

    $message = "Your password has been updated successfully!";
    $showForm = false;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - EcoTrip</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8f9fa;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .reset-card {
            width: 100%;
            max-width: 420px;
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.1);
        }
        .btn-update {
            background-color: #1f7a4c;
            border-color: #1f7a4c;
            color: white;
        }
        .btn-update:hover {
            background-color: #145a32;
            border-color: #145a32;
        }
    </style>
</head>

<body>

<div class="reset-card">

    <div class="text-center mb-4">
        <iconify-icon icon="material-symbols:lock-reset" width="48" class="text-success mb-2"></iconify-icon>
        <h3 class="fw-bold mb-0">Password Recovery</h3>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger text-center fw-semibold">
            <?= htmlspecialchars($error) ?>
        </div>
        <a href="login.php" class="btn btn-secondary w-100 mt-2">Back to Login</a>

    <?php elseif ($message): ?>
        <div class="alert alert-success text-center fw-semibold">
            <?= htmlspecialchars($message) ?>
        </div>
        <a href="login.php" class="btn btn-update w-100">
            <iconify-icon icon="ic:round-login"></iconify-icon>
            Proceed to Login
        </a>

    <?php elseif ($showForm): ?>
        <p class="text-muted small text-center mb-4">Enter your new password below.</p>

        <form method="post">
            <div class="form-floating mb-4">
                <input type="password" class="form-control" id="newPassword" name="new_password" placeholder="New Password" required>
                <label for="newPassword">New Password</label>
            </div>

            <button class="btn btn-update btn-lg w-100 fw-bold" type="submit">
                <iconify-icon icon="material-symbols:save"></iconify-icon>
                Update Password
            </button>
        </form>

    <?php endif; ?>

</div>

</body>
</html>
