<?php
require "db_connect.php";
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
    // Assuming 'description' column exists, otherwise we use 'transactionType'
    // If you have a challengeID, you might need to JOIN the challenges table here
    $stmt = $conn->prepare("SELECT pointsTransaction, generate_at, transactionType, 'User Activity' as source_name 
                           FROM pointtransaction 
                           WHERE userID = ? AND transactionType = 'earn' 
                           ORDER BY generate_at ASC");
    $stmt->bind_param("i", $id);
} else {
    // Get transaction history for the whole team
    $stmt = $conn->prepare("SELECT pt.pointsTransaction, pt.generate_at, pt.transactionType, u.firstName as source_name 
                           FROM pointtransaction pt 
                           JOIN user u ON pt.userID = u.userID 
                           WHERE u.teamID = ? AND pt.transactionType = 'earn' 
                           ORDER BY pt.generate_at ASC");
    $stmt->bind_param("i", $id);
}

$stmt->execute();
$result = $stmt->get_result();

$cumulativePoints = 0;
$graphLabels = [];
$graphData = [];

while ($row = $result->fetch_assoc()) {
    // Process Graph Data (Cumulative or per day)
    $date = date('M d', strtotime($row['generate_at']));
    $cumulativePoints += $row['pointsTransaction'];
    
    // Simple logic: Add entry for graph
    $graphLabels[] = $date;
    $graphData[] = $cumulativePoints; // Shows growth over time
    
    // Process Breakdown List (Latest first for display, so we unshift)
    array_unshift($breakdown, [
        'points' => $row['pointsTransaction'],
        'date' => date('Y-m-d H:i', strtotime($row['generate_at'])),
        'description' => $row['transactionType'], // Replace with column 'description' if available
        'source' => $row['source_name']
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