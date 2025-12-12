<?php
session_start();
require_once "../db_connect.php";

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

$userID = (int)$_SESSION['userID'];

$stmt = $conn->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE userID = ? AND is_read = 0
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
exit;
