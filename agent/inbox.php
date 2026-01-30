<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
requireLogin();
requireRole('agent');

$agentId = (int)$_SESSION['agent_id'];

$db = new Database();
$pdo = $db->getPdo();

$sql = "
SELECT
  p.id AS property_id,
  p.title AS property_title,
  COUNT(CASE WHEN pc.is_read = 0 AND pc.sender = 'client' THEN 1 END) AS unread_count,
  MAX(pc.created_at) AS last_message_time
FROM property_chat pc
JOIN properties p ON p.id = pc.property_id
WHERE pc.agent_id = ?
GROUP BY p.id
ORDER BY last_message_time DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$agentId]);

echo json_encode([
  'success' => true,
  'inbox' => $stmt->fetchAll()
]);
