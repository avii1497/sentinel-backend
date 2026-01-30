<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
header('Content-Type: application/json');

try {
  requireLogin();
  requireRole(['customer', 'premium_customer']);
  requireCsrf();

  $propertyId = (int)($_POST['property_id'] ?? 0);
  $agentId    = (int)($_POST['agent_id'] ?? 0);
  $message    = trim($_POST['message'] ?? '');
  $clientId   = (int)$_SESSION['user_id'];

  if ($propertyId <= 0 || $agentId <= 0 || $message === '') {
    throw new Exception("Invalid input");
  }

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
