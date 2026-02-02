<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy user profile update endpoint.
// Safe to remove after: 2026-06-30 (if profile updates use role-specific endpoints).
require_once __DIR__ . '/../cors.php';

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header('Content-Type: application/json');

requireLogin();

try {
  $payload = json_decode(file_get_contents('php://input'), true) ?? [];
  $payload = sanitize_array($payload ?? []);
  $fields  = $payload['fields'] ?? [];
  if (!is_array($fields)) {
    bad_request('fields must be an object.');
  }
  $fields = sanitize_array($fields);

  if (array_key_exists('first_name', $fields)) {
    $fields['first_name'] = v_string($fields['first_name'], 'first name', 100, 1, false);
  }
  if (array_key_exists('last_name', $fields)) {
    $fields['last_name'] = v_string($fields['last_name'], 'last name', 100, 1, false);
  }
  if (array_key_exists('email', $fields)) {
    $fields['email'] = v_email($fields['email'], 'email', false);
  }
  if (array_key_exists('phone', $fields)) {
    $fields['phone'] = v_phone($fields['phone'], 'phone', false);
  }

  $db = new Database();
  $ok = $db->updateUserProfile((int)$_SESSION['user_id'], $fields);

  echo json_encode(['success' => (bool)$ok]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
