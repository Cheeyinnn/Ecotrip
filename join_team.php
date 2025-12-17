<?php
// ==============================
// join_team.php
// ==============================
session_start();
require 'db_connect.php';
require 'includes/auth.php';
require 'includes/notify.php'; // ⭐ Enable notifications

// -----------------------------
// PAGE TITLE FOR TOPBAR
// -----------------------------
$pageTitle = "Join Team";

// Must be logged in
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$userID = (int)$_SESSION['userID'];

// Fetch user info (needed by layout_start and logic)
$stmt = $conn->prepare("
    SELECT firstName, lastName, role, avatarURL, teamID, pendingTeamID 
    FROM user 
    WHERE userID = ?
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$userRole       = $userData['role'];
$currentTeamID  = !empty($userData['teamID']) ? (int)$userData['teamID'] : null;
$pendingTeamID  = !empty($userData['pendingTeamID']) ? (int)$userData['pendingTeamID'] : null;

// ADMIN CANNOT JOIN TEAMS
if ($userRole === 'admin') {
    $_SESSION['flash_danger'] = "Admins cannot join any team.";
    header("Location: index.php");
    exit;
}

// Prepare `$user` for sidebar layout
$user = [
    'firstName' => $userData['firstName'],
    'lastName'  => $userData['lastName'],
    'role'      => $userData['role'],
    'avatarURL' => $userData['avatarURL'] ?? null,
];

// Avatar resolve for layout (topbar uses this if needed)
$avatarPath = "uploads/default.png";
if (!empty($userData['avatarURL']) && file_exists(__DIR__ . "/" . $userData['avatarURL'])) {
    $avatarPath = $userData['avatarURL'];
}

// JOIN STATE CHECK
$isAlreadyMember   = !is_null($currentTeamID);
$hasPendingRequest = !is_null($pendingTeamID);
$canJoin           = (!$isAlreadyMember && !$hasPendingRequest);

$message = "";
$messageType = "";

// -------------------------------------------
// INVITE CODE SUBMISSION → DIRECT JOIN
// -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($isAlreadyMember) {
        $message = "You already belong to a team.";
        $messageType = "info";

    } elseif ($hasPendingRequest) {
        $message = "You already have a pending request for Team ID: {$pendingTeamID}.";
        $messageType = "info";

    } else {
        $invite = trim($_POST['invite_code'] ?? '');

        if ($invite === "") {
            $message = "Invite code is required.";
            $messageType = "warning";

        } else {
            // Verify invite code
            $stmtTeam = $conn->prepare("SELECT teamID FROM team WHERE joinCode = ?");
            $stmtTeam->bind_param("s", $invite);
            $stmtTeam->execute();
            $team = $stmtTeam->get_result()->fetch_assoc();
            $stmtTeam->close();

            if (!$team) {
                $message = "Invalid invite code!";
                $messageType = "danger";

            } else {
                $joinTeamID = (int)$team['teamID'];

                // Capacity check (max 4 members)
                $stmtCount = $conn->prepare("SELECT COUNT(*) AS cnt FROM user WHERE teamID = ?");
                $stmtCount->bind_param("i", $joinTeamID);
                $stmtCount->execute();
                $count = $stmtCount->get_result()->fetch_assoc();
                $stmtCount->close();

                if ($count['cnt'] >= 4) {
                    $message = "This team is full (Max 4 members).";
                    $messageType = "warning";

                } else {
                    // DIRECT JOIN: set teamID, clear pendingTeamID
                    $stmtUpdate = $conn->prepare("
                        UPDATE user 
                        SET teamID = ?, pendingTeamID = NULL 
                        WHERE userID = ?
                    ");
                    $stmtUpdate->bind_param("ii", $joinTeamID, $userID);

                    if ($stmtUpdate->execute()) {

                        // ---------------------------------------
                        // FETCH TEAM LEADER DETAILS
                        // ---------------------------------------
                        $stmtLeader = $conn->prepare("
                            SELECT t.teamName, t.teamLeaderID, u.firstName, u.lastName
                            FROM team t
                            LEFT JOIN user u ON t.teamLeaderID = u.userID
                            WHERE t.teamID = ?
                        ");
                        $stmtLeader->bind_param("i", $joinTeamID);
                        $stmtLeader->execute();
                        $teamInfo = $stmtLeader->get_result()->fetch_assoc();
                        $stmtLeader->close();

                        $teamName = $teamInfo['teamName'];
                        $leaderID = (int)$teamInfo['teamLeaderID'];
                        $userFullName = $userData['firstName'] . " " . $userData['lastName'];

                        // ---------------------------------------
                        // ⭐ NOTIFY USER (joined)
                        // ---------------------------------------
                        sendNotification(
                            $conn,
                            $userID,
                            "You have joined the team '{$teamName}'.",
                            "team.php"
                        );

                        // ---------------------------------------
                        // ⭐ NOTIFY TEAM LEADER
                        // ---------------------------------------
                        if ($leaderID > 0) {
                            sendNotification(
                                $conn,
                                $leaderID,
                                "{$userFullName} has joined your team '{$teamName}'.",
                                "team.php"
                            );
                        }

                        // ---------------------------------------
// SUCCESS → redirect (PREVENT stale state)
// ---------------------------------------
$_SESSION['flash_success'] = "You have joined the team '{$teamName}' successfully!";
header("Location: team.php");
exit;


                    } else {
                        $message = "Error joining team.";
                        $messageType = "danger";
                    }
                    $stmtUpdate->close();
                }
            }
        }
    }
}

// -------------------------------------------
// LAYOUT START (handles sidebar + topbar)
// -------------------------------------------
include "includes/layout_start.php";
?>

<style>
.card-join {
    background:#fff;
    padding:40px;
    max-width:460px;
    margin:auto;
    border-radius:16px;
    box-shadow:0 10px 30px rgba(0,0,0,0.08);
}
</style>

<div class="p-4">

    <div class="card-join text-center">

        <?php if ($canJoin): ?>

            <iconify-icon icon="material-symbols:group-add-outline"
                          width="60" class="text-success mb-3"></iconify-icon>

            <h3 class="fw-bold mb-3">Join a Team</h3>
            <p class="text-muted mb-4">
                Enter the invite code to join the team directly.
            </p>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show shadow-sm">
                    <?= htmlspecialchars($message) ?>
                    <button class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($messageType !== 'success'): ?>
                <form method="POST">
                    <div class="form-floating mb-3">
                        <input type="text" name="invite_code" class="form-control text-center"
                               placeholder="Invite Code" required>
                        <label>Team Invite Code</label>
                    </div>

                    <button class="btn btn-success w-100 mb-2">
                        <iconify-icon icon="ic:round-send" width="20"></iconify-icon>
                        Join Team
                    </button>

                    <a href="index.php" class="btn btn-outline-secondary w-100">
                        Cancel / Back to Dashboard
                    </a>
                </form>
            <?php endif; ?>

        <?php else: ?>

            <iconify-icon icon="material-symbols:block-flipped-outline"
                          width="60" class="text-danger mb-3"></iconify-icon>

            <h3 class="fw-bold mb-3">Cannot Join via Code</h3>

            <div class="alert alert-info shadow-sm">
                <?php if ($isAlreadyMember): ?>
                    You are already a member of a team (Team ID: <?= htmlspecialchars($currentTeamID) ?>).
                <?php elseif ($hasPendingRequest): ?>
                    You already have a <strong>pending request</strong> for Team ID: <?= htmlspecialchars($pendingTeamID) ?>.
                <?php endif; ?>
            </div>

            <?php if ($isAlreadyMember): ?>
                <a href="team.php" class="btn btn-primary w-100 mb-2">
                    Go to My Team
                </a>
            <?php else: ?>
                <a href="team.php" class="btn btn-primary w-100 mb-2">
                    Open Team Page
                </a>
            <?php endif; ?>

            <a href="index.php" class="btn btn-outline-secondary w-100">
                Back to Dashboard
            </a>

        <?php endif; ?>

    </div>
</div>

<?php include "includes/layout_end.php"; ?>
