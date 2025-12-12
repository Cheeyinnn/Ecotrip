<?php
function sendNotification($conn, $userID, $message, $link = null) {
    $stmt = $conn->prepare("
        INSERT INTO notifications (userID, message, link)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iss", $userID, $message, $link);
    $stmt->execute();
    $stmt->close();
}
?>
