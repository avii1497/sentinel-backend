<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json; charset=UTF-8");

requireLogin();
requireRole('agent');

$agent_id = (int)($_SESSION['agent_id'] ?? 0);

try {
    $pdo = (new Database())->getPdo();
    if ($agent_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $agent_id = (int)$stmt->fetchColumn();
    }
    if ($agent_id <= 0) {
        throw new Exception("Invalid agent");
    }

    /**
     * Fetch PAID + READY reservations
     * that are assigned to this agent
     * and NOT YET scheduled
     */
    $sql = "
        SELECT
            r.id AS reservation_id,
            r.property_id,
            r.customer_id,
            r.reservation_fee,
            r.payment_status,
            r.ready_for_final,
            r.coordination_status,
            r.expires_at,

            p.title    AS property_title,
            p.location AS property_location,
            p.owner_id,

            CONCAT(u.first_name, ' ', u.last_name) AS client_name,
            u.email AS client_email

        FROM property_reservations r
        INNER JOIN properties  p ON p.id = r.property_id
        INNER JOIN users       u ON u.id = r.customer_id

        WHERE p.assigned_agent_id = ?
          AND (r.reservation_status = 'PAID_CONFIRMED' OR r.payment_status = 'paid')
          AND r.ready_for_final = 1
          AND r.coordination_status IN ('pending', 'agent_notified')
          AND r.expires_at >= NOW()
          AND r.cancelled_at IS NULL

        ORDER BY r.expires_at ASC, r.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$agent_id]);

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
