<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json; charset=UTF-8");

requireLogin();
requireRole('owner');

try {
    $pdo = (new Database())->getPdo();

    $owner_id = $_SESSION['owner_id'] ?? null;
    if (!$owner_id) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $owner_id = $stmt->fetchColumn();
        if ($owner_id) {
            $_SESSION['owner_id'] = (int)$owner_id;
        }
    }

    $requested_owner_id = $_GET['owner_id'] ?? null;
    if ($requested_owner_id && (int)$requested_owner_id !== (int)$owner_id) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid owner_id"
        ]);
        exit;
    }

    if (!$owner_id || !is_numeric($owner_id)) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid owner_id"
        ]);
        exit;
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
            CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
            a.agency AS agent_agency,
            p.title AS property_title,
            p.location AS property_location
        FROM meetings m
        INNER JOIN agents a ON m.agent_id = a.id
        INNER JOIN users u  ON a.user_id = u.id
        LEFT JOIN properties p ON p.id = m.property_id
        WHERE m.owner_id = ?
        ORDER BY m.meeting_date ASC, m.start_time ASC, m.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$owner_id]);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $meetings
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
