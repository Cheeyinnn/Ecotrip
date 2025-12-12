<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur'); // for timing
require 'db_connect.php';

// moderator_id
$moderatorId = $_SESSION['userID'] ?? 6;


$pageTitle = "Moderator Review";



// --- FILTER INPUTS ---

$searchQuery = trim($_GET['q'] ?? '');

$timeFilter = $_GET['time_filter'] ?? 'all';

$searchPattern = '%' . $searchQuery . '%';



$dateFilter = null;
switch ($timeFilter) {
    case 'today':
        $dateFilter = date('Y-m-d 00:00:00'); //
        break;
    case 'week_ago':
        $dateFilter = date('Y-m-d H:i:s', strtotime('-7 days'));
        break;
    case 'month_ago':
        $dateFilter = date('Y-m-d H:i:s', strtotime('-1 month'));
        break;
    default:
        $timeFilter = 'all';
        $dateFilter = null;
        break;
}


// --- retrieve all the submissions from database---

$sql = "SELECT
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
    JOIN challenge c ON s.challengeID = c.challengeID";


$paramTypes = "";
$bindParams = [];
$whereClause = [];


if (!empty($searchQuery)) {
   
    $whereClause[] = "(u.firstName LIKE ? OR u.lastName LIKE ? OR s.caption LIKE ? OR c.challengeTitle LIKE ? OR c.city LIKE ?)";
    $paramTypes .= "sssss";
   
    $bindParams = [&$searchPattern, &$searchPattern, &$searchPattern, &$searchPattern, &$searchPattern];
}

if ($dateFilter) {

    $whereClause[] = "s.uploaded_at >= ?";
    $paramTypes .= "s";
    $bindParams[] = &$dateFilter;
}


if (!empty($whereClause)) {
    $sql .= " WHERE " . implode(" AND ", $whereClause);
}


$sql .= " ORDER BY s.uploaded_at ASC";


// --- EXECUTE SQL ---
$stmt = $conn->prepare($sql);

if ($stmt === false) {

    die("SQL prepare failed: " . $conn->error);
}


if (!empty($paramTypes)) {

    $stmt->bind_param($paramTypes, ...$bindParams);
}

$stmt->execute();
$res = $stmt->get_result();

$submissions = [];
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
         $submissions[] = [
             'id' => $row['submissionID'],
             'user' => $row['firstName'] . ' ' . $row['lastName'],
             'title' => $row['caption'] ?: 'No Caption',
             'thumbnail' => $row['filePath'] ?: 'placeholder.jpg',
             'status' => ucfirst(strtolower($row['status'])),
             'status_class' => match(strtolower($row['status'])) {
             'pending' => 'warning',
             'approved' => 'success',
             'denied' => 'danger',
             default => 'neutral-400',
           },
           'isResubmit' => strtolower($row['status']) === 'pending' && !empty($row['reviewNote']),
            'feedback' => $row['reviewNote'] ?? '',
             'userId'=> $row['userID'],
             'submit_time' => $row['uploaded_at'],
             'feedback' => $row['reviewNote'] ?? '',
             'challengeID' => $row['challengeID'],
             'challengeTitle' => $row['challengeTitle'],
             'challengeDesc' => $row['description'],
             'challengeCity' => $row['city'],
             'points' => $row['pointAward'],
             ];
    }
}
$stmt->close();


$pendingList = array_values(array_filter($submissions, fn($s) => strtolower($s['status']) === 'pending'));


if (isset($_GET['id'])) {
    $currentId = intval($_GET['id']);
    $currentSubmission = null;
    foreach ($submissions as $sub) {
        if ($sub['id'] == $currentId) {
            $currentSubmission = $sub;
            break;
        }
    }
} else {

    $currentSubmission = $pendingList[0] ?? $submissions[0] ?? null;
}

// total pending submission
$pendingSubmissions = count($pendingList);


// review decision
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submission_id'])) {
    $submissionId = intval($_POST['submission_id']);
    $decision = $_POST['review_result'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');
 
   
    $tempSql = "SELECT s.userID, c.pointAward FROM sub s JOIN challenge c ON s.challengeID = c.challengeID WHERE s.submissionID = ?";
    $tempStmt = $conn->prepare($tempSql);
    $tempStmt->bind_param("i", $submissionId);
    $tempStmt->execute();
    $tempRes = $tempStmt->get_result();
    $tempRow = $tempRes->fetch_assoc();
    $tempStmt->close();
   
    $submissionUserID = $tempRow['userID'] ?? null;
    $challengePoints = $tempRow['pointAward'] ?? 0;

    if (in_array($decision, ['approve', 'reject'])) {

        if ($decision === 'approve') {
            $status = 'Approved';
       
            $pointsToAward = $challengePoints;
            $sql = "UPDATE sub
                    SET status=?, pointEarned=?, reviewNote=?, approved_at=NOW(), denied_at=NULL
                    WHERE submissionID=?";
        } else {
            // reject
            $status = 'Denied';
 
            $pointsToAward = 0;
            $sql = "UPDATE sub
                    SET status=?, pointEarned=?, reviewNote=?, denied_at=NOW(), approved_at=NULL
                    WHERE submissionID=?";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sisi", $status, $pointsToAward, $feedback, $submissionId);
        $stmt->execute();
        $stmt->close();

        if ($decision === 'approve' && $submissionUserID) {
       
            $insert = $conn->prepare("
                INSERT INTO pointtransaction
                (transactionType, pointsTransaction, generate_at, userID)
                VALUES ('earn', ?, NOW(), ?)
            ");
            $insert->bind_param("ii", $pointsToAward, $submissionUserID);
            $insert->execute();
            $insert->close();
        }

    $_SESSION['flash'] = "Submission reviewed successfully.";

    header("Location: moderator.php");
    exit;
    }
}

// ----------------------------------------------------
//  RENDER PAGE
// ----------------------------------------------------
include "includes/layout_start.php";

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Moderator Check</title>
    <script src="https://res.gemcoder.com/js/reload.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <script src="https://cdn.bootcdn.net/ajax/libs/echarts/5.4.3/echarts.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#165DFF',
                        success: '#00B42A',
                        warning: '#FF7D00',
                        danger: '#F53F3F',
                        neutral: {
                            100: '#F2F3F5',
                            200: '#E5E6EB',
                            300: '#C9CDD4',
                            400: '#86909C',
                            500: '#4E5969',
                            600: '#272E3B',
                            700: '#1D2129'
                        }
                    },
                    fontFamily: {
                        inter: ['Inter', 'system-ui', 'sans-serif']
                    },
                    boxShadow: {
                        'card': '0 2px 14px 0 rgba(0, 0, 0, 0.06)',
                        'dropdown': '0 4px 16px 0 rgba(0, 0, 0, 0.08)'
                    }
                }
            }
        };
    </script>
    <style type="text/tailwindcss">
        @layer utilities {
            .content-auto {
                content-visibility: auto;
            }
            .scrollbar-hide {
                -ms-overflow-style: none;
                scrollbar-width: none;
            }
            .scrollbar-hide::-webkit-scrollbar {
                display: none;
            }
            .text-balance {
                text-wrap: balance;
            }
            .transition-bg-opacity {
                transition-property: background-color, opacity;
            }
        }
    </style>
</head>



<body class="font-inter bg-neutral-100 text-neutral-700 min-h-screen flex flex-col">


<main class="flex-1 overflow-y-auto bg-neutral-100 p-4 sm:p-6">
    <div class="container mx-auto">
        <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-[clamp(1.5rem,3vw,2rem)] font-bold text-neutral-700">
                   Moderator Review Panel
                </h2>
                <p class="text-neutral-400 mt-1">
                    Manage users' submitted proof of challenge completion, review them, and process points rewards.
                </p>
            </div>
           
        </div>

         <form class="mb-6 flex flex-wrap items-center justify-between bg-white p-2 rounded-xl shadow-card gap-2" method="get">
           
            <div class="flex-1 min-w-[200px]">
                <input
                     type="text"
                     name="q"
                     placeholder="Search user, title, challenge, or city..."
                     value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES) ?>"
                     class="w-full p-2 border border-neutral-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary transition shadow-sm"
                    id="search-input"     />
            </div>

            <div class="w-full sm:w-auto min-w-[150px]">
                <select name="time_filter" id="time_filter"
                        class="form-select w-full px-3 py-2 border border-neutral-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary transition shadow-sm">
                   
                    <?php $currentFilter = $_GET['time_filter'] ?? 'all'; ?>
                   
                    <option value="all" <?= $currentFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                    <option value="today" <?= $currentFilter === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week_ago" <?= $currentFilter === 'week_ago' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="month_ago" <?= $currentFilter === 'month_ago' ? 'selected' : '' ?>>Last 30 Days</option>
                   
                </select>
            </div>

            <div class="flex gap-2 flex-shrink-0">
               
                <a href="moderator.php" class="flex items-center px-4 py-2 border border-neutral-300 text-red-600 rounded-lg hover:bg-neutral-100 transition shadow-sm">
                    <i class="fas fa-times mr-2"></i> Clear
                </a>
            </div>
        </form>


        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-card h-full flex flex-col">
                    <div class="p-4 border-b border-neutral-100 flex justify-between items-center">
                        <h3 class="font-medium text-neutral-700">Submission List</h3>
                    </div>

                    <div class="flex-1 overflow-y-auto scrollbar-hide p-2 space-y-3">

            <?php
           
            // distribute submissions based on status for accordian
            $statuses = ['pending' => 'Pending', 'approved' => 'Approved', 'denied' => 'Denied'];
            foreach ($statuses as $key => $label):
                $filteredSubs = array_filter($submissions, fn($s) => strtolower($s['status']) === $key);
            ?>
            <div class="bg-neutral-100 rounded-lg">
                <button class="w-full flex justify-between items-center px-4 py-2 font-medium text-neutral-700 focus:outline-none accordion-header">
                    <span><?= $label ?> (<?= count($filteredSubs) ?>)</span>
                    <i class="fas fa-chevron-down transition-transform duration-200"></i>
                </button>

                <div class="accordion-content max-h-0 overflow-hidden transition-all duration-300">
                    <?php foreach ($filteredSubs as $submission): ?>

                    <div class="submit-item p-3 rounded-lg hover:bg-neutral-50 mb-2 cursor-pointer flex"
                        data-id="<?= $submission['id']; ?>"
                        data-title="<?= htmlspecialchars($submission['title']); ?>"
                        data-user="<?= htmlspecialchars($submission['user']); ?>"
                        data-status="<?= $submission['status']; ?>"
                        data-status-class="<?= $submission['status_class']; ?>"
                        data-submit-time="<?= $submission['submit_time']; ?>"
                        data-points="<?= $submission['points']; ?>"
                        data-feedback="<?= htmlspecialchars($submission['feedback']); ?>"
                        data-thumbnail="<?= $submission['thumbnail']; ?>"
                        data-challenge-id="<?= htmlspecialchars($submission['challengeID']); ?>"
                        data-challenge-title="<?= htmlspecialchars($submission['challengeTitle']); ?>"
                        data-challenge-desc="<?= htmlspecialchars($submission['challengeDesc']); ?>"
                        data-challenge-city="<?= htmlspecialchars($submission['challengeCity']); ?>"
                        data-challenge-points="<?= $submission['points']; ?>"
                        data-feedback="<?= htmlspecialchars($submission['feedback']); ?>"
                        data-is-resubmit="<?= $submission['isResubmit'] ? 'true' : 'false'; ?>"
                    >

                        <img alt="thumbnail" class="w-14 h-14 rounded-lg object-cover flex-shrink-0"
                            src="<?= $submission['thumbnail']; ?>" />
                        <div class="ml-3 flex-1 min-w-0">
                            <div class="flex justify-between items-start">
                                <h4 class="font-medium text-neutral-700 truncate">
                                    <?= $submission['title']; ?>
        
                                </h4>
                                <span class="text-xs px-2 py-0.5 bg-<?= $submission['status_class']; ?>/10 text-<?= $submission['status_class']; ?> rounded-full">
                                    <?= $submission['status']; ?>
                                </span>
                            </div>
                            <p class="text-sm text-neutral-500 truncate mt-1">UserName: <?= $submission['user']; ?></p>
                            <div class="flex justify-between items-center mt-2">
                                <span class="text-xs text-neutral-400">Submitted at <?= $submission['submit_time']; ?></span>
                            </div>
                        </div>
                    </div>


                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
    </div>
</div>

            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-card h-full flex flex-col">
                    <div class="p-4 border-b border-neutral-100 flex justify-between items-center">
                        <h3 class="font-medium text-neutral-700">Submission Details</h3>
                        <div class="flex items-center space-x-2">
                            <button class="p-2 rounded-full hover:bg-neutral-100 transition-colors text-neutral-500">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <button class="p-2 rounded-full hover:bg-neutral-100 transition-colors text-neutral-500">
                                <i class="fas fa-print"></i>
                            </button>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto scrollbar-hide p-4">

           <?php if (empty($pendingList)): ?>
                <div class="p-8 text-center text-neutral-500">
                    <i class="fas fa-check-circle text-5xl mb-4 text-green-400"></i>
                    <p class="text-lg font-bold">All Submissions Reviewed</p>
                    <p class="text-sm">There are no more pending submissions to review.</p>
                </div>
            <?php elseif (is_null($currentSubmission)): ?>
                <div class="p-8 text-center text-neutral-500">
                    <i class="fas fa-inbox text-5xl mb-4 text-neutral-300"></i>
                    <p class="text-lg font-medium">No Submissions Found</p>
                    <p class="text-sm">Either the search yielded no results, or there are no submissions to review.</p>
                </div>
            <?php else: ?>
                <div class="mb-6">
                        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                            <div>
                                <h2 id="detail-title" class="text-xl font-bold text-neutral-700">
                                    <?= $currentSubmission['title']; ?>
                                </h2>

                                <p id="detail-username" class="text-l font-semibold text-neutral-500">
                                    UserName: <?= $currentSubmission['user']; ?>
                                </p>

                                <p id="detail-type" class="text-neutral-400 mt-1">
                                    Challenge Submission · Sent at <?= $currentSubmission['submit_time']; ?>
                                </p>
                            </div>
                        </div>

                        <div class="bg-neutral-50 rounded-xl p-4 mb-6 shadow-sm">

                            <h4 class="font-semibold text-neutral-700 mb-3">Challenge Information</h4>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">

                                <div>
                                    <p class="text-xs text-neutral-400 mb-0.5">Challenge ID</p>
                                    <p class="text-sm font-medium text-neutral-700" id="challenge-id">
                                        <?= $currentSubmission['challengeID']; ?>
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs text-neutral-400 mb-0.5">Challenge Title</p>
                                    <p class="text-sm font-medium text-neutral-700" id="challenge-title">
                                        <?= $currentSubmission['challengeTitle']; ?>
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs text-neutral-400 mb-0.5">City</p>
                                    <p class="text-sm font-medium text-neutral-700" id="challenge-city">
                                        <?= $currentSubmission['challengeCity']; ?>
                                    </p>
                                </div>

                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">

                                <div>
                                    <p class="text-xs text-neutral-400 mb-0.5">Description</p>
                                    <p class="text-sm font-medium text-neutral-700" id="challenge-desc">
                                        <?= $currentSubmission['challengeDesc']; ?>
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs text-neutral-400 mb-0.5">Reward Points</p>
                                    <p class="text-sm font-medium text-neutral-700" id="challenge-points">
                                        <?= $currentSubmission['points']; ?>
                                    </p>
                                </div>

                            </div>

                        </div>

                       <div class="mb-6">
                            <label class="font-medium text-neutral-700 mb-3">Submitted Proof</label>
                            <div class="mb-6 flex justify-center">
                                <div class="w-full max-w-md h-64 bg-gray-100 rounded-lg shadow-sm border border-neutral-200 overflow-hidden">
                                    <img id="detail-image"
                                        src="<?= $currentSubmission['thumbnail']; ?>"
                                        onclick="openModal('<?= $currentSubmission['thumbnail']; ?>')"
                                        class="w-full h-full object-cover" />
                                </div>
                            </div>
                        </div>


                    </div>
               

                       
        <h3 class="font-medium text-neutral-700 mb-3">Review actions</h3>
        <div class="bg-neutral-50 rounded-lg p-4">

            <?php
            $statusKey = strtolower($currentSubmission['status'] ?? 'pending');
                $reviewFormDisplay = ($statusKey === 'pending') ? 'block' : 'none';
                $reviewStaticDisplay = ($statusKey === 'pending') ? 'none' : 'block';

            ?>

            <div id="review-form-block" style="display: <?= $reviewFormDisplay ?>;">
                <form id="review-form" method="post">
                    <input type="hidden" id="submission_id_input" name="submission_id" value="<?= $currentSubmission['id']; ?>">
                   
                    <input type="hidden" name="points" value="<?= $currentSubmission['points']; ?>">

                    <div class="mb-4">
                        <label class="block font-semibold text-sm text-neutral-700 mb-2">Review result</label>
                        <div class="flex items-center space-x-4">
                            <label class="inline-flex items-center cursor-pointer">
                                <input class="hidden peer" name="review_result" type="radio" value="approve"/>
                                <span class="w-5 h-5 border border-neutral-300 rounded-full flex items-center justify-center peer-checked:border-success peer-checked:bg-success mr-2">
                                    <i class="fas fa-check text-white text-xs opacity-0 peer-checked:opacity-100"></i>
                                </span>
                                <span class="text-sm text-neutral-700">Approved</span>
                            </label>

                            <label class="inline-flex items-center cursor-pointer">
                                <input class="hidden peer" name="review_result" type="radio" value="reject" />
                                <span class="w-5 h-5 border border-neutral-300 rounded-full flex items-center justify-center peer-checked:border-danger peer-checked:bg-danger mr-2">
                                    <i class="fas fa-times text-white text-xs opacity-0 peer-checked:opacity-100"></i>
                                </span>
                                <span class="text-sm text-neutral-700">Denied</span>
                            </label>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm text-neutral-700 mb-2">Feedback</label>
                        <textarea id="detail-feedback" name="feedback" class="w-full p-3 border border-neutral-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all min-h-[100px]" placeholder="Please Enter Your Feedback..."><?= htmlspecialchars($currentSubmission['feedback'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg
                            hover:bg-green-700 transition-all shadow-sm hover:shadow-md">
                        Submit
                    </button>

                    <button type="reset"
                        class="px-4 py-2 border border-green-600 text-green-600 rounded-lg
                            hover:bg-green-50 transition-all shadow-sm hover:shadow-md">
                        Refresh
                    </button>

                </form>
            </div>

            <div id="review-static-block" style="display: <?= $reviewStaticDisplay ?>;">
               
                <p></p>
                <p class="text-sm static-status-text font-semibold
                    <?php
                        if ($statusKey === 'approved') echo 'text-success';
                        elseif ($statusKey === 'denied') echo 'text-danger';
                        else echo 'text-warning';
                    ?>"
                data-status="<?= strtolower($currentSubmission['status'] ?? 'pending') ?>">
                    This submission has been <?= ucfirst($currentSubmission['status'] ?? 'Pending'); ?>.
                </p>

                <p class="mt-2 text-sm text-neutral-700 static-feedback-text"
                    data-feedback="<?= htmlspecialchars($currentSubmission['feedback'] ?? '') ?>"
                >
                    <?php if(!empty($currentSubmission['feedback'])): ?>
                        Moderator feedback: <?= htmlspecialchars($currentSubmission['feedback']); ?>
                    <?php endif; ?>
                </p>
                <p></p>


            </div>

        </div>
    <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>

   
        <div id="imageModal" class="fixed inset-0 bg-neutral-700 bg-opacity-50 backdrop-blur-sm flex items-center justify-center hidden z-50 transition-bg-opacity duration-300">
            <div class="relative bg-white rounded-lg shadow-2xl max-w-3xl w-full mx-4 transition-transform duration-300 scale-95 opacity-0">
                <button onclick="closeModal()" class="absolute top-2 right-2 text-gray-500 hover:text-gray-800 text-3xl font-light leading-none z-10">&times;</button>
                <img id="modalImage" src="" class="w-full h-auto rounded-lg">
            </div>
        </div>
   
</main>

<script id="page-interactions">

    document.addEventListener('DOMContentLoaded', function () {
    initSubmitItemClick();
    initReviewResultRadio();
    initTimeFilterAutoSubmit();

    // 首次加载时点击第一个 pending item
    const firstPending = document.querySelector('.accordion-content div[data-status="Pending"]');
    const firstAny = document.querySelector('.submit-item');
   
    if (firstPending) {
         firstPending.click();
    } else if (firstAny) {
         firstAny.click();
    }
});



function initSubmitItemClick() {
    const submitItems = document.querySelectorAll('.submit-item');

    const reviewFormBlock = document.getElementById('review-form-block');
    const reviewStaticBlock = document.getElementById('review-static-block');
    const submissionIdInput = document.getElementById('submission_id_input');
    const detailFeedbackTextarea = document.getElementById('detail-feedback');

    // Collect Challenge Info DOM
    const challengeIdEl = document.getElementById('challenge-id');
    const challengeTitleEl = document.getElementById('challenge-title');
    const challengeDescEl = document.getElementById('challenge-desc');
    const challengeCityEl = document.getElementById('challenge-city');
    const challengePointsEl = document.getElementById('challenge-points');
    const detailImageEl = document.getElementById('detail-image');

    submitItems.forEach(item => {
        item.addEventListener('click', () => {
            // 左侧高亮
            submitItems.forEach(i => i.classList.remove('bg-primary/5','border','border-primary/20'));
            item.classList.add('bg-primary/5','border','border-primary/20');

            // 从 data-* 读取（注意：dataset 用 camelCase）
            const id = item.dataset.id;
            const title = item.dataset.title || 'No Caption';
            const user = item.dataset.user || '';
            const status = (item.dataset.status || '').toLowerCase();
            const points = item.dataset.points || '0';
            const feedback = item.dataset.feedback || '';
            const thumbnail = item.dataset.thumbnail || 'placeholder.jpg';
            const submitTime = item.dataset.submitTime || 'N/A';
            const chId = item.dataset.challengeId || 'N/A';
            const chTitle = item.dataset.challengeTitle || 'N/A';
            const chDesc = item.dataset.challengeDesc || 'N/A';
            const chCity = item.dataset.challengeCity || 'N/A';
            const chPoints = item.dataset.challengePoints || points || '0';

            // 更新右侧顶部基本 info
            const detailTitleEl = document.getElementById('detail-title');
            const detailUsernameEl = document.getElementById('detail-username');
            const detailTypeEl = document.getElementById('detail-type');
           
            if (detailTitleEl) detailTitleEl.textContent = title;
            if (detailUsernameEl) detailUsernameEl.textContent = `UserName: ${user}`;
            if (detailTypeEl) detailTypeEl.textContent = `Challenge Submission · Sent at ${submitTime}`;
            if (detailImageEl) {
                detailImageEl.src = thumbnail;
                // 更新 modal 的 onclick 事件
                detailImageEl.setAttribute('onclick', `openModal('${thumbnail}')`);
            }

            // 更新 challenge info（下方五个块）
            if (challengeIdEl) challengeIdEl.textContent = chId;
            if (challengeTitleEl) challengeTitleEl.textContent = chTitle;
            if (challengeDescEl) challengeDescEl.textContent = chDesc;
            if (challengeCityEl) challengeCityEl.textContent = chCity;
            if (challengePointsEl) challengePointsEl.textContent = chPoints;

            // 根据状态切换表单 / static block
            if (status === 'pending') {
                reviewFormBlock.style.display = 'block';
                reviewStaticBlock.style.display = 'none';

                submissionIdInput.value = id;
                detailFeedbackTextarea.value = feedback;

                const isResubmit = item.dataset.isResubmit === 'true'; // dataset 属性需要加上 data-is-resubmit="true"

                if (status === 'pending') {
                    reviewFormBlock.style.display = 'block';
                    reviewStaticBlock.style.display = 'none';

                    submissionIdInput.value = id;
                   
                    // 对 resubmit 清空 feedback
                    if (isResubmit) {
                        detailFeedbackTextarea.value = '';
                    } else {
                        detailFeedbackTextarea.value = feedback ? feedback : '';
                    }

                    // 清 radio
                    const radios = document.querySelectorAll('#review-form input[name="review_result"]');
                    radios.forEach(r => r.checked = false);
                }

                // 清 radio
                const radios = document.querySelectorAll('#review-form input[name="review_result"]');
                radios.forEach(r => r.checked = false);

            } else {
                reviewFormBlock.style.display = 'none';
                reviewStaticBlock.style.display = 'block';

                const statusColors = {
                    'pending': 'text-warning',
                    'approved': 'text-success',
                    'denied': 'text-danger'
                };

                const staticStatus = reviewStaticBlock.querySelector('.static-status-text');
                const staticFeedback = reviewStaticBlock.querySelector('.static-feedback-text');

                if (staticStatus) {
                    staticStatus.textContent = `This submission has been ${status.charAt(0).toUpperCase() + status.slice(1)}.`;
                    staticStatus.classList.remove('text-warning','text-success','text-danger','text-neutral-400');
                    staticStatus.classList.add(statusColors[status] || 'text-neutral-400');
                }

                if (staticFeedback) {
                    if (feedback && feedback.trim() !== '') {
                        staticFeedback.textContent = `Moderator feedback: ${feedback}`;
                        staticFeedback.style.display = 'block';
                    } else {
                        staticFeedback.textContent = '';
                        staticFeedback.style.display = 'none';
                    }
                }
            }
        });
    });
}

    function initReviewResultRadio() {
        var radioButtons = document.querySelectorAll('input[name="review_result"]');
       
        radioButtons.forEach(function (radio) {
            radio.addEventListener('change', function () {
                // Future logic for points/rejection reason display goes here.
            });
        });
    }

    // Accordion toggle
    document.querySelectorAll('.accordion-header').forEach(header => {
        header.addEventListener('click', () => {
            const content = header.nextElementSibling;
            const icon = header.querySelector('i');

            // Toggle logic
            if(content.style.maxHeight && content.style.maxHeight !== '0px') {
                content.style.maxHeight = null;
                icon.style.transform = 'rotate(0deg)';
            } else {
                // 计算实际高度并应用
                content.style.maxHeight = content.scrollHeight + 'px';
                icon.style.transform = 'rotate(180deg)';
            }
        });
    });

    // default open Pending accordian after web refresh
    window.addEventListener('DOMContentLoaded', () => {
        const pendingAccordion = document.querySelector('.accordion-header');
        if (pendingAccordion) {
             // 仅在初始状态下点击，确保用户搜索后不会自动关闭
             if (pendingAccordion.textContent.includes('Pending')) {
                pendingAccordion.click();
             }
        }
    });

   
    //close the modal if the user clicks outside the image
    document.getElementById('imageModal').addEventListener('click', function(e) {
        if (e.target.id === 'imageModal') {
            closeModal();
        }
    });

    function openModal(imageSrc) {
    const modal = document.getElementById('imageModal');
    const modalContent = modal.querySelector('div'); // The inner div
    const modalImg = document.getElementById('modalImage');

    modalImg.src = imageSrc;

    modal.classList.remove('hidden');


    setTimeout(() => {
        modalContent.classList.remove('scale-95', 'opacity-0');
        modalContent.classList.add('scale-100', 'opacity-100');
    }, 10);
}

//Click the × button to close
function closeModal() {
    const modal = document.getElementById('imageModal');
    const modalContent = modal.querySelector('div'); // The inner div

    modalContent.classList.remove('scale-100', 'opacity-100');
    modalContent.classList.add('scale-95', 'opacity-0');
   

    setTimeout(() => {
        modal.classList.add('hidden');
    }, 10);
}

    document.getElementById('search-input').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
           
            e.preventDefault();
           
            this.closest('form').submit();
        }
    });

    function initTimeFilterAutoSubmit() {
    const timeFilterSelect = document.getElementById('time_filter');
   
   
    timeFilterSelect.addEventListener('change', function() {

        this.closest('form').submit();
    });
}

</script>

<?php include "includes/layout_end.php"; ?>

</body>
</html>