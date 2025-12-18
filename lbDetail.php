<?php
require "db_connect.php";

// Clean JSON output
ini_set('display_errors', 0);
header('Content-Type: application/json');

/* ===============================
   INPUTS
   =============================== */
$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type  = $_GET['type']  ?? 'user';     // user | team
$scope = $_GET['scope'] ?? 'all';      // all | weekly | monthly
$month = isset($_GET['month']) ? (int)$_GET['month'] : null;
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : null;

if ($id <= 0) {
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

/* ===============================
   DATE FILTER
   =============================== */
$dateCondition = "";

// -------------------------------
// WEEKLY
// -------------------------------
if ($scope === 'weekly') {

    // Monday → Sunday (current week)
    $dateCondition = "
        AND pt.generate_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
        AND pt.generate_at <  DATE_ADD(
            DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY),
            INTERVAL 7 DAY
        )
    ";


// -------------------------------
// MONTHLY (FIXED)
// -------------------------------
} elseif ($scope === 'monthly') {

    // ✅ FIX: Default to CURRENT month/year if missing
    if (!$month || !$year) {
        $month = (int)date('m');
        $year  = (int)date('Y');
    }

    // Strict month range
    $startDate = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $endDate   = date(
        'Y-m-d 23:59:59',
        strtotime($startDate . ' +1 month -1 second')
    );

    $dateCondition = "
        AND pt.generate_at >= '$startDate'
        AND pt.generate_at <= '$endDate'
    ";
}

/* ===============================
   QUERY
   =============================== */
if ($type === 'user') {

    $sql = "
        SELECT
            pt.pointsTransaction,
            pt.generate_at,
            COALESCE(c.challengeTitle, 'General Activity') AS source_name
        FROM pointtransaction pt
        LEFT JOIN sub s ON pt.submissionID = s.submissionID
        LEFT JOIN challenge c ON s.challengeID = c.challengeID
        WHERE pt.userID = ?
          AND pt.transactionType = 'earn'
          $dateCondition
        ORDER BY pt.generate_at ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);

} else {

    // TEAM
    $sql = "
        SELECT
            pt.pointsTransaction,
            pt.generate_at,
            CONCAT(u.firstName, ' ', u.lastName) AS user_name,
            COALESCE(c.challengeTitle, 'General Activity') AS challenge_name
        FROM pointtransaction pt
        JOIN user u ON pt.userID = u.userID
        LEFT JOIN sub s ON pt.submissionID = s.submissionID
        LEFT JOIN challenge c ON s.challengeID = c.challengeID
        WHERE u.teamID = ?
          AND pt.transactionType = 'earn'
          $dateCondition
        ORDER BY pt.generate_at ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
}

/* ===============================
   EXECUTE
   =============================== */
if (!$stmt || !$stmt->execute()) {
    echo json_encode(['error' => 'Database error']);
    exit;
}

$result = $stmt->get_result();

/* ===============================
   BUILD CHART + BREAKDOWN
   =============================== */
$labels = [];
$data = [];
$breakdown = [];
$cumulative = 0;

while ($row = $result->fetch_assoc()) {

    $cumulative += (int)$row['pointsTransaction'];

    // Chart
    $labels[] = date('M d', strtotime($row['generate_at']));
    $data[]   = $cumulative;

    // Breakdown
    $description = ($type === 'team')
        ? $row['user_name'] . ' - ' . $row['challenge_name']
        : $row['source_name'];

    array_unshift($breakdown, [
        'points'       => (int)$row['pointsTransaction'],
        'date'         => date('Y-m-d H:i', strtotime($row['generate_at'])),
        'description' => $description
    ]);
}

// Only show last 10 records
$breakdown = array_slice($breakdown, 0, 10);

/* ===============================
   OUTPUT
   =============================== */
echo json_encode([
    'labels'    => $labels,
    'data'      => $data,
    'breakdown' => $breakdown
]);
