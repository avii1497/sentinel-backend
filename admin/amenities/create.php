<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';


$data = json_decode(file_get_contents("php://input"), true);

$data = is_array($data) ? sanitize_array($data) : [];
$name = v_string($data['name'] ?? null, 'name', 100);
$category = v_string($data['category'] ?? null, 'category', 100);
$icon = v_string($data['icon'] ?? null, 'icon', 100, 0, false);

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
  INSERT INTO amenities (name, icon, category)
  VALUES (?, ?, ?)
");
$stmt->execute([$name, $icon, $category]);

echo json_encode(['success' => true]);
