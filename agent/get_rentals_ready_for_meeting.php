<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json; charset=UTF-8");

if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('agent');

try {
    $pdo = (new Database())->getPdo();

    $agent_id = (int)($_SESSION['agent_id'] ?? 0);
    if ($agent_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $agent_id = (int)$stmt->fetchColumn();
    }
    if ($agent_id <= 0) {
        throw new Exception("Invalid agent");
    }

    /**
     * Fetch PAID + UPCOMING rentals
     * assigned to this agent
     * and NOT YET scheduled
     */
    $sql = "
        SELECT
            rb.id AS booking_id,
            rb.checkin,
            rb.checkout,
            rb.guests,
            rb.total_price,
            rb.status,
            rb.payment_status,

            p.title    AS property_title,
            p.location AS property_location,

            CONCAT(u.first_name, ' ', u.last_name) AS client_name,
            u.email AS client_email

        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        INNER JOIN customers c ON c.id = rb.tenant_id
        INNER JOIN users u ON u.id = c.user_id

        WHERE p.assigned_agent_id = ?
          AND rb.payment_status = 'paid'
          AND rb.status = 'accepted'
          AND rb.cancelled_at IS NULL
          AND rb.checkin > CURDATE()
          AND NOT EXISTS (
            SELECT 1 FROM meetings m
            WHERE m.booking_id = rb.id
          )

        ORDER BY rb.checkin ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agent_id]);

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
