<?php

session_start();
include("db_connect.php");

if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['userID'];

// --- Fetch existing submission if coming from Resubmit link ---
$submissionId = $_GET['id'] ?? null;
$existingSubmission = null;

if ($submissionId) {
    $stmt = $conn->prepare("SELECT * FROM sub WHERE submissionID = ? AND userID = ?");
    $stmt->bind_param("ii", $submissionId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existingSubmission = $result->fetch_assoc();
    $stmt->close();

    // Set challengeID for sidebar from this submission
    if ($existingSubmission) {
        $_SESSION['challengeID'] = $existingSubmission['challengeID'];
    }
}

// --------------------  challengeID --------------------
$challengeID = $_SESSION['challengeID'] ?? null;

if (!$challengeID) {
    $_SESSION['flash'] = 'Please select a challenge first.';
    header("Location: view.php");
    exit;
}

// --------------------  challenge  --------------------
$stmt = $conn->prepare("SELECT challengeTitle, description FROM challenge WHERE challengeID = ?");
$stmt->bind_param("i", $challengeID);
$stmt->execute();
$stmt->bind_result($dbChallengeName, $dbChallengeRule);
$stmt->fetch();
$stmt->close();

// Page title for top bar
$pageTitle = "Submissions Form";
// Layout must load AFTER auth check
include "includes/layout_start.php";

// ---------------------------- Retrieve existing submission ---------------------- //
$submissionId = $_GET['id'] ?? null;
$existingSubmission = null;

if ($submissionId) {
    $stmt = $conn->prepare("SELECT * FROM sub WHERE submissionID = ? AND userID = ?");
    $stmt->bind_param("ii", $submissionId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existingSubmission = $result->fetch_assoc();
    $stmt->close();
}

//---------------------------- process of uploading picture ---------------------- //
$uploadResults = []; // for saving the result message

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $uploadDir = "photoProofs/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $file = $_FILES['photo'];
    $fileName = $file['name'];
    $fileTmp  = $file['tmp_name'];
    $fileSize = $file['size'];
    $fileError = $file['error'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowedTypes = ['jpeg', 'png', 'jpg'];
    $maxSize = 10 * 1024 * 1024; //10MB

    if ($fileError === 4) {
        $uploadResults[] = "Error: Please Upload a File.";
    } else {

        if (!in_array($fileExt, $allowedTypes)) {
            $uploadResults[] = "Error: You cannot upload ".$fileName." due to format error.";
        } elseif ($fileError !== 0) {
            $uploadResults[] = "Error: There was an error when uploading ".$fileName.".";
        } elseif ($fileSize > $maxSize) {
            $uploadResults[] = "Error: Your ".$fileName." exceed 10MB limit!";
        } else {
            $fileHash = md5_file($fileTmp);
            $duplicateFound = false;
            $existingFiles = glob($uploadDir . '*');

            foreach ($existingFiles as $existingFile) {
                if (md5_file($existingFile) === $fileHash) {
                    $duplicateFound = true;
                    break;
                }
            }

            if ($duplicateFound) {
                $uploadResults[] = "Error: This file already exists!";
            } else {
                $caption = $_POST['caption'];
                $fileNewName = uniqid('', true).'.'. $fileExt;
                $savePath = $uploadDir . $fileNewName;

                if (move_uploaded_file($fileTmp, $savePath)) {
                    
                    if ($existingSubmission) {
                        // ------------------ 限制每个 submission 只能 resubmit 一次 ------------------
                        $resubmitCount = $existingSubmission['resubmitCount'] ?? 0;

                        if ($resubmitCount >= 1) {
                            $uploadResults[] = "Error: You have already resubmitted this submission once.";
                        } else {
                            $newResubmitCount = $resubmitCount + 1;

                            // ------------------ renew submission and challengeID ------------------
                            $stmt = $conn->prepare("
                                UPDATE sub 
                                SET 
                                    caption=?, 
                                    fileName=?, 
                                    filePath=?, 
                                    fileHash=?, 
                                    originalName=?, 
                                    status='pending',
                                    reviewNote = NULL,   
                                    pointEarned = 0,     
                                    approved_at = NULL,  
                                    denied_at = NULL,   
                                    challengeID=?,
                                    resubmitCount=?
                                WHERE submissionID=?
                            ");
                            $stmt->bind_param("ssssiiii", $caption, $fileNewName, $savePath, $fileHash, $fileName, $challengeID, $newResubmitCount, $submissionId);
                            $stmt->execute();
                            $stmt->close();

                            $uploadResults[] = "Success: $fileName updated successfully. It is now pending review.";

                            $existingSubmission['filePath'] = $savePath;
                            $existingSubmission['caption'] = $caption;
                            $existingSubmission['resubmitCount'] = $newResubmitCount;
                        }

                    } else {
                        // ------------------  submission  challengeID ------------------
                        $stmt = $conn->prepare("
                            INSERT INTO sub (userID, fileName, filePath, fileHash, originalName, caption, status, challengeID, resubmitCount)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $status = "pending";
                        $resubmitCount = 0; // 新提交初始化为0
                        $stmt->bind_param("issssssii", $userId, $fileNewName, $savePath, $fileHash, $fileName, $caption, $status, $challengeID, $resubmitCount);
                        $stmt->execute();
                        $submissionId = $conn->insert_id;
                        $stmt->close();

                        $uploadResults[] = "Success: $fileName uploaded successfully. It is now pending review.";
                    }

                } else {
                    $uploadResults[] = "Error: $fileName upload failed";
                }
            }
        }
    }
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Challenge Photo</title>
    <link href="upload.css" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>

</head>


<body class="bg-gray-100">

<div class="max-w-7xl mx-auto p-6">

    <!-- Title -->
    <header class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">Challenge Photo Upload</h1>
        <p class="text-gray-500 mt-1">Upload on-site photos for administrator approval.</p>
    </header>

    <div class="flex flex-col lg:flex-row gap-8">

        <!-- LEFT: SIDEBAR -->
        <aside class="lg:w-1/3 space-y-6">
            
          <div class="bg-white rounded-xl shadow p-6 border border-gray-100 space-y-5">

              <h2 class="text-lg font-semibold text-gray-800">Challenge Submission</h2>

              <div>
                  <p class="text-xs font-semibold text-blue-600 mb-1">Challenge Info</p>  <!-- Challenge Title -->
                  <div class="p-3 bg-blue-50 border border-blue-100 rounded-lg text-gray-800 font-medium">
                      <?= htmlspecialchars($dbChallengeName) ?>
                  </div>
              </div>

              <!-- Challenge Description -->
              <div>
                  <p class="text-xs font-semibold text-yellow-600 mb-1">Rules & How-to</p>
                  <div class="p-3 bg-yellow-50 border border-yellow-100 rounded-lg text-gray-800 font-medium">
                      <?= htmlspecialchars($dbChallengeRule) ?>
                  </div>
              </div>

          </div>

          <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="bg-gray-50 px-6 py-3 border-b border-gray-100 flex justify-between items-center">
                        <h2 class="font-bold text-gray-800">Required Format</h2>
                        <i class="fa-solid fa-circle-exclamation text-yellow-500"></i>
                    </div>
                    
                    <div class="p-5">
                        <div class="bg-slate-800 rounded-lg w-full aspect-video relative flex items-center justify-center mb-4 shadow-inner border border-slate-700">
                            <span class="text-slate-500 font-medium">Your Photo Here</span>
                            
                            <div class="absolute bottom-3 right-3 text-right">
                                <p class="text-yellow-400 font-mono text-xs font-bold leading-tight drop-shadow-md">
                                    2025-12-11 09:30:15<br>
                                    Perak, Malaysia<br>
                                    Lat: 4.342, Long: 101.123
                                </p>
                            </div>
                        </div>

                        <div class="bg-red-50 border-l-4 border-red-500 p-3 rounded-r mb-6">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fa-solid fa-triangle-exclamation text-red-500"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-xs text-red-700 font-semibold">
                                        Photos WITHOUT this date/location text will be rejected immediately.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Guidelines Card -->
                        <h2 class="text-lg font-semibold text-gray-800 mb-3">Upload Guidelines</h2>

                            <ul class="space-y-2 text-sm text-gray-700">
                                <li class="flex items-start">
                                    <i class="fa fa-circle-check text-green-500 mt-1 mr-2"></i>
                                    Ensure the photos are clear and not blurry.
                                </li>
                                <li class="flex items-start">
                                    <i class="fa fa-circle-check text-green-500 mt-1 mr-2"></i>
                                    Support only JPG/PNG format.
                                </li>
                                <li class="flex items-start">
                                    <i class="fa fa-circle-check text-green-500 mt-1 mr-2"></i>
                                    Max size: 10MB per picture.
                                </li>
                                <li class="flex items-start">
                                    <i class="fa fa-circle-check text-green-500 mt-1 mr-2"></i>
                                    Duplicate photos are not allowed.
                                </li>
                            </ul>

                    </div>
                </div>

        </aside>

        <!-- RIGHT: UPLOAD BOX -->
        <main class="flex-1">

            <div class="bg-white rounded-xl shadow p-8 border border-gray-100">

                <h2 class="text-xl font-bold text-gray-800 mb-6">Upload Photo</h2>

                <form method="POST" enctype="multipart/form-data">


                <?php if (!empty($uploadResults)): ?>


                    <div class="mt-6 bg-white border border-gray-200 rounded-xl p-3 shadow-sm">

                        <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
                            <i class="fa fa-info-circle text-blue-500"></i>
                            Upload Result
                        </h3>

                        <ul class="space-y-2 text-sm">
                            <?php foreach ($uploadResults as $msg): ?>
                                <li class="flex items-start gap-2">

                                    <?php if (str_contains(strtolower($msg), 'success')): ?>
                                        <li class="flex items-center justify-between gap-3">

                                            <div class="flex items-start gap-2">
                                                <i class="fa fa-check-circle text-green-600 mt-0.5"></i>
                                                <span class="text-green-700"><?= htmlspecialchars($msg) ?></span>
                                            </div>
                  
                                            <a 
                                                href="userdashboard.php"
                                                class="inline-flex items-center px-3 py-1.5 text-xs rounded-md border border-green-600 
                                                    text-green-700 font-medium hover:bg-green-600 hover:text-white transition-all duration-200 whitespace-nowrap"
                                            >
                                                <i class="fa fa-image mr-1"></i> View Submitted Photos
                                            </a>

                                        </li>
                        
                                    <?php elseif (str_contains(strtolower($msg), 'fail') || str_contains(strtolower($msg), 'error')): ?>
                                        <i class="fa fa-times-circle text-red-600 mt-0.5"></i>
                                        <span class="text-red-700"><?= htmlspecialchars($msg) ?></span>

                                    <?php else: ?>
                                        <i class="fa fa-circle-info text-gray-500 mt-0.5"></i>
                                        <span class="text-gray-700"><?= htmlspecialchars($msg) ?></span>
                                    <?php endif; ?>

                                </li>
                            <?php endforeach; ?>
                        </ul>

                       

                    </div>

                <?php endif; ?>

                  <!-- Upload Area -->
                   <div><br></div>
                  <label id="drop-area"
                        class="block border-2 border-dashed border-green-300 rounded-2xl
                                p-12 text-center bg-white
                                hover:border-green-500 hover:bg-green-50 shadow-sm
                                transition duration-300 cursor-pointer">


                        <div id="preview-container" class="mt-4 <?= !empty($existingSubmission['filePath']) ? '' : 'hidden' ?>">
                            <img id="preview-image" 
                                class="w-full max-h-80 object-cover rounded-xl shadow border border-green-200" 
                                alt="Photo Preview"
                                src="<?= !empty($existingSubmission['filePath']) ? htmlspecialchars($existingSubmission['filePath']) : '' ?>">
                        </div>

                        <div id="upload-placeholder" class="<?= !empty($existingSubmission['filePath']) ? 'hidden' : '' ?>">
                            <i class="fa fa-cloud-upload text-5xl text-green-500 opacity-80"></i>
                            <p class="text-gray-700 font-medium mt-4">Drop Photo Here</p>
                            <p class="text-gray-500 text-sm">or click to choose a file</p>
                            <p class="text-xs text-gray-400 mt-4">Support JPG/PNG format, each picture not exceed 10MB</p>
                        </div>

                        <input id="file-input" type="file" name="photo" class="hidden">
                    </label>



                    <p id="file-name" class="text-red-500 text-sm mt-3"></p>

                      <!-- Caption -->
                    <div class="mb-4">
                        <label class="block font-semibold mb-1">Caption</label>
                        <input type="text" name="caption" required
                            value="<?= htmlspecialchars($existingSubmission['caption'] ?? '') ?>"
                            class="w-full p-3 border border-gray-300 rounded-lg">
                    </div>



                    <div class="mt-8 flex justify-center gap-4">
                        <button type="submit" class="w-32 bg-green-600 text-white py-3 rounded-full font-semibold shadow hover:bg-green-700 transition">
                            <i class="fa fa-check"></i>
                            <?= $existingSubmission ? 'Resubmit' : 'Submit' ?>
                        </button>


                       <button type="reset" id="cancel-btn" class="w-32 border-2 border-red-500 text-red-500 py-3 rounded-full font-semibold hover:bg-red-50 transition">
                          <i class="fa fa-times"></i>
                          Cancel
                      </button>

                        

                    </div>

                </form>

            </div>

        </main>
    </div>

</div>

</body>

</html>

<script>

   const hasExisting = <?= !empty($existingSubmission['filePath']) ? 'true' : 'false' ?>;
    const existingFilePath = "<?= htmlspecialchars($existingSubmission['filePath'] ?? '') ?>";

    const fileInput = document.getElementById('file-input');
    const fileNameDisplay = document.getElementById('file-name');
    const cancelBtn = document.getElementById('cancel-btn');
    const previewContainer = document.getElementById("preview-container");
    const previewImage = document.getElementById("preview-image");
    const uploadPlaceholder = document.getElementById("upload-placeholder");

    // 初始化显示
    if (hasExisting) {
        previewImage.src = existingFilePath;
        previewContainer.classList.remove("hidden");
        uploadPlaceholder.classList.add("hidden");
    } else {
        previewContainer.classList.add("hidden");
        uploadPlaceholder.classList.remove("hidden");
    }

    // 用户选择新文件
    fileInput.addEventListener('change', function() {
        const file = fileInput.files[0];
        if (file && file.type.startsWith("image/")) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
                previewContainer.classList.remove("hidden");
                uploadPlaceholder.classList.add("hidden");
            };
            reader.readAsDataURL(file);
            fileNameDisplay.textContent = "Selected file: " + file.name;
        } else {
            if (hasExisting) {
                previewImage.src = existingFilePath;
                previewContainer.classList.remove("hidden");
                uploadPlaceholder.classList.add("hidden");
            } else {
                previewContainer.classList.add("hidden");
                uploadPlaceholder.classList.remove("hidden");
            }
            fileNameDisplay.textContent = "";
        }
    });

    // Cancel 按钮
    cancelBtn.addEventListener('click', function () {
        fileInput.value = "";
        fileNameDisplay.textContent = "";
        if (hasExisting) {
            previewImage.src = existingFilePath;
            previewContainer.classList.remove("hidden");
            uploadPlaceholder.classList.add("hidden");
        } else {
            previewContainer.classList.add("hidden");
            uploadPlaceholder.classList.remove("hidden");
        }
    });




</script>

