<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
    $pdo = (new Database())->getPdo();

    $rentalType   = $_GET['rental_type'] ?? null;   // short_term, long_term, corporate, hotel
    $region       = $_GET['region'] ?? null;
    $bedrooms     = $_GET['bedrooms'] ?? null;
    $propertyType = $_GET['property_type_id'] ?? null;
    $minPrice     = $_GET['min_price'] ?? null;
    $maxPrice     = $_GET['max_price'] ?? null;
    $sort         = $_GET['sort'] ?? 'newest';

    $sql = "
        SELECT
            p.id AS property_id,
            p.title,
            p.description,
            p.location,
            p.bedrooms,
            p.bathrooms,
            p.area_sqft,
            p.image_url,
            p.property_type_id,
            pt.type_name AS property_type_name,
            rp.rental_type,
            rp.price_daily,
            rp.price_nightly,
            rp.price_monthly,
            rp.price_yearly
        FROM properties p
        INNER JOIN rental_properties rp ON rp.property_id = p.id
        LEFT JOIN property_types pt ON p.property_type_id = pt.id
        WHERE p.is_published = 1
          AND p.status = 'Available'
          AND p.rental_status = 'Published'
          AND rp.is_active = 1
    ";

    $params = [];

    if (!empty($rentalType)) {
        $sql .= " AND rp.rental_type = ? ";
        $params[] = $rentalType;
    }

    if (!empty($region)) {
        $sql .= " AND p.location LIKE ? ";
        $params[] = "%$region%";
    }

    if (!empty($bedrooms)) {
        $sql .= " AND p.bedrooms >= ? ";
        $params[] = $bedrooms;
    }

    if (!empty($propertyType)) {
        $sql .= " AND p.property_type_id = ? ";
        $params[] = $propertyType;
    }

    if (!empty($minPrice)) {
        $sql .= " AND (
            rp.price_nightly >= ? OR
            rp.price_monthly >= ? OR
            rp.price_yearly >= ?
        )";
        $params[] = $minPrice;
        $params[] = $minPrice;
        $params[] = $minPrice;
    }

    if (!empty($maxPrice)) {
        $sql .= " AND (
            rp.price_nightly <= ? OR
            rp.price_monthly <= ? OR
            rp.price_yearly <= ?
        )";
        $params[] = $maxPrice;
        $params[] = $maxPrice;
        $params[] = $maxPrice;
    }

    switch ($sort) {
        case 'cheapest':
            $sql .= " ORDER BY rp.price_nightly ASC, rp.price_monthly ASC ";
            break;
        case 'expensive':
            $sql .= " ORDER BY rp.price_nightly DESC, rp.price_monthly DESC ";
            break;
        default:
            $sql .= " ORDER BY p.created_at DESC ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach gallery
    $gStmt = $pdo->prepare("
        SELECT image_url
        FROM property_gallery
        WHERE property_id = ?
        ORDER BY uploaded_at ASC
    ");

    foreach ($rows as &$r) {
        $gStmt->execute([$r['property_id']]);
        $imgs = $gStmt->fetchAll(PDO::FETCH_COLUMN);
        $r['gallery'] = $imgs;
    }

    echo json_encode(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
