<?php
// Centralized CORS + session cookie policy for all endpoints.

$defaultOrigins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'http://localhost',
    'http://127.0.0.1',
];

$envOriginSingle = array_filter([
    getenv('FRONTEND_URL') ?: '',
    getenv('FRONTEND_URL_DEV') ?: '',
]);

$envOriginsList = getenv('FRONTEND_ORIGINS') ?: getenv('ALLOWED_ORIGINS') ?: '';
$extraOrigins = $envOriginsList !== ''
    ? preg_split('/\s*,\s*/', $envOriginsList, -1, PREG_SPLIT_NO_EMPTY)
    : [];

$allowedOrigins = array_values(array_filter(array_merge($envOriginSingle, $extraOrigins, $defaultOrigins)));

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
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$stateChanging = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
if ($stateChanging) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $csrfExempt = ($requestPath === '/payments/webhook.php')
        || str_starts_with($requestPath, '/admin/')
          // Auth endpoints (session bootstrap)
        || str_starts_with($requestPath, '/auth/login.php')
        || str_starts_with($requestPath, '/auth/register.php')
        || str_starts_with($requestPath, '/auth/reset_password.php')
        || str_starts_with($requestPath, '/auth/admin_login.php');

    if (!$csrfExempt) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionToken = $_SESSION['csrf_token'] ?? '';
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? '';

        if ($sessionToken === '' || $headerToken === '' || !hash_equals($sessionToken, $headerToken)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Invalid CSRF token'
            ]);
            exit;
        }
    }
}
