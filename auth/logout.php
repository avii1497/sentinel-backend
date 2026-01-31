<?php
require_once __DIR__ . '/../cors.php';
session_start();

// Destroy the PHP session properly
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $params['secure'] ?? false,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Logout successful']);
