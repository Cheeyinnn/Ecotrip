<?php
// ==============================
// team.php  (My Team page)
// ==============================
session_start();
require 'db_connect.php';
require 'includes/auth.php';   // browser-token protection
require 'includes/notify.php'; // ⭐ Notification system

if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$userID = (int)$_SESSION['userID'];
$message = "";
$messageType = "";

// ======================================================================
// ⭐ UNIVERSAL AVATAR RESOLVER (for member avatars, NOT topbar)
// ======================================================================
function getAvatar($path)
{
    $default = "uploads/default.png";
    if (empty($path)) return $default;

    if (file_exists(__DIR__ . "/" . $path)) {
        return $path;
    }

    $filename = basename($path);
    $candidates = [
        "uploads/" . $filename,
        "upload/"  . $filename,
        $filename
    ];

    foreach ($candidates as $p) {
        if (file_exists(__DIR__ . "/" . $p)) {
            return $p;
        }
    }

    return $default;
}

// ======================================================================
// ⭐ Helper: notify all members in a team
// ======================================================================
function notifyTeamMembers($conn, $teamID, $message, $link = 'team.php')
{
    $stmt = $conn->prepare("SELECT userID FROM user WHERE teamID = ?");
    $stmt->bind_param("i", $teamID);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        sendNotification(
            $conn,
            (int)$row['userID'],
            $message,
            $link
        );
    }
    $stmt->close();
}

// ====================== LOAD USER ROLE + TEAM STATE ======================
$stmtRole = $conn->prepare("
    SELECT userID, firstName, lastName, role, avatarURL, teamID, pendingTeamID
    FROM user
    WHERE userID = ?
");
$stmtRole->bind_param("i", $userID);
$stmtRole->execute();
$userData = $stmtRole->get_result()->fetch_assoc();
$stmtRole->close();

$userRole = $userData['role'];

// Interpret team state
$teamID        = !empty($userData['teamID']) ? (int)$userData['teamID'] : null;
$pendingTeamID = !empty($userData['pendingTeamID']) ? (int)$userData['pendingTeamID'] : null;

// Redirect admins away from team page
if ($userRole === 'admin') {
    header("Location: index.php");
    exit;
}

// ====================== FLASH MESSAGE (e.g. from create_team.php) ======================
if (isset($_SESSION['flash_success']) && $message === "") {
    $message     = $_SESSION['flash_success'];
    $messageType = "success";
    unset($_SESSION['flash_success']);
}

// ====================== HANDLE JOIN REQUEST (BROWSE TEAM CARD) ======================
// This is DIFFERENT from invite code. Here user browses teams and clicks "Request to Join".
// It uses pendingTeamID (no negative values).
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === "request_join"
) {
    $targetTeamID = intval($_POST['teamID']);

    if ($teamID) {
        $message     = "You already belong to a team.";
        $messageType = "info";
    } elseif ($pendingTeamID) {
        $message     = "You already have a pending join request.";
        $messageType = "info";
    } else {
        // Capacity check (max 4 members)
        $stmtCount = $conn->prepare("SELECT COUNT(*) AS cnt FROM user WHERE teamID = ?");
        $stmtCount->bind_param("i", $targetTeamID);
        $stmtCount->execute();
        $rowCount = $stmtCount->get_result()->fetch_assoc();
        $stmtCount->close();

        if ($rowCount['cnt'] >= 4) {
            $message     = "This team is full (Max 4 members).";
            $messageType = "warning";
        } else {
            $stmt = $conn->prepare("UPDATE user SET pendingTeamID = ? WHERE userID = ?");
            $stmt->bind_param("ii", $targetTeamID, $userID);

            if ($stmt->execute()) {
                $message       = "Join request sent! Please wait for the team leader to approve.";
                $messageType   = "success";
                $pendingTeamID = $targetTeamID;

                // ⭐ Notify TEAM LEADER + USER about join request
                $stmtInfo = $conn->prepare("
                    SELECT t.teamName, t.teamLeaderID, u.firstName, u.lastName
                    FROM team t
                    JOIN user u ON u.userID = t.teamLeaderID
                    WHERE t.teamID = ?
                ");
                $stmtInfo->bind_param("i", $targetTeamID);
                $stmtInfo->execute();
                $rowInfo = $stmtInfo->get_result()->fetch_assoc();
                $stmtInfo->close();

                if ($rowInfo) {
                    $teamName   = $rowInfo['teamName'];
                    $leaderID   = (int)$rowInfo['teamLeaderID'];
                    $userFull   = $userData['firstName'] . " " . $userData['lastName'];

                    // Leader: new request
                    if ($leaderID > 0) {
                        sendNotification(
                            $conn,
                            $leaderID,
                            "{$userFull} has requested to join your team '{$teamName}'.",
                            "team.php"
                        );
                    }

                    // User: record of request
                    sendNotification(
                        $conn,
                        $userID,
                        "You have requested to join team '{$teamName}'. Please wait for approval.",
                        "team.php"
                    );
                }

            } else {
                $message     = "Failed to send join request.";
                $messageType = "danger";
            }
            $stmt->close();
        }
    }
}

// ====================== OWNER: APPROVE / REJECT REQUESTS ======================

// APPROVE REQUEST
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === "approve_request"
) {
    $targetID       = intval($_POST['userID']);
    $teamIDFromForm = intval($_POST['teamID']);

    // Only proceed if current user is leader of this team
    $stmtTeam = $conn->prepare("SELECT teamLeaderID, teamName FROM team WHERE teamID = ?");
    $stmtTeam->bind_param("i", $teamIDFromForm);
    $stmtTeam->execute();
    $teamRow = $stmtTeam->get_result()->fetch_assoc();
    $stmtTeam->close();

    if ($teamRow && (int)$teamRow['teamLeaderID'] === $userID) {
        $teamName = $teamRow['teamName'];

        // Get target user's name for notification
        $stmtUserInfo = $conn->prepare("SELECT firstName, lastName FROM user WHERE userID = ?");
        $stmtUserInfo->bind_param("i", $targetID);
        $stmtUserInfo->execute();
        $userRow = $stmtUserInfo->get_result()->fetch_assoc();
        $stmtUserInfo->close();

        $targetName = $userRow ? ($userRow['firstName'] . " " . $userRow['lastName']) : "A user";

        $stmt = $conn->prepare("
            UPDATE user 
            SET teamID = ?, pendingTeamID = NULL 
            WHERE userID = ? AND pendingTeamID = ?
        ");
        $stmt->bind_param("iii", $teamIDFromForm, $targetID, $teamIDFromForm);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message     = "Join request approved.";
            $messageType = "success";

            // ⭐ Notify MEMBER: approved
            sendNotification(
                $conn,
                $targetID,
                "Your request to join team '{$teamName}' has been approved.",
                "team.php"
            );

        } else {
            $message     = "Unable to approve (might be already processed).";
            $messageType = "info";
        }
        $stmt->close();
    } else {
        $message     = "You are not the leader of this team.";
        $messageType = "danger";
    }
}

// REJECT REQUEST
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === "reject_request"
) {
    $targetID       = intval($_POST['userID']);
    $teamIDFromForm = intval($_POST['teamID']);

    // Verify current user is leader of this team
    $stmtTeam = $conn->prepare("SELECT teamLeaderID, teamName FROM team WHERE teamID = ?");
    $stmtTeam->bind_param("i", $teamIDFromForm);
    $stmtTeam->execute();
    $teamRow = $stmtTeam->get_result()->fetch_assoc();
    $stmtTeam->close();

    if ($teamRow && (int)$teamRow['teamLeaderID'] === $userID) {

        $teamName = $teamRow['teamName'];

        $stmt = $conn->prepare("
            UPDATE user 
            SET pendingTeamID = NULL 
            WHERE userID = ? AND pendingTeamID = ?
        ");
        $stmt->bind_param("ii", $targetID, $teamIDFromForm);

        if ($stmt->execute()) {
            $message     = "Join request rejected.";
            $messageType = "info";

            // ⭐ Notify MEMBER: rejected
            sendNotification(
                $conn,
                $targetID,
                "Your request to join team '{$teamName}' has been rejected.",
                "team.php"
            );

        } else {
            $message     = "Unable to reject request.";
            $messageType = "danger";
        }
        $stmt->close();
    } else {
        $message     = "You are not the leader of this team.";
        $messageType = "danger";
    }
}

// ====================== HANDLE TEAM ACTIONS (ONLY IF IN A TEAM) ======================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    ($teamID)        // must already be in a team
) {
    // Load team data to get owner ID & current team image + name
    $stmtTeam = $conn->prepare("SELECT teamLeaderID, teamImage, teamName FROM team WHERE teamID = ?");
    $stmtTeam->bind_param("i", $teamID);
    $stmtTeam->execute();
    $teamData = $stmtTeam->get_result()->fetch_assoc();
    $stmtTeam->close();

    if ($teamData) {
        $ownerID          = (int)$teamData['teamLeaderID'];
        $currentTeamImage = $teamData['teamImage'];
        $teamNameCurrent  = $teamData['teamName'];
    } else {
        $ownerID          = 0;
        $currentTeamImage = null;
        $teamNameCurrent  = "";
    }

    $action = $_POST['action'];

    // ================== UPLOAD TEAM IMAGE HANDLER ==================
    if ($action === 'upload_team_image' && $userID == $ownerID && isset($_FILES['teamImage'])) {

        $file = $_FILES['teamImage'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = "File upload failed with error code " . $file['error'];
            $messageType = "danger";
        } elseif (!in_array(mime_content_type($file['tmp_name']), ['image/jpeg', 'image/png', 'image/gif'])) {
            $message = "Only JPG, PNG, and GIF files are allowed.";
            $messageType = "danger";
        } elseif ($file['size'] > 5000000) { // 5MB limit
            $message = "File size must be less than 5MB.";
            $messageType = "danger";
        } else {
            $uploadDir = "uploads/team/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $extension   = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFileName = $teamID . '_' . time() . '.' . $extension;
            $uploadPath  = $uploadDir . $newFileName;
            $dbPath      = $uploadPath;

            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Delete old image if it exists and is not default
                if (!empty($currentTeamImage) &&
                    file_exists($currentTeamImage) &&
                    $currentTeamImage !== 'uploads/team/default_team.png') {
                    @unlink($currentTeamImage);
                }

                $stmt = $conn->prepare("UPDATE team SET teamImage = ? WHERE teamID = ?");
                $stmt->bind_param("si", $dbPath, $teamID);

                if ($stmt->execute()) {
                    $message = "Team image uploaded successfully.";
                    $messageType = "success";

                    // ⭐ Notify ALL MEMBERS
                    notifyTeamMembers(
                        $conn,
                        $teamID,
                        "The team image for '{$teamNameCurrent}' has been updated.",
                        "team.php"
                    );

                } else {
                    $message = "Database update failed after file upload.";
                    $messageType = "danger";
                    @unlink($uploadPath);
                }
                $stmt->close();
            } else {
                $message = "Failed to move uploaded file.";
                $messageType = "danger";
            }
        }
    }

    // Kick member
    if ($action === 'kick_member' && isset($_POST['targetID'])) {

        $targetID = intval($_POST['targetID']);

        if ($userID == $ownerID && $targetID != $ownerID) {

            // get target user's name for notification
            $stmtUserInfo = $conn->prepare("SELECT firstName, lastName FROM user WHERE userID = ?");
            $stmtUserInfo->bind_param("i", $targetID);
            $stmtUserInfo->execute();
            $userRow = $stmtUserInfo->get_result()->fetch_assoc();
            $stmtUserInfo->close();

            $targetName = $userRow ? ($userRow['firstName'] . " " . $userRow['lastName']) : "A member";

            $stmt = $conn->prepare("UPDATE user SET teamID = NULL WHERE userID = ? AND teamID = ?");
            $stmt->bind_param("ii", $targetID, $teamID);
            $stmt->execute();
            $stmt->close();

            $message     = "Member kicked successfully!";
            $messageType = "success";

            // ⭐ Notify kicked MEMBER
            sendNotification(
                $conn,
                $targetID,
                "You have been removed from the team '{$teamNameCurrent}'.",
                "team.php"
            );

            // ⭐ Optional: Notify OWNER about kick action
            sendNotification(
                $conn,
                $ownerID,
                "You removed {$targetName} from your team '{$teamNameCurrent}'.",
                "team.php"
            );

        } else {
            $message     = "Cannot kick this user!";
            $messageType = "info";
        }
    }

    // Leave team
    if ($action === 'leave_team') {

        if ($userID == $ownerID) {
            $message     = "Owner cannot leave team. Transfer ownership first!";
            $messageType = "info";
        } else {
            $stmt = $conn->prepare("UPDATE user SET teamID = NULL WHERE userID = ? AND teamID = ?");
            $stmt->bind_param("ii", $userID, $teamID);
            $stmt->execute();
            $stmt->close();

            $message     = "You have left the team.";
            $messageType = "success";
            $teamID      = null;  // local state

            $leaverName = $userData['firstName'] . " " . $userData['lastName'];

            // ⭐ Notify LEADER
            if ($ownerID > 0) {
                sendNotification(
                    $conn,
                    $ownerID,
                    "{$leaverName} has left your team '{$teamNameCurrent}'.",
                    "team.php"
                );
            }

            // ⭐ Notify USER
            sendNotification(
                $conn,
                $userID,
                "You have left the team '{$teamNameCurrent}'.",
                "team.php"
            );
        }
    }

    // Transfer owner
    if ($action === 'transfer_owner' && isset($_POST['newOwnerID'])) {

        $newOwnerID = intval($_POST['newOwnerID']);

        if ($userID == $ownerID) {

            // Get new owner's name
            $stmtUserInfo = $conn->prepare("SELECT firstName, lastName FROM user WHERE userID = ?");
            $stmtUserInfo->bind_param("i", $newOwnerID);
            $stmtUserInfo->execute();
            $newUserRow = $stmtUserInfo->get_result()->fetch_assoc();
            $stmtUserInfo->close();

            $newOwnerName = $newUserRow ? ($newUserRow['firstName'] . " " . $newUserRow['lastName']) : "a member";

            $stmt = $conn->prepare("UPDATE team SET teamLeaderID = ? WHERE teamID = ?");
            $stmt->bind_param("ii", $newOwnerID, $teamID);

            if ($stmt->execute()) {
                $message     = "Ownership transferred successfully! You are now a regular member.";
                $messageType = "success";

                // ⭐ Notify NEW OWNER
                sendNotification(
                    $conn,
                    $newOwnerID,
                    "You are now the owner of team '{$teamNameCurrent}'.",
                    "team.php"
                );

                // ⭐ Notify OLD OWNER
                sendNotification(
                    $conn,
                    $ownerID,
                    "You have transferred ownership of '{$teamNameCurrent}' to {$newOwnerName}.",
                    "team.php"
                );

            } else {
                $message     = "Failed to transfer ownership.";
                $messageType = "info";
            }
            $stmt->close();
        }
    }

    // Edit team details
    if ($action === 'edit_team') {

        $teamName = $_POST['teamName'] ?? "";
        $teamDesc = $_POST['teamDesc'] ?? "";

        if ($userID == $ownerID) {
            $stmt = $conn->prepare("UPDATE team SET teamName = ?, teamDesc = ? WHERE teamID = ?");
            $stmt->bind_param("ssi", $teamName, $teamDesc, $teamID);

            if ($stmt->execute()) {
                $message     = "Team details updated.";
                $messageType = "success";

                // ⭐ Notify all MEMBERS
                notifyTeamMembers(
                    $conn,
                    $teamID,
                    "The details for team '{$teamName}' have been updated.",
                    "team.php"
                );

            } else {
                $message     = "Failed to update.";
                $messageType = "info";
            }
            $stmt->close();
        }
    }
}

// ====================== RELOAD USER STATE AFTER ACTIONS ======================
$stmtRole2 = $conn->prepare("
    SELECT userID, firstName, lastName, role, avatarURL, teamID, pendingTeamID
    FROM user
    WHERE userID = ?
");
$stmtRole2->bind_param("i", $userID);
$stmtRole2->execute();
$userData = $stmtRole2->get_result()->fetch_assoc();
$stmtRole2->close();

$userRole       = $userData['role'];
$teamID         = !empty($userData['teamID']) ? (int)$userData['teamID'] : null;
$pendingTeamID  = !empty($userData['pendingTeamID']) ? (int)$userData['pendingTeamID'] : null;

// ====================== LOAD AVAILABLE TEAMS WHEN NO TEAM ======================
$availableTeams = [];
if (!$teamID) {
    $sql = "
        SELECT 
            t.teamID, 
            t.teamName, 
            t.teamDesc, 
            t.teamImage,
            u.firstName, 
            u.lastName,
            (SELECT COUNT(*) FROM user WHERE teamID = t.teamID) AS memberCount
        FROM team t
        JOIN user u ON u.userID = t.teamLeaderID
    ";

    $resultTeams = $conn->query($sql);
    while ($row = $resultTeams->fetch_assoc()) {
        $availableTeams[] = $row;
    }
    if ($resultTeams) $resultTeams->free();
}

// ====================== FETCH TEAM DATA (AFTER ACTIONS) ======================
$team            = null;
$ownerID         = null;
$owner           = null;
$membersResult   = null;
$pendingRequests = null;
$totalMembers    = 0;

if ($teamID) {
    $resTeam = $conn->query("SELECT * FROM team WHERE teamID = $teamID");
    $team    = $resTeam->fetch_assoc();
    if ($resTeam) $resTeam->free();

    if ($team) {
        $ownerID = (int)$team['teamLeaderID'];

        $resOwner = $conn->query("SELECT firstName, lastName FROM user WHERE userID = $ownerID");
        $owner    = $resOwner->fetch_assoc();
        if ($resOwner) $resOwner->free();

        $resCnt = $conn->query("SELECT COUNT(*) AS total FROM user WHERE teamID = $teamID");
        $totalMembers = $resCnt->fetch_assoc()['total'];
        if ($resCnt) $resCnt->free();

        $membersResult = $conn->query("
            SELECT userID, firstName, lastName, avatarURL, last_online 
            FROM user 
            WHERE teamID = $teamID 
            ORDER BY userID = $ownerID DESC, firstName ASC
        ");

        // OWNER: pending join requests (pendingTeamID = teamID)
        if ($userID == $ownerID) {
            $pendingRequests = $conn->query("
                SELECT userID, firstName, lastName, avatarURL
                FROM user
                WHERE pendingTeamID = $teamID
            ");
        }
    }
}

// If user has pending request but not in a team, load info for banner
$pendingTeamInfo = null;
if ($pendingTeamID && !$teamID) {
    $stmtPend = $conn->prepare("
        SELECT t.teamName, u.firstName, u.lastName 
        FROM team t
        JOIN user u ON u.userID = t.teamLeaderID
        WHERE t.teamID = ?
    ");
    $stmtPend->bind_param("i", $pendingTeamID);
    $stmtPend->execute();
    $pendingTeamInfo = $stmtPend->get_result()->fetch_assoc();
    $stmtPend->close();
}

// ====================== PROVIDE $user + PAGE TITLE FOR LAYOUT ======================
$user = [
    'firstName' => $userData['firstName'],
    'lastName'  => $userData['lastName'],
    'role'      => $userData['role'],
    'avatarURL' => $userData['avatarURL'] ?? null,
];

$pageTitle = "My Team";

// ====================== LAYOUT START (SIDEBAR + TOPBAR) ======================
include "includes/layout_start.php";
?>

<style>
/* --- COLOR REDESIGN: Using Neutral Shades --- */
:root {
    --neutral-dark: #343a40;
    --neutral-light: #5a5f64;
}

.member-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
}
.status-indicator {
    position: absolute;
    bottom: 2px;
    right: 2px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 2px solid #fff;
}
.status-indicator.online  { background-color: #16a34a; }
.status-indicator.offline { background-color: #9ca3af; }
.avatar-container { position: relative; }
.available-team-desc { color: #6c757d; }

.card.bg-dark-neutral {
    background-color: var(--neutral-dark) !important;
    color: white !important;
}
.text-dark-neutral {
    color: var(--neutral-dark) !important;
}
.btn-dark-neutral {
    background-color: var(--neutral-dark);
    border-color: var(--neutral-dark);
    color: white;
}
.btn-dark-neutral:hover {
    background-color: var(--neutral-light);
    border-color: var(--neutral-light);
}

.team-image-lg {
    width: 150px;
    height: 150px;
    object-fit: cover;
    border-radius: 10px;
    border: 3px solid rgba(255, 255, 255, 0.7);
}

.badge.bg-dark-neutral {
    background-color: var(--neutral-light) !important;
}
</style>

<div class="p-4 container-fluid">

    <?php if ($message): ?>
        <div id="alertMsg" class="alert alert-<?= $messageType ?> alert-dismissible fade show shadow-sm">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$teamID): ?>

        <?php if ($pendingTeamID && $pendingTeamInfo): ?>
            <div class="card p-4 mb-4 shadow-sm border-warning">
                <h5 class="mb-2">Join Request Pending</h5>
                <p class="mb-1">
                    You have requested to join 
                    <strong><?= htmlspecialchars($pendingTeamInfo['teamName']); ?></strong><br>
                    Team Leader:
                    <?= htmlspecialchars($pendingTeamInfo['firstName'] . " " . $pendingTeamInfo['lastName']); ?>
                </p>
                <p class="text-muted small mb-0">
                    Please wait for the team leader to approve or reject your request.
                </p>
            </div>
        <?php endif; ?>

        <div class="card p-5 text-center shadow-lg rounded-3 mb-4">
            <iconify-icon icon="ic:round-groups" width="60"
                          class="text-dark-neutral mx-auto mb-3"></iconify-icon>
            <h3 class="mb-3">You're not part of any team yet.</h3>
            <p class="text-muted">
                Join an existing team or create a new one to collaborate.
            </p>
            <div class="mt-4">
                <a href="create_team.php" class="btn btn-dark-neutral btn-lg me-3">
                    <iconify-icon icon="ic:round-group-add"></iconify-icon> Create a Team
                </a>
                <a href="join_team.php" class="btn btn-success btn-lg">
                    <iconify-icon icon="material-symbols:link"></iconify-icon> Join via Code
                </a>
            </div>
        </div>

        <div class="card p-4 shadow-sm">
            <h4 class="mb-3">Available Teams</h4>
            <p class="text-muted">Browse teams and send a join request.</p>

            <?php if (empty($availableTeams)): ?>
                <div class="alert alert-info">
                    No teams created yet. Be the first to create one!
                </div>
            <?php else: ?>
                <?php foreach ($availableTeams as $t):
                    $thisTeamID        = (int)$t['teamID'];
                    $isPendingThisTeam = ($pendingTeamID === $thisTeamID);
                    $memberCount       = (int)($t['memberCount'] ?? 0);
                ?>
                    <div class="d-flex align-items-center border rounded p-3 mb-3 bg-white shadow-sm">
                        <img src="<?= htmlspecialchars($t['teamImage'] ?: 'uploads/team/default_team.png'); ?>"
                             style="width:70px; height:70px; object-fit:cover; border-radius:10px; margin-right:15px;">
                        <div class="flex-fill text-start">
                            <h5 class="mb-1"><?= htmlspecialchars($t['teamName']); ?></h5>
                            <p class="mb-1 available-team-desc">
                                <?= htmlspecialchars($t['teamDesc']); ?>
                            </p>

                            <small class="text-muted d-block">
                                <iconify-icon icon="solar:user-id-bold-duotone" width="15"></iconify-icon>
                                Leader: <?= htmlspecialchars($t['firstName'] . " " . $t['lastName']); ?>
                            </small>

                            <small class="text-muted d-block mt-1">
                                <iconify-icon icon="solar:users-group-rounded-line-duotone" width="17"
                                              class="me-1"></iconify-icon>
                                <?= $memberCount ?> / 4 Members
                            </small>
                        </div>
                        <div>
                            <?php if ($isPendingThisTeam): ?>
                                <span class="badge bg-warning">Pending Approval</span>

                            <?php elseif ($pendingTeamID): ?>
                                <button class="btn btn-secondary" disabled>
                                    Another Request Pending
                                </button>

                            <?php elseif ($memberCount >= 4): ?>
                                <span class="badge bg-danger">Full</span>

                            <?php else: ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="request_join">
                                    <input type="hidden" name="teamID" value="<?= $t['teamID']; ?>">
                                    <button class="btn btn-dark-neutral">
                                        <iconify-icon icon="material-symbols:person-add"></iconify-icon>
                                        Request to Join
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <?php 
            $teamImageUrl = $team['teamImage'] ?: 'uploads/team/default_team.png';
            if (!file_exists($teamImageUrl)) {
                $teamImageUrl = 'uploads/team/default_team.png';
            }
        ?>

        <div class="card p-4 shadow-lg mb-4 bg-dark-neutral rounded-3">
            <div class="d-flex justify-content-between align-items-start"> 
                
                <div>
                    <h2 class="mb-0"><?= htmlspecialchars($team['teamName']) ?></h2>
                    <p class="lead mt-1 mb-0 fst-italic text-white-50">Descriptions:
                        <?= htmlspecialchars($team['teamDesc'] ?? "No description available.") ?>
                    </p>
                </div>

                <img src="<?= htmlspecialchars($teamImageUrl); ?>" 
                     alt="<?= htmlspecialchars($team['teamName']) ?> Image"
                     class="team-image-lg">
            </div>
            <hr class="text-white-50 my-2">
            <div class="row small">
                <div class="col-auto">
                    <strong>Owner:</strong>
                    <?= htmlspecialchars($owner['firstName'] . " " . $owner['lastName']) ?>
                </div>
                <div class="col-auto">
                    <strong>Members:</strong> <?= $totalMembers ?>
                </div>
                <div class="col-auto">
                    <strong>Created:</strong>
                    <?= date('M d, Y', strtotime($team['created_at'])) ?>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs nav-tabs-bordered mb-4">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#overview">
                    Overview
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#members">
                    Members
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#settings">
                    Settings
                </button>
            </li>
            
            <?php if ($userID == $ownerID): ?>
                <?php 
                    $pendingCount = ($pendingRequests && $pendingRequests->num_rows > 0) 
                                    ? $pendingRequests->num_rows 
                                    : 0;
                ?>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#requests">
                        Join Requests
                        <?php if ($pendingCount > 0): ?>
                            <span class="badge bg-danger ms-1"><?= $pendingCount ?></span>
                        <?php endif; ?>
                    </button>
                </li>
            <?php endif; ?>

            <!-- 🔗 Dashboard Nav Link (ADDED ONLY) -->
<li class="nav-item">
    <a class="nav-link"
       href="team_dashboard.php?teamID=<?= (int)$teamID ?>">
        <iconify-icon icon="mdi:view-dashboard-outline"></iconify-icon>
        Dashboard
    </a>
</li>


        </ul>

        <div class="tab-content pt-2">

            <div class="tab-pane fade show active" id="overview">
                <div class="card p-4 shadow-sm">
                    <h5 class="card-title text-dark-neutral">Team Invitation</h5>
                    <p class="mb-1">
                        Invite Code:
                        <span id="inviteCode"
                              style="cursor:pointer; font-weight:bold; color:#0d6efd;">
                            <?= htmlspecialchars($team['joinCode']) ?>
                        </span>
                        <iconify-icon icon="ic:round-content-copy" width="18"
                                      style="vertical-align: sub;"
                                      class="text-secondary ms-1"></iconify-icon>
                        <span id="copyMsg" class="text-success ms-2 small"
                              style="display:none;">Copied!</span>
                    </p>
                    <hr>
                    <p class="text-muted small mb-0">
                        Click the code above to copy it. Anyone using this code in
                        "Join via Code" will join your team directly (if there is capacity).
                    </p>
                </div>
            </div>

            <div class="tab-pane fade" id="members">
                <div class="list-group shadow-sm">
                    <?php
                    if ($membersResult) $membersResult->data_seek(0);
                    while ($m = $membersResult->fetch_assoc()):
                        $avatar        = getAvatar($m['avatarURL']);
                        $isOwner       = $m['userID'] == $ownerID;
                        $isCurrentUser = $m['userID'] == $userID;
                        $onlineStatus  = ($m['last_online'] && strtotime($m['last_online']) > strtotime('-5 minutes'))
                                             ? 'online' : 'offline';
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center member-list-item">
                            <div class="d-flex align-items-center">
                                <div class="avatar-container position-relative me-3">
                                    <img src="<?= htmlspecialchars($avatar) ?>"
                                         class="member-avatar" alt="Avatar">
                                    <span class="status-indicator <?= $onlineStatus ?>"></span>
                                </div>
                                <div>
                                    <h6 class="mb-1">
                                        <?= htmlspecialchars($m['firstName'] . " " . $m['lastName']) ?>
                                        <?php if ($isCurrentUser): ?>
                                            <span class="badge bg-info ms-2">You</span>
                                        <?php endif; ?>
                                    </h6>
                                    <span class="badge <?= $isOwner ? 'bg-dark-neutral' : 'bg-secondary' ?> me-2">
                                        <?= $isOwner ? 'Owner' : 'Member' ?>
                                    </span>
                                    <small class="text-muted d-block mt-1">
                                        Last online:
                                        <?= $m['last_online']
                                            ? date('M d, Y h:i A', strtotime($m['last_online']))
                                            : 'Never'
                                        ?>
                                    </small>
                                </div>
                            </div>

                            <?php if ($userID == $ownerID && !$isOwner): ?>
                                <form method="POST" class="d-inline-block"
                                      onsubmit="return confirm('Are you sure you want to kick <?= htmlspecialchars($m['firstName']) ?> from the team?');">
                                    <input type="hidden" name="action" value="kick_member">
                                    <input type="hidden" name="targetID" value="<?= $m['userID'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">
                                        <iconify-icon icon="ic:round-person-remove"></iconify-icon> Kick
                                    </button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endwhile; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="settings">
                <div class="row g-4">

                    <?php if ($userID == $ownerID): ?>

                        <div class="col-md-6">
                            <div class="card p-4 shadow-sm border-info border-3 border-start">
                                <h5 class="card-title text-info mb-3">
                                    <iconify-icon icon="material-symbols:edit-document-outline"
                                                  class="me-2"></iconify-icon>
                                    Edit Team Details
                                </h5>
                                <form method="POST">
                                    <input type="hidden" name="action" value="edit_team">

                                    <label for="teamName" class="form-label">Team Name</label>
                                    <input type="text" id="teamName" name="teamName"
                                           class="form-control mb-2"
                                           value="<?= htmlspecialchars($team['teamName']) ?>" required>

                                    <label for="teamDesc" class="form-label">Team Description</label>
                                    <textarea id="teamDesc" name="teamDesc" class="form-control mb-3"
                                              rows="3"><?= htmlspecialchars($team['teamDesc']) ?></textarea>

                                    <button class="btn btn-info w-100">
                                        <iconify-icon icon="material-symbols:save"></iconify-icon>
                                        Save Changes
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card p-4 shadow-sm border-secondary border-3 border-start">
                                <h5 class="card-title text-secondary mb-3">
                                    <iconify-icon icon="ic:round-image" class="me-2"></iconify-icon>
                                    Team Image
                                </h5>
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="upload_team_image">
                                    
                                    <img src="<?= htmlspecialchars($teamImageUrl); ?>" 
                                         alt="Current Team Image"
                                         style="width:100px; height:100px; object-fit:cover; border-radius:8px; margin-bottom:15px; border:1px solid #ddd;">

                                    <label for="teamImageFile" class="form-label">Upload New Image (Max 5MB)</label>
                                    <input type="file" id="teamImageFile" name="teamImage" 
                                           class="form-control mb-3" accept=".jpg,.jpeg,.png,.gif" required>

                                    <button class="btn btn-secondary w-100">
                                        <iconify-icon icon="material-symbols:upload"></iconify-icon>
                                        Upload Image
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card p-4 shadow-sm border-warning border-3 border-start">
                                <h5 class="card-title text-warning mb-3">
                                    <iconify-icon icon="material-symbols:vpn-key-outline"
                                                  class="me-2"></iconify-icon>
                                    Transfer Ownership
                                </h5>
                                <form method="POST"
                                      onsubmit="return confirm('WARNING: Are you absolutely sure you want to transfer ownership? You will become a regular member.');">
                                    <input type="hidden" name="action" value="transfer_owner">

                                    <label for="newOwnerID" class="form-label">Select New Owner</label>

                                    <select name="newOwnerID" id="newOwnerID"
                                            class="form-select mb-3" required>
                                        <option value="" disabled selected>
                                            Select a team member...
                                        </option>
                                        <?php
                                        $membersList = $conn->query("
                                            SELECT userID, firstName, lastName 
                                            FROM user 
                                            WHERE teamID = $teamID AND userID != $ownerID
                                        ");
                                        while ($mem = $membersList->fetch_assoc()):
                                        ?>
                                            <option value="<?= $mem['userID'] ?>">
                                                <?= htmlspecialchars($mem['firstName'] . " " . $mem['lastName']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>

                                    <?php if ($membersList->num_rows > 0): ?>
                                        <button class="btn btn-warning w-100">
                                            <iconify-icon icon="material-symbols:transfer-within-a-station">
                                            </iconify-icon>
                                            Transfer Ownership
                                        </button>
                                    <?php else: ?>
                                        <div class="alert alert-secondary mb-0">
                                            No other members available to transfer ownership.
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($membersList) $membersList->free(); ?>
                                </form>
                            </div>
                        </div>

                    <?php endif; ?>

                    <div class="col-12">
                        <div class="card p-4 shadow-sm border-danger border-3 border-start">
                            <h5 class="card-title text-danger mb-3">
                                <iconify-icon icon="ic:baseline-logout" class="me-2"></iconify-icon>
                                Team Exit
                            </h5>
                            <form method="POST"
                                  onsubmit="return confirm('Are you sure you want to leave the team?');">
                                <input type="hidden" name="action" value="leave_team">

                                <button class="btn btn-danger btn-lg"
                                    <?= ($userID == $ownerID ? 'disabled' : '') ?>>
                                    Leave Team
                                </button>

                                <?php if ($userID == $ownerID): ?>
                                    <p class="text-muted small mt-2 mb-0">
                                        You must transfer ownership to another member before you can leave the team.
                                    </p>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

            <?php if ($userID == $ownerID): ?>
            <div class="tab-pane fade" id="requests">
                <div class="card p-4 shadow-sm">
                    <h5 class="card-title mb-3">Pending Join Requests</h5>

                    <?php if ($pendingRequests && $pendingRequests->num_rows > 0): ?>
                        <?php while ($req = $pendingRequests->fetch_assoc()):
                            $reqAvatar = getAvatar($req['avatarURL']);
                        ?>
                            <div class="d-flex align-items-center border rounded p-3 mb-2 bg-white">
                                <div class="avatar-container position-relative me-3">
                                    <img src="<?= htmlspecialchars($reqAvatar); ?>"
                                         class="member-avatar" alt="Avatar">
                                </div>
                                <div class="flex-fill">
                                    <strong>
                                        <?= htmlspecialchars($req['firstName'] . " " . $req['lastName']); ?>
                                    </strong>
                                    <p class="text-muted small mb-0">
                                        wants to join your team.
                                    </p>
                                </div>
                                <form method="POST" class="d-inline me-2">
                                    <input type="hidden" name="action" value="approve_request">
                                    <input type="hidden" name="userID" value="<?= $req['userID']; ?>">
                                    <input type="hidden" name="teamID" value="<?= $teamID; ?>">
                                    <button class="btn btn-success btn-sm">
                                        <iconify-icon icon="ic:round-check-circle"></iconify-icon>
                                        Approve
                                    </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="reject_request">
                                    <input type="hidden" name="userID" value="<?= $req['userID']; ?>">
                                    <input type="hidden" name="teamID" value="<?= $teamID; ?>">
                                    <button class="btn btn-danger btn-sm">
                                        <iconify-icon icon="ic:round-cancel"></iconify-icon>
                                        Reject
                                    </button>
                                </form>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">
                            No pending join requests.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

    <?php endif; // end if $teamID ?>

</div>

<script>
// Copy invite code
document.getElementById("inviteCode")?.addEventListener("click", function () {
    navigator.clipboard.writeText(this.textContent.trim()).then(() => {
        let msg = document.getElementById("copyMsg");
        if (msg) {
            msg.style.display = "inline";
            setTimeout(() => msg.style.display = "none", 1200);
        }
    });
});

// Auto-hide alerts
setTimeout(() => {
    const alertNode = document.getElementById("alertMsg");
    if (alertNode && window.bootstrap && bootstrap.Alert) {
        const alertInstance = bootstrap.Alert.getOrCreateInstance(alertNode);
        if (alertInstance) alertInstance.close();
    }
}, 4000);
</script>

<?php include "includes/layout_end.php"; ?>
