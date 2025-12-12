<?php
// ---------------------------------------------
// Start session
// ---------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'db_connect.php';

// ---------------------------------------------
// 1. Must be logged in
// ---------------------------------------------
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['userID'];

// ---------------------------------------------
// ⭐ 2. Optimized last_online update
// Update only once every 60 seconds
// ---------------------------------------------
$now = time();

if (!isset($_SESSION['last_online_update']) || ($now - $_SESSION['last_online_update']) > 60) {

    $stmt = $conn->prepare("UPDATE user SET last_online = NOW() WHERE userID = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $stmt->close();

    // Store timestamp so we don't update too often
    $_SESSION['last_online_update'] = $now;
}

// ---------------------------------------------
// 3. Must have browser token
// ---------------------------------------------
if (!isset($_SESSION['browser_token'])) {
    header("Location: logout.php");
    exit;
}

$phpToken = $_SESSION['browser_token'];

// ---------------------------------------------
// 4. Disable Browser Cache (prevents seeing pages after logout)
// ---------------------------------------------
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
?>

<script>
// ================================================
// Browser Token Validation
// ================================================
const phpToken = "<?= $phpToken ?>";
const browserToken = sessionStorage.getItem("browser_token");

// CASE 1 — Browser restarted → sessionStorage empty
if (!browserToken) {
    sessionStorage.clear();
    window.location = "logout.php";
}

// CASE 2 — Token mismatch → stolen or invalid session
if (browserToken !== phpToken) {
    sessionStorage.clear();
    window.location = "logout.php";
}
</script>
