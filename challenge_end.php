<?php
// --------------------------------------------------
// SESSION + DB + AUTH
// --------------------------------------------------
require 'db_connect.php';
require 'includes/auth.php';   // ensures user is logged in + browser token

// --------------------------------------------------
// ADMIN ONLY
// --------------------------------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash'] = "Access denied.";
    header("Location: manage.php");
    exit;
}

// --------------------------------------------------
// Validate Challenge ID
// --------------------------------------------------
if (!isset($_GET['id']) || intval($_GET['id']) <= 0) {
    $_SESSION['flash'] = "Invalid challenge ID.";
    header("Location: manage.php");
    exit;
}

$challengeID = intval($_GET['id']);

// --------------------------------------------------
// End Challenge: Set end_date = today + deactivate
// --------------------------------------------------
$today = date("Y-m-d");

$stmt = $conn->prepare("
    UPDATE challenge
    SET end_date = ?, is_active = 0
    WHERE challengeID = ?
");

$stmt->bind_param("si", $today, $challengeID);
$success = $stmt->execute();

if ($success) {
    $_SESSION['flash'] = "Challenge ended successfully.";
} else {
    $_SESSION['flash'] = "Failed to end challenge: " . $stmt->error;
}

$stmt->close();
$conn->close();

// Redirect back to manage list
header("Location: manage.php");
exit;
?>