<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur'); // for timing
require 'db_connect.php';
require_once "includes/auth.php";  


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'moderator') {
    header("Location: index.php");
    exit;
}


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


    if (!empty($pendingList)) {
        $currentSubmission = $pendingList[0];
    } else {
        $currentSubmission = null; 
    }
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
                (transactionType, pointsTransaction, generate_at, submissionID, userID)
                VALUES ('earn', ?, NOW(), ?, ?)
            ");

            if (!$insert) {
                die("Prepare failed: " . $conn->error);
            }

            $insert->bind_param(
                "iii",
                $pointsToAward,
                $submissionId,
                $submissionUserID
            );

            $insert->execute();
            $insert->close();
        }
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




<div class="bg-neutral-100 p-4 sm:p-6">
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
                    <span id="<?= $key ?>-count">
                        <?= $label ?> (<?= count($filteredSubs) ?>)
                    </span>


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

                    >


                        <img alt="thumbnail" class="w-14 h-14 rounded-lg object-cover flex-shrink-0"
                            src="<?= $submission['thumbnail']; ?>" />
                        <div class="ml-3 flex-1 min-w-0">
                            <div class="flex justify-between items-start">
                                <h4 class="font-medium text-neutral-700 truncate">
                                    <?= $submission['title']; ?>
        
                                </h4>
                              <span
                                    class="status-badge text-xs px-2 py-0.5
                                        bg-<?= $submission['status_class']; ?>/10
                                        text-<?= $submission['status_class']; ?> rounded-full"
                                >
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

                    <!-- Pagination container-->
                      <div class="pagination m-3 flex justify-center gap-2"
                            data-status="<?= $key ?>">
                            <div></div>
                    </div>


                </div>
            </div>
            <?php endforeach; ?>

        </div>
    </div>
</div>

            <div class="lg:col-span-2" id="submission-list">
                <div class="bg-white rounded-xl shadow-card h-full flex flex-col">
                    <div class="p-4 border-b border-neutral-100 flex justify-between items-center">
                        <h3 class="font-medium text-neutral-700">Submission Details</h3>
                        <div class="flex items-center space-x-2">
                            
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto scrollbar-hide p-2 space-y-3">

                    <!-- Placeholder：永远存在 -->
                        <div
                            id="no-submission-placeholder"
                            class="p-8 text-center text-neutral-500"
                            style="<?= $currentSubmission ? 'display:none;' : '' ?>"
                        >
                            <i class="fas fa-inbox text-5xl mb-4 text-neutral-300"></i>

                            <?php if (empty($pendingList)): ?>
                                <p class="text-lg font-bold">All Submissions Reviewed</p>
                                <p class="text-sm">There are no pending submissions to review.</p>
                            <?php else: ?>
                                <p class="text-lg font-medium">No Submission Selected</p>
                                <p class="text-sm">Please select a submission from the list.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Submission details：永远存在 -->
                        <div
                            id="submission-details"
                            style="<?= $currentSubmission ? '' : 'display:none;' ?>"
                        >
                          

                            <!-- submission details START -->

                                    
                    <div class="mb-6">
                        
                        <div class="flex flex-col md:flex-row md:items-start justify-between gap-4 mb-6 pb-6 border-b border-gray-100">
                            <div class="pl-4 border-l-4 border-blue-600 space-y-2">
                                <h2 id="detail-title" class="text-2xl font-extrabold text-gray-800 tracking-tight leading-tight">
                                    <?= $currentSubmission['title']; ?>
                                </h2>

                                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                    <p id="detail-username" class="flex items-center text-sm font-bold text-blue-600">
                                        <i class="fas fa-user-circle mr-2"></i>
                                        <span class="text-gray-500 font-medium mr-1">User:</span> 
                                        <?= $currentSubmission['user']; ?>
                                    </p>

                                    <span class="hidden md:block text-gray-300">|</span>

                                    <p id="detail-type" class="flex items-center text-sm text-gray-400">
                                        <i class="far fa-clock mr-2"></i>
                                        Challenge Submission · <?= $currentSubmission['submit_time']; ?>
                                    </p>
                                </div>
                            </div>

                        </div>

                        <div class="bg-neutral-50 rounded-xl p-4 mb-6 shadow-sm">

                            
                          <div class="flex items-center gap-2 mb-4">
                            <div class="w-1 h-4 bg-blue-500 rounded-full"></div> <h3 class="font-bold text-neutral-700 text-sm uppercase tracking-wide">
                                Challenge Information
                            </h3>
                        </div>
                        

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

                                <div class="md:col-span-3">
                                    <p class="text-xs text-neutral-400 mb-0.5">Description</p>
                                    <p class="text-sm font-medium text-neutral-700 line-clamp-3"
                                        id="challenge-desc">
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs text-neutral-400 mb-0.5">Reward Points</p>
                                    <p class="text-sm font-medium text-neutral-700"
                                        id="challenge-points">
                                    </p>

                                </div>

                            </div>

                        </div>

                       <div class="mb-6">
                            <div class="flex items-center gap-2 mb-4">
                                <div class="w-1 h-4 bg-blue-500 rounded-full"></div> <h3 class="font-bold text-neutral-700 text-sm uppercase tracking-wide">
                                    Submitted Proof
                                </h3>
                            </div>
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
               
             
        <div class="flex items-center gap-2 mb-4">
            <div class="w-1 h-4 bg-blue-500 rounded-full"></div> <h3 class="font-bold text-neutral-700 text-sm uppercase tracking-wide">
                Review actions
            </h3>
        </div>               
        
        <div class="bg-neutral-50 rounded-lg p-4">

            <?php
            $statusKey = strtolower($currentSubmission['status'] ?? 'pending');
                $reviewFormDisplay = ($statusKey === 'pending') ? 'block' : 'none';
                $reviewStaticDisplay = ($statusKey === 'pending') ? 'none' : 'block';

            ?>

            <div id="review-form-block" style="display: <?= $reviewFormDisplay ?>;">
                <form id="review-form">
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
                        <textarea required id="detail-feedback" name="feedback" class="w-full p-3 border border-neutral-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all min-h-[100px]" placeholder="Please Enter Your Feedback..."><?= htmlspecialchars($currentSubmission['feedback'] ?? ''); ?></textarea>
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
   </div>

                

                </div>

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
   


<script id="page-interactions">

const PAGE_SIZE = 5;

const paginationState = {
    pending: 1,
    approved: 1,
    denied: 1
};

    document.addEventListener('DOMContentLoaded', function () {

        initSubmitItemClick();
        initReviewResultRadio();
        initTimeFilterAutoSubmit();

        ['pending','approved','denied'].forEach(status => {
            renderAccordionPage(status);
        });

        const pendingAccordion = Array.from(
            document.querySelectorAll('.accordion-header')
        ).find(h => h.textContent.includes('Pending'));

        if (pendingAccordion) pendingAccordion.click();

        const firstPending = document.querySelector('.submit-item[data-status="Pending"]');
        if (firstPending) firstPending.click();
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

                const placeholder = document.getElementById('no-submission-placeholder');
                const details = document.getElementById('submission-details');

                if (placeholder) placeholder.style.display = 'none';
                if (details) details.style.display = 'block';


            submitItems.forEach(i => i.classList.remove('bg-primary/5','border','border-primary/20'));
            item.classList.add('bg-primary/5','border','border-primary/20');

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

            const detailTitleEl = document.getElementById('detail-title');
            const detailUsernameEl = document.getElementById('detail-username');
            const detailTypeEl = document.getElementById('detail-type');
           
            if (detailTitleEl) detailTitleEl.textContent = title;
            if (detailUsernameEl) detailUsernameEl.textContent = `UserName: ${user}`;
            if (detailTypeEl) detailTypeEl.textContent = `Challenge Submission · Sent at ${submitTime}`;
            if (detailImageEl) {
                detailImageEl.src = thumbnail;

                detailImageEl.setAttribute('onclick', `openModal('${thumbnail}')`);
            }

            if (challengeIdEl) challengeIdEl.textContent = chId;
            if (challengeTitleEl) challengeTitleEl.textContent = chTitle;
            if (challengeDescEl) challengeDescEl.textContent = chDesc;
            if (challengeCityEl) challengeCityEl.textContent = chCity;
            if (challengePointsEl) challengePointsEl.textContent = chPoints;

            if (status === 'pending') {
                reviewFormBlock.style.display = 'block';
                reviewStaticBlock.style.display = 'none';

                submissionIdInput.value = id;
                detailFeedbackTextarea.value = feedback;

                if (status === 'pending') {
                    reviewFormBlock.style.display = 'block';
                    reviewStaticBlock.style.display = 'none';

                    submissionIdInput.value = id;
                   
                    if (isResubmit) {
                        detailFeedbackTextarea.value = '';
                    } else {
                        detailFeedbackTextarea.value = feedback ? feedback : '';
                    }

                    const radios = document.querySelectorAll('#review-form input[name="review_result"]');
                    radios.forEach(r => r.checked = false);
                }

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
                content.style.maxHeight = content.scrollHeight + 'px';
                icon.style.transform = 'rotate(180deg)';
            }
        });
    });

function scrollToSubmissionList() {
    const list = document.getElementById('submission-list');
    if (!list) return;

    list.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });
}



   
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

const searchInput = document.getElementById('search-input');
if (searchInput) {
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            this.closest('form').submit();
        }
    });
}


    function initTimeFilterAutoSubmit() {
    const timeFilterSelect = document.getElementById('time_filter');
   
   
    timeFilterSelect.addEventListener('change', function() {

        this.closest('form').submit();
    });
}


/* =========================
   AJAX Review Submit
========================= */

    const reviewForm = document.getElementById('review-form');

        if (reviewForm) {
        reviewForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(reviewForm);

            fetch('review_submission.php', { method:'POST', body: formData })
            .then(res => res.json())
            .then(data => {
            if (!data.success) {
                alert(data.message || 'Review failed');
                return;
            }

            const item = document.querySelector(`.submit-item[data-id="${data.submission_id}"]`);
            if (!item) return;

            /* =========================
            1️⃣ 更新 item 的 status（关键）
            ========================= */
            const newStatus = data.status.toLowerCase(); // approved / denied
            const statusText = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);

            item.dataset.status = statusText;

            /* =========================
            2️⃣ 更新 badge 文本 & 颜色
            ========================= */
            const badge = item.querySelector('.status-badge');
            if (badge) {
                badge.textContent = statusText;

                badge.className =
                    'status-badge text-xs px-2 py-0.5 rounded-full ' +
                    (newStatus === 'approved'
                        ? 'bg-success/10 text-success'
                        : 'bg-danger/10 text-danger');
            }

            /* =========================
            3️⃣ 更新右侧面板（静态模式）
            ========================= */
            updateRightPanelAfterReview(data);

            /* =========================
            4️⃣ 更新 Accordion 数量
            ========================= */
            updateAccordionCount('pending', -1);
            updateAccordionCount(newStatus, +1);

            /* =========================
            5️⃣ 移动 item 到正确 accordion
            ========================= */
            moveItemToAccordion(item, statusText);

            /* =========================
            6️⃣ 重新分页 & 高度修正
            ========================= */
            ['pending', 'approved', 'denied'].forEach(s => renderAccordionPage(s));

            /* =========================
            7️⃣ 自动选择下一个 pending
            （若没有 → 显示 All Reviewed）
            ========================= */
            autoSelectNextPending();

            /* =========================
            8️⃣ 回到 submission list
            ========================= */
            scrollToSubmissionList();

        })
        .catch(err => {
            console.error(err);
            alert('AJAX request failed. See console.');
        });
    });

}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}


function removeLeftItem(id) {
    const item = document.querySelector(`.submit-item[data-id="${id}"]`);
    if (item) item.remove();
}

function autoSelectNextPending() {
    const pendingItems = Array.from(
        document.querySelectorAll('.submit-item')
    ).filter(item => item.dataset.status === 'Pending');

    if (pendingItems.length > 0) {
        pendingItems[0].click();
    } else {
        document.getElementById('submission-details').style.display = 'none';
        document.getElementById('no-submission-placeholder').style.display = 'block';
    }
}



function updateRightPanelAfterReview(data) {
    const reviewFormBlock = document.getElementById('review-form-block');
    const reviewStaticBlock = document.getElementById('review-static-block');

    reviewFormBlock.style.display = 'none';
    reviewStaticBlock.style.display = 'block';

    const statusText = reviewStaticBlock.querySelector('.static-status-text');
    const feedbackText = reviewStaticBlock.querySelector('.static-feedback-text');

    statusText.textContent = `This submission has been ${data.status}.`;
    statusText.className = `text-sm font-semibold ${
        data.status === 'approved' ? 'text-success' : 'text-danger'
    }`;

    if (data.feedback) {
        feedbackText.textContent = `Moderator feedback: ${data.feedback}`;
        feedbackText.style.display = 'block';
    } else {
        feedbackText.style.display = 'none';
    }
}

function decreasePendingCount() {
    const pendingSpan = document.getElementById('pending-count');
    if (!pendingSpan) return;

    const match = pendingSpan.textContent.match(/\((\d+)\)/);
    if (!match) return;

    let count = parseInt(match[1], 10);
    count = Math.max(0, count - 1);

    pendingSpan.textContent = `Pending (${count})`;
}

function updateAccordionCount(type, delta) {
    const span = document.getElementById(`${type}-count`);
    if (!span) return;
    const match = span.textContent.match(/\((\d+)\)/);
    if (!match) return;
    let count = parseInt(match[1], 10) + delta;
    count = Math.max(0, count);
    span.textContent = `${capitalize(type)} (${count})`;
}

function moveItemToAccordion(item, targetStatus) {
    const headers = document.querySelectorAll('.accordion-header');
    let targetContent = null;
    headers.forEach(header => {
        if (header.textContent.includes(targetStatus)) {
            targetContent = header.nextElementSibling;
        }
    });
    if (!targetContent) return;

    // 仅移动 DOM
    targetContent.appendChild(item);
}
function renderAccordionPage(status) {
        const items = Array.from(
            document.querySelectorAll(`.submit-item[data-status="${capitalize(status)}"]`)
        );

        const page = paginationState[status];
        const start = (page - 1) * PAGE_SIZE;
        const end = start + PAGE_SIZE;

        items.forEach((item, index) => {
            item.style.display = (index >= start && index < end) ? 'flex' : 'none';
        });

        renderPaginationControls(status, items.length);

        const accordion = document
            .querySelector(`.pagination[data-status="${status}"]`)
            ?.closest('.accordion-content');

        if (accordion && accordion.style.maxHeight) {
            accordion.style.maxHeight = accordion.scrollHeight + 'px';
        }


    }


    function renderPaginationControls(status, totalItems) {
        const totalPages = Math.ceil(totalItems / PAGE_SIZE);
        const container = document.querySelector(`.pagination[data-status="${status}"]`);

        if (!container || totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = `
            <button class="px-3 py-1 border rounded flex items-center gap-1"
                ${paginationState[status] === 1 ? 'disabled' : ''}
                onclick="changePage('${status}', -1)">

                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none"
                    viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            <span class="px-2 text-sm">
                Page ${paginationState[status]} / ${totalPages}
            </span>

            <button class="px-3 py-1 border rounded flex items-center gap-1"
                ${paginationState[status] === totalPages ? 'disabled' : ''}
                onclick="changePage('${status}', 1)">


                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none"
                    viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 5l7 7-7 7"/>
                </svg>
            </button>

        `;
    }

    function changePage(status, delta) {
        paginationState[status] += delta;
        renderAccordionPage(status);
    }



</script>
<?php include "includes/layout_end.php"; ?>

</body>
</html>