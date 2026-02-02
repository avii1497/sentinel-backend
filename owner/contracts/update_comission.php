<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy commission update endpoint.
// Safe to remove after: 2026-06-30 (if /owner/contracts/update_meta.php is sole updater).
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header("Content-Type: application/json");
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['owner_id'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);
    $input = sanitize_array($input ?? []);

    $contract_id = v_int($input['contract_id'] ?? null, 'contract id');
    $commission  = v_float($input['commission_rate'] ?? null, 'commission rate', 0, 100);
    $endDateRaw  = $input['end_date'] ?? null;
    if ($endDateRaw === '') $endDateRaw = null;
    $end_date = v_date($endDateRaw, 'end date', false);

    $pdo = (new Database())->getPdo();

    // Ensure owner owns this contract
    $stmt = $pdo->prepare("
        SELECT owner_signed_at, agent_signed_at
        FROM property_contracts
        WHERE contract_id = ? AND owner_id = ?
    ");
    $stmt->execute([$contract_id, $_SESSION['owner_id']]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        throw new Exception("Contract not found");
    }

    // Optional lock after signatures
    if ($contract['owner_signed_at'] && $contract['agent_signed_at']) {
        throw new Exception("Commission locked after signing");
    }

    $stmt = $pdo->prepare("
        UPDATE property_contracts
        SET commission_rate = ?, end_date = ?
        WHERE contract_id = ?
    ");
    $stmt->execute([$commission, $end_date, $contract_id]);

    echo json_encode(["success" => true]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
