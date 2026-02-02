<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

$data = json_decode(file_get_contents("php://input"), true);
$data = is_array($data) ? sanitize_array($data) : [];
$id = v_int($data['id'] ?? null, 'id');
$typeName = v_string($data['type_name'] ?? null, 'type name', 100);
$description = v_string($data['description'] ?? null, 'description', 500, 0, false);

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  UPDATE listing_types
  SET type_name = :type_name,
      description = :description
  WHERE id = :id
");

$stmt->execute([
  ':id' => $id,
  ':type_name' => $typeName,
  ':description' => $description
]);

echo json_encode(['success' => true]);
