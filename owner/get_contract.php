<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    $owner_id    = $_GET['owner_id'] ?? null;
    $contract_id = $_GET['contract_id'] ?? null;

    if (!$owner_id || !is_numeric($owner_id)) {
        throw new Exception("Invalid owner_id.");
    }
    if (!$contract_id || !is_numeric($contract_id)) {
        throw new Exception("Invalid contract_id.");
    }

    $pdo = (new Database())->getPdo();

    // ======================================================
    //  Secure: owner must own the contract
    // ======================================================
    $sql = "
        SELECT 
            c.contract_id,
            c.owner_id,
            c.property_id,
            c.agent_id,
            c.start_date,
            c.end_date,
            c.commission_rate,
            c.status AS contract_status,
            c.contract_file,
            c.created_at,
            c.owner_signed_at,
            c.agent_signed_at,

            -- Property
            p.title AS property_title,
            p.location AS property_location,
            p.price AS property_price,

            -- Agent
            CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
            a.agency AS agent_agency,
            a.license_no AS agent_license,
            a.phone AS agent_phone,
            a.profile_photo AS agent_photo

        FROM property_contracts c

        LEFT JOIN properties p 
            ON p.id = c.property_id

        LEFT JOIN agents a 
            ON a.id = c.agent_id

        LEFT JOIN users u 
            ON u.id = a.user_id

        WHERE c.contract_id = ?
          AND c.owner_id = ?   -- ← VERY IMPORTANT SECURITY FIX
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$contract_id, $owner_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Contract not found or access denied.");
    }

    echo json_encode([
        "success" => true,
        "data" => $row
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
