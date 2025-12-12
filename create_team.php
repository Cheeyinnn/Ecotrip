<?php
$pageTitle = "Create Team";
session_start();
require 'db_connect.php';
require 'includes/notify.php'; // ⭐ enable notifications

// Must be logged in
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$userID = (int)$_SESSION['userID'];

// Block admin
if ($_SESSION['role'] === 'admin') {
    $_SESSION['flash_danger'] = "Admins cannot create or join teams.";
    header("Location: index.php");
    exit;
}

// Fetch user teamID only (layout_start will load user info)
$stmt = $conn->prepare("SELECT teamID FROM user WHERE userID=?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

$userTeamID = $userData['teamID'] ?? null;
$canCreate  = empty($userTeamID);

$message = "";
$messageType = "";

/* ------------------------------------------------------
   HANDLE CREATE TEAM
------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canCreate) {

    $teamName = trim($_POST['teamName'] ?? '');
    $teamDesc = trim($_POST['teamDesc'] ?? '');
    $teamImage = "";

    if ($teamName === "" || $teamDesc === "") {

        $message = "Team name and description are required.";
        $messageType = "danger";

    } else {

        // Optional image upload
        if (!empty($_FILES['teamImage']['name'])) {

            $allowed = ['jpg', 'jpeg', 'png'];
            $ext = strtolower(pathinfo($_FILES['teamImage']['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed)) {
                $message = "Invalid image format. Only JPG, JPEG, PNG allowed.";
                $messageType = "danger";

            } else {

                if (!is_dir("uploads/team")) {
                    mkdir("uploads/team", 0777, true);
                }

                $fileName = "team_" . time() . "_" . rand(1000, 9999) . "." . $ext;
                $filePath = "uploads/team/" . $fileName;

                if (move_uploaded_file($_FILES['teamImage']['tmp_name'], $filePath)) {
                    $teamImage = $filePath;
                } else {
                    $message = "Image upload failed.";
                    $messageType = "danger";
                }
            }
        }

        if ($messageType !== "danger") {

            // Join Code
            $joinCode = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ23456789"), 0, 6);

            // Insert team
            $stmt = $conn->prepare("
                INSERT INTO team (teamName, teamDesc, teamLeaderID, joinCode, teamImage, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("ssiss", $teamName, $teamDesc, $userID, $joinCode, $teamImage);

            if ($stmt->execute()) {

                $teamID = $stmt->insert_id;

                // Attach creator as member
                $upd = $conn->prepare("UPDATE user SET teamID=? WHERE userID=?");
                $upd->bind_param("ii", $teamID, $userID);

                if ($upd->execute()) {

                    // ---------------------------------------
                    // ⭐ SEND NOTIFICATION TO USER
                    // ---------------------------------------
                    sendNotification(
                        $conn,
                        $userID,
                        "You have successfully created the team '{$teamName}'.",
                        "team.php"
                    );

                    $_SESSION['flash_success'] =
                        "Team created successfully! Join Code: " . htmlspecialchars($joinCode);

                    header("Location: team.php");
                    exit;

                } else {
                    $message = "Team created but user update failed.";
                    $messageType = "danger";
                }

            } else {
                $message = "Error creating team.";
                $messageType = "danger";
            }
        }
    }
}

/* ------------------------------------------------------
   LAYOUT START
------------------------------------------------------ */
include "includes/layout_start.php";
?>

<style>
.create-team-wrapper {
    max-width: 900px;
    margin: auto;
}
.team-card {
    background:#fff;
    padding:40px;
    border-radius:16px;
    box-shadow:0 10px 30px rgba(0,0,0,0.07);
}
</style>

<div class="p-4 create-team-wrapper">
    <div class="team-card">

        <?php if ($canCreate): ?>

            <div class="text-center mb-4">
                <iconify-icon icon="ic:round-group-add" width="60" class="text-primary"></iconify-icon>
                <h3 class="fw-bold mt-2">Create Your Team</h3>
                <p class="text-muted">Set up your team identity and start collaborating.</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show shadow-sm">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">

                <div class="form-floating mb-3">
                    <input type="text" name="teamName" class="form-control" placeholder="Team Name" required>
                    <label>Team Name</label>
                </div>

                <div class="form-floating mb-4">
                    <textarea name="teamDesc" class="form-control" style="height:120px"
                              placeholder="Team Description" required></textarea>
                    <label>Team Description</label>
                </div>

                <div class="mb-4">
                    <label class="form-label">Team Image (optional)</label>
                    <input type="file" name="teamImage" class="form-control" accept=".jpg,.jpeg,.png">
                </div>

                <button class="btn btn-success w-100 btn-lg">
                    <iconify-icon icon="solar:add-circle-bold-duotone" width="22" class="me-1"></iconify-icon>
                    Create Team
                </button>

            </form>

        <?php else: ?>

            <div class="text-center">
                <iconify-icon icon="material-symbols:block-flipped-outline"
                              width="60" class="text-danger"></iconify-icon>
                <h3 class="fw-bold mt-2">Cannot Create Team</h3>
            </div>

            <div class="alert alert-info shadow-sm mt-3">
                You already belong to a team.
            </div>

            <a href="team.php" class="btn btn-primary btn-lg w-100 mt-3">
                <iconify-icon icon="material-symbols:groups" class="me-1"></iconify-icon>
                Go to My Team
            </a>

        <?php endif; ?>

    </div>
</div>

<?php include "includes/layout_end.php"; ?>
