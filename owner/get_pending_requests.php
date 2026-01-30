<?php
require_once __DIR__ . '/../cors.php'; 
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json; charset=UTF-8");

if (!isset($_GET['owner_id'])) {
    echo json_encode(["success" => false, "error" => "Missing owner_id parameter"]);
    exit;
}

$owner_id = (int) $_GET['owner_id'];

try {
    $db = new Database();
    $pdo = $db->getPdo();

    // ✅ Show only pending requests (not accepted)
    $sql = "
        SELECT 
            l.link_id,
            l.status,
            l.requested_at,
            l.updated_at,
            a.id AS agent_id,
            u.first_name AS agent_first_name,
            u.last_name AS agent_last_name,
            u.email AS agent_email,
            a.phone AS agent_phone,
            a.license_no,
            a.specialization,
            a.commission_rate,
            a.profile_photo AS profile_pic,
            a.agency
        FROM owner_agent_link l
        JOIN agents a ON a.id = l.agent_id
        JOIN users u ON a.user_id = u.id
        WHERE l.owner_id = ?
          AND l.status IN ('Pending', 'Declined')  -- ✅ exclude Accepted
        ORDER BY l.requested_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$owner_id]);
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($agents),
        "data" => $agents
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database error: " . $e->getMessage()
    ]);
}
?>
