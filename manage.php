<?php
// --------------------------------------------------------
// PAGE CONFIG
// --------------------------------------------------------
$pageTitle = "Manage Challenges";

// --------------------------------------------------------
// DB + AUTH (auth.php already handles session_start)
// --------------------------------------------------------
require 'db_connect.php';
require 'includes/auth.php';   // Now uses the NEW fixed auth (no browser token)

// --------------------------------------------------------
// ADMIN ONLY CHECK
// --------------------------------------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash'] = "Access denied. Administrators only.";
    header("Location: view.php");
    exit;
}

// --------------------------------------------------------
// AUTO-DEACTIVATE EXPIRED CHALLENGES
// --------------------------------------------------------
$conn->query("
    UPDATE challenge
    SET is_active = 0
    WHERE end_date < CURDATE() AND is_active = 1
");

// --------------------------------------------------------
// FLASH MESSAGE
// --------------------------------------------------------
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// --------------------------------------------------------
// FETCH CHALLENGES
// --------------------------------------------------------
$sql = "
    SELECT 
        c.*,
        cat.categoryName,
        CONCAT(u.firstName, ' ', u.lastName) AS creatorName
    FROM challenge c
    LEFT JOIN category cat ON c.categoryID = cat.categoryID
    LEFT JOIN user u ON c.created_by = u.userID
    ORDER BY c.start_date DESC, c.challengeID DESC
";

$result = $conn->query($sql);

$activeChallenges = [];
$expiredChallenges = [];
$today = date("Y-m-d");

// Separate active / expired
while ($row = $result->fetch_assoc()) {

    $isExpired = (!empty($row['end_date']) && $row['end_date'] < $today);
    $isHidden  = ($row['is_active'] == 0);

    if ($isExpired || $isHidden) {
        $expiredChallenges[] = $row;
    } else {
        $activeChallenges[] = $row;
    }
}

// --------------------------------------------------------
// LAYOUT START
// --------------------------------------------------------
include "includes/layout_start.php";
?>

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="text-primary">Manage Challenges</h1>

        <a href="challenge_create_form.php" class="btn btn-success">
            <i class="fas fa-plus-circle"></i> Create Challenge
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- ================= ACTIVE CHALLENGES ================= -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-running"></i> Active & Upcoming Challenges
        </div>

        <div class="table-responsive p-2">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>City</th>
                        <th>Points</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!empty($activeChallenges)): ?>
                    <?php foreach ($activeChallenges as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['challengeTitle']) ?></td>
                            <td><?= htmlspecialchars($c['categoryName'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($c['city']) ?></td>
                            <td><?= $c['pointAward'] ?></td>
                            <td><?= $c['start_date'] ?: '-' ?></td>
                            <td><?= $c['end_date'] ?: 'Ongoing' ?></td>
                            <td><span class="badge bg-success">Visible</span></td>
                            <td><?= htmlspecialchars($c['creatorName'] ?? 'N/A') ?></td>

                            <td>
                                <a href="challenge_edit.php?id=<?= $c['challengeID'] ?>" 
                                   class="btn btn-sm btn-primary">Edit</a>

                                <a href="challenge_end.php?id=<?= $c['challengeID'] ?>"
                                   onclick="return confirm('End this challenge now?');"
                                   class="btn btn-sm btn-warning ms-1">
                                   End
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center py-3">No active challenges.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================= EXPIRED CHALLENGES ================= -->
    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white">
            <i class="fas fa-history"></i> Ended / Expired Challenges
        </div>

        <div class="table-responsive p-2">
            <table class="table table-bordered table-striped bg-light">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>City</th>
                        <th>Points</th>
                        <th>Start</th>
                        <th>Ended</th>
                        <th>Created By</th>
                        <th>Edit</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!empty($expiredChallenges)): ?>
                    <?php foreach ($expiredChallenges as $c): ?>
                        <tr>
                            <td class="text-muted"><?= htmlspecialchars($c['challengeTitle']) ?></td>
                            <td><?= htmlspecialchars($c['categoryName'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($c['city']) ?></td>
                            <td><?= $c['pointAward'] ?></td>
                            <td><?= $c['start_date'] ?></td>
                            <td class="text-danger fw-bold"><?= $c['end_date'] ?></td>
                            <td><?= htmlspecialchars($c['creatorName'] ?? 'N/A') ?></td>

                            <td>
                                <a href="challenge_edit.php?id=<?= $c['challengeID'] ?>"
                                   class="btn btn-sm btn-secondary">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center py-3">No expired challenges.</td></tr>
                <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

</div>

<?php include "includes/layout_end.php"; ?>
