<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

$data = json_decode(file_get_contents("php://input"), true);
$data = is_array($data) ? sanitize_array($data) : [];
$typeName = v_string($data['type_name'] ?? null, 'type name', 100);
$description = v_string($data['description'] ?? null, 'description', 500, 0, false);

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  INSERT INTO listing_types (type_name, description)
  VALUES (:type_name, :description)
");

$stmt->execute([
  ':type_name' => $typeName,
  ':description' => $description
]);

echo json_encode(['success' => true]);
