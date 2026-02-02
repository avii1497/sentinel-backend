<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header("Content-Type: application/json");

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Inline auth (NO auth guard)
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'owner') {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);
    $input = sanitize_array($input ?? []);
    $contract_id = v_int($input['contract_id'] ?? null, 'contract id');
    $reason = v_string($input['reason'] ?? 'Contract terminated by owner', 'reason', 500, 0, false);

    $pdo = (new Database())->getPdo();
    $pdo->beginTransaction();

    // Ownership check
    $stmt = $pdo->prepare("
        SELECT owner_id
        FROM property_contracts
        WHERE contract_id = ?
        LIMIT 1
    ");
    $stmt->execute([$contract_id]);
    $ownerId = $stmt->fetchColumn();

    if (!$ownerId || (int)$ownerId !== (int)$_SESSION['owner_id']) {
        throw new Exception("Unauthorized contract access");
    }

    // Terminate contract
    $stmt = $pdo->prepare("
        UPDATE property_contracts
        SET
            status = 'TERMINATED',
            end_date = CURDATE(),
            notes = ?
        WHERE contract_id = ?
    ");
    $stmt->execute([$reason, $contract_id]);

    // Optional audit log
    $stmt = $pdo->prepare("
        INSERT INTO contract_terminations
        (contract_id, terminated_by, reason)
        VALUES (?, 'owner', ?)
    ");
    $stmt->execute([$contract_id, $reason]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Contract terminated successfully"
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
