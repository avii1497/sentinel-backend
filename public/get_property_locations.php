<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

function buildNominatimQuery(string $location): string
{
    $query = trim($location);
    if ($query === '') {
        return '';
    }

    if (stripos($query, 'mauritius') === false) {
        $query .= ', Mauritius';
    }

    return $query;
}

function geocodeWithNominatim(string $query): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $email = getenv('NOMINATIM_EMAIL') ?: '';
    $params = [
        'format' => 'jsonv2',
        'q' => $query,
        'limit' => 1,
        'addressdetails' => 0,
    ];
    if ($email !== '') {
        $params['email'] = $email;
    }

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);

    $userAgent = getenv('NOMINATIM_USER_AGENT');
    if (!$userAgent) {
        $userAgent = $email !== '' ? "SentinelProperty/1.0 ({$email})" : "SentinelProperty/1.0";
    }

    $response = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $userAgent,
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            return null;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: {$userAgent}\r\n",
                'timeout' => 8,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data)) {
        return null;
    }

    $lat = isset($data[0]['lat']) ? (float)$data[0]['lat'] : null;
    $lng = isset($data[0]['lon']) ? (float)$data[0]['lon'] : null;
    if (!is_finite($lat) || !is_finite($lng)) {
        return null;
    }

    return ['lat' => $lat, 'lng' => $lng];
}

try {
    $pdo = (new Database())->getPdo();

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $baseRoot = $protocol . $host . '/sentinel-backend/';

    $propertyId = isset($_GET['property_id']) ? intval($_GET['property_id']) : 0;

    if ($propertyId > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                title,
                location,
                latitude,
                longitude,
                price,
                (
                    SELECT image_url
                    FROM property_gallery
                    WHERE property_id = properties.id
                    ORDER BY id ASC
                    LIMIT 1
                ) AS first_gallery_image
            FROM properties
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$propertyId]);
        $property = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$property) {
            echo json_encode([
                "success" => false,
                "error" => "Property not found"
            ]);
            exit;
        }

        $lat = isset($property['latitude']) ? (float)$property['latitude'] : null;
        $lng = isset($property['longitude']) ? (float)$property['longitude'] : null;

        if (!is_finite($lat) || !is_finite($lng)) {
            $query = buildNominatimQuery((string)($property['location'] ?? ''));
            if ($query !== '') {
                $geo = geocodeWithNominatim($query);
                if ($geo) {
                    $lat = $geo['lat'];
                    $lng = $geo['lng'];
                    $upd = $pdo->prepare("UPDATE properties SET latitude = ?, longitude = ? WHERE id = ?");
                    $upd->execute([$lat, $lng, $propertyId]);
                    $property['latitude'] = $lat;
                    $property['longitude'] = $lng;
                }
            }
        }

        if (!empty($property['first_gallery_image'])) {
            $img = $property['first_gallery_image'];
            $property['image_url'] = (strpos($img, 'http') === 0) ? $img : $baseRoot . ltrim($img, '/');
        }

        echo json_encode([
            "success" => true,
            "data" => $property
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                title,
                location,
                latitude,
                longitude,
                price,
                (
                    SELECT image_url
                    FROM property_gallery
                    WHERE property_id = properties.id
                    ORDER BY id ASC
                    LIMIT 1
                ) AS first_gallery_image
            FROM properties
            WHERE 
                is_published = 1
                AND status IN ('Available','Pending')
                AND NOT EXISTS (
                    SELECT 1
                    FROM property_reservations pr_paid
                    WHERE pr_paid.property_id = properties.id
                      AND pr_paid.cancelled_at IS NULL
                      AND (pr_paid.reservation_status = 'PAID_CONFIRMED' OR pr_paid.payment_status = 'paid')
                )
                AND listing_type_id IS NOT NULL
                AND latitude IS NOT NULL
                AND longitude IS NOT NULL
        ");

        $stmt->execute();
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($properties as &$prop) {
            if (!empty($prop['first_gallery_image'])) {
                $img = $prop['first_gallery_image'];
                $prop['image_url'] = (strpos($img, 'http') === 0) ? $img : $baseRoot . ltrim($img, '/');
            }
        }
        unset($prop);

        echo json_encode([
            "success" => true,
            "data" => $properties
        ]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
