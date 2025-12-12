<?php
session_start();
require "db_connect.php";
require "includes/auth.php"; // browser-token auto logout + auth
require "includes/notify.php"; // ⭐ for notifications

// --------------------------------------------------
// PAGE TITLE FOR TOPBAR
// --------------------------------------------------
$pageTitle = "Team Management";

// Must be logged in
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

// Must be admin
if ($_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$userID = (int)$_SESSION['userID']; // admin ID

// --------------------------------------------------
// OPTIONAL: FETCH ADMIN INFO (TOPBAR USES SESSION)
// --------------------------------------------------
$stmtUser = $conn->prepare("
    SELECT firstName, lastName, email, role, avatarURL
    FROM user
    WHERE userID = ?
");
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$_SESSION['role'] = $user['role'];

// --------------------------------------------------
// HELPER: RENDER TEAM MEMBERS IN COLLAPSE
// --------------------------------------------------
function getTeamMembersHTML($conn, $teamID, $teamLeaderID)
{
    $stmtMembers = $conn->prepare("
        SELECT userID, firstName, lastName, email, is_Active, avatarURL
        FROM user
        WHERE teamID = ?
        ORDER BY (userID = ?) DESC, firstName ASC
    ");
    $stmtMembers->bind_param("ii", $teamID, $teamLeaderID);
    $stmtMembers->execute();
    $result = $stmtMembers->get_result();
    $stmtMembers->close();

    if ($result->num_rows === 0) {
        return '<div class="alert alert-info m-2 small">No members in this team.</div>';
    }

    $html = '<ul class="list-group list-group-flush">';

    while ($m = $result->fetch_assoc()) {
        $avatar = "uploads/default.png";
        if (!empty($m['avatarURL'])) {
            $checkPath = __DIR__ . "/" . $m['avatarURL'];
            if (file_exists($checkPath)) {
                $avatar = $m['avatarURL'];
            }
        }

        $isLeader = ($m['userID'] == $teamLeaderID);

        $roleBadge = $isLeader
            ? '<span class="badge bg-primary">Leader</span>'
            : '<span class="badge bg-secondary">Member</span>';

        $statusBadge = $m['is_Active']
            ? '<span class="badge bg-success">Active</span>'
            : '<span class="badge bg-warning text-dark">Suspended</span>';

        $html .= '
            <li class="list-group-item bg-light d-flex justify-content-between align-items-center">
                <div>
                    <img src="' . htmlspecialchars($avatar) . '" class="avatar-sm me-2">
                    <strong>' . htmlspecialchars($m['firstName'] . " " . $m['lastName']) . '</strong>
                    (' . htmlspecialchars($m['email']) . ')
                </div>
                <div>' . $roleBadge . ' ' . $statusBadge . '</div>
            </li>
        ';
    }

    $html .= '</ul>';

    return $html;
}

// --------------------------------------------------
// EDIT TEAM HANDLER (WITH NOTIFICATIONS)
// --------------------------------------------------
$message = "";
$messageType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === "edit") {

    $teamID   = intval($_POST['teamID']);
    $teamName = trim($_POST['teamName']);
    $teamDesc = trim($_POST['teamDesc']);

    if ($teamName === "") {
        $message = "Team name cannot be empty.";
        $messageType = "warning";
    } else {

        // Fetch team info first (leader + old name)
        $stmtTeamInfo = $conn->prepare("
            SELECT teamLeaderID, teamName
            FROM team
            WHERE teamID = ?
        ");
        $stmtTeamInfo->bind_param("i", $teamID);
        $stmtTeamInfo->execute();
        $teamInfo = $stmtTeamInfo->get_result()->fetch_assoc();
        $stmtTeamInfo->close();

        if (!$teamInfo) {
            $message = "Team not found.";
            $messageType = "danger";
        } else {

            $leaderID = (int)$teamInfo['teamLeaderID'];
            $oldName  = $teamInfo['teamName'];

            // Update team
            $stmt = $conn->prepare("UPDATE team SET teamName = ?, teamDesc = ? WHERE teamID = ?");
            $stmt->bind_param("ssi", $teamName, $teamDesc, $teamID);

            if ($stmt->execute()) {
                $message = "Team updated successfully.";
                $messageType = "success";

                // ⭐ Notify TEAM LEADER (only if team has leader)
                if ($leaderID > 0) {
                    sendNotification(
                        $conn,
                        $leaderID,
                        "Your team '{$oldName}' has been updated by an administrator.",
                        "team.php"
                    );
                }

                // ⭐ Notify ADMIN (self log)
                sendNotification(
                    $conn,
                    $userID,
                    "You updated the team '{$teamName}'.",
                    "manage_team.php"
                );

            } else {
                $message = "Failed to update team.";
                $messageType = "danger";
            }

            $stmt->close();
        }
    }
}

// --------------------------------------------------
// FILTERS + PAGINATION (ADVANCED)
// --------------------------------------------------
$search      = trim($_GET['q'] ?? '');
$minMembers  = $_GET['min_members'] ?? '';
$maxMembers  = $_GET['max_members'] ?? '';
$sort        = $_GET['sort'] ?? 'newest';
$perPage     = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 5;
$page        = isset($_GET['page']) ? (int)$_GET['page'] : 1;

// Normalise
if ($perPage <= 0) $perPage = 5;
$allowedPerPage = [5, 10, 20, 50];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 5;
}
if ($page < 1) $page = 1;

// Convert min/max to int or null
$minMembersInt = ($minMembers === '' ? null : max(0, (int)$minMembers));
$maxMembersInt = ($maxMembers === '' ? null : max(0, (int)$maxMembers));

// Ensure min <= max
if ($minMembersInt !== null && $maxMembersInt !== null && $maxMembersInt < $minMembersInt) {
    $tmp = $minMembersInt;
    $minMembersInt = $maxMembersInt;
    $maxMembersInt = $tmp;
}

// Build WHERE conditions
$whereClauses = [];
$paramTypes   = "";
$params       = [];

if ($search !== "") {
    $pattern = '%' . $search . '%';
    $whereClauses[] = "(t.teamName LIKE ? OR u.firstName LIKE ? OR u.lastName LIKE ?)";
    $paramTypes .= "sss";
    $params[] = $pattern;
    $params[] = $pattern;
    $params[] = $pattern;
}

if ($minMembersInt !== null) {
    $whereClauses[] = "COALESCE(m.memberCount,0) >= ?";
    $paramTypes .= "i";
    $params[] = $minMembersInt;
}

if ($maxMembersInt !== null) {
    $whereClauses[] = "COALESCE(m.memberCount,0) <= ?";
    $paramTypes .= "i";
    $params[] = $maxMembersInt;
}

$whereSQL = "";
if (!empty($whereClauses)) {
    $whereSQL = "WHERE " . implode(" AND ", $whereClauses);
}

// Sort
switch ($sort) {
    case 'name_asc':
        $orderSQL = "ORDER BY t.teamName ASC";
        break;
    case 'name_desc':
        $orderSQL = "ORDER BY t.teamName DESC";
        break;
    case 'members_desc':
        $orderSQL = "ORDER BY COALESCE(m.memberCount,0) DESC, t.teamID DESC";
        break;
    case 'members_asc':
        $orderSQL = "ORDER BY COALESCE(m.memberCount,0) ASC, t.teamID ASC";
        break;
    case 'oldest':
        $orderSQL = "ORDER BY t.created_at ASC";
        break;
    case 'newest':
    default:
        $orderSQL = "ORDER BY t.created_at DESC";
        break;
}

$offset = ($page - 1) * $perPage;

// --------------------------------------------------
// TOTAL COUNT (FOR PAGINATION)
// --------------------------------------------------
$sqlCount = "
    SELECT COUNT(*) AS total
    FROM team t
    LEFT JOIN user u ON t.teamLeaderID = u.userID
    LEFT JOIN (
        SELECT teamID, COUNT(*) AS memberCount
        FROM user
        GROUP BY teamID
    ) m ON t.teamID = m.teamID
    $whereSQL
";

$stmtCount = $conn->prepare($sqlCount);
if ($paramTypes !== "") {
    $stmtCount->bind_param($paramTypes, ...$params);
}
$stmtCount->execute();
$resCount = $stmtCount->get_result();
$totalTeams = (int)($resCount->fetch_assoc()['total'] ?? 0);
$stmtCount->close();

$totalPages = max(1, (int)ceil($totalTeams / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// --------------------------------------------------
// FETCH TEAMS (WITH MEMBER COUNT)
// --------------------------------------------------
$sqlTeams = "
    SELECT 
        t.*, 
        t.teamImage,
        u.firstName, 
        u.lastName,
        COALESCE(m.memberCount,0) AS memberCount
    FROM team t
    LEFT JOIN user u ON t.teamLeaderID = u.userID
    LEFT JOIN (
        SELECT teamID, COUNT(*) AS memberCount
        FROM user
        GROUP BY teamID
    ) m ON t.teamID = m.teamID
    $whereSQL
    $orderSQL
    LIMIT ? OFFSET ?
";

$stmtTeams = $conn->prepare($sqlTeams);

$paramTypesTeams = $paramTypes . "ii";
$paramsTeams     = $params;
$paramsTeams[]   = $perPage;
$paramsTeams[]   = $offset;

$stmtTeams->bind_param($paramTypesTeams, ...$paramsTeams);
$stmtTeams->execute();
$teams = $stmtTeams->get_result();
$stmtTeams->close();

// --------------------------------------------------
// LAYOUT START
// --------------------------------------------------
include "includes/layout_start.php";
?>

<style>
.avatar-sm {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
}

.team-img-sm {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.rotate-icon {
    transition: transform .25s ease;
}

.rotate-icon.rotated {
    transform: rotate(90deg);
}
</style>

<div class="p-4">

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show shadow-sm">
            <?= htmlspecialchars($message); ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm p-4">
        <h5 class="card-title fw-bold">Team List</h5>
        <p class="text-muted small mb-3">Manage teams, filter by size and sort them. aaaaa</p>

        <!-- FILTER BAR (ADVANCED) -->
        <form method="get" class="row g-2 align-items-end mb-3">

            <div class="col-md-4">
                <label class="form-label small text-muted">Search (Team / Leader)</label>
                <input type="text"
                       name="q"
                       class="form-control"
                       placeholder="Search team name or leader..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label small text-muted">Min Members</label>
                <input type="number"
                       name="min_members"
                       class="form-control"
                       min="0"
                       value="<?= htmlspecialchars($minMembers) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label small text-muted">Max Members</label>
                <input type="number"
                       name="max_members"
                       class="form-control"
                       min="0"
                       value="<?= htmlspecialchars($maxMembers) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label small text-muted">Sort By</label>
                <select name="sort" class="form-select">
                    <option value="newest"       <?= $sort=='newest'?'selected':'' ?>>Newest</option>
                    <option value="oldest"       <?= $sort=='oldest'?'selected':'' ?>>Oldest</option>
                    <option value="name_asc"     <?= $sort=='name_asc'?'selected':'' ?>>Name A–Z</option>
                    <option value="name_desc"    <?= $sort=='name_desc'?'selected':'' ?>>Name Z–A</option>
                    <option value="members_desc" <?= $sort=='members_desc'?'selected':'' ?>>Most Members</option>
                    <option value="members_asc"  <?= $sort=='members_asc'?'selected':'' ?>>Least Members</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small text-muted">Per Page</label>
                <select name="per_page" class="form-select">
                    <?php foreach ([5,10,20,50] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $perPage==$opt?'selected':'' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 d-flex justify-content-between mt-2">
                <div>
                    <button type="submit" class="btn btn-primary btn-sm me-2">
                        <iconify-icon icon="solar:magnifer-linear"></iconify-icon> Apply Filters
                    </button>
                    <a href="manage_team.php" class="btn btn-outline-secondary btn-sm">
                        Reset
                    </a>
                </div>

                <div class="small text-muted align-self-center">
                    <?= $totalTeams ?> team(s) found
                </div>
            </div>
        </form>

        <?php if ($totalTeams === 0): ?>

            <div class="alert alert-warning text-center p-4 mt-2">
                <iconify-icon icon="mdi:account-group" width="40"></iconify-icon>
                <h5 class="mt-2">No Teams Found</h5>
                <p class="text-muted mb-0">Try adjusting your filters or search term.</p>
            </div>

        <?php else: ?>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th></th>
                        <th>ID</th>
                        <th>Image</th>
                        <th>Team Name</th>
                        <th>Leader</th>
                        <th>Members</th>
                        <th>Join Code</th>
                        <th>Created</th>
                        <th class="text-end">Edit</th>
                    </tr>
                </thead>

                <tbody>
                <?php while ($row = $teams->fetch_assoc()):
                    $teamID = $row['teamID'];

                    $teamImagePath = "uploads/team_default.png";
                    if (!empty($row['teamImage'])) {
                        $pathCheck = __DIR__ . "/" . $row['teamImage'];
                        if (file_exists($pathCheck)) {
                            $teamImagePath = $row['teamImage'];
                        }
                    }

                    $collapseID = "team-" . $teamID;
                ?>
                    <tr>
                        <td class="text-center">
                            <button class="btn btn-sm btn-light btn-collapse"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#<?= $collapseID ?>">
                                <i class="bi bi-caret-right-fill rotate-icon"></i>
                            </button>
                        </td>

                        <td><?= $teamID ?></td>

                        <td>
                            <img src="<?= htmlspecialchars($teamImagePath) ?>" class="team-img-sm" alt="Team">
                        </td>

                        <td><?= htmlspecialchars($row['teamName']) ?></td>

                        <td><?= htmlspecialchars(($row['firstName'] ?? '') . " " . ($row['lastName'] ?? '')); ?></td>

                        <td><span class="badge bg-secondary"><?= (int)$row['memberCount'] ?></span></td>

                        <td><?= htmlspecialchars($row['joinCode']) ?></td>

                        <td>
                            <?= $row['created_at'] ? htmlspecialchars(date("Y-m-d", strtotime($row['created_at']))) : "-" ?>
                        </td>

                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#edit-<?= $collapseID ?>">
                                <iconify-icon icon="solar:pen-new-round-linear"></iconify-icon>
                            </button>
                        </td>
                    </tr>

                    <!-- COLLAPSE: MEMBERS -->
                    <tr>
                        <td colspan="9" class="p-0 border-0">
                            <div id="<?= $collapseID ?>" class="collapse">
                                <div class="p-3 bg-light border-top border-bottom">
                                    <h6 class="fw-bold mb-2">Team Members</h6>
                                    <?= getTeamMembersHTML($conn, $teamID, $row['teamLeaderID']); ?>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <!-- COLLAPSE: EDIT TEAM -->
                    <tr>
                        <td colspan="9" class="p-0 border-0">
                            <div id="edit-<?= $collapseID ?>" class="collapse">
                                <div class="p-3 bg-white border-bottom">
                                    <form method="post" class="row g-2 align-items-end">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="teamID" value="<?= $teamID ?>">

                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Team Name</label>
                                            <input type="text"
                                                   name="teamName"
                                                   class="form-control form-control-sm"
                                                   required
                                                   value="<?= htmlspecialchars($row['teamName']) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Description</label>
                                            <input type="text"
                                                   name="teamDesc"
                                                   class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($row['teamDesc'] ?? '') ?>">
                                        </div>

                                        <div class="col-md-2 d-grid">
                                            <button type="submit" class="btn btn-sm btn-primary mt-3 mt-md-0">
                                                Save
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>

                <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php
        $baseParams = $_GET;
        ?>
        <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination justify-content-end">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $baseParams['page'] = $prevPage;
                    $prevUrl = 'manage_team.php?' . htmlspecialchars(http_build_query($baseParams));
                    $baseParams['page'] = $nextPage;
                    $nextUrl = 'manage_team.php?' . htmlspecialchars(http_build_query($baseParams));
                    ?>

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $page <= 1 ? '#' : $prevUrl ?>">Previous</a>
                    </li>

                    <?php
                    for ($p = 1; $p <= $totalPages; $p++):
                        $baseParams['page'] = $p;
                        $pageUrl = 'manage_team.php?' . htmlspecialchars(http_build_query($baseParams));
                    ?>
                        <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $pageUrl ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $page >= $totalPages ? '#' : $nextUrl ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

        <?php endif; // totalTeams > 0 ?>

    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".btn-collapse").forEach(btn => {
        let icon = btn.querySelector("i.rotate-icon");
        let target = document.querySelector(btn.getAttribute("data-bs-target"));

        if (!icon || !target) return;

        target.addEventListener("shown.bs.collapse", () => {
            icon.classList.add("rotated");
        });

        target.addEventListener("hidden.bs.collapse", () => {
            icon.classList.remove("rotated");
        });
    });
});
</script>

<?php include "includes/layout_end.php"; ?>
