<?php
require_once __DIR__ . '/../cors.php'; 
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

try {
  $db = new Database();
  $pdo = $db->getPdo();

  $stmt = $pdo->query("SELECT id, type_name FROM property_types ORDER BY type_name");
  $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['success' => true, 'data' => $types]);
} catch (Throwable $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
