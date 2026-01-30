<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
    $propertyId = $_GET['property_id'] ?? null;
    $rentalType = $_GET['rental_type'] ?? null;

    if (!$propertyId) {
        throw new Exception("Missing property_id");
    }

    $pdo = (new Database())->getPdo();

    $params = [$propertyId];
    $rtWhere = '';

    if (!empty($rentalType)) {
        $rtWhere = " AND rp.rental_type = ? ";
        $params[] = $rentalType;
    }

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            pt.type_name AS property_type_name,
            rp.rental_type,
            rp.price_daily,
            rp.price_nightly,
            rp.price_monthly,
            rp.price_yearly,
            rp.min_stay_days,
            rp.max_stay_days,
            rp.max_guests
        FROM properties p
        INNER JOIN rental_properties rp ON rp.property_id = p.id
        LEFT JOIN property_types pt ON p.property_type_id = pt.id
        WHERE p.id = ?
          AND p.is_published = 1
          AND p.status = 'Available'
          AND p.rental_status = 'Published'
          AND rp.is_active = 1
        $rtWhere
        LIMIT 1
    ");
    $stmt->execute($params);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        throw new Exception("Property not found or rental config missing");
    }

    // Rules
    $rStmt = $pdo->prepare("SELECT rule_text FROM rental_rules WHERE property_id = ?");
    $rStmt->execute([$propertyId]);
    $rules = $rStmt->fetchAll(PDO::FETCH_COLUMN);

    // Gallery
    $gStmt = $pdo->prepare("
        SELECT image_url FROM property_gallery
        WHERE property_id = ?
        ORDER BY uploaded_at ASC
    ");
    $gStmt->execute([$propertyId]);
    $gallery = $gStmt->fetchAll(PDO::FETCH_COLUMN);

    // Availability next 60 days
    $aStmt = $pdo->prepare("
        SELECT date, is_available, price_override
        FROM rental_availability
        WHERE property_id = ?
          AND date >= CURDATE()
          AND date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
        ORDER BY date ASC
    ");
    $aStmt->execute([$propertyId]);
    $availability = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'property' => $property,
            'rules' => $rules,
            'gallery' => $gallery,
            'availability' => $availability
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
