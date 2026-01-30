<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->query("SELECT * FROM listing_types ORDER BY id ASC");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  'success' => true,
  'data' => $data
]);
