<?php
include 'db.php'; 

// Function to fetch all rewards from the database
function getRewards($conn) {
    $sql = "SELECT * FROM reward";
    return $conn->query($sql);
}

// Handle form submission for adding a new reward
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['addReward'])) {
    // Get form data
    $rewardName = $_POST['rewardName'];
    $description = $_POST['description'];
    $stockQuantity = $_POST['stockQuantity'];
    $pointRequired = $_POST['pointRequired'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Insert new reward into the database using prepared statements
    $sql = $conn->prepare("INSERT INTO reward (rewardName, description, stockQuantity, pointRequired, is_active) 
            VALUES (?, ?, ?, ?, ?)");
    $sql->bind_param("ssiii", $rewardName, $description, $stockQuantity, $pointRequired, $is_active);

    if ($sql->execute()) {
        echo "<p>New reward added successfully</p>";
    } else {
        echo "<p>Error: " . $sql->error . "</p>";
    }

    // Close the prepared statement
    $sql->close();
}

// Handle form submission for editing an existing reward
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['editReward'])) {
    // Get form data
    $rewardID = $_POST['rewardID'];
    $rewardName = $_POST['rewardName'];
    $description = $_POST['description'];
    $stockQuantity = $_POST['stockQuantity'];
    $pointRequired = $_POST['pointRequired'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Update the reward in the database using prepared statements
    $sql = $conn->prepare("UPDATE reward SET rewardName = ?, description = ?, stockQuantity = ?, pointRequired = ?, is_active = ? 
            WHERE rewardID = ?");
    $sql->bind_param("ssiiii", $rewardName, $description, $stockQuantity, $pointRequired, $is_active, $rewardID);

    if ($sql->execute()) {
        echo "<p>Reward updated successfully</p>";
    } else {
        echo "<p>Error: " . $sql->error . "</p>";
    }

    // Close the prepared statement
    $sql->close();
}

// Handle reward deletion
if (isset($_GET['deleteRewardID'])) {
    $deleteRewardID = $_GET['deleteRewardID'];
    
    // SQL query to delete the reward
    $sql = $conn->prepare("DELETE FROM reward WHERE rewardID = ?");
    $sql->bind_param("i", $deleteRewardID);

    if ($sql->execute()) {
        echo "<p>Reward deleted successfully</p>";
    } else {
        echo "<p>Error: " . $sql->error . "</p>";
    }

    // Close the prepared statement
    $sql->close();
}

// Fetch all rewards for display
$rewards = getRewards($conn);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rewards</title>
</head>
<body>

<h2>Manage Rewards</h2>

<!-- Form to add a new reward -->
<h3>Add New Reward</h3>
<form action="" method="POST">
    <label for="rewardName">Reward Name:</label><br>
    <input type="text" id="rewardName" name="rewardName" required><br><br>

    <label for="description">Description:</label><br>
    <input type="text" id="description" name="description" required><br><br>

    <label for="stockQuantity">Stock Quantity:</label><br>
    <input type="number" id="stockQuantity" name="stockQuantity" required><br><br>

    <label for="pointRequired">Points Required:</label><br>
    <input type="number" id="pointRequired" name="pointRequired" required><br><br>

    <label for="is_active">Is Active:</label><br>
    <input type="checkbox" id="is_active" name="is_active" value="1" checked><br><br>

    <input type="submit" name="addReward" value="Add Reward">
</form>

<h3>All Rewards</h3>
<table border="1">
    <thead>
        <tr>
            <th>Reward ID</th>
            <th>Reward Name</th>
            <th>Description</th>
            <th>Stock Quantity</th>
            <th>Points Required</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = $rewards->fetch_assoc()) { ?>
            <tr>
                <td><?php echo $row['rewardID']; ?></td>
                <td><?php echo $row['rewardName']; ?></td>
                <td><?php echo $row['description']; ?></td>
                <td><?php echo $row['stockQuantity']; ?></td>
                <td><?php echo $row['pointRequired']; ?></td>
                <td><?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?></td>
                <td>
                    <!-- Edit Form -->
                    <form action="" method="POST" style="display:inline;">
                        <input type="hidden" name="rewardID" value="<?php echo $row['rewardID']; ?>">
                        <input type="text" name="rewardName" value="<?php echo $row['rewardName']; ?>" required><br><br>
                        <input type="text" name="description" value="<?php echo $row['description']; ?>" required><br><br>
                        <input type="number" name="stockQuantity" value="<?php echo $row['stockQuantity']; ?>" required><br><br>
                        <input type="number" name="pointRequired" value="<?php echo $row['pointRequired']; ?>" required><br><br>
                        <label for="is_active">Is Active:</label>
                        <input type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>><br><br>
                        <input type="submit" name="editReward" value="Edit Reward">
                    </form>

                    <!-- Delete Button -->
                    <a href="?deleteRewardID=<?php echo $row['rewardID']; ?>" onclick="return confirm('Are you sure you want to delete this reward?')">Delete</a>
                </td>
            </tr>
        <?php } ?>
    </tbody>
</table>

</body>
</html>
