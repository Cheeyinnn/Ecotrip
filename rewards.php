<?php   
include 'db.php';

// Get userID from URL query string (e.g., rewards.php?userID=2)
if (isset($_GET['userID'])) {
    $userID = $_GET['userID'];
} else {
    die("No user ID provided.");
}

// Fetch the user's total points from the pointtransaction table, defaulting to 0 if no points found
$sql = "SELECT COALESCE(SUM(
            CASE 
                WHEN transactionType = 'earn' THEN pointsTransaction
                WHEN transactionType = 'return' THEN pointsTransaction
                WHEN transactionType = 'burn' THEN -pointsTransaction
                ELSE 0
            END
        ), 0) AS totalPoints
        FROM pointtransaction
        WHERE userID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userID);
$stmt->execute();
$stmt->bind_result($userPoints);
$stmt->fetch();
$stmt->close();

// Check if user has points or not, if no points, we set it to 0 (handled by COALESCE in SQL)
if ($userPoints === null) {
    $userPoints = 0;
}

// Fetch all active rewards from the rewards table
$sql_rewards = "SELECT * FROM reward";
$rewards_result = $conn->query($sql_rewards);

// Fetch the user's redemption requests and their statuses (allowing NULL rewardID for deleted rewards)
$sql_requests = "SELECT rr.redemptionID, rw.rewardName, rr.quantity, rr.pointSpent, rr.status
                 FROM redemptionrequest rr 
                 LEFT JOIN reward rw ON rr.rewardID = rw.rewardID
                 WHERE rr.userID = ?
                 ORDER BY rr.redemptionID ASC";
$stmt = $conn->prepare($sql_requests);
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();  // Use get_result() to get the result set
$stmt->close();

// Handle adding new rewards (submit redemption request)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['rewardID'])) {
    $rewardID = $_POST['rewardID'];
    $quantity = $_POST['quantity'];
    $pointsRequired = $_POST['pointRequired'] * $quantity;

    // Fetch the stockQuantity and availability for the requested reward
    $sql_stock = "SELECT stockQuantity, is_active FROM reward WHERE rewardID = ?";
    $stmt = $conn->prepare($sql_stock);
    $stmt->bind_param("i", $rewardID);
    $stmt->execute();
    $stmt->bind_result($stockQuantity, $isActive);
    $stmt->fetch();
    $stmt->close();

    // Check if the reward is active
    if ($isActive != 1) {
        echo "This reward is unavailable.";
    } else {
        // Check if the requested quantity exceeds the available stock
        if ($quantity > $stockQuantity) {
            echo "Sorry, there is not enough stock for this reward.";
        } else {
            // Check if the user has enough points
            if ($userPoints >= $pointsRequired) {
                // Insert the redemption request into the redemptionrequest table
                $sql = "INSERT INTO redemptionrequest (userID, rewardID, quantity, pointSpent, status) 
                        VALUES (?, ?, ?, ?, 'pending')";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiii", $userID, $rewardID, $quantity, $pointsRequired);
                $stmt->execute();
                $stmt->close();

                // Deduct points from the user's account (burn points)
                $newPoints = $userPoints - $pointsRequired;

                // Insert a new point transaction for the points spent
                $sql = "INSERT INTO pointtransaction (userID, transactionType, pointsTransaction) 
                        VALUES (?, 'burn', ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $userID, $pointsRequired);
                $stmt->execute();
                $stmt->close();

                // Update the stock quantity of the reward
                $newStockQuantity = $stockQuantity - $quantity;
                $sql_update_stock = "UPDATE reward SET stockQuantity = ? WHERE rewardID = ?";
                $stmt = $conn->prepare($sql_update_stock);
                $stmt->bind_param("ii", $newStockQuantity, $rewardID);
                $stmt->execute();
                $stmt->close();

                // Redirect after processing the form to avoid resubmission on page reload
                header("Location: rewards.php?userID=$userID");
                exit();  // Don't forget to call exit() after header to stop further script execution
            } else {
                echo "You do not have enough points for this reward.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rewards</title>
</head>
<body>
    <h1>Available Rewards</h1>
    <p>Your current points: <?php echo $userPoints; ?></p> <!-- Display points directly from totalPoints -->

    <!-- Display the user's redemption request list -->
    <h2>Your Redemption Requests</h2>
    <table border="1">
        <tr>
            <th>RedemptionID</th>
            <th>Reward Name</th>
            <th>Quantity</th>
            <th>Points Spent</th>
            <th>Status</th>
        </tr>
        <?php
        // Display the user's redemption requests
        while ($row = $result->fetch_assoc()) {
            $rewardName = $row['rewardName'] ? $row['rewardName'] : 'No Reward';
            echo "<tr>
                    <td>{$row['redemptionID']}</td>
                    <td>{$rewardName}</td>
                    <td>{$row['quantity']}</td>
                    <td>{$row['pointSpent']}</td>
                    <td>{$row['status']}</td>
                  </tr>";
        }
        ?>
    </table>

    <!-- Display available rewards to request -->
    <h2>Available Rewards</h2>
    <table border="1">
        <tr>
            <th>Reward Name</th>
            <th>Description</th>
            <th>Points Required</th>
            <th>Stock Quantity</th>
            <th>Request</th>
        </tr>
        <?php 
        if ($rewards_result->num_rows > 0) {
            while ($row = $rewards_result->fetch_assoc()) {
                // Check if the reward is active
                $isActive = $row['is_active'];
                
                // Display the reward details
                echo "<tr>
                        <td>" . $row['rewardName'] . "</td>
                        <td>" . $row['description'] . "</td>
                        <td>" . $row['pointRequired'] . "</td>
                        <td>" . $row['stockQuantity'] . "</td>";

                // If the reward is active, show the request form, else show "Unavailable"
                if ($isActive == 1) {
                    echo "<td>
                            <form action='rewards.php?userID=$userID' method='POST'>
                                <input type='hidden' name='rewardID' value='" . $row['rewardID'] . "'>
                                <input type='hidden' name='pointRequired' value='" . $row['pointRequired'] . "'>
                                Quantity: <input type='number' name='quantity' min='1' max='" . $row['stockQuantity'] . "' required>
                                <button type='submit'>Request Reward</button>
                            </form>
                          </td>";
                } else {
                    echo "<td><input type='text' value='Unavailable' disabled></td>";
                }
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='5'>No active rewards available</td></tr>";
        }
        ?>
    </table>
</body>
</html>
