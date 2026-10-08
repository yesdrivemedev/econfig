<?php
require_once __DIR__ . '/includes/session.php';
appSessionStart();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', time() - 42000, '/');
}
session_destroy();
header('location: login.php');
exit;
