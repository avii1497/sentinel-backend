<?php

// Force same session across all endpoints
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:3000",
    "http://localhost",
    "http://127.0.0.1",
    "https://musical-swan-291e56.netlify.app"
];

$originRaw = $_SERVER['HTTP_ORIGIN'] ?? '';
$origin = rtrim(strtolower($originRaw), '/');
$allowedOriginsNormalized = array_map(function ($o) {
    return rtrim(strtolower($o), '/');
}, $allowedOrigins);

$isAllowedOrigin = $origin !== '' && in_array($origin, $allowedOriginsNormalized, true);

if ($isAllowedOrigin) {
    header("Access-Control-Allow-Origin: $originRaw");
    header("Vary: Origin");
}

// CORS config safe for cookies
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-XSRF-Token");

// Configure cookie attributes for local vs. deployed
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

$isLocalOrigin = strpos($origin, "http://localhost") === 0 ||
    strpos($origin, "http://127.0.0.1") === 0;

$cookieSameSite = ($isLocalOrigin || !$isHttps) ? 'Lax' : 'None';
$cookieSecure = $isHttps && !$isLocalOrigin;

ini_set('session.cookie_samesite', $cookieSameSite);
ini_set('session.cookie_secure', $cookieSecure ? 1 : 0);

// Ensure same session path and cookie flags
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $cookieSecure,
    'httponly' => true,
    'samesite' => $cookieSameSite
]);

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
