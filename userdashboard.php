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

// --- Status Filter ---
$statusFilter = $_GET['status_filter'] ?? 'all';

$where = "WHERE userid = $userId";

if ($statusFilter !== 'all') {
    $safeStatus = strtolower($conn->real_escape_string($statusFilter));
    $where .= " AND LOWER(status) = '$safeStatus'";
}

// --- Fetch Submission Table Data ---
$sql = "
    SELECT 
        s.submissionID, s.filePath, s.status, s.pointEarned, s.reviewNote,
        c.challengeTitle
    FROM sub s 
    JOIN challenge c ON s.challengeID = c.challengeID
    $where
    ORDER BY s.uploaded_at ASC
";

$result = $conn->query($sql);
$submissions = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $submissions[] = [
            "id"        => $row["submissionID"],
            "photo"     => $row["filePath"],
            "challenge" => $row["challengeTitle"],
            "status"    => $row["status"],
            "points"    => $row["pointEarned"] ?? 0,
            "feedback"  => $row["reviewNote"] ?? ""
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
?>

<!-- Tailwind + Datatable CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simple-datatables@latest/dist/style.css">
<script src="https://cdn.tailwindcss.com"></script>

<div class="container-fluid py-4">

    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-bold">My Challenge Submissions</h2>
            <a href="submissionform.php" class="text-blue-600 hover:underline">Submit New</a>
        </div>

        <!-- Mini Analytics Section -->
        <div class="flex flex-col md:flex-row gap-10 mb-6">
            
            <div class="grid grid-cols-1 gap-4 w-64 md:w-80 lg:w-96 flex-shrink-0">
                <div class="bg-blue-50 p-4 rounded-xl border border-blue-200">
                    <p class="text-sm text-blue-700 font-bold">Total Submissions</p>
                    <h3 class="text-2xl font-bold text-blue-800"><?= $total ?></h3>
                </div>
                <div class="bg-purple-50 p-4 rounded-xl border border-purple-200">
                    <p class="text-sm text-purple-700 font-bold">Total Points Earned (Approved Only)</p>
                    <h3 class="text-2xl font-bold text-purple-800"><?= $points ?></h3>
                </div>
            </div>

            <!-- Donut Chart -->
            <div class="bg-white p-6 rounded-xl shadow flex-1 max-w-md">
                <h3 class="text-lg font-semibold mb-4">Status Breakdown</h3>

                <div class="flex items-center gap-6">
                    <div class="relative w-40 h-40 donut">
                        <div class="absolute inset-0 rounded-full"
                             style="background: conic-gradient(
                                 #22c55e <?= $approvedPercent ?>%, 
                                 #eab308 0 <?= $approvedPercent + $pendingPercent ?>%, 
                                 #ef4444 0
                             );">
                        </div>
                        <div class="absolute inset-4 bg-white rounded-full flex items-center justify-center">
                            <span class="text-xl font-semibold text-neutral-700"><?= $total ?> total</span>
                        </div>
                    </div>

                    <div class="text-sm space-y-2">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                            Approved: <?= $approved ?>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                            Pending: <?= $pending ?>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-red-500"></div>
                            Denied: <?= $denied ?>
                        </div>
                    </div>

                </div>
            </div>

        </div>

        <!-- Filter -->
        <div class="flex justify-end mb-4">
            <label for="status_filter" class="mr-2 font-medium text-gray-700 self-center">Filter Status:</label>
            <select id="status_filter"
                    class="px-3 py-2 border rounded-md shadow-sm"
                    onchange="window.location='userDashboard.php?status_filter='+this.value;">
                <option value="all" <?= $statusFilter=='all'?'selected':'' ?>>All</option>
                <option value="pending" <?= $statusFilter=='pending'?'selected':'' ?>>Pending</option>
                <option value="approved" <?= $statusFilter=='approved'?'selected':'' ?>>Approved</option>
                <option value="denied" <?= $statusFilter=='denied'?'selected':'' ?>>Denied</option>
            </select>
        </div>

        <!-- Table -->
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
                        <th class="py-3 px-4 text-right">Action</th>
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
                            <td class="py-3 px-4"><?= $item["id"] ?></td>

                            <td class="py-3 px-4">
                                <img src="<?= $item["photo"] ?>" 
                                     class="w-32 h-24 object-cover rounded border cursor-pointer"
                                     onclick="openModal('<?= $item["photo"] ?>')" />
                            </td>

                            <td class="py-3 px-4"><?= htmlspecialchars($item["challenge"]) ?></td>
                            <td class="py-3 px-4"><?= statusTag($item["status"]) ?></td>

                            <td class="py-3 px-4">
                                <?php if ($item["status"] == "approved"): ?>
                                    <?= $item["points"] ?>
                                <?php elseif ($item["status"] == "pending"): ?>
                                    <span class="text-yellow-600">Pending</span>
                                <?php else: ?>
                                    <span class="text-red-500">0</span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3 px-4"><?= htmlspecialchars($item["feedback"]) ?></td>

                            <td class="py-3 px-4 text-right">
                                <?php if ($item["status"] === "denied"): ?>
                                    <a href="submissionform.php?id=<?= $item["id"] ?>"
                                       class="px-3 py-1 border border-yellow-500 text-yellow-600 rounded hover:bg-yellow-500 hover:text-white">
                                        Resubmit
                                    </a>
                                <?php elseif ($item["status"] === "approved"): ?>
                                    <a href="userpoints.php"
                                       class="px-3 py-1 border border-green-600 text-green-600 rounded hover:bg-green-600 hover:text-white">
                                        View Points
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="imageModal" class="fixed inset-0 bg-black bg-opacity-70 hidden items-center justify-center z-50">
    <div class="relative bg-white rounded-lg shadow-lg max-w-3xl w-full">
        <button onclick="closeModal()" class="absolute top-2 right-2 text-gray-500 text-2xl">&times;</button>
        <img id="modalImage" class="w-full rounded-b-lg" />
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/simple-datatables@latest"></script>

<script>
function openModal(src) {
    document.getElementById("modalImage").src = src;
    document.getElementById("imageModal").classList.remove("hidden");
}
function closeModal() {
    document.getElementById("imageModal").classList.add("hidden");
}

window.addEventListener("DOMContentLoaded", () => {
    const table = document.getElementById("datatablesSimple");
    if (table) new simpleDatatables.DataTable(table);
});
</script>

<?php include "includes/layout_end.php"; ?>
