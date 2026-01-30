<?php
require_once __DIR__ . '/../cors.php';
session_start();

// Destroy the PHP session properly
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Logout successful']);
