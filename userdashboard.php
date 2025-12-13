<?php

// =============================================================
// Protect Page (SESSION + BROWSER TOKEN)
// =============================================================
require_once "includes/auth.php";  // <--- REQUIRED FIRST

// Only normal users can access dashboard (admin/mod cannot see dashboard)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'user') {
    header("Location: index.php");
    exit;
}

// Page title for top bar
$pageTitle = "My Submissions";

// Layout must load AFTER auth.php
include "includes/layout_start.php";

date_default_timezone_set('Asia/Kuala_Lumpur');
require_once "db_connect.php";

$userId = $_SESSION['userID'];

// --- FETCH USER NAME FOR GREETING ---
$username = "User"; // Default name
$sql_user_name = "SELECT firstName, lastName FROM user WHERE userID = $userId LIMIT 1";
$res_user_name = $conn->query($sql_user_name);

if ($res_user_name && $res_user_name->num_rows > 0) {
    $user_row = $res_user_name->fetch_assoc();
    $username = htmlspecialchars($user_row['firstName'] . ' ' . $user_row['lastName']);
}
if ($res_user_name) $res_user_name->free();


// --- Status Filter ---
$statusFilter = $_GET['status_filter'] ?? 'all';

// --- Challenge Filter ---
$challengeFilter = $_GET['challenge_filter'] ?? 'all';

$challenges = [];
$sql_fetch_challenges = "
    SELECT DISTINCT c.challengeID, c.challengeTitle
    FROM challenge c
    JOIN sub s ON c.challengeID = s.challengeID
    WHERE s.userID = $userId
    ORDER BY c.challengeTitle ASC
";

$res_challenges = $conn->query($sql_fetch_challenges);

if ($res_challenges && $res_challenges->num_rows > 0) {
    while ($row = $res_challenges->fetch_assoc()) {
        $challenges[] = [
            'id' => $row['challengeID'],
            'name' => $row['challengeTitle']
        ];
    }
}
if ($res_challenges) $res_challenges->free();

// --- Setup WHERE clause for main submission query ---
$where = "WHERE s.userid = $userId"; // Start with user ID constraint

// Apply Status Filter
if ($statusFilter !== 'all') {
    $safeStatus = strtolower($conn->real_escape_string($statusFilter));
    $where .= " AND LOWER(s.status) = '$safeStatus'";
}

// Apply Challenge Filter
if ($challengeFilter !== 'all' && is_numeric($challengeFilter)) {
    // Ensure the filter is treated as an integer and safe
    $safeChallengeID = (int)$challengeFilter;
    $where .= " AND s.challengeID = $safeChallengeID";
}

// --- Fetch Submission Table Data ---

$sql = "
        SELECT
        s.submissionID, s.filePath, s.status, s.pointEarned, s.reviewNote,s.resubmitCount,
        c.challengeTitle,
        c.challengeID      
        FROM sub s
        JOIN challenge c ON s.challengeID = c.challengeID
        $where
        ORDER BY s.uploaded_at ASC
";

$result = $conn->query($sql);
$submissions = [];

// Initialize a counter for display index
$submissionIndex = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $submissionIndex++;

        $submissions[] = [
            "db_id"=> $row["submissionID"],
            "id"=> $submissionIndex,
            "photo"=> $row["filePath"],
            "challenge" => $row["challengeTitle"],
            "status"=> $row["status"],
            "points"=> $row["pointEarned"] ?? 0,
            "feedback"=> $row["reviewNote"] ?? "",
            "challenge_id" => $row["challengeID"], // NEW: Store Challenge ID
            "resubmitCount" => $row["resubmitCount"] ?? 0 
        ];
    }
}
if ($result) $result->free();

// --- Mini Analytics ---
$sql2 = "SELECT status, pointEarned FROM sub WHERE userid = $userId";
$r2 = $conn->query($sql2);

$all = [];
while ($row = $r2->fetch_assoc()) $all[] = $row;
if ($r2) $r2->free();

$total = count($all);
$pending = $approved = $denied = $points = 0;

foreach ($all as $s) {
    $st = strtolower($s["status"]);
    if ($st == "pending") $pending++;
    if ($st == "approved") {
        $approved++;
        $points += (int)$s["pointEarned"];
    }
    if ($st == "denied") $denied++;
}

$approvedPercent = $total ? ($approved / $total * 100) : 0;
$pendingPercent  = $total ? ($pending  / $total * 100) : 0;
$deniedPercent   = $total ? ($denied   / $total * 100) : 0;

// --- Status Tag Function ---
function statusTag($status) {
    $status = strtolower($status);
    if ($status == "pending")  return '<span class="px-2 py-1 text-xs text-yellow-600 bg-yellow-100 rounded">Pending</span>';
    if ($status == "approved") return '<span class="px-2 py-1 text-xs text-green-600 bg-green-100 rounded">Approved</span>';
    if ($status == "denied")   return '<span class="px-2 py-1 text-xs text-red-600 bg-red-100 rounded">Denied</span>';
    return '<span class="px-2 py-1 text-xs text-gray-600 bg-gray-100 rounded">'.htmlspecialchars($status).'</span>';
}

        // --- Build Recent Actions automatically ---
        $sqlLogs = "
            SELECT submissionID, status, approved_at, denied_at
            FROM sub
            WHERE userid = $userId
            AND (
                    (status='approved' AND approved_at IS NOT NULL)
                OR (status='denied' AND denied_at IS NOT NULL)
            )
            ORDER BY
                COALESCE(approved_at, denied_at) DESC
            LIMIT 3;
        ";

        $resLogs = mysqli_query($conn, $sqlLogs);

        $recentActions = [];


        function timeAgo($timestamp) {
            if (!$timestamp || $timestamp === "0000-00-00 00:00:00") return "";

            $time = strtotime($timestamp);
            if ($time <= 0) return ""; 

            $diff = time() - $time;

            if ($diff < 60) return $diff . " seconds ago";
            if ($diff < 3600) return floor($diff/60) . " minutes ago";
            if ($diff < 86400) return floor($diff/3600) . " hours ago";
            return floor($diff/86400) . " days ago";
        }


        if ($resLogs && mysqli_num_rows($resLogs) > 0) {
            while ($row = mysqli_fetch_assoc($resLogs)) {

                if ($row['status'] === 'approved') {
                    $action = "Approved submission #" . $row['submissionID'];
                    $time = timeAgo($row['approved_at']);
                } else {
                    $action = "Denied submission #" . $row['submissionID'];
                    $time = timeAgo($row['denied_at']);
                }

                $recentActions[] = [
                    "action" => $action,
                    "time" => $time
                ];
            }
        }

       
?>

<!-- Tailwind + Datatable CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simple-datatables@latest/dist/style.css">
<script src="https://cdn.tailwindcss.com"></script>

<div class="container-fluid py-4">

    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <div class="flex items-center space-x-3">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-7 h-7 text-blue-500">
                    <path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z" clip-rule="evenodd" />
                </svg>
               
                <h2 class="text-2xl font-extrabold text-gray-800">
                    Hai <span class="text-blue-600"><?= $username ?></span>!
                    <span class="font-medium text-gray-500 text-xl"> Welcome to submission dashboard.</span>
                </h2>
            </div>

           <a href="view.php"
            class="flex items-center px-3 py-1.5 bg-blue-400 text-white font-semibold rounded-lg shadow-sm hover:bg-blue-700 transition duration-150 ease-in-out text-sm">
               
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-1.5">
                    <path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM12.75 9a.75.75 0 0 0-1.5 0v2.25H9a.75.75 0 0 0 0 1.5h2.25v2.25a.75.75 0 0 0 1.5 0v-2.25H15a.75.75 0 0 0 0-1.5h-2.25V9Z" clip-rule="evenodd" />
                </svg>
               
                Submit New Challenge
            </a>

        </div>

        <!-- Mini Analytics Section -->
        <div class="flex flex-col md:flex-row gap-10 mb-3">
           
            <div class="grid grid-cols-1 gap-4 w-64 md:w-80 lg:w-96 flex-shrink-0">
   
        <div class="bg-blue-50 p-4 rounded-xl border border-blue-200 relative pb-14">
            <p class="text-sm text-blue-700 font-bold">Total Submissions</p>
            <h3 class="text-2xl font-bold text-blue-800"><?= $total ?></h3>

        


            








        </div>
       
        <div class="bg-purple-50 p-4 rounded-xl border border-purple-200">
            <p class="text-sm text-purple-700 font-bold">Total Points Earned (Approved Only)</p>
            <h3 class="text-2xl font-bold text-purple-800"><?= $points ?></h3>
        </div>
    </div>

            <div id="chartModal"
     class="fixed inset-0 bg-neutral-700 bg-opacity-50 backdrop-blur-sm flex items-center justify-center hidden z-50 transition-all duration-300">
   
    <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 p-6 transition-all duration-300 scale-95 opacity-0">
       
        <button onclick="closeChartModal()"
                class="absolute top-2 right-2 text-gray-500 hover:text-gray-800 text-3xl font-light leading-none z-10">&times;</button>
       
        <h3 class="text-xl font-bold text-gray-800 mb-6">Submission Status Distribution</h3>

        <div class="flex items-center justify-center gap-6">
           
            <div class="relative w-40 h-40 donut">
                <div class="absolute inset-0 rounded-full"
                     style="background: conic-gradient(
                         #22c55e <?= $approvedPercent ?>%,   #eab308 0 <?= $approvedPercent + $pendingPercent ?>%,  #ef4444 0 );"></div>
                <div class="absolute inset-4 bg-white rounded-full flex items-center justify-center">
                    <span class="text-xl font-semibold text-neutral-700"><?= $total ?> total</span>
                </div>
            </div>

            <div class="text-sm space-y-2">
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full bg-green-500"></div>
                    <span class="font-medium text-green-700">Approved:</span> <?= $approved ?>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                    <span class="font-medium text-yellow-700">Pending:</span> <?= $pending ?>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full bg-red-500"></div>
                    <span class="font-medium text-red-700">Denied:</span> <?= $denied ?>
                </div>
            </div>
        </div>
    </div>
</div>

            <!-- Recent Actions Panel -->
            <div class="bg-white p-2 rounded-xl shadow flex-1 max-w-l">
                <h3 class="text-lg font-semibold mb-4">Recent Actions</h3>

                <div class="space-y-5">
                    <?php foreach ($recentActions as $log): ?>
                        <div class="p-2 bg-neutral-50 rounded border">
                            <p class="text-sm font-medium text-neutral-800">
                                <?= $log["action"] ?>
                            </p>
                            <p class="text-xs text-neutral-500">
                                <?= $log["time"] ?>
                            </p>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($recentActions)): ?>
                        <div class="flex items-center justify-center p-4 rounded-lg bg-neutral-100 border border-dashed border-neutral-300">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-neutral-400 mr-2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            
                            <p class="text-neutral-500 text-sm font-medium">No recent actions yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

           

        </div>

        <!-- Filter -->
        <div class="flex justify-end mb-4 gap-4">

            <label for="challenge_filter" class="mr-2 font-medium text-gray-700 self-center">Filter <small>by</small> Challenge:</label>
            <select id="challenge_filter"
                    class="px-3 py-2 border rounded-md shadow-sm"
                    onchange="window.location='userDashboard.php?status_filter=<?= htmlspecialchars($statusFilter) ?>&challenge_filter='+this.value;">
                <option value="all" <?= $challengeFilter=='all'?'selected':'' ?>>All Challenges</option>
               
                <?php
                // Iterate through the $challenges array fetched in step 1
                foreach ($challenges as $challenge) {
                    $selected = ($challengeFilter == $challenge['id']) ? 'selected' : '';
                    echo "<option value='{$challenge['id']}' {$selected}>" . htmlspecialchars($challenge['name']) . "</option>";
                }
                ?>
            </select>
           
            <label for="status_filter" class="mr-2 font-medium text-gray-700 self-center">Filter <small>by</small> Status:</label>
            <select id="status_filter"
                    class="px-3 py-2 border rounded-md shadow-sm"
                    onchange="window.location='userDashboard.php?challenge_filter=<?= htmlspecialchars($challengeFilter) ?>&status_filter='+this.value;">
                <option value="all" <?= $statusFilter=='all'?'selected':'' ?>>All</option>
                <option value="pending" <?= $statusFilter=='pending'?'selected':'' ?>>Pending</option>
                <option value="approved" <?= $statusFilter=='approved'?'selected':'' ?>>Approved</option>
                <option value="denied" <?= $statusFilter=='denied'?'selected':'' ?>>Denied</option>
            </select>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto bg-white rounded-lg shadow">
<div class="overflow-x-auto bg-white rounded-lg shadow">
    <table class="w-full text-sm" id="datatablesSimple">
        <thead class="bg-gray-50 text-gray-500">
            <tr>
                <th class="py-3 px-4">ID</th>
                <th class="py-3 px-4">Photo</th>
                <th class="py-3 px-4">Challenge</th>
                <th class="py-3 px-4">Status</th>
                <th class="py-3 px-4">Points</th>
                <th class="py-3 px-4">Feedback</th>
                <th class="py-3 px-4 ">Action</th>
            </tr>
        </thead>

        <tbody>
            <?php if (empty($submissions)): ?>
                <tr>
                    <td colspan="7" class="text-center py-4 text-gray-500">No Submissions Found</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($submissions as $item): ?>
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-3 px-4 text-center align-middle"><?= $item["id"] ?></td>

                    <td class="py-3 px-4 text-center align-middle">
                        <img src="<?= $item["photo"] ?>"
                            class="w-32 h-24 object-cover rounded border cursor-pointer"
                            onclick="openModal('<?= $item["photo"] ?>')" />
                    </td>

                    <td class="py-3 px-4 align-middle"><?= htmlspecialchars($item["challenge"]) ?></td>
                    <td class="py-3 px-4 text-center align-middle"><?= statusTag($item["status"]) ?></td>

                    <td class="py-3 px-4 text-center align-middle">
                        <?php if ($item["status"] == "approved"): ?>
                            <?= $item["points"] ?>
                        <?php elseif ($item["status"] == "pending"): ?>
                            <span class="text-yellow-600">Pending</span>
                        <?php else: ?>
                            <span class="text-red-500">0</span>
                        <?php endif; ?>
                    </td>

                    <td class="py-3 px-4 align-middle"><?= htmlspecialchars($item["feedback"]) ?></td>

                <td class="py-3 px-4 ">
                    <?php
                        $status = strtolower($item["status"]);
                        $submissionId = $item["db_id"]; // Use actual submission ID from DB

                        if ($status === "pending") {
                            echo '<span class="text-gray-400">—</span>';
                        }
                        elseif ($status === "denied") {
                            if (($item["resubmitCount"] ?? 0) < 1) {
                                // 允许 resubmit
                                echo '<a href="submissionform.php?id=' . $submissionId . '"
                                        class="inline-block px-4 py-2 text-sm font-medium border border-yellow-500
                                            text-yellow-600 rounded-lg hover:bg-yellow-500 hover:text-white
                                            transition-all duration-200">
                                        Resubmit
                                    </a>';
                            } else {
 
                                echo '<span class="text-red-400">Challenge fail</span>';
                            }
                        }
                        elseif ($status === "approved") {
                            echo '<a href="userpoints.php"
                                    class="inline-block px-4 py-2 text-sm font-medium border border-green-600
                                        text-green-600 rounded-lg hover:bg-green-600 hover:text-white
                                        transition-all duration-200">
                                    View Points
                                </a>';
                        }

                        ?>

                </td>


                </tr>
            <?php endforeach; ?>
        </tbody>

    </table>
</div>

<!-- Modal -->
<div id="imageModal"
     class="fixed inset-0 bg-neutral-700 bg-opacity-50 backdrop-blur-sm flex items-center justify-center hidden z-50 transition-all duration-300">
   
    <div class="relative bg-white rounded-lg shadow-2xl max-w-3xl w-full mx-4 p-4 flex items-center justify-center transition-all duration-300 scale-95 opacity-0">
        <button onclick="closeModal()" class="absolute top-2 right-2 text-gray-500 hover:text-gray-800 text-3xl font-light leading-none z-10">&times;</button>
        <img id="modalImage" class="w-full h-auto max-h-[90vh] object-contain" />
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/simple-datatables@latest"></script>

<script>

    function openModal(imageSrc) {
    const modal = document.getElementById('imageModal');
    const modalContent = modal.querySelector('div.relative');
    const modalImg = document.getElementById('modalImage');

    modalImg.src = imageSrc;
   
    modal.classList.remove("hidden");
    modal.classList.add('opacity-100');
    modalContent.classList.remove('scale-95', 'opacity-0');
    modalContent.classList.add('scale-100', 'opacity-100');
}

function closeModal() {
    const modal = document.getElementById('imageModal');
    const modalContent = modal.querySelector('div.relative');
   
    modal.classList.remove('opacity-100');
    modalContent.classList.remove('scale-100', 'opacity-100');
    modalContent.classList.add('scale-95', 'opacity-0');

    modal.classList.add("hidden");
}


// Chart Modal Functions (Instant Open/Close)
function openChartModal() {
    const modal = document.getElementById('chartModal');
    const modalContent = modal.querySelector('div.relative');

    modal.classList.remove("hidden");
   
    modalContent.classList.remove('scale-95', 'opacity-0');
    modalContent.classList.add('scale-100', 'opacity-100');
}

function closeChartModal() {
    const modal = document.getElementById('chartModal');
    const modalContent = modal.querySelector('div.relative');
   
    modalContent.classList.remove('scale-100', 'opacity-100');
    modalContent.classList.add('scale-95', 'opacity-0');

    modal.classList.add("hidden");
}


// =======================================================
// DOM Ready Listeners
// =======================================================
document.getElementById('imageModal').addEventListener('click', function(e) {
    if (e.target.id === 'imageModal') {
        closeModal();
    }
});

// NEW: Add click listener for chart modal background
document.getElementById('chartModal').addEventListener('click', function(e) {
    if (e.target.id === 'chartModal') {
        closeChartModal();
    }
});


window.addEventListener("DOMContentLoaded", () => {
    // Datatables initialization is here
    const table = document.getElementById("datatablesSimple");
    if (table) new simpleDatatables.DataTable(table);
});
</script>

<?php include "includes/layout_end.php"; ?>