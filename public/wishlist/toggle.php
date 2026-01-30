<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
header('Content-Type: application/json');

try {
  requireLogin();
  requireCsrf();

  $userId = (int)$_SESSION['user_id'];
  $propertyId = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;

  if ($propertyId <= 0) {
    throw new Exception("Missing or invalid property_id");
  }

  $db = new Database();
  $pdo = $db->getPdo();

  // Check existing
  $check = $pdo->prepare("SELECT id FROM wishlists WHERE user_id = ? AND property_id = ? LIMIT 1");
  $check->execute([$userId, $propertyId]);
  $existing = $check->fetch();

  if ($existing) {
    // Remove
    $del = $pdo->prepare("DELETE FROM wishlists WHERE user_id = ? AND property_id = ?");
    $del->execute([$userId, $propertyId]);

    echo json_encode([
      "success" => true,
      "action" => "removed",
      "wishlisted" => false
    ]);
    exit;
  }

  // Add
  $ins = $pdo->prepare("INSERT INTO wishlists (user_id, property_id) VALUES (?, ?)");
  $ins->execute([$userId, $propertyId]);

  echo json_encode([
    "success" => true,
    "action" => "added",
    "wishlisted" => true
  ]);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
