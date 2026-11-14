<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'cookie_path'     => '/',
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']), //enables secure flag automatically when deployed on https
        'cookie_samesite' => 'Lax'                     //protects against cross-site request forgery
    ]);
}

//flash, redirect and CSRF helpers live in ui.php (single source of truth)
require_once __DIR__ . '/ui.php';

//check if user is logged in
if (!function_exists('require_login')) {
    function require_login(): void {
        if (!isset($_SESSION['user_id'])) {
            //adjust relative path depending on where the script is called from
            $path = (str_contains($_SERVER['PHP_SELF'], '/modules/')) ? '../login.php' : 'login.php';
            header("Location: " . $path);
            exit;
        }
    }
}

//check if logged-in user has admin privileges
if (!function_exists('require_admin')) {
    function require_admin(): void {
        require_login();
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            flash('danger', 'Access denied. Administrator rights required.');
            $path = (str_contains($_SERVER['PHP_SELF'], '/modules/')) ? '../dashboard.php' : 'dashboard.php';
            header("Location: " . $path);
            exit;
        }
    }
}