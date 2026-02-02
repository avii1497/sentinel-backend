<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');

try {
    requireLogin();

    if (empty($_SESSION['agent_id'])) {
        throw new Exception("Not an agent");
    }

    $propertyId = v_int($_POST['property_id'] ?? null, 'property id');
    $message    = v_string($_POST['message'] ?? null, 'message', 2000);
    $agentId    = (int)$_SESSION['agent_id'];

    $db  = new Database();
    $pdo = $db->getPdo();

    // 🔎 Get client_id for this conversation
    $stmt = $pdo->prepare("
        SELECT DISTINCT client_id
        FROM property_chat
        WHERE property_id = ?
          AND agent_id = ?
        ORDER BY created_at ASC
        LIMIT 1
    ");
    $stmt->execute([$propertyId, $agentId]);
    $clientId = $stmt->fetchColumn();

    if (!$clientId) {
        throw new Exception("No client found for this chat");
    }

    // ✅ Insert agent message
    $stmt = $pdo->prepare("
        INSERT INTO property_chat
        (property_id, agent_id, client_id, sender, message)
        VALUES (?, ?, ?, 'agent', ?)
    ");
    $stmt->execute([$propertyId, $agentId, $clientId, $message]);

    echo json_encode(["success" => true]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
