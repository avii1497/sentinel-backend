<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy contract deletion endpoint.
// Safe to remove after: 2026-06-30 (contracts now use /owner/contracts/terminate.php).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json");

try {
    requireLogin();
    requireRole('owner');
    requireCsrf();

    $data = json_decode(file_get_contents("php://input"), true);
    $data = sanitize_array($data ?? []);
    $contract_id = v_int($data['contract_id'] ?? null, 'contract id');

    $pdo = (new Database())->getPdo();

    $owner_id = $_SESSION['owner_id'] ?? null;
    if (!$owner_id) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $owner_id = $stmt->fetchColumn();
    }
    if (!$owner_id) throw new Exception("Owner account not found.");

    // Check status
    $stmt = $pdo->prepare("SELECT contract_file, status FROM property_contracts WHERE contract_id = ? AND owner_id = ?");
    $stmt->execute([$contract_id, $owner_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception("Contract not found.");

    if ($row['status'] !== "Pending") {
        throw new Exception("Only pending contracts can be deleted.");
    }

    // Delete file
    if ($row['contract_file']) {
        $path = __DIR__ . "/uploads/" . $row['contract_file'];
        if (file_exists($path)) unlink($path);
    }

    $pdo->prepare("DELETE FROM property_contracts WHERE contract_id = ?")
        ->execute([$contract_id]);

    echo json_encode(["success" => true, "message" => "Contract deleted."]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
