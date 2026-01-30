<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../config/jwt.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['email']) || empty($data['password'])) {
  http_response_code(400);
  echo json_encode([
    'success' => false,
    'message' => 'Email and password required'
  ]);
  exit;
}

$email = trim($data['email']);
$password = $data['password'];

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
