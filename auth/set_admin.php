<?php
require_once __DIR__ . '/../Database.php';

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? '') !== 'local') {
    http_response_code(404);
    echo 'Not found';
    exit;
}


$pdo = (new Database())->getPdo();

// 1) choose the email + password you want
$email = 'admin@sentinel.com';
$newPassword = 'Admin123!';

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
