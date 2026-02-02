<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json");

try {
$pdo = (new Database())->getPdo();

    $sessionRole = strtolower($_SESSION['role'] ?? '');
    $isPremiumViewer = ($sessionRole === 'premium_customer') || ((int)($_SESSION['is_premium'] ?? 0) === 1);

    // ===============================
    // 1️⃣ READ FILTERS FROM URL
    // ===============================
    $listingType  = strtolower(trim($_GET['listing_type'] ?? '')); // rent | sale | land
    $propertyType = $_GET['property_type'] ?? null; // land | villa | etc.
    $minPrice     = $_GET['min_price'] ?? null;
    $maxPrice     = $_GET['max_price'] ?? null;
    $bedrooms     = $_GET['bedrooms'] ?? null;
    $region       = $_GET['region'] ?? null;
    $sort         = $_GET['sort'] ?? "newest"; // newest | cheapest | expensive
    $isFeatured   = isset($_GET['featured']) && $_GET['featured'] === '1';

    // ===============================
    // 2️⃣ BASE QUERY (SAFE & GENERIC)
    // ===============================
    $sql = "
        SELECT 
            p.*,
            pt.type_name,
            lt.type_name AS listing_name,

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
            ) AS reservation_expires_at

        FROM properties p
        LEFT JOIN property_types pt ON p.property_type_id = pt.id
        LEFT JOIN listing_types lt ON p.listing_type_id = lt.id

        WHERE p.is_published = 1
          AND p.status IN ('Available','Pending')

          AND NOT EXISTS (
              SELECT 1
              FROM property_reservations pr_paid
              WHERE pr_paid.property_id = p.id
                AND pr_paid.cancelled_at IS NULL
                AND (
                    pr_paid.reservation_status = 'PAID_CONFIRMED'
                    OR pr_paid.payment_status = 'paid'
                )
          )
    ";

    $params = [];

    // ===============================
    // 3️⃣ PREMIUM VISIBILITY GATE
    // ===============================
    if (!$isPremiumViewer) {
        $sql .= " AND p.is_premium_listing = 0 ";
    }

    // ===============================
    // 4️⃣ FEATURED FILTER (FIXED)
    // ===============================
    if ($isFeatured) {
        $sql .= " AND p.is_premium_listing = 1 ";
    }

    // ===============================
    // 5️⃣ LISTING INTENT (FIXED)
    // ===============================
    if ($listingType === "Rent") {
        $sql .= " AND LOWER(lt.type_name) = 'for rent' ";
    } elseif ($listingType === "Sale") {
        $sql .= " AND LOWER(lt.type_name) = 'for sale' ";
    } elseif ($listingType === "land") {
        $sql .= " AND pt.type_name = 'Land' ";
    }

    // ===============================
    // 6️⃣ PROPERTY TYPE FILTER
    // ===============================
    if (!empty($propertyType)) {
        $sql .= " AND pt.type_name = ? ";
        $params[] = ucfirst($propertyType);
    }

    // ===============================
    // 7️⃣ PRICE FILTERS (LAND SAFE)
    // ===============================
    if (!empty($minPrice)) {
        $sql .= " AND (pt.type_name != 'Land' AND p.price >= ?) ";
        $params[] = $minPrice;
    }

    if (!empty($maxPrice)) {
        $sql .= " AND (pt.type_name != 'Land' AND p.price <= ?) ";
        $params[] = $maxPrice;
    }

    // ===============================
    // 8️⃣ BEDROOM FILTER (LAND SAFE)
    // ===============================
    if (!empty($bedrooms)) {
        $sql .= " AND (pt.type_name != 'Land' AND p.bedrooms >= ?) ";
        $params[] = $bedrooms;
    }

    // ===============================
    // 9️⃣ REGION FILTER
    // ===============================
    if (!empty($region)) {
        $sql .= " AND p.location LIKE ? ";
        $params[] = "%$region%";
    }

    // ===============================
    // 🔟 SORTING
    // ===============================
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
    // 1️⃣1️⃣ EXECUTE QUERY
    // ===============================
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===============================
    // 1️⃣2️⃣ LOAD GALLERY
    // ===============================
    $galleryStmt = $pdo->prepare("
        SELECT image_url 
        FROM property_gallery 
        WHERE property_id = ?
        ORDER BY uploaded_at ASC
    ");

    foreach ($properties as &$prop) {
        $galleryStmt->execute([$prop['id']]);
        $images = $galleryStmt->fetchAll(PDO::FETCH_COLUMN);

        $prop['gallery'] = array_map(
            fn($img) => "http://localhost/sentinel-backend/" . $img,
            $images
        );
    }

    // ===============================
    // 1️⃣3️⃣ RESPONSE
    // ===============================
    echo json_encode([
        "success" => true,
        "data" => $properties
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
