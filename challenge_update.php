<?php
// ------------------------------------------------------
// SESSION + DB + AUTH
// ------------------------------------------------------
require "db_connect.php";
require "includes/auth.php"; // browser token + login required

// ------------------------------------------------------
// ADMIN ONLY
// ------------------------------------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash'] = "Access denied. Admin only.";
    header("Location: index.php");
    exit;
}

// ------------------------------------------------------
// VALIDATE REQUEST
// ------------------------------------------------------
$challengeID   = intval($_POST['challengeID'] ?? 0);
$title         = trim($_POST['challengeTitle'] ?? '');
$description   = trim($_POST['description'] ?? '');
$categoryID    = intval($_POST['categoryID'] ?? 0);
$city          = trim($_POST['city'] ?? '');
$pointAward    = intval($_POST['pointAward'] ?? 0);
$is_active     = intval($_POST['is_active'] ?? 0);
$start_date    = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
$end_date      = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
$created_by    = intval($_POST['created_by'] ?? $_SESSION['userID']); // keep original creator

// Required fields
if ($challengeID <= 0 || $title === '' || $categoryID <= 0 || $pointAward < 0) {
    $_SESSION['flash'] = "Invalid input. Missing required fields.";
    header("Location: manage.php");
    exit;
}

// ------------------------------------------------------
// PREPARED STATEMENT UPDATE (Safe)
// ------------------------------------------------------
$sql = "
    UPDATE challenge SET
        challengeTitle = ?,
        description = ?,
        categoryID = ?,
        city = ?,
        pointAward = ?,
        is_active = ?,
        start_date = ?,
        end_date = ?,
        created_by = ?
    WHERE challengeID = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ssississii",
    $title,
    $description,
    $categoryID,
    $city,
    $pointAward,
    $is_active,
    $start_date,
    $end_date,
    $created_by,
    $challengeID
);

$ok = $stmt->execute();

if ($ok) {
    $_SESSION['flash'] = "Challenge updated successfully.";
} else {
    $_SESSION['flash'] = "Update failed: " . $stmt->error;
}

$stmt->close();
$conn->close();

// ------------------------------------------------------
// REDIRECT BACK
// ------------------------------------------------------
header("Location: manage.php");
exit;
?>