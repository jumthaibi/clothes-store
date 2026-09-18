<?php
session_start();

// Keep the two entry points intuitive: admin logout returns to the original
// admin login screen, while customer logout returns to the storefront.
$return_to_login = isset($_GET['admin']) && $_GET['admin'] === '1';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header("Location: " . ($return_to_login ? "admin.php" : "index.php"));
exit;
?>