<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');
$BASE_URL = "http://localhost/sentinel-backend/";

try {
    $owner_id = $_GET['owner_id'] ?? null;
    if (!$owner_id || !is_numeric($owner_id)) {
        throw new Exception('Missing or invalid owner_id');
    }

    $db = new Database();
    $pdo = $db->getPdo();

    // (owner validation... you already have this)
    $stmtOwner = $pdo->prepare("
        SELECT 
            o.id,
            o.owner_type_id,
            o.is_primary_contact,
            ot.type_name
        FROM owners o
        LEFT JOIN owner_types ot ON ot.id = o.owner_type_id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmtOwner->execute([$owner_id]);
    $owner = $stmtOwner->fetch(PDO::FETCH_ASSOC);

    if (!$owner) {
        throw new Exception("Owner not found.");
    }

    // 🏠 Fetch all properties + joins
    $sql = "
        SELECT 
            p.id,
            p.title,
            p.description,
            p.location,
            p.price,
            p.bedrooms,
            p.bathrooms,
            p.area_sqft,
            p.status,
            p.vr_link,
            p.image_url,
            p.model_3d_url,
            p.property_type_id,
            p.listing_type_id,
            p.assigned_agent_id,
            p.is_premium_listing,
            p.created_at,

            pt.type_name AS property_type,
            lt.type_name AS listing_type,

            a.id AS agent_id,
            CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
            a.agency AS agent_agency,

            (
                SELECT status 
                FROM owner_agent_link 
                WHERE owner_id = p.owner_id
                  AND agent_id = p.assigned_agent_id
                LIMIT 1
            ) AS agent_link_status

        FROM properties p
        LEFT JOIN property_types pt ON p.property_type_id = pt.id
        LEFT JOIN listing_types lt ON p.listing_type_id = lt.id
        LEFT JOIN agents a ON p.assigned_agent_id = a.id
        LEFT JOIN users u ON a.user_id = u.id
        WHERE p.owner_id = :owner_id
        ORDER BY p.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':owner_id' => $owner_id]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 🖼 Load gallery per property
    $galleryStmt = $pdo->prepare("
        SELECT image_url 
        FROM property_gallery 
        WHERE property_id = ?
    ");

    // 🆕 Load documents per property
    $docsStmt = $pdo->prepare("
        SELECT document_key, document_label, file_url
        FROM property_documents
        WHERE property_id = ?
    ");
// Load rental config
$rentalStmt = $pdo->prepare("
    SELECT rental_type, price_nightly, price_daily, price_monthly, price_yearly, is_active
    FROM rental_properties
    WHERE property_id = ?
");

foreach ($properties as &$p) {

    // Gallery
    $galleryStmt->execute([$p['id']]);
    
$rawGallery = $galleryStmt->fetchAll(PDO::FETCH_COLUMN);

$p['gallery'] = array_map(function ($img) use ($BASE_URL) {
    return $BASE_URL . $img;
}, $rawGallery);

  // ✅ FIX MAIN IMAGE URL (THIS WAS MISSING)
    if (!empty($p['image_url'])) {
        $p['image_url'] = $BASE_URL . $p['image_url'];
    }

    // Documents
    $docsStmt->execute([$p['id']]);
    $p['documents'] = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Rental config
    $rentalStmt->execute([$p['id']]);
    $rows = $rentalStmt->fetchAll(PDO::FETCH_ASSOC);

    $p['rental_config'] = [];

    foreach ($rows as $r) {
        $p['rental_config'][$r['rental_type']] = [
            "is_active"      => (int)$r['is_active'],
            "price_nightly"  => $r['price_nightly'],
            "price_daily"    => $r['price_daily'],
            "price_monthly"  => $r['price_monthly'],
            "price_yearly"   => $r['price_yearly']
        ];
    }

    $p['is_rental_enabled'] = count($p['rental_config']) > 0;
}


    echo json_encode([
        'success' => true,
        'count' => count($properties),
        'data' => $properties
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
