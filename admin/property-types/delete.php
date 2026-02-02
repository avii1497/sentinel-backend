<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

$data = json_decode(file_get_contents("php://input"), true);
$data = is_array($data) ? sanitize_array($data) : [];
$id = v_int($data['id'] ?? null, 'id');

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("DELETE FROM property_types WHERE id = :id");
$stmt->execute([':id' => $id]);

echo json_encode(['success' => true]);
