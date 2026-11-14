<?php
//start session to check login status
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

//if user is already logged in, send them to the dashboard; otherwise, send to login
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}
exit;
?>