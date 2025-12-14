<?php
session_start();
require "db_connect.php";

$msg = "";

// Ensure a user just registered and was redirected here
if (!isset($_SESSION['unverified_email'])) {
    // If not, redirect them back to registration or login
    header("Location: register.php");
    exit;
}

$email = $_SESSION['unverified_email'];

if (isset($_POST['verify'])) {
    $user_otp = trim($_POST['otp']);

    // 1. Check if the provided OTP matches the one in the database
    $stmt = $conn->prepare("SELECT otp FROM user WHERE email = ? AND is_verified = 0");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stored_otp = $row['otp'];

        if ($user_otp === $stored_otp) {
            // 2. OTP matches! Update the user's status to verified (is_verified = 1)
            $update_stmt = $conn->prepare("UPDATE user SET is_verified = 1, otp = NULL WHERE email = ?");
            $update_stmt->bind_param("s", $email);
            
            if ($update_stmt->execute()) {
                // Verification successful
                unset($_SESSION['unverified_email']); // Clear session variable
                header("Location: login.php?verified=1"); // Redirect to login with success message
                exit;
            } else {
                $msg = "Database error during verification. Please try again.";
            }
        } else {
            $msg = "Invalid OTP. Please check your email and try again.";
        }
    } else {
        $msg = "Account not found or already verified. Please go to login.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>EcoTrip - Verify OTP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
<style>
    body {
        font-family: 'Inter', sans-serif;
        background: #f8f9fa;
        margin: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
    }
    .verify-card {
        width: 100%;
        max-width: 450px;
        background: white;
        padding: 40px;
        border-radius: 12px;
        box-shadow: 0 4px 25px rgba(0,0,0,0.1);
    }
    .btn-verify {
        background-color: #1f7a4c;
        border-color: #1f7a4c;
        transition: background-color 0.2s;
        color: white;
    }
    .btn-verify:hover {
        background-color: #145a32;
        border-color: #145a32;
    }
</style>
</head>

<body>

<div class="container">
    <div class="verify-card mx-auto my-5">
        
        <div class="text-center mb-4">
            <iconify-icon icon="ic:round-mark-email-read" width="48" height="48" class="text-success mb-2"></iconify-icon>
            <h3 class="mb-0 fw-bold">Verify Your Email</h3>
            <p class="text-muted small">We have sent a 6-digit verification code to **<?= htmlspecialchars($email) ?>**.</p>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-danger alert-dismissible fade show small">
                <iconify-icon icon="ic:round-error-outline" class="me-1"></iconify-icon> 
                <?= htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-floating mb-4">
                <input type="text" name="otp" class="form-control" id="otpInput" placeholder="Verification Code" required maxlength="6" pattern="\d{6}">
                <label for="otpInput">Enter 6-digit OTP</label>
            </div>

            <button type="submit" name="verify" class="btn btn-verify btn-lg w-100 fw-bold">
                <iconify-icon icon="ic:round-check-circle-outline" class="me-1"></iconify-icon> Verify Account
            </button>
        </form>

        <div class="text-center small mt-4 pt-3 border-top">
            Didn't receive the code? <a href="register.php" class="fw-semibold text-decoration-none text-primary">Re-register to resend</a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>