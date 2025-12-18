<?php
require 'db_connect.php';

$token = $_GET['token'] ?? '';

$error = '';
$showForm = false;
$userID = null;

// ===================================================
// 1. VALIDATE TOKEN (GET) — FIXED
// ===================================================
if ($token !== '') {

    $stmt = $conn->prepare("
        SELECT userID
        FROM verificationtoken
        WHERE otpCode = ?
          AND used_at IS NULL
          AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $error = "Invalid or expired reset link.";
    } else {
        $row = $result->fetch_assoc();
        $userID = (int)$row['userID'];
        $showForm = true;
    }

} else {
    $error = "Invalid reset request.";
}

// ===================================================
// 2. HANDLE PASSWORD UPDATE (POST)
// ===================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showForm) {

    $pass1 = $_POST['new_password'] ?? '';
    $pass2 = $_POST['confirm_password'] ?? '';

    if (strlen($pass1) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($pass1 !== $pass2) {
        $error = "Passwords do not match.";
    } else {

        $newHash = password_hash($pass1, PASSWORD_DEFAULT);

        $conn->begin_transaction();

        try {
            // Update password
            $update = $conn->prepare(
                "UPDATE user SET password = ? WHERE userID = ?"
            );
            $update->bind_param("si", $newHash, $userID);
            $update->execute();

            // Mark token as used
            $mark = $conn->prepare(
                "UPDATE verificationtoken SET used_at = NOW() WHERE otpCode = ?"
            );
            $mark->bind_param("s", $token);
            $mark->execute();

            $conn->commit();

            header("Location: login.php?reset=1");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = "An error occurred. Please try again.";
            $showForm = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - EcoTrip</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

    <style>
        body {
            font-family: Inter, Arial, sans-serif;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }
        .reset-card {
            width: 420px;
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.1);
        }
        .btn-update {
            background-color: #1f7a4c;
            border-color: #1f7a4c;
            color: #fff;
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

    <?php if ($error): ?>
        <div class="alert alert-danger text-center fw-semibold">
            <?= htmlspecialchars($error) ?>
        </div>
        <a href="login.php" class="btn btn-secondary w-100">Back to Login</a>

    <?php elseif ($showForm): ?>
        <p class="text-muted small text-center mb-4">Enter your new password below.</p>

        <form method="post">
            <div class="form-floating mb-3">
                <input type="password" class="form-control" name="new_password" placeholder="New Password" required>
                <label>New Password (Min 6 chars)</label>
            </div>

            <div class="form-floating mb-4">
                <input type="password" class="form-control" name="confirm_password" placeholder="Confirm Password" required>
                <label>Confirm Password</label>
            </div>

            <button class="btn btn-update btn-lg w-100 fw-bold" type="submit">
                <iconify-icon icon="material-symbols:save"></iconify-icon>
                Update Password
            </button>
        </form>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
