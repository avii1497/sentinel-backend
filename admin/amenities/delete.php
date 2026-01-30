<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';



$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("DELETE FROM amenities WHERE id = ?");
$stmt->execute([$id]);

echo json_encode(['success' => true]);
