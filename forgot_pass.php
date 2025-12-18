<?php
require 'db_connect.php';

$message = "";
$messageType = ""; // success or danger (for error)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);

    // Check if user exists
    $stmt = $conn->prepare("SELECT userID FROM user WHERE email=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {

        $user = $result->fetch_assoc();
        $userID = $user['userID'];

        // Create reset token
        $token   = bin2hex(random_bytes(32));
        $channel = 1; // email

        // INSERT TOKEN (timezone-safe)
        $insert = $conn->prepare("
            INSERT INTO verificationtoken (otpCode, channel, expires_at, userID)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)
        ");
        $insert->bind_param("sii", $token, $channel, $userID);
        $insert->execute();

        $reset_link = "http://localhost/ecotrip/reset_password.php?token=" . urlencode($token);

        // Send email
        require 'reset_email.php';
        sendResetEmail($email, $reset_link);

        $message = "A password reset link has been sent to your email. Check your inbox (and spam folder) for the link which expires in 5 minutes.";
        $messageType = "success";

    } else {
        $message = "Email not found in our system. Please check the spelling.";
        $messageType = "danger";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password - EcoTrip</title>

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
        .forgot-card {
            width: 100%;
            max-width: 420px;
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.1);
        }
        .btn-send {
            background-color: #1f7a4c;
            border-color: #1f7a4c;
            transition: background-color 0.2s;
            color: white;
        }
        .btn-send:hover {
            background-color: #145a32;
            border-color: #145a32;
        }
    </style>
</head>

<body>

<div class="container">
    <div class="forgot-card mx-auto">
        
        <div class="text-center mb-4">
            <iconify-icon icon="material-symbols:vpn-key-outline" width="48" height="48" class="text-secondary mb-2"></iconify-icon>
            <h3 class="mb-0 fw-bold">Forgot Password</h3>
        </div>

        <?php if ($message != ""): ?>
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show small">
                <iconify-icon icon="<?= $messageType === 'success' ? 'ic:round-check-circle' : 'ic:round-error-outline' ?>" class="me-1"></iconify-icon>
                <?= htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <p class="text-muted small text-center mb-4">
            Enter your account's email address and we will send you a reset link.
        </p>

        <form method="post">
            <div class="form-floating mb-4">
                <input type="email" class="form-control" id="emailInput" name="email" placeholder="Email address" required>
                <label for="emailInput">Email address</label>
            </div>

            <button class="btn btn-send btn-lg w-100 fw-bold" type="submit">
                <iconify-icon icon="ic:round-email" class="me-1"></iconify-icon>
                Send Reset Link
            </button>
        </form>

        <div class="text-center small mt-4 pt-3 border-top">
            <a href="login.php" class="btn btn-outline-secondary w-100">
                <iconify-icon icon="ic:round-arrow-back"></iconify-icon>
                Back to Login
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
