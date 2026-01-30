<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
header('Content-Type: application/json');

try {
  requireLogin();

  $userId = (int)$_SESSION['user_id'];

  $db = new Database();
  $pdo = $db->getPdo();

  
  $sql = "
    SELECT 
      w.id AS wishlist_id,
      w.created_at,
      p.id AS property_id,
      p.title,
      p.location,
      p.image_url,
      p.price
    FROM wishlists w
    JOIN properties p ON p.id = w.property_id
    WHERE w.user_id = ?
    ORDER BY w.created_at DESC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([$userId]);
  $rows = $stmt->fetchAll();

  echo json_encode(["success" => true, "data" => $rows]);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
