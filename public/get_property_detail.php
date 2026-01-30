<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json; charset=UTF-8");

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $db = new Database();
    $pdo = $db->getPdo();

    // ===============================================================
    // VALIDATE PROPERTY ID
    // ===============================================================
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid property_id"]);
        exit;
    }

    // ===============================================================
    // FETCH PROPERTY DETAILS
    // ===============================================================
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            pt.type_name AS property_type,
            lt.type_name AS listing_type
        FROM properties p
        LEFT JOIN property_types pt ON p.property_type_id = pt.id
        LEFT JOIN listing_types lt ON p.listing_type_id = lt.id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        echo json_encode(["success" => false, "message" => "Property not found"]);
        exit;
    }

    $sessionRole = strtolower($_SESSION['role'] ?? '');
    $isPremiumViewer = ($sessionRole === 'premium_customer') || ((int)($_SESSION['is_premium'] ?? 0) === 1);

    // Premium listing gating (server-side)
    if ((int)($property['is_premium_listing'] ?? 0) === 1) {
        if (!$isPremiumViewer) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Premium listing"]);
            exit;
        }
    }

    // Sold properties hidden from customers (server-side)
    $isClientViewer = ($sessionRole === '') || in_array($sessionRole, ['customer', 'premium_customer'], true);
    if (($property['status'] ?? '') === 'Sold' && $isClientViewer) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Property not available"]);
        exit;
    }

    // Load latest active reservation status (if any)
    $resStmt = $pdo->prepare("
        SELECT reservation_status, payment_status, cancelled_at, expires_at
        FROM property_reservations
        WHERE property_id = ?
          AND cancelled_at IS NULL
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $resStmt->execute([$id]);
    $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);

    $reservationStatus = $reservation['reservation_status'] ?? null;
    if (!$reservationStatus && $reservation) {
        if (($reservation['payment_status'] ?? '') === 'paid') {
            $reservationStatus = 'PAID_CONFIRMED';
        } elseif (!empty($reservation['expires_at']) && strtotime($reservation['expires_at']) < time()) {
            $reservationStatus = 'EXPIRED';
        } else {
            $reservationStatus = 'ACCEPTED_AWAITING_PAYMENT';
        }
    }

    $property['reservation_status'] = $reservationStatus;
    $property['reservation_expires_at'] = $reservation['expires_at'] ?? null;

    if ($isClientViewer && $reservationStatus === 'PAID_CONFIRMED') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Property not available"]);
        exit;
    }

    // ===============================================================
    // PREPARE BASE URL
    // ===============================================================
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        ? "https://"
        : "http://";

    $host = $_SERVER['HTTP_HOST'];
    $baseRoot = $protocol . $host . "/sentinel-backend/";

    // ===============================================================
    // FETCH GALLERY IMAGES
    // ===============================================================
    $galleryStmt = $pdo->prepare("
        SELECT image_url 
        FROM property_gallery 
        WHERE property_id = ?
        ORDER BY id ASC
    ");
    $galleryStmt->execute([$id]);
    $gallery = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);

    // ===============================================================
    // FIX GALLERY IMAGE URLS
    // ===============================================================
    foreach ($gallery as &$img) {
        if (!empty($img['image_url'])) {
            // Already absolute URL?
            if (strpos($img['image_url'], "http") === 0) {
                continue;
            }
            $path = ltrim($img['image_url'], "/");
            if (stripos($path, "sentinel-backend/") === 0) {
                $path = substr($path, strlen("sentinel-backend/"));
            }
            $img['image_url'] = $baseRoot . $path;
        }
    }
    unset($img);

    // ===============================================================
    // SET MAIN IMAGE (first gallery image)
    // ===============================================================
    if (!empty($gallery[0]['image_url'])) {
        $property['image_url'] = $gallery[0]['image_url'];
    } elseif (!empty($property['image_url'])) {
        $path = ltrim($property['image_url'], "/");
        if (stripos($path, "sentinel-backend/") === 0) {
            $path = substr($path, strlen("sentinel-backend/"));
        }
        $property['image_url'] = $baseRoot . $path;
    } else {
        $property['image_url'] = $baseRoot . "properties/uploads/gallery/default-placeholder.jpg";
    }

    echo json_encode([
        "success" => true,
        "property" => $property,
        "gallery"  => $gallery
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
