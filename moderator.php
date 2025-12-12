<?php
$pageTitle = "Moderator Panel";
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require "db_connect.php";

// Protect page: Only moderators allowed
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'moderator') {
    header("Location: index.php");
    exit;
}

$moderatorId = $_SESSION['userID'];

// ----------------------------------------------------
//  FILTER INPUTS
// ----------------------------------------------------
$searchQuery = trim($_GET['q'] ?? '');
$timeFilter  = $_GET['time_filter'] ?? 'all';
$searchPattern = '%' . $searchQuery . '%';

$dateFilter = null;
switch ($timeFilter) {
    case 'today':
        $dateFilter = date('Y-m-d 00:00:00');
        break;
    case 'week_ago':
        $dateFilter = date('Y-m-d H:i:s', strtotime('-7 days'));
        break;
    case 'month_ago':
        $dateFilter = date('Y-m-d H:i:s', strtotime('-30 days'));
        break;
}

$sql = "
SELECT 
    s.submissionID,
    s.userID,
    u.firstName,
    u.lastName,
    s.originalName,
    s.filePath,
    s.caption,
    s.status,
    s.uploaded_at,
    s.reviewNote,
    c.challengeID,
    c.challengeTitle,
    c.description,
    c.city,
    c.pointAward
FROM sub s
JOIN user u ON s.userID = u.userID
JOIN challenge c ON s.challengeID = c.challengeID
";

$where = [];
$paramTypes = "";
$params = [];

// Search
if ($searchQuery !== "") {
    $where[] = "(u.firstName LIKE ? OR u.lastName LIKE ? OR s.caption LIKE ? OR c.challengeTitle LIKE ? OR c.city LIKE ?)";
    $paramTypes .= "sssss";
    $params = array_merge($params, array_fill(0,5,$searchPattern));
}

// Time filter
if ($dateFilter) {
    $where[] = "s.uploaded_at >= ?";
    $paramTypes .= "s";
    $params[] = $dateFilter;
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY s.uploaded_at ASC";

// Execute
$stmt = $conn->prepare($sql);
if ($paramTypes !== "") {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

// ----------------------------------------------------
//  PARSE SUBMISSIONS
// ----------------------------------------------------
$submissions = [];
while ($row = $res->fetch_assoc()) {
    $statusLower = strtolower($row['status']);
    $statusClass = match($statusLower) {
        'pending' => 'warning',
        'approved' => 'success',
        'denied' => 'danger',
        default => 'neutral-400'
    };

    $submissions[] = [
        'id' => $row['submissionID'],
        'user' => $row['firstName'] . ' ' . $row['lastName'],
        'title' => $row['caption'] ?: 'No Caption',
        'thumbnail' => $row['filePath'] ?: 'placeholder.jpg',
        'status' => ucfirst($statusLower),
        'status_class' => $statusClass,
        'userId' => $row['userID'],
        'submit_time' => $row['uploaded_at'],
        'feedback' => $row['reviewNote'],
        'challengeID' => $row['challengeID'],
        'challengeTitle' => $row['challengeTitle'],
        'challengeDesc' => $row['description'],
        'challengeCity' => $row['city'],
        'points' => $row['pointAward']
    ];
}

$stmt->close();

// Pending first
$pendingList = array_values(array_filter($submissions, fn($s) => strtolower($s['status']) === 'pending'));

// Default selected submission
$currentSubmission = null;
if (isset($_GET['id'])) {
    foreach ($submissions as $s) {
        if ($s['id'] == $_GET['id']) {
            $currentSubmission = $s;
            break;
        }
    }
}
if (!$currentSubmission) {
    $currentSubmission = $pendingList[0] ?? $submissions[0] ?? null;
}

// ----------------------------------------------------
//  HANDLE REVIEW ACTION
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submission_id'])) {
    $submissionId = (int)$_POST['submission_id'];
    $decision     = $_POST['review_result'] ?? '';
    $feedback     = trim($_POST['feedback']);

    // Get user + points
    $meta = $conn->prepare("
        SELECT s.userID, c.pointAward 
        FROM sub s 
        JOIN challenge c ON s.challengeID = c.challengeID
        WHERE submissionID=?
    ");
    $meta->bind_param("i", $submissionId);
    $meta->execute();
    $metaData = $meta->get_result()->fetch_assoc();
    $meta->close();

    $submissionUser = $metaData['userID'];
    $challengePoints = (int)$metaData['pointAward'];

    if ($decision === "approve" || $decision === "reject") {

        if ($decision === "approve") {
            $status = "Approved";
            $points = $challengePoints;
            $sql = "UPDATE sub SET status=?, pointEarned=?, reviewNote=?, approved_at=NOW(), denied_at=NULL WHERE submissionID=?";
        } else {
            $status = "Denied";
            $points = 0;
            $sql = "UPDATE sub SET status=?, pointEarned=?, reviewNote=?, denied_at=NOW(), approved_at=NULL WHERE submissionID=?";
        }

        $st = $conn->prepare($sql);
        $st->bind_param("sisi", $status, $points, $feedback, $submissionId);
        $st->execute();
        $st->close();

        if ($decision === "approve") {
            $add = $conn->prepare("
                INSERT INTO pointtransaction (transactionType, pointsTransaction, generate_at, userID)
                VALUES ('earn', ?, NOW(), ?)
            ");
            $add->bind_param("ii", $points, $submissionUser);
            $add->execute();
            $add->close();
        }

        $_SESSION['flash_success'] = "Review submitted successfully.";
        header("Location: moderator.php");
        exit;
    }
}

// ----------------------------------------------------
//  RENDER PAGE
// ----------------------------------------------------
include "includes/layout_start.php";
?>


<!-- Tailwind + FontAwesome -->
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold">Moderator Review Panel</h1>
        <p class="text-neutral-500">Review submissions from users and award points.</p>
    </div>

    <!-- FILTER FORM -->
    <form method="get" class="flex flex-wrap items-center gap-3 bg-white p-4 rounded-xl shadow mb-6">

        <input type="text" name="q" placeholder="Search user, caption, challenge..."
            value="<?= htmlspecialchars($searchQuery) ?>"
            class="flex-1 min-w-[200px] p-2 border rounded-lg"/>

        <select name="time_filter" class="p-2 border rounded-lg">
            <option value="all"      <?= $timeFilter=='all'?'selected':'' ?>>All Time</option>
            <option value="today"    <?= $timeFilter=='today'?'selected':'' ?>>Today</option>
            <option value="week_ago" <?= $timeFilter=='week_ago'?'selected':'' ?>>Last 7 Days</option>
            <option value="month_ago"<?= $timeFilter=='month_ago'?'selected':'' ?>>Last 30 Days</option>
        </select>

        <button class="px-4 py-2 bg-blue-600 text-white rounded-lg">Filter</button>

        <a href="moderator.php" class="px-4 py-2 border rounded-lg">Clear</a>
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- LEFT SIDE: Submission List -->
        <div class="bg-white rounded-xl shadow h-full flex flex-col">
            <div class="p-4 font-bold border-b">Submissions</div>
            <div class="flex-1 overflow-y-auto p-3 space-y-4">

                <?php
                $statuses = ['pending'=>'Pending','approved'=>'Approved','denied'=>'Denied'];

                foreach ($statuses as $key=>$label):
                    $group = array_filter($submissions, fn($s)=>strtolower($s['status'])==$key);
                ?>
                <div class="border rounded-lg">
                    <button class="w-full px-4 py-2 flex justify-between items-center accordion-header">
                        <span><?= $label ?> (<?= count($group) ?>)</span>
                        <i class="fa fa-chevron-down"></i>
                    </button>

                    <div class="accordion-content max-h-0 overflow-hidden transition-all">
                        <?php foreach ($group as $s): ?>
                        <div class="submit-item p-3 hover:bg-gray-50 cursor-pointer flex gap-3 border-b"
                            data-id="<?= $s['id'] ?>"
                            data-user="<?= htmlspecialchars($s['user']) ?>"
                            data-title="<?= htmlspecialchars($s['title']) ?>"
                            data-status="<?= $s['status'] ?>"
                            data-thumbnail="<?= $s['thumbnail'] ?>"
                            data-submit-time="<?= $s['submit_time'] ?>"
                            data-points="<?= $s['points'] ?>"
                            data-feedback="<?= htmlspecialchars($s['feedback']) ?>"
                            data-challenge-id="<?= $s['challengeID'] ?>"
                            data-challenge-title="<?= htmlspecialchars($s['challengeTitle']) ?>"
                            data-challenge-desc="<?= htmlspecialchars($s['challengeDesc']) ?>"
                            data-challenge-city="<?= htmlspecialchars($s['challengeCity']) ?>"
                            data-challenge-points="<?= $s['points'] ?>"
                        >
                            <img src="<?= $s['thumbnail'] ?>" class="w-14 h-14 rounded object-cover"/>
                            <div class="flex-1">
                                <div class="font-semibold truncate"><?= $s['title'] ?></div>
                                <div class="text-xs text-gray-500"><?= $s['user'] ?></div>
                                <div class="text-xs text-gray-400"><?= $s['submit_time'] ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

            </div>
        </div>

        <!-- RIGHT SIDE: Submission Details -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow p-4">

            <?php if (!$currentSubmission): ?>
                <p class="text-center text-gray-500 py-10">No submissions available.</p>

            <?php else: ?>
                <h2 class="text-xl font-bold mb-2"><?= $currentSubmission['title'] ?></h2>
                <p class="text-gray-600 mb-4">User: <?= $currentSubmission['user'] ?></p>

                <img src="<?= $currentSubmission['thumbnail'] ?>"
                     onclick="openModal('<?= $currentSubmission['thumbnail'] ?>')"
                     class="w-full max-w-xl mx-auto rounded shadow mb-4"/>

                <h3 class="font-semibold mb-1">Challenge Info</h3>
                <p><strong>City:</strong> <?= $currentSubmission['challengeCity'] ?></p>
                <p><strong>Description:</strong> <?= $currentSubmission['challengeDesc'] ?></p>
                <p><strong>Reward:</strong> <?= $currentSubmission['points'] ?> pts</p>

                <hr class="my-4"/>

                <!-- Review Form -->
                <?php if (strtolower($currentSubmission['status']) === 'pending'): ?>

                <form method="post" class="space-y-3">
                    <input type="hidden" name="submission_id" value="<?= $currentSubmission['id'] ?>">

                    <label class="font-semibold">Decision</label>
                    <div class="flex gap-4">
                        <label><input type="radio" name="review_result" value="approve"> Approve</label>
                        <label><input type="radio" name="review_result" value="reject"> Reject</label>
                    </div>

                    <label class="font-semibold">Feedback</label>
                    <textarea name="feedback" class="w-full border rounded p-2"
                              placeholder="Write feedback..."><?= $currentSubmission['feedback'] ?></textarea>

                    <button class="px-4 py-2 bg-green-600 text-white rounded">Submit</button>
                </form>

                <?php else: ?>

                <p class="font-bold">
                    Status: <?= $currentSubmission['status'] ?>
                </p>
                <?php if ($currentSubmission['feedback']): ?>
                    <p class="mt-2">Moderator Feedback: <?= htmlspecialchars($currentSubmission['feedback']) ?></p>
                <?php endif; ?>

                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>


<!-- Image Modal -->
<div id="imageModal"
     class="fixed inset-0 bg-black bg-opacity-70 hidden justify-center items-center z-50">
    <div class="relative bg-white rounded-lg p-4 shadow-xl max-w-3xl">
        <button onclick="closeModal()" class="absolute top-2 right-2 text-gray-500 text-2xl">&times;</button>
        <img id="modalImage" class="rounded max-h-[80vh] mx-auto"/>
    </div>
</div>


<script>
// Image Modal JS
function openModal(src) {
    modalImage.src = src;
    imageModal.classList.remove("hidden");
}
function closeModal() {
    imageModal.classList.add("hidden");
}

// Accordion
document.querySelectorAll(".accordion-header").forEach(head => {
    head.addEventListener("click", () => {
        const content = head.nextElementSibling;
        content.style.maxHeight = content.style.maxHeight ? null : content.scrollHeight + "px";
    });
});
</script>

<?php include "includes/layout_end.php"; ?>
