<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/owner_guard.php';

header('Content-Type: application/json');

try {

    $sql = "
        SELECT
            -- Pending approval
            SUM(
                CASE 
                    WHEN rb.status = 'pending' THEN 1 
                    ELSE 0 
                END
            ) AS pending_requests,

            -- Approved but not paid yet
            SUM(
                CASE 
                    WHEN rb.status = 'accepted'
                     AND COALESCE(rb.payment_status,'unpaid') = 'unpaid'
                    THEN 1 
                    ELSE 0 
                END
            ) AS approved_unpaid,

            -- Active rentals (paid + in-range)
            SUM(
                CASE 
                    WHEN rb.status IN ('accepted','ongoing')
                     AND rb.payment_status = 'paid'
                     AND rb.checkin <= CURDATE()
                     AND rb.checkout >= CURDATE()
                    THEN 1 
                    ELSE 0 
                END
            ) AS active_rentals,

            -- Past rentals (completed / rejected / cancelled / ended)
            SUM(
                CASE 
                    WHEN rb.status IN ('completed','rejected','cancelled')
                     OR (
                        rb.payment_status = 'paid'
                        AND rb.checkout < CURDATE()
                     )
                    THEN 1 
                    ELSE 0 
                END
            ) AS past_rentals

        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        WHERE p.owner_id = :owner_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':owner_id' => $OWNER_ID
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'data' => [
            'pending'         => (int)($row['pending_requests'] ?? 0),
            'approved_unpaid' => (int)($row['approved_unpaid'] ?? 0),
            'active'          => (int)($row['active_rentals'] ?? 0),
            'past'            => (int)($row['past_rentals'] ?? 0),
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
    exit;
}
