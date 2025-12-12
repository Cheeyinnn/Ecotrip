<?php
// ===================================================
// SESSION + AUTH CHECK
// ===================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';

// Must be logged in
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$userID = (int)$_SESSION['userID'];

// ===================================================
// FETCH USER INFO (safe + guaranteed)
// ===================================================
// Some pages already load $user, but if not, load it here
if (!isset($user) || !is_array($user) || empty($user['firstName'])) {

    $stmtLayoutUser = $conn->prepare("
        SELECT userID, firstName, lastName, email, role, avatarURL
        FROM user
        WHERE userID = ?
    ");
    $stmtLayoutUser->bind_param("i", $userID);
    $stmtLayoutUser->execute();
    $user = $stmtLayoutUser->get_result()->fetch_assoc();
    $stmtLayoutUser->close();
}

// If somehow user does not exist → force logout
if (!$user) {
    header("Location: logout.php");
    exit;
}

// ===================================================
// SECURE SESSION SYNC
// ===================================================


// Only overwrite role if valid and non-empty
if (!empty($user['role'])) {
    $_SESSION['role'] = $user['role'];
}

// Store name for convenience
$_SESSION['firstName'] = $user['firstName'] ?? '';
$_SESSION['lastName']  = $user['lastName'] ?? '';

// ===================================================
// UNREAD NOTIFICATION COUNT
// ===================================================
$notiCount = 0;

$notiStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE userID = ? AND is_read = 0
");
$notiStmt->bind_param("i", $userID);
$notiStmt->execute();
$notiRow = $notiStmt->get_result()->fetch_assoc();
$notiStmt->close();

if (!empty($notiRow['total'])) {
    $notiCount = (int)$notiRow['total'];
}

// ===================================================
// AVATAR PATH
// ===================================================
$avatarPath = "uploads/default.png";

if (!empty($user['avatarURL'])) {
    $realPath = __DIR__ . '/../' . $user['avatarURL'];
    if (file_exists($realPath)) {
        $avatarPath = $user['avatarURL'];
    }
}

// ===================================================
// PAGE TITLE FALLBACK
// ===================================================
if (!isset($pageTitle)) {
    $pageTitle = "EcoTrip Dashboard";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css"
          rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
    

    <!-- Layout CSS -->
    <link rel="stylesheet" href="includes/layout.css">
</head>

<body>

<div class="layout-wrapper">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content d-flex flex-column">

        <?php include __DIR__ . '/topbar.php'; ?>

        <div class="content-wrapper flex-grow-1">
