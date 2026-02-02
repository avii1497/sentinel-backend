<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
  $propertyId = (int)($_GET['property_id'] ?? 0);
  if ($propertyId <= 0) {
    throw new Exception("Invalid property ID");
  }

  $db = new Database();
  $pdo = $db->getPdo();

  $stmt = $pdo->prepare("
    SELECT 
      a.id AS agent_id,
      u.first_name,
      u.last_name,
      u.email,
      a.phone,
      a.whatsapp_number,
      a.agency,
      a.position
    FROM properties p
    INNER JOIN owner_agent_link oal 
      ON p.owner_id = oal.owner_id
      AND oal.status = 'Accepted'
    INNER JOIN agents a 
      ON oal.agent_id = a.id
    INNER JOIN users u 
      ON a.user_id = u.id
    WHERE p.id = ?
    LIMIT 1
  ");

  $stmt->execute([$propertyId]);
  $agent = $stmt->fetch();

  if (!$agent) {
    echo json_encode([
      "success" => true,
      "agent" => null
    ]);
    exit;
  }

  echo json_encode([
    "success" => true,
    "agent" => $agent
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "error" => $e->getMessage()
  ]);
}
