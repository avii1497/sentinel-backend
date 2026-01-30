<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();
requireRole('agent');
requireCsrf();

$db = new Database();
$pdo = $db->getPdo();

$userId = (int)($_SESSION['user_id'] ?? 0);
$agentId = $_SESSION['agent_id'] ?? null;

if (!$agentId) {
    $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $agentId = $stmt->fetchColumn();
    if ($agentId) {
        $_SESSION['agent_id'] = (int)$agentId;
    }
}

if (!$agentId) {
    echo json_encode(["success" => false, "error" => "Agent profile not found."]);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE agents SET status = 'Inactive', owner_id = NULL WHERE id = ?");
    $stmt->execute([(int)$agentId]);

    $stmt = $pdo->prepare("UPDATE users SET status = 0 WHERE id = ?");
    $stmt->execute([$userId]);

    $pdo->commit();

    session_unset();
    session_destroy();

    echo json_encode(["success" => true, "message" => "Account deactivated successfully."]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
