<?php
require_once __DIR__ . '/../Database.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(["success" => false, "error" => "Not logged in"]);
  exit;
}

$db = new Database();
$pdo = $db->getPdo();

/**
 * Resolve CUSTOMER_ID from logged-in USER
 */
$stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $_SESSION['user_id']]);
$customer = $stmt->fetch();

if (!$customer) {
  http_response_code(403);
  echo json_encode(["success" => false, "error" => "Not a customer"]);
  exit;
}

$tenantId = (int)$customer['id'];
