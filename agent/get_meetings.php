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

    $pdo = (new Database())->getPdo();

    $agent_id = (int)($_SESSION['agent_id'] ?? 0);
    if ($agent_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $agent_id = (int)$stmt->fetchColumn();
    }
    if ($agent_id <= 0) {
        throw new Exception("Unauthorized");
    }

    $requested_agent_id = $_GET['agent_id'] ?? null;
    if ($requested_agent_id && (int)$requested_agent_id !== (int)$agent_id) {
        throw new Exception("Invalid agent_id.");
    }

    $sql = "
        SELECT
            m.id AS meeting_id,
            m.owner_id,
            m.agent_id,
            m.property_id,
            m.title,
            m.description,
            m.meeting_date,
            m.start_time,
            m.end_time,
            m.status,
            m.created_by,
            m.created_at,
            m.updated_at,
            CONCAT(u.first_name, ' ', u.last_name) AS owner_name
        FROM meetings m
        INNER JOIN owners o ON m.owner_id = o.id
        INNER JOIN users  u ON o.user_id = u.id
        WHERE m.agent_id = ?
        ORDER BY m.meeting_date DESC, m.start_time DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agent_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data"    => $rows
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
