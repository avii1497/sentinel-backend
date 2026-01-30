<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['id']) || empty($data['type_name'])) {
  echo json_encode(['success' => false, 'message' => 'Invalid data']);
  exit;
}

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  UPDATE listing_types
  SET type_name = :type_name,
      description = :description
  WHERE id = :id
");

$stmt->execute([
  ':id' => $data['id'],
  ':type_name' => $data['type_name'],
  ':description' => $data['description'] ?? null
]);

echo json_encode(['success' => true]);
