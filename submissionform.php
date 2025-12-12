<?php

session_start();
include("db_connect.php");

if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['userID'];

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
$uploadResults = [];

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

    // Basic validation
    if ($fileError === 4) {
        $uploadResults[] = "Error: Please Upload a File.";
    } else {
        if (!in_array($fileExt, $allowedTypes)) {
            $uploadResults[] = "Error: Invalid format ($fileName). Only JPG/PNG allowed.";
        } elseif ($fileError !== 0) {
            $uploadResults[] = "Error: Upload error for $fileName.";
        } elseif ($fileSize > $maxSize) {
            $uploadResults[] = "Error: File size exceeds 10MB limit.";
        } else {
            // Check for duplicates
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
                $uploadResults[] = "Error: This photo has already been uploaded!";
            } else {
                $caption = $_POST['caption'];
                $fileNewName = uniqid('', true).'.'. $fileExt;
                $savePath = $uploadDir . $fileNewName;

                if (move_uploaded_file($fileTmp, $savePath)) {
                    if ($existingSubmission) {
                        // Update existing
                        $stmt = $conn->prepare("
                            UPDATE sub 
                            SET caption=?, fileName=?, filePath=?, fileHash=?, originalName=?, status='pending', challengeID=? 
                            WHERE submissionID=?
                        ");
                        $stmt->bind_param("ssssiii", $caption, $fileNewName, $savePath, $fileHash, $fileName, $challengeID, $submissionId);
                        $stmt->execute();
                        $stmt->close();
                        $uploadResults[] = "Success: Submission updated successfully.";
                    } else {
                        // Insert new
                        $stmt = $conn->prepare("
                            INSERT INTO sub (userID, fileName, filePath, fileHash, originalName, caption, status, challengeID)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $status = "pending";
                        $stmt->bind_param("issssssi", $userId, $fileNewName, $savePath, $fileHash, $fileName, $caption, $status, $challengeID);
                        $stmt->execute();
                        $submissionId = $conn->insert_id;
                        $stmt->close();
                        $uploadResults[] = "Success: Photo uploaded successfully.";
                    }

                    // Update local variable for view
                    $existingSubmission = [
                        'filePath' => $savePath,
                        'caption' => $caption
                    ];
                } else {
                    $uploadResults[] = "Error: Failed to move uploaded file.";
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
    <title>Submit Challenge Proof</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <style>
        /* Custom scrollbar for better aesthetics */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>

<body class="bg-gray-50 text-gray-800 font-sans">

    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">
                <i class="fa-solid fa-camera text-green-600 mr-2"></i> Challenge Submission
            </h1>
            <a href="view.php" class="text-gray-500 hover:text-gray-900 transition text-sm font-medium">
                <i class="fa-solid fa-arrow-left mr-1"></i> Back to Challenges
            </a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-6 py-10">

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

            <aside class="lg:col-span-4 space-y-6">
                
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-100">
                        <h2 class="font-semibold text-gray-800">Current Challenge</h2>
                    </div>
                    <div class="p-6">
                        <h3 class="text-xl font-bold text-blue-700 mb-2"><?= htmlspecialchars($dbChallengeName) ?></h3>
                        <div class="text-sm text-gray-600 leading-relaxed bg-blue-50 p-3 rounded-lg border border-blue-100">
                            <?= nl2br(htmlspecialchars($dbChallengeRule)) ?>
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

                        <div class="border-t border-gray-100 pt-4">
                            <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-3">Please use this app:</h3>
                            
                            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="flex-shrink-0 w-10 h-10 bg-white rounded-full border border-gray-200 flex items-center justify-center shadow-sm">
                                        <div class="flex items-center gap-0.5">
                                            <i class="fab fa-android text-green-500 text-sm"></i>
                                            <span class="text-gray-300 text-[10px]">|</span>
                                            <i class="fab fa-apple text-gray-800 text-sm"></i>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 class="font-bold text-gray-800">Timemark</h4>
                                        <p class="text-[10px] text-gray-500 uppercase font-semibold">Free • iOS & Android</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <a href="https://play.google.com/store/apps/details?id=com.oceangalaxy.camera.new" target="_blank" class="flex items-center justify-center gap-2 px-3 py-2 bg-white border border-gray-200 rounded-lg shadow-sm hover:border-green-300 hover:text-green-600 transition-all text-xs font-medium text-gray-700">
                                        <i class="fab fa-google-play"></i> Android
                                    </a>
                                    <a href="https://apps.apple.com/us/app/timemark-photo-proof/id6446071834" target="_blank" class="flex items-center justify-center gap-2 px-3 py-2 bg-white border border-gray-200 rounded-lg shadow-sm hover:border-blue-300 hover:text-blue-600 transition-all text-xs font-medium text-gray-700">
                                        <i class="fab fa-apple"></i> iPhone
                                    </a>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Upload Guidelines</h2>
                    <ul class="space-y-3 text-sm text-gray-600">
                        <li class="flex items-start">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 mr-2"></i>
                            <span>Photos must be clear, not blurry.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 mr-2"></i>
                            <span>Allowed: <strong>JPG, PNG</strong> only.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 mr-2"></i>
                            <span>Max file size: <strong>10MB</strong>.</span>
                        </li>
                         <li class="flex items-start">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 mr-2"></i>
                            <span>Duplicate photos are not allowed.</span>
                        </li>
                    </ul>
                </div>

            </aside>

            <main class="lg:col-span-8">
                
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                    <div class="px-8 py-6 border-b border-gray-100 bg-white">
                        <h2 class="text-xl font-bold text-gray-800">Upload Evidence</h2>
                        <p class="text-gray-500 text-sm mt-1">Submit your photo proof to complete this challenge.</p>
                    </div>

                    <div class="p-8">
                        
                        <?php if (!empty($uploadResults)): ?>
                            <div class="mb-8 space-y-3">
                                <?php foreach ($uploadResults as $msg): ?>
                                    <?php 
                                        $isSuccess = str_contains(strtolower($msg), 'success');
                                        $bgClass = $isSuccess ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200';
                                        $textClass = $isSuccess ? 'text-green-800' : 'text-red-800';
                                        $icon = $isSuccess ? 'fa-circle-check' : 'fa-circle-xmark';
                                    ?>
                                    <div class="<?= $bgClass ?> border rounded-lg p-4 flex items-start gap-3">
                                        <i class="fa-solid <?= $icon ?> <?= $textClass ?> mt-0.5 text-lg"></i>
                                        <div class="flex-1">
                                            <p class="<?= $textClass ?> text-sm font-medium"><?= htmlspecialchars($msg) ?></p>
                                        </div>
                                        <?php if($isSuccess): ?>
                                            <a href="userDashboard.php?highlight=dashboard" class="text-xs font-bold text-green-700 hover:text-green-900 underline">View Gallery &rarr;</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="upload-form">
                            
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Photo Proof</label>
                                
                                <label id="drop-area" class="relative block group cursor-pointer">
                                    <input id="file-input" type="file" name="photo" class="hidden" accept=".jpg,.jpeg,.png">
                                    
                                    <div class="border-2 border-dashed border-gray-300 rounded-xl p-10 text-center transition-all duration-200 hover:border-green-500 hover:bg-green-50 group-hover:shadow-sm">
                                        
                                        <div id="upload-placeholder" class="<?= !empty($existingSubmission['filePath']) ? 'hidden' : '' ?>">
                                            <div class="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4 transition-transform group-hover:scale-110">
                                                <i class="fa-solid fa-cloud-arrow-up text-2xl"></i>
                                            </div>
                                            <p class="text-gray-900 font-medium">Click to upload or drag and drop</p>
                                            <p class="text-gray-500 text-xs mt-1">SVG, PNG, JPG or GIF (max. 10MB)</p>
                                        </div>

                                        <div id="preview-container" class="<?= !empty($existingSubmission['filePath']) ? '' : 'hidden' ?> relative">
                                            <img id="preview-image" 
                                                src="<?= !empty($existingSubmission['filePath']) ? htmlspecialchars($existingSubmission['filePath']) : '' ?>" 
                                                class="mx-auto max-h-[400px] rounded-lg shadow-md object-contain border border-gray-200"
                                            >
                                            <div class="absolute inset-0 bg-black bg-opacity-0 hover:bg-opacity-10 transition-all rounded-lg flex items-center justify-center">
                                                <span class="bg-white text-gray-800 px-3 py-1 rounded-full text-xs font-bold shadow opacity-0 group-hover:opacity-100 transition-opacity">Change Photo</span>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                                <p id="file-name" class="text-right text-xs text-gray-500 mt-2 h-4"></p>
                            </div>

                            <div class="mb-8">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Caption / Notes</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fa-regular fa-comment-dots text-gray-400"></i>
                                    </div>
                                    <input 
                                        type="text" 
                                        id="caption-input"
                                        name="caption" 
                                        required
                                        placeholder="Describe your submission briefly..."
                                        value="<?= htmlspecialchars($existingSubmission['caption'] ?? '') ?>"
                                        class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none transition-all"
                                    >
                                </div>
                            </div>

                            <div class="flex items-center gap-4 pt-4 border-t border-gray-100">
                                <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-6 rounded-lg shadow-sm transition-colors duration-200 flex justify-center items-center gap-2">
                                    <i class="fa-solid fa-paper-plane"></i>
                                    <?= $existingSubmission ? 'Update Submission' : 'Submit Challenge' ?>
                                </button>
                                
                                <button type="button" id="cancel-btn" class="flex-none w-32 bg-white border border-gray-300 text-gray-700 font-semibold py-3 px-6 rounded-lg hover:bg-gray-50 hover:text-red-600 hover:border-red-300 transition-colors duration-200">
                                    Cancel
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </main>

        </div>
    </div>

    <script>
        // Data passed from PHP
        const hasExisting = <?= !empty($existingSubmission['filePath']) ? 'true' : 'false' ?>;
        const existingFilePath = "<?= htmlspecialchars($existingSubmission['filePath'] ?? '') ?>";
        const initialCaption = "<?= htmlspecialchars($existingSubmission['caption'] ?? '') ?>";

        // Elements
        const fileInput = document.getElementById('file-input');
        const fileNameDisplay = document.getElementById('file-name');
        const cancelBtn = document.getElementById('cancel-btn');
        const previewContainer = document.getElementById("preview-container");
        const previewImage = document.getElementById("preview-image");
        const uploadPlaceholder = document.getElementById("upload-placeholder");
        const captionInput = document.getElementById("caption-input");

        // Function to set the view state
        function renderView(showPreview, imgSrc = null) {
            if (showPreview && imgSrc) {
                previewImage.src = imgSrc;
                previewContainer.classList.remove("hidden");
                uploadPlaceholder.classList.add("hidden");
            } else {
                previewContainer.classList.add("hidden");
                uploadPlaceholder.classList.remove("hidden");
                previewImage.src = "";
            }
        }

        // 1. Initial Load
        if (hasExisting) {
            renderView(true, existingFilePath);
        } else {
            renderView(false);
        }

        // 2. Handle File Selection
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file && file.type.startsWith("image/")) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    renderView(true, e.target.result);
                };
                reader.readAsDataURL(file);
                fileNameDisplay.textContent = file.name;
                fileNameDisplay.classList.add("text-green-600");
            } else {
                // If invalid or cancelled selection, revert
                if (hasExisting) {
                    renderView(true, existingFilePath);
                } else {
                    renderView(false);
                }
                fileNameDisplay.textContent = "";
            }
        });

        // 3. Handle Cancel Button
        cancelBtn.addEventListener('click', function() {
            // Reset form inputs
            fileInput.value = "";
            captionInput.value = initialCaption;
            fileNameDisplay.textContent = "";

            // Reset view to original state (either show existing image or empty placeholder)
            if (hasExisting) {
                renderView(true, existingFilePath);
            } else {
                renderView(false);
            }
        });
    </script>
</body>
</html>