<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../../lib/validation.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$data = is_array($data) ? sanitize_array($data) : [];

$email = v_email($data['email'] ?? null);
$password = v_string($data['password'] ?? null, 'password', 256);

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("SELECT * FROM admins WHERE email = :email LIMIT 1");
$stmt->execute(['email' => $email]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || !password_verify($password, $admin['password_hash'])) {
  http_response_code(401);
  echo json_encode([
    'success' => false,
    'message' => 'Invalid admin credentials'
  ]);
  exit;
}

$token = createAdminToken($admin);

echo json_encode([
  'success' => true,
  'token' => $token,
  'admin' => [
    'id' => (int)$admin['id'],
    'email' => $admin['email']
  ]
]);
