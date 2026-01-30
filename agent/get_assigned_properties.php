<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json; charset=UTF-8");

// 🔐 Validate agent_id
$agent_id = $_GET['agent_id'] ?? null;
if (!$agent_id || !is_numeric($agent_id)) {
    echo json_encode([
        "success" => false,
        "error" => "Missing or invalid agent_id"
    ]);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->getPdo();

    // =====================================================
    // FETCH PROPERTIES ASSIGNED TO AGENT
    // =====================================================
    $sql = "
        SELECT 
            p.id AS property_id,
            p.title,
            p.description,
            p.location,
            p.price,
            p.status,
            p.image_url,
            p.model_3d_url,
            p.vr_link,
            p.bedrooms,
            p.bathrooms,
            p.area_sqft,
            p.created_at,
            p.property_type_id,
            p.listing_type_id,
            p.is_premium_listing,  -- ⭐ NEW premium field

            -- Listing type (Rent/Sale)
            lt.type_name AS listing_type,

            -- Property type (Villa, Apartment, etc.)
            pt.type_name AS property_type,

            -- OWNER INFO
            o.id AS owner_id,
            CONCAT(u.first_name, ' ', u.last_name) AS owner_name,
            u.email AS owner_email,
            o.phone AS owner_phone,
            o.address AS owner_address

        FROM properties p
        JOIN owners o ON p.owner_id = o.id
        JOIN users u ON o.user_id = u.id

        LEFT JOIN property_types pt ON p.property_type_id = pt.id
        LEFT JOIN listing_types lt ON p.listing_type_id = lt.id

        WHERE p.assigned_agent_id = :agent_id
        ORDER BY p.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['agent_id' => $agent_id]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =====================================================
    // LOAD GALLERY IMAGES
    // =====================================================
    $galleryStmt = $pdo->prepare("SELECT image_url FROM property_gallery WHERE property_id = ?");

    foreach ($properties as &$prop) {
        $galleryStmt->execute([$prop['property_id']]);
        $prop['gallery'] = $galleryStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    echo json_encode([
        "success" => true,
        "count" => count($properties),
        "data" => $properties
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Server error: " . $e->getMessage()
    ]);
}
