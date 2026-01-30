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

    $agent_id    = $_POST['agent_id'] ?? null;
    $owner_id    = $_POST['owner_id'] ?? null;
    $property_id = $_POST['property_id'] ?? null;   
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $meeting_date = $_POST['meeting_date'] ?? null; // YYYY-MM-DD
    $start_time   = $_POST['start_time'] ?? null;   // HH:MM
    $end_time     = $_POST['end_time'] ?? null;     // HH:MM

    if (!$owner_id || !$title || !$meeting_date || !$start_time || !$end_time) {
        throw new Exception("Missing required fields.");
    }

    if (!is_numeric($owner_id)) {
        throw new Exception("Invalid agent_id or owner_id.");
    }

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
