<?php
// ========================================================
// export_challenges.php  — Export ALL challenges to CSV
// ========================================================

// 1. Start Session & Output Buffering
session_start();
ob_start(); 

require 'db_connect.php';
require 'includes/auth.php';

// --------------------------------------------------------
// 🔐 ADMIN ONLY
// --------------------------------------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access denied. Administrators only.");
}

// ========================================================
// 🔴 FIX: CLEAN THE BUFFER
// ========================================================
// Removes any HTML/JS output from auth.php so the CSV is clean
if (ob_get_length()) {
    ob_end_clean();
}

// --------------------------------------------------------
// 2. Set download headers
// --------------------------------------------------------
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=challenges_list_' . date('Y-m-d') . '.csv');

// --------------------------------------------------------
// 3. Open output stream
// --------------------------------------------------------
$output = fopen('php://output', 'w');

// --------------------------------------------------------
// 4. Write CSV headers (Added Description)
// --------------------------------------------------------
fputcsv($output, [
    'ID',
    'Title',
    'Description',  // <--- NEW COLUMN
    'Category',
    'City',
    'Points',
    'Status',
    'Start Date',
    'End Date',
    'Created By'
]);

// --------------------------------------------------------
// 5. Fetch challenge data
// --------------------------------------------------------
$sql = "
    SELECT 
        c.challengeID,
        c.challengeTitle,
        c.description,  -- <--- FETCH DESCRIPTION
        cat.categoryName,
        c.city,
        c.pointAward,
        c.is_active,
        c.start_date,
        c.end_date,
        CONCAT(u.firstName, ' ', u.lastName) AS creatorName
    FROM challenge c
    LEFT JOIN category cat ON c.categoryID = cat.categoryID
    LEFT JOIN user u ON c.created_by = u.userID
    ORDER BY c.challengeID ASC
";

$result = $conn->query($sql);

// --------------------------------------------------------
// 6. Write data rows
// --------------------------------------------------------
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {

        $status = $row['is_active'] ? 'Active' : 'Inactive';

        fputcsv($output, [
            $row['challengeID'],
            $row['challengeTitle'],
            $row['description'], // <--- WRITE DESCRIPTION
            $row['categoryName'] ?? 'N/A',
            $row['city'] ?: 'Global',
            $row['pointAward'],
            $status,
            $row['start_date'] ?: '-',
            $row['end_date'] ?: 'Ongoing',
            $row['creatorName'] ?? 'N/A'
        ]);
    }
}

// --------------------------------------------------------
// 7. End output
// --------------------------------------------------------
fclose($output);
exit;
?>