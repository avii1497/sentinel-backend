<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
requireLogin();
requireRole('agent');

$agentId = (int)$_SESSION['agent_id'];

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  SELECT COUNT(*) 
  FROM property_chat
  WHERE agent_id = ? AND is_read = 0 AND sender = 'client'
");
$stmt->execute([$agentId]);

echo json_encode([
  'success' => true,
  'count' => (int)$stmt->fetchColumn()
]);
