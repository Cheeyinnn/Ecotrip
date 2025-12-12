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
$dbtitle         = trim($_POST['challengeTitle'] ?? '');
$description   = trim($_POST['description'] ?? '');
$categoryID    = intval($_POST['categoryID'] ?? 0);
$city          = trim($_POST['city'] ?? '');
$pointAward    = intval($_POST['pointAward'] ?? 0);
$is_active     = intval($_POST['is_active'] ?? 0);
$start_date    = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
$end_date      = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
$created_by    = intval($_POST['created_by'] ?? $_SESSION['userID']); // keep original creator
$newCategoryName = trim($_POST['newCategory'] ?? '');

// 1. GET CATEGORY INPUTS
$categoryID      = intval($_POST['categoryID'] ?? 0);      // The Dropdown
$newCategoryName = trim($_POST['newCategory'] ?? '');      // The Text Input

// 2. 🔴 VALIDATION: CANNOT CHOOSE BOTH
if ($categoryID > 0 && !empty($newCategoryName)) {
    $_SESSION['flash'] = "Error: Please select an existing category OR create a new one. Do not fill both fields.";
    header("Location: challenge_edit.php?id=" . $challengeID); // Send back to edit page
    exit;
}

// 3. 🟢 HANDLE NEW CATEGORY (Only if text input is filled)
if (!empty($newCategoryName)) {
    
    // Check for duplicates
    $checkStmt = $conn->prepare("SELECT categoryID FROM category WHERE categoryName = ?");
    $checkStmt->bind_param("s", $newCategoryName);
    $checkStmt->execute();
    $res = $checkStmt->get_result();

    if ($res->num_rows > 0) {
        // If it exists, use the existing ID (Smart Match)
        $row = $res->fetch_assoc();
        $categoryID = $row['categoryID'];
    } else {
        // If it doesn't exist, Create It
        $insertCat = $conn->prepare("INSERT INTO category (categoryName) VALUES (?)");
        $insertCat->bind_param("s", $newCategoryName);
        if ($insertCat->execute()) {
            $categoryID = $conn->insert_id;
        }
        $insertCat->close();
    }
    $checkStmt->close();
}

// 4. FINAL CHECK: Did we end up with a category?
if ($categoryID <= 0) {
    $_SESSION['flash'] = "Error: You must select or create a category.";
    header("Location: challenge_edit.php?id=" . $challengeID);
    exit;
}

// Required fields
if ($challengeID <= 0 || $dbtitle === '' || $categoryID <= 0 || $pointAward < 0) {
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
    $dbtitle,
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