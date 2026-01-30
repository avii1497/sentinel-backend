<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json; charset=UTF-8");

if (!isset($_GET['agent_id'])) {
    echo json_encode(["success" => false, "error" => "Missing agent_id parameter"]);
    exit;
}

$agent_id = (int) $_GET['agent_id'];

try {
    $db = new Database();
    $pdo = $db->getPdo();

    // 🧠 Fetch owners + their property list with image thumbnails
    $sql = "
        SELECT 
            o.id AS owner_id,
            o.company_name,
            o.business_type,
            o.phone AS owner_phone,
            o.address AS owner_address,
            o.profile_pic,
            u.email AS owner_email,
            COUNT(p.id) AS total_properties,
            GROUP_CONCAT(p.title SEPARATOR ', ') AS property_titles,
            GROUP_CONCAT(p.image_url SEPARATOR ',') AS property_images
        FROM owners o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN properties p ON p.owner_id = o.id
        LEFT JOIN owner_agent_link l 
            ON l.owner_id = o.id 
            AND l.agent_id = ?
        WHERE l.status IS NULL OR l.status = 'Declined'
        GROUP BY o.id, o.company_name, o.phone, o.address, u.email, o.profile_pic
        ORDER BY o.company_name ASC
    ";

    $st = $pdo->prepare($sql);
    $st->execute([$agent_id]);
    $owners = $st->fetchAll(PDO::FETCH_ASSOC);

    // ✅ Convert image URLs into arrays
    foreach ($owners as &$owner) {
        $owner['property_images'] = !empty($owner['property_images'])
            ? explode(',', $owner['property_images'])
            : [];
    }

    echo json_encode(["success" => true, "data" => $owners]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
