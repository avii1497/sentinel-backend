<?php

require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$data = is_array($data) ? sanitize_array($data) : [];
$id = v_int($data['id'] ?? null, 'id');
$name = v_string($data['name'] ?? null, 'name', 100);
$icon = v_string($data['icon'] ?? null, 'icon', 100);
$category = v_string($data['category'] ?? null, 'category', 100);

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
    UPDATE amenities
    SET name = :name,
        icon = :icon,
        category = :category
    WHERE id = :id
");

$stmt->execute([
    ':id'       => $id,
    ':name'     => $name,
    ':icon'     => $icon,
    ':category' => $category,
]);

echo json_encode([
    'success' => true,
    'message' => 'Amenity updated'
]);
