<?php
// --------------------------------------------------
// 1. SETUP & AUTH
// --------------------------------------------------
require "db_connect.php";
require "includes/auth.php";

// Validate ID
if (!isset($_GET['id']) || intval($_GET['id']) <= 0) {
    $_SESSION['flash'] = "Invalid challenge ID.";
    header("Location: view.php");
    exit;
}
$challengeID = intval($_GET['id']);

// Fetch Data
$stmt = $conn->prepare("
    SELECT c.*, cat.categoryName, CONCAT(u.firstName, ' ', u.lastName) AS creatorName
    FROM challenge c
    LEFT JOIN category cat ON c.categoryID = cat.categoryID
    LEFT JOIN user u ON c.created_by = u.userID
    WHERE c.challengeID = ? LIMIT 1
");
$stmt->bind_param("i", $challengeID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['flash'] = "Challenge not found.";
    header("Location: view.php");
    exit;
}
$challenge = $result->fetch_assoc();
$stmt->close();

$_SESSION['challengeID'] = $challenge['challengeID'];

// --------------------------------------------------
// 2. IMAGE LOGIC (Custom URL + Fallback)
    // --------------------------------------------------
function getHeroImageURL($title, $id) {
    
    // -------------------------------------------------------------
    // 🟢 CUSTOM IMAGE LIST (EDIT HERE)
    // Format: Challenge_ID => 'URL',
    // -------------------------------------------------------------
                 $custom_urls = [
                    30 => 'https://cj.my/wp-content/uploads/2023/03/the-iconic-penang-ferry-service-7-1300x500.jpg', 
                    45 => 'https://wallpapers.com/images/hd/food-4k-spdnpz7bhmx4kv2r.jpg',
                    41 => 'https://bangkokattractions.com/wp-content/uploads/2023/04/ipoh.jpg',
                    29 => 'https://ik.imagekit.io/tvlk/blog/2022/10/03-KL-Monorail-1024x683.jpg?tr=dpr-2,w-675',
                    44 => 'https://tse1.mm.bing.net/th/id/OIP.gfHJEWugJxMht6qauXJaRgHaEo?pid=Api&P=0&h=180',
                    42 => 'https://tse4.mm.bing.net/th/id/OIP.Onirx1aPQ5JvZ2QEhlv2hwHaEL?pid=Api&P=0&h=180',
                    37 => 'https://media.tacdn.com/media/attractions-content--1x-1/0b/39/b0/98.jpg',
                    35 => 'https://tse3.mm.bing.net/th/id/OIP.gRliVC74pBhaqeP7DTxj1wHaHa?pid=Api&P=0&h=180',
                    31 => 'https://tse4.mm.bing.net/th/id/OIP.Cts7VCGtR3PqXCt9lUaGCgHaCe?pid=Api&P=0&h=180',
                    32=> 'https://th.bing.com/th/id/OIP.LkVjBr2VGh2iKsTd5aJfMAHaH6?w=89&h=90&c=1&rs=1&qlt=70&r=0&o=7&cb=ucfimg2&dpr=1.3&pid=InlineBlock&rm=3&ucfimg=1',
                    33=> 'https://img.freepik.com/premium-vector/single-use-plastic-ban-environmental-concept-say-no-plastic-concept-vector-illustration_494556-955.jpg?w=2000',
                    34=> 'https://malaysia.images.search.yahoo.com/images/view;_ylt=AwrO6t7i7EJprP4Map7lPwx.;_ylu=c2VjA3NyBHNsawNpbWcEb2lkAzIxNjNjYTM4NjY5NzNkMGU0Mjg0MWUwMGFkZmQzOWNhBGdwb3MDMQRpdANiaW5n?back=https%3A%2F%2Fmalaysia.images.search.yahoo.com%2Fsearch%2Fimages%3Fp%3Dclean%2Bbeach%26ei%3DUTF-8%26fr%3Dmcafee-malaysia%26fr2%3Dp%253As%252Cv%253Ai%252Cm%253Asb-top%26tab%3Dorganic%26ri%3D1&w=2000&h=1333&imgurl=cdn.shopify.com%2Fs%2Ffiles%2F1%2F0569%2F0615%2F4154%2Ffiles%2FGettyImages-187164094.jpg&rurl=https%3A%2F%2Fnaturespath.com%2Fblogs%2Fposts%2Forganize-beach-cleanup&size=230KB&p=clean+beach&oid=2163ca3866973d0e42841e00adfd39ca&fr2=p%3As%2Cv%3Ai%2Cm%3Asb-top&fr=mcafee-malaysia&tt=How+to+Organize+a+Beach+Cleanup+%E2%80%93+Nature%26%2339%3Bs+Path&b=0&ni=80&no=1&ts=&tab=organic&sigr=am6Mz8pOwVpS&sigb=_iA30vHEmdE4&sigi=a1R8sA_5gX.w&sigt=iuVnXO1nPLft&.crumb=Sj0dlJWGzYr&fr=mcafee-malaysia&fr2=p%3As%2Cv%3Ai%2Cm%3Asb-top',
                    36 =>'https://th.bing.com/th/id/OIP.wHoPaK1Fk6KMu0HjixrqfwHaFH?w=252&h=180&c=7&r=0&o=5&cb=ucfimg2&dpr=1.3&pid=1.7&ucfimg=1',
                    39 => 'https://tse4.mm.bing.net/th/id/OIP.N1leuql4JYM4pvuatxhFYAHaEK?pid=Api&P=0&h=180',
                    40 => 'https://malaysia.images.search.yahoo.com/images/view;_ylt=AwrO6t5r7UJpyXsPgEPlPwx.;_ylu=c2VjA3NyBHNsawNpbWcEb2lkA2Y2NGJkYzA3N2Q5ZTI2ODViMTM4ZmJiZDQ3ZjFkNTZjBGdwb3MDMwRpdANiaW5n?back=https%3A%2F%2Fmalaysia.images.search.yahoo.com%2Fsearch%2Fimages%3Fp%3Dcar%2Bfree%26ei%3DUTF-8%26fr%3Dmcafee-malaysia%26fr2%3Dp%253As%252Cv%253Ai%252Cm%253Asb-top%26tab%3Dorganic%26ri%3D3&w=1600&h=900&imgurl=newsd.in%2Fwp-content%2Fuploads%2F2022%2F09%2FWorld-Car-Free-Day-2022-1.jpg&rurl=https%3A%2F%2Fnewsd.in%2Fworld-car-free-day-2022-date-history-and-benefits-of-the-day%2F&size=61KB&p=car+free&oid=f64bdc077d9e2685b138fbbd47f1d56c&fr2=p%3As%2Cv%3Ai%2Cm%3Asb-top&fr=mcafee-malaysia&tt=World+Car-Free+Day+2022%3A+Date%2C+History+and+benefits+of+the+day&b=0&ni=80&no=3&ts=&tab=organic&sigr=NG1wltyIkxxj&sigb=5ZJGGmnQhmHV&sigi=hFH.7CpPiXIn&sigt=hrhXbrxnFBeA&.crumb=Sj0dlJWGzYr&fr=mcafee-malaysia&fr2=p%3As%2Cv%3Ai%2Cm%3Asb-top',
                    43 => 'https://contentgrid.homedepot-static.com/hdus/en_US/DTCCOMNEW/Articles/how-to-plant-a-tree-update-hero.jpg',
                    47 => 'https://tse2.mm.bing.net/th/id/OIP.qmqkdV-FnDI-FmxMeu41ZwHaFg?pid=Api&P=0&h=180',
                    48 => 'https://th.bing.com/th/id/OIP.j2cOlW3Fc-IJnJF_O0b5lAHaHa?w=182&h=182&c=7&r=0&o=5&cb=ucfimg2&dpr=1.3&pid=1.7&ucfimg=1', 
                    38 => 'https://malaysia.images.search.yahoo.com/images/view;_ylt=Awr93aMJ7kJphwoUjRTlPwx.;_ylu=c2VjA3NyBHNsawNpbWcEb2lkA2IwYmE4NjI0YjEwZmViNDQ4NTNkNGNmMTBhMjk2MzE3BGdwb3MDMjkEaXQDYmluZw--?back=https%3A%2F%2Fmalaysia.images.search.yahoo.com%2Fsearch%2Fimages%3Fp%3Dcycling%26ei%3DUTF-8%26fr%3Dmcafee-malaysia%26fr2%3Dp%253As%252Cv%253Ai%252Cm%253Asb-top%26tab%3Dorganic%26ri%3D29&w=1600&h=1068&imgurl=cdn.britannica.com%2F63%2F82563-050-3FCFC72A%2FFamily-country-road.jpg&rurl=https%3A%2F%2Fwww.britannica.com%2Ftechnology%2Fbicycle&size=369KB&p=cycling&oid=b0ba8624b10feb44853d4cf10a296317&fr2=p%3As%2Cv%3Ai%2Cm%3Asb-top&fr=mcafee-malaysia&tt=Bicycle+%7C+Definition%2C+History%2C+Types%2C+%26+Facts+%7C+Britannica&b=0&ni=80&no=29&ts=&tab=organic&sigr=Y60yMSYhGhES&sigb=6vPuZX4fpzzA&sigi=dsTxW41uJdyV&sigt=DXBNZJlqRAZ8&.crumb=Sj0dlJWGzYr&fr=mcafee-malaysia&fr2=p%3As%2Cv%3Ai%2Cm%3Asb-top',
                ];

    function getExactHeroImage($title) {
    // 1. Clean the title
    $t = strtolower(trim($title));

    switch ($t) {
        // --- 🔴 EXACT MATCHES (Same as View Page) ---
       // --- CATEGORY: TRANSPORT ---
        case 'bike to work':            return 'bicycle';
        case 'walk the last mile':      return 'sneakers';
        case 'carpool crew':            return 'automobile'; // 'carpool' is often misunderstood by AI
        case 'public bus adventure':    return 'bus vehicle';

        // --- CATEGORY: WASTE ---
        case 'say no to straws':        return 'iced drinks '; // Shows drink without straw usually
        case 'bring your bottle':       return 'reusable water bottle';
        case 'tote bag shopper':        return 'reuseable bag';
        case 'compost your scraps':     return 'compost bin';

        // --- CATEGORY: FOOD ---
        case 'meatless monday':         return 'vegetables';
        case 'support local farmers':   return 'farmers market';
        case 'vegan meal challenge':    return 'vegetables';
        case 'love your leftovers':     return 'food container';

        // --- CATEGORY: ENERGY & WATER ---
        case 'unplug the vampires':     return 'electrical socket';
        case 'cold wash cycle':         return 'washing machine';
        case 'air dry laundry':         return 'clothesline';
        case '5-minute shower':         return 'shower head';

        // --- CATEGORY: NATURE ---
        case 'plant a tree':            return 'planting tree';
        case 'litter pickup':           return 'garbage';
        case 'clean ocean promise':     return 'clean beach';
        case 'wild bird watch':         return 'bird';

        // --- FALLBACKS ---
        default:
            if (strpos($t, 'transport') !== false) return 'traffic';
            if (strpos($t, 'food') !== false)      return 'fruit';
            if (strpos($t, 'water') !== false)     return 'water drop';
            if (strpos($t, 'energy') !== false)    return 'electricity';
            return 'nature'; 
    }
}


    // 1. Check Custom List
    if (array_key_exists($id, $custom_urls)) {
        return $custom_urls[$id];
    }

    // 2. Fallback: Auto-Generate Keyword if ID is not in the list
    $t = strtolower(trim($title));
    $keyword = 'nature'; 

    if (strpos($t, 'bike') !== false)         $keyword = 'bicycle';
    elseif (strpos($t, 'walk') !== false)     $keyword = 'sneakers';
    elseif (strpos($t, 'bus') !== false)      $keyword = 'bus';
    elseif (strpos($t, 'carpool') !== false)  $keyword = 'traffic';
    elseif (strpos($t, 'straw') !== false)    $keyword = 'drink';
    elseif (strpos($t, 'bottle') !== false)   $keyword = 'water bottle';
    elseif (strpos($t, 'bag') !== false)      $keyword = 'shopping bag';
    elseif (strpos($t, 'food') !== false)     $keyword = 'vegetables';
    elseif (strpos($t, 'plant') !== false)    $keyword = 'planting';
    elseif (strpos($t, 'beach') !== false)    $keyword = 'beach';
    elseif (strpos($t, 'river') !== false)    $keyword = 'river';
    
    // Return LoremFlickr URL
    return "https://loremflickr.com/1200/600/" . urlencode($keyword) . "?lock=" . $id;
}

// --------------------------------------------------
// 3. HARDCODED RULES & BENEFITS
// --------------------------------------------------
function getHardcodedDetails($title) {
    $t = strtolower($title); 

    // --- CATEGORY 1: ECO-COMMUTER ---
    if (strpos($t, 'monorail') !== false) {
        return [
            'r' => 'Take a selfie inside the Monorail carriage. The photo must show todays date and time to verify your ride.',
            'b' => 'You save money on parking, arrive faster during rush hour, and help reduce carbon emissions in the city centers.'
        ];
    }
    if (strpos($t, 'ferry') !== false) {
        return [
            'r' => 'Take a photo of the ocean view from the ferry deck. Ensure the stamp shows you are crossing the strait.', 
            'b' => 'Enjoy a scenic, stress-free journey with the sea breeze while reducing vehicle exhaust fumes.'
        ];
    }
    if (strpos($t, 'global bus') !== false) {
        return [
            'r' => 'Snap a clear photo of your bus ticket or the view inside the bus. The date must match the challenge period.', 
            'b' => 'Lower your personal carbon footprint significantly and support public transit systems.'
        ];
    }
    if (strpos($t, 'jb work') !== false) {
        return [
            'r' => 'Take a photo of yourself at the bus stop or inside the bus. The time must show typical commuting hours (morning or evening).', 
            'b' => 'Reduce severe traffic congestion at the Causeway and city center while saving on petrol.'
        ];
    }

    // --- CATEGORY 2: WASTE WARRIOR ---
    if (strpos($t, 'plastic free') !== false) {
        return [
            'r' => 'Take a photo of your food inside your own reusable container (Tupperware/Tiffin). The photo must verify the date.', 
            'b' => 'Directly prevents plastic waste from clogging drains and harming local wildlife.'
        ];
    }
    if (strpos($t, 'beach clean') !== false) {
        return [
            'r' => 'Take a photo of the trash you collected in a bag. The location stamp must confirm you are at the beach.', 
            'b' => 'Protects marine life like turtles and fish from eating plastic, and keeps our beaches beautiful for everyone.'
        ];
    }
    if (strpos($t, 'sorting') !== false) {
        return [
            'r' => 'Photograph your separated waste bins at home. The photo must clearly show at least two different categories of waste.', 
            'b' => 'Ensures materials can be actually recycled instead of being sent to the landfill.'
        ];
    }
    if (strpos($t, 'recycling') !== false && strpos($t, 'shah alam') !== false) { // Specific check
        return [
            'r' => 'Take a selfie at the recycling center or machine. Must show you visited today.', 
            'b' => 'Promotes the circular economy where old plastic is turned into new products rather than trash.'
        ];
    }

    // --- CATEGORY 3: ACTIVE MOVER ---
    if (strpos($t, 'river walk') !== false) {
        return [
            'r' => 'Take a photo of the river scenery or a selfie while walking. The time and location must be visible.', 
            'b' => 'Improves cardiovascular health, burns calories, and lets you appreciate the city history without a car.',
        ];
    }
    if (strpos($t, 'bike') !== false) {
        return [
            'r' => 'Take a photo of your bicycle with a landmark (like the mosque or bridge) in the background.', 
            'b' => 'Cycling strengthens your leg muscles and produces absolutely no air pollution.'
        ];
    }
    if (strpos($t, '10k steps') !== false) {
        return [
            'r' => 'Take a photo of your smartwatch or phone screen showing the step count and today date.', 
            'b' => 'Walking 10k steps daily significantly reduces the risk of heart disease and keeps you active.'
        ];
    }
    if (strpos($t, 'car free') !== false) {
        return [
            'r' => 'Take a selfie with the crowd on the main road. The time  must be between 7 AM and 9 AM.', 
            'b' => 'Experience a noise-free, pollution-free city environment and support the green city initiative.'
        ];
    }

    // --- CATEGORY 4: NATURE GUARDIAN ---
    if (strpos($t, 'cave') !== false) {
        return [
            'r' => 'Take a photo at the cave entrance. Ensure you do not leave any litter behind.', 
            'b' => 'Promotes eco-tourism which helps fund the preservation of these natural limestone wonders.'
        ];
    }
    if (strpos($t, 'taiping') !== false || strpos($t, 'lake zen') !== false) {
        return [
            'r' => 'Take a photo of a Rain Tree (Deduap Tree). Location stamp must match Taiping.', 
            'b' => 'Studies show that time spent in green spaces lowers stress levels and improves mental health.'
        ];
    }
    if (strpos($t, 'plant life') !== false) {
        return [
            'r' => 'Take a photo of you planting the seed or sapling into the soil.', 
            'b' => 'Plants absorb Carbon Dioxide (CO2) and release Oxygen, helping to clean the air we breathe.'
        ];
    }
    if (strpos($t, 'geo tour') !== false) {
        return [
            'r' => 'Take a photo of an educational signboard you find there.', 
            'b' => 'Increases awareness about fragile ecosystems and the importance of mangroves in preventing coastal erosion.'
        ];
    }

    // --- CATEGORY 5: GREEN LIVING ---
    if (strpos($t, 'local food') !== false) {
        return [
            'r' => 'Take a photo of your meal with the shop signboard visible in the background.', 
            'b' => 'Local food usually travels fewer miles (lower carbon footprint) and keeps money in the local community.'
        ];
    }
    if (strpos($t, 'veggie') !== false) {
        return [
            'r' => 'Take a photo of your plate showing only vegetables, grains, or fruits (no meat).', 
            'b' => 'Cutting meat consumption even once a week saves huge amounts of water and land resources.'
        ];
    }
    if (strpos($t, 'zero waste') !== false) {
        return [
            'r' => 'Take a photo of you filling your own jar at the dispenser.', 
            'b' => 'This completely eliminates the need for single-use plastic packaging that ends up in oceans.'
        ];
    }
    if (strpos($t, 'carpool') !== false) {
        return [
            'r' => 'Take a selfie with your carpool buddy inside the car.', 
            'b' => 'Carpooling reduces fuel consumption per person, eases parking shortages, and makes the commute less lonely.'
        ];
    }

    // Default Fallback
    return [
        'r' => 'Upload clear proof of your activity.', 
        'b' => 'Every small action counts towards a better planet.'
    ];
}

// --------------------------------------------------
// 4. PREPARE VIEW VARIABLES
// --------------------------------------------------

// 🟢 CALL THE NEW FUNCTION TO GET THE URL
$heroImage = getHeroImageURL($challenge['challengeTitle'], $challenge['challengeID']);

// Data Logic
$pageTitle = $challenge['challengeTitle'];
$details = getHardcodedDetails($challenge['challengeTitle']);

include "includes/layout_start.php";
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">

<style>
    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f4f7f6;
    }

    /* --- HERO SECTION --- */
    .hero-wrapper {
        position: relative;
        height: 400px;
        margin-bottom: 5rem;
    }
    .hero-bg {
        /* USE THE NEW $heroImage VARIABLE */
        background: url('<?= $heroImage ?>') no-repeat center center;
        background-size: cover;
        height: 100%;
        width: 100%;
        position: relative;
    }
    .hero-overlay {
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background: linear-gradient(to bottom, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.8) 100%);
        display: flex;
        align-items: flex-end;
        padding-bottom: 4rem;
    }
    .hero-title {
        color: white;
        font-weight: 800;
        font-size: 3.5rem;
        text-shadow: 0 4px 15px rgba(0,0,0,0.3);
        margin: 0;
    }

    /* --- FLOATING STATS BAR --- */
    .stats-floater {
        position: absolute;
        bottom: -40px;
        left: 50%;
        transform: translateX(-50%);
        width: 90%;
        max-width: 1000px;
        background: white;
        border-radius: 20px;
        padding: 1.5rem 2rem;
        box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-around;
        align-items: center;
        z-index: 10;
    }
    .stat-item {
        text-align: center;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    .stat-icon {
        width: 50px; height: 50px;
        border-radius: 12px;
        background: #e8f5e9; color: #27ae60;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
    }
    .stat-text h6 {
        margin: 0; color: #7f8c8d; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;
    }
    .stat-text span {
        font-size: 1.2rem; font-weight: 700; color: #2c3e50;
    }

    /* --- CONTENT AREA --- */
    .content-card {
        background: white;
        border-radius: 20px;
        padding: 2.5rem;
        box-shadow: 0 5px 20px rgba(0,0,0,0.03);
        margin-bottom: 2rem;
    }
    .section-title {
        font-weight: 700; color: #27ae60; margin-bottom: 1.5rem;
        display: flex; align-items: center; gap: 10px;
    }
    .desc-text {
        font-size: 1.1rem; line-height: 1.8; color: #555;
    }

    /* --- INFO BOXES --- */
    .info-box {
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
        border-left: 5px solid;
    }
    .info-box h5 {
        font-size: 0.9rem;
        font-weight: 700;
        text-transform: uppercase;
        margin-bottom: 0.5rem;
        letter-spacing: 0.5px;
    }
    


    /* --- ACTION SIDEBAR --- */
    .action-card {
        background: white;
        border-radius: 20px;
        padding: 2rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        text-align: center;
        position: sticky;
        top: 2rem;
    }
    .points-circle {
        width: 120px; height: 120px;
        background: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
        color: white; border-radius: 50%;
        display: flex; flex-direction: column; justify-content: center; align-items: center;
        margin: 0 auto 1.5rem;
        box-shadow: 0 10px 20px rgba(253, 160, 133, 0.4);
    }
    .points-val { font-size: 2.5rem; font-weight: 800; line-height: 1; }
    .points-lbl { font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }

    /* Button Styles */
    .btn-submit {
        background: #27ae60; color: white; padding: 1rem; width: 100%;
        border-radius: 15px; font-weight: 700; font-size: 1.1rem;
        border: none; transition: transform 0.2s, box-shadow 0.2s;
        display: block; text-decoration: none;
    }
    .btn-submit:hover {
        background: #219150; transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(39, 174, 96, 0.3); color: white;
    }

    .btn-back {
        margin-top: 1rem; color: #95a5a6; font-weight: 600;
        text-decoration: none; display: inline-block; transition: color 0.2s;
    }
    .btn-back:hover { color: #7f8c8d; }

    /* Map Frame */
    .map-frame {
        border-radius: 15px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    }
</style>


<div class="hero-wrapper">
    <div class="hero-bg">
        <div class="hero-overlay">
            <div class="container">
                <h1 class="hero-title"><?= htmlspecialchars($challenge['challengeTitle']) ?></h1>
            </div>
        </div>
    </div>
    
    <div class="stats-floater">
        
        <div class="stat-item">
            <div class="stat-icon"><i class="bi bi-tag"></i></div>
            <div class="stat-text">
                <h6>Category</h6>
                <span><?= htmlspecialchars($challenge['categoryName'] ?? 'General') ?></span>
            </div>
        </div>

        <div style="width:1px; height:40px; background:#eee;" class="d-none d-md-block"></div>

        <div class="stat-item">
            <div class="stat-icon" style="background:#e3f2fd; color:#2196f3;"><i class="bi bi-geo-alt"></i></div>
            <div class="stat-text">
                <h6>Location</h6>
                <span><?= htmlspecialchars($challenge['city'] ?: 'Anywhere') ?></span>
            </div>
        </div>

        <div style="width:1px; height:40px; background:#eee;" class="d-none d-md-block"></div>

        <div class="stat-item d-none d-md-flex">
            <div class="stat-icon" style="background:#fff3e0; color:#ff9800;"><i class="bi bi-person"></i></div>
            <div class="stat-text">
                <h6>Creator</h6>
                <span><?= htmlspecialchars($challenge['creatorName'] ?: 'Admin') ?></span>
            </div>
        </div>

    </div>
</div>

<div class="container">
    <div class="row">

        <div class="col-lg-8">
            
            <div class="content-card">
                <h3 class="section-title"><i class="bi bi-info-circle-fill"></i> About this Challenge</h3>
                
                <p class="desc-text mb-4">
                    <?= nl2br(htmlspecialchars($challenge['description'])) ?>
                </p>
                
                <div class="desc-text mb-4">
                    <h5><i class=""></i> Rules: </h5>
                    <p class="m-0"><?= htmlspecialchars($details['r']) ?></p>
                </div>

                <div class="desc-text mb-4">
                    <h5><i class=""></i> Benefits: </h5>
                    <p class="m-0"><?= htmlspecialchars($details['b']) ?></p>
                </div>

            </div>

            <?php if (!empty($challenge['city'])): ?>
            <div class="content-card">
                <h3 class="section-title"><i class="bi bi-map-fill"></i> Location Map</h3>
                <div class="ratio ratio-21x9 map-frame">
                    <iframe
                        src="https://maps.google.com/maps?q=<?= urlencode($challenge['city']) ?>&output=embed"
                        loading="lazy"
                        style="border:0;">
                    </iframe>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <div class="col-lg-4">
            <div class="action-card">
                
                <div class="points-circle">
                    <div class="points-val"><?= $challenge['pointAward'] ?></div>
                    <div class="points-lbl">Points</div>
                </div>

                <h4 class="fw-bold mb-3 text-dark">Join the Movement</h4>
                <p class="text-muted small mb-4">Complete this challenge, upload your proof, and earn points towards your eco-goal.</p>

                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    
                    <div class="d-grid gap-2">
                        <a href="challenge_edit.php?id=<?= $challenge['challengeID'] ?>" 
                           class="btn btn-primary fw-bold py-2 rounded-3 text-white text-decoration-none">
                            <i class="bi bi-pencil-square me-2"></i> Edit Challenge
                        </a>

                        <a href="challenge_end.php?id=<?= $challenge['challengeID'] ?>" 
                           onclick="return confirm('Are you sure you want to end this challenge immediately?');"
                           class="btn btn-danger fw-bold py-2 rounded-3 text-white text-decoration-none">
                            <i class="bi bi-stop-circle-fill me-2"></i> End Challenge
                        </a>
                    </div>

                <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'moderator'): ?>

                    <a href="moderator.php" 
                       class="btn btn-warning fw-bold py-3 w-100 rounded-3 text-dark text-decoration-none">
                        <i class="bi bi-card-checklist me-2"></i> View All Submissions
                    </a>
                    <p class="text-muted small mt-2 mb-0">Review user proofs for this challenge.</p>

                <?php else: ?>

                    <a href="submissionform.php" class="btn-submit">
                        <i class="bi bi-camera me-2"></i> Submit Proof
                    </a>

                <?php endif; ?>

                <div class="mt-4 pt-4 border-top">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Start Date</span>
                        <span class="fw-bold text-dark small">
                            <?= $challenge['start_date'] ? date("M d, Y", strtotime($challenge['start_date'])) : "Anytime" ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted small">End Date</span>
                        <span class="fw-bold text-danger small">
                            <?= $challenge['end_date'] ? date("M d, Y", strtotime($challenge['end_date'])) : "Ongoing" ?>
                        </span>
                    </div>
                </div>

                <a href="view.php" class="btn-back">
                    <i class="bi bi-arrow-left"></i> Back to Challenges
                </a>

            </div>
        </div>

    </div>
</div>

<?php include "includes/layout_end.php"; ?>