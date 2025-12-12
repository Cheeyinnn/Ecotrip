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

// Prepare query based on User or Team
if ($type === 'user') {
    // Get transaction history for a single user
    // Link path: pointtransaction -> submissions -> challenges
    $query = "SELECT 
                pt.pointsTransaction, 
                pt.generate_at, 
                pt.transactionType, 
                COALESCE(c.title, 'General Activity') as source_name 
              FROM pointtransaction pt 
              LEFT JOIN submissions s ON pt.submissionID = s.id
              LEFT JOIN challenges c ON s.challenge_id = c.id
              WHERE pt.userID = ? AND pt.transactionType = 'earn' 
              ORDER BY pt.generate_at ASC";
              
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param("i", $id);
} else {
    // Get transaction history for the whole team
    $query = "SELECT 
                pt.pointsTransaction, 
                pt.generate_at, 
                pt.transactionType, 
                u.firstName as user_name,
                COALESCE(c.title, 'General Activity') as challenge_name
              FROM pointtransaction pt 
              JOIN user u ON pt.userID = u.userID 
              LEFT JOIN submissions s ON pt.submissionID = s.id
              LEFT JOIN challenges c ON s.challenge_id = c.id
              WHERE u.teamID = ? AND pt.transactionType = 'earn' 
              ORDER BY pt.generate_at ASC";
              
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param("i", $id);
}

if (!$stmt->execute()) {
    echo json_encode(['error' => 'Execute failed: ' . $stmt->error]);
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