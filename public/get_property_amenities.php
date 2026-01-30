<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

try {
    if (!isset($_GET['property_id']) || !is_numeric($_GET['property_id'])) {
        throw new Exception("Invalid property_id");
    }

    $propertyId = (int) $_GET['property_id'];

    $db = new Database();
    $pdo = $db->getPdo();

    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.icon, a.category
        FROM amenities a
        JOIN property_amenities pa ON pa.amenity_id = a.id
        WHERE pa.property_id = ?
        ORDER BY a.category, a.name
    ");

    $stmt->execute([$propertyId]);
    $amenities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $amenities
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
