<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy rental requests endpoint.
// Safe to remove after: 2026-06-30 (use /owner/rentals/list.php?stage=requests).
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/owner_guard.php';

header('Content-Type: application/json');

function json_error(string $msg, int $code = 500): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            rb.id AS booking_id,
            rb.checkin,
            rb.checkout,
            rb.guests,
            rb.total_price,
            rb.rental_type,
            rb.status,
            rb.payment_status,

            p.title    AS property_title,
            p.location AS property_location,

            u.first_name AS tenant_first_name,
            u.last_name  AS tenant_last_name,
            u.email      AS tenant_email

        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        LEFT JOIN customers c  ON c.id = rb.tenant_id
        LEFT JOIN users u      ON u.id = c.user_id

        WHERE 
            p.owner_id = ?
            AND rb.status = 'pending'

        ORDER BY rb.created_at DESC
    ");

    $stmt->execute([$OWNER_ID]);

    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;

} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
