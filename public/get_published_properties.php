<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy published properties endpoint.
// Safe to remove after: 2026-06-30 (use /public/get_properties_market.php).
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json; charset=UTF-8");

try {
$db = new Database();
    $pdo = $db->getPdo();

    // 🆕 Read role from session when available (prevents role spoofing)
    $requestedRole = strtolower($_GET['role'] ?? 'customer');
    $sessionRole = strtolower($_SESSION['role'] ?? '');
    $role = $sessionRole !== '' ? $sessionRole : $requestedRole;
    $isPremiumViewer = ($role === 'premium_customer') || ((int)($_SESSION['is_premium'] ?? 0) === 1);
    if (!$isPremiumViewer) {
        $role = 'customer';
    }

    // 🧠 Detect base URL dynamically
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
        ? "https://" 
        : "http://";

    $host = $_SERVER['HTTP_HOST'];
    $baseGalleryURL = $protocol . $host . "/sentinel-backend/properties/uploads/gallery/";

    // ============================
    //   BUILD SQL QUERY
    // ============================
    $sql = "
        SELECT 
            p.id,
            p.title,
            p.description,
            p.location,
            p.price,
            p.area_sqft,
            p.bedrooms,
            p.bathrooms,
            p.status,
            p.is_published,
            p.is_premium_listing,
            pt.type_name AS property_type,
            lt.type_name AS listing_type,
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

            (
                SELECT image_url
                FROM property_gallery
                WHERE property_id = p.id
                ORDER BY id ASC 
                LIMIT 1
            ) AS first_gallery_image

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
                AND (pr_paid.reservation_status = 'PAID_CONFIRMED' OR pr_paid.payment_status = 'paid')
          )
    ";

    // 🆕 ROLE LOGIC
    // Free customers CANNOT see premium listings
    if ($role === "customer") {
        $sql .= " AND p.is_premium_listing = 0";
    }

    $sql .= " ORDER BY p.created_at DESC";

    // Run query
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================
    //   FIX IMAGE PATHS
    // ============================
    foreach ($data as &$prop) {
        $img = $prop['first_gallery_image'];

        if ($img) {
            // Already absolute URL?
            if (preg_match('/^https?:\/\//', $img)) {
                $prop['image_url'] = $img;
            } else {
                $prop['image_url'] = $baseGalleryURL . $img;
            }
        } else {
            // Fallback
            $prop['image_url'] = $baseGalleryURL . "default-placeholder.jpg";
        }
    }

    echo json_encode([
        "success" => true,
        "count" => count($data),
        "data" => $data
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
