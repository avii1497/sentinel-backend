<?php

require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (
    empty($data['id']) ||
    empty($data['name']) ||
    empty($data['icon']) ||
    empty($data['category'])
) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'All fields are required'
    ]);
    exit;
}

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
    ':id'       => $data['id'],
    ':name'     => trim($data['name']),
    ':icon'     => trim($data['icon']),
    ':category' => trim($data['category']),
]);

echo json_encode([
    'success' => true,
    'message' => 'Amenity updated'
]);
