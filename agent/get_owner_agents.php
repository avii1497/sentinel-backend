<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json; charset=UTF-8");

$owner_id = $_GET['owner_id'] ?? null;
if (!$owner_id || !is_numeric($owner_id)) {
    echo json_encode([
        "success" => false,
        "error" => "Missing or invalid owner_id"
    ]);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->getPdo();

    // ✅ Fetch agents ONLY linked to this owner via owner_agent_link
    $sql = "
        SELECT 
            a.id AS agent_id,
            a.agency,
            a.position,
            a.profile_photo,
            a.years_of_experience,
            a.specialization,
            a.commission_rate,
            a.phone,
            CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
            u.email AS agent_email
        FROM owner_agent_link l
        INNER JOIN agents a ON l.agent_id = a.id
        INNER JOIN users u ON u.id = a.user_id
        WHERE l.owner_id = :owner_id
          AND l.status = 'Accepted'
        ORDER BY agent_name ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['owner_id' => $owner_id]);
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ If no agents linked, return empty
    if (empty($agents)) {
        echo json_encode([
            "success" => true,
            "count" => 0,
            "data" => []
        ]);
        exit;
    }

    // ✅ Fetch assigned properties per agent
    $pstmt = $pdo->prepare("
        SELECT 
            id AS property_id,
            title,
            location,
            price,
            status,
            (
                SELECT image_url 
                FROM property_gallery 
                WHERE property_id = properties.id 
                ORDER BY id ASC 
                LIMIT 1
            ) AS image_url
        FROM properties
        WHERE owner_id = :owner_id
          AND assigned_agent_id = :agent_id
        ORDER BY created_at DESC
    ");

    foreach ($agents as &$agent) {
        $pstmt->execute([
            'owner_id' => $owner_id,
            'agent_id' => $agent['agent_id']
        ]);
        $props = $pstmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($props as &$prop) {
            if (!empty($prop['image_url']) && strpos($prop['image_url'], 'http') !== 0) {
                $prop['image_url'] = "http://localhost/sentinel-backend/properties/uploads/gallery/" . $prop['image_url'];
            }
        }
        unset($prop);

        $agent['assigned_properties'] = $props;
    }

    echo json_encode([
        "success" => true,
        "count" => count($agents),
        "data" => $agents
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Server error: " . $e->getMessage()
    ]);
}
?>
