<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

function getClientIp(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// ✅ Configure session for cross-origin access (React <-> PHP)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');      // set to 1 when using HTTPS
    ini_set('session.cookie_httponly', '1');
    session_start();
}

try {
    $db = new Database();

    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = strtolower(trim($payload['email'] ?? ''));
    $password = (string)($payload['password'] ?? '');

    // === Validate Inputs ===
    if (!$email || !$password) {
        throw new RuntimeException('Please enter both email and password.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email format.');
    }

    // === Fetch user by email ===
    $user = $db->getUserByEmail($email);
    if (!$user) {
        throw new RuntimeException('Account not found.');
    }

    // === Verify password ===
    if (!password_verify($password, $user['password_hash'])) {
        throw new RuntimeException('Invalid credentials.');
    }

    // === Check if active ===
    if (isset($user['status']) && (int)$user['status'] !== 1) {
        throw new RuntimeException('Account is inactive. Please contact support.');
    }

    session_regenerate_id(true);
    

    // === Update last login ===
    $db->updateLastLogin((int)$user['id']);
    $ipAddress = getClientIp();
    $ipAddress = $ipAddress !== '' ? $ipAddress : null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $userAgent = $userAgent !== '' ? $userAgent : null;
    try {
        $db->logUserLogin((int)$user['id'], $ipAddress, $userAgent);
    } catch (Throwable $e) {
        // Best-effort audit logging.
    }

    // === Create session ===
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $_SESSION['email'] = $user['email'];
    $_SESSION['is_premium'] = (int)($user['is_premium'] ?? 0);

    // === Fetch owner_id or agent_id ===
    $pdo = $db->getPdo();
    $owner_id = null;
    $agent_id = null;

    if ($user['role'] === 'owner') {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $owner_id = $stmt->fetchColumn();
        $_SESSION['owner_id'] = $owner_id;
    } elseif ($user['role'] === 'agent') {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $agent_id = $stmt->fetchColumn();
        $_SESSION['agent_id'] = $agent_id;
    }

    $csrfToken = issueCsrfToken();

    // === Optional: map redirect per role ===
    $redirectMap = [
        'owner' => '/owner/dashboard',
        'agent' => '/agent/dashboard',
        'customer' => '/client/dashboard',
        'premium_customer' => '/client/dashboard'
    ];
    $redirect = $redirectMap[$user['role']] ?? '/dashboard';

    // === Success Response ===
    echo json_encode([
        'success' => true,
        'message' => 'Login successful.',
        'user' => [
            'id' => (int)$user['id'],
            'name' => $_SESSION['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'owner_id' => $owner_id,
            'agent_id' => $agent_id
        ],
        'csrf_token' => $csrfToken,
        'redirect' => $redirect
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
