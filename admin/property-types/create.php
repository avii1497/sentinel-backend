<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['type_name'])) {
  echo json_encode(['success' => false, 'message' => 'Type name required']);
  exit;
}

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  INSERT INTO property_types (type_name)
  VALUES (:type_name)
");

$stmt->execute([
  ':type_name' => $data['type_name']
]);

echo json_encode(['success' => true]);
