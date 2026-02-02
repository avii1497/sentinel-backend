<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json");

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    requireLogin();
    requireRole('agent');
    requireCsrf();

    $agent_id = v_int($_POST['agent_id'] ?? null, 'agent id', 1, 2147483647, false);
    $owner_id = v_int($_POST['owner_id'] ?? null, 'owner id');
    $property_id = v_int($_POST['property_id'] ?? null, 'property id', 1, 2147483647, false);
    $title = v_string($_POST['title'] ?? null, 'title', 200);
    $description = v_string($_POST['description'] ?? null, 'description', 2000, 0, false);
    $meeting_date = v_date($_POST['meeting_date'] ?? null, 'meeting date');
    $start_time = v_time($_POST['start_time'] ?? null, 'start time');
    $end_time = v_time($_POST['end_time'] ?? null, 'end time');

    $pdo = (new Database())->getPdo();

    $sessionAgentId = (int)($_SESSION['agent_id'] ?? 0);
    if ($sessionAgentId <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $sessionAgentId = (int)$stmt->fetchColumn();
    }
    if ($sessionAgentId <= 0) {
        throw new Exception("Unauthorized");
    }

    if ($agent_id && (int)$agent_id !== $sessionAgentId) {
        throw new Exception("Invalid agent_id.");
    }

    $agent_id = $sessionAgentId;

    // Optional: make sure this owner is linked to this agent
    $linkCheck = $pdo->prepare("
        SELECT 1 
        FROM owner_agent_link 
        WHERE owner_id = ? AND agent_id = ? AND status = 'Accepted'
        LIMIT 1
    ");
    $linkCheck->execute([$owner_id, $agent_id]);
    if (!$linkCheck->fetch()) {
        throw new Exception("This owner is not linked to this agent.");
    }

    $sql = "
        INSERT INTO meetings (
            owner_id, agent_id, property_id,
            title, description,
            meeting_date, start_time, end_time,
            status, created_by
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'agent')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $owner_id,
        $agent_id,
        $property_id ?: null,
        $title,
        $description ?: null,
        $meeting_date,
        $start_time,
        $end_time
    ]);

    echo json_encode([
        "success" => true,
        "meeting_id" => $pdo->lastInsertId()
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
