<?php
// ------------------------------------
// logout.php — Secure Logout
// ------------------------------------
session_start();

// 1️⃣ Completely clear all session data
$_SESSION = [];
session_unset();
session_destroy();

// 2️⃣ Delete PHP SESSION cookie (VERY IMPORTANT)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3️⃣ Expire Remember Me cookies
setcookie('remember_email', '', time() - 3600, '/');
setcookie('remember_password', '', time() - 3600, '/');

?>
<!DOCTYPE html>
<html>
<head>
    <script>
        // 4️⃣ Delete browser_token to force re-login
        sessionStorage.removeItem("browser_token");

        // 5️⃣ Prevent back button from showing protected pages
        window.history.pushState({}, "", "login.php");

        // 6️⃣ Redirect to login
        window.location = "login.php";
    </script>
</head>
<body></body>
</html>
