<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy inbox read-tracking endpoint.
// Safe to remove after: 2026-06-30 (confirm UI does not need per-item read updates).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');
requireLogin();
requireRole('agent');

$agentId = (int)$_SESSION['agent_id'];
$propertyId = v_int($_POST['property_id'] ?? null, 'property id');

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  UPDATE property_chat
  SET is_read = 1
  WHERE agent_id = ? AND property_id = ? AND sender = 'client'
");
$stmt->execute([$agentId, $propertyId]);

echo json_encode(['success' => true]);
