<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

try {
    requireLogin();

    $propertyId = (int)($_GET['property_id'] ?? 0);
    $agentId    = (int)($_GET['agent_id'] ?? 0);
    $lastId     = (int)($_GET['last_id'] ?? 0);

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
          AND id > ?
        ORDER BY id ASC
    ");
    $stmt->execute([$propertyId, $agentId, $lastId]);

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
