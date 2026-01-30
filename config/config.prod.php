<?php
// ==========================
// SESSION (PRODUCTION)
// ==========================
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'None'); // REQUIRED for cross-site
ini_set('session.cookie_secure', 1);        // HTTPS only

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

// ==========================
// CORS (PRODUCTION)
// ==========================
$allowedOrigins = [
    "https://musical-swan-291e56.netlify.app"
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-XSRF-Token");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// ==========================
// DATABASE (PRODUCTION)
// ==========================
define('DB_HOST', 'sql303.infinityfree.com');
define('DB_NAME', 'if0_41031066_senti_db'); // exact DB name
define('DB_USER', 'if0_41031066');
define('DB_PASS', '9p13PaXCfcI1hg');
define('DB_CHARSET', 'utf8mb4');
