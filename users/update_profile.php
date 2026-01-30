<?php
require_once __DIR__ . '/../cors.php';

require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

requireLogin();

try {
  $payload = json_decode(file_get_contents('php://input'), true) ?? [];
  $fields  = $payload['fields'] ?? [];

  $db = new Database();
  $ok = $db->updateUserProfile((int)$_SESSION['user_id'], $fields);

  echo json_encode(['success' => (bool)$ok]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
