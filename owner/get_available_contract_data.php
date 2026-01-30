<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    $owner_id = $_GET['owner_id'] ?? null;

    if (!$owner_id || !is_numeric($owner_id)) {
        throw new Exception("Invalid owner_id.");
    }

    $pdo = (new Database())->getPdo();

    // =========================================================
    // Fetch (property, agent) pairs for this owner
    // - Property belongs to this owner
    // - Property has assigned_agent_id
    // - Agent is linked to this owner in owner_agent_link (Accepted)
    // =========================================================
    $sql = "
        SELECT 
            -- Agent
            a.id AS agent_id,
            CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
            a.agency AS agent_agency,

            -- Property
            p.id AS property_id,
            p.title AS property_title,
            p.location AS property_location,
            p.image_url AS property_image_url

        FROM properties p
        INNER JOIN agents a 
            ON a.id = p.assigned_agent_id

        INNER JOIN owner_agent_link l
            ON l.agent_id = a.id
           AND l.owner_id = ?
           AND l.status = 'Accepted'

        INNER JOIN users u
            ON u.id = a.user_id

        WHERE p.owner_id = ?
        ORDER BY agent_name ASC, p.title ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$owner_id, $owner_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data"    => $rows
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
