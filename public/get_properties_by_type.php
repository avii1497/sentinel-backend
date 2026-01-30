<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json; charset=UTF-8");

function base_url(): string {
    // Example: http://localhost/sentinel-backend
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? "localhost";
    // If your backend is in /sentinel-backend folder:
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // /public
    // remove trailing "/public"
    $basePath = preg_replace('#/public$#', '', $basePath);
    return $scheme . "://" . $host . $basePath;
}

try {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $pdo = (new Database())->getPdo();

    // Premium gating
    $sessionRole = strtolower($_SESSION['role'] ?? '');
    $isPremiumViewer = ($sessionRole === 'premium_customer') || ((int)($_SESSION['is_premium'] ?? 0) === 1);

    // Filters
    $type = strtolower(trim($_GET['type'] ?? '')); // villa | land | bungalow | etc. (optional)
    $sort = strtolower(trim($_GET['sort'] ?? 'newest')); // newest | cheapest | expensive
    $limit = (int)($_GET['limit'] ?? 0); // optional

    // Base query: all published, Available/Pending, and NOT already paid
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
                AND (pr_paid.reservation_status = 'PAID_CONFIRMED' OR pr_paid.payment_status = 'paid')
          )
    ";

    $params = [];

    // Premium gating server-side
    if (!$isPremiumViewer) {
        $sql .= " AND p.is_premium_listing = 0 ";
    }

    // Property Type filter (villa / land / bungalow)
    // ✅ IMPORTANT: compare in LOWER() so we don’t depend on ucfirst or exact casing
    if ($type !== "") {
        $sql .= " AND LOWER(pt.type_name) = ? ";
        $params[] = $type; // expects property_types.type_name like "villa" OR "Villa"? (LOWER handles)
    }

    // Sorting
    if ($sort === "cheapest") $sql .= " ORDER BY p.price ASC ";
    elseif ($sort === "expensive") $sql .= " ORDER BY p.price DESC ";
    else $sql .= " ORDER BY p.created_at DESC ";

    // Optional limit
    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Gallery
    $galleryStmt = $pdo->prepare("
        SELECT image_url
        FROM property_gallery
        WHERE property_id = ?
        ORDER BY uploaded_at ASC
    ");

    $BASE = base_url();

    foreach ($properties as &$prop) {
        // Fix main image to absolute URL too (so PixelTransition always works)
        if (!empty($prop['image_url']) && strpos($prop['image_url'], 'http') !== 0) {
            $prop['image_url'] = $BASE . "/" . ltrim($prop['image_url'], "/");
        }

        $galleryStmt->execute([$prop['id']]);
        $images = $galleryStmt->fetchAll(PDO::FETCH_COLUMN);

        $prop['gallery'] = array_map(function ($img) use ($BASE) {
            // Your DB usually stores relative paths like "properties/uploads/gallery/xxx.jpg"
            if (strpos($img, "http") === 0) return $img;
            return $BASE . "/" . ltrim($img, "/");
        }, $images);
    }

    echo json_encode([
        "success" => true,
        "data" => $properties
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
    exit;
}
