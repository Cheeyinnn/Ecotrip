<?php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$submissionId = intval($_POST['submission_id'] ?? 0);
$decision     = $_POST['review_result'] ?? '';
$feedback     = trim($_POST['feedback'] ?? '');

if (!$submissionId || !in_array($decision, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

/* ===== check submission + challenge ===== */
$tempSql = "
    SELECT s.userID, c.pointAward
    FROM sub s
    JOIN challenge c ON s.challengeID = c.challengeID
    WHERE s.submissionID = ?
";
$tempStmt = $conn->prepare($tempSql);
$tempStmt->bind_param("i", $submissionId);
$tempStmt->execute();
$temp = $tempStmt->get_result()->fetch_assoc();
$tempStmt->close();

$status = $decision === 'approve' ? 'Approved' : 'Denied';
$points = $decision === 'approve' ? (int)$temp['pointAward'] : 0;

/* ===== renew submission (with time) ===== */
if ($decision === 'approve') {
    $status = 'Approved';
    $points = (int)$temp['pointAward'];

    $sql = "
        UPDATE sub
        SET status=?, pointEarned=?, reviewNote=?,
            approved_at=NOW(), denied_at=NULL
        WHERE submissionID=?
    ";
} else {
    $status = 'Denied';
    $points = 0;

    $sql = "
        UPDATE sub
        SET status=?, pointEarned=?, reviewNote=?,
            denied_at=NOW(), approved_at=NULL
        WHERE submissionID=?
    ";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("sisi", $status, $points, $feedback, $submissionId);
$stmt->execute();
$stmt->close();


/* ===== record point（approve ）===== */
if ($decision === 'approve' && !empty($temp['userID'])) {
    $insert = $conn->prepare("
        INSERT INTO pointtransaction
        (transactionType, pointsTransaction, generate_at, submissionID, userID)
        VALUES ('earn', ?, NOW(), ?, ?)
    ");
    $insert->bind_param("iii", $points, $submissionId, $temp['userID']);
    $insert->execute();
    $insert->close();
}

/* ===== back JSON ===== */
echo json_encode([
    'success' => true,
    'submission_id' => $submissionId,
    'status' => strtolower($status),
    'feedback' => $feedback
]);
