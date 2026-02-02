<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

$data = json_decode(file_get_contents("php://input"), true);
$data = is_array($data) ? sanitize_array($data) : [];
$typeName = v_string($data['type_name'] ?? null, 'type name', 100);

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  INSERT INTO property_types (type_name)
  VALUES (:type_name)
");

$stmt->execute([
  ':type_name' => $typeName
]);

echo json_encode(['success' => true]);
