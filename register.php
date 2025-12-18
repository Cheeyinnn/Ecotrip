<?php
session_start();
require "db_connect.php"; // contains $conn

// Load PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// --- UPDATED PATHS based on your folder structure (PHPMailer/src/) ---
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
// -----------------------------------------------------------------------------

$msg = "";

// Initialize variables for sticky form
$first = $_POST['firstName'] ?? '';
$last  = $_POST['lastName'] ?? '';
$email = $_POST['email'] ?? '';
$phone = $_POST['phone'] ?? ''; 
$address = $_POST['address'] ?? ''; 

if (isset($_POST['register'])) {

    $first = trim($_POST['firstName']);
    $last  = trim($_POST['lastName']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']); 
    $address = trim($_POST['address']); 
    $pass1 = $_POST['password'];
    $pass2 = $_POST['confirm_password'];

    // ==========================
    // PASSWORD VALIDATION
    // ==========================
    if (strlen($pass1) < 6) {
        $msg = "Password must be at least 6 characters.";
    }
    elseif ($pass1 !== $pass2) {
        $msg = "Passwords do not match!";
    } 
    else {

        $pass = password_hash($pass1, PASSWORD_DEFAULT);
        
        // Generate a 6-digit numeric OTP
        $otp = random_int(100000, 999999); 

        // Check if email exists
        $check = $conn->prepare("SELECT userID FROM user WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $msg = "Email already exists!";
        } else {

            // INSERT user with unverified status (is_verified = 0) and the OTP
            // *** REQUIREMENT: user table must have 'is_verified' (TINYINT default 0) and 'otp' (VARCHAR) columns ***
            $stmt = $conn->prepare("
                INSERT INTO user (firstName, lastName, email, phone, address, password, role, is_verified, otp) 
                VALUES (?, ?, ?, ?, ?, ?, 'user', 0, ?)
            ");
            
            $stmt->bind_param("sssssss", $first, $last, $email, $phone, $address, $pass, $otp);

            if ($stmt->execute()) {
                
                // === SEND OTP EMAIL USING MAILTRAP/PHPMailer ===
                $mail = new PHPMailer(true);
                
                try {
                    // MAILTRAP SANDBOX CREDENTIALS (for testing)
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.mailtrap.io'; 
                    $mail->SMTPAuth   = true;
                    $mail->Port       = 2525; 

                    // Your Mailtrap Sandbox credentials
                    $mail->Username   = 'b8bbcfb6e70940'; 
                    $mail->Password   = 'faa75c357b0d79'; 
                    
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 

                    // Recipients
                    $mail->setFrom('noreply@ecotrip.com', 'EcoTrip Registration');
                    $mail->addAddress($email, $first . ' ' . $last); 

                    // Content
                    $mail->isHTML(true);
                    $mail->Subject = 'EcoTrip - Email Verification Code';
                    $mail->Body    = "
                        <h2>Welcome to EcoTrip!</h2>
                        <p>Thank You for registering. Your One-Time Password (OTP) for verification is:</p>
                        <h1 style='color:#1f7a4c; text-align:center;'>$otp</h1>
                        <p>Please enter this code on the next screen to activate your account.</p>
                        <p>If you did not register for an account, please ignore this email.</p>
                    ";
                    $mail->AltBody = "Your verification code is: $otp";

                    $mail->send();
                    
                    // Email sent successfully, redirect to OTP verification page
                    $_SESSION['unverified_email'] = $email;
                    header("Location: verify_otp.php");
                    exit;
                    
                } catch (Exception $e) {
                    // If email sending fails, delete the partially created account 
                    $delete_stmt = $conn->prepare("DELETE FROM user WHERE email = ?");
                    $delete_stmt->bind_param("s", $email);
                    $delete_stmt->execute();
                    
                    $msg = "Registration successful, but OTP email could not be sent. Mailer Error: {$mail->ErrorInfo}";
                }

            } else {
                $msg = "Error creating account: " . $stmt->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>EcoTrip - Registration</title>
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

    .register-card {
        width: 100%;
        max-width: 650px; 
        background: white;
        padding: 40px;
        border-radius: 12px;
        box-shadow: 0 4px 25px rgba(0,0,0,0.1);
    }
    
    /* Custom Green Button Styling */
    .btn-register {
        background-color: #1f7a4c;
        border-color: #1f7a4c;
        transition: background-color 0.2s;
        color: white;
    }
    .btn-register:hover {
        background-color: #145a32;
        border-color: #145a32;
    }
    
    /* Ensure inputs look good with Form Floating */
    .form-floating > .form-control {
        height: calc(3.5rem + 2px);
        line-height: 1.25;
    }
    /* Fixed height for textarea in form-floating */
    .form-floating textarea {
        min-height: 80px;
    }
</style>
</head>

<body>

<div class="container">
    <div class="register-card mx-auto my-5">
        
        <div class="text-center mb-4">
            <iconify-icon icon="ic:round-person-add-alt" width="48" height="48" class="text-primary mb-2"></iconify-icon>
            <h3 class="mb-0 fw-bold">Create Your Account</h3>
            <p class="text-muted small">Join EcoTrip and start managing your team.</p>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-danger alert-dismissible fade show small">
                <iconify-icon icon="ic:round-error-outline" class="me-1"></iconify-icon> 
                <?= htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST">
            
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="form-floating">
                        <input type="text" name="firstName" class="form-control" id="firstNameInput" placeholder="First Name" value="<?= htmlspecialchars($first) ?>" required>
                        <label for="firstNameInput">First Name</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-floating">
                        <input type="text" name="lastName" class="form-control" id="lastNameInput" placeholder="Last Name" value="<?= htmlspecialchars($last) ?>" required>
                        <label for="lastNameInput">Last Name</label>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <div class="form-floating">
                        <input type="email" name="email" class="form-control" id="emailInput" placeholder="Email" value="<?= htmlspecialchars($email) ?>" required>
                        <label for="emailInput">Email Address</label>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="form-floating">
                        <input type="tel" name="phone" class="form-control" id="phoneInput" placeholder="Phone Number (Optional)" value="<?= htmlspecialchars($phone) ?>">
                        <label for="phoneInput">Phone Number (Optional)</label>
                    </div>
                </div>
            </div>
            
            <div class="form-floating mb-3">
                <textarea name="address" class="form-control" id="addressInput" placeholder="Address (Optional)"><?= htmlspecialchars($address) ?></textarea>
                <label for="addressInput">Address (Optional)</label>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="form-floating">
                        <input type="password" name="password" class="form-control" id="passwordInput" placeholder="Password" required>
                        <label for="passwordInput">Password (Min 6 chars)</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-floating">
                        <input type="password" name="confirm_password" class="form-control" id="confirmPassInput" placeholder="Confirm Password" required>
                        <label for="confirmPassInput">Confirm Password</label>
                    </div>
                </div>
            </div>

            <button type="submit" name="register" class="btn btn-register btn-lg w-100 fw-bold">
                <iconify-icon icon="ic:round-how-to-reg" class="me-1"></iconify-icon> Register Account
            </button>
        </form>

        <div class="text-center small mt-4 pt-3 border-top">
            Already have an account? <a href="login.php" class="fw-semibold text-decoration-none text-primary">Log in here</a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>