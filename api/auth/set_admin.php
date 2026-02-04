<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Local admin bootstrap utility.
// Safe to remove after: 2026-06-30 (once bootstrap process is documented elsewhere).
require_once __DIR__ . '/../Database.php';

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? '') !== 'local') {
    http_response_code(404);
    echo 'Not found';
    exit;
}


$pdo = (new Database())->getPdo();

// 1) choose the email + password you want
$email = $_ENV['ADMIN_BOOTSTRAP_EMAIL'] ?? getenv('ADMIN_BOOTSTRAP_EMAIL') ?? '';
$newPassword = $_ENV['ADMIN_BOOTSTRAP_PASSWORD'] ?? getenv('ADMIN_BOOTSTRAP_PASSWORD') ?? '';

if ($email === '' || $newPassword === '') {
    http_response_code(500);
    echo 'Missing ADMIN_BOOTSTRAP_EMAIL or ADMIN_BOOTSTRAP_PASSWORD';
    exit;
}

// 2) hash password using PHP
$hash = password_hash($newPassword, PASSWORD_DEFAULT);

// 3) update row (or create if not exists)
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $upd = $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin' WHERE email = ?");
    $upd->execute([$hash, $email]);
    echo "Admin updated.";
} else {
    $ins = $pdo->prepare("
        INSERT INTO users (first_name, last_name, email, password_hash, role, status)
        VALUES ('Main', 'Admin', ?, ?, 'admin', 1)
    ");
    $ins->execute([$email, $hash]);
    echo "Admin created.";
}
