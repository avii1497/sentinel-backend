<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
    $agent_id = $_GET['agent_id'] ?? null;

    if (!$agent_id || !is_numeric($agent_id)) {
        throw new Exception("Missing or invalid agent_id");
    }

    $pdo = (new Database())->getPdo();

    // load contracts for this agent + property info
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
            p.title   AS property_title,
            p.location AS property_location
        FROM property_contracts pc
        JOIN properties p ON p.id = pc.property_id
        WHERE pc.agent_id = ?
        ORDER BY pc.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agent_id]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => $contracts,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
