<?php
// -------------------------------------
// PAGE SETUP
// -------------------------------------
$pageTitle = "User Management";

require 'db_connect.php';
require 'includes/auth.php';  // Handles login + browser token + session_start
require 'includes/notify.php'; // ⭐ Added for notification sending

// -------------------------------------
// ADMIN ONLY
// -------------------------------------
if ($_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Current admin ID
$userID = (int)$_SESSION['userID'];

// -------------------------------------
// HANDLE ACTIONS (SUSPEND / ACTIVATE / PROMOTE / DEMOTE)
// -------------------------------------
$message = "";
$messageType = "";

// Helper: set flash + redirect
function set_flash_and_redirect($msg, $type = 'info') {
    $_SESSION['alert_message'] = $msg;
    $_SESSION['alert_type']    = $type;
    header("Location: manage_user.php");
    exit;
}

if (isset($_GET['userID'], $_GET['action'])) {
    $targetID = (int)$_GET['userID'];
    $action   = $_GET['action'];
    $adminID  = (int)$_SESSION['userID'];

    // Prevent self-modification
    if ($targetID === $adminID) {
        set_flash_and_redirect("You cannot modify your own account.", "warning");
    }

    // Fetch target user info (name + role)
    $stmtUser = $conn->prepare("
        SELECT firstName, lastName, role 
        FROM user 
        WHERE userID = ?
    ");
    $stmtUser->bind_param("i", $targetID);
    $stmtUser->execute();
    $uRow = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();

    if (!$uRow) {
        set_flash_and_redirect("User not found.", "danger");
    }

    $targetName = $uRow['firstName'] . " " . $uRow['lastName'];
    $targetRole = $uRow['role'];

    // Protect other admins
    if ($targetRole === 'admin') {
        set_flash_and_redirect("You cannot modify another admin account.", "danger");
    }

    // --------------------------------------------------------------
    // ⭐ SUSPEND / ACTIVATE
    // --------------------------------------------------------------
    if ($action === "suspend" || $action === "activate") {

        if ($action === "suspend") {
            $status = 0;
            $msg    = "User account has been suspended.";
            $msgType = "warning";

            // Notify USER
            sendNotification(
                $conn,
                $targetID,
                "Your account has been suspended by an administrator.",
                "login.php"
            );

            // Notify ADMIN
            sendNotification(
                $conn,
                $adminID,
                "You suspended account: {$targetName}.",
                "manage_user.php"
            );

        } else {
            $status = 1;
            $msg    = "User account has been activated.";
            $msgType = "success";

            // Notify USER
            sendNotification(
                $conn,
                $targetID,
                "Your account has been reactivated. You may now log in again.",
                "login.php"
            );

            // Notify ADMIN
            sendNotification(
                $conn,
                $adminID,
                "You reactivated account: {$targetName}.",
                "manage_user.php"
            );
        }

        $stmt = $conn->prepare("UPDATE user SET is_Active = ? WHERE userID = ?");
        $stmt->bind_param("ii", $status, $targetID);
        $stmt->execute();
        $stmt->close();

        set_flash_and_redirect($msg, $msgType);
    }

    // --------------------------------------------------------------
    // ⭐ PROMOTE USER → MODERATOR
    // --------------------------------------------------------------
    if ($action === "promote") {

        if ($targetRole !== 'user') {
            set_flash_and_redirect("Only normal users can be promoted to moderator.", "info");
        }

        $newRole = 'moderator';

        $stmt = $conn->prepare("UPDATE user SET role = ? WHERE userID = ?");
        $stmt->bind_param("si", $newRole, $targetID);
        $stmt->execute();
        $stmt->close();

        // Notify USER
        sendNotification(
            $conn,
            $targetID,
            "Congratulations! You have been promoted to Moderator.",
            "dashboard.php"
        );

        // Notify ADMIN
        sendNotification(
            $conn,
            $adminID,
            "You promoted {$targetName} to Moderator.",
            "manage_user.php"
        );

        set_flash_and_redirect("User has been promoted to Moderator.", "success");
    }

    // --------------------------------------------------------------
    // ⭐ DEMOTE MODERATOR → USER
    // --------------------------------------------------------------
    if ($action === "demote") {

        if ($targetRole !== 'moderator') {
            set_flash_and_redirect("Only moderators can be demoted to normal users.", "info");
        }

        $newRole = 'user';

        $stmt = $conn->prepare("UPDATE user SET role = ? WHERE userID = ?");
        $stmt->bind_param("si", $newRole, $targetID);
        $stmt->execute();
        $stmt->close();

        // Notify USER
        sendNotification(
            $conn,
            $targetID,
            "Your moderator privileges have been removed. You are now a normal user.",
            "dashboard.php"
        );

        // Notify ADMIN
        sendNotification(
            $conn,
            $adminID,
            "You demoted {$targetName} back to User.",
            "manage_user.php"
        );

        set_flash_and_redirect("Moderator has been demoted to normal User.", "success");
    }

    // Fallback
    set_flash_and_redirect("Unknown action.", "danger");
}


// -------------------------------------
// FLASH MESSAGE
// -------------------------------------
if (isset($_SESSION['alert_message'])) {
    $message     = $_SESSION['alert_message'];
    $messageType = $_SESSION['alert_type'];
    unset($_SESSION['alert_message'], $_SESSION['alert_type']);
}

// -------------------------------------
// FILTERS + PAGINATION INPUT
// -------------------------------------
$search      = trim($_GET['search'] ?? '');
$roleFilter  = $_GET['role']   ?? 'all';
$statusFilter= $_GET['status'] ?? 'all';

$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 5;
$validPerPage = [5,10,20,50];
if (!in_array($perPage, $validPerPage, true)) {
    $perPage = 5;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// -------------------------------------
// BUILD WHERE CLAUSE
// -------------------------------------
$conditions = [];
$params = [];
$types  = "";

// Search on name, email, role, team
if ($search !== '') {
    $conditions[] = "(CONCAT(u.firstName, ' ', u.lastName) LIKE ? 
                      OR u.email LIKE ? 
                      OR u.role LIKE ? 
                      OR t.teamName LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= "ssss";
}

// Role filter
if ($roleFilter !== 'all') {
    $conditions[] = "u.role = ?";
    $params[] = $roleFilter;
    $types   .= "s";
}

// Status filter
if ($statusFilter !== 'all') {
    $conditions[] = "u.is_Active = ?";
    $params[] = ($statusFilter === 'active') ? 1 : 0;
    $types   .= "i";
}

$whereSql = $conditions ? ("WHERE " . implode(" AND ", $conditions)) : "";

// -------------------------------------
// TOTAL ROW COUNT FOR PAGINATION
// -------------------------------------
$countSql = "
    SELECT COUNT(*) AS total
    FROM user u
    LEFT JOIN team t ON u.teamID = t.teamID
    $whereSql
";

$stmtCount = $conn->prepare($countSql);
if ($types !== "") {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$resultCount = $stmtCount->get_result()->fetch_assoc();
$stmtCount->close();

$totalRows  = (int)($resultCount['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// -------------------------------------
// FETCH USERS FOR CURRENT PAGE
// -------------------------------------
$dataSql = "
    SELECT 
        u.userID, u.firstName, u.lastName, u.email, u.role, u.is_Active,
        u.created_at, u.last_online, u.avatarURL,
        t.teamName
    FROM user u
    LEFT JOIN team t ON u.teamID = t.teamID
    $whereSql
    ORDER BY u.userID DESC
    LIMIT ? OFFSET ?
";

$paramsData = $params;
$typesData  = $types . "ii";
$paramsData[] = $perPage;
$paramsData[] = $offset;

$stmtUsers = $conn->prepare($dataSql);
if ($typesData !== "") {
    $stmtUsers->bind_param($typesData, ...$paramsData);
}
$stmtUsers->execute();
$users = $stmtUsers->get_result();

// -------------------------------------
// HELPER: BUILD PAGE URL
// -------------------------------------
function buildPageUrl($page, $search, $roleFilter, $statusFilter, $perPage) {
    $query = [
        'page'      => $page,
        'search'    => $search,
        'role'      => $roleFilter,
        'status'    => $statusFilter,
        'per_page'  => $perPage
    ];
    return 'manage_user.php?' . http_build_query($query);
}

// -------------------------------------
// LAYOUT HEADER
// -------------------------------------
include "includes/layout_start.php";
?>

<!-- ========================================================= -->
<!-- UI BELOW (unchanged from your version) -->
<!-- ========================================================= -->

<?php
/*  
 * I keep everything below exactly the same as your file.
 * No edits needed because notifications work from the actions at the top.
 */
?>

<style>
.user-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.06);
}

.avatar-sm {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #3b82f6;
}

.badge-status {
    padding: 6px 10px;
    font-size: 12px;
    border-radius: 10px;
    color: white;
}

.status-active { background: #22c55e; }
.status-suspended { background: #f59e0b; }

.collapse-box {
    background: #f3f4f6;
    border-radius: 10px;
}

.btn-collapse {
    width: 36px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}

.btn-collapse i {
    transition: transform 0.25s ease;
    font-size: 18px;
}
</style>

<div class="p-4">

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show shadow-sm">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="user-card">

        <h5 class="fw-bold mb-2">User List</h5>
        <p class="text-muted small mb-4">
            Manage user accounts, roles, and view details.
            <?php if ($totalRows > 0): ?>
                <span class="ms-2">(<?= $totalRows ?> user<?= $totalRows>1?'s':'' ?> found)</span>
            <?php endif; ?>
        </p>

<!-- FILTER BAR -->
<form method="get" class="mb-4 mt-2">
    <div class="row g-2 align-items-end">

        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text"
                   name="search"
                   class="form-control"
                   placeholder="Name, email, role, team..."
                   value="<?= htmlspecialchars($search) ?>">
        </div>

        <div class="col-md-2">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
                <option value="all"       <?= $roleFilter==='all'?'selected':'' ?>>All</option>
                <option value="user"      <?= $roleFilter==='user'?'selected':'' ?>>User</option>
                <option value="moderator" <?= $roleFilter==='moderator'?'selected':'' ?>>Moderator</option>
                <option value="admin"     <?= $roleFilter==='admin'?'selected':'' ?>>Admin</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="all"       <?= $statusFilter==='all'?'selected':'' ?>>All</option>
                <option value="active"    <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
                <option value="suspended" <?= $statusFilter==='suspended'?'selected':'' ?>>Suspended</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">Show per page</label>
            <select name="per_page" class="form-select">
                <?php foreach ([5,10,20,50] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $perPage==$opt?'selected':'' ?>>
                        <?= $opt ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100">Apply</button>
            <a href="manage_user.php" class="btn btn-outline-secondary">Reset</a>
        </div>

    </div>
</form>

<?php if ($totalRows === 0): ?>
    <div class="alert alert-info mb-0">
        No users found for the current filter.
    </div>
<?php else: ?>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th></th>
                <th>ID</th>
                <th>User</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>

        <tbody>
            <?php while ($u = $users->fetch_assoc()):
                $collapseId = "user-" . $u['userID'];

                // Avatar fix
                $avatar = "uploads/default.png";
                if (!empty($u['avatarURL'])) {
                    $filePath = str_replace("upload/", "uploads/", $u['avatarURL']);
                    $fullPath = __DIR__ . "/" . $filePath;
                    if (file_exists($fullPath)) {
                        $avatar = $filePath;
                    }
                }

                $joined = ($u['created_at'] && $u['created_at'] !== '0000-00-00 00:00:00')
                            ? $u['created_at'] : "-";

                $lastActive = ($u['last_online'] && $u['last_online'] !== '0000-00-00 00:00:00')
                            ? $u['last_online'] : "Never";

                $canModify = ($u['userID'] != $userID && $u['role'] !== 'admin');
            ?>
            <tr>
                <td class="text-center">
                    <button class="btn btn-sm btn-light btn-collapse"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?= $collapseId ?>">
                        <i class="bi bi-caret-right-fill"></i>
                    </button>
                </td>

                <td><?= $u['userID'] ?></td>

                <td>
                    <img src="<?= htmlspecialchars($avatar); ?>" class="avatar-sm me-2" alt="Avatar">
                    <?= htmlspecialchars($u['firstName'] . " " . $u['lastName']); ?>
                </td>

                <td><?= htmlspecialchars($u['email']); ?></td>

                <td>
                    <span class="badge bg-secondary">
                        <?= htmlspecialchars(ucfirst($u['role'])); ?>
                    </span>
                </td>

                <td>
                    <span class="badge-status <?= $u['is_Active'] ? 'status-active' : 'status-suspended' ?>">
                        <?= $u['is_Active'] ? "Active" : "Suspended" ?>
                    </span>
                </td>

                <td class="text-end">
                    <?php if ($canModify): ?>

                        <!-- Role actions -->
                        <?php if ($u['role'] === 'user'): ?>
                            <a href="?action=promote&userID=<?= $u['userID'] ?>"
                               class="btn btn-sm btn-outline-primary me-1"
                               title="Promote to Moderator">
                                <iconify-icon icon="solar:star-linear"></iconify-icon>
                            </a>
                        <?php elseif ($u['role'] === 'moderator'): ?>
                            <a href="?action=demote&userID=<?= $u['userID'] ?>"
                               class="btn btn-sm btn-outline-secondary me-1"
                               title="Demote to User">
                                <iconify-icon icon="solar:arrow-down-linear"></iconify-icon>
                            </a>
                        <?php endif; ?>

                        <!-- Status actions -->
                        <?php if ($u['is_Active']): ?>
                            <a href="?action=suspend&userID=<?= $u['userID'] ?>"
                               class="btn btn-sm btn-outline-warning"
                               title="Suspend User">
                                <iconify-icon icon="solar:shield-cross-linear"></iconify-icon>
                            </a>
                        <?php else: ?>
                            <a href="?action=activate&userID=<?= $u['userID'] ?>"
                               class="btn btn-sm btn-outline-success"
                               title="Activate User">
                                <iconify-icon icon="solar:shield-check-linear"></iconify-icon>
                            </a>
                        <?php endif; ?>

                    <?php else: ?>
                        <span class="text-muted small">No actions</span>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- COLLAPSE DETAILS -->
            <tr>
                <td colspan="7" class="p-0 border-0">
                    <div id="<?= $collapseId ?>" class="collapse collapse-box p-3">
                        <div class="row small g-3">

                            <div class="col-md-4">
                                <strong>Team:</strong><br>
                                <span class="badge bg-primary">
                                    <?= htmlspecialchars($u['teamName'] ?: "No Team"); ?>
                                </span>
                            </div>

                            <div class="col-md-4">
                                <strong>Joined:</strong><br>
                                <?= htmlspecialchars($joined) ?>
                            </div>

                            <div class="col-md-4">
                                <strong>Last Active:</strong><br>
                                <?= htmlspecialchars($lastActive) ?>
                            </div>

                        </div>
                    </div>
                </td>
            </tr>

            <?php endwhile; ?>
        </tbody>

    </table>
</div>

<!-- PAGINATION -->
<?php if ($totalPages > 1): ?>
    <nav aria-label="User pagination">
        <ul class="pagination justify-content-end mt-2">

            <!-- Previous -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link"
                   href="<?= $page > 1 ? buildPageUrl($page-1, $search, $roleFilter, $statusFilter, $perPage) : '#' ?>">
                    «
                </a>
            </li>

            <!-- Page numbers -->
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                    <a class="page-link"
                       href="<?= buildPageUrl($i, $search, $roleFilter, $statusFilter, $perPage) ?>">
                        <?= $i ?>
                    </a>
                </li>
            <?php endfor; ?>

            <!-- Next -->
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link"
                   href="<?= $page < $totalPages ? buildPageUrl($page+1, $search, $roleFilter, $statusFilter, $perPage) : '#' ?>">
                    »
                </a>
            </li>

        </ul>
    </nav>
<?php endif; ?>

<?php endif; // totalRows > 0 ?>

    </div>
</div>

<!-- ICON ROTATION SCRIPT -->
<script>
document.addEventListener("DOMContentLoaded", function () {

    document.querySelectorAll(".btn-collapse").forEach(btn => {
        let icon = btn.querySelector("i");
        let target = document.querySelector(btn.getAttribute("data-bs-target"));

        if (!icon || !target) return;

        target.addEventListener("shown.bs.collapse", () => {
            icon.style.transform = "rotate(90deg)";
        });

        target.addEventListener("hidden.bs.collapse", () => {
            icon.style.transform = "rotate(0deg)";
        });
    });

});
</script>

<?php include "includes/layout_end.php"; ?>
