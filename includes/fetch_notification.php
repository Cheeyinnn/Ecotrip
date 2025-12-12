<?php
session_start();
require "../db_connect.php";

if (!isset($_SESSION['userID'])) {
    echo json_encode(["ok" => false]);
    exit;
}

$userID = (int)$_SESSION['userID'];

// Get unread count
$stmt1 = $conn->prepare("
    SELECT COUNT(*) AS unread
    FROM notifications
    WHERE userID = ? AND is_read = 0
");
$stmt1->bind_param("i", $userID);
$stmt1->execute();
$unread = $stmt1->get_result()->fetch_assoc()['unread'] ?? 0;
$stmt1->close();

// Latest 10 notifications
$stmt2 = $conn->prepare("
    SELECT id, message, link, created_at, is_read
    FROM notifications
    WHERE userID = ?
    ORDER BY id DESC
    LIMIT 10
");
$stmt2->bind_param("i", $userID);
$stmt2->execute();
$list = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

echo json_encode([
    "ok" => true,
    "unread" => $unread,
    "list" => $list
]);
?>
