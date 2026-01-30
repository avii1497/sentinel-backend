<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
  $db = new Database();
  $pdo = $db->getPdo();

  // Fetch all listing types
  $stmt = $pdo->query("SELECT id, type_name, description FROM listing_types ORDER BY id ASC");
  $listingTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "success" => true,
    "count" => count($listingTypes),
    "data" => $listingTypes
  ]);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    "success" => false,
    "error" => $e->getMessage()
  ]);
}
