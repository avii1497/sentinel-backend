<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
header('Content-Type: application/json');

try {
  requireLogin();

  $userId = (int)$_SESSION['user_id'];
  $propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;

  if ($propertyId <= 0) {
    throw new Exception("Missing or invalid property_id");
  }

  $db = new Database();
  $pdo = $db->getPdo();

  $stmt = $pdo->prepare("SELECT 1 FROM wishlists WHERE user_id = ? AND property_id = ? LIMIT 1");
  $stmt->execute([$userId, $propertyId]);

  echo json_encode([
    "success" => true,
    "wishlisted" => (bool)$stmt->fetch()
  ]);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
