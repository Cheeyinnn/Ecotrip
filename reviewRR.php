<?php   
include 'db.php';
session_start();

// Check if admin
// if (!isset($_SESSION['userID']) || $_SESSION['role'] != 'admin') {
//     die("Access denied. You must be an admin to access this page.");
// Fetch all redemption requests
$sql = "SELECT r.redemptionID, u.firstName, u.lastName, rw.rewardID, rw.rewardName, r.requested_at, r.quantity, r.pointSpent, r.status, r.fulfilled_at
        FROM redemptionrequest r
        JOIN user u ON r.userID = u.userID
        LEFT JOIN reward rw ON r.rewardID = rw.rewardID
        ORDER BY r.redemptionID ASC";
$requests = $conn->query($sql);

// Handle Update Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['updateRequest'])) {

    $redemptionID = $_POST['redemptionID'];
    $newStatus = $_POST['status'];
    $currentTime = date('Y-m-d H:i:s');

    // Get current status + points + actual userID of request owner
    $sql = "SELECT status, pointSpent, userID 
            FROM redemptionrequest 
            WHERE redemptionID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $redemptionID);
    $stmt->execute();
    $stmt->bind_result($currentStatus, $pointsSpent, $memberID);
    $stmt->fetch();
    $stmt->close();

    /* ------------------------------------------------------------
        STATUS CHANGE LOGIC
       ------------------------------------------------------------ */

    // Case 1: pending → approved
    if ($currentStatus == 'pending' && $newStatus == 'approved') {
        echo "<script>alert('Email sent to member.');</script>";
    }

    // Case 2: pending → denied → refund
    if ($currentStatus == 'pending' && $newStatus == 'denied') {
        if ($pointsSpent > 0) {
            $sql = "INSERT INTO pointtransaction (userID, transactionType, pointsTransaction)
                    VALUES (?, 'return', ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $memberID, $pointsSpent);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Case 3: approved → denied → refund
    if ($currentStatus == 'approved' && $newStatus == 'denied') {
        if ($pointsSpent > 0) {
            $sql = "INSERT INTO pointtransaction (userID, transactionType, pointsTransaction)
                    VALUES (?, 'return', ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $memberID, $pointsSpent);
            $stmt->execute();
            $stmt->close();
            echo "<script>alert('Points returned to the user.');</script>";
        }
    }

    // Case 4: denied → approved → burn points again
    if ($currentStatus == 'denied' && $newStatus == 'approved') {
        if ($pointsSpent > 0) {
            $sql = "INSERT INTO pointtransaction (userID, transactionType, pointsTransaction)
                    VALUES (?, 'burn', ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $memberID, $pointsSpent);
            $stmt->execute();
            $stmt->close();
        }
    }

     // Update final status and set fulfilled_at timestamp when status changes
    if ($currentStatus != $newStatus) {
        $sql = "UPDATE redemptionrequest SET status = ?, fulfilled_at = ? WHERE redemptionID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $newStatus, $currentTime, $redemptionID);
        $stmt->execute();
        $stmt->close();
    } else {
        // If status didn't change, update only the fulfilled_at timestamp
        $sql = "UPDATE redemptionrequest SET fulfilled_at = ? WHERE redemptionID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $currentTime, $redemptionID);
        $stmt->execute();
        $stmt->close();
    }

    // Redirect safely
    echo "<script>window.location.href='reviewRR.php';</script>";
    exit();
}

// Handle Delete Reward Request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['deleteReward'])) {

    $rewardIDToDelete = $_POST['rewardID'];

    // Step 1: Update all redemption requests to set rewardID to NULL where rewardID matches the one to be deleted
    $updateSql = "UPDATE redemptionrequest SET rewardID = NULL WHERE rewardID = ?";
    $stmt = $conn->prepare($updateSql);
    $stmt->bind_param("i", $rewardIDToDelete);
    $stmt->execute();
    $stmt->close();

    // Step 2: Delete the reward from the reward table
    $deleteSql = "DELETE FROM reward WHERE rewardID = ?";
    $stmt = $conn->prepare($deleteSql);
    $stmt->bind_param("i", $rewardIDToDelete);
    $stmt->execute();
    $stmt->close();

    echo "<script>alert('Reward deleted, redemption requests remain.');</script>";
    echo "<script>window.location.href='reviewRR.php';</script>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Redemption Requests</title>
</head>
<body>
    <h1>Review Redemption Requests</h1>

    <table border="1">
        <tr>
            <th>Redemption ID</th>
            <th>User Name</th>
            <th>Reward ID</th>
            <th>Reward Name</th>
            <th>Requested at</th>
            <th>Quantity</th>
            <th>Points Spent</th>
            <th>Status</th>
            <th>Fulfilled at</th>
            <th>Action</th>
        </tr>

        <?php while ($row = $requests->fetch_assoc()): ?>
            <tr>
                <td><?php echo $row['redemptionID']; ?></td>
                <td><?php echo $row['firstName']. ' ' . $row['lastName']; ?></td>
                <td><?php echo $row['rewardID'] ? $row['rewardID'] : 'No Reward'; ?></td>
                <td><?php echo $row['rewardName']; ?></td>
                <td><?php echo $row['requested_at']; ?></td>
                <td><?php echo $row['quantity']; ?></td>
                <td><?php echo $row['pointSpent']; ?></td>
                <td><?php echo $row['status']; ?></td>
                <td><?php echo $row['fulfilled_at']; ?></td>

                <td>
                    <form action="reviewRR.php" method="POST">
                        <input type="hidden" name="redemptionID" value="<?php echo $row['redemptionID']; ?>">

                        <select name="status">
                            <option value="approved" <?php echo ($row['status'] == 'approved') ? 'selected' : ''; ?>>Approve</option>
                            <option value="denied" <?php echo ($row['status'] == 'denied') ? 'selected' : ''; ?>>Deny</option>
                        </select>

                        <button type="submit" name="updateRequest">Update</button>
                    </form>
                    <form action="reviewRR.php" method="POST">
                        <input type="hidden" name="rewardID" value="<?php echo $row['rewardID']; ?>">
                        <button type="submit" name="deleteReward">Delete Reward</button>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>

    </table>
</body>
</html>
