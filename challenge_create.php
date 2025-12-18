<?php
// --------------------------------------------------------
// SESSION + DB + AUTH
// --------------------------------------------------------
require 'db_connect.php';
require 'includes/auth.php';

if ($_SESSION['role'] !== 'admin') {
    $_SESSION['flash'] = "Access denied.";
    header("Location: view.php");
    exit;
}

$userID = $_SESSION['userID'];

// --------------------------------------------------------
// FETCH DATA
// --------------------------------------------------------
$challengeTitle = trim($_POST['challengeTitle'] ?? '');
$description    = trim($_POST['description'] ?? '');
$city           = trim($_POST['city'] ?? '');
$pointAward     = intval($_POST['pointAward'] ?? 0);
$is_active      = intval($_POST['is_active'] ?? 1);
$start_date     = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
$end_date       = !empty($_POST['end_date'])   ? $_POST['end_date']   : null;

// ========================================================
// 🆕 DATE VALIDATION
// ========================================================
$today = date('Y-m-d');

if ($start_date !== null && $start_date < $today) {
    $_SESSION['flash'] = "Error: Start date cannot be earlier than today.";
    header("Location: challenge_create_form.php");
    exit;
}

if ($start_date !== null && $end_date !== null && $end_date < $start_date) {
    $_SESSION['flash'] = "Error: End date cannot be earlier than the start date.";
    header("Location: challenge_create_form.php");
    exit;
}

// ========================================================
// 🆕 CATEGORY LOGIC: CHECK NEW vs EXISTING
// ========================================================
$categoryID = intval($_POST['categoryID'] ?? 0);
$newCategoryName = trim($_POST['newCategory'] ?? '');

// If user typed a new category name, insert it first!
if (!empty($newCategoryName)) {
    // Check if it already exists to avoid duplicates (Optional but good)
    $checkStmt = $conn->prepare("SELECT categoryID FROM category WHERE categoryName = ?");
    $checkStmt->bind_param("s", $newCategoryName);
    $checkStmt->execute();
    $res = $checkStmt->get_result();

    if ($res->num_rows > 0) {
        // It exists, just use that ID
        $row = $res->fetch_assoc();
        $categoryID = $row['categoryID'];
    } else {
        // It doesn't exist, INSERT IT
        $insertCat = $conn->prepare("INSERT INTO category (categoryName) VALUES (?)");
        $insertCat->bind_param("s", $newCategoryName);
        if ($insertCat->execute()) {
            $categoryID = $conn->insert_id; // Get the ID of the new category
        }
        $insertCat->close();
    }
    $checkStmt->close();
}

// Validation
if ($challengeTitle === '' || $categoryID <= 0 || $pointAward < 0) {
    $_SESSION['flash'] = "Please fill in all required fields (Title, Category, Points).";
    header("Location: challenge_create_form.php");
    exit;
}

// --------------------------------------------------------
// INSERT CHALLENGE
// --------------------------------------------------------
$sql = "
    INSERT INTO challenge 
        (challengeTitle, description, city, pointAward, start_date, end_date, is_active, created_by, categoryID)
    VALUES 
        (?, ?, ?, ?, ?, ?, ?, ?, ?)
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "sssissiii",
    $challengeTitle,
    $description,
    $city,
    $pointAward,
    $start_date,
    $end_date,
    $is_active,
    $userID,
    $categoryID
);

if ($stmt->execute()) {
    $_SESSION['flash'] = "Challenge created successfully!";
} else {
    $_SESSION['flash'] = "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();

header("Location: manage.php");
exit;
?>