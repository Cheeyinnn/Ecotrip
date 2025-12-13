<?php
require "db_connect.php";
// Ensure no PHP warnings/notices break the JSON output
ini_set('display_errors', 0);
header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'user';

if ($id === 0) {
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$history = [];
$breakdown = [];

// Helper function to handle SQL errors
function handleSqlError($stmt, $conn) {
    if (!$stmt) {
        echo json_encode(['error' => 'SQL Prepare Error: ' . $conn->error]);
        exit;
    }
}

// Prepare query based on User or Team
if ($type === 'user') {
    // UPDATED NAMES based on your specific instructions:
    // Table: challenge (singular), PK: challengeID, Title: challengeTitle
    // Table: sub, PK: submissionID
    // Table: pointtransaction, FK: submissionID
    $query = "SELECT 
                pt.pointsTransaction, 
                pt.generate_at, 
                pt.transactionType, 
                COALESCE(c.challengeTitle, 'General Activity') as source_name 
              FROM pointtransaction pt 
              LEFT JOIN sub s ON pt.submissionID = s.submissionID
              LEFT JOIN challenge c ON s.challengeID = c.challengeID
              WHERE pt.userID = ? AND pt.transactionType = 'earn' 
              ORDER BY pt.generate_at ASC";
              
    $stmt = $conn->prepare($query);
    handleSqlError($stmt, $conn);
    $stmt->bind_param("i", $id);
} else {
    // Team Query
    $query = "SELECT 
                pt.pointsTransaction, 
                pt.generate_at, 
                pt.transactionType, 
                CONCAT(u.firstName, ' ', u.lastName) as user_name,
                COALESCE(c.challengeTitle, 'General Activity') as challenge_name
              FROM pointtransaction pt 
              JOIN user u ON pt.userID = u.userID 
              LEFT JOIN sub s ON pt.submissionID = s.submissionID
              LEFT JOIN challenge c ON s.challengeID = c.challengeID
              WHERE u.teamID = ? AND pt.transactionType = 'earn' 
              ORDER BY pt.generate_at ASC";
              
    $stmt = $conn->prepare($query);
    handleSqlError($stmt, $conn);
    $stmt->bind_param("i", $id);
}

if (!$stmt->execute()) {
    echo json_encode(['error' => 'SQL Execute Error: ' . $stmt->error]);
    exit;
}

$result = $stmt->get_result();

$cumulativePoints = 0;
$graphLabels = [];
$graphData = [];

while ($row = $result->fetch_assoc()) {
    // Process Graph Data (Cumulative)
    $date = date('M d', strtotime($row['generate_at']));
    $cumulativePoints += $row['pointsTransaction'];
    
    $graphLabels[] = $date;
    $graphData[] = $cumulativePoints; 
    
    // Process Breakdown List
    if ($type === 'team') {
        $description = $row['user_name'] . ' - ' . $row['challenge_name'];
        $source = $row['challenge_name'];
    } else {
        $description = $row['source_name']; // The Challenge Title
        $source = $row['transactionType'];
    }

    array_unshift($breakdown, [
        'points' => $row['pointsTransaction'],
        'date' => date('Y-m-d H:i', strtotime($row['generate_at'])),
        'description' => $description, 
        'source' => $source
    ]);
}

// Limit breakdown to last 10 items
$breakdown = array_slice($breakdown, 0, 10);

echo json_encode([
    'labels' => $graphLabels,
    'data' => $graphData,
    'breakdown' => $breakdown
]);
?>