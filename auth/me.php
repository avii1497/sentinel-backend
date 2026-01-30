<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

// --------------------------------------------------------
// ✅ Production-safe session cookie config
// - Cross-site frontend (Netlify) calling backend (InfinityFree)
// - Must use SameSite=None + Secure=true when HTTPS
// --------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

    // ✅ SameSite rules:
    // - Cross-site XHR needs SameSite=None
    // - SameSite=None requires Secure=true (browser rule)
    $sameSite = $isHttps ? 'None' : 'Lax';
    $secure   = $isHttps ? true : false;

    // Apply params BEFORE session_start
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);

    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.cookie_samesite', $sameSite);

    session_start();
}

// --------------------------------------------------------
// ✅ Always ensure CSRF exists
// --------------------------------------------------------
$csrfToken = $_SESSION['csrf_token'] ?? issueCsrfToken();

// --------------------------------------------------------
// ❌ Not logged in
// --------------------------------------------------------
if (empty($_SESSION['user_id'])) {
    echo json_encode([
        'success'    => false,
        'error'      => 'Not authenticated',
        'csrf_token' => $csrfToken
    ]);
    exit;
}

// --------------------------------------------------------
// ✅ Refresh user from DB (keep session current)
// --------------------------------------------------------
try {
    $db = new Database();
    $pdo = $db->getPdo();

    $userRow = $db->getUserById((int)$_SESSION['user_id']);
    if ($userRow) {
        $_SESSION['role']       = $userRow['role'] ?? ($_SESSION['role'] ?? '');
        $_SESSION['is_premium'] = (int)($userRow['is_premium'] ?? ($_SESSION['is_premium'] ?? 0));
        $_SESSION['first_name'] = $userRow['first_name'] ?? ($_SESSION['first_name'] ?? '');
        $_SESSION['last_name']  = $userRow['last_name'] ?? ($_SESSION['last_name'] ?? '');
        $_SESSION['email']      = $userRow['email'] ?? ($_SESSION['email'] ?? '');
        $_SESSION['phone']      = $userRow['phone'] ?? ($_SESSION['phone'] ?? '');
        $_SESSION['name']       = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    }

    // --------------------------------------------------------
    // ✅ Optional: attach profile photo for customer roles
    // --------------------------------------------------------
    if (($_SESSION['role'] ?? '') === 'customer' || ($_SESSION['role'] ?? '') === 'premium_customer') {
        $stmt = $pdo->prepare("SELECT profile_photo FROM customers WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $photo = $stmt->fetchColumn();
        if ($photo) {
            $_SESSION['profile_photo'] = $photo;
        }
    }

} catch (Throwable $e) {
    // Best-effort refresh; keep session if DB fails.
}

// --------------------------------------------------------
// ✅ Normalize name fields
// --------------------------------------------------------
$firstName = $_SESSION['first_name'] ?? '';
$lastName  = $_SESSION['last_name'] ?? '';

if ($firstName && strpos($firstName, ' ') !== false && !$lastName) {
    [$firstName, $lastName] = explode(' ', $firstName, 2);
}

// --------------------------------------------------------
// ✅ Build user payload
// --------------------------------------------------------
$userData = [
    'id'         => (int)($_SESSION['user_id'] ?? 0),
    'first_name' => $firstName,
    'last_name'  => $lastName,
    'email'      => $_SESSION['email'] ?? '',
    'phone'      => $_SESSION['phone'] ?? '',
    'role'       => $_SESSION['role'] ?? '',
    'is_premium' => (int)($_SESSION['is_premium'] ?? 0),

    // ROLE IDS
    'agent_id'    => $_SESSION['agent_id'] ?? null,
    'owner_id'    => $_SESSION['owner_id'] ?? null,
    'customer_id' => $_SESSION['customer_id'] ?? null,
];

if (!empty($_SESSION['profile_photo'])) {
    $userData['profile_photo'] = $_SESSION['profile_photo'];
}

// --------------------------------------------------------
// 🎉 Success
// --------------------------------------------------------
echo json_encode([
    'success'    => true,
    'user'       => $userData,
    'csrf_token' => $csrfToken
]);
