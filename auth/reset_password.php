<?php
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? '') !== 'local') {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found']);
    exit;
}

// === ⚠️ TEMPORARY TOOL ===
// Use ONLY for development/testing.
// Delete this file before going live!

try {
    // Parse JSON body
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = strtolower(trim($payload['email'] ?? ''));
    $newPassword = (string)($payload['new_password'] ?? '');

    // === Validation ===
    if (!$email || !$newPassword) {
        throw new RuntimeException('Email and new password are required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email format.');
    }

    // === Connect to DB ===
    $db = new Database();

    // Fetch user
    $user = $db->getUserByEmail($email);
    if (!$user) {
        throw new RuntimeException('User not found.');
    }

    // Hash new password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update in DB
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE email = :email");
    $stmt->execute([
        ':hash'  => $newHash,
        ':email' => $email
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Password reset successfully for {$email}"
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
