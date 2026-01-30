<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json; charset=UTF-8");

$agent_id = $_GET['agent_id'] ?? null;
$owner_id = $_GET['owner_id'] ?? null;

if (!$agent_id || !$owner_id || !is_numeric($agent_id) || !is_numeric($owner_id)) {
    echo json_encode([
        "success" => false,
        "error" => "Invalid agent_id or owner_id"
    ]);
    exit;
}

try {
    $pdo = (new Database())->getPdo();

    $sql = "
        SELECT 
            p.id AS property_id,
            p.title,
            p.location,
            p.price,
            p.image_url,
            (
                SELECT image_url 
                FROM property_gallery 
                WHERE property_id = p.id 
                ORDER BY id ASC 
                LIMIT 1
            ) AS gallery_image
        FROM properties p
        WHERE p.owner_id = ?
          AND p.assigned_agent_id = ?
        ORDER BY p.title ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$owner_id, $agent_id]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($properties as &$prop) {
        // Use gallery image first
        $img = $prop['gallery_image'] ?: $prop['image_url'];

        if ($img) {
            $prop['thumbnail'] = "http://localhost/sentinel-backend/properties/uploads/gallery/" . $img;
        } else {
            $prop['thumbnail'] = null;
        }
    }

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
