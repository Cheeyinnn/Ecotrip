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
// VERIFY USER IS IN TEAM & FETCH TEAM DATA
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
// ⭐ FUNCTIONAL FIX: CALCULATE TOTAL TEAM POINTS
// 1. Calculate the actual current total from all member scores.
// --------------------
$stmtPoints = $conn->prepare("
    SELECT COALESCE(SUM(scorePoint), 0) AS calculatedTotal
    FROM user
    WHERE teamID = ?
");
$stmtPoints->bind_param("i", $teamID);
$stmtPoints->execute();
$resPoints = $stmtPoints->get_result();
$totalTeamPoints = (int)$resPoints->fetch_assoc()['calculatedTotal'];
$stmtPoints->close();

// --------------------
// ⭐ DB WRITE: Store the calculated total back into team.teamPoint
// This ensures the database field has the correct, current value.
// --------------------
$stmtUpdate = $conn->prepare("
    UPDATE team
    SET teamPoint = ?
    WHERE teamID = ?
");
$stmtUpdate->bind_param("ii", $totalTeamPoints, $teamID);
$stmtUpdate->execute();
$stmtUpdate->close();


// --------------------
// FETCH TEAM MEMBERS (Includes scorePoint for display and sorting)
// --------------------
$members = [];
$resMembers = $conn->query("
    SELECT userID, firstName, lastName, avatarURL, last_online, COALESCE(scorePoint, 0) as scorePoint
    FROM user
    WHERE teamID = $teamID
    ORDER BY COALESCE(scorePoint, 0) DESC, userID = {$data['teamLeaderID']} DESC, firstName
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

    <div class="row g-4 mb-4">

        <div class="col-md-3 col-sm-6">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-muted">Total Members</h6>
                    <h2><?= count($members) ?></h2>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-sm-6">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-muted">Your Role</h6>
                    <h2><?= $isOwner ? 'Owner' : 'Member' ?></h2>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6">
            <div class="card text-center shadow-sm h-100 bg-success text-white">
                <div class="card-body">
                    <h6 class="text-white">Total Team Score</h6>
                    <h2><?= number_format($totalTeamPoints) ?> <small>pts</small></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-muted">Team ID</h6>
                    <h2>#<?= $teamID ?></h2>
                </div>
            </div>
        </div>

    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <strong>Team Members</strong>
        </div>

        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between align-items-center bg-light small text-muted fw-bold py-2">
                <div style="flex-basis: 50%;">MEMBER NAME</div>
                <div style="flex-basis: 30%; text-align: center;">LAST ACTIVE</div>
                <div style="flex-basis: 20%; text-align: right;">SCORE</div>
            </li>
            
            <?php 
            $timeThreshold = strtotime('-5 minutes'); // 5 minutes for online check
            foreach ($members as $m): ?>
                <?php
                    $isLeader = ((int)$m['userID'] === (int)$data['teamLeaderID']);
                    $isCurrentUser = ((int)$m['userID'] === (int)$userID);
                    
                    // Determine online status
                    $online = ($m['last_online'] && strtotime($m['last_online']) > $timeThreshold);
                ?>
                <li class="list-group-item d-flex justify-content-between align-items-center <?= $isCurrentUser ? 'bg-light-info' : '' ?>">
                    <div style="flex-basis: 50%;">
                        <div class="d-flex align-items-center">
                            <img src="<?= htmlspecialchars($m['avatarURL'] ?? 'uploads/avatar/default.png') ?>" 
                                 style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" class="me-2">
                            <div>
                                <strong>
                                    <?= htmlspecialchars($m['firstName'] . " " . $m['lastName']) ?>
                                </strong>
        
                                <?php if ($isLeader): ?>
                                    <span class="badge bg-primary ms-1">Leader</span>
                                <?php endif; ?>
        
                                <?php if ($isCurrentUser): ?>
                                    <span class="badge bg-info ms-1">You</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
        
                    <div class="text-center small text-muted" style="flex-basis: 30%;">
                         <span class="badge <?= $online ? 'bg-success' : 'bg-secondary' ?> me-2">
                            <?= $online ? 'Online' : 'Offline' ?>
                        </span>
                        
                        <?= $m['last_online']
                            ? date('M d, Y', strtotime($m['last_online']))
                            : 'N/A'
                        ?>
                    </div>
                    
                    <div class="text-end fw-bold text-success" style="flex-basis: 20%;">
                        <?= number_format($m['scorePoint']) ?> pts
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

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