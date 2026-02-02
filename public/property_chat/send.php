<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';
header('Content-Type: application/json');

try {
  requireLogin();
  requireRole(['customer', 'premium_customer']);
  requireCsrf();

  $propertyId = v_int($_POST['property_id'] ?? null, 'property id');
  $agentId    = v_int($_POST['agent_id'] ?? null, 'agent id');
  $message    = v_string($_POST['message'] ?? null, 'message', 2000);
  $clientId   = (int)$_SESSION['user_id'];

  $db = new Database();
  $pdo = $db->getPdo();

  $stmt = $pdo->prepare("
    INSERT INTO property_chat
      (property_id, agent_id, client_id, sender, message)
    VALUES (?, ?, ?, 'client', ?)
  ");
  $stmt->execute([$propertyId, $agentId, $clientId, $message]);

  echo json_encode(["success" => true]);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
