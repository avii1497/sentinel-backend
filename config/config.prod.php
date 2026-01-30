<?php
/* ======================================================
   CORS (NETLIFY FRONTEND → RENDER BACKEND)
   ====================================================== */
$FRONTEND_ORIGIN = "https://musical-swan-291e56.netlify.app";

if (!empty($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $FRONTEND_ORIGIN) {
    header("Access-Control-Allow-Origin: $FRONTEND_ORIGIN");
    header("Vary: Origin");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ======================================================
   SESSION (RENDER + HTTPS ONLY)
   ====================================================== */
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'None'); // REQUIRED for cross-site
ini_set('session.cookie_secure', 1);        // HTTPS only (Render)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ======================================================
   DATABASE (RAILWAY MYSQL — HARDCODED)
   ====================================================== */
define('DB_HOST', 'centerbeam.proxy.rlwy.net');
define('DB_PORT', '56449');
define('DB_NAME', 'railway');
define('DB_USER', 'root');
define('DB_PASS', 'qAqePfAEdtpUccqPxFBCFBraUoJiDNNv');
define('DB_CHARSET', 'utf8mb4');
