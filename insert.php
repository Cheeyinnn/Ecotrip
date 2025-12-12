<?php
session_start();
require 'db_connect.php';
require 'includes/auth.php';   // browser-token protection

// Only admin can create challenge
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash'] = "Access denied.";
    header("Location: manage.php");
    exit;
}

// Fetch POST data safely
$challengeTitle = trim($_POST['challengeTitle'] ?? '');
$description    = trim($_POST['description'] ?? '');
$categoryID     = intval($_POST['categoryID'] ?? 0);
$city           = trim($_POST['city'] ?? '');
$pointAward     = intval($_POST['pointAward'] ?? 0);
$is_active      = intval($_POST['is_active'] ?? 1);
$start_date     = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
$end_date       = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
$created_by     = $_SESSION['userID'];

// ----------------------------
// VALIDATION
// ----------------------------
if ($challengeTitle === '' || $categoryID <= 0) {
    $_SESSION['flash'] = "Please fill in all required fields.";
    header("Location: challenge_create_form.php");
    exit;
}

// Escape strings
$title_esc = mysqli_real_escape_string($conn, $challengeTitle);
$desc_esc  = mysqli_real_escape_string($conn, $description);
$city_esc  = mysqli_real_escape_string($conn, $city);
$start_esc = $start_date ? "'" . mysqli_real_escape_string($conn, $start_date) . "'" : "NULL";
$end_esc   = $end_date ? "'" . mysqli_real_escape_string($conn, $end_date) . "'" : "NULL";

// ----------------------------
// SQL INSERT
// ----------------------------
$sql = "
    INSERT INTO challenge 
        (challengeTitle, description, categoryID, city, pointAward, is_active, start_date, end_date, created_by)
    VALUES
        ('$title_esc', '$desc_esc', $categoryID, '$city_esc', $pointAward, $is_active, $start_esc, $end_esc, $created_by)
";

if (mysqli_query($conn, $sql)) {
    $_SESSION['flash'] = "Challenge created successfully.";
} else {
    $_SESSION['flash'] = "Error: " . mysqli_error($conn);
}

mysqli_close($conn);
header("Location: manage.php");
exit;
?>