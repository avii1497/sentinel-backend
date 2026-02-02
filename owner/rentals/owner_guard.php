<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header("Content-Type: application/json");

requireLogin();
requireRole('owner');

$db = new Database();
$pdo = $db->getPdo();

// Ensure owner_id exists in session
if (empty($_SESSION['owner_id'])) {
  $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
  $stmt->execute([ (int)$_SESSION['user_id'] ]);
  $ownerId = $stmt->fetchColumn();

  if (!$ownerId) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Owner profile not found."]);
    exit;
  }

  $_SESSION['owner_id'] = (int)$ownerId;
}

$OWNER_ID = (int)$_SESSION['owner_id'];
