<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
    $contract_id = $_GET['contract_id'] ?? null;

    if (!$contract_id || !is_numeric($contract_id)) {
        throw new Exception("Missing or invalid contract_id");
    }

    $pdo = (new Database())->getPdo();

    // =============================================
    // FETCH CONTRACT + PROPERTY + OWNER + AGENT
    // =============================================
    $sql = "
        SELECT 
            pc.contract_id,
            pc.owner_id,
            pc.property_id,
            pc.agent_id,
            pc.start_date,
            pc.end_date,
            pc.approved_at,
            pc.owner_signed_at,
            pc.agent_signed_at,
            pc.commission_rate,
            pc.status,
            pc.contract_type,
            pc.contract_file,
            pc.signed_pdf_file,
            pc.created_at,

            -- Property
            p.title AS property_title,
            p.location AS property_location,

            -- Owner details
            u1.first_name AS owner_first_name,
            u1.last_name AS owner_last_name,
            u1.email AS owner_email,

            -- Agent details
            u2.first_name AS agent_first_name,
            u2.last_name AS agent_last_name,
            a.agency AS agent_agency

        FROM property_contracts pc
        JOIN properties p ON p.id = pc.property_id

        -- Owner user details
        JOIN owners o ON o.id = pc.owner_id
        JOIN users u1 ON u1.id = o.user_id

        -- Agent user details
        JOIN agents a ON a.id = pc.agent_id
        JOIN users u2 ON u2.id = a.user_id

        WHERE pc.contract_id = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        throw new Exception("Contract not found.");
    }

    // Format names
    $contract['owner_name'] = $contract['owner_first_name'] . " " . $contract['owner_last_name'];
    $contract['agent_name'] = $contract['agent_first_name'] . " " . $contract['agent_last_name'];

    echo json_encode([
        "success" => true,
        "data" => $contract
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
