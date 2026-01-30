<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header("Content-Type: application/json");
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['owner_id'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $contract_id = (int)($input['contract_id'] ?? 0);
    $commission  = isset($input['commission_rate']) ? (float)$input['commission_rate'] : null;
    $end_date    = $input['end_date'] ?? null;

    if (!$contract_id) {
        throw new Exception("Contract ID required");
    }

    $pdo = (new Database())->getPdo();

    // Ensure ownership
    $stmt = $pdo->prepare("
        SELECT owner_signed_at, agent_signed_at, status
        FROM property_contracts
        WHERE contract_id = ? AND owner_id = ?
    ");
    $stmt->execute([$contract_id, $_SESSION['owner_id']]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) throw new Exception("Contract not found");

    if ($c['status'] === 'TERMINATED') {
        throw new Exception("Contract already terminated");
    }

    // Optional: lock after both signatures
    if ($c['owner_signed_at'] && $c['agent_signed_at']) {
        throw new Exception("Contract locked after signing");
    }

    $stmt = $pdo->prepare("
        UPDATE property_contracts
        SET
            commission_rate = COALESCE(?, commission_rate),
            end_date = COALESCE(?, end_date)
        WHERE contract_id = ?
    ");
    $stmt->execute([$commission, $end_date, $contract_id]);

    echo json_encode(["success" => true]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
