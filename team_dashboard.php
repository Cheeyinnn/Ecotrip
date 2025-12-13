<?php
// ===================================================
// TEAM DASHBOARD
// ===================================================
session_start();
require 'db_connect.php';
require 'includes/auth.php';

// --------------------
// AUTH CHECK
// --------------------
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$userID = (int)$_SESSION['userID'];
$teamID = (int)($_GET['teamID'] ?? 0);

if ($teamID <= 0) {
    die("Invalid team.");
}

// --------------------
// VERIFY USER IS IN TEAM
// --------------------
$stmt = $conn->prepare("
    SELECT u.firstName, u.lastName, u.role, u.avatarURL, u.teamID,
           t.teamName, t.teamDesc, t.teamLeaderID, t.teamImage, t.created_at
    FROM user u
    JOIN team t ON t.teamID = u.teamID
    WHERE u.userID = ? AND u.teamID = ?
");
$stmt->bind_param("ii", $userID, $teamID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Access denied.");
}

$data = $result->fetch_assoc();
$stmt->close();

// --------------------
// BASIC VARIABLES
// --------------------
$isOwner   = ((int)$data['teamLeaderID'] === $userID);
$teamName  = $data['teamName'];
$teamDesc  = $data['teamDesc'];
$teamImage = $data['teamImage'] ?: 'uploads/team/default_team.png';

// --------------------
// FETCH TEAM MEMBERS
// --------------------
$members = [];
$resMembers = $conn->query("
    SELECT userID, firstName, lastName, avatarURL, last_online
    FROM user
    WHERE teamID = $teamID
    ORDER BY userID = {$data['teamLeaderID']} DESC, firstName
");
while ($row = $resMembers->fetch_assoc()) {
    $members[] = $row;
}
$resMembers->free();

// --------------------
// PROVIDE USER + PAGE TITLE FOR LAYOUT
// --------------------
$user = [
    'firstName' => $data['firstName'],
    'lastName'  => $data['lastName'],
    'role'      => $data['role'],
    'avatarURL' => $data['avatarURL'] ?? null,
];

$pageTitle = "Team Dashboard";

// --------------------
// LAYOUT START
// --------------------
include "includes/layout_start.php";
?>

<div class="container-fluid p-4">

    <!-- ================= TEAM HEADER ================= -->
    <div class="card shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <h3 class="mb-1"><?= htmlspecialchars($teamName) ?></h3>
                <p class="text-muted mb-0">
                    <?= htmlspecialchars($teamDesc ?: 'No description provided.') ?>
                </p>
                <small class="text-muted">
                    Created on <?= date('M d, Y', strtotime($data['created_at'])) ?>
                </small>
            </div>

            <img src="<?= htmlspecialchars($teamImage) ?>"
                 style="width:90px;height:90px;object-fit:cover;border-radius:10px;">
        </div>
    </div>

    <!-- ================= QUICK STATS ================= -->
    <div class="row g-4 mb-4">

        <div class="col-md-4">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Total Members</h6>
                    <h2><?= count($members) ?></h2>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Your Role</h6>
                    <h2><?= $isOwner ? 'Owner' : 'Member' ?></h2>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Team ID</h6>
                    <h2>#<?= $teamID ?></h2>
                </div>
            </div>
        </div>

    </div>

    <!-- ================= MEMBERS LIST ================= -->
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <strong>Team Members</strong>
        </div>

        <ul class="list-group list-group-flush">
            <?php foreach ($members as $m): ?>
                <?php
                    $online = ($m['last_online'] && strtotime($m['last_online']) > strtotime('-5 minutes'));
                ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>
                            <?= htmlspecialchars($m['firstName'] . " " . $m['lastName']) ?>
                        </strong>

                        <?php if ($m['userID'] == $data['teamLeaderID']): ?>
                            <span class="badge bg-dark ms-2">Owner</span>
                        <?php endif; ?>

                        <?php if ($m['userID'] == $userID): ?>
                            <span class="badge bg-info ms-1">You</span>
                        <?php endif; ?>

                        <div class="small text-muted">
                            Last online:
                            <?= $m['last_online']
                                ? date('M d, Y h:i A', strtotime($m['last_online']))
                                : 'Never'
                            ?>
                        </div>
                    </div>

                    <span class="badge <?= $online ? 'bg-success' : 'bg-secondary' ?>">
                        <?= $online ? 'Online' : 'Offline' ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- ================= ACTIONS ================= -->
    <div class="mt-4">
        <a href="team.php" class="btn btn-outline-secondary">
            ← Back to Team
        </a>

        <?php if ($isOwner): ?>
            <span class="text-muted ms-3">
                You are the team owner.
            </span>
        <?php endif; ?>
    </div>

</div>

<?php include "includes/layout_end.php"; ?>
