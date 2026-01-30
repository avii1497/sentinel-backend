<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    requireLogin();
    requireRole('agent');
    requireCsrf();

    $data = json_decode(file_get_contents("php://input"), true);
    $contract_id = $data['contract_id'] ?? null;

    if (!$contract_id || !is_numeric($contract_id)) throw new Exception("Missing contract_id.");

    $pdo = (new Database())->getPdo();

    $agent_id = (int)($_SESSION['agent_id'] ?? 0);
    if ($agent_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $agent_id = (int)$stmt->fetchColumn();
    }
    if ($agent_id <= 0) throw new Exception("Unauthorized");

    $check = $pdo->prepare("
        SELECT agent_id
        FROM property_contracts
        WHERE contract_id = ?
        LIMIT 1
    ");
    $check->execute([(int)$contract_id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception("Contract not found.");
    }
    if ((int)$row['agent_id'] !== $agent_id) {
        http_response_code(403);
        echo json_encode(["success" => false, "error" => "Forbidden"]);
        exit;
    }

    $pdo->prepare("
        UPDATE property_contracts 
        SET status = 'Active'
        WHERE contract_id = ?
    ")->execute([$contract_id]);

    echo json_encode(["success" => true, "message" => "Contract approved."]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
