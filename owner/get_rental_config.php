<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy rental configuration fetch.
// Safe to remove after: 2026-06-30 (rental settings now use /rental/get_rental_settings.php).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
    if (empty($_SESSION['user_id'])) {
        throw new Exception("Not authenticated");
    }

    $userId = (int) $_SESSION['user_id'];
    $propertyId = isset($_GET['property_id']) ? (int) $_GET['property_id'] : 0;

    if ($propertyId <= 0) {
        throw new Exception("Missing or invalid property_id");
    }

    $pdo = (new Database())->getPdo();

    // 1) Get owner ID linked to this user
    $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ?");
    $stmt->execute([$userId]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$owner) {
        throw new Exception("You are not registered as an owner.");
    }

    $ownerId = (int) $owner['id'];

    // 2) Ensure the property belongs to this owner
    $stmt = $pdo->prepare("
        SELECT p.*, pt.type_name AS property_type_name, lt.type_name AS listing_type_name
        FROM properties p
        LEFT JOIN property_types pt ON p.property_type_id = pt.id
        LEFT JOIN listing_types lt ON p.listing_type_id = lt.id
        WHERE p.id = ? AND p.owner_id = ?
    ");
    $stmt->execute([$propertyId, $ownerId]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        throw new Exception("Property not found or not owned by you.");
    }

    // 3) Load rental configuration rows
    $stmt = $pdo->prepare("
        SELECT *
        FROM rental_properties
        WHERE property_id = ?
        ORDER BY rental_type ASC
    ");
    $stmt->execute([$propertyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rentalConfig = [];

    foreach ($rows as $r) {
        $rentalConfig[$r['rental_type']] = [
            "price_nightly"     => $r['price_nightly'],
            "price_daily"       => $r['price_daily'],
            "price_monthly"     => $r['price_monthly'],
            "price_yearly"      => $r['price_yearly'],
            "min_stay_nights"   => $r['min_stay_nights'],
            "max_stay_nights"   => $r['max_stay_nights'],
            "max_guests"        => $r['max_guests'],
            "is_active"         => (int)$r['is_active'],
        ];
    }

    echo json_encode([
        "success" => true,
        "data" => [
            "property" => $property,
            "rental_config" => $rentalConfig
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
