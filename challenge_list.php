<?php
// -------------------------------------------
// ADMIN — CHALLENGE LIST PAGE
// -------------------------------------------
$pageTitle = "Admin Challenge Management";

session_start();
require "db_connect.php";
require "includes/auth.php"; // browser-token + login required

// -------------------------------------------
// ADMIN ONLY
// -------------------------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash'] = "Access denied. Only administrators may access this page.";
    header("Location: view.php");
    exit;
}

// -------------------------------------------
// SEARCH FILTER
// -------------------------------------------
$q = trim($_GET['q'] ?? "");

$flash = $_SESSION['flash'] ?? "";
unset($_SESSION['flash']);

$search_sql = "";
if ($q !== "") {
    $q_esc = $conn->real_escape_string($q);
    $search_sql = "
        AND (
            c.challengeTitle LIKE '%$q_esc%' OR
            c.description LIKE '%$q_esc%' OR
            cat.categoryName LIKE '%$q_esc%' OR
            c.city LIKE '%$q_esc%' OR
            CONCAT(u.firstName, ' ', u.lastName) LIKE '%$q_esc%'
        )
    ";
}

// -------------------------------------------
// FETCH ALL CHALLENGES WITH CATEGORY + CREATOR
// -------------------------------------------
$sql = "
    SELECT 
        c.*,
        cat.categoryName,
        CONCAT(u.firstName, ' ', u.lastName) AS creatorName
    FROM challenge c
    LEFT JOIN category cat ON c.categoryID = cat.categoryID
    LEFT JOIN user u ON c.created_by = u.userID
    WHERE 1 = 1
    $search_sql
    ORDER BY c.start_date DESC, c.challengeID DESC
";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= $pageTitle ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="text-primary m-0">Admin Challenge Management</h1>

        <a href="challenge_create_form.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> Create Challenge
        </a>
    </div>

    <!-- Search Form -->
    <form class="row g-2 mb-4" method="get">
        <div class="col-md-4">
            <input name="q" class="form-control" placeholder="Search challenges..."
                   value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary">Search</button>
        </div>
        <div class="col-auto">
            <a href="admin_challenge_list.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    <?php if ($flash): ?>
        <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Challenge Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            Existing Challenges
        </div>

        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>City</th>
                        <th>Points</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['challengeID'] ?></td>

                            <td><?= htmlspecialchars($row['challengeTitle']) ?></td>

                            <td><?= htmlspecialchars($row['description']) ?></td>

                            <td><?= htmlspecialchars($row['categoryName'] ?? 'N/A') ?></td>

                            <td><?= htmlspecialchars($row['city']) ?></td>

                            <td><?= $row['pointAward'] ?></td>

                            <td><?= $row['start_date'] ?: '-' ?></td>

                            <td><?= $row['end_date'] ?: '-' ?></td>

                            <td><?= htmlspecialchars($row['creatorName'] ?? "N/A") ?></td>

                            <td>
                                <a href="challenge_edit.php?id=<?= $row['challengeID'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                <a href="challenge_delete.php?id=<?= $row['challengeID'] ?>" 
                                   onclick="return confirm('Delete this challenge?');"
                                   class="btn btn-sm btn-danger">
                                   Delete
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="text-center py-3">No challenges found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>

<?php
if ($result) $result->free();
$conn->close();
?>