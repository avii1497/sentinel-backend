<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json");

try {
$pdo = (new Database())->getPdo();
    $sessionRole = strtolower($_SESSION['role'] ?? '');
    $isPremiumViewer = ($sessionRole === 'premium_customer') || ((int)($_SESSION['is_premium'] ?? 0) === 1);

    // ===============================
    // 1️⃣ READ FILTERS
    // ===============================
    $listingType = $_GET['listing_type'] ?? null; 
    $propertyType = $_GET['property_type'] ?? null; 
    $minPrice = $_GET['min_price'] ?? null;
    $maxPrice = $_GET['max_price'] ?? null;
    $bedrooms = $_GET['bedrooms'] ?? null;
    $region = $_GET['region'] ?? null;
    $sort = $_GET['sort'] ?? "newest";

    // ===============================
    // 2️⃣ BASE QUERY
    // ===============================
    $sql = "
        SELECT 
            p.*,
            pt.type_name AS property_type_name,
            lt.type_name AS listing_type_name,
            rp.rental_type AS rental_type,
            (
                SELECT pr.reservation_status
                FROM property_reservations pr
                WHERE pr.property_id = p.id
                  AND pr.cancelled_at IS NULL
                ORDER BY pr.created_at DESC
                LIMIT 1
            ) AS reservation_status,
            (
                SELECT pr.expires_at
                FROM property_reservations pr
                WHERE pr.property_id = p.id
                  AND pr.cancelled_at IS NULL
                ORDER BY pr.created_at DESC
                LIMIT 1
            ) AS reservation_expires_at,

            -- ASSIGNED AGENT INFORMATION
            a.id AS agent_id,
            u.first_name AS agent_first_name,
            u.last_name AS agent_last_name,
            a.phone AS agent_phone,
            a.profile_photo AS agent_photo

        FROM properties p

        LEFT JOIN property_types pt ON p.property_type_id = pt.id
        LEFT JOIN listing_types lt ON p.listing_type_id = lt.id
        LEFT JOIN (
            SELECT property_id, MIN(rental_type) AS rental_type
            FROM rental_properties
            WHERE is_active = 1
            GROUP BY property_id
        ) rp ON rp.property_id = p.id

        LEFT JOIN agents a ON p.assigned_agent_id = a.id
        LEFT JOIN users u ON a.user_id = u.id

        WHERE p.is_published = 1
          AND p.status IN ('Available','Pending')
          AND NOT EXISTS (
              SELECT 1
              FROM property_reservations pr_paid
              WHERE pr_paid.property_id = p.id
                AND pr_paid.cancelled_at IS NULL
                AND (pr_paid.reservation_status = 'PAID_CONFIRMED' OR pr_paid.payment_status = 'paid')
          )
    ";

    $params = [];

    // ===============================
    // 3️⃣ APPLY FILTERS
    // ===============================

    // Premium listing gating (server-side)
    if (!$isPremiumViewer) {
        $sql .= " AND p.is_premium_listing = 0 ";
    }

    // Listing Type — MATCH DB VALUES
    // LISTING TYPE
if ($listingType === "Sale") {
    $sql .= " AND lt.type_name = 'For Sale' ";
}
elseif ($listingType === "Rent") {
    $sql .= " AND lt.type_name = 'For Rent' ";
}
elseif ($listingType === "Lease") {
    $sql .= " AND lt.type_name = 'For Lease' ";
}
elseif ($listingType === "Auction") {
    $sql .= " AND lt.type_name = 'For Auction' ";
}

    // Property Type (STRING)
    if (!empty($_GET['property_type_id'])) {
    $sql .= " AND p.property_type_id = ? ";
    $params[] = $_GET['property_type_id'];
}

    // Price range (MAKE SURE PRICES EXIST IN DB)
    if (!empty($minPrice)) {
        $sql .= " AND p.price >= ? ";
        $params[] = $minPrice;
    }

    if (!empty($maxPrice)) {
        $sql .= " AND p.price <= ? ";
        $params[] = $maxPrice;
    }

    // Bedrooms filter
    if (!empty($bedrooms)) {
        $sql .= " AND p.bedrooms >= ? ";
        $params[] = $bedrooms;
    }

    // Region filter (MATCH LOCATION FIELD IN DB)
    if (!empty($region)) {
        $sql .= " AND p.location LIKE ? ";
        $params[] = "%$region%";
    }

    // Sorting
    switch ($sort) {
        case "cheapest":
            $sql .= " ORDER BY p.price ASC ";
            break;
        case "expensive":
            $sql .= " ORDER BY p.price DESC ";
            break;
        default:
            $sql .= " ORDER BY p.created_at DESC ";
    }

    // ===============================
    // 4️⃣ EXECUTE QUERY
    // ===============================
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===============================
    // 5️⃣ ADD GALLERY IMAGES
    // ===============================
    $gStmt = $pdo->prepare("
        SELECT image_url FROM property_gallery
        WHERE property_id = ?
        ORDER BY uploaded_at ASC
    ");

    foreach ($properties as &$p) {
        $gStmt->execute([$p['id']]);
        $imgs = $gStmt->fetchAll(PDO::FETCH_COLUMN);

        $p['gallery'] = array_map(function($path){
            return "http://localhost/sentinel-backend/" . $path;
        }, $imgs);
    }

    echo json_encode(["success" => true, "data" => $properties]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
