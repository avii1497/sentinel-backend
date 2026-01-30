<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['id'])) {
  echo json_encode(['success' => false, 'message' => 'ID required']);
  exit;
}

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("DELETE FROM property_types WHERE id = :id");
$stmt->execute([':id' => $data['id']]);

echo json_encode(['success' => true]);
