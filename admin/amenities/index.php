<?php

require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';



$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->query("
  SELECT id, name, icon, category, created_at
  FROM amenities
  ORDER BY category, name
");

echo json_encode([
  'success' => true,
  'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
