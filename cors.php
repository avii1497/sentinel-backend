<?php
// Centralized CORS + session cookie policy for all endpoints.

$defaultOrigins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'http://localhost',
    'http://127.0.0.1',
    'https://relaxed-tartufo-866a0b.netlify.app',
    'https://musical-swan-291e56.netlify.app',
];

$envOrigins = getenv('FRONTEND_ORIGINS') ?: getenv('ALLOWED_ORIGINS') ?: '';
$extraOrigins = $envOrigins !== ''
    ? preg_split('/\s*,\s*/', $envOrigins, -1, PREG_SPLIT_NO_EMPTY)
    : [];

$allowedOrigins = array_values(array_filter(array_merge($defaultOrigins, $extraOrigins)));

$originRaw = $_SERVER['HTTP_ORIGIN'] ?? '';
$origin = rtrim(strtolower($originRaw), '/');
$allowedOriginsNormalized = array_map(
    static fn($o) => rtrim(strtolower($o), '/'),
    $allowedOrigins
);

$isAllowedOrigin = $origin !== '' && in_array($origin, $allowedOriginsNormalized, true);

if ($isAllowedOrigin) {
    header("Access-Control-Allow-Origin: $originRaw");
    header('Vary: Origin');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-XSRF-Token, Accept');
header('Access-Control-Max-Age: 86400');

// Cookie policy for cross-site sessions (Netlify -> Render).
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

$isLocalOrigin = strpos($origin, 'http://localhost') === 0
    || strpos($origin, 'http://127.0.0.1') === 0;

$cookieSameSite = ($isLocalOrigin || !$isHttps) ? 'Lax' : 'None';
$cookieSecure = $isHttps && !$isLocalOrigin;

ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
ini_set('session.cookie_samesite', $cookieSameSite);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $cookieSecure,
    'httponly' => true,
    'samesite' => $cookieSameSite,
]);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
