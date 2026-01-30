<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json; charset=UTF-8");

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

    // 1) Owners linked to this agent (status = Accepted)
    $sql = "
        SELECT 
            o.id AS owner_id,
            CONCAT(u.first_name, ' ', u.last_name) AS owner_name,
            u.email AS owner_email
        FROM owner_agent_link l
        INNER JOIN owners o ON l.owner_id = o.id
        INNER JOIN users  u ON u.id = o.user_id
        WHERE l.agent_id = :agent_id
          AND l.status  = 'Accepted'
        ORDER BY owner_name ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['agent_id' => $agent_id]);
    $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($owners)) {
        echo json_encode([
            "success" => true,
            "count"   => 0,
            "data"    => []
        ]);
        exit;
    }

    // 2) For each owner, fetch properties assigned to THIS agent
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
        WHERE owner_id          = :owner_id
          AND assigned_agent_id = :agent_id
        ORDER BY created_at DESC
    ");

    foreach ($owners as &$owner) {
        $pstmt->execute([
            'owner_id' => $owner['owner_id'],
            'agent_id' => $agent_id
        ]);
        $props = $pstmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($props as &$prop) {
            if (!empty($prop['image_url']) && strpos($prop['image_url'], 'http') !== 0) {
                $prop['image_url'] = "http://localhost/sentinel-backend/properties/uploads/gallery/" . $prop['image_url'];
            }
        }
        unset($prop);

        $owner['assigned_properties'] = $props;
    }
    unset($owner);

    echo json_encode([
        "success" => true,
        "count"   => count($owners),
        "data"    => $owners
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => "Server error: " . $e->getMessage()
    ]);
}
