<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
header('Content-Type: application/json');

try {
    requireLogin();

    $propertyId = (int)($_GET['property_id'] ?? 0);
    $agentId    = (int)($_GET['agent_id'] ?? 0);
    $clientId   = (int)$_SESSION['user_id'];

    if ($propertyId <= 0 || $agentId <= 0) {
        throw new Exception("Invalid parameters");
    }

    $db  = new Database();
    $pdo = $db->getPdo();

   $stmt = $pdo->prepare("
    SELECT id, sender, message, created_at
    FROM property_chat
    WHERE property_id = ?
      AND agent_id = ?
    ORDER BY id ASC
");
$stmt->execute([$propertyId, $agentId]);

    echo json_encode([
        "success"  => true,
        "messages" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
