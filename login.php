<?php
session_start();
require 'db_connect.php';
require_once 'includes/notify.php';

$error = '';
$success_msg = '';

// ------------------------------------------
// Success messages from redirect
// ------------------------------------------
if (isset($_GET['registered'])) {
    $success_msg = 'Registration successful! Please check your email for the verification code.';
}
if (isset($_GET['verified'])) {
    $success_msg = 'Account successfully verified! You may now log in.';
}

// ------------------------------------------
// Remember Me (email only)
// ------------------------------------------
$saved_email = $_COOKIE['remember_email'] ?? '';

// ------------------------------------------
// Handle Login
// ------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $stmt = $conn->prepare(
        'SELECT userID, firstName, lastName, email, password, role, is_Active, is_verified
         FROM user
         WHERE email = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {

        $user = $result->fetch_assoc();

        if ((int)$user['is_Active'] === 0) {
    $error = 'Your account is suspended. Please contact admin.';
}
elseif (!password_verify($password, $user['password'])) {
    $error = 'Invalid password.';
}
else {

            // Security
            session_regenerate_id(true);

            // Session data
            $_SESSION['userID'] = (int)$user['userID'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['firstName'] = $user['firstName'];
            $_SESSION['lastName'] = $user['lastName'];
            $_SESSION['browser_token'] = bin2hex(random_bytes(16));

            // Notify user
            sendNotification(
                $conn,
                $user['userID'],
                'Login Successful',
                'view.php'
            );

            // Notify admins
            $adminQuery = $conn->query(
                "SELECT userID FROM user WHERE role='admin' AND is_Active=1"
            );

            if ($adminQuery) {
                while ($admin = $adminQuery->fetch_assoc()) {
                    sendNotification(
                        $conn,
                        $admin['userID'],
                        $user['firstName'].' '.$user['lastName'].' has logged in.',
                        'manage_user.php'
                    );
                }
            }

            // Remember email only
            if (isset($_POST['remember'])) {
                setcookie('remember_email', $email, time() + (86400 * 30), '/');
            } else {
                setcookie('remember_email', '', time() - 3600, '/');
            }

            // Redirect by role
            if ($user['role'] === 'admin') {
                header('Location: manage.php');
            } elseif ($user['role'] === 'moderator') {
                header('Location: moderator.php');
            } else {
                header('Location: view.php');
            }
            exit;
        }

    } else {
        $error = 'Email not found.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - EcoTrip</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

    <style>
        body {
            font-family: Inter, Arial, sans-serif;
            background: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            width: 420px;
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            margin: auto;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
    </style>
</head>

<body>

<div class="login-card">

    <div class="text-center mb-4">
        <iconify-icon icon="ic:sharp-eco" width="48" class="text-success mb-2"></iconify-icon>
        <h3 class="fw-bold mb-0">Sign in to EcoTrip</h3>
        <p class="text-muted small">Access your dashboard and team tools.</p>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show small">
            <?= htmlspecialchars($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show small">
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="post">

        <div class="form-floating mb-3">
            <input type="email" class="form-control" name="email" id="emailInput"
                   placeholder="Email"
                   value="<?= htmlspecialchars($saved_email) ?>" required>
            <label for="emailInput">Email address</label>
        </div>

        <div class="form-floating mb-3 position-relative">
            <input type="password" class="form-control" name="password" id="passwordInput"
                   placeholder="Password" required>
            <label for="passwordInput">Password</label>

            <span id="togglePassword"
                  class="position-absolute"
                  style="top:50%; right:15px; transform:translateY(-50%); cursor:pointer;">
                <iconify-icon id="togglePasswordIcon" icon="mdi:eye-off" width="22"></iconify-icon>
            </span>
        </div>

        <div class="d-flex justify-content-between align-items-center small mb-4">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="rememberMe" name="remember"
                    <?= $saved_email ? 'checked' : '' ?>>
                <label for="rememberMe" class="form-check-label text-muted">Remember me</label>
            </div>
            <a href="forgot_pass.php" class="small text-secondary">Forgot password?</a>
        </div>

        <button class="btn btn-success w-100 fw-bold" type="submit">Log In</button>
    </form>

    <div class="text-center small mt-4 pt-3 border-top">
        No account yet?
        <a href="register.php" class="fw-semibold text-primary text-decoration-none">Create one</a>
    </div>
</div>

<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const input = document.getElementById('passwordInput');
    const icon = document.getElementById('togglePasswordIcon');

    if (input.type === 'password') {
        input.type = 'text';
        icon.setAttribute('icon', 'mdi:eye');
    } else {
        input.type = 'password';
        icon.setAttribute('icon', 'mdi:eye-off');
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
