<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
requireLogin();
requireRole('agent');

$agentId = (int)$_SESSION['agent_id'];
$propertyId = (int)($_POST['property_id'] ?? 0);

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  UPDATE property_chat
  SET is_read = 1
  WHERE agent_id = ? AND property_id = ? AND sender = 'client'
");
$stmt->execute([$agentId, $propertyId]);

echo json_encode(['success' => true]);
