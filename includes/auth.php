<?php
// ===================================================
// AUTH MIDDLEWARE (PHP-ONLY, SAFE)
// ===================================================

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';

// ---------------------------------------------------
// 1. Must be logged in
// ---------------------------------------------------
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$userID = (int)$_SESSION['userID'];

// ---------------------------------------------------
// 2. Update last_online (once every 60 seconds)
// ---------------------------------------------------
$now = time();

if (!isset($_SESSION['last_online_update']) || ($now - $_SESSION['last_online_update']) > 60) {

    $stmt = $conn->prepare("UPDATE user SET last_online = NOW() WHERE userID = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $stmt->close();

    $_SESSION['last_online_update'] = $now;
}

// ---------------------------------------------------
// 3. Optional role-based access control
// ---------------------------------------------------
if (isset($requiredRole) && $_SESSION['role'] !== $requiredRole) {
    header("Location: login.php");
    exit;
}

// ---------------------------------------------------
// 4. Disable browser cache
// ---------------------------------------------------
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
