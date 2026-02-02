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

$appEnv = strtolower($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? '');
$nodeEnv = strtolower($_ENV['NODE_ENV'] ?? getenv('NODE_ENV') ?? '');
$isProduction = $appEnv === 'production'
    || $appEnv === 'prod'
    || $nodeEnv === 'production'
    || (getenv('RENDER') !== false)
    || (getenv('RENDER_EXTERNAL_HOSTNAME') !== false);

$isLocalOrigin = strpos($origin, 'http://localhost') === 0
    || strpos($origin, 'http://127.0.0.1') === 0;

if ($isProduction) {
    $cookieSameSite = 'None';
    $cookieSecure = true;
} else {
    $cookieSameSite = ($isLocalOrigin || !$isHttps) ? 'Lax' : 'None';
    $cookieSecure = $isHttps && !$isLocalOrigin;
}

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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$stateChanging = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
if ($stateChanging) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $csrfExempt =  $requestPath === '/payments/webhook.php'
                    || $requestPath === '/auth/csrf.php'
        || str_starts_with($requestPath, '/admin/')
          // Auth endpoints (session bootstrap)
        || str_starts_with($requestPath, '/auth/login.php')
        || str_starts_with($requestPath, '/auth/register.php')
        || str_starts_with($requestPath, '/auth/reset_password.php')
        || str_starts_with($requestPath, '/auth/admin_login.php');

    if (!$csrfExempt) {
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

/* ======================
     AUTH GUARDS
   ====================== */

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

function requireRole(array|string $allowed): void {
    if (empty($_SESSION['role'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $allowed = (array)$allowed;
    if (!in_array($_SESSION['role'], $allowed, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
}

/* ======================
     CSRF
   ====================== */

function getJsonBody(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if ($ct && stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        $cached = is_array($decoded) ? $decoded : [];
        return $cached;
    }

    $cached = [];
    return $cached;
}

function issueCsrfToken(): string {
    if (!empty($_SESSION['csrf_token'])) {
        return (string)$_SESSION['csrf_token'];
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function requireCsrf(): void {
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    $headerToken =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_SERVER['HTTP_X_XSRF_TOKEN']
        ?? $_SERVER['HTTP_X_CSRFTOKEN']
        ?? $_SERVER['HTTP_XSRF_TOKEN']
        ?? '';

    $postToken = $_POST['csrf_token'] ?? '';

    $jsonToken = '';
    if ($headerToken === '' && $postToken === '') {
        $json = getJsonBody();
        $jsonToken = is_array($json) ? ($json['csrf_token'] ?? '') : '';
    }

    $token = $headerToken ?: $postToken ?: $jsonToken;

    if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

/* ======================
     SESSION HELPERS
   ====================== */

function clearSessionCookie(): void {
    if (!ini_get('session.use_cookies')) {
        return;
    }

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
