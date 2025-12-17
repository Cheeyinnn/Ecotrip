<?php
session_start();
require 'db_connect.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Invalid request']);
    exit;
}

$submissionId = intval($_POST['submission_id'] ?? 0);
$decision     = $_POST['review_result'] ?? '';
$feedback     = trim($_POST['feedback'] ?? '');

if (!$submissionId || !in_array($decision,['approve','reject'])) {
    echo json_encode(['success'=>false,'message'=>'Invalid data']);
    exit;
}

// moderator ID from session
$moderatorID = $_SESSION['userID'] ?? null;
if (!$moderatorID) {
    echo json_encode(['success'=>false,'message'=>'Moderator not logged in']);
    exit;
}

// fetch submission + challenge info
$tempSql = "SELECT s.userID, c.pointAward FROM sub s JOIN challenge c ON s.challengeID = c.challengeID WHERE s.submissionID=?";
$tempStmt = $conn->prepare($tempSql);
$tempStmt->bind_param("i",$submissionId);
$tempStmt->execute();
$temp = $tempStmt->get_result()->fetch_assoc();
$tempStmt->close();

$status = $decision === 'approve' ? 'Approved' : 'Denied';
$points = $decision === 'approve' ? (int)$temp['pointAward'] : 0;

// 更新 submission + moderatorID
if ($decision === 'approve') {
    $sql = "UPDATE sub SET status=?, pointEarned=?, reviewNote=?, approved_at=NOW(), denied_at=NULL, moderatorID=? WHERE submissionID=?";
} else {
    $sql = "UPDATE sub SET status=?, pointEarned=?, reviewNote=?, denied_at=NOW(), approved_at=NULL, moderatorID=? WHERE submissionID=?";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("sisii",$status,$points,$feedback,$moderatorID,$submissionId);
if (!$stmt->execute()) {
    echo json_encode(['success'=>false,'message'=>'Database update failed: '.$stmt->error]);
    exit;
}
$stmt->close();

// 插入 points（approve）
if ($decision==='approve' && !empty($temp['userID'])) {
    $insert = $conn->prepare("
        INSERT INTO pointtransaction 
        (transactionType, pointsTransaction, generate_at, submissionID, userID) 
        VALUES ('earn', ?, NOW(), ?, ?)
    ");
    $insert->bind_param("iii",$points,$submissionId,$temp['userID']);
    $insert->execute();
    $insert->close();
}

echo json_encode([
    'success'=>true,
    'submission_id'=>$submissionId,
    'status'=>strtolower($status),
    'feedback'=>$feedback
]);
