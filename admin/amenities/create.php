<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';


$data = json_decode(file_get_contents("php://input"), true);

$name     = trim($data['name'] ?? '');
$icon     = trim($data['icon'] ?? '');
$category = trim($data['category'] ?? '');

if (!$name || !$category) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Name and category are required'
    ]);
    exit;
}

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  INSERT INTO amenities (name, icon, category)
  VALUES (?, ?, ?)
");
$stmt->execute([$name, $icon, $category]);

echo json_encode(['success' => true]);
