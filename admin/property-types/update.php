<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

$data = json_decode(file_get_contents("php://input"), true);
$data = is_array($data) ? sanitize_array($data) : [];
$id = v_int($data['id'] ?? null, 'id');
$typeName = v_string($data['type_name'] ?? null, 'type name', 100);

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  UPDATE property_types
  SET type_name = :type_name
  WHERE id = :id
");

$stmt->execute([
  ':id' => $id,
  ':type_name' => $typeName
]);

echo json_encode(['success' => true]);
