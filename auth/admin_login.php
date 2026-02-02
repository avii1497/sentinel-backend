<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');

// Start session (once)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', '0'); // set to 1 if HTTPS
    ini_set('session.cookie_httponly', '1');
    session_start();
}

// 1️⃣ Try to read from POST (for Android @FormUrlEncoded)
$email = $_POST['email'] ?? null;
$password = $_POST['password'] ?? null;

// 2️⃣ If POST is empty, fall back to JSON body (for React/axios JSON)
if ($email === null || $password === null || $email === '' || $password === '') {
    $input = json_decode(file_get_contents("php://input"), true) ?? [];
    $input = sanitize_array($input);
    $email    = $input['email'] ?? $email;
    $password = $input['password'] ?? $password;
}

$email = v_email($email, 'email');
$password = v_string($password, 'password', 256);

try {
    $pdo = (new Database())->getPdo();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // USER NOT FOUND
    if (!$user) {
        echo json_encode(["success" => false, "error" => "Invalid login"]);
        exit;
    }

    // NOT ADMIN
    if ($user['role'] !== 'admin') {
        echo json_encode(["success" => false, "error" => "Access denied"]);
        exit;
    }

    // WRONG PASSWORD
    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(["success" => false, "error" => "Incorrect password"]);
        exit;
    }

    session_regenerate_id(true);
    $csrfToken = issueCsrfToken();

    // LOGIN SUCCESS → Save session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = "admin";
    $_SESSION['name']    = $user['first_name'] . " " . $user['last_name'];

    echo json_encode([
        "success" => true,
        "user" => [
            "id"    => $user['id'],
            "email" => $user['email'],
            "name"  => $user['first_name'] . " " . $user['last_name'],
            "role"  => "admin"
        ],
        "csrf_token" => $csrfToken
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error"   => "Server error: " . $e->getMessage()
    ]);
}
