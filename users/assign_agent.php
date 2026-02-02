<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy admin user-agent assignment.
// Safe to remove after: 2026-06-30 (if no admin tool uses it).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header('Content-Type: application/json');

requireLogin();
requireRole(['owner']);
requireCsrf();

try {
    $data = json_decode(file_get_contents("php://input"), true);
    $data = sanitize_array($data ?? []);
    $payloadOwnerId = v_int($data['owner_id'] ?? null, 'owner id', 1, 2147483647, false) ?? 0;
    $agentId = v_int($data['agent_id'] ?? null, 'agent id');

    $db = new Database();
    $pdo = $db->getPdo();

    $ownerId = (int)($_SESSION['owner_id'] ?? 0);
    if ($ownerId <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $ownerId = (int)$stmt->fetchColumn();
        if ($ownerId > 0) {
            $_SESSION['owner_id'] = $ownerId;
        }
    }

    if ($payloadOwnerId > 0 && $payloadOwnerId !== $ownerId) {
        throw new RuntimeException("Invalid owner_id or agent_id");
    }

    if ($ownerId <= 0) {
        throw new RuntimeException("Owner not found");
    }

    $agentCheck = $pdo->prepare("SELECT id FROM agents WHERE id = ? LIMIT 1");
    $agentCheck->execute([$agentId]);
    if (!$agentCheck->fetchColumn()) {
        throw new RuntimeException("Agent not found");
    }

    // Update owner record with assigned agent
    $stmt = $pdo->prepare("UPDATE owners SET assigned_agent_id = ? WHERE id = ?");
    $stmt->execute([$agentId, $ownerId]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException("Owner not found or no change made");
    }

    echo json_encode([
        "success" => true,
        "message" => "Agent assigned successfully"
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
